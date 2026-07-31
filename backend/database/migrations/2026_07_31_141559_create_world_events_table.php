<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WorldEvent is a time-boxed macro modifier — a fuel crisis, a festival
 * surge, a regional storm, a border strike — that reshapes prices, demand,
 * fuel and risk while active. Events drive the "living world" narrative feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');   // fuel_crisis|festival|storm|boom|embargo|pandemic|strike
            $table->string('title');
            $table->text('description');
            $table->string('severity')->default('minor'); // minor|major|critical

            // Optional scoping. Null = global.
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('region')->nullable();
            $table->unsignedBigInteger('commodity_id')->nullable();

            // Multipliers applied while active.
            $table->decimal('price_modifier', 5, 3)->default(1.000);
            $table->decimal('demand_modifier', 5, 3)->default(1.000);
            $table->decimal('fuel_modifier', 5, 3)->default(1.000);
            $table->decimal('risk_modifier', 5, 3)->default(1.000);

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index('type');
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_events');
    }
};
