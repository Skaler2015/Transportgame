<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cities are the nodes of the Transoria world graph. Each has a real
 * geographic position, a living economy (population, growth, fuel price,
 * tax, traffic, weather) and a set of commodities it produces/consumes.
 *
 * NOTE: The city roster is an ORIGINAL fictional world ("the Transoria
 * belt"), not a copy of any real-world dataset or existing game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('region');            // fictional macro-region
            $table->string('country_code', 3);   // fictional 3-letter code
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);

            $table->unsignedBigInteger('population');
            $table->decimal('economic_growth', 5, 3)->default(1.000); // multiplier
            $table->unsignedTinyInteger('traffic')->default(30);      // 0..100 congestion
            $table->decimal('tax_rate', 5, 4)->default(0.0800);       // fraction of revenue
            $table->decimal('fuel_price', 8, 2)->default(1.20);       // ₡ per litre

            $table->string('weather')->default('clear'); // clear|rain|snow|storm|fog|heat|flood
            $table->unsignedTinyInteger('unlock_level')->default(1);
            $table->boolean('has_port')->default(false);
            $table->boolean('has_airport')->default(false);
            $table->boolean('has_rail')->default(false);

            $table->timestamps();

            $table->index('region');
            $table->index('unlock_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
