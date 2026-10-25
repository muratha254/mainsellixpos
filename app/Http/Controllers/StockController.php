<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\StockAlertService;
use App\Services\UnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function manager(Request $request)
    {
        $this->authorizePermission('inventory.view');

        $user = auth()->user();
        $branchId = $this->resolveBranchId(
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        $products = Product::query()
            ->with(['category', 'brand', 'unit', 'tax', 'purchaseUnit', 'variants'])
            ->availableAtBranch($branchId)
            ->where('manage_stock', true)
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->category_id);
            })
            ->orderByDesc('id')
            ->get();

        $stock = ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        $variantStock = ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->whereIn('product_id', $products->pluck('id'))
            ->where('product_variant_id', '>', 0)
            ->get(['product_id', 'product_variant_id', 'quantity']);

        $variationChoices = [];
        foreach ($products as $product) {
            $variationChoices[$product->id] = $product->has_variants
                ? $product->variants->where('is_active', true)->map(function ($variant) use ($product, $variantStock) {
                    $row = $variantStock->first(function ($stockRow) use ($product, $variant) {
                        return (int) $stockRow->product_id === (int) $product->id
                            && (int) $stockRow->product_variant_id === (int) $variant->id;
                    });

                    return [
                        'id' => $variant->id,
                        'name' => $variant->label(),
                        'stock' => $row ? (float) $row->quantity : 0,
                    ];
                })->values()
                : [];
        }

        return view('stock.manager', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'stock.manager',
            'products' => $products,
            'stock' => $stock,
            'variantStock' => $variantStock,
            'variationChoices' => $variationChoices,
            'filters' => [
                'category_id' => $request->input('category_id'),
                'branch_id' => $branchId,
            ],
            'canViewCost' => auth()->user()->hasPermission('products.view_cost'),
            'canCreate' => auth()->user()->hasPermission('products.create'),
            'canUpdate' => auth()->user()->hasPermission('products.update'),
            'canAdjust' => auth()->user()->hasPermission('inventory.adjust'),
            'selectedBranchId' => $branchId ?: $this->currentBranchId(),
            'controlAccounts' => config('sellix.control_accounts', []),
            'adjustStatuses' => config('sellix.stock_adjust_statuses', []),
            'conversion' => app(UnitConversionService::class),
        ]));
    }

    public function adjust(Request $request, Product $product, InventoryService $inventory)
    {
        $this->authorizePermission('inventory.adjust');

        $statuses = array_keys(config('sellix.stock_adjust_statuses', ['addition' => 'Addition']));
        $accounts = array_keys(config('sellix.control_accounts', ['migration_control' => 'Migration Control']));

        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'occurred_at' => 'required|date',
            'status' => 'required|in:' . implode(',', $statuses),
            'control_account' => 'required|in:' . implode(',', $accounts),
            'quantity' => 'required|numeric|min:0.0001',
            'adjust_unit' => 'nullable|in:base,purchase',
            'product_variant_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->assertBranchAccess((int) $data['branch_id']);
        $data['branch_id'] = $this->currentBranchId();

        $qtyEntered = (float) $data['quantity'];
        $conversion = app(UnitConversionService::class);
        $product->loadMissing(['unit', 'purchaseUnit']);
        $usePurchase = ($data['adjust_unit'] ?? 'base') === 'purchase';
        $factor = $usePurchase ? $conversion->purchaseFactor($product) : 1.0;
        $qty = $conversion->toBase($qtyEntered, $factor);
        $unitName = $usePurchase ? $conversion->purchaseUnitLabel($product) : $conversion->baseUnitLabel($product);
        $inTypes = ['addition'];
        $type = StockMovement::ADJUSTMENT;
        if ($data['status'] === 'damaged') {
            $type = StockMovement::DAMAGE;
        } elseif ($data['status'] === 'issued') {
            $type = StockMovement::ISSUE;
        }

        $variantId = (int) ($data['product_variant_id'] ?? 0);
        if ($product->has_variants) {
            $variant = $product->variants()->where('is_active', true)->find($variantId);
            if (! $variant) {
                return back()->withInput()->with('error', 'Select a variation before adjusting stock.');
            }
            $variantId = (int) $variant->id;
        } else {
            $variantId = 0;
        }

        $payload = [
            'company_id' => $product->company_id,
            'branch_id' => (int) $data['branch_id'],
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'type' => $type,
            'unit_cost' => $conversion->costPerBase($product),
            'unit_name' => $unitName,
            'unit_factor' => $factor,
            'quantity_entered' => $qtyEntered,
            'user_id' => auth()->id(),
            'notes' => trim(($data['notes'] ?? '') . ' [' . $data['control_account'] . '] ' . $qtyEntered . ' ' . $unitName),
            'reference_number' => strtoupper($data['status']),
            'occurred_at' => $data['occurred_at'],
        ];

        if (in_array($data['status'], $inTypes, true)) {
            $payload['quantity_in'] = $qty;
        } else {
            $payload['quantity_out'] = $qty;
        }

        try {
            $inventory->apply($payload);
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $batch = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $data['branch_id'])
            ->orderByDesc('id')
            ->first();

        if ($batch) {
            $delta = in_array($data['status'], $inTypes, true) ? $qty : -$qty;
            $batch->balance_qty = round((float) $batch->balance_qty + $delta, 4);
            $batch->save();
        }

        return back()->with('success', 'Stock adjusted.');
    }

    public function updatePrice(Request $request, Product $product, AuditLogger $audit)
    {
        $this->authorizePermission('products.update');

        $accounts = array_keys(config('sellix.control_accounts', ['migration_control' => 'Migration Control']));

        $data = $request->validate([
            'purchase_price' => (can_view_item_buying_price() ? 'required' : 'nullable').'|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'wholesale_price' => 'required|numeric|min:0',
            'promo_price' => 'required|numeric|min:0',
            'control_account' => 'required|in:' . implode(',', $accounts),
        ]);

        $before = $product->only(['name', 'purchase_price', 'selling_price', 'wholesale_price', 'promo_price']);
        $purchasePrice = can_view_item_buying_price()
            ? $data['purchase_price']
            : $product->purchase_price;
        $product->update([
            'purchase_price' => $purchasePrice,
            'selling_price' => $data['selling_price'],
            'wholesale_price' => $data['wholesale_price'],
            'promo_price' => $data['promo_price'],
        ]);

        $audit->record('price_change', 'products', $product, $before, [
            'name' => $product->name,
            'purchase_price' => $purchasePrice,
            'selling_price' => $data['selling_price'],
            'wholesale_price' => $data['wholesale_price'],
            'promo_price' => $data['promo_price'],
            'control_account' => $data['control_account'],
        ]);

        return back()->with('success', 'Stock price updated.');
    }

    public function alert(StockAlertService $alerts)
    {
        $this->authorizePermission('inventory.view');

        $branchId = $this->currentBranchId();
        $products = $alerts->lowStock($branchId);
        $outOfStockCount = $products->filter(fn ($product) => (float) $product->stock_on_hand <= 0)->count();

        return view('stock.alert', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.alert',
            'products' => $products,
            'outOfStockCount' => $outOfStockCount,
        ]));
    }

    public function issued()
    {
        $this->authorizePermission('inventory.adjust');

        $movements = StockMovement::query()
            ->with(['product', 'user', 'branch'])
            ->whereIn('type', [StockMovement::ISSUE, StockMovement::DAMAGE])
            ->orderByDesc('id')
            ->get();

        return view('stock.issued', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.issued',
            'movements' => $movements,
            'canAdjust' => auth()->user()->hasPermission('inventory.adjust'),
        ]));
    }

    public function createIssued()
    {
        $this->authorizePermission('inventory.adjust');

        $products = Product::query()
            ->availableAtBranch($this->currentBranchId())
            ->where('is_active', true)
            ->where('manage_stock', true)
            ->orderBy('name')
            ->get();

        return view('stock.issued-create', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.issued',
            'products' => $products,
            'selectedBranchId' => $this->currentBranchId(),
            'productPrices' => can_view_item_buying_price()
                ? $products->mapWithKeys(function (Product $product) {
                    return [$product->id => (float) $product->purchase_price];
                })
                : collect(),
        ]));
    }

    public function storeIssued(Request $request, InventoryService $inventory)
    {
        $this->authorizePermission('inventory.adjust');

        $companyId = auth()->user()->company_id;
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.issued_at' => 'required|date',
            'items.*.notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($inventory, $data, $companyId) {
                foreach ($data['items'] as $line) {
                    $product = Product::query()->findOrFail($line['product_id']);
                    $inventory->apply([
                        'company_id' => $companyId,
                        'branch_id' => $data['branch_id'],
                        'product_id' => $product->id,
                        'type' => StockMovement::ISSUE,
                        'quantity_out' => $line['quantity'],
                        'unit_cost' => can_view_item_buying_price()
                            ? ($line['unit_price'] ?? $product->purchase_price)
                            : $product->purchase_price,
                        'user_id' => auth()->id(),
                        'notes' => $line['notes'] ?? 'Issued',
                        'reference_number' => 'ISSUED',
                        'occurred_at' => $line['issued_at'],
                    ]);
                }
            });
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock.issued')->with('success', 'Issued products saved.');
    }

    public function destroyIssued(StockMovement $movement, InventoryService $inventory)
    {
        $this->authorizePermission('inventory.adjust');
        abort_unless(in_array($movement->type, [StockMovement::ISSUE, StockMovement::DAMAGE], true), 404);

        $inventory->reverse($movement);

        return redirect()->route('stock.issued')->with('success', 'Issued product deleted.');
    }

    public function conversion()
    {
        $this->authorizePermission('inventory.adjust');

        $outs = StockMovement::query()
            ->with(['product', 'user'])
            ->where('type', StockMovement::CONVERSION)
            ->where('quantity_out', '>', 0)
            ->orderByDesc('id')
            ->get();

        $ins = StockMovement::query()
            ->with('product')
            ->where('type', StockMovement::CONVERSION)
            ->where('quantity_in', '>', 0)
            ->orderBy('id')
            ->get();

        $inByRef = $ins->keyBy('reference_id');
        $usedIn = [];
        $rows = [];

        foreach ($outs as $out) {
            $in = $inByRef->get($out->id);
            if (! $in) {
                $in = $ins->first(function ($row) use ($out, $usedIn) {
                    return empty($usedIn[$row->id])
                        && (string) $row->reference_number === (string) $out->reference_number
                        && (int) $row->user_id === (int) $out->user_id
                        && $row->id > $out->id;
                });
            }
            if ($in) {
                $usedIn[$in->id] = true;
            }

            $when = $out->occurred_at ?: $out->created_at;
            $rows[] = [
                'id' => $out->id,
                'parent' => optional($out->product)->name,
                'qty_converted' => (float) $out->quantity_out,
                'child' => optional(optional($in)->product)->name,
                'qty_produced' => $in ? (float) $in->quantity_in : 0,
                'description' => $out->notes ?: optional($in)->notes,
                'when' => $when,
                'user' => optional($out->user)->name,
            ];
        }

        return view('stock.conversion', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.conversion',
            'rows' => $rows,
        ]));
    }

    public function storeConversion(Request $request, InventoryService $inventory)
    {
        $this->authorizePermission('inventory.adjust');

        $data = $request->validate([
            'from_product_id' => 'required|exists:products,id|different:to_product_id',
            'from_quantity' => 'required|numeric|min:0.0001',
            'to_product_id' => 'required|exists:products,id',
            'to_quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string|max:1000',
        ]);

        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select a branch first.');
        $companyId = auth()->user()->company_id;

        try {
            DB::transaction(function () use ($inventory, $data, $branchId, $companyId) {
                $out = $inventory->apply([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $data['from_product_id'],
                    'type' => StockMovement::CONVERSION,
                    'quantity_out' => $data['from_quantity'],
                    'user_id' => auth()->id(),
                    'notes' => $data['notes'] ?? 'Stock conversion out',
                    'reference_number' => 'CONV',
                ]);

                $inventory->apply([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $data['to_product_id'],
                    'type' => StockMovement::CONVERSION,
                    'quantity_in' => $data['to_quantity'],
                    'user_id' => auth()->id(),
                    'notes' => $data['notes'] ?? 'Stock conversion in',
                    'reference_type' => StockMovement::class,
                    'reference_id' => $out->id,
                    'reference_number' => 'CONV',
                ]);
            });
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock.conversion')->with('success', 'Stock converted.');
    }
}
