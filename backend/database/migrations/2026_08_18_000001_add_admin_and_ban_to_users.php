<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator role + moderation. `is_admin` gates the admin panel; `banned_at`
 * marks a suspended account (its API requests are rejected until lifted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_admin')) {
                $table->boolean('is_admin')->default(false)->index()->after('password');
            }
            if (! Schema::hasColumn('users', 'banned_at')) {
                $table->timestamp('banned_at')->nullable()->after('is_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['is_admin', 'banned_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
