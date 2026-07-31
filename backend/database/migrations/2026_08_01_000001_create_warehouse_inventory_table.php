<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock a company holds inside a warehouse. Enables direct arbitrage: buy a
 * commodity at a city's local price into the warehouse, hold it, and sell when
 * the local price rises. avg_unit_cost tracks the weighted purchase cost for
 * profit reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commodity_id')->constrained();
            $table->unsignedInteger('units')->default(0);
            $table->decimal('avg_unit_cost', 12, 2)->default(0); // ₡ per unit
            $table->timestamps();

            $table->unique(['warehouse_id', 'commodity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inventory');
    }
};
