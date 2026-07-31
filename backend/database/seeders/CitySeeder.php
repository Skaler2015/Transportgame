<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Commodity;
use Illuminate\Database\Seeder;

/**
 * The Transoria belt — an ORIGINAL fictional world of 18 cities spread across
 * five macro-regions. Coordinates render on standard map tiles but the roster,
 * names and economy are invented for this game.
 *
 * Each city declares what it PRODUCES (surplus, cheap to buy) and CONSUMES
 * (demand, sells high). The gap between a producer and a consumer is the
 * arbitrage that contracts monetise.
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            // name, region, country, lat, lng, population, fuel, tax, port, airport, rail, unlock, produces[], consumes[]
            ['Vantropol', 'Coreland', 'TRA', 34.02, 71.80, 8_400_000, 1.28, 0.09, false, true, true, 1,
                ['textiles', 'furniture', 'electronics'], ['grain', 'fresh_produce', 'fuel', 'medicine']],
            ['Cindermoor', 'Ironvale', 'IRV', 31.40, 74.60, 3_100_000, 1.10, 0.07, false, false, true, 1,
                ['steel_coil', 'cement', 'machinery'], ['grain', 'electronics', 'coffee']],
            ['Halcyon Bay', 'Marisands', 'MAR', 28.55, 77.10, 5_600_000, 1.34, 0.11, true, true, true, 1,
                ['crude_oil', 'fuel', 'automobiles'], ['steel_coil', 'timber', 'fresh_produce']],
            ['Greenhollow', 'Sunbelt', 'SUN', 26.90, 70.20, 1_450_000, 1.02, 0.06, false, false, false, 1,
                ['grain', 'fresh_produce', 'livestock'], ['fuel', 'machinery', 'textiles']],
            ['Ferrock', 'Ironvale', 'IRV', 30.10, 76.90, 2_200_000, 1.06, 0.07, false, false, true, 2,
                ['steel_coil', 'timber', 'cement'], ['electronics', 'coffee', 'medicine']],
            ['Solvane', 'Sunbelt', 'SUN', 24.70, 73.30, 1_900_000, 1.14, 0.08, false, true, false, 2,
                ['solar_panels', 'coffee', 'grain'], ['machinery', 'steel_coil', 'automobiles']],
            ['Northspire', 'Northreach', 'NOR', 37.60, 74.10, 2_800_000, 1.40, 0.10, false, true, true, 2,
                ['machinery', 'electronics', 'medicine'], ['timber', 'grain', 'fuel']],
            ['Tidewater', 'Marisands', 'MAR', 27.20, 79.40, 4_100_000, 1.30, 0.10, true, true, true, 3,
                ['automobiles', 'luxury_goods', 'fuel'], ['steel_coil', 'textiles', 'frozen_goods']],
            ['Duskford', 'Coreland', 'TRA', 33.10, 69.40, 1_700_000, 1.20, 0.08, false, false, true, 3,
                ['furniture', 'timber', 'textiles'], ['electronics', 'medicine', 'coffee']],
            ['Aurelia', 'Coreland', 'TRA', 35.30, 73.20, 6_900_000, 1.36, 0.12, false, true, true, 3,
                ['electronics', 'luxury_goods', 'medicine'], ['grain', 'fuel', 'automobiles', 'coffee']],
            ['Saltmere', 'Marisands', 'MAR', 25.80, 80.60, 2_600_000, 1.24, 0.09, true, false, true, 4,
                ['crude_oil', 'chemicals', 'bottled_water'], ['machinery', 'furniture', 'steel_coil']],
            ['Emberton', 'Ironvale', 'IRV', 29.30, 75.70, 1_350_000, 1.08, 0.06, false, false, true, 4,
                ['cement', 'steel_coil', 'chemicals'], ['fresh_produce', 'coffee', 'electronics']],
            ['Meadowgate', 'Sunbelt', 'SUN', 23.40, 71.60, 980_000, 0.98, 0.05, false, false, false, 4,
                ['grain', 'livestock', 'fresh_produce'], ['fuel', 'machinery', 'medicine']],
            ['Frosthaven', 'Northreach', 'NOR', 39.20, 76.80, 1_600_000, 1.46, 0.11, false, true, true, 5,
                ['frozen_goods', 'machinery', 'timber'], ['grain', 'fuel', 'textiles', 'automobiles']],
            ['Portazure', 'Marisands', 'MAR', 26.10, 82.30, 3_800_000, 1.32, 0.10, true, true, true, 5,
                ['automobiles', 'electronics', 'luxury_goods'], ['crude_oil', 'steel_coil', 'frozen_goods']],
            ['Quarrydale', 'Ironvale', 'IRV', 32.60, 77.90, 1_200_000, 1.12, 0.07, false, false, true, 6,
                ['steel_coil', 'cement', 'machinery'], ['fresh_produce', 'medicine', 'luxury_goods']],
            ['Sunreach', 'Sunbelt', 'SUN', 22.10, 74.90, 2_050_000, 1.16, 0.08, false, true, false, 6,
                ['solar_panels', 'coffee', 'luxury_goods'], ['steel_coil', 'machinery', 'frozen_goods']],
            ['Glaciera', 'Northreach', 'NOR', 40.80, 72.30, 1_100_000, 1.52, 0.12, false, true, true, 7,
                ['frozen_goods', 'medicine', 'luxury_goods'], ['grain', 'fuel', 'furniture', 'automobiles']],
        ];

        $commodityIds = Commodity::pluck('id', 'key');

        foreach ($cities as $c) {
            [$name, $region, $country, $lat, $lng, $pop, $fuel, $tax, $port, $air, $rail, $unlock, $produces, $consumes] = $c;

            $city = City::updateOrCreate(['name' => $name], [
                'region' => $region,
                'country_code' => $country,
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
            ]);

            $sync = [];

            // Producers: high production, modest local consumption, large stock.
            foreach ($produces as $key) {
                if (! isset($commodityIds[$key])) {
                    continue;
                }
                $scale = max(1, (int) ($pop / 500_000));
                $sync[$commodityIds[$key]] = [
                    'production' => random_int(60, 140) * $scale,
                    'consumption' => random_int(10, 30) * $scale,
                    'stock' => random_int(4000, 12000),
                    'stock_cap' => 40000,
                ];
            }

            // Consumers: high consumption, little/no production, low stock (shortage).
            foreach ($consumes as $key) {
                if (! isset($commodityIds[$key]) || isset($sync[$commodityIds[$key]])) {
                    continue;
                }
                $scale = max(1, (int) ($pop / 500_000));
                $sync[$commodityIds[$key]] = [
                    'production' => 0,
                    'consumption' => random_int(50, 120) * $scale,
                    'stock' => random_int(200, 1500),
                    'stock_cap' => 30000,
                ];
            }

            $city->commodities()->sync($sync);
        }
    }
}
