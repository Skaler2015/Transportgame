<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VehicleModel is a purchasable blueprint in the dealership catalog
 * (e.g. "Aurox H90 Heavy Hauler"). Player-owned Vehicles are instances of
 * a model. All model names are ORIGINAL, fictional brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('brand');
            $table->string('class'); // pickup|mini|medium|heavy|container|reefer|tanker|carrier|electric|hydrogen|autonomous

            $table->bigInteger('price');              // ₡ cents to purchase
            $table->decimal('capacity_weight', 8, 2); // tonnes
            $table->decimal('capacity_volume', 8, 2); // m^3
            $table->unsignedSmallInteger('top_speed'); // km/h
            $table->decimal('fuel_capacity', 8, 2);    // litres
            $table->decimal('fuel_economy', 6, 3);     // litres per km at full load
            $table->string('powertrain')->default('diesel'); // diesel|electric|hydrogen

            // Capability flags — must match commodity handling requirements.
            $table->boolean('can_reefer')->default(false);
            $table->boolean('can_tanker')->default(false);
            $table->boolean('can_hazmat')->default(false);

            $table->decimal('reliability', 5, 3)->default(0.980); // 0..1 breakdown resistance
            $table->unsignedTinyInteger('upgrade_slots')->default(2);
            $table->unsignedTinyInteger('unlock_level')->default(1);

            $table->timestamps();

            $table->index('class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
