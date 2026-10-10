<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\AccountingPoster;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Support\DiscountCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleInvoiceController extends Controller
{
    public function index()
    {
        $this->authorizePermission('invoices.view');

        $invoices = Sale::query()
            ->with(['customer', 'cashier'])
            ->where('document_type', Sale::TYPE_INVOICE)
            ->whereNotIn('status', [Sale::STATUS_HELD, Sale::STATUS_DRAFT])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->get();

        $user = auth()->user();

        return view('invoices.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'invoices.index',
            'invoices' => $invoices,
            'canCreate' => $user->hasPermission('invoices.create'),
            'canPrint' => $user->hasPermission('sales.print_invoice')
                || $user->hasPermission('invoices.view'),
        ]));
    }

    public function create()
    {
        $this->authorizePermission('invoices.create');

        return view('invoices.create', array_merge(fleet_shared_view_data(), $this->formData(new Sale([
            'sale_date' => now(),
            'due_date' => now()->addDays(30),
            'discount_amount' => 0,
        ])), [
            'activeMenu' => 'invoices.create',
        ]));
    }

    public function store(
        Request $request,
        DocumentNumberService $numbers,
        InventoryService $inventory,
        AccountingPoster $accounting,
        LoyaltyService $loyalty,
        AuditLogger $audit
    ) {
        $this->authorizePermission('invoices.create');

        $companyId = (int) auth()->user()->company_id;
        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select a branch first.');

        [$data, $lines, $totals] = $this->validatedPayload($request, $companyId);

        try {
            $sale = DB::transaction(function () use ($data, $lines, $totals, $numbers, $inventory, $accounting, $loyalty, $audit, $companyId, $branchId) {
                $sale = Sale::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'customer_id' => $data['customer_id'],
                    'user_id' => auth()->id(),
                    'document_type' => Sale::TYPE_INVOICE,
                    'number' => $numbers->next($companyId, 'sale'),
                    'invoice_number' => $numbers->next($companyId, 'invoice'),
                    'sale_date' => $data['sale_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'status' => Sale::STATUS_COMPLETED,
                    'payment_status' => Sale::PAYMENT_UNPAID,
                    'subtotal' => $totals['subtotal'],
                    'discount_percent' => $totals['discount_percent'],
                    'discount_amount' => $totals['discount_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'total' => $totals['total'],
                    'paid_amount' => 0,
                    'balance' => $totals['total'],
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($lines as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    $conversion = app(\App\Services\UnitConversionService::class);
                    $unit = $conversion->resolveSellingUnit(
                        $product->loadMissing(['unit', 'purchaseUnit', 'sellingUnits']),
                        $line['product_unit_id'] ?? null,
                        $line['sell_as'] ?? null
                    );
                    $qtyBase = $conversion->toBase($line['quantity'], $unit['factor']);
                    $costEach = $conversion->costPerSellingUnit($product, $unit['factor']);
                    SaleItem::query()->create([
                        'company_id' => $companyId,
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_variant_id' => 0,
                        'product_unit_id' => $unit['id'],
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'size' => $product->size,
                        'color' => $product->color,
                        'quantity' => $line['quantity'],
                        'quantity_base' => $qtyBase,
                        'unit_name' => $unit['name'],
                        'unit_factor' => $unit['factor'],
                        'unit_price' => $line['unit_price'],
                        'cost_price' => $costEach,
                        'discount_amount' => $line['discount_amount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                    ]);

                    if ($product->manage_stock) {
                        $inventory->apply([
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'product_id' => $product->id,
                            'product_variant_id' => 0,
                            'type' => StockMovement::SALE,
                            'quantity_out' => $qtyBase,
                            'unit_cost' => $conversion->costPerBase($product),
                            'unit_name' => $unit['name'],
                            'unit_factor' => $unit['factor'],
                            'quantity_entered' => $line['quantity'],
                            'user_id' => auth()->id(),
                            'notes' => 'Invoice ' . $sale->invoice_number,
                            'reference_type' => Sale::class,
                            'reference_id' => $sale->id,
                            'reference_number' => $sale->invoice_number ?: $sale->number,
                            'occurred_at' => $sale->sale_date,
                        ]);
                    }
                }

                $sale = $sale->fresh(['items', 'customer']);
                $accounting->postSale($sale);
                $loyalty->earnForSale($sale);

                $audit->record('create', 'invoices', $sale, null, $sale->only([
                    'number', 'invoice_number', 'customer_id', 'status', 'payment_status', 'total',
                ]));

                return $sale;
            });
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $sale)
            ->with('success', 'Invoice ' . ($sale->invoice_number ?: $sale->number) . ' saved.');
    }

    public function show(Sale $sale)
    {
        $this->authorizePermission('invoices.view');
        abort_unless($sale->document_type === Sale::TYPE_INVOICE, 404);
        abort_if(in_array($sale->status, [Sale::STATUS_HELD, Sale::STATUS_DRAFT], true), 404);

        $sale->load(['customer', 'cashier', 'branch', 'company', 'items.product', 'quotation']);
        $user = auth()->user();

        return view('invoices.show', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'invoices.index',
            'sale' => $sale,
            'canPrint' => $user->hasPermission('sales.print_invoice') || $user->hasPermission('invoices.view'),
            'canPay' => $user->hasPermission('payments.create') && $sale->status === Sale::STATUS_COMPLETED && $sale->remainingBalance() > 0,
        ]));
    }

    /**
     * @return array{0: array, 1: array<int, array>, 2: array{subtotal: float, discount_amount: float, tax_amount: float, total: float}}
     */
    private function validatedPayload(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'sale_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:sale_date',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:fixed,percent',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        $lines = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($data['items'] as $row) {
            $product = Product::query()->with('tax')->findOrFail($row['product_id']);
            $qty = (float) $row['quantity'];
            $unitPrice = (float) $row['unit_price'];
            $taxRate = array_key_exists('tax_rate', $row) && $row['tax_rate'] !== null && $row['tax_rate'] !== ''
                ? (float) $row['tax_rate']
                : (float) (optional($product->tax)->rate ?? 0);
            $base = round($qty * $unitPrice, 2);
            $lineDiscount = DiscountCalculator::money(
                $row['discount_type'] ?? 'fixed',
                $row['discount_value'] ?? $row['discount_amount'] ?? 0,
                $base
            );
            $taxable = round(max(0, $base - $lineDiscount), 2);
            $taxAmt = round($taxable * ($taxRate / 100), 2);
            $lineTotal = round($taxable + $taxAmt, 2);

            $subtotal += $taxable;
            $taxTotal += $taxAmt;

            $lines[] = [
                'product' => $product,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmt,
                'line_total' => $lineTotal,
            ];
        }

        $headerType = $data['discount_type'] ?? 'fixed';
        $headerValue = $data['discount_value'] ?? $data['discount_amount'] ?? 0;
        $headerDiscount = DiscountCalculator::money($headerType, $headerValue, round($subtotal, 2));
        $total = round(max(0, $subtotal - $headerDiscount + $taxTotal), 2);

        return [
            $data,
            $lines,
            [
                'subtotal' => round($subtotal, 2),
                'discount_amount' => $headerDiscount,
                'discount_percent' => DiscountCalculator::percentStored($headerType, $headerValue),
                'tax_amount' => round($taxTotal, 2),
                'total' => $total,
            ],
        ];
    }

    private function formData(Sale $sale): array
    {
        $products = Product::query()
            ->with('tax')
            ->availableAtBranch($this->currentBranchId())
            ->where('is_active', true)
            ->where('for_sale', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'selling_price', 'tax_id', 'size', 'color']);

        return [
            'sale' => $sale,
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderByDesc('is_walk_in')
                ->orderBy('name')
                ->get(),
            'products' => $products,
        ];
    }
}
