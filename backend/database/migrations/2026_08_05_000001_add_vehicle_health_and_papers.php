<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deeper vehicle upkeep: engine oil and battery health that degrade with use,
 * plus insurance and registration papers that expire and must be renewed.
 * Existing vehicles are backfilled to healthy + valid in worldsync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('oil_level', 5, 2)->default(100)->after('tire_wear'); // 0..100
            $table->decimal('battery', 5, 2)->default(100)->after('oil_level');    // 0..100
            $table->timestamp('insured_until')->nullable()->after('battery');
            $table->timestamp('registered_until')->nullable()->after('insured_until');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['oil_level', 'battery', 'insured_until', 'registered_until']);
        });
    }
};
