<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company financing. A loan pays out its principal immediately and accrues
 * interest each world tick until repaid; the outstanding balance feeds the
 * company's debt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('principal');           // ₡ cents borrowed
            $table->bigInteger('balance');             // ₡ cents still owed
            $table->decimal('interest_rate', 6, 4);    // per-tick fractional rate

            $table->string('status')->default('active'); // active | repaid
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
