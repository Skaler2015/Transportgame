<?php

namespace Database\Seeders;

use App\Models\ListedCompany;
use Illuminate\Database\Seeder;

/**
 * The exchange's listed firms — fictional blue-chips across the logistics
 * economy. Prices in ₡ per share. Idempotent (keeps the live price on re-run).
 */
class ListedCompanySeeder extends Seeder
{
    public function run(): void
    {
        $firms = [
            // key, name, sector, base_price, dividend_yield, volatility
            ['trn', 'Transnational Haulage', 'Road Freight', 240.00, 0.010, 0.035],
            ['blr', 'Bharat Logistics', 'Road Freight', 120.00, 0.012, 0.040],
            ['oce', 'Oceanic Freight Lines', 'Shipping', 480.00, 0.008, 0.050],
            ['sky', 'SkyCargo Air', 'Air Cargo', 650.00, 0.006, 0.060],
            ['rlx', 'RailEx Corp', 'Rail', 300.00, 0.011, 0.030],
            ['ful', 'FuelCorp Energy', 'Energy', 90.00, 0.020, 0.045],
            ['wre', 'WareGrid Storage', 'Warehousing', 160.00, 0.014, 0.028],
            ['aut', 'AutoParts International', 'Industrial', 210.00, 0.009, 0.038],
        ];

        foreach ($firms as [$key, $name, $sector, $base, $yield, $vol]) {
            $existing = ListedCompany::where('key', $key)->first();
            ListedCompany::updateOrCreate(['key' => $key], [
                'name' => $name,
                'sector' => $sector,
                'base_price' => $base,
                // Seed the live price at base only on first insert.
                'share_price' => $existing?->share_price ?? $base,
                'dividend_yield' => $yield,
                'volatility' => $vol,
            ]);
        }
    }
}
