<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight stock exchange. Players invest in listed logistics/industrial
 * firms whose share prices drift with the economy (updated lazily on view) and
 * pay periodic dividends. share_holdings tracks each company's portfolio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listed_companies', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('sector', 24);
            $table->decimal('base_price', 10, 2);   // ₡ per share reference
            $table->decimal('share_price', 10, 2);   // current ₡ per share
            $table->decimal('dividend_yield', 5, 4); // per dividend cycle
            $table->decimal('volatility', 5, 4);     // per price tick
            $table->timestamps();
        });

        Schema::create('share_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listed_company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shares')->default(0);
            $table->decimal('avg_cost', 10, 2)->default(0);
            $table->timestamp('last_dividend_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'listed_company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_holdings');
        Schema::dropIfExists('listed_companies');
    }
};
