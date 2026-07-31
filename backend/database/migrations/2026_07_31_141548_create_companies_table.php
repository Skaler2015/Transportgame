<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Company is the player's persistent economic entity in Transoria.
 * One user owns exactly one company; a company owns fleet, drivers,
 * warehouses and accumulates reputation & capital over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_color', 9)->default('#38bdf8');
            $table->string('motto')->nullable();

            // Home base (kept as a loose reference to avoid migration ordering coupling).
            $table->unsignedBigInteger('headquarters_city_id')->nullable();

            // Finances stored as integer cents of the in-game currency "Credits" (₡).
            $table->bigInteger('cash')->default(250000_00);
            $table->bigInteger('debt')->default(0);

            // Progression.
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('xp')->default(0);
            $table->unsignedInteger('reputation')->default(500); // 0..1000
            $table->unsignedInteger('research_points')->default(0);

            // Lifetime stats for leaderboards.
            $table->unsignedBigInteger('shipments_completed')->default(0);
            $table->unsignedBigInteger('shipments_failed')->default(0);
            $table->bigInteger('lifetime_revenue')->default(0);
            $table->bigInteger('lifetime_expenses')->default(0);

            $table->timestamp('last_tick_at')->nullable();
            $table->timestamps();

            $table->index('cash');
            $table->index('reputation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
