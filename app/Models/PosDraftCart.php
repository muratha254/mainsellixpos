<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PosDraftCart extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'revision',
        'payload',
    ];

    protected $casts = [
        'revision' => 'integer',
        'payload' => 'array',
    ];
}
