<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'product_id',
        'sku',
        'barcode',
        'name',
        'options',
        'signature',
        'size',
        'color',
        'purchase_price',
        'selling_price',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'reorder_level' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function label(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $parts = array_filter([$this->size, $this->color]);

        return $parts ? implode(' / ', $parts) : 'Variation';
    }

    public function displayName(): string
    {
        $productName = optional($this->product)->name ?: 'Item';

        return $productName . ' (' . $this->label() . ')';
    }
}
