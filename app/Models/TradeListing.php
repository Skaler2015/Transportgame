<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeListing extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'units' => 'integer',
        'price_per_unit' => 'float',
        'expires_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_SOLD = 'sold';
    public const STATUS_CANCELLED = 'cancelled';

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'seller_company_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN)->where('expires_at', '>', now());
    }

    /** Total ₡ (cents) to buy the whole listing. */
    public function totalCents(): int
    {
        return (int) round($this->price_per_unit * $this->units * 100);
    }
}
