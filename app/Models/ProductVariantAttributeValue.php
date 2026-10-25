<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariantAttributeValue extends Model
{
    protected $fillable = [
        'attribute_id',
        'value',
        'sort_order',
    ];

    public function attribute()
    {
        return $this->belongsTo(ProductVariantAttribute::class, 'attribute_id');
    }
}
