<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-city economic profile for a commodity: how much it produces vs.
 * consumes, and the live stock level. Producers push local price down,
 * consumers pull it up; the delta between two cities is where profit lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_commodity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commodity_id')->constrained()->cascadeOnDelete();

            $table->integer('production')->default(0);  // units produced per tick
            $table->integer('consumption')->default(0); // units consumed per tick
            $table->integer('stock')->default(0);       // current inventory
            $table->integer('stock_cap')->default(100000);

            $table->timestamps();

            $table->unique(['city_id', 'commodity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_commodity');
    }
};
