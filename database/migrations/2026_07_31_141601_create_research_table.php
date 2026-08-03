<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which research nodes a company has unlocked. The tree itself is
 * defined in config/transoria.php; this table records ownership and the
 * cumulative bonuses applied to the company's operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_research', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('node_key');    // matches config transoria.research nodes
            $table->timestamp('unlocked_at');

            $table->unique(['company_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_research');
    }
};
