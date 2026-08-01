<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse operations: workers & forklifts (staff_level) and security/CCTV
 * (security_level). Both raise effective throughput/capacity; cold storage,
 * hazmat bay and automation already exist as flags and become upgradeable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedTinyInteger('staff_level')->default(0)->after('automated');
            $table->unsignedTinyInteger('security_level')->default(0)->after('staff_level');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['staff_level', 'security_level']);
        });
    }
};
