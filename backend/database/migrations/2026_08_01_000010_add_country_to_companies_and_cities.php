<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces real-world countries. A company plays within one country; cities
 * belong to a country. Existing rows default to India (IN) and are migrated
 * onto real Indian cities by the transoria:worldsync command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('country', 2)->default('IN')->after('slug');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('region')->index();
            $table->string('country_name')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('country');
        });
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['country', 'country_name']);
        });
    }
};
