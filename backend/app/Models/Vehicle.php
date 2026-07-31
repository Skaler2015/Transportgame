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
        'odometer' => 'integer',
    ];

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
