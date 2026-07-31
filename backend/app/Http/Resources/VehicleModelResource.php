<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $company = $request->user()?->company;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'brand' => $this->brand,
            'class' => $this->class,
            'price' => (int) $this->price,
            'capacity_weight' => (float) $this->capacity_weight,
            'capacity_volume' => (float) $this->capacity_volume,
            'top_speed' => $this->top_speed,
            'fuel_capacity' => (float) $this->fuel_capacity,
            'fuel_economy' => (float) $this->fuel_economy,
            'powertrain' => $this->powertrain,
            'can_reefer' => $this->can_reefer,
            'can_tanker' => $this->can_tanker,
            'can_hazmat' => $this->can_hazmat,
            'reliability' => (float) $this->reliability,
            'upgrade_slots' => $this->upgrade_slots,
            'unlock_level' => $this->unlock_level,
            'locked' => $company ? $company->level < $this->unlock_level : false,
            'affordable' => $company ? $company->cash >= $this->price : false,
        ];
    }
}
