<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\LedgerEntry;
use App\Models\Shipment;
use App\Models\Vehicle;
use App\Models\WorldEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the shipment life-cycle: validating and dispatching a truck against a
 * contract, then resolving the outcome (on-time / late / failed) with fuel,
 * wear, incidents, tax, payout, reputation and XP. Server-authoritative — the
 * client never computes money.
 */
class ShipmentService
{
    private const WEATHER_FACTOR = [
        'clear' => 1.00, 'heat' => 0.95, 'rain' => 0.90,
        'fog' => 0.85, 'snow' => 0.75, 'storm' => 0.70, 'flood' => 0.60,
    ];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ResearchService $research,
    ) {}

    /**
     * Dispatch a vehicle + driver against an accepted contract. Throws
     * RuntimeException with a human message on any validation failure.
     */
    public function dispatch(Company $company, Contract $contract, Vehicle $vehicle, Driver $driver): Shipment
    {
        return DB::transaction(function () use ($company, $contract, $vehicle, $driver) {
            $contract->loadMissing('commodity', 'origin', 'destination');
            $vehicle->loadMissing('model');

            if ($contract->company_id !== $company->id || $contract->status !== Contract::STATUS_ACCEPTED) {
                throw new RuntimeException('This contract is not ready to dispatch.');
            }
            if ($vehicle->company_id !== $company->id || ! $vehicle->isAvailable()) {
                throw new RuntimeException('That vehicle is not available.');
            }
            if ($driver->company_id !== $company->id || ! $driver->isAvailable()) {
                throw new RuntimeException('That driver is not available.');
            }

            $commodity = $contract->commodity;
            $model = $vehicle->model;

            if (! $commodity->canBeCarriedBy($model)) {
                throw new RuntimeException("A {$model->name} can't handle {$commodity->name}.");
            }
            if ($commodity->is_hazardous && ! $driver->hazmat_licence) {
                throw new RuntimeException("{$driver->name} lacks a hazmat licence for this cargo.");
            }

            $totalWeight = $commodity->weight_per_unit * $contract->units;
            $totalVolume = $commodity->volume_per_unit * $contract->units;

            if ($totalWeight > $model->capacity_weight + 0.001) {
                throw new RuntimeException('Cargo exceeds the vehicle weight capacity.');
            }
            if ($totalVolume > $model->capacity_volume + 0.001) {
                throw new RuntimeException('Cargo exceeds the vehicle volume capacity.');
            }

            $bonuses = $this->research->bonuses($company);

            // Effective route distance (route AI can shorten it).
            $distance = $contract->distance_km * (1 + ($bonuses['distance_factor'] ?? 0));

            // Effective speed after driver, weather, traffic, research.
            $weather = $contract->destination->weather ?? 'clear';
            $weatherFactor = self::WEATHER_FACTOR[$weather] ?? 1.0;
            $trafficFactor = 1 - ($contract->destination->traffic / 100) * 0.4;
            $speedFactor = $driver->speedFactor() * (1 + ($bonuses['speed_factor'] ?? 0));

            $speed = max(30, $model->top_speed * $speedFactor * $weatherFactor * $trafficFactor);
            $travelHours = $distance / $speed;

            // Fuel budget & upfront fuel cost (electric/hydrogen sip a little).
            $economy = $model->fuel_economy * (1 + ($bonuses['fuel_economy'] ?? 0));
            $fuelBudget = max(0, $distance * $economy);
            $fuelCost = (int) round($fuelBudget * $contract->origin->fuel_price * 100);

            if ($company->cash < $fuelCost) {
                throw new RuntimeException('Not enough cash to fuel this trip.');
            }

            $secondsPerGameHour = config('transoria.tick.seconds_per_game_hour', 60);
            $departedAt = now();
            $etaAt = $departedAt->copy()->addSeconds((int) max(20, round($travelHours * $secondsPerGameHour)));

            $shipment = Shipment::create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'status' => Shipment::STATUS_EN_ROUTE,
                'distance_km' => round($distance, 2),
                'progress_km' => 0,
                'avg_speed' => round($speed, 2),
                'fuel_budget' => round($fuelBudget, 2),
                'projected_payout' => $contract->payout,
                'weather_snapshot' => $weather,
                'event_log' => [[
                    'at' => $departedAt->toIso8601String(),
                    'text' => "Departed {$contract->origin->name} for {$contract->destination->name}.",
                ]],
                'departed_at' => $departedAt,
                'eta_at' => $etaAt,
            ]);

            if ($fuelCost > 0) {
                $this->ledger->post(
                    $company,
                    LedgerEntry::CAT_FUEL,
                    "Fuel: {$contract->origin->name} → {$contract->destination->name}",
                    -$fuelCost,
                    $shipment,
                );
            }

            $vehicle->update(['status' => Vehicle::STATUS_EN_ROUTE, 'fuel' => $model->fuel_capacity]);
            $driver->update(['status' => Driver::STATUS_DRIVING]);
            $contract->update(['status' => Contract::STATUS_IN_PROGRESS]);

            return $shipment;
        });
    }

    /**
     * Resolve a shipment whose ETA has passed: roll incidents, settle payout,
     * apply wear/fatigue/reputation/XP. Idempotent for already-resolved rows.
     */
    public function resolve(Shipment $shipment): void
    {
        if ($shipment->status !== Shipment::STATUS_EN_ROUTE) {
            return;
        }

        DB::transaction(function () use ($shipment) {
            $shipment->loadMissing(['company', 'contract.commodity', 'contract.destination', 'contract.origin', 'vehicle.model', 'driver']);

            $company = $shipment->company;
            $contract = $shipment->contract;
            $vehicle = $shipment->vehicle;
            $driver = $shipment->driver;
            $commodity = $contract->commodity;

            $bonuses = $this->research->bonuses($company);
            $log = $shipment->event_log ?? [];

            // --- Incident rolls ------------------------------------------------
            $cfg = config('transoria.shipment');
            $reliability = $vehicle->model->reliability;

            $weatherFactor = self::WEATHER_FACTOR[$shipment->weather_snapshot] ?? 1.0;
            $weatherRisk = $weatherFactor < 0.85 ? 1.6 : 1.0;

            $breakdownChance = $cfg['breakdown_base_chance'] * (2 - $reliability) * (1 + ($bonuses['breakdown_chance'] ?? 0));
            $accidentChance = $cfg['accident_base_chance']
                * (1 + ($driver->fatigue / 100))
                * $weatherRisk
                * (1 - $driver->skill / 300);

            $failed = false;
            $repairCost = 0;

            if ($this->roll($accidentChance * ($commodity->risk / 40))) {
                // Serious accident: cargo lost, contract fails.
                $failed = true;
                $log[] = ['at' => now()->toIso8601String(), 'text' => 'Accident en route — cargo lost.'];
            } elseif ($this->roll($breakdownChance)) {
                // Breakdown: costly repair, delivery slips but completes.
                $repairCost = (int) round($vehicle->model->price * 0.015);
                $log[] = ['at' => now()->toIso8601String(), 'text' => 'Mechanical breakdown — roadside repair required.'];
            }

            // --- Timing --------------------------------------------------------
            $arrivedAt = now();
            $late = $arrivedAt->greaterThan($contract->deadline_at);

            // --- Financial settlement -----------------------------------------
            $tax = $contract->destination->tax_rate;

            if ($failed) {
                $shipment->status = Shipment::STATUS_FAILED;
                $this->ledger->post($company, LedgerEntry::CAT_PENALTY,
                    "Failed delivery penalty: {$commodity->name}", -$contract->penalty, $shipment);

                $company->shipments_failed++;
                $company->reputation = max(0, $company->reputation - (5 + $contract->difficulty * 3));
                $contract->status = Contract::STATUS_FAILED;
            } else {
                $gross = $late
                    ? (int) round($contract->payout * $cfg['late_payout_pct'])
                    : $contract->payout;

                $taxAmount = (int) round($gross * $tax);
                $net = $gross - $taxAmount;

                $shipment->status = $late ? Shipment::STATUS_LATE : Shipment::STATUS_DELIVERED;
                $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                    ($late ? 'Late delivery: ' : 'Delivered: ')."{$commodity->name} → {$contract->destination->name}",
                    $net, $shipment);

                $company->shipments_completed++;

                $repGain = (int) round($contract->reputation_reward * (1 + ($bonuses['reputation_gain'] ?? 0)));
                $company->reputation = min(1000, $company->reputation + ($late ? (int) ceil($repGain / 2) : $repGain));
                $company->xp += (int) round($cfg['xp_per_shipment'] * (1 + ($contract->difficulty - 1) * 0.2));

                $contract->status = Contract::STATUS_COMPLETED;

                $log[] = ['at' => $arrivedAt->toIso8601String(),
                    'text' => $late ? "Delivered late — partial payout." : "Delivered on time."];

                // Goods physically move: drain origin stock, feed destination stock.
                $this->moveStock($contract->origin_city_id, $contract->destination_city_id, $commodity->id, $contract->units);
            }

            if ($repairCost > 0) {
                $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
                    "Roadside repair: {$vehicle->model->name}", -$repairCost, $shipment);
            }

            // --- Vehicle wear --------------------------------------------------
            $km = $shipment->distance_km;
            $vehicle->odometer += (int) round($km);
            $vehicle->condition = max(0, $vehicle->condition - $km / 1000 * $cfg['condition_loss_per_1000km'] - ($failed ? 8 : 0));
            $vehicle->tire_wear = min(100, $vehicle->tire_wear + $km / 1000 * $cfg['tire_loss_per_1000km']);
            $vehicle->fuel = max(0, $vehicle->fuel - $shipment->fuel_budget);
            $vehicle->status = $vehicle->condition < 15 ? Vehicle::STATUS_MAINTENANCE : Vehicle::STATUS_IDLE;
            $vehicle->city_id = $contract->destination_city_id;
            $vehicle->save();

            // --- Driver ---------------------------------------------------------
            $driver->fatigue = min(100, $driver->fatigue + $cfg['fatigue_gain_per_trip']);
            $driver->shipments_done++;
            if (! $failed) {
                $skillGain = 1 + (int) round(($bonuses['driver_skill_gain'] ?? 0) * 2);
                $driver->skill = min(100, $driver->skill + $skillGain);
                $driver->morale = min(100, $driver->morale + 2);
            } else {
                $driver->morale = max(0, $driver->morale - 6);
            }
            $driver->status = Driver::STATUS_RESTING;
            $driver->save();

            // --- Progress + level-up -------------------------------------------
            $shipment->progress_km = $shipment->distance_km;
            $shipment->arrived_at = $arrivedAt;
            $shipment->event_log = $log;
            $shipment->save();

            $this->applyLevelUps($company);
            $company->save();
        });
    }

    /** Advance a driver's rest between trips (called by the tick). */
    public function restDrivers(Company $company): void
    {
        $company->drivers()
            ->where('status', Driver::STATUS_RESTING)
            ->each(function (Driver $driver) {
                $driver->fatigue = max(0, $driver->fatigue - 25);
                if ($driver->fatigue < 60) {
                    $driver->status = Driver::STATUS_AVAILABLE;
                }
                $driver->save();
            });
    }

    private function moveStock(int $originId, int $destId, int $commodityId, int $units): void
    {
        DB::table('city_commodity')
            ->where('city_id', $originId)->where('commodity_id', $commodityId)
            ->decrement('stock', $units);
        DB::table('city_commodity')
            ->where('city_id', $originId)->where('commodity_id', $commodityId)
            ->where('stock', '<', 0)->update(['stock' => 0]);

        DB::table('city_commodity')
            ->where('city_id', $destId)->where('commodity_id', $commodityId)
            ->increment('stock', $units);
    }

    private function applyLevelUps(Company $company): void
    {
        while ($company->xp >= Company::xpForLevel($company->level)) {
            $company->xp -= Company::xpForLevel($company->level);
            $company->level++;
            $company->research_points += 10 + $company->level * 2;
        }
    }

    private function roll(float $chance): bool
    {
        return mt_rand() / mt_getrandmax() < max(0, min(0.95, $chance));
    }
}
