<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\Unit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnitConversionService
{
    public const SCALE = 6;

    public const MODE_MANUAL = 'manual';

    public const MODE_AUTOMATIC = 'automatic';

    public function roundQty($qty): float
    {
        return round((float) $qty, self::SCALE);
    }

    public function toBase($quantity, $factor): float
    {
        $factor = (float) $factor;
        if ($factor <= 0) {
            $factor = 1.0;
        }

        return $this->roundQty((float) $quantity * $factor);
    }

    public function fromBase($baseQty, $factor): float
    {
        $factor = (float) $factor;
        if ($factor <= 0) {
            $factor = 1.0;
        }

        return $this->roundQty((float) $baseQty / $factor);
    }

    public function purchaseFactor(Product $product): float
    {
        $factor = (float) ($product->purchase_to_base ?? 1);
        if ($factor <= 0) {
            return 1.0;
        }

        return $factor;
    }

    public function costPerBase(Product $product): float
    {
        $factor = $this->purchaseFactor($product);

        return round((float) $product->purchase_price / $factor, 6);
    }

    public function costPerSellingUnit(Product $product, $factorToBase): float
    {
        return round($this->costPerBase($product) * $this->positiveFactor($factorToBase), 6);
    }

    public function hasPackaging(Product $product): bool
    {
        if (! $product->purchase_unit_id || (int) $product->purchase_unit_id === (int) $product->unit_id) {
            return false;
        }

        return $this->purchaseFactor($product) != 1.0;
    }

    public function pricePerBase(Product $product): float
    {
        if ($product->base_selling_price !== null && (float) $product->base_selling_price > 0) {
            return round((float) $product->base_selling_price, 4);
        }

        $factor = $this->purchaseFactor($product);
        if ($factor == 1.0) {
            return round((float) $product->selling_price, 4);
        }

        return round((float) $product->selling_price / $factor, 4);
    }

    public function wholesalePerBase(Product $product): float
    {
        if ($product->base_wholesale_price !== null && (float) $product->base_wholesale_price > 0) {
            return round((float) $product->base_wholesale_price, 4);
        }

        $factor = $this->purchaseFactor($product);
        $pack = (float) $product->wholesale_price;
        if ($factor == 1.0) {
            return round($pack, 4);
        }

        return round($pack / $factor, 4);
    }

    public function wholesaleForFactor(Product $product, $factorToBase): float
    {
        $factor = $this->positiveFactor($factorToBase);
        $packFactor = $this->purchaseFactor($product);
        if ($this->hasPackaging($product) && abs($factor - $packFactor) < 0.0000001) {
            return round((float) $product->wholesale_price, 4);
        }

        return round($this->wholesalePerBase($product) * $factor, 4);
    }

    public function promoPerBase(Product $product): float
    {
        $factor = $this->purchaseFactor($product);
        $pack = (float) $product->promo_price;
        if ($factor == 1.0) {
            return round($pack, 4);
        }

        return round($pack / $factor, 4);
    }

    public function promoForFactor(Product $product, $factorToBase): float
    {
        $factor = $this->positiveFactor($factorToBase);
        $packFactor = $this->purchaseFactor($product);
        if ($this->hasPackaging($product) && abs($factor - $packFactor) < 0.0000001) {
            return round((float) $product->promo_price, 4);
        }

        return round($this->promoPerBase($product) * $factor, 4);
    }

    public function packSellingPrice(Product $product): float
    {
        return round((float) $product->selling_price, 4);
    }

    public function sellingPrice(Product $product, ?ProductUnit $unit): float
    {
        if (! $unit) {
            return (float) $product->selling_price;
        }

        $factor = $this->positiveFactor($unit->factor_to_base);
        if (abs($factor - 1.0) < 0.0000001) {
            return $this->pricePerBase($product);
        }
        if (abs($factor - $this->purchaseFactor($product)) < 0.0000001) {
            return $this->packSellingPrice($product);
        }
        if (($product->unit_pricing_mode ?: self::MODE_AUTOMATIC) === self::MODE_AUTOMATIC) {
            return round($this->pricePerBase($product) * $factor, 4);
        }

        return (float) $unit->selling_price;
    }

    public function formatQty($qty): string
    {
        $formatted = number_format((float) $qty, 4, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    public function baseUnitLabel(Product $product): string
    {
        return optional($product->unit)->short_name
            ?: optional($product->unit)->name
            ?: 'unit';
    }

    public function purchaseUnitLabel(Product $product): string
    {
        return optional($product->purchaseUnit)->short_name
            ?: optional($product->purchaseUnit)->name
            ?: $this->baseUnitLabel($product);
    }

    public function stockDisplay(Product $product, $baseQty, string $mode = 'both'): string
    {
        $baseQty = $this->roundQty($baseQty);
        $baseLabel = $this->baseUnitLabel($product);
        $baseText = $this->formatQty($baseQty) . ' ' . $baseLabel;
        $factor = $this->purchaseFactor($product);
        $sameUnit = ! $product->purchase_unit_id || (int) $product->purchase_unit_id === (int) $product->unit_id || $factor == 1.0;
        $purchaseLabel = $this->purchaseUnitLabel($product);

        if ($sameUnit) {
            return $baseText;
        }

        $whole = (int) floor(($baseQty + 0.0000001) / $factor);
        $remainder = $this->roundQty($baseQty - ($whole * $factor));
        if ($remainder < 0) {
            $remainder = 0.0;
        }

        if ($remainder < 0.00005) {
            $purchaseText = $whole . ' ' . $purchaseLabel;
        } elseif ($whole <= 0) {
            $purchaseText = $baseText;
        } else {
            $purchaseText = $whole . ' ' . $purchaseLabel . ' + ' . $this->formatQty($remainder) . ' ' . $baseLabel;
        }

        if ($mode === 'base') {
            return $baseText;
        }
        if ($mode === 'purchase') {
            return $purchaseText;
        }

        if ($purchaseText === $baseText) {
            return $baseText;
        }

        return $baseText . ' (' . $purchaseText . ')';
    }

    /**
     * @return array{id:?int,key:string,name:string,factor:float,selling_price:float,barcode:?string,is_default?:bool}
     */
    public function resolveSellingUnit(Product $product, $productUnitId = null, $sellAs = null): array
    {
        $options = $this->sellingUnitPayloads($product);

        if ($productUnitId) {
            foreach ($options as $option) {
                if (! empty($option['id']) && (int) $option['id'] === (int) $productUnitId) {
                    return $option;
                }
            }
        }

        $sellAs = $sellAs ? strtolower((string) $sellAs) : null;
        if ($sellAs) {
            foreach ($options as $option) {
                if (($option['key'] ?? '') === $sellAs) {
                    return $option;
                }
            }
        }

        foreach ($options as $option) {
            if (! empty($option['is_default'])) {
                return $option;
            }
        }

        return $options[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sellingUnitPayloads(Product $product): array
    {
        $options = [];

        if ($this->hasPackaging($product)) {
            $options[] = [
                'id' => null,
                'key' => 'purchase',
                'name' => $this->purchaseUnitLabel($product),
                'factor' => $this->purchaseFactor($product),
                'selling_price' => $this->packSellingPrice($product),
                'wholesale_price' => round((float) $product->wholesale_price, 4),
                'promo_price' => round((float) $product->promo_price, 4),
                'barcode' => $product->barcode,
                'is_default' => true,
            ];
            $options[] = [
                'id' => null,
                'key' => 'base',
                'name' => $this->baseUnitLabel($product),
                'factor' => 1.0,
                'selling_price' => $this->pricePerBase($product),
                'wholesale_price' => $this->wholesalePerBase($product),
                'promo_price' => $this->promoPerBase($product),
                'barcode' => null,
                'is_default' => false,
            ];
        } else {
            $options[] = [
                'id' => null,
                'key' => 'base',
                'name' => $this->baseUnitLabel($product),
                'factor' => 1.0,
                'selling_price' => (float) $product->selling_price,
                'wholesale_price' => round((float) $product->wholesale_price, 4),
                'promo_price' => round((float) $product->promo_price, 4),
                'barcode' => $product->barcode,
                'is_default' => true,
            ];
        }

        $units = $product->relationLoaded('sellingUnits')
            ? $product->sellingUnits
            : $product->sellingUnits()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        foreach ($units->where('is_active', true) as $unit) {
            $factor = $this->positiveFactor($unit->factor_to_base);
            $duplicate = false;
            foreach ($options as $option) {
                if (abs($option['factor'] - $factor) < 0.0000001
                    && mb_strtolower($option['name']) === mb_strtolower($unit->name)) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                continue;
            }
            $options[] = [
                'id' => (int) $unit->id,
                'key' => 'extra:' . $unit->id,
                'name' => $unit->name,
                'factor' => $factor,
                'selling_price' => $this->sellingPrice($product, $unit),
                'wholesale_price' => $this->wholesaleForFactor($product, $factor),
                'promo_price' => $this->promoForFactor($product, $factor),
                'barcode' => $unit->barcode,
                'is_default' => false,
            ];
        }

        return $options;
    }

    public function findByBarcode(int $companyId, string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $unit = ProductUnit::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('barcode', $barcode)
            ->first();

        if ($unit) {
            return ['product_id' => (int) $unit->product_id, 'product_unit_id' => (int) $unit->id, 'sell_as' => 'extra:' . $unit->id];
        }

        $variant = ProductVariant::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($barcode) {
                $query->where('barcode', $barcode)->orWhere('sku', $barcode);
            })
            ->first();

        if ($variant) {
            return [
                'product_id' => (int) $variant->product_id,
                'product_variant_id' => (int) $variant->id,
                'product_unit_id' => null,
                'sell_as' => 'base',
            ];
        }

        $product = Product::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('barcode', $barcode)
            ->first();

        if ($product) {
            return [
                'product_id' => (int) $product->id,
                'product_unit_id' => null,
                'sell_as' => $this->hasPackaging($product) ? 'purchase' : 'base',
            ];
        }

        return null;
    }

    public function syncSellingUnits(Product $product, array $rows, int $companyId): void
    {
        $keep = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'selling_units' => 'Duplicate selling unit: ' . $name,
                ]);
            }
            $seen[$key] = true;

            $factor = (float) ($row['factor_to_base'] ?? 0);
            if ($factor <= 0) {
                throw ValidationException::withMessages([
                    'selling_units' => 'Conversion factor must be greater than 0 for ' . $name,
                ]);
            }

            $price = (float) ($row['selling_price'] ?? 0);
            if ($price < 0) {
                throw ValidationException::withMessages([
                    'selling_units' => 'Selling price cannot be negative for ' . $name,
                ]);
            }

            $unitId = ! empty($row['unit_id']) ? (int) $row['unit_id'] : null;
            if ($unitId) {
                $owned = Unit::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->whereKey($unitId)
                    ->exists();
                if (! $owned) {
                    throw ValidationException::withMessages([
                        'selling_units' => 'Unit does not belong to this business.',
                    ]);
                }
            }

            $barcode = trim((string) ($row['barcode'] ?? '')) ?: null;
            if ($barcode) {
                $taken = ProductUnit::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('barcode', $barcode)
                    ->when(! empty($row['id']), fn ($q) => $q->where('id', '!=', (int) $row['id']))
                    ->exists();
                if ($taken) {
                    throw ValidationException::withMessages([
                        'selling_units' => 'Barcode already used: ' . $barcode,
                    ]);
                }
            }

            $payload = [
                'company_id' => $companyId,
                'product_id' => $product->id,
                'unit_id' => $unitId,
                'name' => $name,
                'factor_to_base' => $this->roundQty($factor),
                'selling_price' => $price,
                'barcode' => $barcode,
                'is_default' => ! empty($row['is_default']),
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'sort_order' => $index,
            ];

            if (! empty($row['id'])) {
                $model = ProductUnit::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->find((int) $row['id']);
                abort_unless($model, 403, 'Invalid selling unit.');
                $model->update($payload);
            } else {
                $model = ProductUnit::query()->create($payload);
            }

            $keep[] = $model->id;
        }

        $query = ProductUnit::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('product_id', $product->id);

        if ($keep) {
            $query->whereNotIn('id', $keep)->delete();
        } else {
            $query->delete();
        }

        $remaining = ProductUnit::query()
            ->withoutGlobalScope('company')
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        if ($remaining->isNotEmpty() && ! $remaining->contains(fn (ProductUnit $unit) => $unit->is_default)) {
            $remaining->first()->update(['is_default' => true]);
        }
    }

    public function saleItemBaseQty(SaleItem $item, $sellingQty = null): float
    {
        $qty = $sellingQty === null ? (float) $item->quantity : (float) $sellingQty;
        if ($sellingQty === null && $item->quantity_base !== null) {
            return $this->roundQty($item->quantity_base);
        }

        $factor = $this->positiveFactor($item->unit_factor ?? 1);

        return $this->toBase($qty, $factor);
    }

    public function validateProductUnitFields(array $data, int $companyId): void
    {
        Validator::make($data, [
            'purchase_unit_id' => ['nullable', Rule::exists('units', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'purchase_to_base' => 'nullable|numeric|min:0.000001',
            'unit_pricing_mode' => 'nullable|in:manual,automatic',
        ])->validate();
    }

    private function positiveFactor($factor): float
    {
        $factor = (float) $factor;

        return $factor > 0 ? $factor : 1.0;
    }
}
