<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\PurchaseOrderItem;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariationService
{
    public const MAX_COMBINATIONS = 100;

    public function sync(
        Product $product,
        bool $enabled,
        array $attributes,
        array $postedVariations,
        int $branchId,
        InventoryService $inventory,
        bool $previouslyEnabled
    ): void {
        if (! $enabled) {
            $this->disable($product);

            return;
        }

        if (! $previouslyEnabled) {
            $baseQty = (float) ProductBranchStock::query()
                ->withoutGlobalScope('branch')
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->where('product_variant_id', 0)
                ->sum('quantity');
            if ($baseQty > 0.0001) {
                throw ValidationException::withMessages([
                    'has_variants' => 'Adjust the current stock to zero before adding variations. Then enter the quantity on each variation.',
                ]);
            }
        }

        $normalized = $this->normalizeAttributes($attributes);
        $combinations = $this->combinations($normalized);
        if (count($combinations) > self::MAX_COMBINATIONS) {
            throw ValidationException::withMessages([
                'variation_payload' => 'That is ' . count($combinations) . ' variations. Keep the list at ' . self::MAX_COMBINATIONS . ' or fewer.',
            ]);
        }

        $overrides = [];
        foreach ($postedVariations as $row) {
            $signature = (string) ($row['signature'] ?? '');
            if ($signature !== '') {
                $overrides[$signature] = $row;
            }
        }

        $canCost = ! function_exists('can_view_item_buying_price') || can_view_item_buying_price();
        $companyId = (int) $product->company_id;

        DB::transaction(function () use ($product, $normalized, $combinations, $overrides, $canCost, $companyId, $branchId, $inventory) {
            $product->variantAttributes()->delete();
            foreach ($normalized as $index => $attribute) {
                $row = ProductVariantAttribute::query()->create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'name' => $attribute['name'],
                    'sort_order' => $index,
                ]);
                foreach ($attribute['values'] as $valueIndex => $value) {
                    $row->values()->create([
                        'value' => $value,
                        'sort_order' => $valueIndex,
                    ]);
                }
            }

            $keep = [];
            foreach ($combinations as $combo) {
                $posted = $overrides[$combo['signature']] ?? [];
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('signature', $combo['signature'])
                    ->first();

                $sku = $this->blankToNull($posted['sku'] ?? null);
                $barcode = $this->blankToNull($posted['barcode'] ?? null);
                $this->assertCodes($companyId, $sku, $barcode, $product->id, $variant ? (int) $variant->id : null);

                $selling = $this->nullableNumber($posted['selling_price'] ?? null);
                $reorder = $this->nullableNumber($posted['reorder_level'] ?? null);
                $active = ! array_key_exists('is_active', $posted) || filter_var($posted['is_active'], FILTER_VALIDATE_BOOLEAN);
                $purchase = $canCost
                    ? $this->nullableNumber($posted['purchase_price'] ?? null)
                    : ($variant ? $variant->purchase_price : null);

                $fields = [
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $combo['name'],
                    'options' => $combo['options'],
                    'signature' => $combo['signature'],
                    'selling_price' => $selling,
                    'purchase_price' => $purchase,
                    'reorder_level' => $reorder,
                    'is_active' => $active,
                ];

                if ($variant) {
                    $variant->update($fields);
                } else {
                    $variant = ProductVariant::query()->create($fields);
                }
                $keep[] = $variant->id;
                $this->syncStock($product, $variant, $branchId, $posted['stock'] ?? null, $inventory);
            }

            $removed = ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereNotIn('id', $keep ?: [0])
                ->get();
            foreach ($removed as $variant) {
                if ($this->hasHistory($variant) || $this->quantity($product, $variant, $branchId) > 0.0001) {
                    throw ValidationException::withMessages([
                        'variation_payload' => $variant->label() . ' has stock or sales history. Deactivate it instead of removing it.',
                    ]);
                }
                $variant->delete();
            }
        });
    }

    public function editorState(Product $product, ?int $branchId): array
    {
        $product->loadMissing(['variantAttributes.values', 'variants']);
        $attributes = $product->variantAttributes->map(function (ProductVariantAttribute $attribute) {
            return [
                'name' => $attribute->name,
                'values' => $attribute->values->pluck('value')->values()->all(),
            ];
        })->values()->all();

        $variations = $product->variants->map(function (ProductVariant $variant) use ($product, $branchId) {
            return [
                'signature' => $variant->signature,
                'name' => $variant->label(),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'selling_price' => $variant->selling_price,
                'purchase_price' => $variant->purchase_price,
                'reorder_level' => $variant->reorder_level,
                'stock' => $branchId ? $this->quantity($product, $variant, $branchId) : 0,
                'is_active' => (bool) $variant->is_active,
            ];
        })->values()->all();

        if (function_exists('can_view_item_buying_price') && ! can_view_item_buying_price()) {
            $variations = array_map(function (array $row) {
                unset($row['purchase_price']);

                return $row;
            }, $variations);
        }

        return [
            'enabled' => (bool) $product->has_variants,
            'attributes' => $attributes,
            'variations' => $variations,
        ];
    }

    private function disable(Product $product): void
    {
        $variants = $product->variants()->get();
        foreach ($variants as $variant) {
            if ($this->hasHistory($variant)) {
                throw ValidationException::withMessages([
                    'has_variants' => 'Variations that already have sales or stock movements stay on the item. Deactivate a variation instead of turning variations off.',
                ]);
            }
        }

        DB::transaction(function () use ($product) {
            ProductBranchStock::query()
                ->withoutGlobalScope('branch')
                ->where('product_id', $product->id)
                ->where('product_variant_id', '>', 0)
                ->delete();
            $product->variants()->delete();
            $product->variantAttributes()->delete();
        });
    }

    private function normalizeAttributes(array $attributes): array
    {
        $clean = [];
        $names = [];
        foreach (array_values($attributes) as $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    'variation_payload' => 'Every variation attribute needs a name.',
                ]);
            }
            $key = mb_strtolower($name);
            if (isset($names[$key])) {
                throw ValidationException::withMessages([
                    'variation_payload' => 'Attribute "' . $name . '" is listed twice.',
                ]);
            }
            $names[$key] = true;
            $values = [];
            $seen = [];
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $valueKey = mb_strtolower($value);
                if (isset($seen[$valueKey])) {
                    throw ValidationException::withMessages([
                        'variation_payload' => 'Value "' . $value . '" is repeated on ' . $name . '.',
                    ]);
                }
                $seen[$valueKey] = true;
                $values[] = $value;
            }
            if ($values === []) {
                throw ValidationException::withMessages([
                    'variation_payload' => 'Add at least one value for ' . $name . '.',
                ]);
            }
            $clean[] = ['name' => $name, 'values' => $values];
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'variation_payload' => 'Add at least one attribute, such as Size or Colour, and its values.',
            ]);
        }

        return $clean;
    }

    public function combinations(array $attributes): array
    {
        $sets = [[]];
        foreach ($attributes as $attribute) {
            $next = [];
            foreach ($sets as $set) {
                foreach ($attribute['values'] as $value) {
                    $copy = $set;
                    $copy[$attribute['name']] = $value;
                    $next[] = $copy;
                }
            }
            $sets = $next;
        }

        return array_map(function (array $options) {
            return [
                'options' => $options,
                'name' => implode(' / ', array_values($options)),
                'signature' => $this->signature($options),
            ];
        }, $sets);
    }

    private function signature(array $options): string
    {
        $pairs = [];
        foreach ($options as $name => $value) {
            $pairs[mb_strtolower((string) $name)] = mb_strtolower(trim((string) $value));
        }
        ksort($pairs);
        $chunks = [];
        foreach ($pairs as $name => $value) {
            $chunks[] = $name . '=' . $value;
        }

        return implode('|', $chunks);
    }

    private function assertCodes(int $companyId, ?string $sku, ?string $barcode, int $productId, ?int $variantId): void
    {
        if ($sku) {
            $productHit = Product::query()->where('company_id', $companyId)->where('sku', $sku)->exists();
            $variantHit = ProductVariant::query()
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->when($variantId, fn ($query) => $query->where('id', '!=', $variantId))
                ->exists();
            if ($productHit || $variantHit) {
                throw ValidationException::withMessages([
                    'variation_payload' => 'SKU ' . $sku . ' is already used.',
                ]);
            }
        }

        if ($barcode) {
            $productHit = Product::query()->where('company_id', $companyId)->where('barcode', $barcode)->exists();
            $variantHit = ProductVariant::query()
                ->where('company_id', $companyId)
                ->where('barcode', $barcode)
                ->when($variantId, fn ($query) => $query->where('id', '!=', $variantId))
                ->exists();
            $unitHit = ProductUnit::query()->where('company_id', $companyId)->where('barcode', $barcode)->exists();
            if ($productHit || $variantHit || $unitHit) {
                throw ValidationException::withMessages([
                    'variation_payload' => 'Barcode ' . $barcode . ' is already used.',
                ]);
            }
        }
    }

    private function syncStock(Product $product, ProductVariant $variant, int $branchId, $postedStock, InventoryService $inventory): void
    {
        $this->ensureStockRow($product, $variant, $branchId);
        if ($postedStock === null || $postedStock === '') {
            return;
        }
        if (! $product->manage_stock) {
            return;
        }

        $target = round((float) $postedStock, 4);
        if ($target < 0 && ! $product->allow_negative_stock) {
            throw ValidationException::withMessages([
                'variation_payload' => 'Stock for ' . $variant->label() . ' cannot be negative.',
            ]);
        }
        $current = $this->quantity($product, $variant, $branchId);
        $delta = round($target - $current, 4);
        if (abs($delta) < 0.0001) {
            return;
        }

        $payload = [
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovement::ADJUSTMENT,
            'unit_cost' => $variant->purchase_price !== null ? (float) $variant->purchase_price : (float) $product->purchase_price,
            'user_id' => auth()->id(),
            'notes' => 'Variation stock ' . $variant->label(),
            'reference_type' => Product::class,
            'reference_id' => $product->id,
            'reference_number' => $product->sku,
            'occurred_at' => now(),
        ];
        if ($delta > 0) {
            $payload['quantity_in'] = $delta;
        } else {
            $payload['quantity_out'] = abs($delta);
        }
        $inventory->apply($payload);
    }

    private function ensureStockRow(Product $product, ProductVariant $variant, int $branchId): void
    {
        ProductBranchStock::query()->withoutGlobalScope('branch')->firstOrCreate(
            [
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
            ],
            ['quantity' => 0, 'average_cost' => 0]
        );
    }

    private function quantity(Product $product, ProductVariant $variant, int $branchId): float
    {
        return (float) ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
    }

    private function hasHistory(ProductVariant $variant): bool
    {
        return StockMovement::query()->where('product_variant_id', $variant->id)->exists()
            || SaleItem::query()->where('product_variant_id', $variant->id)->exists()
            || PurchaseOrderItem::query()->where('product_variant_id', $variant->id)->exists();
    }

    private function blankToNull($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }
}
