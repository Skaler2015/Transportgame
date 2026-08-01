<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver careers: age, experience (→ rank), health, a driving licence that
 * expires, and two specialities — wet-weather driving and fuel economy — that
 * feed shipment outcomes. Existing crews are backfilled in worldsync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->default(32)->after('name');
            $table->unsignedInteger('experience')->default(0)->after('shipments_done');
            $table->unsignedTinyInteger('health')->default(100)->after('fatigue');
            $table->unsignedTinyInteger('rain_skill')->default(30)->after('skill');
            $table->unsignedTinyInteger('eco_skill')->default(30)->after('rain_skill');
            $table->timestamp('licence_until')->nullable()->after('hazmat_licence');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['age', 'experience', 'health', 'rain_skill', 'eco_skill', 'licence_until']);
        });
    }
};
