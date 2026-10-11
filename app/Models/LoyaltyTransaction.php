<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use BelongsToCompany;

    public const TYPE_EARN = 'earn';
    public const TYPE_REVERSE = 'reverse';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_EXPIRE = 'expire';

    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'customer_id', 'user_id', 'type', 'points', 'balance_after',
        'source_type', 'source_id', 'reference', 'notes',
        'status', 'sale_id', 'amount', 'points_remaining', 'expires_at',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'amount' => 'decimal:2',
        'points_remaining' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
