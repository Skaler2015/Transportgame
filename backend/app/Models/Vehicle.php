<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'condition' => 'float',
        'tire_wear' => 'float',
        'fuel' => 'float',
        'oil_level' => 'float',
        'battery' => 'float',
        'insured_until' => 'datetime',
        'registered_until' => 'datetime',
        'odometer' => 'integer',
        'engine_level' => 'integer',
        'tires_level' => 'integer',
        'trailer_level' => 'integer',
    ];

    public function isInsured(): bool
    {
        return $this->insured_until !== null && $this->insured_until->isFuture();
    }

    public function isRegistered(): bool
    {
        return $this->registered_until !== null && $this->registered_until->isFuture();
    }

    /** Effective cargo weight capacity including trailer upgrades (+8%/level). */
    public function effectiveCapacityWeight(): float
    {
        $base = $this->model->capacity_weight ?? 0;

        return round($base * (1 + 0.08 * $this->trailer_level), 2);
    }

    public function effectiveCapacityVolume(): float
    {
        $base = $this->model->capacity_volume ?? 0;

        return round($base * (1 + 0.08 * $this->trailer_level), 2);
    }

    public const STATUS_IDLE = 'idle';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_MAINTENANCE = 'maintenance';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_IDLE && $this->condition > 15;
    }
}
