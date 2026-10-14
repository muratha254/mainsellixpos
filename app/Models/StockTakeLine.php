<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTakeLine extends Model
{
    protected $fillable = [
        'stock_take_id',
        'product_id',
        'product_variant_id',
        'sku',
        'barcode',
        'item_name',
        'category_name',
        'unit_name',
        'system_quantity',
        'counted_quantity',
        'reconciled_system_quantity',
        'applied_variance',
        'notes',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
        'reconciled_system_quantity' => 'decimal:4',
        'applied_variance' => 'decimal:4',
        'product_variant_id' => 'integer',
    ];

    public function stockTake()
    {
        return $this->belongsTo(StockTake::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function wasCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    public function variance(): ?float
    {
        if (! $this->wasCounted()) {
            return null;
        }

        return round((float) $this->counted_quantity - (float) $this->system_quantity, 4);
    }

    public function countStatus(): string
    {
        $variance = $this->variance();
        if ($variance === null) {
            return 'Uncounted';
        }
        if ($variance < -0.0001) {
            return 'Shortage';
        }
        if ($variance > 0.0001) {
            return 'Surplus';
        }

        return 'Matched';
    }
}
