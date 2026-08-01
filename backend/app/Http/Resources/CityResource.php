<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'country' => $this->country,
            'country_name' => $this->country_name,
            'country_code' => $this->country_code,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'population' => $this->population,
            'gdp_per_capita' => $this->gdp_per_capita !== null ? (int) $this->gdp_per_capita : null,
            'crime_index' => $this->crime_index !== null ? (int) $this->crime_index : null,
            'road_quality' => $this->road_quality !== null ? (int) $this->road_quality : null,
            'toll_per_km' => $this->toll_per_km !== null ? (float) $this->toll_per_km : null,
            'industrial_growth' => $this->industrial_growth !== null ? (float) $this->industrial_growth : null,
            'traffic' => $this->traffic,
            'tax_rate' => (float) $this->tax_rate,
            'fuel_price' => (float) $this->fuel_price,
            'weather' => $this->weather,
            'unlock_level' => $this->unlock_level,
            'has_port' => $this->has_port,
            'has_airport' => $this->has_airport,
            'has_rail' => $this->has_rail,
        ];
    }
}
