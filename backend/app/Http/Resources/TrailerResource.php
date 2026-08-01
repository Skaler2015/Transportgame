<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrailerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'condition' => (float) $this->condition,
            'available' => $this->isAvailable(),
            'model' => new TrailerModelResource($this->whenLoaded('model')),
            'city' => new CityResource($this->whenLoaded('city')),
        ];
    }
}
