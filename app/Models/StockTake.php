<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockTake extends Model
{
    use BelongsToCompany;
    use BelongsToBranch;

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'branch_id',
        'number',
        'status',
        'scope',
        'category_id',
        'created_by',
        'approved_by',
        'reconciled_by',
        'approved_at',
        'reconciled_at',
        'cancelled_at',
        'upload_name',
        'upload_hash',
        'notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(StockTakeLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_UPLOADED => 'Uploaded',
            self::STATUS_PENDING => 'Pending Approval',
            self::STATUS_RECONCILED => 'Reconciled',
            self::STATUS_CANCELLED => 'Cancelled',
        ][$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_UPLOADED,
            self::STATUS_PENDING,
        ], true);
    }
}
