<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\DocumentNumberService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use App\Services\AccountingPoster;
use App\Services\LoyaltyService;
use App\Services\AuditLogger;
use App\Services\UnitConversionService;
use App\Support\DiscountCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function index()
    {
        $this->authorizePermission('pos.view');

        $walkIn = $this->walkInCustomer();
        $customers = Customer::query()
            ->where('is_active', true)
            ->withSum(['sales as credit_sales' => function ($query) {
                $query->where('status', Sale::STATUS_COMPLETED);
            }], 'balance')
            ->orderByDesc('is_walk_in')
            ->orderBy('name')
            ->get();

        $heldCount = Sale::query()
            ->where('status', Sale::STATUS_HELD)
            ->where('document_type', Sale::TYPE_POS)
            ->count();

        return view('pos.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'sales.pos',
            'customers' => $customers,
            'walkInId' => $walkIn ? $walkIn->id : null,
            'heldCount' => $heldCount,
            'canCreateCustomer' => auth()->user()->hasPermission('customers.create'),
            'paymentMethods' => config('sellix.payment_methods', []),
        ]));
    }

    public function catalog(Request $request)
    {
        $this->authorizePermission('pos.view');

        $branchId = $this->currentBranchId();
        $categoryId = $request->filled('category_id') ? (int) $request->category_id : null;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 6;

        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->where('show_on_pos', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $query = Product::query()
            ->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits'])
            ->availableAtBranch($branchId)
            ->where('is_active', true)
            ->where('for_sale', true)
            ->when($categoryId, function ($builder) use ($categoryId) {
                $builder->where('category_id', $categoryId);
            })
            ->orderBy('name');

        $total = $query->count();
        $products = $query->forPage($page, $perPage)->get();

        return response()->json([
            'categories' => $categories,
            'products' => $products->map(function (Product $product) use ($branchId) {
                return $this->productPayload($product, $branchId);
            })->values(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function search(Request $request)
    {
        $this->authorizePermission('pos.view');

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $branchId = $this->currentBranchId();
        $products = Product::query()
            ->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits'])
            ->availableAtBranch($branchId)
            ->where('is_active', true)
            ->where('for_sale', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('sku', 'like', '%' . $q . '%')
                    ->orWhere('barcode', $q)
                    ->orWhereHas('sellingUnits', function ($units) use ($q) {
                        $units->where('barcode', $q);
                    });
            })
            ->orderBy('name')
            ->limit(15)
            ->get();

        $hit = app(UnitConversionService::class)->findByBarcode(auth()->user()->company_id, $q);

        return response()->json($products->map(function (Product $product) use ($branchId, $hit) {
            $payload = $this->productPayload($product, $branchId);
            if ($hit && (int) $hit['product_id'] === (int) $product->id) {
                if (! empty($hit['product_unit_id'])) {
                    $payload['scan_unit_id'] = (int) $hit['product_unit_id'];
                }
                if (! empty($hit['sell_as'])) {
                    $payload['scan_sell_as'] = $hit['sell_as'];
                }
            }

            return $payload;
        })->values());
    }

    public function storeCustomer(Request $request)
    {
        $this->authorizePermission('customers.create');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
        ]);

        $customer = Customer::query()->create([
            'company_id' => auth()->user()->company_id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'type' => Customer::TYPE_REGULAR,
            'is_walk_in' => false,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'credit_limit' => (float) $customer->credit_limit,
            'credit_used' => $customer->creditAmount(),
        ]);
    }

    public function holds()
    {
        $this->authorizePermission('pos.view');

        $holds = Sale::query()
            ->with('customer')
            ->where('status', Sale::STATUS_HELD)
            ->orderByDesc('held_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($holds->map(function (Sale $sale) {
            return [
                'id' => $sale->id,
                'number' => $sale->number,
                'customer' => optional($sale->customer)->name ?: 'WALK-IN',
                'total' => number_format((float) $sale->total, 2),
                'held_at' => optional($sale->held_at ?: $sale->created_at)->format('d-m-Y H:i'),
                'url' => route('pos.holds.show', $sale),
            ];
        }));
    }

    public function showHold(Sale $sale)
    {
        $this->authorizePermission('pos.view');
        abort_unless($sale->status === Sale::STATUS_HELD, 404);

        $sale->load(['items.product.tax', 'items.product.unit', 'items.product.purchaseUnit']);
        $branchId = $this->currentBranchId();

        return response()->json([
            'id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'due_date' => optional($sale->due_date)->format('Y-m-d'),
            'discount_amount' => (float) $sale->discount_amount,
            'discount_percent' => (float) $sale->discount_percent,
            'items' => $sale->items->map(function (SaleItem $item) use ($branchId) {
                $payload = $item->product
                    ? $this->productPayload($item->product, $branchId)
                    : [
                        'id' => $item->product_id,
                        'name' => $item->name,
                        'price' => (float) $item->unit_price,
                        'selling_price' => (float) $item->unit_price,
                        'tax_rate' => (float) $item->tax_rate,
                        'tax_inclusive' => true,
                        'qty' => 0,
                        'cost' => (float) $item->cost_price,
                    ];
                $payload['qty_sold'] = (float) $item->quantity;
                $payload['unit_id'] = $item->product_unit_id;
                $payload['unit_name'] = $item->unit_name;
                $payload['sell_as'] = $item->product_unit_id
                    ? ('extra:' . $item->product_unit_id)
                    : (((float) ($item->unit_factor ?: 1) == 1.0) ? 'base' : 'purchase');
                $payload['selling_price'] = (float) $item->unit_price;
                $payload['price'] = (float) $item->quantity > 0
                    ? round((float) $item->line_total / (float) $item->quantity, 2)
                    : (float) $item->unit_price;
                $payload['tax_rate'] = (float) $item->tax_rate;
                $payload['tax_amount'] = (float) $item->quantity > 0
                    ? round((float) $item->tax_amount / (float) $item->quantity, 4)
                    : (float) $item->tax_amount;
                $payload['apply_tax'] = (float) $item->tax_amount > 0;

                return $payload;
            })->values(),
        ]);
    }

    public function hold(Request $request, DocumentNumberService $numbers)
    {
        $this->authorizePermission('pos.operate');

        $data = $this->validateCart($request);
        $sale = $this->writeSale($data, $numbers, Sale::STATUS_HELD, 0, null);

        return response()->json([
            'id' => $sale->id,
            'number' => $sale->number,
            'held_count' => Sale::query()->where('status', Sale::STATUS_HELD)->count(),
        ]);
    }

    public function order(Request $request, DocumentNumberService $numbers, InventoryService $inventory, AccountingPoster $accounting, LoyaltyService $loyalty, AuditLogger $audit)
    {
        $this->authorizePermission('pos.operate');

        $data = $this->validateCart($request);
        $data = array_merge($data, $request->validate([
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:2000',
            'service_type' => 'nullable|string|max:32',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required_with:payments|string|max:32',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.reference' => 'nullable|string|max:64',
            'payments.*.bank_name' => 'nullable|string|max:128',
        ]));

        $payments = collect($data['payments'] ?? [])
            ->filter(function ($row) {
                return (float) ($row['amount'] ?? 0) > 0;
            })
            ->values();

        $payAmount = (float) $payments->sum('amount');
        if ($payAmount <= 0) {
            $payAmount = (float) ($data['payment_amount'] ?? 0);
            if ($payAmount > 0) {
                $payments = collect([[
                    'method' => $data['payment_method'] ?: Payment::METHOD_CASH,
                    'amount' => $payAmount,
                ]]);
            }
        }

        // Preview total for payment normalization (same formula as writeSale).
        $previewTotal = (float) ($this->cartTotals($data)['total'] ?? 0);
        $payments = $this->normalizeSalePayments($payments, $previewTotal);
        $payAmount = round((float) $payments->sum('amount'), 2);

        $customer = Customer::query()->find($data['customer_id'] ?? null);
        $balanceDue = round(max(0, $previewTotal - min($payAmount, $previewTotal)), 2);
        if ($balanceDue > 0.009) {
            if (! $customer || $customer->is_walk_in) {
                return response()->json([
                    'message' => 'Walk-in customers cannot buy on credit. Select a customer account or receive full payment.',
                ], 422);
            }

            $limit = round((float) $customer->credit_limit, 2);
            if ($limit > 0) {
                $projected = round($customer->creditAmount() + $balanceDue, 2);
                if ($projected - $limit > 0.009) {
                    return response()->json([
                        'message' => 'Credit limit exceeded. Available: Ksh '
                            . number_format(max(0, $limit - $customer->creditAmount()), 2)
                            . ', this sale would add Ksh ' . number_format($balanceDue, 2) . '.',
                    ], 422);
                }
            }

            // Credit sales are stored as invoices so they appear in receivables.
            $data['document_type'] = Sale::TYPE_INVOICE;
        }

        $noteParts = array_filter([
            $data['service_type'] ?? null,
            $data['notes'] ?? null,
            $balanceDue > 0.009 ? 'On credit' : null,
        ]);
        $data['notes'] = $noteParts ? implode(' | ', $noteParts) : null;

        try {
            $sale = DB::transaction(function () use ($data, $numbers, $inventory, $payAmount, $payments, $accounting, $loyalty, $audit) {
                $sale = $this->writeSale($data, $numbers, Sale::STATUS_COMPLETED, $payAmount, $inventory);

                foreach ($payments as $row) {
                    Payment::query()->create([
                        'company_id' => $sale->company_id,
                        'branch_id' => $sale->branch_id,
                        'user_id' => auth()->id(),
                        'customer_id' => $sale->customer_id,
                        'number' => $numbers->next($sale->company_id, 'payment'),
                        'payable_type' => Sale::class,
                        'payable_id' => $sale->id,
                        'method' => $row['method'],
                        'amount' => (float) $row['amount'],
                        'reference' => $row['reference'] ?? null,
                        'paid_at' => now(),
                        'notes' => trim(implode(' | ', array_filter([
                            $row['bank_name'] ?? null,
                            $data['notes'] ?? null,
                        ]))) ?: null,
                    ]);
                }

                $sale->load(['items.product', 'payments']);
                $accounting->postSale($sale);
                $loyalty->earnForSale($sale);
                $audit->record('create', 'sales', $sale, null, [
                    'number' => $sale->number,
                    'total' => $sale->total,
                    'paid_amount' => $sale->paid_amount,
                    'status' => $sale->status,
                ]);

                return $sale;
            });
        } catch (NegativeStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $sale->id,
            'number' => $sale->number,
            'invoice_number' => $sale->invoice_number,
            'receipt_number' => $sale->receipt_number,
            'receipt_url' => route('pos.receipt', $sale),
        ]);
    }

    public function receipt(Sale $sale, SettingsService $settings)
    {
        $this->authorizePermission('pos.view');
        abort_unless($sale->status === Sale::STATUS_COMPLETED, 404);

        $sale->load(['items', 'customer', 'cashier', 'payments']);
        $paper = $settings->get((int) $sale->company_id, 'receipt_paper_size', '80mm');
        if (! in_array($paper, ['58mm', '80mm'], true)) {
            $paper = '80mm';
        }

        return view('pos.receipt', [
            'sale' => $sale,
            'paper' => $paper,
            'profile' => fleet_company_profile(),
            'paymentLabels' => config('sellix.payment_methods', []),
        ]);
    }

    private function validateCart(Request $request): array
    {
        $companyId = auth()->user()->company_id;

        return $request->validate([
            'hold_id' => 'nullable|integer|exists:sales,id',
            'customer_id' => ['required', Rule::exists('customers', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'sale_date' => 'required|date',
            'due_date' => 'nullable|date',
            'document_type' => 'required|in:pos,invoice',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'nullable|integer',
            'items.*.sell_as' => 'nullable|string|max:32',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.apply_tax' => 'nullable',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.price_type' => 'nullable|in:R,W',
        ]);
    }

    private function itemAppliesTax(array $row): bool
    {
        if (! array_key_exists('apply_tax', $row) || $row['apply_tax'] === null || $row['apply_tax'] === '') {
            return false;
        }
        $value = $row['apply_tax'];
        if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function postedUnitPrice(array $row): ?float
    {
        if (! array_key_exists('unit_price', $row) || $row['unit_price'] === null || $row['unit_price'] === '') {
            return null;
        }

        return round((float) $row['unit_price'], 4);
    }

    private function lineUnitPrice(Product $product, array $unit, array $payload, array $row): float
    {
        $posted = $this->postedUnitPrice($row);
        if ($posted !== null) {
            $inclusive = (bool) $payload['tax_inclusive'];
            $rate = (float) $payload['tax_rate'];
            if (! $inclusive && $rate > 0) {
                return round($posted / (1 + $rate / 100), 4);
            }

            return $posted;
        }
        if (($row['price_type'] ?? 'R') === 'W') {
            $conversion = app(UnitConversionService::class);
            $wholesale = $conversion->wholesaleForFactor($product, $unit['factor'] ?? 1);
            if ($wholesale > 0) {
                return $wholesale;
            }
        }

        return (float) $unit['selling_price'];
    }

    private function lineDisplayPrice(Product $product, array $unit, array $payload, array $row, bool $inclusive, float $rate): float
    {
        $posted = $this->postedUnitPrice($row);
        if ($posted !== null) {
            return round($posted, 4);
        }
        $unitPrice = $this->lineUnitPrice($product, $unit, $payload, $row);
        if ($inclusive || $rate <= 0) {
            return round($unitPrice, 4);
        }

        return round($unitPrice * (1 + $rate / 100), 2);
    }

    private function writeSale(array $data, DocumentNumberService $numbers, string $status, float $payAmount, ?InventoryService $inventory): Sale
    {
        $companyId = auth()->user()->company_id;
        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select a branch first.');

        $hold = null;
        if (! empty($data['hold_id'])) {
            $hold = Sale::query()->where('status', Sale::STATUS_HELD)->find($data['hold_id']);
        }

        $conversion = app(UnitConversionService::class);
        $settings = app(SettingsService::class);
        $multiUnit = $settings->get($companyId, 'multi_unit_selling', 'Yes') !== 'No';

        $lines = [];
        $neededBase = [];
        foreach ($data['items'] as $row) {
            $product = Product::query()->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits'])->findOrFail($row['product_id']);
            abort_unless(
                $product->isAvailableAtBranch($branchId),
                403,
                'Item is not available at the active branch.'
            );
            $unitId = $multiUnit ? ($row['product_unit_id'] ?? null) : null;
            $sellAs = $multiUnit ? ($row['sell_as'] ?? null) : 'base';
            $unit = $conversion->resolveSellingUnit($product, $unitId, $sellAs);
            $payload = $this->productPayload($product, $branchId, $unit);
            $qty = (float) $row['quantity'];
            $qtyBase = $conversion->toBase($qty, $unit['factor']);
            $rate = (float) $payload['tax_rate'];
            $inclusive = (bool) $payload['tax_inclusive'];
            $applyTax = $this->itemAppliesTax($row);
            $unitPrice = $this->lineUnitPrice($product, $unit, $payload, $row);
            $displayPrice = $this->lineDisplayPrice($product, $unit, $payload, $row, $inclusive, $rate);
            if (! $applyTax) {
                $rate = 0.0;
            }
            $lineTax = ($applyTax && $rate > 0)
                ? ($inclusive ? round($unitPrice * $qty * $rate / (100 + $rate), 2) : round($unitPrice * $qty * $rate / 100, 2))
                : 0;
            $lineTotal = round($displayPrice * $qty, 2);
            $costEach = $conversion->costPerSellingUnit($product, $unit['factor']);
            $lines[] = [
                'product' => $product,
                'quantity' => $qty,
                'quantity_base' => $qtyBase,
                'unit_id' => $unit['id'],
                'unit_name' => $unit['name'],
                'unit_factor' => $unit['factor'],
                'unit_price' => $unitPrice,
                'display_price' => $displayPrice,
                'cost_price' => $costEach,
                'cost_per_base' => $conversion->costPerBase($product),
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ];
            $neededBase[$product->id] = ($neededBase[$product->id] ?? 0) + $qtyBase;
        }

        if ($inventory && $status === Sale::STATUS_COMPLETED) {
            foreach ($neededBase as $productId => $need) {
                $match = collect($lines)->first(function ($line) use ($productId) {
                    return $line['product']->id === $productId;
                });
                $product = $match['product'];
                if (! $product->manage_stock || $product->allow_negative_stock) {
                    continue;
                }
                $onHand = $inventory->quantityOnHand($companyId, $branchId, $productId);
                if ($need - $onHand > 0.0000005) {
                    throw NegativeStockException::available(
                        $conversion->formatQty($onHand) . ' ' . $conversion->baseUnitLabel($product)
                    );
                }
            }
        }

        $subtotal = round(collect($lines)->sum('line_total'), 2);
        $taxTotal = round(collect($lines)->sum('tax_amount'), 2);
        $discountType = $data['discount_type'] ?? 'fixed';
        $discountValue = $data['discount_value'] ?? $data['discount_amount'] ?? 0;
        $discount = DiscountCalculator::money($discountType, $discountValue, $subtotal);
        $total = round(max(0, $subtotal - $discount), 2);
        $paid = min($total, $payAmount);
        $isInvoice = ($data['document_type'] ?? Sale::TYPE_POS) === Sale::TYPE_INVOICE;

        $attrs = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'customer_id' => $data['customer_id'],
            'user_id' => auth()->id(),
            'document_type' => $isInvoice ? Sale::TYPE_INVOICE : Sale::TYPE_POS,
            'sale_date' => $data['sale_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $status,
            'payment_status' => $status === Sale::STATUS_HELD
                ? Sale::PAYMENT_UNPAID
                : ($paid <= 0 ? Sale::PAYMENT_UNPAID : ($paid + 0.009 >= $total ? Sale::PAYMENT_PAID : Sale::PAYMENT_PARTIAL)),
            'subtotal' => $subtotal,
            'discount_percent' => DiscountCalculator::percentStored($discountType, $discountValue),
            'discount_amount' => $discount,
            'tax_amount' => $taxTotal,
            'total' => $total,
            'paid_amount' => $status === Sale::STATUS_HELD ? 0 : $paid,
            'balance' => $status === Sale::STATUS_HELD ? $total : round($total - $paid, 2),
            'notes' => $data['notes'] ?? null,
            'held_at' => $status === Sale::STATUS_HELD ? now() : null,
        ];

        if ($hold) {
            $hold->items()->delete();
            $hold->update($attrs);
            $sale = $hold;
        } else {
            $attrs['number'] = $numbers->next($companyId, 'sale');
            if ($status === Sale::STATUS_COMPLETED) {
                if ($isInvoice) {
                    $attrs['invoice_number'] = $numbers->next($companyId, 'invoice');
                } else {
                    $attrs['receipt_number'] = $numbers->next($companyId, 'receipt');
                }
            }
            $sale = Sale::query()->create($attrs);
        }

        if ($status === Sale::STATUS_COMPLETED && ! $sale->invoice_number && ! $sale->receipt_number) {
            $sale->update($isInvoice
                ? ['invoice_number' => $numbers->next($companyId, 'invoice')]
                : ['receipt_number' => $numbers->next($companyId, 'receipt')]);
        }

        foreach ($lines as $line) {
            SaleItem::query()->create([
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'product_unit_id' => $line['unit_id'],
                'name' => $line['product']->name,
                'sku' => $line['product']->sku,
                'quantity' => $line['quantity'],
                'quantity_base' => $line['quantity_base'],
                'unit_name' => $line['unit_name'],
                'unit_factor' => $line['unit_factor'],
                'unit_price' => $line['unit_price'],
                'cost_price' => $line['cost_price'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);

            if ($inventory && $status === Sale::STATUS_COMPLETED && $line['product']->manage_stock) {
                $inventory->apply([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $line['product']->id,
                    'type' => StockMovement::POS_SALE,
                    'quantity_out' => $line['quantity_base'],
                    'unit_cost' => $line['cost_per_base'],
                    'unit_name' => $line['unit_name'],
                    'unit_factor' => $line['unit_factor'],
                    'quantity_entered' => $line['quantity'],
                    'user_id' => auth()->id(),
                    'notes' => 'POS ' . $sale->number . ' · ' . $line['quantity'] . ' ' . $line['unit_name'],
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'reference_number' => $sale->number,
                    'occurred_at' => $sale->sale_date,
                ]);
            }
        }

        return $sale->fresh();
    }

    private function cartTotals(array $data): array
    {
        $branchId = $this->currentBranchId();
        $conversion = app(UnitConversionService::class);
        $multiUnit = app(SettingsService::class)->get(auth()->user()->company_id, 'multi_unit_selling', 'Yes') !== 'No';
        $subtotal = 0;
        foreach ($data['items'] as $row) {
            $product = Product::query()->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits'])->find($row['product_id']);
            if (! $product) {
                continue;
            }
            $unit = $conversion->resolveSellingUnit(
                $product,
                $multiUnit ? ($row['product_unit_id'] ?? null) : null,
                $multiUnit ? ($row['sell_as'] ?? null) : 'base'
            );
            $payload = $this->productPayload($product, $branchId, $unit);
            $qty = (float) $row['quantity'];
            $inclusive = (bool) $payload['tax_inclusive'];
            $rate = (float) $payload['tax_rate'];
            $displayPrice = $this->lineDisplayPrice($product, $unit, $payload, $row, $inclusive, $rate);
            $subtotal += round($displayPrice * $qty, 2);
        }
        $discount = DiscountCalculator::money(
            $data['discount_type'] ?? 'fixed',
            $data['discount_value'] ?? $data['discount_amount'] ?? 0,
            $subtotal
        );

        return ['total' => round(max(0, $subtotal - $discount), 2)];
    }

    private function normalizeSalePayments($payments, float $saleTotal)
    {
        $saleTotal = round(max(0, $saleTotal), 2);
        $payments = collect($payments)->values();

        // Accidental double-fill: Cash 60 + Bank 60 on a 60 sale → keep the first method only.
        if ($saleTotal > 0 && $payments->count() > 1) {
            $allFull = $payments->every(function ($row) use ($saleTotal) {
                return abs(round((float) ($row['amount'] ?? 0), 2) - $saleTotal) < 0.009;
            });
            if ($allFull) {
                $payments = $payments->take(1)->values();
            }
        }

        $remaining = $saleTotal;
        $others = [];
        $cash = [];

        foreach ($payments as $row) {
            $method = strtolower((string) ($row['method'] ?? Payment::METHOD_CASH));
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $row['method'] = $method;
            $row['amount'] = $amount;
            if ($method === Payment::METHOD_CASH) {
                $cash[] = $row;
            } else {
                $others[] = $row;
            }
        }

        $normalized = collect();
        foreach ($others as $row) {
            if ($remaining <= 0) {
                break;
            }
            $amount = min((float) $row['amount'], $remaining);
            if ($amount <= 0) {
                continue;
            }
            $row['amount'] = round($amount, 2);
            $normalized->push($row);
            $remaining = round(max(0, $remaining - $amount), 2);
        }

        foreach ($cash as $row) {
            // Cash can exceed remaining — that excess is change.
            $normalized->push($row);
        }

        return $normalized->values();
    }
    private function productPayload(Product $product, ?int $branchId, ?array $selectedUnit = null): array
    {
        $conversion = app(UnitConversionService::class);
        $settings = app(SettingsService::class);
        $companyId = (int) $product->company_id;
        $multiUnit = $settings->get($companyId, 'multi_unit_selling', 'Yes') !== 'No';
        $units = $multiUnit ? $conversion->sellingUnitPayloads($product) : [[
            'id' => null,
            'key' => 'base',
            'name' => optional($product->unit)->name ?: 'Unit',
            'factor' => 1.0,
            'selling_price' => (float) $product->selling_price,
            'barcode' => $product->barcode,
            'is_default' => true,
        ]];
        $unit = $selectedUnit ?: ($units[0] ?? $conversion->resolveSellingUnit($product, null));
        if (! $selectedUnit) {
            foreach ($units as $candidate) {
                if (! empty($candidate['is_default'])) {
                    $unit = $candidate;
                    break;
                }
            }
        }
        $rate = (float) optional($product->tax)->rate;
        $selling = (float) $unit['selling_price'];
        $inclusive = (bool) $product->tax_inclusive;
        $price = $inclusive ? $selling : round($selling * (1 + $rate / 100), 2);
        $taxAmount = $rate > 0
            ? ($inclusive ? round($selling * $rate / (100 + $rate), 2) : round($selling * $rate / 100, 2))
            : 0;
        $stock = 0;
        if ($branchId) {
            $stock = $product->quantityAtBranch($branchId);
        }
        $displayMode = $settings->get($companyId, 'stock_display_mode', 'both');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'selling_price' => $selling,
            'wholesale_price' => (float) ($unit['wholesale_price'] ?? $product->wholesale_price),
            'price' => $price,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'tax_inclusive' => $inclusive,
            'qty' => $stock,
            'qty_display' => $conversion->stockDisplay($product, $stock, $displayMode),
            'cost' => $conversion->costPerSellingUnit($product, $unit['factor'] ?? 1),
            'image' => $product->image_path ? asset('storage/' . ltrim($product->image_path, '/')) : null,
            'unit_id' => $unit['id'] ?? null,
            'unit_name' => $unit['name'] ?? (optional($product->unit)->name ?: 'Unit'),
            'unit_factor' => $unit['factor'] ?? 1,
            'sell_as' => $unit['key'] ?? 'base',
            'packaging' => $conversion->hasPackaging($product),
            'selling_units' => $units,
            'multi_unit' => $multiUnit && count($units) > 1,
        ];
    }

    private function walkInCustomer(): Customer
    {
        $customer = Customer::query()->where('is_walk_in', true)->first();
        if ($customer) {
            return $customer;
        }

        return Customer::query()->create([
            'company_id' => auth()->user()->company_id,
            'name' => 'WALK-IN',
            'type' => Customer::TYPE_WALK_IN,
            'is_walk_in' => true,
            'is_active' => true,
        ]);
    }
}
