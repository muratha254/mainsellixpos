<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProduct extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
