<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-modal fleet: every vehicle model now belongs to a transport mode
 * (road / rail / sea / air), and road tractors declare whether they need a
 * trailer to haul. Rigid vans and non-road craft are self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->string('mode', 8)->default('road')->after('class');       // road|rail|sea|air
            $table->boolean('needs_trailer')->default(false)->after('mode');  // road tractors
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->dropColumn(['mode', 'needs_trailer']);
        });
    }
};
