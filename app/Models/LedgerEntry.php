<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public const CAT_REVENUE = 'revenue';
    public const CAT_FUEL = 'fuel';
    public const CAT_WAGES = 'wages';
    public const CAT_PURCHASE = 'purchase';
    public const CAT_UPKEEP = 'upkeep';
    public const CAT_PENALTY = 'penalty';
    public const CAT_LOAN = 'loan';
    public const CAT_INTEREST = 'interest';
    public const CAT_RESEARCH = 'research';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
