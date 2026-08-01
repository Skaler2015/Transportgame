<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Live progress from wall-clock so the client sees a moving truck
        // even between world ticks.
        $livePercent = $this->liveProgressPercent();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'distance_km' => (float) $this->distance_km,
            'avg_speed' => (float) $this->avg_speed,
            'projected_payout' => (int) $this->projected_payout,
            'service_cost' => (int) $this->service_cost,
            'weather_snapshot' => $this->weather_snapshot,
            'progress_percent' => $livePercent,
            'departed_at' => $this->departed_at?->toIso8601String(),
            'eta_at' => $this->eta_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'event_log' => $this->event_log,
            // The last thing that happened — e.g. "Accident en route — cargo
            // lost." — so the UI can explain a FAILED / LATE outcome at a glance.
            'outcome_note' => is_array($this->event_log) && count($this->event_log)
                ? ($this->event_log[count($this->event_log) - 1]['text'] ?? null)
                : null,
            'contract' => new ContractResource($this->whenLoaded('contract')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'trailer' => new TrailerResource($this->whenLoaded('trailer')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
        ];
    }

    private function liveProgressPercent(): float
    {
        if ($this->status !== \App\Models\Shipment::STATUS_EN_ROUTE) {
            return 100.0;
        }
        if (! $this->departed_at || ! $this->eta_at) {
            return $this->progressPercent();
        }

        $total = $this->eta_at->getTimestamp() - $this->departed_at->getTimestamp();
        if ($total <= 0) {
            return 100.0;
        }
        $elapsed = now()->getTimestamp() - $this->departed_at->getTimestamp();

        return max(0.0, min(100.0, round($elapsed / $total * 100, 1)));
    }
}
