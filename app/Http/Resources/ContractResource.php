<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'units' => $this->units,
            'distance_km' => (float) $this->distance_km,
            'payout' => (int) $this->payout,
            'penalty' => (int) $this->penalty,
            'reputation_reward' => $this->reputation_reward,
            'difficulty' => $this->difficulty,
            'is_rush' => $this->is_rush,
            'is_fragile' => $this->is_fragile,
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'commodity' => new CommodityResource($this->whenLoaded('commodity')),
            'origin' => new CityResource($this->whenLoaded('origin')),
            'destination' => new CityResource($this->whenLoaded('destination')),
            'total_weight' => $this->whenLoaded('commodity', fn () => round($this->commodity->weight_per_unit * $this->units, 2)),
            'total_volume' => $this->whenLoaded('commodity', fn () => round($this->commodity->volume_per_unit * $this->units, 2)),
            // True when a truck of ours is already parked at this job's origin —
            // a backhaul it can pick up without deadheading back empty.
            'at_fleet_city' => (bool) ($this->at_fleet_city ?? false),
            // True when that truck isn't there yet but is EN ROUTE to this origin.
            'fleet_arriving' => (bool) ($this->fleet_arriving ?? false),
        ];
    }
}
