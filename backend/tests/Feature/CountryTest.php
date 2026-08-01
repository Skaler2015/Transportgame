<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyService;
use Database\Seeders\CitySeeder;
use Database\Seeders\CommoditySeeder;
use Database\Seeders\VehicleModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CommoditySeeder::class, CitySeeder::class, VehicleModelSeeder::class]);
    }

    public function test_registration_places_company_in_chosen_country(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'Yankee', 'email' => 'us@example.com', 'password' => 'password123',
            'company_name' => 'Star Freight', 'country' => 'US',
        ])->assertCreated();

        $this->assertSame('US', $res->json('company.country'));

        $company = Company::first();
        $hq = City::find($company->headquarters_city_id);
        $this->assertSame('US', $hq->country);
    }

    public function test_country_defaults_to_india(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'Desi', 'email' => 'in@example.com', 'password' => 'password123',
            'company_name' => 'Bharat Cargo',
        ])->assertCreated();

        $this->assertSame('IN', $res->json('company.country'));
    }

    public function test_cities_endpoint_is_scoped_to_country(): void
    {
        $in = $this->getJson('/api/world/cities?country=IN')->json('data');
        $us = $this->getJson('/api/world/cities?country=US')->json('data');

        $this->assertNotEmpty($in);
        $this->assertNotEmpty($us);
        $this->assertTrue(collect($in)->every(fn ($c) => $c['country'] === 'IN'));
        $this->assertTrue(collect($us)->every(fn ($c) => $c['country'] === 'US'));
    }

    public function test_changing_country_relocates_fleet(): void
    {
        $user = User::factory()->create();
        $company = app(CompanyService::class)->found($user, 'Mover Co', 'IN');
        Sanctum::actingAs($user);

        $this->postJson('/api/company/country', ['country' => 'AE'])->assertOk();

        $company->refresh();
        $this->assertSame('AE', $company->country);

        $hq = City::find($company->headquarters_city_id);
        $this->assertSame('AE', $hq->country);

        $vehicleCity = City::find(Vehicle::where('company_id', $company->id)->value('city_id'));
        $this->assertSame('AE', $vehicleCity->country);
    }
}
