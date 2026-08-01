<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Vehicle;
use RuntimeException;

/**
 * Vehicle maintenance & upgrades. Repairs restore condition/tyres; upgrades
 * permanently improve a vehicle within the model's upgrade-slot budget.
 */
class GarageService
{
    public const UPGRADES = ['engine', 'tires', 'trailer'];

    public function __construct(private readonly LedgerService $ledger) {}

    /** Full service: restore condition to 100 and tyres to 0 wear. */
    public function repair(Company $company, Vehicle $vehicle): Vehicle
    {
        if ($vehicle->company_id !== $company->id) {
            throw new RuntimeException('That vehicle is not yours.');
        }
        if ($vehicle->status === Vehicle::STATUS_EN_ROUTE) {
            throw new RuntimeException('Cannot service a vehicle that is on the road.');
        }

        $cfg = config('transoria.garage');
        $cost = (int) round((100 - $vehicle->condition) * $cfg['repair_cost_per_point']
            + $vehicle->tire_wear * $cfg['tire_cost_per_point']);

        if ($cost <= 0) {
            throw new RuntimeException('This vehicle is already in perfect condition.');
        }
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash for this service.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
            "Serviced {$vehicle->model->name}", -$cost, $vehicle);

        $vehicle->condition = 100;
        $vehicle->tire_wear = 0;
        if ($vehicle->status === Vehicle::STATUS_MAINTENANCE) {
            $vehicle->status = Vehicle::STATUS_IDLE;
        }
        $vehicle->save();

