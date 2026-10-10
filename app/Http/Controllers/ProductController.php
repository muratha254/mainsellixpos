<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission('products.view');

        $branchId = $this->currentBranchId();

        $products = Product::query()
            ->with(['category', 'brand', 'unit', 'tax', 'purchaseUnit', 'sellingUnits'])
            ->availableAtBranch($branchId)
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->category_id);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                if ($request->status === 'active') {
                    $query->where('is_active', true);
                } elseif ($request->status === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->orderByDesc('id')
            ->get();

        $stockByProduct = collect();
        $soldByProduct = collect();
        if ($branchId) {
            $productIds = $products->pluck('id');
            $stockByProduct = ProductBranchStock::query()
                ->withoutGlobalScope('branch')
                ->where('branch_id', $branchId)
                ->whereIn('product_id', $productIds)
                ->selectRaw('product_id, SUM(quantity) as quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id');
            $soldByProduct = $this->soldQtyByProduct($branchId, $productIds);
        }

        return view('products.index', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'products.index',
            'products' => $products,
            'stockByProduct' => $stockByProduct,
            'soldByProduct' => $soldByProduct,
            'selectedBranchId' => $branchId,
            'filters' => $request->only(['category_id', 'status']),
            'canCreate' => auth()->user()->hasPermission('products.create'),
            'canUpdate' => auth()->user()->hasPermission('products.update'),
            'canDelete' => auth()->user()->hasPermission('products.delete'),
            'canConvert' => auth()->user()->hasPermission('products.create'),
            'canViewCost' => auth()->user()->hasPermission('products.view_cost'),
            'currencyCode' => optional(auth()->user()->company)->currency_code ?? 'KES',
            'conversion' => app(UnitConversionService::class),
        ]));
    }

    public function create()
    {
        $this->authorizePermission('products.create');

        return view('products.form', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'products.create',
            'selectedBranchId' => $this->currentBranchId(),
            'product' => new Product([
                'is_active' => true,
                'for_sale' => true,
                'manage_stock' => true,
                'allow_negative_stock' => false,
                'tax_inclusive' => true,
                'wholesale_price' => 0,
                'promo_price' => 0,
                'profit_margin' => 0,
                'sales_commission' => 0,
                'reorder_level' => 0,
            ]),
        ]));
    }

    public function store(Request $request, InventoryService $inventory, AuditLogger $audit, UnitConversionService $conversion)
    {
        $this->authorizePermission('products.create');

        $data = $this->validated($request);
        $data['company_id'] = auth()->user()->company_id;
        $data['sku'] = $data['sku'] ?: null;
        $data['barcode'] = $data['barcode'] ?: null;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $opening = (float) $request->input('opening_stock', 0);
        $openingUnit = $this->openingUnitMode($request);
        $sellingUnits = $request->input('selling_units', []);
        unset($data['opening_stock'], $data['opening_stock_unit'], $data['branch_id'], $data['selling_units']);

        if (empty($data['barcode']) && ! empty($data['sku'])) {
            $data['barcode'] = $data['sku'];
        }

        if (empty($data['purchase_unit_id'])) {
            $data['purchase_unit_id'] = $data['unit_id'] ?? null;
        }
        $data['purchase_to_base'] = $data['purchase_to_base'] ?? 1;
        $data['unit_pricing_mode'] = $data['unit_pricing_mode'] ?? 'automatic';

        $product = Product::query()->create($data);
        $conversion->syncSellingUnits($product, is_array($sellingUnits) ? $sellingUnits : [], (int) $product->company_id);

        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select an active branch before creating items.');
        $this->assertBranchAccess($branchId);

        $openingBase = $this->openingToBase($product->fresh(['purchaseUnit', 'unit']), $opening, $openingUnit, $conversion);

        try {
            $this->ensureBranchStock($product, $branchId);
            $this->applyOpeningStock($inventory, $product, $branchId, $openingBase, StockMovement::OPENING, $this->openingNote($opening, $openingUnit, $product, $conversion));
            $this->recordBatch($product, $branchId, $openingBase);
        } catch (NegativeStockException $e) {
            return redirect()->route('products.edit', $product)->with('error', $e->getMessage());
        }

        $audit->record('create', 'products', $product, null, $product->only(['name', 'sku', 'purchase_price', 'selling_price']));

        return redirect()->route('products.index')->with('success', 'Item saved.');
    }

    public function show(Product $product)
    {
        $this->authorizePermission('products.view');
        $this->authorizeProductBranch($product);

        $product->load(['category', 'brand', 'unit', 'tax', 'purchaseUnit', 'sellingUnits']);
        $branchId = $this->currentBranchId();
        $qty = $product->quantityAtBranch($branchId);
        $soldQty = $branchId
            ? (float) ($this->soldQtyByProduct($branchId, collect([$product->id]))[$product->id] ?? 0)
            : 0.0;

        if ($branchId && $qty != 0.0 && ! $product->batches()->withoutGlobalScope('branch')->where('branch_id', $branchId)->exists()) {
            $this->recordBatch($product, $branchId, $qty);
        }

        $batches = $product->batches()
            ->withoutGlobalScope('branch')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch')
            ->orderBy('id')
            ->get();
        $children = $product->children()->with(['unit', 'brand'])->orderBy('name')->get();
        $childStock = ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $children->pluck('id'))
            ->pluck('quantity', 'product_id');
        $movements = StockMovement::query()
            ->withoutGlobalScope('branch')
            ->where('product_id', $product->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('user')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get();

        return view('products.show', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'products.index',
            'product' => $product,
            'quantity' => $qty,
            'soldQty' => $soldQty,
            'movements' => $movements,
            'batches' => $batches,
            'children' => $children,
            'childStock' => $childStock,
            'currencyCode' => optional(auth()->user()->company)->currency_code ?? 'KES',
            'canUpdate' => auth()->user()->hasPermission('products.update'),
            'canCreate' => auth()->user()->hasPermission('products.create'),
            'canViewCost' => auth()->user()->hasPermission('products.view_cost'),
            'conversion' => app(UnitConversionService::class),
        ]));
    }

    public function updateImage(Request $request, Product $product)
    {
        $this->authorizePermission('products.update');

        $request->validate([
            'image' => 'required|image|max:2048',
        ]);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->update([
            'image_path' => $request->file('image')->store('products', 'public'),
        ]);

        return back()->with('success', 'Image updated.');
    }

    public function children(Product $product)
    {
        $this->authorizePermission('products.view');

        $rows = $product->children()->with('unit')->orderBy('name')->get()->map(function (Product $child) {
            return [
                'id' => $child->id,
                'code' => $child->item_code,
                'name' => $child->name,
                'unit' => optional($child->unit)->name,
                'rate' => (float) $child->conversion_rate,
                'selling_price' => (float) $child->selling_price,
            ];
        });

        return response()->json($rows);
    }

    public function storeChild(Request $request, Product $product)
    {
        $this->authorizePermission('products.create');

        $companyId = auth()->user()->company_id;
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'name' => 'required|string|max:255',
            'conversion_rate' => 'required|numeric|min:0.0001',
            'unit_id' => ['required', Rule::exists('units', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'selling_price' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:2000',
        ]);

        $rate = (float) $data['conversion_rate'];

        Product::query()->create([
            'company_id' => $companyId,
            'parent_id' => $product->id,
            'conversion_rate' => $rate,
            'name' => $data['name'],
            'unit_id' => $data['unit_id'],
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'tax_id' => $product->tax_id,
            'description' => $data['description'] ?? null,
            'purchase_price' => $rate > 0 ? round((float) $product->purchase_price / $rate, 2) : $product->purchase_price,
            'selling_price' => $data['selling_price'],
            'wholesale_price' => $product->wholesale_price ?? 0,
            'promo_price' => $product->promo_price ?? 0,
            'reorder_level' => 0,
            'for_sale' => $product->for_sale,
            'manage_stock' => $product->manage_stock,
            'allow_negative_stock' => $product->allow_negative_stock,
            'tax_inclusive' => $product->tax_inclusive,
            'is_active' => true,
        ]);

        return back()->with('success', 'Child item created.');
    }

    public function storeChildStock(Request $request, Product $product, InventoryService $inventory)
    {
        $this->authorizePermission('products.create');

        $data = $request->validate([
            'batch_id' => 'required|exists:product_batches,id',
            'name' => 'required|string|max:255',
            'reorder_level' => 'required|numeric|min:0',
            'qty_to_convert' => 'required|numeric|min:0.0001',
            'qty_to_produce' => 'required|numeric|min:0.0001',
            'control_qty' => 'nullable|numeric|min:0.0001',
            'break_now' => 'required|in:0,1',
        ]);

        $batch = ProductBatch::query()
            ->where('product_id', $product->id)
            ->findOrFail($data['batch_id']);

        $rate = (float) $data['qty_to_produce'];
        $control = (float) ($data['control_qty'] ?? $rate);

        $child = Product::query()->firstOrNew([
            'company_id' => $product->company_id,
            'parent_id' => $product->id,
            'name' => $data['name'],
        ]);

        $child->fill([
            'unit_id' => $child->unit_id ?: $product->unit_id,
            'category_id' => $child->category_id ?: $product->category_id,
            'brand_id' => $child->brand_id ?: $product->brand_id,
            'tax_id' => $child->tax_id ?: $product->tax_id,
            'conversion_rate' => $control,
            'reorder_level' => $data['reorder_level'],
            'purchase_price' => $rate > 0 ? round((float) $product->purchase_price / $rate, 2) : $product->purchase_price,
            'selling_price' => $child->selling_price ?: $product->selling_price,
            'wholesale_price' => $child->wholesale_price ?: ($product->wholesale_price ?? 0),
            'promo_price' => $child->promo_price ?: ($product->promo_price ?? 0),
            'for_sale' => $product->for_sale,
            'manage_stock' => $product->manage_stock,
            'allow_negative_stock' => $product->allow_negative_stock,
            'tax_inclusive' => $product->tax_inclusive,
            'is_active' => true,
        ]);
        $child->save();

        if ($request->boolean('break_now')) {
            $qtyOut = (float) $data['qty_to_convert'];
            $qtyIn = round($qtyOut * $rate, 4);

            try {
                $out = $inventory->apply([
                    'company_id' => $product->company_id,
                    'branch_id' => $batch->branch_id,
                    'product_id' => $product->id,
                    'type' => StockMovement::CONVERSION,
                    'quantity_out' => $qtyOut,
                    'unit_cost' => $product->purchase_price,
                    'user_id' => auth()->id(),
                    'notes' => 'Break parent into child',
                    'reference_number' => 'CHILD',
                ]);

                $inventory->apply([
                    'company_id' => $child->company_id,
                    'branch_id' => $batch->branch_id,
                    'product_id' => $child->id,
                    'type' => StockMovement::CONVERSION,
                    'quantity_in' => $qtyIn,
                    'unit_cost' => $child->purchase_price,
                    'user_id' => auth()->id(),
                    'notes' => 'Child stock from parent',
                    'reference_type' => StockMovement::class,
                    'reference_id' => $out->id,
                    'reference_number' => 'CHILD',
                ]);
            } catch (NegativeStockException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            $batch->balance_qty = round((float) $batch->balance_qty - $qtyOut, 4);
            $batch->save();
            $this->recordBatch($child, (int) $batch->branch_id, $qtyIn);
        }

        return back()->with('success', 'Child item saved.');
    }

    public function edit(Product $product)
    {
        $this->authorizePermission('products.update');
        $this->authorizeProductBranch($product);

        $product->load(['sellingUnits', 'purchaseUnit', 'unit']);
        $conversion = app(UnitConversionService::class);
        $branchId = $this->currentBranchId();
        $currentBase = $branchId ? $product->quantityAtBranch($branchId) : 0.0;

        return view('products.form', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'products.index',
            'selectedBranchId' => $branchId,
            'product' => $product,
            'openingStock' => $this->openingDisplayQty($product, $currentBase, $conversion),
        ]));
    }

    public function update(Request $request, Product $product, AuditLogger $audit, InventoryService $inventory, UnitConversionService $conversion)
    {
        $this->authorizePermission('products.update');
        $this->authorizeProductBranch($product);

        $before = $product->only(['name', 'sku', 'purchase_price', 'selling_price', 'wholesale_price']);
        $data = $this->validated($request, $product->id);
        $data['sku'] = $data['sku'] ?: null;
        $data['barcode'] = $data['barcode'] ?: null;

        if (empty($data['barcode']) && ! empty($data['sku'])) {
            $data['barcode'] = $data['sku'];
        }

        if ($request->boolean('remove_image') && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $opening = (float) $request->input('opening_stock', 0);
        $openingUnit = $this->openingUnitMode($request);
        $sellingUnits = $request->input('selling_units', []);
        unset($data['opening_stock'], $data['opening_stock_unit'], $data['branch_id'], $data['selling_units']);

        if (empty($data['purchase_unit_id'])) {
            $data['purchase_unit_id'] = $data['unit_id'] ?? $product->unit_id;
        }
        $data['purchase_to_base'] = $data['purchase_to_base'] ?? 1;
        $data['unit_pricing_mode'] = $data['unit_pricing_mode'] ?? 'automatic';

        $product->update($data);
        $conversion->syncSellingUnits($product->fresh(), is_array($sellingUnits) ? $sellingUnits : [], (int) $product->company_id);

        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select an active branch before updating stock.');
        $this->assertBranchAccess($branchId);

        $product = $product->fresh(['unit', 'purchaseUnit']);
        $desiredBase = $this->openingToBase($product, $opening, $openingUnit, $conversion);
        $currentBase = $product->quantityAtBranch($branchId);
        $delta = $conversion->roundQty($desiredBase - $currentBase);

        try {
            $this->ensureBranchStock($product, $branchId);
            $this->applyOpeningStock($inventory, $product, $branchId, $delta, StockMovement::ADJUSTMENT, $this->openingNote($opening, $openingUnit, $product, $conversion));
            $this->recordBatch($product, $branchId, $delta);
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $after = $product->only(['name', 'sku', 'purchase_price', 'selling_price', 'wholesale_price']);
        $priceChanged = $before['purchase_price'] != $after['purchase_price']
            || $before['selling_price'] != $after['selling_price']
            || $before['wholesale_price'] != $after['wholesale_price'];

        $audit->record($priceChanged ? 'price_change' : 'update', 'products', $product, $before, $after);

        return redirect()->route('products.index')->with('success', 'Item updated.');
    }

    public function destroy(Product $product, AuditLogger $audit)
    {
        $this->authorizePermission('products.delete');
        $this->authorizeProductBranch($product);

        $audit->record('delete', 'products', $product, $product->only(['name', 'sku']), null);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Item deleted.');
    }

    public function search(Request $request)
    {
        $this->authorizePermission('products.view');

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $branchId = $this->currentBranchId();
        $products = Product::query()
            ->with(['tax', 'unit', 'purchaseUnit'])
            ->availableAtBranch($branchId)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('sku', 'like', '%' . $q . '%')
                    ->orWhere('barcode', 'like', '%' . $q . '%')
                    ->orWhereHas('sellingUnits', function ($units) use ($q) {
                        $units->where('barcode', 'like', '%' . $q . '%');
                    });
            })
            ->orderBy('name')
            ->limit(15)
            ->get();

        return response()->json($products->map(function (Product $product) use ($branchId) {
            $conversion = app(UnitConversionService::class);
            $stock = $product->quantityAtBranch($branchId);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'purchase_price' => (float) $product->purchase_price,
                'selling_price' => (float) $product->selling_price,
                'tax_rate' => (float) optional($product->tax)->rate,
                'stock' => $stock,
                'stock_display' => $conversion->stockDisplay($product, $stock),
                'purchase_unit' => $conversion->purchaseUnitLabel($product),
                'purchase_to_base' => $conversion->purchaseFactor($product),
                'expiry' => optional($product->expiry_date)->format('Y-m-d'),
            ];
        }));
    }

    public function labels()
    {
        $this->authorizePermission('products.view');

        return view('products.labels', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'products.labels',
        ]));
    }

    public function previewLabels(Request $request)
    {
        $this->authorizePermission('products.view');

        $payload = $this->labelSelection($request);
        if ($payload === null) {
            return response()->json(['message' => 'Add at least one item.'], 422);
        }

        return view('products.partials.label-stickers', $payload);
    }

    public function printLabels(Request $request)
    {
        $this->authorizePermission('products.view');

        $payload = $this->labelSelection($request);
        if ($payload === null) {
            return back()->with('error', 'Add at least one item.');
        }

        return view('products.labels-print', array_merge($payload, [
            'autoPrint' => $request->boolean('auto_print'),
            'systemName' => fleet_system_name(),
        ]));
    }

    private function labelSelection(Request $request): ?array
    {
        $items = $request->input('items', []);
        if (! is_array($items) || count($items) === 0) {
            return null;
        }

        $quantities = [];
        foreach ($items as $id => $qty) {
            $id = (int) $id;
            $qty = max(1, min(500, (int) $qty));
            if ($id > 0) {
                $quantities[$id] = $qty;
            }
        }

        if (count($quantities) === 0) {
            return null;
        }

        $products = Product::query()->whereIn('id', array_keys($quantities))->orderBy('name')->get();
        if ($products->isEmpty()) {
            return null;
        }

        $profile = fleet_company_profile();

        return [
            'products' => $products,
            'quantities' => $quantities,
            'companyName' => $profile['companyName'] ?? fleet_system_name(),
        ];
    }

    public function priceLog()
    {
        $this->authorizePermission('products.view');

        $logs = AuditLog::query()
            ->with('user')
            ->where('module', 'products')
            ->where('action', 'price_change')
            ->orderByDesc('created_at')
            ->get();

        $productNames = Product::withTrashed()
            ->whereIn('id', $logs->pluck('auditable_id')->filter()->unique())
            ->pluck('name', 'id');

        return view('products.price-log', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'products.prices',
            'logs' => $logs,
            'productNames' => $productNames,
        ]));
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge([
            'sku' => $request->filled('sku') ? $request->input('sku') : null,
            'barcode' => $request->filled('barcode') ? $request->input('barcode') : null,
            'brand_id' => $request->filled('brand_id') ? $request->input('brand_id') : null,
            'expiry_date' => $request->filled('expiry_date') ? $request->input('expiry_date') : null,
        ]);

        $companyId = auth()->user()->company_id;

        $uniqueSku = Rule::unique('products', 'sku')
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->whereNull('deleted_at');
            });
        $uniqueBarcode = Rule::unique('products', 'barcode')
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->whereNull('deleted_at');
            });

        if ($ignoreId) {
            $uniqueSku->ignore($ignoreId);
            $uniqueBarcode->ignore($ignoreId);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => ['nullable', 'string', 'max:64', $uniqueSku],
            'barcode' => ['nullable', 'string', 'max:64', $uniqueBarcode],
            'description' => 'nullable|string|max:2000',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'category_id' => ['required', Rule::exists('product_categories', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'unit_id' => ['required', Rule::exists('units', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'purchase_unit_id' => ['nullable', Rule::exists('units', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'purchase_to_base' => 'nullable|numeric|min:0.000001',
            'unit_pricing_mode' => 'nullable|in:manual,automatic',
            'base_selling_price' => 'nullable|numeric|min:0',
            'base_wholesale_price' => 'nullable|numeric|min:0',
            'tax_id' => ['required', Rule::exists('taxes', 'id')->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })],
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'promo_price' => 'nullable|numeric|min:0',
            'profit_margin' => 'nullable|numeric',
            'sales_commission' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'size' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:64',
            'image' => 'nullable|image|max:2048',
            'remove_image' => 'nullable|boolean',
            'manage_stock' => 'nullable|boolean',
            'allow_negative_stock' => 'nullable|boolean',
            'for_sale' => 'nullable|boolean',
            'tax_inclusive' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'opening_stock' => 'nullable|numeric',
            'opening_stock_unit' => 'nullable|in:base,purchase',
        ]);

        $data['manage_stock'] = $request->boolean('manage_stock');
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['for_sale'] = $request->boolean('for_sale');
        $data['tax_inclusive'] = $request->boolean('tax_inclusive');
        $data['is_active'] = true;
        $data['purchase_price'] = $data['purchase_price'] ?? 0;
        $data['wholesale_price'] = $data['wholesale_price'] ?? 0;
        $data['promo_price'] = $data['promo_price'] ?? 0;
        $data['profit_margin'] = $data['profit_margin'] ?? 0;
        $data['sales_commission'] = $data['sales_commission'] ?? 0;
        $data['reorder_level'] = $data['reorder_level'] ?? 0;
        $data['purchase_to_base'] = $data['purchase_to_base'] ?? 1;
        $data['unit_pricing_mode'] = $data['unit_pricing_mode'] ?? 'automatic';
        if (! isset($data['base_selling_price']) || $data['base_selling_price'] === '' || $data['base_selling_price'] === null) {
            $data['base_selling_price'] = null;
        }
        if (! isset($data['base_wholesale_price']) || $data['base_wholesale_price'] === '' || $data['base_wholesale_price'] === null) {
            $data['base_wholesale_price'] = null;
        }
        $data['branch_id'] = $this->currentBranchId();

        return $data;
    }

    private function openingUnitMode(Request $request): string
    {
        return $request->input('opening_stock_unit', 'purchase') === 'base' ? 'base' : 'purchase';
    }

    private function openingDisplayQty(Product $product, float $baseQty, UnitConversionService $conversion): string
    {
        if (! $product->manage_stock) {
            return '';
        }
        if ($conversion->hasPackaging($product)) {
            $qty = $conversion->fromBase($baseQty, $conversion->purchaseFactor($product));
        } else {
            $qty = $conversion->roundQty($baseQty);
        }

        return $conversion->formatQty($qty);
    }

    private function openingToBase(Product $product, float $opening, string $unitMode, UnitConversionService $conversion): float
    {
        if ($opening == 0.0) {
            return 0.0;
        }
        if ($unitMode === 'base') {
            return $conversion->roundQty($opening);
        }

        return $conversion->toBase($opening, $conversion->purchaseFactor($product));
    }

    private function openingNote(float $entered, string $unitMode, Product $product, UnitConversionService $conversion): string
    {
        if ($entered == 0.0) {
            return 'Opening stock';
        }
        $label = $unitMode === 'purchase'
            ? $conversion->purchaseUnitLabel($product)
            : $conversion->baseUnitLabel($product);

        return 'Opening stock ' . $conversion->formatQty($entered) . ' ' . $label;
    }

    private function authorizeProductBranch(Product $product): void
    {
        abort_unless(
            $product->isAvailableAtBranch($this->currentBranchId()),
            403,
            'This item is not available at the active branch.'
        );
    }

    private function ensureBranchStock(Product $product, int $branchId): void
    {
        if (! $product->manage_stock || ! $branchId) {
            return;
        }

        ProductBranchStock::query()
            ->withoutGlobalScope('company')
            ->withoutGlobalScope('branch')
            ->firstOrCreate(
                [
                    'company_id' => $product->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'product_variant_id' => 0,
                ],
                [
                    'quantity' => 0,
                    'average_cost' => (float) ($product->purchase_price ?? 0),
                ]
            );
    }

    private function applyOpeningStock(
        InventoryService $inventory,
        Product $product,
        int $branchId,
        float $opening,
        string $type,
        string $notes
    ): void {
        if ($opening == 0.0 || ! $product->manage_stock || ! $branchId) {
            return;
        }

        $payload = [
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'type' => $type,
            'unit_cost' => $product->purchase_price,
            'user_id' => auth()->id(),
            'notes' => $notes,
        ];

        if ($opening > 0) {
            $payload['quantity_in'] = $opening;
        } else {
            $payload['quantity_out'] = abs($opening);
        }

        $inventory->apply($payload);
    }

    private function soldQtyByProduct(int $branchId, $productIds)
    {
        if ($productIds instanceof \Illuminate\Support\Collection) {
            $productIds = $productIds->all();
        }
        $productIds = array_values(array_filter(array_map('intval', (array) $productIds)));
        if (! $productIds || ! $branchId) {
            return collect();
        }

        $base = StockMovement::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds);

        $sold = (clone $base)
            ->whereIn('type', [StockMovement::POS_SALE, StockMovement::SALE])
            ->selectRaw('product_id, SUM(quantity_out) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $returned = (clone $base)
            ->whereIn('type', [StockMovement::SALE_RETURN, StockMovement::SALE_VOID])
            ->selectRaw('product_id, SUM(quantity_in) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return $sold->map(function ($qty, $productId) use ($returned) {
            return round(max(0, (float) $qty - (float) ($returned[$productId] ?? 0)), 6);
        });
    }

    private function recordBatch(Product $product, int $branchId, float $qty): void
    {
        if ($qty == 0.0 || ! $branchId || ! $product->manage_stock) {
            return;
        }

        $onHand = (float) ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->sum('quantity');

        ProductBatch::query()->create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'batch_date' => now()->toDateString(),
            'expiry_date' => optional($product->expiry_date)->format('Y-m-d'),
            'cost_price' => $product->purchase_price ?? 0,
            'retail_price' => $product->selling_price ?? 0,
            'wholesale_price' => $product->wholesale_price ?? 0,
            'promo_price' => $product->promo_price ?? 0,
            'stocked_qty' => abs($qty),
            'balance_qty' => $onHand,
            'is_active' => true,
        ]);
    }
}
