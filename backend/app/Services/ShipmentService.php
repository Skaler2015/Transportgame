<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\LedgerEntry;
use App\Models\Shipment;
use App\Models\Trailer;
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
    /** Effect multipliers for a weather state (config-driven, Weather v2). */
    private function weatherCfg(?string $w): array
    {
        return config('transoria.weather.'.$w)
            ?? config('transoria.weather.clear');
    }

    /**
     * The weather a trip actually runs through: the harsher of the origin and
     * destination (whichever slows you more), so a storm at either end bites.
     */
    private function laneWeather(?string $origin, ?string $destination): string
    {
        $o = $origin ?: 'clear';
        $d = $destination ?: 'clear';

        return $this->weatherCfg($o)['speed'] <= $this->weatherCfg($d)['speed'] ? $o : $d;
    }

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ResearchService $research,
        private readonly MissionService $missions,
        private readonly GarageService $garage,
    ) {}

    /**
     * Dispatch a vehicle + driver against an accepted contract. Throws
     * RuntimeException with a human message on any validation failure.
     */
    public function dispatch(Company $company, Contract $contract, Vehicle $vehicle, Driver $driver, ?Trailer $trailer = null): Shipment
    {
        return DB::transaction(function () use ($company, $contract, $vehicle, $driver, $trailer) {
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

            // Mode-specific infrastructure: ships sail port-to-port, freighters
            // fly airport-to-airport. Road and rail run anywhere.
            $mode = $model->mode ?? 'road';
            if ($mode === 'sea' && ! ($contract->origin->has_port && $contract->destination->has_port)) {
                throw new RuntimeException('A cargo ship needs a port at both ends of this lane.');
            }
            if ($mode === 'air' && ! ($contract->origin->has_airport && $contract->destination->has_airport)) {
                throw new RuntimeException('An air freighter needs an airport at both ends of this lane.');
            }

            if ($commodity->is_hazardous && ! $driver->hazmat_licence) {
                throw new RuntimeException("{$driver->name} lacks a hazmat licence for this cargo.");
            }

            $totalWeight = $commodity->weight_per_unit * $contract->units;
            $totalVolume = $commodity->volume_per_unit * $contract->units;

            // Trailer vs self-contained. Road tractors must pull a trailer whose
            // type suits the cargo, and the trailer governs how much they haul.
            // Rigid vans and rail/sea/air craft carry cargo directly.
            if ($model->needs_trailer) {
                if (! $trailer) {
                    throw new RuntimeException("A {$model->name} needs a trailer — attach one to haul.");
                }
                $trailer->loadMissing('model');
                if ($trailer->company_id !== $company->id || ! $trailer->isAvailable()) {
                    throw new RuntimeException('That trailer is not available.');
                }
                if (! $trailer->model->canCarry($commodity)) {
                    throw new RuntimeException("A {$trailer->model->name} can't carry {$commodity->name} — use the right trailer type.");
                }
                $capWeight = $trailer->model->capacity_weight;
                $capVolume = $trailer->model->capacity_volume;
            } else {
                $trailer = null; // ignore any trailer passed for a self-contained craft
                if (! $commodity->canBeCarriedBy($model)) {
                    throw new RuntimeException("A {$model->name} can't handle {$commodity->name}.");
                }
                $capWeight = $vehicle->effectiveCapacityWeight();
                $capVolume = $vehicle->effectiveCapacityVolume();
            }

            if ($totalWeight > $capWeight + 0.001) {
                throw new RuntimeException('Cargo exceeds the haulage weight capacity.');
            }
            if ($totalVolume > $capVolume + 0.001) {
                throw new RuntimeException('Cargo exceeds the haulage volume capacity.');
            }

            $bonuses = $this->research->bonuses($company);

            // Effective route distance (route AI can shorten it).
            $distance = $contract->distance_km * (1 + ($bonuses['distance_factor'] ?? 0));

            // Effective speed after driver, weather, traffic, research. Weather
            // is the harsher of the two lane ends (Weather v2).
            $weather = $this->laneWeather($contract->origin->weather ?? 'clear', $contract->destination->weather ?? 'clear');
            $wc = $this->weatherCfg($weather);
            $weatherFactor = $wc['speed'];
            $trafficFactor = 1 - ($contract->destination->traffic / 100) * 0.4;
            // Road quality along the lane (avg of both ends): rough roads slow
            // you down. ~0.82 (poor) → 1.0 (excellent).
            $avgRoad = (($contract->origin->road_quality ?? 70) + ($contract->destination->road_quality ?? 70)) / 2;
            $roadFactor = 0.82 + ($avgRoad / 100) * 0.18;
            // Engine upgrades: +4% speed and -5% fuel burn per level.
            $engineSpeed = 1 + 0.04 * $vehicle->engine_level;
            $engineFuel = 1 - 0.05 * $vehicle->engine_level;

            $speedFactor = $driver->speedFactor() * (1 + ($bonuses['speed_factor'] ?? 0)) * $engineSpeed;

            // Travel at a base km-per-real-minute, scaled by driver/weather/
            // traffic/road conditions. km/h is derived only for display.
            $conditionFactor = $speedFactor * $weatherFactor * $trafficFactor * $roadFactor;
            $kmPerMinute = max(20, config('transoria.shipment.km_per_minute', 225) * $conditionFactor);
            $speed = round($kmPerMinute, 2); // stored as km/min (avg_speed column)
            $travelSeconds = $distance / $kmPerMinute * 60;

            // Fuel drawn from the vehicle's own tank (electric/hydrogen sip a
            // little; econ 0 models need none). You pay for fuel when you refuel,
            // not per trip — so the tank must already hold enough to make it.
            // A fuel-savvy driver sips less (eco_skill up to -20%).
            $ecoDriver = 1 - ($driver->eco_skill ?? 0) / 500;
            $economy = $model->fuel_economy * (1 + ($bonuses['fuel_economy'] ?? 0)) * $engineFuel * $ecoDriver;
            // Tank sufficiency is judged on the BASE range (so dispatch is
            // predictable), but bad weather makes the trip actually BURN more —
            // you arrive lower and the refuel-on-arrival costs more.
            $baseFuel = max(0, $distance * $economy);
            $fuelBudget = max(0, $baseFuel * $wc['fuel']);

            if ($baseFuel > 0 && $vehicle->fuel + 0.001 < $baseFuel) {
                throw new RuntimeException(
                    'Not enough fuel in the tank for this trip — refuel '.
                    ($vehicle->nickname ?: $model->name).' first.'
                );
            }

            $departedAt = now();
            $etaAt = $departedAt->copy()->addSeconds((int) max(20, round($travelSeconds)));

            // Give the job a fresh deadline measured from DEPARTURE, not from
            // when the contract was minted. Contracts linger on the market for
            // minutes before dispatch; a mint-time deadline was often already in
            // the past, so a truck was flagged LATE the instant it rolled out.
            // The window is the ideal (good-conditions) travel time × slack, so a
            // normal run makes it while bad weather/traffic/rough roads risk late.
            $idealTravelSeconds = $distance / config('transoria.shipment.km_per_minute', 225) * 60;
            $deadlineSlack = (float) config('transoria.contracts.deadline_slack', 1.6);
            $deadlineAt = $departedAt->copy()->addSeconds((int) max(45, round($idealTravelSeconds * $deadlineSlack)));

            $shipment = Shipment::create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'vehicle_id' => $vehicle->id,
                'trailer_id' => $trailer?->id,
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
                    'text' => "Departed {$contract->origin->name} for {$contract->destination->name}."
                        .($weather !== 'clear' ? " {$wc['icon']} {$wc['label']} en route." : ''),
                ]],
                'departed_at' => $departedAt,
                'eta_at' => $etaAt,
            ]);

            // Burn the fuel from the tank now (it was paid for at the pump).
            $vehicle->fuel = max(0, $vehicle->fuel - $fuelBudget);
            $vehicle->status = Vehicle::STATUS_EN_ROUTE;
            $vehicle->save();

            if ($trailer) {
                $trailer->update(['status' => Trailer::STATUS_EN_ROUTE]);
            }
            $driver->update(['status' => Driver::STATUS_DRIVING]);
            $contract->update(['status' => Contract::STATUS_IN_PROGRESS, 'deadline_at' => $deadlineAt]);

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

            // Weather v2: the snapshotted lane weather drives incident odds,
            // extra wear and cargo spoilage — all from the config table.
            $wc = $this->weatherCfg($shipment->weather_snapshot);
            $weatherRisk = (float) $wc['accident'];

            // Neglected oil/battery raise breakdown odds; lapsed papers raise
            // accident odds (and invite fines on top of an accident).
            $oilLow = $vehicle->oil_level < 25;
            $batteryLow = $vehicle->battery < 20;

            $breakdownChance = $cfg['breakdown_base_chance'] * (2 - $reliability)
                * (1 + ($bonuses['breakdown_chance'] ?? 0))
                * (1 + ($oilLow ? 0.6 : 0) + ($batteryLow ? 0.4 : 0));
            $wetWeather = $weatherRisk > 1.2;
            $accidentChance = $cfg['accident_base_chance']
                * (1 + ($driver->fatigue / 100))
                * $weatherRisk
                * (1 - $driver->skill / 300)
                * (1 - ($wetWeather ? ($driver->rain_skill ?? 0) / 250 : 0)) // wet-weather specialist
                * (1 + (! $vehicle->isRegistered() ? 0.25 : 0))
                * (1 + (! $driver->isLicensed() ? 0.30 : 0));               // unlicensed = risky

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
            // The truck physically arrives at its ETA. Resolution is LAZY (no
            // cron): a shipment is only settled when the player next loads a
            // page after the ETA has passed — which can be minutes later. Stamp
            // the scheduled arrival, NOT the wall-clock resolution moment, so a
            // player who steps away is never wrongly marked LATE.
            $arrivedAt = $shipment->eta_at ?? now();
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

                // Weather v2: perishable cargo can spoil in harsh weather. A
                // reefer-hauled commodity resists most of it; other perishables
                // take the full hit. Reduces the payout, logged for the player.
                $spoilage = 0.0;
                if ($commodity->is_perishable && ($wc['spoilage'] ?? 0) > 0) {
                    $spoilage = $wc['spoilage'] * ($commodity->requires_reefer ? 0.3 : 1.0);
                    if ($spoilage > 0) {
                        $gross = (int) round($gross * (1 - $spoilage));
                        $log[] = ['at' => $arrivedAt->toIso8601String(),
                            'text' => "{$wc['icon']} {$wc['label']} spoiled ".round($spoilage * 100)."% of the {$commodity->name}."];
                    }
                }

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

                // Mission progress (revenue tracked in ₡, not cents).
                $this->missions->progress($company, 'deliveries', 1);
                if (! $late) {
                    $this->missions->progress($company, 'on_time', 1);
                }
                $this->missions->progress($company, 'revenue', (int) round($net / 100));
                $this->missions->progress($company, 'distance', (int) round($shipment->distance_km));

                // Highway tolls & road charges along the lane (avg of both ends).
                $avgToll = (($contract->origin->toll_per_km ?? 0) + ($contract->destination->toll_per_km ?? 0)) / 2;
                $tollCost = (int) round($shipment->distance_km * $avgToll * 100);
                if ($tollCost > 0) {
                    $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
                        "Tolls & road charges: {$contract->origin->name} → {$contract->destination->name}",
                        -$tollCost, $shipment);
                }
            }

            if ($repairCost > 0) {
                $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
                    "Roadside repair: {$vehicle->model->name}", -$repairCost, $shipment);
            }

            // --- Vehicle wear --------------------------------------------------
            $km = $shipment->distance_km;
            $weatherWear = (float) ($wc['wear'] ?? 1.0); // storms/snow chew up the truck faster
            $vehicle->odometer += (int) round($km);
            $vehicle->condition = max(0, $vehicle->condition - $km / 1000 * $cfg['condition_loss_per_1000km'] * $weatherWear - ($failed ? 8 : 0));
            // Tyre upgrades cut wear by 15% per level.
            $tireResist = max(0.4, 1 - 0.15 * $vehicle->tires_level);
            $vehicle->tire_wear = min(100, $vehicle->tire_wear + $km / 1000 * $cfg['tire_loss_per_1000km'] * $tireResist * $weatherWear);
            // Engine oil and battery drain with distance; service to restore.
            $garage = config('transoria.garage');
            $vehicle->oil_level = max(0, $vehicle->oil_level - $km / 1000 * $garage['oil_loss_per_1000km']);
            $vehicle->battery = max(0, $vehicle->battery - $km / 1000 * $garage['battery_loss_per_1000km']);
            // Fuel was already burned from the tank at dispatch — don't drain twice.
            $vehicle->status = $vehicle->condition < 15 ? Vehicle::STATUS_MAINTENANCE : Vehicle::STATUS_IDLE;
            $vehicle->city_id = $contract->destination_city_id;
            $vehicle->save();

            // Auto-REFUEL on arrival (fuel only) so the tank is ready for the
            // next run — a small per-trip line item. Servicing stays manual.
            $serviceCost = 0;
            if (config('transoria.shipment.auto_refuel_on_arrival', true)) {
                $result = $this->garage->refuelOnArrival($company, $vehicle->fresh(['model', 'city']));
                $serviceCost = (int) $result['cost'];
                $vehicle = $result['vehicle'];
            }

            // Release the trailer back to the yard at the destination, with wear.
            if ($shipment->trailer_id) {
                $trailer = $shipment->trailer()->with('model')->first();
                if ($trailer) {
                    $wear = $km / 1000 * config('transoria.equipment.trailer_wear_per_1000km', 5.0);
                    $trailer->condition = max(0, $trailer->condition - $wear);
                    $trailer->status = Trailer::STATUS_IDLE;
                    $trailer->city_id = $contract->destination_city_id;
                    $trailer->save();
                }
            }

            // --- Driver ---------------------------------------------------------
            $driver->fatigue = min(100, $driver->fatigue + $cfg['fatigue_gain_per_trip']);
            $driver->shipments_done++;
            $driver->experience += 1;                                   // every trip builds a career
            $driver->health = max(0, $driver->health - ($failed ? 8 : 2)); // wears down; vacation restores
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
            $shipment->service_cost = $serviceCost;
            $shipment->save();

            $this->applyLevelUps($company);
            $company->save();
        });
    }

    /**
     * Settle any of a company's shipments whose ETA has passed. Called on
     * dashboard/operations load so deliveries always complete when the player
     * is active — even if the world-tick cron isn't running.
     */
    public function resolveDueFor(Company $company): int
    {
        $due = Shipment::where('company_id', $company->id)
            ->where('status', Shipment::STATUS_EN_ROUTE)
            ->where('eta_at', '<=', now())
            ->get();

        foreach ($due as $shipment) {
            $this->resolve($shipment);
        }

        return $due->count();
    }

    /**
     * Self-healing rest recovery: advance driver rest at most once per ~45s per
     * company, so crews recover between trips even when the world-tick cron
     * isn't running (called on Crew/dashboard views).
     */
    public function restDriversThrottled(Company $company): void
    {
        if (\Illuminate\Support\Facades\Cache::add("drivers:rest:{$company->id}", 1, now()->addSeconds(45))) {
            $this->restDrivers($company);
        }
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
