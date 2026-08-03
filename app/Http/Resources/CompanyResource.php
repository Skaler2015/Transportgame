<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * All monetary fields are integer CENTS of the in-game Credit (₡).
 * Clients divide by 100 for display.
 */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'country_name' => config('transoria.countries.'.$this->country, $this->country),
            'currency' => config('transoria.country_currency.'.$this->country, ['symbol' => '₹', 'code' => 'INR']),
            'motto' => $this->motto,
            'logo_color' => $this->logo_color,
            'cash' => (int) $this->cash,
            'debt' => (int) $this->debt,
            'value' => $this->estimatedValue(),
            'level' => $this->level,
            'xp' => $this->xp,
            'xp_to_next' => \App\Models\Company::xpForLevel($this->level),
            'reputation' => $this->reputation,
            'research_points' => $this->research_points,
            'shipments_completed' => $this->shipments_completed,
            'shipments_failed' => $this->shipments_failed,
            'lifetime_revenue' => (int) $this->lifetime_revenue,
            'lifetime_expenses' => (int) $this->lifetime_expenses,
            'headquarters' => new CityResource($this->whenLoaded('headquarters')),
            'fleet_size' => $this->whenCounted('vehicles'),
            'driver_count' => $this->whenCounted('drivers'),
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
            'tutorial_step' => (int) $this->tutorial_step,
        ];
    }
}
