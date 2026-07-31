<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-boxed objectives a company works toward for bonus Credits + XP.
 * Generated per company (daily/weekly), progressed by gameplay events, and
 * claimed once complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('period');   // daily | weekly
            $table->string('metric');   // deliveries | revenue | distance | on_time
            $table->string('title');
            $table->string('description');

            $table->unsignedBigInteger('target');
            $table->unsignedBigInteger('progress')->default(0);

            $table->bigInteger('reward_cash')->default(0); // ₡ cents
            $table->unsignedInteger('reward_xp')->default(0);

            // active | completed | claimed | expired
            $table->string('status')->default('active');

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
