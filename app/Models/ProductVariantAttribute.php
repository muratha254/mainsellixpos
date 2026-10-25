<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductVariantAttribute extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'sort_order',
    ];

    public function values()
    {
        return $this->hasMany(ProductVariantAttributeValue::class, 'attribute_id')->orderBy('sort_order')->orderBy('id');
    }
}
