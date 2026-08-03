<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Player-to-player commodity exchange. A company lists units of a commodity
 * (drawn from a warehouse) at a set price; another company buys them. The
 * goods land in the buyer's warehouse in the listing's city.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('commodity_id')->constrained();
            $table->unsignedBigInteger('city_id'); // where the goods are / delivered

            $table->unsignedInteger('units');
            $table->decimal('price_per_unit', 12, 2); // ₡ per unit

            // open | sold | cancelled
            $table->string('status')->default('open');
            $table->foreignId('buyer_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'commodity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_listings');
    }
};
