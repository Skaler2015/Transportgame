<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Shipment is a live execution of a Contract: a specific vehicle + driver
 * dispatched along a route. The world tick advances progress and, on
 * arrival, resolves the outcome (on-time / late / failed) and pays out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('driver_id')->constrained();

            // en_route | delivered | late | failed
            $table->string('status')->default('en_route');

            $table->decimal('distance_km', 10, 2);
            $table->decimal('progress_km', 10, 2)->default(0);
            $table->decimal('avg_speed', 6, 2);           // km/h effective, after modifiers
            $table->decimal('fuel_budget', 10, 2);        // litres expected to burn
            $table->bigInteger('projected_payout');       // ₡ cents (may be reduced on late)

            // Snapshot of conditions at dispatch for deterministic resolution.
            $table->string('weather_snapshot')->default('clear');
            $table->json('event_log')->nullable();        // narrative incidents

            $table->timestamp('departed_at');
            $table->timestamp('eta_at');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
