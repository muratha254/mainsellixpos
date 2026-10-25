<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Customer;
use App\Models\OfflineSyncKey;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
            'loyalty' => app(LoyaltyService::class)->rules((int) auth()->user()->company_id),
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

    public function offlineSnapshot(Request $request)
    {
        $this->authorizePermission('pos.view');

        $branchId = $this->currentBranchId();
        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->where('show_on_pos', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = Product::query()
            ->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits'])
            ->availableAtBranch($branchId)
            ->where('is_active', true)
            ->where('for_sale', true)
            ->orderBy('name')
            ->limit(1500)
            ->get();

        $customers = Customer::query()
            ->where('is_active', true)
            ->withSum(['sales as credit_sales' => function ($query) {
                $query->where('status', Sale::STATUS_COMPLETED);
            }], 'balance')
            ->orderByDesc('is_walk_in')
            ->orderBy('name')
            ->limit(1000)
            ->get();

        return response()->json([
            'branch_id' => $branchId,
            'user_id' => auth()->id(),
            'saved_at' => now()->toIso8601String(),
            'categories' => $categories,
            'products' => $products->map(function (Product $product) use ($branchId) {
                $payload = $this->productPayload($product, $branchId);
                unset($payload['cost']);
                $payload['category_id'] = $product->category_id;

                return $payload;
            })->values(),
            'customers' => $customers->map(function (Customer $customer) {
                return $this->customerPosPayload($customer);
            })->values(),
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
            ->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits', 'variants'])
            ->availableAtBranch($branchId)
            ->where('is_active', true)
            ->where('for_sale', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('sku', 'like', '%' . $q . '%')
                    ->orWhere('barcode', $q)
                    ->orWhereHas('sellingUnits', function ($units) use ($q) {
                        $units->where('barcode', $q);
                    })
                    ->orWhereHas('variants', function ($variants) use ($q) {
                        $variants->where('is_active', true)->where(function ($query) use ($q) {
                            $query->where('barcode', $q)
                                ->orWhere('sku', $q)
                                ->orWhere('name', 'like', '%' . $q . '%');
                        });
                    });
            })
            ->orderBy('name')
            ->limit(15)
            ->get();

        $hit = app(UnitConversionService::class)->findByBarcode(auth()->user()->company_id, $q);
        if ($hit && ! $products->contains(fn (Product $product) => (int) $product->id === (int) $hit['product_id'])) {
            $extra = Product::query()
                ->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits', 'variants'])
                ->availableAtBranch($branchId)
                ->where('is_active', true)
                ->where('for_sale', true)
                ->find($hit['product_id']);
            if ($extra) {
                $products->prepend($extra);
            }
        }

        return response()->json($products->map(function (Product $product) use ($branchId, $hit) {
            $payload = $this->productPayload($product, $branchId);
            if ($hit && (int) $hit['product_id'] === (int) $product->id) {
                if (! empty($hit['product_unit_id'])) {
                    $payload['scan_unit_id'] = (int) $hit['product_unit_id'];
                }
                if (! empty($hit['sell_as'])) {
                    $payload['scan_sell_as'] = $hit['sell_as'];
                }
                if (! empty($hit['product_variant_id'])) {
                    $payload['scan_variant_id'] = (int) $hit['product_variant_id'];
                }
            }

            return $payload;
        })->values());
    }

    public function storeCustomer(Request $request)
    {
        $this->authorizePermission('customers.create');

        if ($replay = $this->replayOfflineKey($request)) {
            return $replay;
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
        ]);

        try {
            $customer = DB::transaction(function () use ($data, $request) {
                $customer = Customer::query()->create([
                    'company_id' => auth()->user()->company_id,
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'type' => Customer::TYPE_REGULAR,
                    'is_walk_in' => false,
                    'is_active' => true,
                ]);
                $this->rememberOfflineKey($request, 'customer', $customer->id);

                return $customer;
            });
        } catch (QueryException $e) {
            if ($replay = $this->replayOfflineKey($request)) {
                return $replay;
            }
            throw $e;
        }

        return response()->json($this->customerPosPayload($customer));
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
                'print_url' => route('pos.holds.print', $sale),
                'cancel_url' => route('pos.holds.cancel', $sale),
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
                        'manage_stock' => false,
                        'allow_negative_stock' => false,
                        'unit_factor' => (float) ($item->unit_factor ?: 1),
                    ];
                if (! $item->product && can_view_item_buying_price()) {
                    $payload['cost'] = (float) $item->cost_price;
                }
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
                $payload['unit_factor'] = (float) ($item->unit_factor ?: ($payload['unit_factor'] ?? 1));
                $payload['name'] = $item->name;
                $payload['product_variant_id'] = (int) ($item->product_variant_id ?? 0);
                if ($payload['product_variant_id'] > 0 && $branchId) {
                    $payload['qty'] = app(InventoryService::class)->quantityOnHand(
                        (int) $item->company_id,
                        $branchId,
                        (int) $item->product_id,
                        $payload['product_variant_id']
                    );
                }

                return $payload;
            })->values(),
        ]);
    }

    public function cancelHold(Sale $sale)
    {
        $this->authorizePermission('pos.operate');
        abort_unless($sale->status === Sale::STATUS_HELD, 404);

        DB::transaction(function () use ($sale) {
            $sale->items()->delete();
            $sale->delete();
        });

        return response()->json([
            'held_count' => Sale::query()->where('status', Sale::STATUS_HELD)->count(),
        ]);
    }

    public function hold(Request $request, DocumentNumberService $numbers)
    {
        $this->authorizePermission('pos.operate');

        if ($replay = $this->replayOfflineKey($request)) {
            return $replay;
        }

        $data = $this->validateCart($request);
        $data['offline_key'] = $this->offlineClientKey($request);

        try {
            $sale = DB::transaction(function () use ($data, $numbers, $request) {
                $sale = $this->writeSale($data, $numbers, Sale::STATUS_HELD, 0, null);
                $this->rememberOfflineKey($request, 'hold', $sale->id);

                return $sale;
            });
        } catch (QueryException $e) {
            if ($replay = $this->replayOfflineKey($request)) {
                return $replay;
            }
            throw $e;
        }

        return response()->json([
            'id' => $sale->id,
            'number' => $sale->number,
            'print_url' => route('pos.holds.print', $sale),
            'held_count' => Sale::query()->where('status', Sale::STATUS_HELD)->count(),
        ]);
    }

    public function order(Request $request, DocumentNumberService $numbers, InventoryService $inventory, AccountingPoster $accounting, LoyaltyService $loyalty, AuditLogger $audit)
    {
        $this->authorizePermission('pos.operate');

        if ($replay = $this->replayOfflineKey($request)) {
            return $replay;
        }

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
            'loyalty_points' => 'nullable|numeric|min:0',
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

        $customer = Customer::query()->find($data['customer_id'] ?? null);
        $preview = $this->cartTotals($data);
        $loyaltyPoints = round((float) ($data['loyalty_points'] ?? 0), 2);
        $loyaltyDiscount = 0.0;
        if ($loyaltyPoints > 0) {
            if (! $customer) {
                return response()->json(['message' => 'Select a customer before redeeming points.'], 422);
            }
            try {
                $quote = $loyalty->quoteRedemption($customer, $loyaltyPoints, $preview['lines'], $preview['discount']);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $loyaltyDiscount = $quote['amount'];
            $data['loyalty_points_redeemed'] = $quote['points'];
            $data['loyalty_discount'] = $loyaltyDiscount;
        }
        $previewTotal = round(max(0, (float) $preview['total'] - $loyaltyDiscount), 2);
        $payments = $this->normalizeSalePayments($payments, $previewTotal);
        $payAmount = round((float) $payments->sum('amount'), 2);

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
        $data['offline_key'] = $this->offlineClientKey($request);

        try {
            $sale = DB::transaction(function () use ($data, $numbers, $inventory, $payAmount, $payments, $accounting, $loyalty, $audit, $request) {
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
                $loyalty->redeemForSale($sale);
                $loyalty->earnForSale($sale);
                $audit->record('create', 'sales', $sale, null, [
                    'number' => $sale->number,
                    'total' => $sale->total,
                    'paid_amount' => $sale->paid_amount,
                    'status' => $sale->status,
                ]);

                $this->rememberOfflineKey($request, 'sale', $sale->id);

                return $sale;
            });
        } catch (NegativeStockException | InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            if ($replay = $this->replayOfflineKey($request)) {
                return $replay;
            }
            throw $e;
        }

        return $this->completedSaleResponse($sale);
    }

    public function printHold(Sale $sale, SettingsService $settings)
    {
        $this->authorizePermission('pos.view');
        $this->assertOwnSale($sale);
        abort_unless($sale->status === Sale::STATUS_HELD, 404);

        return $this->receiptView($sale, $settings, true);
    }

    public function receipt(Sale $sale, SettingsService $settings)
    {
        $this->authorizePermission('pos.view');
        $this->assertOwnSale($sale);
        abort_unless($sale->status === Sale::STATUS_COMPLETED, 404);

        return $this->receiptView($sale, $settings, false);
    }

    private function assertOwnSale(Sale $sale): void
    {
        abort_unless(
            ! auth()->user()->seesOwnSalesOnly() || (int) $sale->user_id === (int) auth()->id(),
            403
        );
    }

    private function receiptView(Sale $sale, SettingsService $settings, bool $quote)
    {
        $sale->load(['items', 'customer', 'cashier', 'payments']);
        $paper = $settings->get((int) $sale->company_id, 'receipt_paper_size', '80mm');
        if (! in_array($paper, ['58mm', '80mm'], true)) {
            $paper = '80mm';
        }

        return view('pos.receipt', [
            'sale' => $sale,
            'paper' => $paper,
            'profile' => fleet_company_profile(),
            'receipt' => receipt_print_options((int) $sale->company_id),
            'paymentLabels' => config('sellix.payment_methods', []),
            'quote' => $quote,
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
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_unit_id' => 'nullable|integer',
            'items.*.sell_as' => 'nullable|string|max:32',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.apply_tax' => 'nullable',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.price_type' => 'nullable|in:R,W,P',
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
        $conversion = app(UnitConversionService::class);
        if (($row['price_type'] ?? 'R') === 'W') {
            $wholesale = $conversion->wholesaleForFactor($product, $unit['factor'] ?? 1);
            if ($wholesale > 0) {
                return $wholesale;
            }
        }
        if (($row['price_type'] ?? 'R') === 'P') {
            $promo = $conversion->promoForFactor($product, $unit['factor'] ?? 1);
            if ($promo > 0) {
                return $promo;
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
            if (! $hold && ! empty($data['offline_key'])) {
                throw new InvalidArgumentException('That held sale was already completed or removed on the server. This copy was not posted again.');
            }
        }

        $conversion = app(UnitConversionService::class);
        $settings = app(SettingsService::class);
        $multiUnit = $settings->get($companyId, 'multi_unit_selling', 'Yes') !== 'No';

        $lines = [];
        $neededBase = [];
        foreach ($data['items'] as $row) {
            $product = Product::query()->with(['tax', 'unit', 'purchaseUnit', 'sellingUnits', 'variants'])->findOrFail($row['product_id']);
            abort_unless(
                $product->isAvailableAtBranch($branchId),
                403,
                'Item is not available at the active branch.'
            );
            $variant = null;
            $variantId = 0;
            if ($product->has_variants) {
                $variantId = (int) ($row['product_variant_id'] ?? 0);
                $variant = $product->variants->first(function ($candidate) use ($variantId) {
                    return (int) $candidate->id === $variantId && $candidate->is_active;
                });
                if (! $variant) {
                    throw new InvalidArgumentException('Select a variation for ' . $product->name . '.');
                }
            }
            $unitId = $product->has_variants ? null : ($multiUnit ? ($row['product_unit_id'] ?? null) : null);
            $sellAs = $product->has_variants ? 'base' : ($multiUnit ? ($row['sell_as'] ?? null) : 'base');
            $unit = $conversion->resolveSellingUnit($product, $unitId, $sellAs);
            if ($variant && $variant->selling_price !== null && $this->postedUnitPrice($row) === null) {
                $unit['selling_price'] = (float) $variant->selling_price;
            }
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
            $costPerBase = ($variant && $variant->purchase_price !== null)
                ? (float) $variant->purchase_price
                : $conversion->costPerBase($product);
            $costEach = round($costPerBase * (float) ($unit['factor'] ?? 1), 4);
            $lines[] = [
                'product' => $product,
                'variant' => $variant,
                'variant_id' => $variantId,
                'quantity' => $qty,
                'quantity_base' => $qtyBase,
                'unit_id' => $unit['id'],
                'unit_name' => $unit['name'],
                'unit_factor' => $unit['factor'],
                'unit_price' => $unitPrice,
                'display_price' => $displayPrice,
                'cost_price' => $costEach,
                'cost_per_base' => $costPerBase,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ];
            $stockKey = $product->id . ':' . $variantId;
            $neededBase[$stockKey] = ($neededBase[$stockKey] ?? 0) + $qtyBase;
        }

        if ($inventory && $status === Sale::STATUS_COMPLETED) {
            foreach ($neededBase as $stockKey => $need) {
                [$productId, $variantId] = array_pad(explode(':', (string) $stockKey, 2), 2, 0);
                $match = collect($lines)->first(function ($line) use ($productId, $variantId) {
                    return (int) $line['product']->id === (int) $productId
                        && (int) $line['variant_id'] === (int) $variantId;
                });
                $product = $match['product'];
                if (! $product->manage_stock || $product->allow_negative_stock) {
                    continue;
                }
                $onHand = $inventory->quantityOnHand($companyId, $branchId, (int) $productId, (int) $variantId);
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
        $cashierDiscount = DiscountCalculator::money($discountType, $discountValue, $subtotal);
        $loyaltyDiscount = round(max(0, (float) ($data['loyalty_discount'] ?? 0)), 2);
        $discount = round($cashierDiscount + $loyaltyDiscount, 2);
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
                : (($paid <= 0 && $total > 0.009) ? Sale::PAYMENT_UNPAID : ($paid + 0.009 >= $total ? Sale::PAYMENT_PAID : Sale::PAYMENT_PARTIAL)),
            'subtotal' => $subtotal,
            'discount_percent' => DiscountCalculator::percentStored($discountType, $discountValue),
            'discount_amount' => $discount,
            'loyalty_points_redeemed' => round((float) ($data['loyalty_points_redeemed'] ?? 0), 2),
            'loyalty_discount' => $loyaltyDiscount,
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
                'product_variant_id' => $line['variant_id'],
                'product_unit_id' => $line['unit_id'],
                'name' => $line['variant']
                    ? $line['product']->name . ' (' . $line['variant']->label() . ')'
                    : $line['product']->name,
                'sku' => ($line['variant'] && $line['variant']->sku) ? $line['variant']->sku : $line['product']->sku,
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
                    'product_variant_id' => $line['variant_id'],
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
        $lines = [];
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
            $lineTotal = round($displayPrice * $qty, 2);
            $subtotal += $lineTotal;
            $lines[] = [
                'product_id' => (int) $product->id,
                'quantity' => $qty,
                'line_total' => $lineTotal,
            ];
        }
        $discount = DiscountCalculator::money(
            $data['discount_type'] ?? 'fixed',
            $data['discount_value'] ?? $data['discount_amount'] ?? 0,
            $subtotal
        );

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'total' => round(max(0, $subtotal - $discount), 2),
            'lines' => $lines,
        ];
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
            'wholesale_price' => (float) $product->wholesale_price,
            'promo_price' => (float) $product->promo_price,
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

        $payload = [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'selling_price' => $selling,
            'wholesale_price' => (float) ($unit['wholesale_price'] ?? $product->wholesale_price),
            'promo_price' => (float) ($unit['promo_price'] ?? $product->promo_price),
            'price' => $price,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'tax_inclusive' => $inclusive,
            'qty' => $stock,
            'qty_display' => $conversion->stockDisplay($product, $stock, $displayMode),
            'manage_stock' => (bool) $product->manage_stock,
            'allow_negative_stock' => (bool) $product->allow_negative_stock,
            'image' => $product->image_path ? asset('storage/' . ltrim($product->image_path, '/')) : null,
            'unit_id' => $unit['id'] ?? null,
            'unit_name' => $unit['name'] ?? (optional($product->unit)->name ?: 'Unit'),
            'unit_factor' => $unit['factor'] ?? 1,
            'sell_as' => $unit['key'] ?? 'base',
            'packaging' => $conversion->hasPackaging($product),
            'selling_units' => $units,
            'multi_unit' => $multiUnit && count($units) > 1 && ! $product->has_variants,
            'has_variants' => (bool) $product->has_variants,
            'variations' => $this->variationPayload($product, $branchId, $inclusive, $rate),
        ];

        if (can_view_item_buying_price()) {
            $payload['cost'] = $conversion->costPerSellingUnit($product, $unit['factor'] ?? 1);
        }

        return $payload;
    }

    private function variationPayload(Product $product, ?int $branchId, bool $inclusive, float $rate): array
    {
        if (! $product->has_variants) {
            return [];
        }

        $product->loadMissing('variants');
        $quantities = collect();
        if ($branchId) {
            $quantities = ProductBranchStock::query()
                ->withoutGlobalScope('branch')
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('product_variant_id', '>', 0)
                ->pluck('quantity', 'product_variant_id');
        }

        return $product->variants
            ->where('is_active', true)
            ->map(function ($variant) use ($product, $quantities, $inclusive, $rate) {
                $selling = $variant->selling_price !== null ? (float) $variant->selling_price : (float) $product->selling_price;
                $price = $inclusive ? $selling : round($selling * (1 + $rate / 100), 2);

                return [
                    'id' => $variant->id,
                    'name' => $variant->label(),
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'selling_price' => $selling,
                    'price' => $price,
                    'qty' => (float) ($quantities[$variant->id] ?? 0),
                ];
            })->values()->all();
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

    private function customerPosPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'is_walk_in' => (bool) $customer->is_walk_in,
            'credit_limit' => (float) $customer->credit_limit,
            'credit_used' => $customer->creditAmount(),
            'points' => round((float) $customer->loyalty_points, 2),
        ];
    }

    private function completedSaleResponse(Sale $sale)
    {
        $balance = $sale->customer_id
            ? (float) optional($sale->customer()->first())->loyalty_points
            : 0;

        return response()->json([
            'id' => $sale->id,
            'number' => $sale->number,
            'invoice_number' => $sale->invoice_number,
            'receipt_number' => $sale->receipt_number,
            'receipt_url' => route('pos.receipt', $sale),
            'loyalty_points' => round($balance, 2),
        ]);
    }

    private function offlineClientKey(Request $request): ?string
    {
        $key = trim((string) $request->input('offline_key', ''));
        if ($key === '' || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            return null;
        }

        return $key;
    }

    private function replayOfflineKey(Request $request)
    {
        $key = $this->offlineClientKey($request);
        if (! $key) {
            return null;
        }

        $row = OfflineSyncKey::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('client_key', $key)
            ->first();
        if (! $row) {
            return null;
        }

        if ((int) $row->user_id !== (int) auth()->id()) {
            abort(403);
        }

        if ($row->resource === 'customer') {
            $customer = Customer::query()->find($row->resource_id);
            if (! $customer) {
                return null;
            }

            return response()->json($this->customerPosPayload($customer));
        }

        $sale = Sale::query()->find($row->resource_id);
        if (! $sale) {
            return null;
        }

        if ($sale->status === Sale::STATUS_HELD) {
            return response()->json([
                'id' => $sale->id,
                'number' => $sale->number,
                'print_url' => route('pos.holds.print', $sale),
                'held_count' => Sale::query()->where('status', Sale::STATUS_HELD)->count(),
            ]);
        }

        return $this->completedSaleResponse($sale);
    }

    private function rememberOfflineKey(Request $request, string $resource, int $id): void
    {
        $key = $this->offlineClientKey($request);
        if (! $key) {
            return;
        }

        OfflineSyncKey::query()->create([
            'company_id' => auth()->user()->company_id,
            'user_id' => auth()->id(),
            'client_key' => $key,
            'resource' => $resource,
            'resource_id' => $id,
        ]);
    }
}
