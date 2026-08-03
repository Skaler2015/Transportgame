<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'principal' => 'integer',
        'balance' => 'integer',
        'interest_rate' => 'float',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REPAID = 'repaid';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
