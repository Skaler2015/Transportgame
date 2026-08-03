<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrailerModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $company = $request->user()?->company;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type,
            'price' => (int) $this->price,
            'capacity_weight' => (float) $this->capacity_weight,
            'capacity_volume' => (float) $this->capacity_volume,
            'can_reefer' => (bool) $this->can_reefer,
            'can_tanker' => (bool) $this->can_tanker,
            'can_hazmat' => (bool) $this->can_hazmat,
            'unlock_level' => (int) $this->unlock_level,
            'locked' => $company ? $company->level < $this->unlock_level : false,
            'affordable' => $company ? $company->cash >= $this->price : false,
        ];
    }
}
