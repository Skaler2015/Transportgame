<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI competitors (Phase 1). A rival firm is an ordinary `companies` row flagged
 * `is_ai`, owned by one shared system user. Its fleet is a single counter
 * (`ai_fleet_size`) rather than one Vehicle row per truck, so a whole roster of
 * rivals stays cheap on shared hosting. `ai_state` carries the brain's rolling
 * bookkeeping (loss streak, momentum, founded-at game hour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'is_ai')) {
                $table->boolean('is_ai')->default(false)->index()->after('user_id');
            }
            if (! Schema::hasColumn('companies', 'ai_strategy')) {
                $table->string('ai_strategy')->nullable()->after('is_ai');
            }
            if (! Schema::hasColumn('companies', 'ai_fleet_size')) {
                $table->unsignedInteger('ai_fleet_size')->default(0)->after('ai_strategy');
            }
            if (! Schema::hasColumn('companies', 'ai_state')) {
                $table->json('ai_state')->nullable()->after('ai_fleet_size');
            }
            if (! Schema::hasColumn('companies', 'ai_bankrupt_at')) {
                $table->timestamp('ai_bankrupt_at')->nullable()->after('ai_state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['is_ai', 'ai_strategy', 'ai_fleet_size', 'ai_state', 'ai_bankrupt_at'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
