<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trailers a company owns. Attached to a tractor at dispatch, they roll out
 * with the shipment and return to the yard when it resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trailers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trailer_model_id')->constrained();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('nickname')->nullable();
            $table->string('status')->default('idle');            // idle|en_route
            $table->decimal('condition', 5, 2)->default(100.00);  // 0..100
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trailers');
    }
};
