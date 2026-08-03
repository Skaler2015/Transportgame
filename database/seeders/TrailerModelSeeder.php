<?php

namespace Database\Seeders;

use App\Models\TrailerModel;
use Illuminate\Database\Seeder;

/**
 * The trailer catalogue. A road tractor must pull a trailer whose type suits
 * the cargo: a dry box for general freight, a reefer for perishables, a tanker
 * for liquids/hazmat, a flatbed for heavy general loads, a container box, or a
 * car-carrier. Prices in ₡ cents.
 */
class TrailerModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // key, name, type, price(cents), cap_weight_t, cap_vol_m3, reefer, tanker, hazmat, unlock
            ['box-std', 'Standard Dry Box', 'box', 60_000_00, 12.0, 60.0, false, false, false, 1],
            ['box-max', 'Maxvan Dry Box', 'box', 95_000_00, 26.0, 95.0, false, false, false, 3],
            ['flatbed-std', 'Flatdeck Flatbed', 'flatbed', 110_000_00, 27.0, 45.0, false, false, false, 3],
            ['reefer-std', 'ColdChain Reefer', 'reefer', 135_000_00, 22.0, 70.0, true, false, false, 3],
            ['container-std', 'ConLink Container', 'container', 145_000_00, 28.0, 92.0, false, false, false, 4],
            ['tanker-std', 'FluidLine Tanker', 'tanker', 155_000_00, 30.0, 36.0, false, true, true, 4],
            ['carcarrier-std', 'AutoStack Carrier', 'car_carrier', 165_000_00, 18.0, 125.0, false, false, false, 5],
        ];

        foreach ($models as $m) {
            [$key, $name, $type, $price, $cw, $cv, $reefer, $tanker, $hazmat, $unlock] = $m;

            TrailerModel::updateOrCreate(['key' => $key], [
                'name' => $name,
                'type' => $type,
                'price' => $price,
                'capacity_weight' => $cw,
                'capacity_volume' => $cv,
                'can_reefer' => $reefer,
                'can_tanker' => $tanker,
                'can_hazmat' => $hazmat,
                'unlock_level' => $unlock,
            ]);
        }
    }
}
