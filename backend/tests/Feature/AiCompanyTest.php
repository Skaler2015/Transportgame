<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\AiCompanyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
    }

    public function test_ensure_roster_founds_rivals(): void
    {
        $ai = app(AiCompanyService::class);
        $founded = $ai->ensureRoster('IN');

        $target = (int) config('transoria.ai.roster_size');
        $this->assertSame($target, $founded);

        $rivals = Company::ai()->get();
        $this->assertCount($target, $rivals);
        $this->assertTrue($rivals->every(fn (Company $c) => $c->ai_fleet_size > 0));
        $this->assertTrue($rivals->every(fn (Company $c) => ! empty($c->ai_strategy)));

        // A second pass is a no-op — the roster is already full.
        $this->assertSame(0, $ai->ensureRoster('IN'));
    }

    public function test_step_advances_the_rival_economy(): void
    {
        $ai = app(AiCompanyService::class);
        $ai->ensureRoster('IN');

        // Rewind every rival's clock a few hours so a step has work to do.
        $nowHour = $ai->currentHour();
        Company::ai()->get()->each(function (Company $c) use ($nowHour) {
            $state = $c->ai_state;
            $state['last_hour'] = $nowHour - 4;
            $c->update(['ai_state' => $state, 'lifetime_revenue' => 0]);
        });

        $ai->stepAll();

        $totalRevenue = (int) Company::ai()->sum('lifetime_revenue');
        $this->assertGreaterThan(0, $totalRevenue, 'Rivals should book revenue after stepping.');

        // Their clock has advanced to now.
        $this->assertTrue(
            Company::ai()->get()->every(fn (Company $c) => ($c->ai_state['last_hour'] ?? 0) === $nowHour)
        );
    }

    public function test_sustained_losses_bankrupt_and_refound_a_rival(): void
    {
        $ai = app(AiCompanyService::class);
        $rival = $ai->found('IN');

        $threshold = (int) config('transoria.ai.bankrupt_after_hours');
        $originalName = $rival->name;

        // Force it deep into the red with the loss clock already run down and no
        // trucks to earn its way out — the next stepped hour must fold it.
        $rival->update([
            'cash' => -50000_00,
            'ai_fleet_size' => 0,
            'ai_state' => ['loss_streak' => $threshold, 'last_hour' => $ai->currentHour() - 1],
        ]);

        $ai->stepAll();

        $rival->refresh();
        // Refounded in place: fresh capital, a clean loss streak, still live.
        $this->assertNull($rival->ai_bankrupt_at);
        $this->assertGreaterThan(0, $rival->cash);
        $this->assertSame(0, (int) ($rival->ai_state['loss_streak'] ?? -1));
        $this->assertNotSame($originalName, $rival->name);
    }

    public function test_leaderboard_lists_ai_firms_with_share(): void
    {
        $res = $this->getJson('/api/world/leaderboard')->assertOk();

        $res->assertJsonStructure([
            'data' => [['rank', 'name', 'fleet_size', 'is_ai', 'market_share']],
            'meta' => ['total_firms', 'ai_firms', 'world_revenue'],
        ]);

        $this->assertGreaterThan(0, $res->json('meta.ai_firms'));
        $this->assertTrue(
            collect($res->json('data'))->contains(fn ($row) => $row['is_ai'] === true)
        );
    }
}
