<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiCompanyService;
use App\Services\CompanyService;
use App\Services\EconomyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketEconomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
    }

    public function test_commercial_demand_scales_with_firm_clustering(): void
    {
        $economy = app(EconomyService::class);

        // No firms yet → no regional uplift.
        $this->assertSame([], $economy->commercialDemandByRegion());

        // Populate rivals; now their home regions run hotter (> 1.0), capped.
        app(AiCompanyService::class)->ensureRoster('IN');
        $factors = $economy->commercialDemandByRegion();

        $this->assertNotEmpty($factors);
        $cap = 1 + (float) config('transoria.economy.commercial_demand_cap');
        foreach ($factors as $f) {
            $this->assertGreaterThanOrEqual(1.0, $f);
            $this->assertLessThanOrEqual($cap + 1e-9, $f);
        }
    }

    public function test_market_overview_endpoint_returns_sorted_movers(): void
    {
        // A company so the country resolves, plus a market tick to record prices.
        $user = User::factory()->create();
        app(CompanyService::class)->found($user, 'Test Freight');
        app(EconomyService::class)->tickMarkets(new Collection());
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/market/overview')->assertOk();
        $res->assertJsonStructure([
            'data' => [['commodity_id', 'commodity', 'avg_price', 'base_price', 'delta_pct', 'demand_index', 'markets']],
        ]);

        $deltas = collect($res->json('data'))->pluck('delta_pct')->all();
        $sorted = $deltas;
        rsort($sorted);
        $this->assertSame($sorted, $deltas, 'Movers should be sorted by delta_pct descending.');
    }
}
