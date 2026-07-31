<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Warehouse lets a company buffer stock in a city, enabling arbitrage
 * (buy low now, sell high later) and consolidating outbound shipments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('city_id');
            $table->string('name');

            $table->unsignedInteger('capacity')->default(5000);   // total units
            $table->unsignedTinyInteger('tier')->default(1);       // 1..5 upgrade tier
            $table->boolean('cold_storage')->default(false);
            $table->boolean('hazmat_certified')->default(false);
            $table->boolean('automated')->default(false);          // reduces handling cost

            $table->bigInteger('upkeep')->default(500_00);         // ₡ cents per cycle

            $table->timestamps();

            $table->index(['company_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
