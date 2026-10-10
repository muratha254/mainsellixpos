<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'unit_id',
        'name',
        'factor_to_base',
        'selling_price',
        'barcode',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'factor_to_base' => 'decimal:6',
        'selling_price' => 'decimal:4',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
