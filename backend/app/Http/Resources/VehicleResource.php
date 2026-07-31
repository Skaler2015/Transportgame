<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'livery_color' => $this->livery_color,
            'status' => $this->status,
            'condition' => (float) $this->condition,
            'tire_wear' => (float) $this->tire_wear,
            'fuel' => (float) $this->fuel,
            'odometer' => (int) $this->odometer,
            'available' => $this->isAvailable(),
            'engine_level' => (int) $this->engine_level,
            'tires_level' => (int) $this->tires_level,
            'trailer_level' => (int) $this->trailer_level,
            'upgrade_slots_used' => (int) ($this->engine_level + $this->tires_level + $this->trailer_level),
            'effective_capacity_weight' => $this->whenLoaded('model', fn () => $this->effectiveCapacityWeight()),
            'model' => new VehicleModelResource($this->whenLoaded('model')),
            'city' => new CityResource($this->whenLoaded('city')),
        ];
    }
}
