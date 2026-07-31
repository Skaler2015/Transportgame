<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Driver is an employee who executes shipments. Skill, fatigue, morale
 * and a hazmat licence all affect shipment speed, safety and payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('avatar_seed')->nullable();

            // available | driving | resting
            $table->string('status')->default('available');

            $table->unsignedTinyInteger('skill')->default(30);     // 0..100 -> speed & safety
            $table->unsignedTinyInteger('morale')->default(70);    // 0..100 -> efficiency & retention
            $table->unsignedTinyInteger('fatigue')->default(0);    // 0..100 -> accident risk
            $table->unsignedTinyInteger('loyalty')->default(50);   // 0..100

            $table->boolean('hazmat_licence')->default(false);
            $table->bigInteger('salary')->default(1800_00);        // ₡ cents per pay cycle
            $table->unsignedBigInteger('shipments_done')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
