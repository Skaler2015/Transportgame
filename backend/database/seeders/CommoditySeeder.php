<?php

namespace Database\Seeders;

use App\Models\Commodity;
use Illuminate\Database\Seeder;

/**
 * The Transoria commodity catalog. Categories gate which vehicles can haul
 * what, and drive the arbitrage economy. Prices are in ₡ per unit (cents).
 */
class CommoditySeeder extends Seeder
{
    public function run(): void
    {
        $commodities = [
            // key, name, category, icon, base_price(cents), weight_t, volume_m3, flags..., risk, volatility, unlock
            ['grain', 'Grain', 'food', 'wheat', 42_00, 1.0, 1.30, [], 8, 20, 1],
            ['fresh_produce', 'Fresh Produce', 'food', 'apple', 96_00, 0.9, 1.10, ['perishable' => true], 35, 30, 2],
            ['frozen_goods', 'Frozen Goods', 'food', 'snowflake', 168_00, 1.0, 1.00, ['reefer' => true, 'perishable' => true], 28, 22, 4],
            ['bottled_water', 'Bottled Water', 'food', 'droplet', 30_00, 1.1, 1.00, [], 5, 12, 1],
            ['textiles', 'Textiles', 'industrial', 'shirt', 120_00, 0.5, 1.60, [], 10, 18, 1],
            ['furniture', 'Furniture', 'industrial', 'sofa', 240_00, 0.7, 2.40, [], 14, 16, 2],
            ['steel_coil', 'Steel Coil', 'raw', 'bars', 310_00, 4.0, 0.90, [], 12, 24, 3],
            ['timber', 'Timber', 'raw', 'tree', 88_00, 2.6, 2.20, [], 9, 20, 2],
            ['cement', 'Cement', 'raw', 'bricks', 64_00, 3.2, 0.80, [], 7, 15, 2],
            ['electronics', 'Electronics', 'tech', 'chip', 540_00, 0.3, 1.10, ['fragile' => true], 30, 36, 3],
            ['machinery', 'Machinery', 'tech', 'cog', 720_00, 3.5, 1.80, [], 18, 22, 4],
            ['solar_panels', 'Solar Panels', 'tech', 'sun', 480_00, 0.9, 1.90, ['fragile' => true], 24, 28, 5],
            ['medicine', 'Medicine', 'tech', 'pill', 900_00, 0.2, 0.60, ['reefer' => true, 'perishable' => true], 40, 34, 4],
            ['crude_oil', 'Crude Oil', 'hazmat', 'oil', 210_00, 0.9, 1.00, ['tanker' => true, 'hazardous' => true], 45, 40, 5],
            ['fuel', 'Refined Fuel', 'hazmat', 'flame', 340_00, 0.8, 1.00, ['tanker' => true, 'hazardous' => true], 50, 38, 5],
            ['chemicals', 'Industrial Chemicals', 'hazmat', 'flask', 460_00, 1.0, 1.10, ['tanker' => true, 'hazardous' => true], 55, 42, 6],
            ['livestock', 'Livestock', 'livestock', 'cow', 380_00, 0.5, 2.60, ['perishable' => true], 42, 30, 4],
            ['automobiles', 'Automobiles', 'luxury', 'car', 1_600_00, 1.6, 6.00, ['fragile' => true], 22, 26, 6],
            ['luxury_goods', 'Luxury Goods', 'luxury', 'gem', 2_400_00, 0.2, 0.80, ['fragile' => true], 48, 44, 7],
            ['coffee', 'Coffee', 'food', 'coffee', 260_00, 0.6, 1.20, [], 16, 32, 3],
        ];

        foreach ($commodities as $c) {
            [$key, $name, $category, $icon, $price, $weight, $volume, $flags, $risk, $vol, $unlock] = $c;

            Commodity::updateOrCreate(['key' => $key], [
                'name' => $name,
                'category' => $category,
                'icon' => $icon,
                'base_price' => $price / 100,
                'weight_per_unit' => $weight,
                'volume_per_unit' => $volume,
                'requires_reefer' => $flags['reefer'] ?? false,
                'requires_tanker' => $flags['tanker'] ?? false,
                'is_hazardous' => $flags['hazardous'] ?? false,
                'is_perishable' => $flags['perishable'] ?? false,
                'risk' => $risk,
                'volatility' => $vol,
                'unlock_level' => $unlock,
            ]);
        }
    }
}
