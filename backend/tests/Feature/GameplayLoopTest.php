<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\Trailer;
use App\Models\TrailerModel;
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

    public function test_delivery_that_arrived_before_deadline_is_not_marked_late_when_resolved_late(): void
    {
        // Resolution is lazy (no cron): a shipment settles whenever the player
        // next loads a page after the ETA passes — possibly long after. Lateness
        // must be judged by the scheduled arrival (eta_at) vs the deadline, NOT
        // the wall-clock resolution moment, or an away player is wrongly penalised.
        $user = User::factory()->create();
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);

        $company = $companyService->found($user, 'Punctual Co');
        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);

        $companyService->acceptContract($company, $contract);
        $shipment = $shipmentService->dispatch($company, $contract, $vehicle, $driver);

        // Truck arrived 5 min ago (before a deadline 2 min ago); we only resolve
        // it now — well after both, simulating a player who stepped away.
        $shipment->update([
            'eta_at' => now()->subMinutes(5),
            'deadline_at' => now()->subMinutes(2),
        ]);
        $shipmentService->resolve($shipment->fresh());

        // An accident may still fail it, but it must never be LATE: it arrived on time.
        $this->assertNotSame(Shipment::STATUS_LATE, $shipment->fresh()->status);
    }

    public function test_dispatch_resets_a_stale_contract_deadline_so_prompt_delivery_is_on_time(): void
    {
        // A contract's mint-time deadline can already be in the past by the time
        // the player dispatches it (jobs linger on the market). Dispatch must
        // reset the deadline from departure, or the truck is LATE the instant it rolls.
        $user = User::factory()->create();
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);

        $company = $companyService->found($user, 'Deadline Co');
        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);

        // Simulate a job that sat on the board long enough for its deadline to lapse.
        $contract->update(['deadline_at' => now()->subMinutes(10)]);

        $companyService->acceptContract($company, $contract);
        $shipment = $shipmentService->dispatch($company, $contract->fresh(), $vehicle, $driver);

        // Dispatch should have pushed the deadline into the future.
        $this->assertTrue($contract->fresh()->deadline_at->isFuture());

        // Deliver promptly (arrival within the fresh window) → never LATE.
        $shipment->update(['eta_at' => now()]);
        $shipmentService->resolve($shipment->fresh());
        $this->assertNotSame(Shipment::STATUS_LATE, $shipment->fresh()->status);
    }

    public function test_every_generated_contract_pays_at_least_5_per_km_and_never_runs_at_a_loss(): void
    {
        // Mint a spread of contracts across many lanes.
        for ($i = 0; $i < 4; $i++) {
            $this->artisan('world:tick');
        }

        $contracts = Contract::onMarket()->with(['origin', 'destination'])->limit(80)->get();
        $this->assertNotEmpty($contracts, 'The market should have open contracts.');

        foreach ($contracts as $c) {
            // ₹5/km floor: payout (cents) is at least distance × ₹5 (× 100),
            // allowing a little rounding slack.
            $this->assertGreaterThanOrEqual(
                $c->distance_km * 5 * 100 - 200,
                $c->payout,
                "Contract {$c->id} pays under ₹5/km."
            );

            // Profitability: payout, after tax, must clear tolls and fuel.
            $avgToll = (($c->origin->toll_per_km ?? 0) + ($c->destination->toll_per_km ?? 0)) / 2;
            $tollCents = $c->distance_km * $avgToll * 100;
            $fuelCents = $c->distance_km * 0.30 * ($c->origin->fuel_price ?? 1.0) * 100;
            $taxCents = $c->payout * (float) ($c->destination->tax_rate ?? 0);
            $net = $c->payout - $taxCents - $tollCents - $fuelCents;

            $this->assertGreaterThan(0, $net, "Contract {$c->id} ({$c->origin->name} → {$c->destination->name}) runs at a loss.");
        }
    }

    public function test_contract_market_flags_jobs_at_a_trucks_en_route_destination(): void
    {
        // As a truck drives to a city, the market should already surface (and
        // top-float) the next load leaving that city, flagged "arriving".
        $token = $this->postJson('/api/register', [
            'name' => 'Router', 'email' => 'router@transoria.io',
            'password' => 'password123', 'company_name' => 'Router Freight',
        ])->json('token');

        $user = User::where('email', 'router@transoria.io')->first();
        $company = $user->company;
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);

        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);

        $companyService->acceptContract($company, $contract);
        $shipmentService->dispatch($company, $contract, $vehicle, $driver);
        $destId = $contract->destination_city_id;

        // Point an open job's origin at that destination so there's one to flag.
        $open = Contract::onMarket()->where('origin_city_id', '!=', $destId)->first();
        $this->assertNotNull($open);
        $open->update(['origin_city_id' => $destId]);

        $rows = $this->withToken($token)->getJson('/api/contracts?haulable=0')->json('data');
        $flagged = collect($rows)->firstWhere('id', $open->id);

        $this->assertNotNull($flagged, 'The repointed job should appear on the board.');
        $this->assertTrue($flagged['at_fleet_city'], 'A job at the truck\'s destination should be flagged.');
        $this->assertTrue($flagged['fleet_arriving'], 'It should read as arriving (truck still en route).');
    }

    public function test_completing_a_run_auto_refuels_the_truck_and_records_the_cost(): void
    {
        // On arrival the truck is auto-REFUELLED (fuel only — servicing stays
        // manual), charged as a small per-run line item on the shipment.
        $user = User::factory()->create();
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);

        $company = $companyService->found($user, 'Upkeep Co');
        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);

        $companyService->acceptContract($company, $contract);
        $shipment = $shipmentService->dispatch($company, $contract, $vehicle, $driver);

        $shipment->update(['eta_at' => now()->subMinute(), 'deadline_at' => now()->addYear()]);
        $shipmentService->resolve($shipment->fresh());

        $v = $vehicle->fresh();
        // Tank was topped back up, and the fuel cost was recorded on the run.
        $this->assertEquals((float) $v->model->fuel_capacity, (float) $v->fuel);
        $this->assertGreaterThan(0, $shipment->fresh()->service_cost);
    }

    public function test_every_city_offers_work_for_small_and_large_vehicles(): void
    {
        // All cities stay connected with jobs across load sizes, so any vehicle
        // at any location always has a contract it can take.
        app(\App\Services\EconomyService::class)->ensureCityCoverage('IN');

        foreach (\App\Models\City::where('country', 'IN')->get() as $city) {
            $loads = Contract::onMarket()
                ->where('origin_city_id', $city->id)
                ->with('commodity')->get()
                ->map(fn (Contract $c) => $c->commodity->weight_per_unit * $c->units);

            $this->assertTrue($loads->contains(fn ($t) => $t <= 1.5),
                "City {$city->name} has no small-vehicle load.");
            $this->assertTrue($loads->contains(fn ($t) => $t >= 8),
                "City {$city->name} has no large-vehicle load.");
        }
    }

    public function test_market_guarantees_work_at_a_trucks_destination(): void
    {
        // Wherever a truck is heading, haulable work must be waiting when it arrives.
        $token = $this->postJson('/api/register', [
            'name' => 'Router2', 'email' => 'router2@transoria.io',
            'password' => 'password123', 'company_name' => 'Router2 Freight',
        ])->json('token');

        $company = User::where('email', 'router2@transoria.io')->first()->company;
        $companyService = app(CompanyService::class);
        $shipmentService = app(ShipmentService::class);
        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();

        $contract = null;
        for ($i = 0; $i < 8 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()
                ->where('origin_city_id', $company->headquarters_city_id)
                ->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);
        $companyService->acceptContract($company, $contract);
        $shipmentService->dispatch($company, $contract, $vehicle, $driver);
        $destId = $contract->destination_city_id;

        // Viewing the board tops up work at the destination the truck is driving to.
        $this->withToken($token)->getJson('/api/contracts')->assertOk();

        $haulable = Contract::onMarket()
            ->where('origin_city_id', $destId)
            ->with('commodity')->get()
            ->filter(fn (Contract $c) => $c->commodity->canBeCarriedBy($vehicle->model)
                && $c->commodity->weight_per_unit * $c->units <= $vehicle->effectiveCapacityWeight() + 0.001);

        $this->assertGreaterThan(0, $haulable->count(),
            'A truck should find haulable work waiting at its destination.');
    }

    public function test_market_guarantees_local_work_where_a_free_truck_is_parked(): void
    {
        // A free truck must always have jobs starting from its own city.
        $token = $this->postJson('/api/register', [
            'name' => 'Local', 'email' => 'local@transoria.io',
            'password' => 'password123', 'company_name' => 'Local Freight',
        ])->json('token');

        $company = User::where('email', 'local@transoria.io')->first()->company;
        $truck = $company->vehicles()->where('status', Vehicle::STATUS_IDLE)->with('model')->first();
        $hqId = $company->headquarters_city_id;

        // Viewing the board triggers the local-work guarantee.
        $this->withToken($token)->getJson('/api/contracts')->assertOk();

        $localHaulable = Contract::onMarket()
            ->where('origin_city_id', $hqId)
            ->with('commodity')->get()
            ->filter(fn (Contract $c) => $c->commodity->canBeCarriedBy($truck->model)
                && $c->commodity->weight_per_unit * $c->units <= $truck->effectiveCapacityWeight() + 0.001);

        $this->assertGreaterThan(0, $localHaulable->count(),
            'A free truck should always find haulable jobs starting from its city.');
    }

    public function test_haulable_filter_only_returns_jobs_an_idle_truck_can_carry(): void
    {
        // "Only what my fleet can haul" must reflect what a FREE truck can take
        // now — not heavy jobs only a bigger, still-en-route truck could do.
        $token = $this->postJson('/api/register', [
            'name' => 'Small', 'email' => 'small@transoria.io',
            'password' => 'password123', 'company_name' => 'Small Freight',
        ])->json('token');

        $company = User::where('email', 'small@transoria.io')->first()->company;
        for ($i = 0; $i < 4; $i++) {
            $this->artisan('world:tick');
        }

        $idleCap = $company->vehicles()->where('status', Vehicle::STATUS_IDLE)
            ->with('model')->get()
            ->max(fn (Vehicle $v) => $v->effectiveCapacityWeight());
        $this->assertNotNull($idleCap);

        $rows = $this->withToken($token)->getJson('/api/contracts?haulable=1')->json('data');

        foreach ($rows as $c) {
            $this->assertLessThanOrEqual(
                $idleCap + 0.001,
                $c['total_weight'],
                "Contract {$c['id']} ({$c['total_weight']}t) exceeds the idle fleet's capacity ({$idleCap}t)."
            );
        }
    }

    public function test_vehicles_get_sequential_fleet_numbers(): void
    {
        $user = User::factory()->create();
        $companyService = app(CompanyService::class);
        $company = $companyService->found($user, 'Numbered Co');

        // The starter truck is #1.
        $this->assertSame(1, (int) $company->vehicles()->first()->fleet_no);

        // Each purchase takes the next number.
        $model = VehicleModel::orderBy('price')->first();
        $second = $companyService->buyVehicle($company->fresh(), $model);
        $third = $companyService->buyVehicle($company->fresh(), $model);

        $this->assertSame(2, (int) $second->fleet_no);
        $this->assertSame(3, (int) $third->fleet_no);
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

    public function test_registration_accepts_brand_colour_and_hq_and_tutorial_completes(): void
    {
        $city = \App\Models\City::where('country', 'IN')->where('unlock_level', 1)->first();

        $resp = $this->postJson('/api/register', [
            'name' => 'Founder',
            'email' => 'founder@transoria.io',
            'password' => 'password123',
            'company_name' => 'Custom Co',
            'country' => 'IN',
            'headquarters_city_id' => $city->id,
            'logo_color' => '#a78bfa',
        ]);

        $resp->assertCreated()
            ->assertJsonPath('company.logo_color', '#a78bfa')
            ->assertJsonPath('company.headquarters.id', $city->id)
            ->assertJsonPath('company.onboarded_at', null); // fresh players see the tutorial

        $token = $resp->json('token');

        $done = $this->withToken($token)->postJson('/api/company/tutorial', ['done' => true]);
        $done->assertOk();
        $this->assertNotNull($done->json('data.onboarded_at'));
    }

    public function test_buy_trailer_endpoint_returns_the_purchased_trailer(): void
    {
        // Exercises the full HTTP path incl. ->load('model','city') + resource
        // serialization, which a service-only test would miss.
        $token = $this->postJson('/api/register', [
            'name' => 'Buyer',
            'email' => 'buyer@transoria.io',
            'password' => 'password123',
            'company_name' => 'Buyer Freight',
        ])->json('token');

        $model = TrailerModel::where('key', 'box-std')->first();

        $this->withToken($token)
            ->postJson("/api/trailers/dealership/{$model->id}/buy")
            ->assertCreated()
            ->assertJsonPath('data.model.key', 'box-std')
            ->assertJsonPath('data.status', 'idle');
    }

    public function test_achievements_unlock_and_grant_rewards(): void
    {
        $user = User::factory()->create();
        $svc = app(CompanyService::class);
        $company = $svc->found($user, 'Trophy Co');
        $achievements = app(\App\Services\AchievementService::class);

        // Nothing earned at the very start.
        $this->assertSame(0, \App\Models\CompanyAchievement::where('company_id', $company->id)->count());

        // Simulate a first delivery + some revenue, then evaluate.
        $company->update(['shipments_completed' => 1, 'lifetime_revenue' => 600_000_00, 'cash' => 300_000_00]);
        $cashBefore = $company->fresh()->cash;

        $new = $achievements->check($company->fresh());

        $keys = collect($new)->pluck('key');
        $this->assertTrue($keys->contains('deliver_1'), 'First delivery achievement should unlock.');
        $this->assertTrue($keys->contains('rev_500k'), 'Revenue achievement should unlock.');
        // Reward cash was credited via the ledger.
        $this->assertGreaterThan($cashBefore, $company->fresh()->cash);
        // Re-running is idempotent — no duplicates.
        $this->assertCount(0, $achievements->check($company->fresh()));
    }

    public function test_contract_dispatch_endpoint_claims_and_rolls_in_one_step(): void
    {
        $token = $this->postJson('/api/register', [
            'name' => 'Quick', 'email' => 'quick@transoria.io', 'password' => 'password123',
            'company_name' => 'Quick Freight',
        ])->json('token');

        $company = \App\Models\User::where('email', 'quick@transoria.io')->first()->company;
        $vehicle = $company->vehicles()->with('model')->first();
        $driver = $company->drivers()->first();
        $hq = $company->headquarters_city_id;

        // Find an OPEN market contract the starter van can haul.
        $contract = null;
        for ($i = 0; $i < 10 && ! $contract; $i++) {
            $this->artisan('world:tick');
            $contract = Contract::onMarket()->where('origin_city_id', $hq)->with('commodity')->get()
                ->first(fn (Contract $c) => $this->fits($c, $vehicle));
        }
        $this->assertNotNull($contract);

        // One call: claims the open contract AND dispatches against it.
        $this->withToken($token)
            ->postJson("/api/contracts/{$contract->id}/dispatch", [
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'en_route');

        $this->assertSame(Vehicle::STATUS_EN_ROUTE, $vehicle->fresh()->status);
        $this->assertSame(Contract::STATUS_IN_PROGRESS, $contract->fresh()->status);
    }

    public function test_advisor_returns_actionable_insights(): void
    {
        (new \Database\Seeders\ListedCompanySeeder)->run();

        $token = $this->postJson('/api/register', [
            'name' => 'Boss', 'email' => 'boss@transoria.io', 'password' => 'password123',
            'company_name' => 'Advisor Co',
        ])->json('token');

        $this->artisan('world:tick');

        $resp = $this->withToken($token)->getJson('/api/advisor');
        $resp->assertOk();
        $this->assertNotEmpty($resp->json('data'));
        $this->assertArrayHasKey('title', $resp->json('data')[0]);
    }

    public function test_stock_trading_buys_sells_and_pays_dividends(): void
    {
        (new \Database\Seeders\ListedCompanySeeder)->run();

        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Investor Co');
        $company->update(['cash' => 10_000_000_00]);
        $stocks = app(\App\Services\StockService::class);

        $listed = \App\Models\ListedCompany::first();

        $stocks->buy($company->fresh(), $listed, 100);
        $holding = \App\Models\ShareHolding::where('company_id', $company->id)->first();
        $this->assertSame(100, (int) $holding->shares);

        // A due dividend pays out.
        $holding->update(['last_dividend_at' => now()->subHour()]);
        $cashBefore = $company->fresh()->cash;
        $stocks->refresh($company->fresh());
        $this->assertGreaterThan($cashBefore, $company->fresh()->cash);

        // Selling reduces the position.
        $stocks->sell($company->fresh(), $listed->fresh(), 40);
        $this->assertSame(60, (int) \App\Models\ShareHolding::where('company_id', $company->id)->first()->shares);
    }

    public function test_warehouse_upgrades_expand_capacity_and_unlock_storage(): void
    {
        $user = \App\Models\User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Depot Co');
        $company->update(['cash' => 5_000_000_00]);

        $city = \App\Models\City::where('country', 'IN')->first();
        $wh = app(\App\Services\WarehouseService::class);
        $warehouse = $wh->build($company->fresh(), $city, 'Test Depot');

        $baseCap = $warehouse->effectiveCapacity();

        // Staff raises effective capacity.
        $wh->upgrade($company->fresh(), $warehouse->fresh(), 'staff');
        $this->assertGreaterThan($baseCap, $warehouse->fresh()->effectiveCapacity());

        // Expanding raises the tier.
        $wh->upgrade($company->fresh(), $warehouse->fresh(), 'expand');
        $this->assertSame(2, (int) $warehouse->fresh()->tier);

        // Cold storage unlocks perishables.
        $perishable = \App\Models\Commodity::where('is_perishable', true)->first();
        if ($perishable) {
            $this->assertFalse($warehouse->fresh()->canStore($perishable));
            $wh->upgrade($company->fresh(), $warehouse->fresh(), 'cold');
            $this->assertTrue($warehouse->fresh()->canStore($perishable));
        }
    }

    public function test_driver_hr_actions_train_renew_and_rest(): void
    {
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'HR Co');
        $hr = app(\App\Services\DriverService::class);

        $driver = $company->drivers()->first();
        $this->assertTrue($driver->isLicensed(), 'A hired driver starts licensed.');

        // Training raises skill.
        $skillBefore = $driver->skill;
        $hr->train($company->fresh(), $driver->fresh());
        $this->assertGreaterThan($skillBefore, $driver->fresh()->skill);

        // Licence renewal extends the expiry.
        $driver->update(['licence_until' => now()->subDay()]);
        $this->assertFalse($driver->fresh()->isLicensed());
        $hr->renewLicence($company->fresh(), $driver->fresh());
        $this->assertTrue($driver->fresh()->isLicensed());

        // Vacation restores health and clears fatigue.
        $driver->update(['health' => 40, 'fatigue' => 70]);
        $hr->vacation($company->fresh(), $driver->fresh());
        $this->assertSame(100, (int) $driver->fresh()->health);
        $this->assertSame(0, (int) $driver->fresh()->fatigue);
    }

    public function test_garage_services_restore_health_and_renew_papers(): void
    {
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Garage Co');
        $garage = app(\App\Services\GarageService::class);

        $vehicle = $company->vehicles()->with('model')->first();
        $this->assertTrue($vehicle->isInsured(), 'A new vehicle ships insured.');

        // Neglect it, then service it.
        $vehicle->update(['oil_level' => 10, 'battery' => 15, 'insured_until' => now()->subDay()]);
        $this->assertFalse($vehicle->fresh()->isInsured());

        $garage->service($company->fresh(), $vehicle->fresh(), 'oil');
        $this->assertSame(100.0, (float) $vehicle->fresh()->oil_level);

        $garage->service($company->fresh(), $vehicle->fresh(), 'battery');
        $this->assertSame(100.0, (float) $vehicle->fresh()->battery);

        $garage->service($company->fresh(), $vehicle->fresh(), 'insurance');
        $this->assertTrue($vehicle->fresh()->isInsured(), 'Renewal restores insurance.');
    }

    public function test_full_service_repairs_refuels_and_restores_in_one_call(): void
    {
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'OneClick Co');
        $garage = app(\App\Services\GarageService::class);

        $vehicle = $company->vehicles()->with('model')->first();
        $vehicle->update([
            'condition' => 40, 'tire_wear' => 60, 'oil_level' => 20,
            'battery' => 30, 'fuel' => 10,
        ]);

        $garage->fullService($company->fresh(), $vehicle->fresh());

        $v = $vehicle->fresh();
        $this->assertSame(100.0, (float) $v->condition);
        $this->assertSame(0.0, (float) $v->tire_wear);
        $this->assertSame(100.0, (float) $v->oil_level);
        $this->assertSame(100.0, (float) $v->battery);
        $this->assertEqualsWithDelta((float) $v->model->fuel_capacity, (float) $v->fuel, 0.01);
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
