<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deeper city economies. These attributes make each city behave differently and
 * feed pricing, routing costs and risk. Values are backfilled deterministically
 * in worldsync so existing cities gain them without a reseed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->unsignedBigInteger('gdp_per_capita')->nullable()->after('population'); // ₡ proxy
            $table->unsignedTinyInteger('crime_index')->nullable()->after('gdp_per_capita'); // 0..100
            $table->unsignedTinyInteger('road_quality')->nullable()->after('crime_index');   // 0..100
            $table->decimal('toll_per_km', 6, 2)->nullable()->after('road_quality');          // ₡/km
            $table->decimal('industrial_growth', 5, 3)->nullable()->after('toll_per_km');     // -0.05..0.10
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['gdp_per_capita', 'crime_index', 'road_quality', 'toll_per_km', 'industrial_growth']);
        });
    }
};
