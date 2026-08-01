<?php

namespace App\Services;

use App\Models\City;
use App\Models\Company;
use App\Models\CompanyResearch;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Mission;
use App\Models\Shipment;
use App\Models\TradeListing;
use App\Models\Trailer;
use App\Models\TrailerModel;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
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

    /** Found a new company for a user with the starter loadout in a country. */
    public function found(
        User $user,
        string $companyName,
        ?string $country = null,
        ?int $headquartersCityId = null,
        ?string $logoColor = null,
    ): Company {
        $country = $this->normaliseCountry($country);

        // Honour a chosen HQ if it's a real city in the company's country,
        // otherwise fall back to a sensible starter city.
        $hq = null;
        if ($headquartersCityId) {
            $hq = City::where('id', $headquartersCityId)->where('country', $country)->first();
        }
        $hq ??= $this->pickHq($country);

        $logoColor = $this->normaliseHexColor($logoColor) ?? '#'.substr(md5($companyName), 0, 6);

        $company = Company::create(array_merge([
            'user_id' => $user->id,
            'name' => $companyName,
            'slug' => $this->uniqueSlug($companyName),
            'logo_color' => $logoColor,
        ], $this->starterColumns($country, $hq)));

        $this->grantStarterAssets($company, $hq);

        return $company->fresh(['vehicles', 'drivers', 'headquarters']);
    }

    /** Full oil/battery and valid papers for a newly-acquired vehicle. */
    private function freshPapers(): array
    {
        $g = config('transoria.garage');

        return [
            'oil_level' => 100,
            'battery' => 100,
            'insured_until' => now()->addDays((int) $g['insurance_days']),
            'registered_until' => now()->addDays((int) $g['registration_days']),
        ];
    }

    /** Validate a "#rrggbb" colour; return normalised value or null. */
    private function normaliseHexColor(?string $color): ?string
    {
        if ($color && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }

        return null;
    }

    /**
     * Wipe a company's progress and start it over from the starter loadout,
     * keeping the same login, name and country. Everything the company owned —
     * fleet, trailers, crew, contracts, shipments, warehouses, loans, research,
     * missions, market listings and the entire ledger — is cleared, and a fresh
     * starter fleet is issued.
     */
    public function resetCompany(Company $company): Company
    {
        return DB::transaction(function () use ($company) {
            $country = $this->normaliseCountry($company->country);

            $this->wipeCompanyState($company);

            $hq = $this->pickHq($country);
            $company->update($this->starterColumns($country, $hq));

            $this->grantStarterAssets($company->fresh(), $hq);

            return $company->fresh(['vehicles', 'drivers', 'headquarters']);
        });
    }

    /** Delete every game record tied to a company (safe FK order). */
    private function wipeCompanyState(Company $company): void
    {
        $id = $company->id;

        // Shipments first — they restrict-reference vehicles/drivers/contracts.
        Shipment::where('company_id', $id)->delete();
        Contract::where('company_id', $id)->delete();
        Trailer::where('company_id', $id)->delete();
        Vehicle::where('company_id', $id)->delete();
        Driver::where('company_id', $id)->delete();

        $warehouseIds = Warehouse::where('company_id', $id)->pluck('id');
        if ($warehouseIds->isNotEmpty()) {
            WarehouseInventory::whereIn('warehouse_id', $warehouseIds)->delete();
            Warehouse::whereIn('id', $warehouseIds)->delete();
        }

        TradeListing::where('seller_company_id', $id)->delete();
        Loan::where('company_id', $id)->delete();
        CompanyResearch::where('company_id', $id)->delete();
        Mission::where('company_id', $id)->delete();
        LedgerEntry::where('company_id', $id)->delete();
    }

    /** Starter/reset values for a company's own columns. */
    private function starterColumns(string $country, ?City $hq): array
    {
        $starter = config('transoria.starter');

        return [
            'country' => $country,
            'headquarters_city_id' => $hq?->id,
            'cash' => $starter['cash'],
            'debt' => 0,
            'reputation' => $starter['reputation'],
            'level' => 1,
            'xp' => 0,
            'research_points' => 0,
            'shipments_completed' => 0,
            'shipments_failed' => 0,
            'lifetime_revenue' => 0,
            'lifetime_expenses' => 0,
            'guild_id' => null,
            'guild_role' => null,
            'guild_contribution' => 0,
            'last_tick_at' => now(),
        ];
    }

    /** Issue the physical starter loadout: a truck, a trailer, crew, missions. */
    private function grantStarterAssets(Company $company, ?City $hq): void
    {
        $starter = config('transoria.starter');

        $model = VehicleModel::where('key', $starter['vehicle_model'])->first()
            ?? VehicleModel::orderBy('price')->first();
        if ($model && $hq) {
            Vehicle::create(array_merge([
                'company_id' => $company->id,
                'vehicle_model_id' => $model->id,
                'city_id' => $hq->id,
                'nickname' => 'Old Faithful',
                'status' => Vehicle::STATUS_IDLE,
                'condition' => 100,
                'fuel' => $model->fuel_capacity,
            ], $this->freshPapers()));
        }

        $this->grantStarterTrailer($company, $hq);

        for ($i = 0; $i < ($starter['drivers'] ?? 1); $i++) {
            $this->generateDriver($company, skillFloor: 35);
        }

        $this->missions->ensure($company);
    }

    /** A level-1 city in the country to base a fresh company at. */
    private function pickHq(string $country): ?City
    {
        return $this->pickStarterCity($country)
            ?? City::where('country', $country)->where('unlock_level', 1)->inRandomOrder()->first()
            ?? City::where('country', $country)->first();
    }

    /** Coerce an arbitrary country code to a valid, playable one. */
    private function normaliseCountry(?string $country): string
    {
        return array_key_exists((string) $country, config('transoria.countries'))
            ? (string) $country
            : config('transoria.default_country');
    }

    /**
     * Move a company to a different country: relocate HQ + fleet to a city in
     * the new country, stand down any active shipments, and release contracts
     * that belonged to the old country. Idempotent-friendly.
     */
    public function relocateToCountry(Company $company, string $country): Company
    {
        if (! array_key_exists($country, config('transoria.countries'))) {
            throw new RuntimeException('That country is not available.');
        }

        $hq = $this->pickStarterCity($country)
            ?? City::where('country', $country)->where('unlock_level', 1)->inRandomOrder()->first()
            ?? City::where('country', $country)->first();

        if (! $hq) {
            throw new RuntimeException('That country has no cities yet.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $country, $hq) {
            // Stand down active shipments so trucks/drivers are free again.
            \App\Models\Shipment::where('company_id', $company->id)
                ->where('status', \App\Models\Shipment::STATUS_EN_ROUTE)
                ->update(['status' => \App\Models\Shipment::STATUS_FAILED, 'arrived_at' => now()]);

            // Release contracts that were claimed/open for this company.
            Contract::where('company_id', $company->id)
                ->whereIn('status', [Contract::STATUS_ACCEPTED, Contract::STATUS_IN_PROGRESS])
                ->update(['status' => Contract::STATUS_EXPIRED]);

            // Relocate the whole fleet to the new HQ and free them up.
            Vehicle::where('company_id', $company->id)->update([
                'city_id' => $hq->id,
                'status' => Vehicle::STATUS_IDLE,
            ]);
            Trailer::where('company_id', $company->id)->update([
                'city_id' => $hq->id,
                'status' => Trailer::STATUS_IDLE,
            ]);
            Driver::where('company_id', $company->id)
                ->where('status', Driver::STATUS_DRIVING)
                ->update(['status' => Driver::STATUS_AVAILABLE]);

            $company->country = $country;
            $company->headquarters_city_id = $hq->id;
            $company->save();

            return $company->fresh(['headquarters']);
        });
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

        $vehicle = Vehicle::create(array_merge([
            'company_id' => $company->id,
            'vehicle_model_id' => $model->id,
            'city_id' => $city->id,
            'status' => Vehicle::STATUS_IDLE,
            'condition' => 100,
            'fuel' => $model->fuel_capacity,
        ], $this->freshPapers()));

        $this->ledger->post($company, \App\Models\LedgerEntry::CAT_PURCHASE,
            "Purchased {$model->name}", -$model->price, $vehicle);

        return $vehicle;
    }

    /** Purchase a trailer from the dealership at its catalog price. */
    public function buyTrailer(Company $company, TrailerModel $model, ?City $city = null): Trailer
    {
        if ($company->level < $model->unlock_level) {
            throw new RuntimeException("You must reach level {$model->unlock_level} to buy the {$model->name}.");
        }
        if ($company->cash < $model->price) {
            throw new RuntimeException('Not enough cash for this trailer.');
        }

        $city ??= $company->headquarters ?? City::first();

        $trailer = Trailer::create([
            'company_id' => $company->id,
            'trailer_model_id' => $model->id,
            'city_id' => $city?->id,
            'status' => Trailer::STATUS_IDLE,
            'condition' => 100,
        ]);

        $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
            "Purchased {$model->name}", -$model->price, $trailer);

        return $trailer;
    }

    /**
     * Refuel a vehicle at the local pump. Fills to (an optional number of
     * litres, else) the tank's capacity, charging cash at the current city's
     * fuel price. Electric/hydrogen models with no tank are a no-op.
     */
    public function refuelVehicle(Company $company, Vehicle $vehicle, ?float $liters = null): Vehicle
    {
        if ($vehicle->company_id !== $company->id) {
            throw new RuntimeException('That vehicle is not yours.');
        }
        if ($vehicle->status === Vehicle::STATUS_EN_ROUTE) {
            throw new RuntimeException('You can’t refuel a vehicle that is on the road.');
        }

        $vehicle->loadMissing('model', 'city');
        $capacity = (float) ($vehicle->model->fuel_capacity ?? 0);
        if ($capacity <= 0) {
            throw new RuntimeException('This vehicle doesn’t use fuel.');
        }

        $room = max(0, $capacity - $vehicle->fuel);
        $fill = $liters !== null ? min($liters, $room) : $room;
        if ($fill <= 0.001) {
            throw new RuntimeException('The tank is already full.');
        }

        $pricePerLitre = $vehicle->city->fuel_price
            ?? $company->headquarters?->fuel_price
            ?? 1.0;
        $cost = (int) round($fill * $pricePerLitre * 100);

        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash to refuel.');
        }

        $vehicle->fuel = min($capacity, $vehicle->fuel + $fill);
        $vehicle->save();

        $this->ledger->post($company, LedgerEntry::CAT_FUEL,
            "Refuel {$vehicle->model->name} (".round($fill).' L)', -$cost, $vehicle);

        return $vehicle->fresh(['model', 'city']);
    }

    /** Give a company a free starter box trailer if it has none. */
    public function grantStarterTrailer(Company $company, ?City $city = null): ?Trailer
    {
        if (Trailer::where('company_id', $company->id)->exists()) {
            return null;
        }

        $key = config('transoria.equipment.starter_trailer', 'box-std');
        $model = TrailerModel::where('key', $key)->first() ?? TrailerModel::orderBy('price')->first();
        if (! $model) {
            return null;
        }

        $city ??= $company->headquarters ?? City::where('country', $company->country)->first();

        return Trailer::create([
            'company_id' => $company->id,
            'trailer_model_id' => $model->id,
            'city_id' => $city?->id,
            'nickname' => 'Starter Box',
            'status' => Trailer::STATUS_IDLE,
            'condition' => 100,
        ]);
    }

    /**
     * A level-1 city in the given country that produces at least one light,
     * non-special commodity, so a starter mini-truck always has a haul it can
     * physically carry.
     */
    private function pickStarterCity(string $country): ?City
    {
        return City::query()
            ->where('country', $country)
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
