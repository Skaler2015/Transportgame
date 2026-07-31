<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Commodity;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyService;
use App\Services\EconomyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpansionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);

        $this->user = User::factory()->create();
        app(CompanyService::class)->found($this->user, 'Test Freight');
        app(EconomyService::class)->tickMarkets(new Collection());
        Sanctum::actingAs($this->user);
    }

    public function test_new_company_receives_missions(): void
    {
        $this->getJson('/api/missions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'target', 'percent', 'reward_cash', 'status']]]);

        $this->assertGreaterThan(0, count($this->getJson('/api/missions')->json('data')));
    }

    public function test_warehouse_build_buy_and_sell_flow(): void
    {
        $city = City::first();

        $build = $this->postJson('/api/warehouses', ['city_id' => $city->id, 'name' => 'Depot']);
        $build->assertCreated();
        $warehouseId = $build->json('id');

        $commodity = Commodity::where('requires_tanker', false)->where('is_hazardous', false)->first();

        $this->postJson("/api/warehouses/{$warehouseId}/buy", [
            'commodity_id' => $commodity->id, 'units' => 50,
        ])->assertOk();

        $wh = $this->getJson('/api/warehouses')->json('data.0');
        $this->assertSame(50, $wh['used']);

        $this->postJson("/api/warehouses/{$warehouseId}/sell", [
            'commodity_id' => $commodity->id, 'units' => 50,
        ])->assertOk();

        $this->assertSame(0, $this->getJson('/api/warehouses')->json('data.0.used'));
    }

    public function test_loan_borrow_and_repay(): void
    {
        $this->postJson('/api/finance/borrow', ['amount' => 100000_00])->assertCreated();

        $finance = $this->getJson('/api/finance')->json();
        $this->assertSame(100000_00, $finance['debt']);
        $loanId = $finance['loans'][0]['id'];

        $this->postJson("/api/finance/loans/{$loanId}/repay", ['amount' => 100000_00])->assertOk();
        $this->assertSame(0, $this->getJson('/api/finance')->json('debt'));
    }

    public function test_guild_create_and_leave(): void
    {
        $this->postJson('/api/guilds', ['name' => 'Iron Haul', 'tag' => 'IRON'])->assertCreated();

        $mine = $this->getJson('/api/guilds')->json('mine');
        $this->assertSame('Iron Haul', $mine['name']);
        $this->assertSame('owner', $mine['role']);

        $this->postJson('/api/guilds/leave')->assertOk();
        $this->assertNull($this->getJson('/api/guilds')->json('mine'));
    }

    public function test_accounts_summary_reports_pnl_and_balance_sheet(): void
    {
        // Take a loan so there is both a liability and financing activity.
        $this->postJson('/api/finance/borrow', ['amount' => 100000_00])->assertCreated();

        $res = $this->getJson('/api/accounts?period=all')->assertOk()->json();

        $this->assertArrayHasKey('pnl', $res);
        $this->assertArrayHasKey('balance_sheet', $res);
        $this->assertSame(100000_00, $res['balance_sheet']['liabilities']['loans']);
        // Assets include cash + fleet value (the starter truck).
        $this->assertGreaterThan(0, $res['balance_sheet']['assets']['fleet_value']);
        $this->assertSame(
            $res['balance_sheet']['assets']['total'] - $res['balance_sheet']['liabilities']['total'],
            $res['balance_sheet']['equity'],
        );

        $this->getJson('/api/accounts/ledger')
            ->assertOk()
            ->assertJsonStructure(['data' => [['category', 'amount', 'balance_after']]]);
    }

    public function test_vehicle_upgrade_increases_level(): void
    {
        $vehicle = Vehicle::where('company_id', $this->user->company->id)->first();

        $this->postJson("/api/vehicles/{$vehicle->id}/upgrade", ['kind' => 'engine'])->assertOk();

        $this->assertSame(1, $vehicle->fresh()->engine_level);
    }
}
