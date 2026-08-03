<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class City extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'population' => 'integer',
        'economic_growth' => 'float',
        'gdp_per_capita' => 'integer',
        'crime_index' => 'integer',
        'road_quality' => 'integer',
        'toll_per_km' => 'float',
        'industrial_growth' => 'float',
        'traffic' => 'integer',
        'tax_rate' => 'float',
        'fuel_price' => 'float',
        'has_port' => 'boolean',
        'has_airport' => 'boolean',
        'has_rail' => 'boolean',
    ];

    public function commodities(): BelongsToMany
    {
        return $this->belongsToMany(Commodity::class)
            ->withPivot(['production', 'consumption', 'stock', 'stock_cap'])
            ->withTimestamps();
    }

    /**
     * Great-circle distance in km to another city (haversine).
     * Multiplied by a small road-winding factor so routes aren't perfectly straight.
     */
    public function distanceTo(City $other, float $roadFactor = 1.18): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->lat)) * cos(deg2rad($other->lat)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earth * $c * $roadFactor, 2);
    }
}
