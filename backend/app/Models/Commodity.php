<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Commodity extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_price' => 'float',
        'weight_per_unit' => 'float',
        'volume_per_unit' => 'float',
        'requires_reefer' => 'boolean',
        'requires_tanker' => 'boolean',
        'is_hazardous' => 'boolean',
        'is_perishable' => 'boolean',
        'risk' => 'integer',
        'volatility' => 'integer',
        'unlock_level' => 'integer',
    ];

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class)
            ->withPivot(['production', 'consumption', 'stock', 'stock_cap'])
            ->withTimestamps();
    }

    /** Does a given vehicle model satisfy this commodity's handling needs? */
    public function canBeCarriedBy(VehicleModel $model): bool
    {
        if ($this->requires_reefer && ! $model->can_reefer) {
            return false;
        }
        if ($this->requires_tanker && ! $model->can_tanker) {
            return false;
        }
        if ($this->is_hazardous && ! $model->can_hazmat) {
            return false;
        }

        return true;
    }
}
