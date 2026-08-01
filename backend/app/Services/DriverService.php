<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Driver;
use App\Models\LedgerEntry;
use RuntimeException;

/**
 * Driver HR: training, licence renewal and vacations. Skill and specialities
 * improve with courses; a valid licence is required to drive without penalty;
 * a vacation restores health, morale and clears fatigue.
 */
class DriverService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Send a driver on a training course: raises skill and a rotating specialty. */
    public function train(Company $company, Driver $driver): array
    {
        $this->assertOwned($company, $driver);
        $cfg = config('transoria.driver');

        if ($company->cash < $cfg['train_cost']) {
            throw new RuntimeException('Not enough cash for a training course.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_WAGES,
            "Training course: {$driver->name}", -(int) $cfg['train_cost'], $driver);

        $driver->skill = min(100, $driver->skill + (int) $cfg['train_skill_gain']);
        // Rotate the specialty that gets the boost so both climb over time.
        if ($driver->rain_skill <= $driver->eco_skill) {
            $driver->rain_skill = min(100, $driver->rain_skill + (int) $cfg['train_specialty_gain']);
        } else {
            $driver->eco_skill = min(100, $driver->eco_skill + (int) $cfg['train_specialty_gain']);
        }
        $driver->morale = min(100, $driver->morale + 3);
        $driver->save();

        return ['driver' => $driver, 'message' => "{$driver->name} completed a training course."];
    }

    /** Renew a driver's driving licence. */
    public function renewLicence(Company $company, Driver $driver): array
    {
        $this->assertOwned($company, $driver);
        $cfg = config('transoria.driver');

        if ($company->cash < $cfg['licence_cost']) {
            throw new RuntimeException('Not enough cash to renew the licence.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_WAGES,
            "Licence renewal: {$driver->name}", -(int) $cfg['licence_cost'], $driver);

        $base = $driver->isLicensed() ? $driver->licence_until : now();
        $driver->licence_until = $base->copy()->addDays((int) $cfg['licence_days']);
        $driver->save();

        return ['driver' => $driver, 'message' => "{$driver->name}'s licence renewed."];
    }

    /** Send a driver on vacation: restore health & morale, clear fatigue. */
    public function vacation(Company $company, Driver $driver): array
    {
        $this->assertOwned($company, $driver);
        if ($driver->status === Driver::STATUS_DRIVING) {
            throw new RuntimeException('This driver is on the road right now.');
        }
        $cfg = config('transoria.driver');

        if ($company->cash < $cfg['vacation_cost']) {
            throw new RuntimeException('Not enough cash for a vacation package.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_WAGES,
            "Vacation: {$driver->name}", -(int) $cfg['vacation_cost'], $driver);

        $driver->health = 100;
        $driver->morale = min(100, $driver->morale + 20);
        $driver->fatigue = 0;
        $driver->loyalty = min(100, $driver->loyalty + 5);
        $driver->status = Driver::STATUS_AVAILABLE;
        $driver->save();

        return ['driver' => $driver, 'message' => "{$driver->name} came back refreshed."];
    }

    private function assertOwned(Company $company, Driver $driver): void
    {
        if ($driver->company_id !== $company->id) {
            throw new RuntimeException('That driver is not on your payroll.');
        }
    }
}