        return $vehicle;
    }

    public const SERVICES = ['oil', 'battery', 'insurance', 'registration'];

    /**
     * One-click full service: repair condition + tyres, restore oil + battery,
     * and fill the fuel tank — all charged in a single combined bill.
     *
     * @return array{vehicle: Vehicle, message: string, cost: int}
     */
    public function fullService(Company $company, Vehicle $vehicle): array
    {
        if ($vehicle->company_id !== $company->id) {
            throw new RuntimeException('That vehicle is not yours.');
        }
        if ($vehicle->status === Vehicle::STATUS_EN_ROUTE) {
            throw new RuntimeException('Cannot service a vehicle that is on the road.');
        }

        $vehicle->loadMissing('model', 'city');

        $capacity = (float) ($vehicle->model->fuel_capacity ?? 0);
        $total = $this->fullServiceCost($company, $vehicle);
        if ($total <= 0) {
            throw new RuntimeException('This vehicle is already serviced and fuelled.');
        }
        if ($company->cash < $total) {
            throw new RuntimeException('Not enough cash for a full service.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
            "Full service & refuel: {$vehicle->model->name}", -$total, $vehicle);

        $vehicle->condition = 100;
        $vehicle->tire_wear = 0;
        $vehicle->oil_level = 100;
        $vehicle->battery = 100;
        if ($capacity > 0) {
            $vehicle->fuel = $capacity;
        }
        if ($vehicle->status === Vehicle::STATUS_MAINTENANCE) {
            $vehicle->status = Vehicle::STATUS_IDLE;
        }
        $vehicle->save();

        return ['vehicle' => $vehicle, 'message' => 'Fully serviced & fuelled.', 'cost' => $total];
    }

    /**
     * Perform a service-centre job: oil change, battery replacement, or renewing
     * insurance / registration papers. Returns the vehicle and a message.
     *
     * @return array{vehicle: Vehicle, message: string}
     */
    public function service(Company $company, Vehicle $vehicle, string $type): array
    {
        if ($vehicle->company_id !== $company->id) {
            throw new RuntimeException('That vehicle is not yours.');
        }
        if (! in_array($type, self::SERVICES, true)) {
            throw new RuntimeException('Unknown service.');
        }
        if ($vehicle->status === Vehicle::STATUS_EN_ROUTE) {
            throw new RuntimeException('Cannot service a vehicle that is on the road.');
        }

        $cfg = config('transoria.garage');

        [$cost, $label, $apply] = match ($type) {
            'oil' => [
                (int) $cfg['oil_change_cost'], 'Oil change',
                function (Vehicle $v) { $v->oil_level = 100; },
            ],
            'battery' => [
                (int) $cfg['battery_cost'], 'Battery replacement',
                function (Vehicle $v) { $v->battery = 100; },
            ],
            'insurance' => [
                (int) $cfg['insurance_cost'], 'Insurance renewal',
                function (Vehicle $v) use ($cfg) {
                    $base = ($v->isInsured() ? $v->insured_until : now());
                    $v->insured_until = $base->copy()->addDays((int) $cfg['insurance_days']);
                },
            ],
            'registration' => [
                (int) $cfg['registration_cost'], 'Registration renewal',
                function (Vehicle $v) use ($cfg) {
                    $base = ($v->isRegistered() ? $v->registered_until : now());
                    $v->registered_until = $base->copy()->addDays((int) $cfg['registration_days']);
                },
            ],
        };

        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash for this service.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
            "{$label}: {$vehicle->model->name}", -$cost, $vehicle);

        $apply($vehicle);
        $vehicle->save();

        return ['vehicle' => $vehicle, 'message' => "{$label} done."];
    }

    /**
     * Full-service AND refuel every idle vehicle the company owns, in one go,
     * stopping when cash runs out. Skips en-route vehicles and any already in
     * perfect shape.
     *
     * @return array{serviced: int, total: int}
     */
    public function fullServiceAll(Company $company): array
    {
        $vehicles = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model', 'city')
            ->get();

        $count = 0;
        $total = 0;

        foreach ($vehicles as $vehicle) {
            try {
                // Pass the same $company instance so its running cash balance
                // (mutated by each ledger post) is accurate for the next check.
                $result = $this->fullService($company, $vehicle);
                $count++;
                $total += $result['cost'];
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'enough cash')) {
                    break; // can't afford any more
                }
                // "already serviced" — nothing to do for this one; skip.
            }
        }

        return ['serviced' => $count, 'total' => $total];
    }

    /**
     * Auto-REFUEL a truck the moment a run ends — fuel only, no service. Keeps
     * the tank topped up so the next dispatch isn't blocked on fuel, charged as
     * a small per-trip line item. Servicing (repair/oil/battery) stays manual.
     * Best-effort: if cash is short it skips (cost 0) rather than failing.
     *
     * @return array{vehicle: Vehicle, cost: int}
     */
    public function refuelOnArrival(Company $company, Vehicle $vehicle): array
    {
        $vehicle->loadMissing('model', 'city');

        $capacity = (float) ($vehicle->model->fuel_capacity ?? 0);
        $room = max(0, $capacity - $vehicle->fuel);
        $pricePerLitre = $vehicle->city->fuel_price ?? $company->headquarters?->fuel_price ?? 1.0;
        $fuelCost = (int) round($room * $pricePerLitre * 100);

        if ($fuelCost <= 0 || $company->cash < $fuelCost) {
            return ['vehicle' => $vehicle, 'cost' => 0];
        }

        $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
            "Refuel: {$vehicle->model->name}", -$fuelCost, $vehicle);

        $vehicle->fuel = $capacity;
        $vehicle->save();

        return ['vehicle' => $vehicle, 'cost' => $fuelCost];
    }

    /**
     * The cost of fully servicing + refuelling a single vehicle right now,
     * without charging. Mirrors {@see fullService()}'s pricing exactly.
     */
    public function fullServiceCost(Company $company, Vehicle $vehicle): int
    {
        $vehicle->loadMissing('model', 'city');
        $cfg = config('transoria.garage');

        $repairCost = (int) round((100 - $vehicle->condition) * $cfg['repair_cost_per_point']
            + $vehicle->tire_wear * $cfg['tire_cost_per_point']);
        $oilCost = $vehicle->oil_level < 100 ? (int) $cfg['oil_change_cost'] : 0;
        $battCost = $vehicle->battery < 100 ? (int) $cfg['battery_cost'] : 0;

        $capacity = (float) ($vehicle->model->fuel_capacity ?? 0);
        $room = max(0, $capacity - $vehicle->fuel);
        $pricePerLitre = $vehicle->city->fuel_price ?? $company->headquarters?->fuel_price ?? 1.0;
        $fuelCost = (int) round($room * $pricePerLitre * 100);

        return $repairCost + $oilCost + $battCost + $fuelCost;
    }

    /**
     * Dry-run estimate of what "Service & Fuel All" would cost right now:
     * the number of idle vehicles that need work and the total bill.
     *
     * @return array{count: int, total: int}
     */
    public function estimateFullServiceAll(Company $company): array
    {
        $vehicles = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model', 'city')
            ->get();

        $count = 0;
        $total = 0;
        foreach ($vehicles as $vehicle) {
            $cost = $this->fullServiceCost($company, $vehicle);
            if ($cost > 0) {
                $count++;
                $total += $cost;
            }
        }

        return ['count' => $count, 'total' => $total];
    }

    /** Non-fuel service cost for one vehicle: repair + tyres + oil + battery. */
    public function serviceCostOnly(Vehicle $vehicle): int
    {
        $vehicle->loadMissing('model');
        $g = config('transoria.garage');

        $repair = (100 - $vehicle->condition) * $g['repair_cost_per_point']
            + $vehicle->tire_wear * $g['tire_cost_per_point'];
        $oil = $vehicle->oil_level < 100 ? (int) $g['oil_change_cost'] : 0;
        $batt = $vehicle->battery < 100 ? (int) $g['battery_cost'] : 0;

        return (int) round($repair + $oil + $batt);
    }

    /** Fuel cost to fill one vehicle's tank at its current city. */
    public function refuelCost(Company $company, Vehicle $vehicle): int
    {
        $vehicle->loadMissing('model', 'city');
        $capacity = (float) ($vehicle->model->fuel_capacity ?? 0);
        $room = max(0, $capacity - $vehicle->fuel);
        $price = $vehicle->city->fuel_price ?? $company->headquarters?->fuel_price ?? 1.0;

        return (int) round($room * $price * 100);
    }

    /**
     * Dry-run totals for the separate "Service All" and "Fuel All" actions over
     * idle vehicles: how many need each and the total bill for each.
     *
     * @return array{service: array{count:int,total:int}, fuel: array{count:int,total:int}}
     */
    public function estimateUpkeepAll(Company $company): array
    {
        $vehicles = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model', 'city')->get();

        $svc = ['count' => 0, 'total' => 0];
        $fuel = ['count' => 0, 'total' => 0];

        foreach ($vehicles as $v) {
            $s = $this->serviceCostOnly($v);
            if ($s > 0) {
                $svc['count']++;
                $svc['total'] += $s;
            }
            $f = $this->refuelCost($company, $v);
            if ($f > 0) {
                $fuel['count']++;
                $fuel['total'] += $f;
            }
        }

        return ['service' => $svc, 'fuel' => $fuel];
    }

    /**
     * Service (repair + tyres + oil + battery, NO fuel) every idle vehicle,
     * stopping when cash runs out.
     *
     * @return array{serviced:int, total:int}
     */
    public function serviceAll(Company $company): array
    {
        $vehicles = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model')->get();

        $count = 0;
        $total = 0;
        foreach ($vehicles as $v) {
            $cost = $this->serviceCostOnly($v);
            if ($cost <= 0) {
                continue;
            }
            if ($company->cash < $cost) {
                break;
            }
            $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
                "Service: {$v->model->name}", -$cost, $v);
            $v->condition = 100;
            $v->tire_wear = 0;
            $v->oil_level = 100;
            $v->battery = 100;
            if ($v->status === Vehicle::STATUS_MAINTENANCE) {
                $v->status = Vehicle::STATUS_IDLE;
            }
            $v->save();
            $count++;
            $total += $cost;
        }

        return ['serviced' => $count, 'total' => $total];
    }

    /**
     * Refuel every idle vehicle to a full tank, stopping when cash runs out.
     *
     * @return array{fuelled:int, total:int}
     */
    public function refuelAll(Company $company): array
    {
        $vehicles = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model', 'city')->get();

        $count = 0;
        $total = 0;
        foreach ($vehicles as $v) {
            $cost = $this->refuelCost($company, $v);
            if ($cost <= 0) {
                continue;
            }
            if ($company->cash < $cost) {
                break;
            }
            $this->ledger->post($company, LedgerEntry::CAT_UPKEEP,
                "Refuel: {$v->model->name}", -$cost, $v);
            $v->fuel = (float) ($v->model->fuel_capacity ?? $v->fuel);
            $v->save();
            $count++;
            $total += $cost;
        }

        return ['fuelled' => $count, 'total' => $total];
    }

    /** Buy the next level of an upgrade (engine|tires|trailer). */
    public function upgrade(Company $company, Vehicle $vehicle, string $kind): Vehicle
    {
        if ($vehicle->company_id !== $company->id) {
            throw new RuntimeException('That vehicle is not yours.');
        }
        if (! in_array($kind, self::UPGRADES, true)) {
            throw new RuntimeException('Unknown upgrade.');
        }

        $vehicle->loadMissing('model');
        $cfg = config('transoria.garage');
        $column = $kind.'_level';
        $current = $vehicle->$column;

        if ($current >= $cfg['max_upgrade_level']) {
            throw new RuntimeException('That upgrade is already maxed.');
        }

        $totalLevels = $vehicle->engine_level + $vehicle->tires_level + $vehicle->trailer_level;
        if ($totalLevels >= $vehicle->model->upgrade_slots) {
            throw new RuntimeException('No free upgrade slots on this vehicle.');
        }

        $cost = (int) round($cfg['upgrade_base_cost'] * ($current + 1));
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash for this upgrade.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
            ucfirst($kind)." upgrade: {$vehicle->model->name}", -$cost, $vehicle);

        $vehicle->$column = $current + 1;
        $vehicle->save();

        return $vehicle;
    }
}
