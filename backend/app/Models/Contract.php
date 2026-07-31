<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'units' => 'integer',
        'distance_km' => 'float',
        'payout' => 'integer',
        'penalty' => 'integer',
        'reputation_reward' => 'integer',
        'difficulty' => 'integer',
        'is_rush' => 'boolean',
        'is_fragile' => 'boolean',
        'deadline_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(City::class, 'origin_city_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(City::class, 'destination_city_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** Contracts still available on the open market and not yet expired. */
    public function scopeOnMarket(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)
            ->where('expires_at', '>', now());
    }
}
