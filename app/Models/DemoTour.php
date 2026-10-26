<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoTour extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'mode',
        'step',
        'completed',
        'skipped',
        'baselines',
        'visited',
        'paused',
        'completed_at',
    ];

    protected $casts = [
        'completed' => 'array',
        'skipped' => 'array',
        'baselines' => 'array',
        'visited' => 'array',
        'paused' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
