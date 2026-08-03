<?php

namespace Tests\Feature;

use App\Models\Commodity;
use App\Models\Company;
use App\Models\Factory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\CompanyService;
use App\Services\ManufacturingService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);

        $this->user = User::factory()->create();
        $this->company = app(CompanyService::class)->found($this->user, 'Maker Co');
        // Level up + cash so every recipe is buildable in tests.
        $this->company->update(['level' => 10, 'cash' => 50_000_000_00]);
        Sanctum::actingAs($this->user);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'company_id' => $this->company->id,
            'city_id' => $this->company->headquarters_city_id,
            'name' => 'HQ Depot',
            'tier' => 3,
            'capacity' => 100000,
        ]);
    }

    public function test_build_requires_your_own_warehouse(): void
    {
        $mfg = app(ManufacturingService::class);

        // A warehouse belonging to a different company.
        $rival = app(CompanyService::class)->found(User::factory()->create(), 'Rival Co');
        $other = Warehouse::create([
            'company_id' => $rival->id,
            'city_id' => $rival->headquarters_city_id,
            'name' => 'Not mine', 'tier' => 1, 'capacity' => 1000,
        ]);

        $this->expectExceptionMessage('not yours');
        $mfg->build($this->company, $other, 'farm');
    }

    public function test_extractor_produces_output_and_charges_cash(): void
    {
        $wh = $this->warehouse();
        $mfg = app(ManufacturingService::class);
        $factory = $mfg->build($this->company, $wh, 'farm'); // grain, no inputs
        $this->company->refresh();
        $cashAfterBuild = $this->company->cash;

        // Backdate so several cycles are due, then step.
        $factory->update(['last_produced_at' => now()->subMinutes(30)]);
        $mfg->produceDue($this->company->refresh());

        $grain = Commodity::where('key', 'grain')->first();
        $inv = WarehouseInventory::where('warehouse_id', $wh->id)->where('commodity_id', $grain->id)->first();

        $this->assertNotNull($inv, 'Grain should have been produced into the warehouse.');
        $this->assertGreaterThan(0, $inv->units);
        $this->assertGreaterThan(0, $inv->avg_unit_cost, 'Output carries a real unit cost.');
        $this->assertLessThan($cashAfterBuild, $this->company->refresh()->cash, 'Running the factory spends cash.');
        $this->assertGreaterThan(0, $factory->refresh()->lifetime_output);
    }

    public function test_factory_idles_without_its_inputs(): void
    {
        $wh = $this->warehouse();
        $mfg = app(ManufacturingService::class);
        // Furniture factory needs timber, which the empty warehouse lacks.
        $factory = $mfg->build($this->company, $wh, 'furniture_factory');
        $factory->update(['last_produced_at' => now()->subMinutes(30)]);

        $mfg->produceDue($this->company->refresh());

        $factory->refresh();
        $this->assertSame(0, (int) $factory->lifetime_output);
        $this->assertStringContainsString('Timber', (string) $factory->last_note);
    }

    public function test_supply_chain_consumes_inputs_to_make_finished_goods(): void
    {
        $wh = $this->warehouse();
        $mfg = app(ManufacturingService::class);
        $timber = Commodity::where('key', 'timber')->first();
        $furniture = Commodity::where('key', 'furniture')->first();

        // Stock plenty of timber so the furniture line can run.
        WarehouseInventory::create([
            'warehouse_id' => $wh->id, 'commodity_id' => $timber->id,
            'units' => 500, 'avg_unit_cost' => 80,
        ]);

        $factory = $mfg->build($this->company, $wh, 'furniture_factory');
        $factory->update(['last_produced_at' => now()->subMinutes(10)]);
        $mfg->produceDue($this->company->refresh());

        $timberLeft = WarehouseInventory::where('warehouse_id', $wh->id)->where('commodity_id', $timber->id)->first();
        $furn = WarehouseInventory::where('warehouse_id', $wh->id)->where('commodity_id', $furniture->id)->first();

        $this->assertLessThan(500, $timberLeft->units, 'Timber should be consumed as input.');
        $this->assertNotNull($furn);
        $this->assertGreaterThan(0, $furn->units, 'Furniture should be produced.');
    }

    public function test_factories_endpoint_lists_owned_and_catalog(): void
    {
        $wh = $this->warehouse();
        app(ManufacturingService::class)->build($this->company, $wh, 'farm');

        $res = $this->getJson('/api/factories')->assertOk();
        $res->assertJsonStructure([
            'data' => [['id', 'name', 'level', 'status', 'output_per_cycle', 'warehouse']],
            'catalog' => [['recipe', 'name', 'output', 'build_cost', 'unlocked', 'affordable']],
        ]);
        $this->assertGreaterThan(0, count($res->json('catalog')));
    }
}
