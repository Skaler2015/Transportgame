<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Commodity;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use App\Services\CompanyService;
use App\Services\EconomyService;
use App\Services\EventService;
use App\Services\ShipmentService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
        $this->company = app(CompanyService::class)->found(User::factory()->create(), 'Weather Co');
        for ($i = 0; $i < 3; $i++) {
            app(EconomyService::class)->tickMarkets(new Collection());
        }
    }

    /** One haulable HQ contract (accepted), plus the starter truck & driver. */
    private function pick(?string $commodityKey = null): array
    {
        $vehicle = $this->company->vehicles()->with('model')->first();
        $driver = $this->company->drivers()->first();
        app(EconomyService::class)->ensureWorkForFleet($this->company);

        $contract = Contract::onMarket()
            ->where('origin_city_id', $this->company->headquarters_city_id)
            ->with('commodity')->get()
            ->first(function (Contract $c) use ($vehicle) {
                $m = $vehicle->model;
                return $c->commodity->canBeCarriedBy($m)
                    && ($m->fuel_economy <= 0 || $c->distance_km * $m->fuel_economy <= $m->fuel_capacity)
                    && $c->commodity->weight_per_unit * $c->units <= $vehicle->effectiveCapacityWeight();
            });
        $this->assertNotNull($contract, 'Expected a haulable HQ contract.');

        if ($commodityKey) {
            $contract->commodity_id = Commodity::where('key', $commodityKey)->value('id');
            $contract->units = 1;
            $contract->save();
        }

        $contract->refresh()->load(['origin', 'destination', 'commodity']);
        app(CompanyService::class)->acceptContract($this->company, $contract);

        return [$contract->fresh(['origin', 'destination', 'commodity']), $vehicle->fresh('model'), $driver->fresh()];
    }

    /** Force the whole lane to one weather state. */
    private function setLaneWeather(Contract $c, string $weather): void
    {
        City::whereIn('id', [$c->origin_city_id, $c->destination_city_id])->update(['weather' => $weather]);
    }

    public function test_bad_weather_slows_the_trip_and_burns_more_fuel(): void
    {
        $ship = app(ShipmentService::class);
        [$c, $v, $d] = $this->pick();

        // Same lane, clear weather.
        $this->setLaneWeather($c, 'clear');
        $clear = $ship->dispatch($this->company, $c->fresh(['origin', 'destination', 'commodity']), $v, $d);
        $clearSecs = $clear->eta_at->getTimestamp() - $clear->departed_at->getTimestamp();
        $clearFuel = $clear->fuel_budget;

        // Reset the exact same contract/truck/driver, then re-run under a storm.
        // Use query updates so the writes always land (avoids Eloquent thinking
        // the stale in-memory 'accepted' status is unchanged).
        $clear->delete();
        Contract::whereKey($c->id)->update(['status' => Contract::STATUS_ACCEPTED, 'company_id' => $this->company->id]);
        $v->update(['status' => 'idle', 'fuel' => $v->model->fuel_capacity, 'city_id' => $this->company->headquarters_city_id]);
        $d->update(['status' => 'available', 'fatigue' => 0]);

        $this->setLaneWeather($c, 'storm');
        $storm = $ship->dispatch($this->company, $c->fresh(['origin', 'destination', 'commodity']), $v->fresh('model'), $d->fresh());
        $stormSecs = $storm->eta_at->getTimestamp() - $storm->departed_at->getTimestamp();

        $this->assertGreaterThan($clearSecs, $stormSecs, 'A storm should lengthen the ETA.');
        $this->assertGreaterThan($clearFuel, $storm->fuel_budget, 'A storm should burn more fuel.');
    }

    public function test_perishable_cargo_spoils_in_extreme_weather(): void
    {
        mt_srand(4242); // pin incident rolls so we isolate spoilage
        $ship = app(ShipmentService::class);

        [$c, $v, $d] = $this->pick('fresh_produce');
        $this->setLaneWeather($c, 'cyclone');
        $shipment = $ship->dispatch($this->company, $c->fresh(['origin', 'destination', 'commodity']), $v, $d);
        $shipment->update(['eta_at' => now()->subMinute(), 'deadline_at' => now()->addYear()]);
        $ship->resolve($shipment->fresh());

        $shipment->refresh();
        if ($shipment->status === 'failed') {
            $this->markTestSkipped('Rare accident on this seed — spoilage path not reached.');
        }
        $spoiled = collect($shipment->event_log)->contains(fn ($e) => str_contains($e['text'], 'spoiled'));
        $this->assertTrue($spoiled, 'Perishable cargo should spoil in a cyclone.');
    }

    public function test_drift_produces_only_valid_weather_states(): void
    {
        app(EventService::class)->driftCities(new Collection());

        $valid = array_keys(config('transoria.weather'));
        foreach (City::pluck('weather') as $w) {
            $this->assertContains($w, $valid, "Unexpected weather state: {$w}");
        }
    }
}
