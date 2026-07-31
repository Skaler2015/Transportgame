<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a company to a guild and record its contribution, plus per-cycle
 * mission bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('guild_id')->nullable()->after('headquarters_city_id')
                ->constrained('guilds')->nullOnDelete();
            $table->string('guild_role')->nullable()->after('guild_id'); // owner|officer|member
            $table->bigInteger('guild_contribution')->default(0)->after('guild_role');
            $table->timestamp('missions_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guild_id');
            $table->dropColumn(['guild_role', 'guild_contribution', 'missions_generated_at']);
        });
    }
};
