<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareHolding extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'shares' => 'integer',
        'avg_cost' => 'float',
        'last_dividend_at' => 'datetime',
    ];

    public function listedCompany(): BelongsTo
    {
        return $this->belongsTo(ListedCompany::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
