<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rolling record of a commodity's local price in a city, written each
 * economy tick. Powers price charts and the arbitrage UI. Older rows are
 * pruned by the tick command to keep the series bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('commodity_id');
            $table->decimal('price', 12, 2);       // local ₡ per unit this tick
            $table->decimal('demand_index', 6, 3); // >1 shortage, <1 surplus
            $table->timestamp('recorded_at');

            $table->index(['city_id', 'commodity_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_prices');
    }
};
