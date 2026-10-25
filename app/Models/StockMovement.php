<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToCompany;
    use BelongsToBranch;

    public const OPENING = 'opening';
    public const PURCHASE_RECEIPT = 'purchase_receipt';
    public const SALE = 'sale';
    public const POS_SALE = 'pos_sale';
    public const SALE_VOID = 'sale_void';
    public const SALE_RETURN = 'sale_return';
    public const PURCHASE_RETURN = 'purchase_return';
    public const ADJUSTMENT = 'adjustment';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN = 'transfer_in';
    public const DAMAGE = 'damage';
    public const ISSUE = 'issue';
    public const CONVERSION = 'conversion';

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'product_variant_id',
        'type',
        'unit_name',
        'unit_factor',
        'quantity_entered',
        'quantity_in',
        'quantity_out',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'reference_number',
        'user_id',
        'notes',
        'occurred_at',
    ];

    protected $casts = [
        'unit_factor' => 'decimal:6',
        'quantity_entered' => 'decimal:6',
        'quantity_in' => 'decimal:4',
        'quantity_out' => 'decimal:4',
        'quantity_before' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'product_variant_id' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        $labels = [
            self::OPENING => 'Opening stock',
            self::PURCHASE_RECEIPT => 'Purchase',
            self::SALE => 'Sale',
            self::POS_SALE => 'POS sale',
            self::SALE_VOID => 'Sale void',
            self::SALE_RETURN => 'Sale return',
            self::PURCHASE_RETURN => 'Purchase return',
            self::ADJUSTMENT => 'Adjustment',
            self::TRANSFER_OUT => 'Transfer out',
            self::TRANSFER_IN => 'Transfer in',
            self::DAMAGE => 'Damage',
            self::ISSUE => 'Issue',
            self::CONVERSION => 'Conversion',
        ];

        return $labels[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }
}
