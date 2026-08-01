<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Cash spent auto-servicing & refuelling the truck when this run
            // ended, in integer cents — surfaced per-contract so the player sees
            // maintenance as a small line item instead of a lump sum later.
            $table->unsignedBigInteger('service_cost')->default(0)->after('projected_payout');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('service_cost');
        });
    }
};
