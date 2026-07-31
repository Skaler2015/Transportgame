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
