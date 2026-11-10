<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PosShift extends Model
{
    use BelongsToCompany;

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'number', 'status', 'is_open',
        'opened_at', 'closed_at', 'closed_by',
        'opening_cash', 'opening_mpesa', 'opening_remarks',
        'actual_cash', 'actual_mpesa', 'closing_remarks',
        'cash_sales', 'mpesa_sales', 'card_sales', 'bank_transfer_sales', 'other_sales',
        'cash_refunds', 'mpesa_refunds', 'cash_expenses', 'mpesa_expenses',
        'expected_cash', 'expected_mpesa', 'cash_variance', 'mpesa_variance',
        'reviewed_by', 'reviewed_at', 'review_remarks', 'rejection_reason',
        'reopen_requested_at', 'reopen_requested_by', 'reopened_at', 'reopened_by',
    ];

    protected $casts = [
        'is_open' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'reopen_requested_at' => 'datetime',
        'reopened_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'opening_mpesa' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'actual_mpesa' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'mpesa_sales' => 'decimal:2',
        'card_sales' => 'decimal:2',
        'bank_transfer_sales' => 'decimal:2',
        'other_sales' => 'decimal:2',
        'cash_refunds' => 'decimal:2',
        'mpesa_refunds' => 'decimal:2',
        'cash_expenses' => 'decimal:2',
        'mpesa_expenses' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'expected_mpesa' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'mpesa_variance' => 'decimal:2',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'pos_shift_id');
    }

    public function adjustments()
    {
        return $this->hasMany(PosShiftAdjustment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_PENDING => 'Pending Approval',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CLOSED => 'Closed',
        ][$this->status] ?? ucfirst((string) $this->status);
    }

    public function totalVariance(): ?float
    {
        if ($this->cash_variance === null && $this->mpesa_variance === null) {
            return null;
        }

        return round((float) $this->cash_variance + (float) $this->mpesa_variance, 2);
    }

    public function expectedTotal(): ?float
    {
        if ($this->expected_cash === null && $this->expected_mpesa === null) {
            return null;
        }

        return round((float) $this->expected_cash + (float) $this->expected_mpesa, 2);
    }

    public function actualTotal(): ?float
    {
        if ($this->actual_cash === null && $this->actual_mpesa === null) {
            return null;
        }

        return round((float) $this->actual_cash + (float) $this->actual_mpesa, 2);
    }
}
