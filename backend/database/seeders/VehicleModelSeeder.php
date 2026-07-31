<?php

namespace Database\Seeders;

use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * The Transoria dealership catalog — entirely original fictional brands
 * (Dart, Aurox, Voltra, Kestrel, Nimbus). Prices in ₡ cents.
 */
class VehicleModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // key, name, brand, class, price(cents), cap_weight_t, cap_vol_m3, top_speed, fuel_cap_l, econ_l_per_km, powertrain, reefer, tanker, hazmat, reliability, slots, unlock
            ['dart-lc1', 'Dart LC1 Courier', 'Dart', 'pickup', 45_000_00, 1.2, 6.0, 140, 70, 0.11, 'diesel', false, false, false, 0.985, 1, 1],
            ['dart-mv3', 'Dart MV3 Vanhaul', 'Dart', 'mini', 88_000_00, 3.5, 16.0, 130, 110, 0.16, 'diesel', false, false, false, 0.980, 2, 1],
            ['kestrel-t20', 'Kestrel T20 Regional', 'Kestrel', 'medium', 175_000_00, 9.0, 42.0, 118, 240, 0.24, 'diesel', false, false, false, 0.975, 2, 2],
            ['aurox-h90', 'Aurox H90 Heavy Hauler', 'Aurox', 'heavy', 340_000_00, 24.0, 78.0, 105, 520, 0.34, 'diesel', false, false, false, 0.970, 3, 3],
            ['aurox-cx', 'Aurox CX Container', 'Aurox', 'container', 410_000_00, 26.0, 92.0, 100, 560, 0.36, 'diesel', false, false, false, 0.968, 3, 4],
            ['kestrel-cryo', 'Kestrel Cryo Reefer', 'Kestrel', 'reefer', 465_000_00, 20.0, 70.0, 100, 540, 0.40, 'diesel', true, false, false, 0.965, 3, 4],
            ['voltra-tank', 'Voltra Tanker TX', 'Voltra', 'tanker', 520_000_00, 28.0, 34.0, 95, 600, 0.42, 'diesel', false, true, true, 0.960, 2, 5],
            ['aurox-carrier', 'Aurox AutoCarrier', 'Aurox', 'carrier', 560_000_00, 18.0, 120.0, 98, 560, 0.38, 'diesel', false, false, false, 0.962, 3, 6],
            ['volt-e40', 'Voltra E40 Electric', 'Voltra', 'electric', 640_000_00, 16.0, 60.0, 110, 0, 0.00, 'electric', false, false, false, 0.978, 4, 5],
            ['nimbus-hx', 'Nimbus HX Hydrogen', 'Nimbus', 'hydrogen', 820_000_00, 22.0, 74.0, 112, 60, 0.09, 'hydrogen', false, false, false, 0.982, 4, 7],
            ['nimbus-auto', 'Nimbus AutoPilot A1', 'Nimbus', 'autonomous', 1_180_000_00, 24.0, 80.0, 108, 60, 0.08, 'hydrogen', false, false, false, 0.990, 5, 8],
            ['volt-e40r', 'Voltra E40 Reefer', 'Voltra', 'electric', 720_000_00, 15.0, 56.0, 108, 0, 0.00, 'electric', true, false, false, 0.976, 4, 6],
        ];

        foreach ($models as $m) {
            [$key, $name, $brand, $class, $price, $cw, $cv, $ts, $fc, $econ, $pt, $reefer, $tanker, $hazmat, $rel, $slots, $unlock] = $m;

            VehicleModel::updateOrCreate(['key' => $key], [
                'name' => $name,
                'brand' => $brand,
                'class' => $class,
                'price' => $price,
                'capacity_weight' => $cw,
                'capacity_volume' => $cv,
                'top_speed' => $ts,
                'fuel_capacity' => $fc,
                'fuel_economy' => $econ,
                'powertrain' => $pt,
                'can_reefer' => $reefer,
                'can_tanker' => $tanker,
                'can_hazmat' => $hazmat,
                'reliability' => $rel,
                'upgrade_slots' => $slots,
                'unlock_level' => $unlock,
            ]);
        }
    }
}
