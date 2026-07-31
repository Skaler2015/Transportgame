<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-sided financial audit trail. Every cash movement — payouts,
 * penalties, fuel, wages, purchases, upkeep, loans — is journaled here so
 * the dashboard can render P&L and the anti-cheat layer can reconcile
 * balances server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // revenue | fuel | wages | purchase | upkeep | penalty | loan | interest | research
            $table->string('category');
            $table->string('description');
            $table->bigInteger('amount'); // ₡ cents; positive = income, negative = expense
            $table->bigInteger('balance_after');

            $table->nullableMorphs('source'); // optional link to shipment/vehicle/etc.
            $table->timestamp('occurred_at');

            $table->index(['company_id', 'category']);
            $table->index(['company_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
