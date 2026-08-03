<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Per-company sequential fleet number (1, 2, 3 …) so identical models
            // are still individually identifiable — shown as #0001.
            $table->unsignedInteger('fleet_no')->nullable()->after('id');
        });

        // Backfill: number each company's existing vehicles in purchase order.
        foreach (DB::table('vehicles')->select('company_id')->distinct()->pluck('company_id') as $companyId) {
            $n = 0;
            foreach (DB::table('vehicles')->where('company_id', $companyId)->orderBy('id')->pluck('id') as $id) {
                DB::table('vehicles')->where('id', $id)->update(['fleet_no' => ++$n]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('fleet_no');
        });
    }
};
