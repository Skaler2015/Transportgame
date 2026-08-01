<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'age' => (int) $this->age,
            'avatar_seed' => $this->avatar_seed,
            'status' => $this->status,
            'rank' => $this->rank(),
            'experience' => (int) $this->experience,
            'skill' => $this->skill,
            'rain_skill' => (int) $this->rain_skill,
            'eco_skill' => (int) $this->eco_skill,
            'morale' => $this->morale,
            'fatigue' => $this->fatigue,
            'health' => (int) $this->health,
            'loyalty' => $this->loyalty,
            'hazmat_licence' => $this->hazmat_licence,
            'licence_until' => $this->licence_until?->toIso8601String(),
            'is_licensed' => $this->isLicensed(),
            'salary' => (int) $this->salary,
            'shipments_done' => $this->shipments_done,
            'available' => $this->isAvailable(),
        ];
    }
}
