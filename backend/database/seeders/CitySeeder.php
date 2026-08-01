<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Commodity;
use Illuminate\Database\Seeder;

/**
 * Real-world cities grouped by country. A player's world is scoped to their
 * country, so each country ships a self-contained road network with producers
 * and consumers. Coordinates are real; the economy roles are game design.
 *
 * Idempotent: keyed on (name, country) so re-running only updates.
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $commodityIds = Commodity::pluck('id', 'key');
        if ($commodityIds->isEmpty()) {
            (new CommoditySeeder)->run();
            $commodityIds = Commodity::pluck('id', 'key');
        }

        foreach ($this->world() as $iso => $country) {
            foreach ($country['cities'] as $c) {
                // name, region, lat, lng, pop, fuel, tax, port, air, rail, unlock, produces[], consumes[]
                [$name, $region, $lat, $lng, $pop, $fuel, $tax, $port, $air, $rail, $unlock, $produces, $consumes] = $c;

                $city = City::updateOrCreate(
                    ['name' => $name, 'country' => $iso],
                    [
                        'region' => $region,
                        'country_name' => $country['name'],
                        'country_code' => $iso,
                        'lat' => $lat,
                        'lng' => $lng,
                        'population' => $pop,
                        'economic_growth' => 1.0,
                        'traffic' => random_int(20, 65),
                        'tax_rate' => $tax,
                        'fuel_price' => $fuel,
                        'weather' => 'clear',
                        'unlock_level' => $unlock,
                        'has_port' => $port,
                        'has_airport' => $air,
                        'has_rail' => $rail,
                    ]
                );

                $this->attachCommodities($city, $commodityIds, $produces, $consumes, $pop);
            }
        }
    }

    private function attachCommodities(City $city, $ids, array $produces, array $consumes, int $pop): void
    {
        $sync = [];
        $scale = max(1, (int) ($pop / 2_000_000));

        foreach ($produces as $key) {
            if (! isset($ids[$key])) {
                continue;
            }
            $sync[$ids[$key]] = [
                'production' => random_int(60, 140) * $scale,
                'consumption' => random_int(10, 30) * $scale,
                'stock' => random_int(4000, 12000),
                'stock_cap' => 40000,
            ];
        }
        foreach ($consumes as $key) {
            if (! isset($ids[$key]) || isset($sync[$ids[$key]])) {
                continue;
            }
            $sync[$ids[$key]] = [
                'production' => 0,
                'consumption' => random_int(50, 120) * $scale,
                'stock' => random_int(200, 1500),
                'stock_cap' => 30000,
            ];
        }

        $city->commodities()->sync($sync);
    }

    /** @return array<string,array{name:string,cities:array}> */
    private function world(): array
    {
        return [
            'IN' => ['name' => 'India', 'cities' => [
                ['Delhi', 'Delhi', 28.6139, 77.2090, 32000000, 1.10, 0.09, false, true, true, 1, ['electronics', 'medicine', 'textiles'], ['grain', 'fuel', 'automobiles']],
                ['Mumbai', 'Maharashtra', 19.0760, 72.8777, 20700000, 1.16, 0.11, true, true, true, 1, ['automobiles', 'luxury_goods', 'fuel'], ['steel_coil', 'textiles', 'frozen_goods']],
                ['Bengaluru', 'Karnataka', 12.9716, 77.5946, 13000000, 1.08, 0.08, false, true, true, 1, ['electronics', 'machinery', 'solar_panels'], ['grain', 'coffee', 'medicine']],
                ['Jaipur', 'Rajasthan', 26.9124, 75.7873, 4000000, 1.04, 0.07, false, true, true, 1, ['textiles', 'furniture'], ['electronics', 'fuel', 'medicine']],
                ['Chennai', 'Tamil Nadu', 13.0827, 80.2707, 11000000, 1.14, 0.10, true, true, true, 2, ['automobiles', 'electronics'], ['crude_oil', 'steel_coil', 'grain']],
                ['Kolkata', 'West Bengal', 22.5726, 88.3639, 15000000, 1.12, 0.10, true, false, true, 2, ['steel_coil', 'textiles', 'timber'], ['electronics', 'medicine', 'fuel']],
                ['Hyderabad', 'Telangana', 17.3850, 78.4867, 10000000, 1.06, 0.08, false, true, true, 2, ['medicine', 'electronics'], ['grain', 'fuel', 'coffee']],
                ['Pune', 'Maharashtra', 18.5204, 73.8567, 7000000, 1.08, 0.08, false, false, true, 3, ['automobiles', 'machinery'], ['grain', 'electronics', 'coffee']],
                ['Ahmedabad', 'Gujarat', 23.0225, 72.5714, 8000000, 1.02, 0.07, false, true, true, 3, ['textiles', 'chemicals'], ['grain', 'machinery', 'fuel']],
                ['Surat', 'Gujarat', 21.1702, 72.8311, 7000000, 1.02, 0.07, false, false, true, 4, ['textiles', 'luxury_goods'], ['grain', 'machinery', 'fuel']],
                ['Kanpur', 'Uttar Pradesh', 26.4499, 80.3319, 3000000, 1.00, 0.06, false, false, true, 4, ['textiles', 'timber', 'chemicals'], ['electronics', 'medicine']],
                ['Nagpur', 'Maharashtra', 21.1458, 79.0882, 3000000, 1.04, 0.07, false, true, true, 5, ['steel_coil', 'timber'], ['grain', 'electronics']],
                ['Kochi', 'Kerala', 9.9312, 76.2673, 3000000, 1.16, 0.09, true, true, false, 5, ['crude_oil', 'fuel', 'coffee'], ['steel_coil', 'electronics', 'grain']],
                ['Visakhapatnam', 'Andhra Pradesh', 17.6868, 83.2185, 2300000, 1.10, 0.08, true, false, true, 6, ['steel_coil', 'crude_oil'], ['grain', 'machinery', 'electronics']],
                ['Indore', 'Madhya Pradesh', 22.7196, 75.8577, 3000000, 1.02, 0.06, false, true, true, 6, ['grain', 'textiles'], ['machinery', 'fuel', 'medicine']],
                ['Lucknow', 'Uttar Pradesh', 26.8467, 80.9462, 4000000, 1.04, 0.07, false, true, true, 7, ['grain', 'furniture'], ['electronics', 'fuel', 'medicine']],
            ]],
            'US' => ['name' => 'United States', 'cities' => [
                ['New York', 'New York', 40.7128, -74.0060, 8300000, 0.95, 0.09, true, true, true, 1, ['electronics', 'luxury_goods', 'medicine'], ['grain', 'fuel', 'automobiles']],
                ['Los Angeles', 'California', 34.0522, -118.2437, 3900000, 1.05, 0.10, true, true, true, 1, ['automobiles', 'electronics', 'solar_panels'], ['steel_coil', 'grain', 'fuel']],
                ['Chicago', 'Illinois', 41.8781, -87.6298, 2700000, 0.92, 0.08, false, true, true, 1, ['machinery', 'steel_coil'], ['grain', 'electronics', 'coffee']],
                ['Houston', 'Texas', 29.7604, -95.3698, 2300000, 0.78, 0.06, true, true, true, 2, ['crude_oil', 'fuel', 'chemicals'], ['steel_coil', 'grain', 'electronics']],
                ['Dallas', 'Texas', 32.7767, -96.7970, 1300000, 0.80, 0.06, false, true, true, 2, ['electronics', 'machinery'], ['grain', 'fuel', 'automobiles']],
                ['Atlanta', 'Georgia', 33.7490, -84.3880, 500000, 0.88, 0.07, false, true, true, 3, ['automobiles', 'textiles'], ['electronics', 'medicine', 'coffee']],
                ['Seattle', 'Washington', 47.6062, -122.3321, 750000, 1.02, 0.09, true, true, true, 3, ['machinery', 'electronics', 'coffee'], ['grain', 'fuel', 'frozen_goods']],
                ['Miami', 'Florida', 25.7617, -80.1918, 470000, 0.94, 0.07, true, true, false, 4, ['luxury_goods', 'automobiles'], ['grain', 'fuel', 'steel_coil']],
                ['Denver', 'Colorado', 39.7392, -104.9903, 715000, 0.90, 0.08, false, true, true, 5, ['machinery', 'grain'], ['electronics', 'fuel', 'medicine']],
                ['Detroit', 'Michigan', 42.3314, -83.0458, 630000, 0.86, 0.07, false, true, true, 6, ['automobiles', 'steel_coil', 'machinery'], ['electronics', 'grain', 'fuel']],
            ]],
            'GB' => ['name' => 'United Kingdom', 'cities' => [
                ['London', 'England', 51.5074, -0.1278, 9000000, 1.70, 0.12, true, true, true, 1, ['electronics', 'luxury_goods', 'medicine'], ['grain', 'fuel', 'automobiles']],
                ['Birmingham', 'England', 52.4862, -1.8904, 1150000, 1.62, 0.11, false, true, true, 1, ['automobiles', 'machinery'], ['electronics', 'grain', 'coffee']],
                ['Manchester', 'England', 53.4808, -2.2426, 550000, 1.60, 0.10, false, true, true, 1, ['textiles', 'machinery'], ['electronics', 'grain', 'fuel']],
                ['Southampton', 'England', 50.9097, -1.4044, 250000, 1.66, 0.11, true, false, true, 2, ['automobiles', 'fuel', 'crude_oil'], ['steel_coil', 'grain', 'electronics']],
                ['Leeds', 'England', 53.8008, -1.5491, 790000, 1.58, 0.10, false, false, true, 3, ['textiles', 'furniture'], ['electronics', 'fuel', 'medicine']],
                ['Glasgow', 'Scotland', 55.8642, -4.2518, 630000, 1.64, 0.11, true, true, true, 4, ['steel_coil', 'machinery'], ['grain', 'electronics', 'coffee']],
                ['Liverpool', 'England', 53.4084, -2.9916, 500000, 1.60, 0.10, true, false, true, 5, ['machinery', 'timber'], ['grain', 'fuel', 'frozen_goods']],
                ['Bristol', 'England', 51.4545, -2.5879, 470000, 1.62, 0.10, true, true, true, 6, ['electronics', 'solar_panels'], ['grain', 'fuel', 'automobiles']],
            ]],
            'AE' => ['name' => 'United Arab Emirates', 'cities' => [
                ['Dubai', 'Dubai', 25.2048, 55.2708, 3500000, 0.62, 0.03, true, true, true, 1, ['luxury_goods', 'electronics', 'fuel'], ['grain', 'automobiles', 'frozen_goods']],
                ['Abu Dhabi', 'Abu Dhabi', 24.4539, 54.3773, 1500000, 0.58, 0.03, true, true, true, 1, ['crude_oil', 'fuel', 'chemicals'], ['grain', 'electronics', 'automobiles']],
                ['Sharjah', 'Sharjah', 25.3463, 55.4209, 1800000, 0.60, 0.03, true, true, false, 2, ['machinery', 'textiles'], ['grain', 'electronics', 'fuel']],
                ['Al Ain', 'Abu Dhabi', 24.1917, 55.7605, 770000, 0.60, 0.03, false, true, false, 3, ['grain', 'furniture'], ['electronics', 'fuel', 'medicine']],
                ['Fujairah', 'Fujairah', 25.1288, 56.3265, 250000, 0.60, 0.03, true, false, false, 4, ['crude_oil', 'fuel'], ['grain', 'machinery', 'electronics']],
                ['Ras Al Khaimah', 'Ras Al Khaimah', 25.7895, 55.9432, 350000, 0.60, 0.03, true, true, false, 5, ['cement', 'steel_coil'], ['grain', 'electronics', 'fuel']],
            ]],
        ];
    }
}
