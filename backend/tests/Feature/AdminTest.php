<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorldEvent;
use App\Services\CompanyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_is_forbidden_from_the_panel(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_admin_sees_dashboard_metrics(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['players', 'companies', 'ai_companies', 'active_events', 'player_cash', 'regions']);
    }

    public function test_admin_can_ban_and_unban_a_player(): void
    {
        $admin = $this->admin();
        $victim = User::factory()->create();
        app(CompanyService::class)->found($victim, 'Victim Co');

        // Ban.
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$victim->id}/ban")->assertOk()->assertJson(['banned' => true]);

        // The banned player can no longer use the API.
        Sanctum::actingAs($victim->fresh());
        $this->getJson('/api/me')->assertForbidden();

        // Unban restores access.
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$victim->id}/ban")->assertOk()->assertJson(['banned' => false]);
        Sanctum::actingAs($victim->fresh());
        $this->getJson('/api/me')->assertOk();
    }

    public function test_admin_cannot_ban_themselves(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$admin->id}/ban")->assertStatus(422);
    }

    public function test_admin_can_spawn_and_end_a_world_event(): void
    {
        Sanctum::actingAs($this->admin());
        $before = WorldEvent::active()->count();

        $res = $this->postJson('/api/admin/events', ['type' => 'fuel_crisis', 'hours' => 5])->assertCreated();
        $this->assertSame($before + 1, WorldEvent::active()->count());

        $id = $res->json('id');
        $this->postJson("/api/admin/events/{$id}/end")->assertOk();
        $this->assertSame($before, WorldEvent::active()->count());
    }

    public function test_admin_can_nudge_regional_fuel_prices(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/economy/fuel', ['delta_pct' => 10])
            ->assertOk()
            ->assertJsonStructure(['affected']);
    }
}
