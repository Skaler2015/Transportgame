<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Guild (alliance) groups companies together for shared identity, a common
 * treasury and a combined leaderboard ranking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tag', 6)->unique();      // short [ABC] style tag
            $table->string('slug')->unique();
            $table->foreignId('owner_company_id')->constrained('companies');
            $table->text('description')->nullable();
            $table->string('emblem_color', 9)->default('#38bdf8');

            $table->bigInteger('treasury')->default(0);       // ₡ cents pooled
            $table->unsignedInteger('member_count')->default(1);
            $table->bigInteger('total_reputation')->default(0);

            $table->timestamps();

            $table->index('total_reputation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guilds');
    }
};
