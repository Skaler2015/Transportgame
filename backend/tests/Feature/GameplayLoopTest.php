<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyService;
use App\Services\ShipmentService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
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
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
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
}
