<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'distance_km' => 'float',
        'progress_km' => 'float',
        'avg_speed' => 'float',
        'fuel_budget' => 'float',
        'projected_payout' => 'integer',
        'event_log' => 'array',
        'departed_at' => 'datetime',
        'eta_at' => 'datetime',
        'arrived_at' => 'datetime',
    ];

    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_LATE = 'late';
    public const STATUS_FAILED = 'failed';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Trailer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** 0..100 completion based on distance covered. */
    public function progressPercent(): float
    {
        if ($this->distance_km <= 0) {
            return 100.0;
        }

        return min(100.0, round($this->progress_km / $this->distance_km * 100, 1));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_EN_ROUTE;
    }
}
