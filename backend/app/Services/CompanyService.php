<?php

namespace App\Services;

use App\Models\City;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Company lifecycle & light management actions: founding a starter company,
 * claiming contracts, hiring drivers, buying vehicles.
 */
class CompanyService
{
    private const FIRST_NAMES = ['Ravi', 'Mei', 'Tomas', 'Amara', 'Nikolai', 'Priya', 'Diego', 'Lena', 'Kwame', 'Yuki', 'Sofia', 'Idris', 'Hana', 'Marco', 'Zara', 'Owen'];
    private const LAST_NAMES = ['Vance', 'Okonkwo', 'Bauer', 'Reyes', 'Sato', 'Kaur', 'Novak', 'Haddad', 'Lindqvist', 'Mensah', 'Ferro', 'Costa', 'Adeyemi', 'Rowe', 'Petrov', 'Cole'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly MissionService $missions,
    ) {}

    /** Found a new company for a user with the starter loadout. */
    public function found(User $user, string $companyName): Company
    {
        $starter = config('transoria.starter');
        $hq = $this->pickStarterCity() ?? City::where('unlock_level', 1)->inRandomOrder()->first() ?? City::first();

        $company = Company::create([
            'user_id' => $user->id,
            'name' => $companyName,
            'slug' => $this->uniqueSlug($companyName),
            'headquarters_city_id' => $hq?->id,
            'cash' => $starter['cash'],
            'reputation' => $starter['reputation'],
            'logo_color' => '#'.substr(md5($companyName), 0, 6),
            'last_tick_at' => now(),
        ]);

        // Free first truck.
        $model = VehicleModel::where('key', $starter['vehicle_model'])->first() ?? VehicleModel::orderBy('price')->first();
        if ($model && $hq) {
            Vehicle::create([
                'company_id' => $company->id,
                'vehicle_model_id' => $model->id,
                'city_id' => $hq->id,
                'nickname' => 'Old Faithful',
                'status' => Vehicle::STATUS_IDLE,
                'condition' => 100,
                'fuel' => $model->fuel_capacity,
            ]);
        }

        // Starter driver(s).
        for ($i = 0; $i < ($starter['drivers'] ?? 1); $i++) {
            $this->generateDriver($company, skillFloor: 35);
        }

        // Give the new player an opening slate of missions.
        $this->missions->ensure($company);

        return $company->fresh(['vehicles', 'drivers', 'headquarters']);
    }

    /** Claim an open market contract for a company. */
    public function acceptContract(Company $company, Contract $contract): Contract
    {
        if ($contract->status !== Contract::STATUS_OPEN || $contract->expires_at->isPast()) {
            throw new RuntimeException('That contract is no longer available.');
        }
        if ($contract->company_id !== null) {
            throw new RuntimeException('That contract was already claimed.');
        }

        $contract->update([
            'company_id' => $company->id,
            'status' => Contract::STATUS_ACCEPTED,
        ]);

        return $contract->fresh(['commodity', 'origin', 'destination']);
    }

    /** Hire a fresh driver from the labour market for a signing fee. */
    public function hireDriver(Company $company, int $signingFee = 3500_00): Driver
    {
        if ($company->cash < $signingFee) {
            throw new RuntimeException('Not enough cash to hire a driver.');
        }

        $driver = $this->generateDriver($company);
        $this->ledger->post($company, \App\Models\LedgerEntry::CAT_WAGES,
            "Signing fee: {$driver->name}", -$signingFee, $driver);

        return $driver;
    }

    /** Purchase a vehicle from the dealership at its catalog price. */
    public function buyVehicle(Company $company, VehicleModel $model, ?City $city = null): Vehicle
    {
        if ($company->level < $model->unlock_level) {
            throw new RuntimeException("You must reach level {$model->unlock_level} to buy the {$model->name}.");
        }
        if ($company->cash < $model->price) {
            throw new RuntimeException('Not enough cash for this vehicle.');
        }

        $city ??= $company->headquarters ?? City::first();

        $vehicle = Vehicle::create([
            'company_id' => $company->id,
            'vehicle_model_id' => $model->id,
            'city_id' => $city->id,
            'status' => Vehicle::STATUS_IDLE,
            'condition' => 100,
            'fuel' => $model->fuel_capacity,
        ]);

        $this->ledger->post($company, \App\Models\LedgerEntry::CAT_PURCHASE,
            "Purchased {$model->name}", -$model->price, $vehicle);

        return $vehicle;
    }

    /**
     * A level-1 city that produces at least one light, non-special commodity,
     * so a starter mini-truck always has a haul it can physically carry.
     */
    private function pickStarterCity(): ?City
    {
        return City::query()
            ->where('unlock_level', 1)
            ->whereHas('commodities', function ($q) {
                $q->where('city_commodity.production', '>', 0)
                    ->where('commodities.weight_per_unit', '<=', 2.0)
                    ->where('commodities.requires_reefer', false)
                    ->where('commodities.requires_tanker', false)
                    ->where('commodities.is_hazardous', false);
            })
            ->inRandomOrder()
            ->first();
    }

    private function generateDriver(Company $company, int $skillFloor = 20): Driver
    {
        $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)].' '.self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        return Driver::create([
            'company_id' => $company->id,
            'name' => $name,
            'avatar_seed' => Str::random(8),
            'status' => Driver::STATUS_AVAILABLE,
            'skill' => random_int($skillFloor, $skillFloor + 40),
            'morale' => random_int(55, 85),
            'fatigue' => 0,
            'loyalty' => random_int(40, 70),
            'hazmat_licence' => random_int(1, 100) <= 30,
            'salary' => random_int(1500, 2600) * 100,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $n = 1;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
