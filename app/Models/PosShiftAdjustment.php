<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PosShiftAdjustment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'pos_shift_id', 'user_id', 'cash_amount', 'mpesa_amount', 'reason',
    ];

    protected $casts = [
        'cash_amount' => 'decimal:2',
        'mpesa_amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
