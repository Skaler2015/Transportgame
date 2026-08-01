<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live news wire. Headlines are generated from world events and economic
 * signals; `country` null = global, otherwise scoped to that country's players.
 * `source_*` dedupes news derived from a specific record (e.g. a world event).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2)->nullable()->index();
            $table->string('category', 24)->default('economy');
            $table->string('severity', 12)->default('info'); // info | warning | critical
            $table->string('icon', 8)->default('📰');
            $table->string('headline');
            $table->text('body')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
