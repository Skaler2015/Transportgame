<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listed_companies', function (Blueprint $table) {
            // Rolling recent share prices (last ~30 ticks) for sparklines/charts.
            $table->json('price_history')->nullable()->after('share_price');
        });
    }

    public function down(): void
    {
        Schema::table('listed_companies', function (Blueprint $table) {
            $table->dropColumn('price_history');
        });
    }
};
