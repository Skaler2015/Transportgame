<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'integer',
        'capacity_weight' => 'float',
        'capacity_volume' => 'float',
        'top_speed' => 'integer',
        'fuel_capacity' => 'float',
        'fuel_economy' => 'float',
        'can_reefer' => 'boolean',
        'can_tanker' => 'boolean',
        'can_hazmat' => 'boolean',
        'reliability' => 'float',
        'upgrade_slots' => 'integer',
        'unlock_level' => 'integer',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
