<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\Trailer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\CompanyService;
use App\Services\ShipmentService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\TrailerModelSeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage of the core gameplay loop, exercised through both the
 * service layer and the HTTP API.
 */
class GameplayLoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class, TrailerModelSeeder::class]);
    }

    /** Whether a contract's cargo physically fits (and is handleable by) a vehicle. */
    private function fits(Contract $c, Vehicle $vehicle): bool
    {
        $m = $vehicle->model;

        return $c->commodity->canBeCarriedBy($m)
            && $c->commodity->weight_per_unit * $c->units <= $vehicle->effectiveCapacityWeight()
            && $c->commodity->volume_per_unit * $c->units <= $vehicle->effectiveCapacityVolume();
    }

    public function test_registration_founds_a_starter_company_with_truck_and_driver(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Pilot',
            'email' => 'pilot@transoria.io',
            'password' => 'password123',
            'company_name' => 'Pilot Freight',
        ]);

        $response->assertCreated()
            ->assertJsonPath('company.name', 'Pilot Freight')
            ->assertJsonPath('company.fleet_size', 1)
            ->assertJsonPath('company.driver_count', 1);

        $this->assertNotNull($response->json('token'));
        $this->assertSame(1, Vehicle::count());
        $this->assertSame(1, Driver::count());
    }

    public function test_starter_company_can_always_reach_a_haulable_local_contract(): void
    {
        // The economy must produce at least one contract that the starter
        // truck can physically carry from the company's HQ — otherwise a new
        // player is soft-locked.
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Fresh Co');

        $vehicle = $company->vehicles()->with('model')->first();
        $this->assertNotNull($vehicle);

        // The market replenishes each tick; within a few ticks there must be a
        // haulable local contract, otherwise a new player is soft-locked.
        $haulable = null;
        for ($i = 0; $i < 8 && ! $haulable; $i++) {
            $this->artisan('world:tick')->assertSuccessful();

            $haulable = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')
                ->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }

        $this->assertNotNull($haulable, 'Starter company has no haulable local contract within 8 ticks.');
    }

    public function test_full_dispatch_and_resolution_pays_out_and_awards_xp(): void
    {
        $user = User::factory()->create();
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);

        $company = $companyService->found($user, 'Loop Co');

        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')
                ->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }

        $this->assertNotNull($contract);

        $companyService->acceptContract($company, $contract);
        $cashBeforeDispatch = $company->fresh()->cash;

        $shipment = $shipmentService->dispatch($company, $contract, $vehicle, $driver);

        // Dispatch should charge fuel up front and lock the resources.
        $this->assertLessThanOrEqual($cashBeforeDispatch, $company->fresh()->cash);
        $this->assertSame(Vehicle::STATUS_EN_ROUTE, $vehicle->fresh()->status);
        $this->assertSame(Driver::STATUS_DRIVING, $driver->fresh()->status);

        // Force arrival and resolve.
        $shipment->update(['eta_at' => now()->subMinute(), 'deadline_at' => now()->addYear()]);
        $shipmentService->resolve($shipment->fresh());

        $company->refresh();
        $resolved = $shipment->fresh();

        $this->assertContains($resolved->status, [Shipment::STATUS_DELIVERED, Shipment::STATUS_LATE, Shipment::STATUS_FAILED]);

        if ($resolved->status !== Shipment::STATUS_FAILED) {
            $this->assertSame(1, $company->shipments_completed);
            $this->assertGreaterThan(0, $company->lifetime_revenue);
        }

        // Regardless of outcome, the truck stops being en route and moves to
        // the destination city, and the driver is no longer driving.
        $this->assertNotSame(Vehicle::STATUS_EN_ROUTE, $vehicle->fresh()->status);
        $this->assertSame($contract->destination_city_id, $vehicle->fresh()->city_id);
        $this->assertNotSame(Driver::STATUS_DRIVING, $driver->fresh()->status);
    }

    public function test_cannot_dispatch_cargo_that_exceeds_vehicle_capacity(): void
    {
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Overload Co');
        $this->artisan('world:tick');

        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        // Find a contract that is too heavy for the starter truck.
        $tooBig = Contract::onMarket()->with('commodity')->get()
            ->first(fn (Contract $c) => $c->commodity->weight_per_unit * $c->units > $vehicle->model->capacity_weight
                && $c->commodity->canBeCarriedBy($vehicle->model));

        if (! $tooBig) {
            $this->markTestSkipped('No oversized contract available this run.');
        }

        $tooBig->update(['company_id' => $company->id, 'status' => Contract::STATUS_ACCEPTED]);

        $this->expectException(\RuntimeException::class);
        app(ShipmentService::class)->dispatch($company, $tooBig, $vehicle, $driver);
    }

    /** Find an open, general (non-special) local contract the starter box can haul. */
    private function generalLocalContract(int $hqId, float $maxTonnes = 12.0, float $maxVolume = 60.0): ?Contract
    {
        return Contract::onMarket()->where('origin_city_id', $hqId)->with('commodity')->get()
            ->first(fn (Contract $c) => ! $c->commodity->requires_reefer
                && ! $c->commodity->requires_tanker
                && ! $c->commodity->is_hazardous
                && $c->commodity->weight_per_unit * $c->units <= $maxTonnes
                && $c->commodity->volume_per_unit * $c->units <= $maxVolume);
    }

    public function test_road_tractor_needs_a_matching_trailer_to_dispatch(): void
    {
        $user = User::factory()->create();
        $svc = app(CompanyService::class);
        $ship = app(ShipmentService::class);

        $company = $svc->found($user, 'Trailer Co');
        $driver = $company->drivers()->first();
        $hq = $company->headquarters_city_id;

        // A medium tractor (needs a trailer), parked and fuelled at HQ.
        $tractorModel = VehicleModel::where('key', 'kestrel-t20')->first();
        $tractor = Vehicle::create([
            'company_id' => $company->id,
            'vehicle_model_id' => $tractorModel->id,
            'city_id' => $hq,
            'status' => Vehicle::STATUS_IDLE,
            'condition' => 100,
            'fuel' => $tractorModel->fuel_capacity,
        ]);

        // The starter box trailer (granted at founding).
        $trailer = Trailer::where('company_id', $company->id)->first();
        $this->assertNotNull($trailer, 'Founding should grant a starter trailer.');

        $contract = null;
        for ($i = 0; $i < 10 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = $this->generalLocalContract($hq);
        }
        $this->assertNotNull($contract, 'No general local contract to test trailer dispatch.');
        $svc->acceptContract($company, $contract);

        // Without a trailer → rejected.
        try {
            $ship->dispatch($company, $contract->fresh(), $tractor->fresh(), $driver->fresh(), null);
            $this->fail('A tractor without a trailer should not dispatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('trailer', strtolower($e->getMessage()));
        }

        // With the box trailer → dispatches, and the trailer rolls out.
        $shipment = $ship->dispatch($company, $contract->fresh(), $tractor->fresh(), $driver->fresh(), $trailer->fresh());
        $this->assertSame(Vehicle::STATUS_EN_ROUTE, $tractor->fresh()->status);
        $this->assertSame(Trailer::STATUS_EN_ROUTE, $trailer->fresh()->status);
        $this->assertSame($trailer->id, $shipment->trailer_id);
    }

    public function test_dispatch_needs_fuel_and_refuelling_restores_it(): void
    {
        $user = User::factory()->create();
        $svc = app(CompanyService::class);
        $ship = app(ShipmentService::class);

        $company = $svc->found($user, 'Fuel Co');
        $vehicle = $company->vehicles()->with('model')->first(); // starter van (self-contained)
        $driver = $company->drivers()->first();
        $hq = $company->headquarters_city_id;

        $vehicle->update(['fuel' => 0]); // run the tank dry

        $contract = null;
        for ($i = 0; $i < 10 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()->where('origin_city_id', $hq)->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);
        $svc->acceptContract($company, $contract);

        // Empty tank → blocked.
        try {
            $ship->dispatch($company, $contract->fresh(), $vehicle->fresh(), $driver->fresh());
            $this->fail('Dispatch on an empty tank should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fuel', strtolower($e->getMessage()));
        }

        // Refuel (charges cash), then dispatch succeeds.
        $cashBefore = $company->fresh()->cash;
        $svc->refuelVehicle($company->fresh(), $vehicle->fresh());
        $this->assertGreaterThan(0, $vehicle->fresh()->fuel);
        $this->assertLessThan($cashBefore, $company->fresh()->cash);

        $ship->dispatch($company->fresh(), $contract->fresh(), $vehicle->fresh(), $driver->fresh());
        $this->assertSame(Vehicle::STATUS_EN_ROUTE, $vehicle->fresh()->status);
    }

    public function test_reset_wipes_progress_and_reissues_the_starter_loadout(): void
    {
        $user = User::factory()->create();
        $svc = app(CompanyService::class);
        $company = $svc->found($user, 'Reset Co');

        // Add an extra vehicle and inflate the stats.
        $extra = VehicleModel::where('key', 'kestrel-t20')->first();
        Vehicle::create([
            'company_id' => $company->id,
            'vehicle_model_id' => $extra->id,
            'city_id' => $company->headquarters_city_id,
            'status' => Vehicle::STATUS_IDLE,
            'condition' => 100,
            'fuel' => $extra->fuel_capacity,
        ]);
        $company->update(['cash' => 42, 'level' => 7, 'xp' => 9999, 'reputation' => 5]);

        $this->assertSame(2, Vehicle::where('company_id', $company->id)->count());

        $fresh = $svc->resetCompany($company->fresh());

        // Back to a single truck, one trailer, one driver, level 1, starter cash.
        $this->assertSame(1, Vehicle::where('company_id', $fresh->id)->count());
        $this->assertSame(1, Driver::where('company_id', $fresh->id)->count());
        $this->assertSame(1, Trailer::where('company_id', $fresh->id)->count());
        $this->assertSame(1, (int) $fresh->level);
        $this->assertSame(0, (int) $fresh->xp);
        $this->assertSame(config('transoria.starter.cash'), (int) $fresh->cash);
        // Same user & company row — just a clean slate.
        $this->assertSame($company->id, $fresh->id);
        $this->assertSame($user->id, $fresh->user_id);
    }
}
