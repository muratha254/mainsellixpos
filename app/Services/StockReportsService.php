<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Production;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockReportsService
{
    public function categoryOptions(): Collection
    {
        return ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function brandOptions(): Collection
    {
        return Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function productOptions(): Collection
    {
        return Product::query()
            ->availableAtBranch($this->activeBranchId())
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'sku']);
    }

    public function branchOptions(): Collection
    {
        $query = Branch::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->canSwitchBranches()) {
            $query->whereKey($user->branch_id);
        }

        return $query->get(['id', 'name']);
    }

    public function priceListRows(?int $branchId = null, array $filters = []): Collection
    {
        $showCost = can_view_item_buying_price() && ($filters['display_cost'] ?? 'hide') !== 'hide';
        $priceField = match ($filters['price_category'] ?? 'retail') {
            'wholesale' => 'wholesale_price',
            'promo' => 'promo_price',
            default => 'selling_price',
        };

        $rows = collect();
        $this->productQuery($filters)
            ->with(['category', 'brand', 'unit', 'tax', 'parent', 'branchStock', 'variants'])
            ->orderBy('name')
            ->get()
            ->each(function (Product $product) use ($branchId, $showCost, $priceField, $rows) {
                foreach ($this->pricedLines($product, $branchId) as $line) {
                    $selling = $priceField === 'selling_price'
                        ? $line['selling']
                        : (float) $product->{$priceField};
                    $rows->push([
                        'category' => optional($product->category)->name ?: '-',
                        'brand' => optional($product->brand)->name ?: '-',
                        'item' => $line['name'],
                        'sku' => $line['sku'] ?: '-',
                        'purchase_price' => $showCost ? round($line['purchase'], 2) : null,
                        'selling_price' => round($selling, 2),
                        'wholesale_price' => round((float) $product->wholesale_price, 2),
                        'promo_price' => round((float) $product->promo_price, 2),
                        'stock' => round($line['stock'], 2),
                    ]);
                }
            });

        return $rows->values()->map(function (array $row, int $i) {
            $row['index'] = $i + 1;

            return $row;
        });
    }

    public function stockRows(?int $branchId = null, array $filters = []): Collection
    {
        $conversion = app(\App\Services\UnitConversionService::class);
        $rows = collect();
        $this->productQuery($filters)
            ->with(['category', 'brand', 'variants'])
            ->orderBy('name')
            ->get()
            ->each(function (Product $product) use ($branchId, $rows, $conversion) {
                foreach ($this->pricedLines($product, $branchId) as $line) {
                    $rows->push([
                        'item_code' => $product->item_code,
                        'item' => $line['name'],
                        'category' => optional($product->category)->name ?: '-',
                        'brand' => optional($product->brand)->name ?: '-',
                        'purchase_price' => $this->visibleCost($line['purchase']),
                        'selling_price' => round($line['selling'], 2),
                        'stock' => round($line['stock'], 4),
                        'stock_display' => $conversion->stockDisplay($product, $line['stock']),
                        'reorder' => round($line['reorder'], 2),
                        'value' => $this->visibleCost($line['stock'] * ($line['cost_base'] ?? $line['purchase'])),
                    ]);
                }
            });

        return $rows->values()->map(function (array $row, int $i) {
            $row['index'] = $i + 1;

            return $row;
        });
    }

    public function stockAsAtRows(string $date, ?int $branchId = null, array $filters = [], bool $detailed = false): Collection
    {
        $asAt = Carbon::parse($date)->endOfDay();

        $rows = collect();
        $this->productQuery($filters)
            ->with(['category', 'brand', 'variants'])
            ->orderBy('name')
            ->get()
            ->each(function (Product $product) use ($branchId, $asAt, $detailed, $rows) {
                $lines = $product->has_variants
                    ? $product->variants->map(function ($variant) use ($product, $branchId, $asAt) {
                        return [
                            'name' => $product->name . ' (' . $variant->label() . ')',
                            'sku' => $variant->sku ?: $product->sku,
                            'purchase' => $variant->purchase_price !== null ? (float) $variant->purchase_price : (float) $product->purchase_price,
                            'selling' => $variant->selling_price !== null ? (float) $variant->selling_price : (float) $product->selling_price,
                            'reorder' => $variant->reorder_level !== null ? (float) $variant->reorder_level : (float) $product->reorder_level,
                            'stock' => $this->stockAsAt($product->id, $branchId, $asAt, (int) $variant->id),
                        ];
                    })
                    : collect([[
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'purchase' => (float) $product->purchase_price,
                        'selling' => (float) $product->selling_price,
                        'reorder' => (float) $product->reorder_level,
                        'stock' => $this->stockAsAt($product->id, $branchId, $asAt, 0),
                    ]]);

                foreach ($lines as $line) {
                    $row = [
                        'item' => $line['name'],
                        'category' => optional($product->category)->name ?: '-',
                        'brand' => optional($product->brand)->name ?: '-',
                        'purchase_price' => $this->visibleCost($line['purchase']),
                        'selling_price' => round($line['selling'], 2),
                        'stock' => round($line['stock'], 2),
                        'value' => $this->visibleCost($line['stock'] * $line['purchase']),
                    ];
                    if ($detailed) {
                        $row['item_code'] = $product->item_code;
                        $row['sku'] = $line['sku'] ?: '-';
                        $row['reorder'] = round($line['reorder'], 2);
                        $row['wholesale_price'] = round((float) $product->wholesale_price, 2);
                    }
                    $rows->push($row);
                }
            });

        return $rows->values()->map(function (array $row, int $i) {
            $row['index'] = $i + 1;

            return $row;
        });
    }

    public function templateRows(?int $branchId = null, array $filters = []): Collection
    {
        $order = ($filters['ordering'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $this->productQuery($filters)
            ->with(['category', 'unit', 'tax', 'parent'])
            ->orderBy('name', $order)
            ->get()
            ->map(function (Product $product, int $i) use ($branchId) {
                $stock = $this->productStock($product, $branchId);

                return [
                    'index' => $i + 1,
                    'product_id' => $product->id,
                    'product_code' => $product->item_code,
                    'product_name' => $product->name,
                    'sku' => $product->sku ?: '-',
                    'category' => optional($product->category)->name ?: '-',
                    'buying_price' => $this->visibleCost((float) $product->purchase_price),
                    'selling_price' => round((float) $product->selling_price, 2),
                    'wholesale_price' => round((float) $product->wholesale_price, 2),
                    'promo_price' => round((float) $product->promo_price, 2),
                    'current_stock' => round($stock, 2),
                    'reorder' => round((float) $product->reorder_level, 2),
                    'parent' => optional($product->parent)->name ?: '-',
                    'conversion_rate' => round((float) $product->conversion_rate, 2),
                    'uom' => optional($product->unit)->name ?: '-',
                    'tax' => $product->taxLabel(),
                ];
            })->values();
    }

    public function ledgerRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return $this->movementQuery($from, $to, $branchId, $productId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $movement, int $i) {
                $product = $movement->product;

                return [
                    'index' => $i + 1,
                    'branch' => optional($movement->branch)->name ?: '-',
                    'item' => $this->movementItem($movement),
                    'trans_date' => optional($movement->occurred_at)->format('d-m-Y'),
                    'action' => ucfirst(str_replace('_', ' ', (string) $movement->type)),
                    'description' => $movement->notes ?: '-',
                    'purchase_price' => $this->visibleCost((float) $movement->unit_cost),
                    'sales_price' => round((float) optional($product)->selling_price, 2),
                    'reference' => $movement->reference_number ?: '-',
                    'stock_in' => round((float) $movement->quantity_in, 2),
                    'stock_out' => round((float) $movement->quantity_out, 2),
                    'balance' => round((float) $movement->quantity_after, 2),
                ];
            })->values();
    }

    public function transferRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return StockMovement::query()
            ->with(['product', 'branch', 'user'])
            ->whereIn('type', [StockMovement::TRANSFER_OUT, StockMovement::TRANSFER_IN])
            ->whereBetween('occurred_at', [$this->start($from), $this->end($to)])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('occurred_at')
            ->get()
            ->map(function (StockMovement $movement, int $i) {
                $product = $movement->product;
                $qty = (float) $movement->quantity_in + (float) $movement->quantity_out;

                return [
                    'index' => $i + 1,
                    'from_branch' => $movement->type === StockMovement::TRANSFER_OUT ? (optional($movement->branch)->name ?: '-') : '-',
                    'to_branch' => $movement->type === StockMovement::TRANSFER_IN ? (optional($movement->branch)->name ?: '-') : '-',
                    'transfer_by' => optional($movement->user)->name ?: '-',
                    'item' => $this->movementItem($movement),
                    'trans_date' => optional($movement->occurred_at)->format('d-m-Y'),
                    'purchase_price' => $this->visibleCost((float) $movement->unit_cost),
                    'sales_price' => round((float) optional($product)->selling_price, 2),
                    'quantity' => round($qty, 2),
                ];
            })->values();
    }

    public function adjustmentRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return StockMovement::query()
            ->with(['product', 'branch', 'user'])
            ->where('type', StockMovement::ADJUSTMENT)
            ->whereBetween('occurred_at', [$this->start($from), $this->end($to)])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('occurred_at')
            ->get()
            ->map(function (StockMovement $movement, int $i) {
                $product = $movement->product;
                $cost = (float) $movement->unit_cost;
                $qty = (float) $movement->quantity_in + (float) $movement->quantity_out;

                return [
                    'index' => $i + 1,
                    'branch' => optional($movement->branch)->name ?: '-',
                    'item' => $this->movementItem($movement),
                    'trans_date' => optional($movement->occurred_at)->format('d-m-Y'),
                    'action' => 'Adjustment',
                    'description' => $movement->notes ?: '-',
                    'purchase_price' => $this->visibleCost($cost),
                    'cost_tax_inc' => $this->visibleCost($cost),
                    'sales_price' => round((float) optional($product)->selling_price, 2),
                    'stock_in' => round((float) $movement->quantity_in, 2),
                    'stock_out' => round((float) $movement->quantity_out, 2),
                    'balance' => round((float) $movement->quantity_after, 2),
                    'value' => $this->visibleCost($qty * $cost),
                    'created_by' => optional($movement->user)->name ?: '-',
                ];
            })->values();
    }

    public function alertRows(?int $branchId = null): Collection
    {
        $branchName = $branchId
            ? (optional(Branch::query()->find($branchId))->name ?: '-')
            : '-';
        $rows = collect();
        Product::query()
            ->availableAtBranch($branchId)
            ->with(['category', 'brand', 'variants'])
            ->where('is_active', true)
            ->where('manage_stock', true)
            ->orderBy('name')
            ->get()
            ->each(function (Product $product) use ($branchId, $rows, $branchName) {
                foreach ($this->pricedLines($product, $branchId) as $line) {
                    if ($line['stock'] > $line['reorder']) {
                        continue;
                    }
                    $rows->push([
                        'branch' => $branchName,
                        'item_code' => $product->item_code,
                        'item' => $line['name'],
                        'unit_price' => $this->visibleCost($line['purchase']),
                        'sales_price' => round($line['selling'], 2),
                        'reorder' => round($line['reorder'], 2),
                        'current_stock' => round($line['stock'], 2),
                    ]);
                }
            });

        return $rows->values()->map(function (array $row, int $i) {
            $row['index'] = $i + 1;

            return $row;
        });
    }

    public function valuationRows(?int $branchId = null, array $filters = []): Collection
    {
        $rows = collect();
        $this->productQuery($filters)
            ->with(['category', 'brand', 'variants'])
            ->orderBy('name')
            ->get()
            ->each(function (Product $product) use ($branchId, $rows) {
                foreach ($this->pricedLines($product, $branchId) as $line) {
                    $rows->push([
                        'item' => $line['name'],
                        'category' => optional($product->category)->name ?: '-',
                        'brand' => optional($product->brand)->name ?: '-',
                        'stock' => round($line['stock'], 2),
                        'unit_cost' => $this->visibleCost($line['purchase']),
                        'value' => $this->visibleCost($line['stock'] * $line['purchase']),
                        'sales_value' => round($line['stock'] * $line['selling'], 2),
                    ]);
                }
            });

        return $rows->values()->map(function (array $row, int $i) {
            $row['index'] = $i + 1;

            return $row;
        });
    }

    public function damagedRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return $this->movementByTypeRows($from, $to, StockMovement::DAMAGE, $branchId, $productId, 'total_sales');
    }

    public function issuedRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return $this->movementByTypeRows($from, $to, StockMovement::ISSUE, $branchId, $productId, 'total_cost');
    }

    public function consumptionRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return StockMovement::query()
            ->with(['product', 'reference'])
            ->where('type', StockMovement::ISSUE)
            ->where('reference_type', Production::class)
            ->whereBetween('occurred_at', [$this->start($from), $this->end($to)])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('occurred_at')
            ->get()
            ->map(function (StockMovement $movement, int $i) {
                $production = $movement->reference_type === Production::class
                    ? Production::query()->find($movement->reference_id)
                    : null;
                $qty = (float) $movement->quantity_out;
                $price = (float) $movement->unit_cost;

                return [
                    'index' => $i + 1,
                    'sales_date' => optional($movement->occurred_at)->format('d-m-Y'),
                    'production' => optional($production)->title ?: '-',
                    'item' => $this->movementItem($movement),
                    'qty' => round($qty, 2),
                    'buying_price' => $this->visibleCost($price),
                    'total' => $this->visibleCost($qty * $price),
                ];
            })->values();
    }

    public function productionRows(string $from, string $to, ?int $branchId = null, ?int $productId = null): Collection
    {
        return Production::query()
            ->with(['user', 'bom.product'])
            ->whereBetween('production_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, function ($q) use ($productId) {
                $q->whereHas('bom', fn ($b) => $b->where('product_id', $productId));
            })
            ->orderBy('production_date')
            ->get()
            ->map(function (Production $production, int $i) {
                return [
                    'index' => $i + 1,
                    'production_date' => optional($production->production_date)->format('d-m-Y'),
                    'posted_by' => optional($production->user)->name ?: '-',
                    'description' => $production->description ?: $production->title,
                    'expected_qty' => round((float) $production->expected_production, 2),
                    'production_cost' => $this->visibleCost((float) $production->production_cost),
                    'actual_qty' => round((float) $production->actual_production, 2),
                    'sales_cost' => round((float) optional(optional($production->bom)->product)->selling_price, 2),
                ];
            })->values();
    }

    private function movementByTypeRows(string $from, string $to, string $type, ?int $branchId, ?int $productId, string $totalKey): Collection
    {
        return StockMovement::query()
            ->with(['product', 'branch'])
            ->where('type', $type)
            ->whereBetween('occurred_at', [$this->start($from), $this->end($to)])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('occurred_at')
            ->get()
            ->map(function (StockMovement $movement, int $i) use ($totalKey) {
                $product = $movement->product;
                $qty = (float) $movement->quantity_out ?: (float) $movement->quantity_in;
                $purchase = $this->visibleCost((float) $movement->unit_cost);
                $sales = round((float) optional($product)->selling_price, 2);

                return [
                    'index' => $i + 1,
                    'branch' => optional($movement->branch)->name ?: '-',
                    'item_code' => optional($product)->item_code ?: '-',
                    'entry_date' => optional($movement->occurred_at)->format('d-m-Y'),
                    'item' => $this->movementItem($movement),
                    'qty' => round($qty, 2),
                    'purchase_price' => $purchase,
                    'sales_price' => $sales,
                    $totalKey => $totalKey === 'total_cost'
                        ? ($purchase === null ? null : round($qty * $purchase, 2))
                        : round($qty * $sales, 2),
                ];
            })->values();
    }

    private function visibleCost(float $amount): ?float
    {
        return can_view_item_buying_price() ? round($amount, 2) : null;
    }

    private function movementQuery(string $from, string $to, ?int $branchId = null, ?int $productId = null)
    {
        return StockMovement::query()
            ->with(['product', 'branch', 'user'])
            ->whereBetween('occurred_at', [$this->start($from), $this->end($to)])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId));
    }

    private function productQuery(array $filters)
    {
        return Product::query()
            ->availableAtBranch($this->activeBranchId())
            ->where('is_active', true)
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['brand_id']), fn ($q) => $q->where('brand_id', (int) $filters['brand_id']))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('id', (int) $filters['product_id']));
    }

    private function pricedLines(Product $product, ?int $branchId): array
    {
        if (! $product->has_variants) {
            $stock = $this->productStock($product, $branchId);

            return [[
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => $stock,
                'purchase' => (float) $product->purchase_price,
                'selling' => (float) $product->selling_price,
                'reorder' => (float) $product->reorder_level,
                'cost_base' => app(\App\Services\UnitConversionService::class)->costPerBase($product),
            ]];
        }

        $product->loadMissing('variants');

        return $product->variants->map(function ($variant) use ($product, $branchId) {
            $purchase = $variant->purchase_price !== null ? (float) $variant->purchase_price : (float) $product->purchase_price;

            return [
                'name' => $product->name . ' (' . $variant->label() . ')',
                'sku' => $variant->sku ?: $product->sku,
                'stock' => $this->variantStock($product, (int) $variant->id, $branchId),
                'purchase' => $purchase,
                'selling' => $variant->selling_price !== null ? (float) $variant->selling_price : (float) $product->selling_price,
                'reorder' => $variant->reorder_level !== null ? (float) $variant->reorder_level : (float) $product->reorder_level,
                'cost_base' => $purchase,
            ];
        })->all();
    }

    private function variantStock(Product $product, int $variantId, ?int $branchId): float
    {
        $query = $product->branchStock()->where('product_variant_id', $variantId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('quantity');
    }

    private function movementItem(StockMovement $movement): string
    {
        $name = optional($movement->product)->name ?: '-';
        $variantId = (int) ($movement->product_variant_id ?? 0);
        if ($variantId <= 0) {
            return $name;
        }
        $variant = $movement->relationLoaded('variant') ? $movement->variant : $movement->variant()->first();

        return $variant ? $name . ' (' . $variant->label() . ')' : $name;
    }

    private function productStock(Product $product, ?int $branchId): float
    {
        $query = $product->branchStock();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('quantity');
    }

    private function stockAsAt(int $productId, ?int $branchId, Carbon $asAt, ?int $variantId = null): float
    {
        $query = StockMovement::query()
            ->where('product_id', $productId)
            ->where('occurred_at', '<=', $asAt);

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $in = (float) (clone $query)->sum('quantity_in');
        $out = (float) (clone $query)->sum('quantity_out');

        return $in - $out;
    }

    private function start(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    private function end(string $date): Carbon
    {
        return Carbon::parse($date)->endOfDay();
    }

    private function activeBranchId(): ?int
    {
        if (! app()->bound('currentBranchId')) {
            return null;
        }

        $branchId = (int) app('currentBranchId');

        return $branchId > 0 ? $branchId : null;
    }
}
