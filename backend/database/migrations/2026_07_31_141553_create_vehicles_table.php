<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Vehicle is a player-owned instance of a VehicleModel. It has wear,
 * fuel, a home city, and a status in the operational state machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained();
            $table->unsignedBigInteger('city_id');       // current location
            $table->string('nickname')->nullable();
            $table->string('livery_color', 9)->default('#f8fafc');

            // idle | assigned | en_route | maintenance
            $table->string('status')->default('idle');

            $table->decimal('condition', 5, 2)->default(100.00);   // 0..100 health
            $table->decimal('tire_wear', 5, 2)->default(0.00);     // 0..100
            $table->decimal('fuel', 8, 2)->default(0);             // litres in tank
            $table->unsignedBigInteger('odometer')->default(0);    // km driven

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
