<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding + tutorial state. `onboarded_at` marks a company that has finished
 * (or skipped) the welcome tutorial; `tutorial_step` tracks progress through it.
 * Existing companies are treated as already onboarded via a backfill in worldsync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('missions_generated_at');
            $table->unsignedTinyInteger('tutorial_step')->default(0)->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['onboarded_at', 'tutorial_step']);
        });
    }
};
