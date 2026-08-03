<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-vehicle upgrade levels. Each tier improves an aspect of performance and
 * consumes one of the model's upgrade slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('engine_level')->default(0)->after('odometer');  // +speed, -fuel
            $table->unsignedTinyInteger('tires_level')->default(0)->after('engine_level'); // -tire wear
            $table->unsignedTinyInteger('trailer_level')->default(0)->after('tires_level'); // +capacity
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['engine_level', 'tires_level', 'trailer_level']);
        });
    }
};
