<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Per-company sequential crew number (1, 2, 3 …), shown as #0001, so
            // drivers are individually identifiable.
            $table->unsignedInteger('crew_no')->nullable()->after('id');
        });

        foreach (DB::table('drivers')->select('company_id')->distinct()->pluck('company_id') as $companyId) {
            $n = 0;
            foreach (DB::table('drivers')->where('company_id', $companyId)->orderBy('id')->pluck('id') as $id) {
                DB::table('drivers')->where('id', $id)->update(['crew_no' => ++$n]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('crew_no');
        });
    }
};
