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
            'fleet_no' => $this->fleet_no !== null ? (int) $this->fleet_no : null,
            'nickname' => $this->nickname,
            'livery_color' => $this->livery_color,
            'status' => $this->status,
            'condition' => (float) $this->condition,
            'tire_wear' => (float) $this->tire_wear,
            'oil_level' => (float) $this->oil_level,
            'battery' => (float) $this->battery,
            'insured_until' => $this->insured_until?->toIso8601String(),
            'registered_until' => $this->registered_until?->toIso8601String(),
            'is_insured' => $this->isInsured(),
            'is_registered' => $this->isRegistered(),
            'fuel' => (float) $this->fuel,
            'fuel_capacity' => $this->whenLoaded('model', fn () => (float) $this->model->fuel_capacity),
            'fuel_pct' => $this->whenLoaded('model', fn () => $this->model->fuel_capacity > 0
                ? round($this->fuel / $this->model->fuel_capacity * 100, 1)
                : 100.0),
            'odometer' => (int) $this->odometer,
            'available' => $this->isAvailable(),
            'engine_level' => (int) $this->engine_level,
            'tires_level' => (int) $this->tires_level,
            'trailer_level' => (int) $this->trailer_level,
            'upgrade_slots_used' => (int) ($this->engine_level + $this->tires_level + $this->trailer_level),
            'effective_capacity_weight' => $this->whenLoaded('model', fn () => $this->effectiveCapacityWeight()),
            // Per-service cost (₡ cents) so the UI can show the price on each fix.
            'service_costs' => $this->serviceCosts(),
            'model' => new VehicleModelResource($this->whenLoaded('model')),
            'city' => new CityResource($this->whenLoaded('city')),
        ];
    }

    /** What each one-click fix would cost this vehicle right now, in cents. */
    private function serviceCosts(): array
    {
        $cfg = config('transoria.garage');

        return [
            'repair' => (int) round((100 - $this->condition) * $cfg['repair_cost_per_point']
                + $this->tire_wear * $cfg['tire_cost_per_point']),
            'oil' => (int) $cfg['oil_change_cost'],
            'battery' => (int) $cfg['battery_cost'],
            'insurance' => (int) $cfg['insurance_cost'],
            'registration' => (int) $cfg['registration_cost'],
        ];
    }
}
