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
            'avatar_seed' => $this->avatar_seed,
            'status' => $this->status,
            'skill' => $this->skill,
            'morale' => $this->morale,
            'fatigue' => $this->fatigue,
            'loyalty' => $this->loyalty,
            'hazmat_licence' => $this->hazmat_licence,
            'salary' => (int) $this->salary,
            'shipments_done' => $this->shipments_done,
            'available' => $this->isAvailable(),
        ];
    }
}
