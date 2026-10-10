<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sale_id', 'product_id', 'product_variant_id', 'product_unit_id',
        'name', 'sku', 'size', 'color', 'quantity', 'quantity_base', 'unit_name', 'unit_factor',
        'unit_price', 'cost_price', 'discount_amount', 'tax_rate', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'quantity_base' => 'decimal:6',
        'unit_factor' => 'decimal:6',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'product_variant_id' => 'integer',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function displayName(): string
    {
        $unit = trim((string) $this->unit_name);
        if ($unit === '') {
            return (string) $this->name;
        }

        return $this->name . ' — ' . $unit;
    }

    public function baseQuantity(): float
    {
        if ($this->quantity_base !== null) {
            return (float) $this->quantity_base;
        }

        $factor = (float) ($this->unit_factor ?: 1);

        return round((float) $this->quantity * ($factor > 0 ? $factor : 1), 6);
    }

    public function cogsAmount(): float
    {
        $qty = (float) $this->quantity;
        $cost = (float) $this->cost_price;
        $factor = (float) ($this->unit_factor ?: 0);
        if ($this->quantity_base !== null && $factor > 0) {
            return round((float) $this->quantity_base * ($cost / $factor), 6);
        }

        return round($cost * $qty, 6);
    }

    public function returnItems()
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
