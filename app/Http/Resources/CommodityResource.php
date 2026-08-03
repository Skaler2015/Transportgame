<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommodityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'category' => $this->category,
            'icon' => $this->icon,
            'base_price' => (float) $this->base_price,
            'weight_per_unit' => (float) $this->weight_per_unit,
            'volume_per_unit' => (float) $this->volume_per_unit,
            'requires_reefer' => $this->requires_reefer,
            'requires_tanker' => $this->requires_tanker,
            'is_hazardous' => $this->is_hazardous,
            'is_perishable' => $this->is_perishable,
            'risk' => $this->risk,
            'unlock_level' => $this->unlock_level,
        ];
    }
}
