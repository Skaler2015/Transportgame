<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trailer catalogue. A trailer's TYPE decides which cargo it can legally
 * carry (dry box, refrigerated, tanker, flatbed, container, car-carrier), and
 * its capacity governs how much a tractor pulling it can haul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trailer_models', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type', 16);                 // box|reefer|tanker|flatbed|container|car_carrier
            $table->unsignedBigInteger('price');        // cents
            $table->decimal('capacity_weight', 8, 2);   // tonnes
            $table->decimal('capacity_volume', 8, 2);   // m³
            $table->boolean('can_reefer')->default(false);
            $table->boolean('can_tanker')->default(false);
            $table->boolean('can_hazmat')->default(false);
            $table->unsignedTinyInteger('unlock_level')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trailer_models');
    }
};
