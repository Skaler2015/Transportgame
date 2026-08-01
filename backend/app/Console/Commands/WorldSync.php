<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Vehicle;
use App\Services\CompanyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\TrailerModelSeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotently brings a world up to the real-country model:
 *  1. seeds commodities, real cities (per country) and the vehicle catalog,
 *  2. neutralises any legacy fictional cities (no country) so they stop
 *     producing contracts,
 *  3. backfills every company's country to the default (India), and
 *  4. relocates any company still based in a legacy/foreign city onto a real
 *     city in its own country.
 *
 * Safe to run on every deploy.
 */
class WorldSync extends Command
{
    protected $signature = 'transoria:worldsync';

    protected $description = 'Seed real-country cities and migrate existing companies onto them';

    public function handle(CompanyService $companies): int
    {
        $this->info('Seeding commodities, cities, vehicles and trailers…');
        (new CommoditySeeder)->run();
        (new CitySeeder)->run();
        (new VehicleModelSeeder)->run();
        (new TrailerModelSeeder)->run();

        // 1. Neutralise legacy fictional cities (those with no real country).
        $legacyIds = City::whereNull('country')->pluck('id');
        if ($legacyIds->isNotEmpty()) {
            DB::table('city_commodity')->whereIn('city_id', $legacyIds)->update(['production' => 0]);
            Contract::whereIn('origin_city_id', $legacyIds)
                ->where('status', Contract::STATUS_OPEN)
                ->update(['status' => Contract::STATUS_EXPIRED]);
            $this->info("Neutralised {$legacyIds->count()} legacy cities.");
        }

        // 2. Backfill missing company country to the default.
        $default = config('transoria.default_country');
        Company::whereNull('country')->orWhere('country', '')->update(['country' => $default]);

        // 3. Relocate companies still based on a legacy/foreign city.
        $moved = 0;
        Company::with('headquarters')->chunkById(200, function ($batch) use ($companies, &$moved) {
            foreach ($batch as $company) {
                $hq = $company->headquarters;
                if (! $hq || $hq->country !== $company->country) {
                    try {
                        $companies->relocateToCountry($company, $company->country);
                        $moved++;
                    } catch (\Throwable $e) {
                        $this->warn("Company #{$company->id}: ".$e->getMessage());
                    }
                }
            }
        });

        $this->info("Relocated {$moved} companies onto real cities.");

        // 4. Trailer + fuel groundwork for the haulage model. Give every company
        //    a starter trailer (if it has none) so its tractors can haul, and
        //    top up any empty idle tank once so nobody is stranded on rollout.
        $trailered = 0;
        Company::with('headquarters')->chunkById(200, function ($batch) use ($companies, &$trailered) {
            foreach ($batch as $company) {
                try {
                    if ($companies->grantStarterTrailer($company, $company->headquarters)) {
                        $trailered++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Starter trailer for company #{$company->id}: ".$e->getMessage());
                }
            }
        });
        $this->info("Granted starter trailers to {$trailered} companies.");

        $fuelled = Vehicle::whereHas('model', fn ($q) => $q->where('fuel_capacity', '>', 0))
            ->where('status', Vehicle::STATUS_IDLE)
            ->where('fuel', '<=', 0)
            ->get()
            ->each(function (Vehicle $v) {
                $v->update(['fuel' => $v->model->fuel_capacity]);
            })
            ->count();
        $this->info("Topped up {$fuelled} empty tanks.");

        // 5. Established companies skip the new welcome tutorial; brand-new ones
        //    (no activity yet) keep it. Idempotent across deploys.
        $onboarded = Company::whereNull('onboarded_at')
            ->where(function ($q) {
                $q->where('shipments_completed', '>', 0)
                    ->orWhere('level', '>', 1)
                    ->orWhere('lifetime_revenue', '>', 0);
            })
            ->update(['onboarded_at' => now()]);
        $this->info("Marked {$onboarded} established companies as onboarded.");

        // 6. Backfill deeper city economics deterministically (stable per city).
        $enriched = 0;
        foreach (City::whereNull('road_quality')->get() as $city) {
            $h = crc32($city->name);
            $bit = fn (int $shift) => (($h >> $shift) & 0xFF) / 255.0; // stable 0..1
            $popM = $city->population / 1_000_000;
            // "Development" score from infrastructure + size.
            $dev = min(1.0, ($city->has_airport ? 0.4 : 0) + ($city->has_port ? 0.3 : 0) + min(0.3, $popM * 0.03));

            $city->gdp_per_capita = (int) round(150_000 + $dev * 400_000 + $bit(0) * 150_000);
            $city->road_quality = (int) min(98, round(45 + $dev * 45 + $bit(8) * 10));
            $city->crime_index = (int) max(5, min(90, round(15 + (1 - $dev) * 40 + $bit(16) * 15)));
            $city->toll_per_km = round(0.30 + $dev * 1.00 + $bit(24) * 0.30, 2);
            $city->industrial_growth = round(-0.010 + $bit(4) * 0.080, 3);
            $city->save();
            $enriched++;
        }
        $this->info("Enriched {$enriched} cities with economic attributes.");

        // 7. Give existing vehicles valid papers so nobody starts uninsured.
        $g = config('transoria.garage');
        $papered = Vehicle::whereNull('registered_until')->update([
            'insured_until' => now()->addDays((int) $g['insurance_days']),
            'registered_until' => now()->addDays((int) $g['registration_days']),
        ]);
        $this->info("Issued papers to {$papered} existing vehicles.");

        // 8. Give existing drivers a valid licence so nobody drives illegally.
        $licensed = \App\Models\Driver::whereNull('licence_until')->update([
            'licence_until' => now()->addDays((int) config('transoria.driver.licence_days', 30)),
        ]);
        $this->info("Licensed {$licensed} existing drivers.");

        $this->info('World sync complete.');

        return self::SUCCESS;
    }
}
