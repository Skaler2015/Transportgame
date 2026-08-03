<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the persistent Transoria world. Player companies are created at
     * registration time, not here — this only lays down the shared world:
     * commodities, cities, the dealership catalog, and an initial market
     * snapshot + contract batch so a fresh player has something to do.
     */
    public function run(): void
    {
        $this->call([
            CommoditySeeder::class,
            CitySeeder::class,
            VehicleModelSeeder::class,
        ]);
    }
}
