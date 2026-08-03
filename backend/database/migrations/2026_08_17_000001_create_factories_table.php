<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factories (manufacturing). A factory sits at one of the company's warehouses
 * and, each production cycle, converts cash (+ optional input commodities drawn
 * from that warehouse's inventory) into output units deposited back into the
 * same warehouse. Production is stepped lazily, so `last_produced_at` anchors
 * the catch-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('recipe');                       // config key
            $table->unsignedInteger('level')->default(1);
            $table->string('status')->default('active');    // active | paused
            $table->string('last_note')->nullable();        // why it idled last cycle
            $table->unsignedBigInteger('lifetime_output')->default(0);
            $table->timestamp('last_produced_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factories');
    }
};
