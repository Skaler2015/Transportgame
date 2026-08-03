<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Commodity is a class of goods that can be produced, consumed and
 * hauled. Each defines physical/logistical properties that gate which
 * vehicles can carry it and how risky/valuable the haul is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commodities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();     // machine key e.g. "frozen_produce"
            $table->string('name');
            $table->string('category');          // food|tech|industrial|hazmat|luxury|raw|livestock
            $table->string('icon')->default('package');

            $table->decimal('base_price', 12, 2);    // ₡ per unit at equilibrium
            $table->decimal('weight_per_unit', 8, 3); // tonnes per unit
            $table->decimal('volume_per_unit', 8, 3); // m^3 per unit

            // Special handling flags. A vehicle must satisfy these to carry the cargo.
            $table->boolean('requires_reefer')->default(false);  // temperature controlled
            $table->boolean('requires_tanker')->default(false);  // liquid/gas
            $table->boolean('is_hazardous')->default(false);     // dangerous goods licence
            $table->boolean('is_perishable')->default(false);    // decays if late

            $table->unsignedTinyInteger('risk')->default(10);        // 0..100 spoilage/theft
            $table->unsignedTinyInteger('volatility')->default(15);  // 0..100 price swing
            $table->unsignedTinyInteger('unlock_level')->default(1);

            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commodities');
    }
};
