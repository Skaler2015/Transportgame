<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // The pre-pulse "market base" payout (cents). The live payout is this
            // scaled by the hourly market pulse, so open jobs reprice each hour.
            if (! Schema::hasColumn('contracts', 'base_payout')) {
                $table->unsignedBigInteger('base_payout')->nullable()->after('payout');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'base_payout')) {
                $table->dropColumn('base_payout');
            }
        });
    }
};
