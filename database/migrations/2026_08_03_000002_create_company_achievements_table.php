<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which achievements a company has unlocked. Definitions live in
 * config/transoria.php; this table only records unlock state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key');
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->unique(['company_id', 'achievement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_achievements');
    }
};
