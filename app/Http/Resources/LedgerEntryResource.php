<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (int) $this->amount,
            'balance_after' => (int) $this->balance_after,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
