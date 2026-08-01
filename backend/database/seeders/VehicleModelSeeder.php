<?php

namespace Database\Seeders;

use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * The Transoria dealership catalog — entirely original fictional brands
 * (Dart, Aurox, Voltra, Kestrel, Nimbus, Ironline, Meridian, Skyhaul). Prices
 * in ₡ cents.
 *
 * `mode` places each craft on road / rail / sea / air. `needs_trailer` marks a
 * road tractor that must pull a matching trailer to haul — rigid vans, the
 * specialised reefer/tanker rigs and every rail/sea/air craft are
 * self-contained (they carry cargo directly and keep their own capability
 * flags).
 */
class VehicleModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // key, name, brand, class, mode, needs_trailer, price(cents), cap_weight_t, cap_vol_m3, top_speed, fuel_cap_l, econ_l_per_km, powertrain, reefer, tanker, hazmat, reliability, slots, unlock
            ['dart-lc1', 'Dart LC1 Courier', 'Dart', 'pickup', 'road', false, 45_000_00, 1.2, 6.0, 140, 300, 0.11, 'diesel', false, false, false, 0.985, 1, 1],
            ['dart-mv3', 'Dart MV3 Vanhaul', 'Dart', 'mini', 'road', false, 88_000_00, 3.5, 16.0, 130, 440, 0.16, 'diesel', false, false, false, 0.980, 2, 1],
            ['kestrel-t20', 'Kestrel T20 Tractor', 'Kestrel', 'medium', 'road', true, 175_000_00, 9.0, 42.0, 118, 640, 0.24, 'diesel', false, false, false, 0.975, 2, 2],
            ['aurox-h90', 'Aurox H90 Heavy Tractor', 'Aurox', 'heavy', 'road', true, 340_000_00, 24.0, 78.0, 105, 920, 0.34, 'diesel', false, false, false, 0.970, 3, 3],
            ['aurox-cx', 'Aurox CX Container Tractor', 'Aurox', 'container', 'road', true, 410_000_00, 26.0, 92.0, 100, 980, 0.36, 'diesel', false, false, false, 0.968, 3, 4],
            ['kestrel-cryo', 'Kestrel Cryo Reefer', 'Kestrel', 'reefer', 'road', false, 465_000_00, 20.0, 70.0, 100, 1000, 0.40, 'diesel', true, false, false, 0.965, 3, 4],
            ['voltra-tank', 'Voltra Tanker TX', 'Voltra', 'tanker', 'road', false, 520_000_00, 28.0, 34.0, 95, 1100, 0.42, 'diesel', false, true, true, 0.960, 2, 5],
            ['aurox-carrier', 'Aurox AutoTractor', 'Aurox', 'carrier', 'road', true, 560_000_00, 18.0, 120.0, 98, 1000, 0.38, 'diesel', false, false, false, 0.962, 3, 6],
            ['volt-e40', 'Voltra E40 Electric Tractor', 'Voltra', 'electric', 'road', true, 640_000_00, 16.0, 60.0, 110, 0, 0.00, 'electric', false, false, false, 0.978, 4, 5],
            ['nimbus-hx', 'Nimbus HX Hydrogen Tractor', 'Nimbus', 'hydrogen', 'road', true, 820_000_00, 22.0, 74.0, 112, 420, 0.09, 'hydrogen', false, false, false, 0.982, 4, 7],
            ['nimbus-auto', 'Nimbus AutoPilot A1', 'Nimbus', 'autonomous', 'road', true, 1_180_000_00, 24.0, 80.0, 108, 420, 0.08, 'hydrogen', false, false, false, 0.990, 5, 8],
            ['volt-e40r', 'Voltra E40 Reefer', 'Voltra', 'electric', 'road', false, 720_000_00, 15.0, 56.0, 108, 0, 0.00, 'electric', true, false, false, 0.976, 4, 6],

            // --- Rail / Sea / Air: self-contained, high-capacity end-game craft ---
            ['ironline-fr1', 'Ironline FR1 Freight Train', 'Ironline', 'freight_train', 'rail', false, 2_400_000_00, 320.0, 1400.0, 90, 4000, 1.60, 'diesel', true, true, true, 0.985, 2, 6],
            ['meridian-cs1', 'Meridian CS1 Cargo Ship', 'Meridian', 'cargo_ship', 'sea', false, 5_800_000_00, 900.0, 6000.0, 45, 20000, 3.50, 'diesel', true, true, true, 0.990, 2, 9],
            ['skyhaul-af1', 'Skyhaul AF1 Air Freighter', 'Skyhaul', 'air_freighter', 'air', false, 9_500_000_00, 90.0, 900.0, 820, 60000, 8.00, 'jet', true, false, false, 0.992, 1, 12],
        ];

        foreach ($models as $m) {
            [$key, $name, $brand, $class, $mode, $needsTrailer, $price, $cw, $cv, $ts, $fc, $econ, $pt, $reefer, $tanker, $hazmat, $rel, $slots, $unlock] = $m;

            VehicleModel::updateOrCreate(['key' => $key], [
                'name' => $name,
                'brand' => $brand,
                'class' => $class,
                'mode' => $mode,
                'needs_trailer' => $needsTrailer,
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
