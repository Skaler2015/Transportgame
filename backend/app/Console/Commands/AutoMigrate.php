<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs pending migrations automatically during deploy — but ONLY when the
 * server's .env sets AUTO_MIGRATE=true. This lets Hostinger's composer-install
 * deploy step apply schema changes with no manual action, while staying a
 * harmless no-op in CI (where AUTO_MIGRATE is unset), so it never breaks builds.
 */
class AutoMigrate extends Command
{
    protected $signature = 'transoria:auto-migrate';

    protected $description = 'Run migrations on deploy when AUTO_MIGRATE=true';

    public function handle(): int
    {
        if (! filter_var(env('AUTO_MIGRATE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->info('AUTO_MIGRATE off — skipping migrations.');

            return self::SUCCESS;
        }

        try {
            Artisan::call('migrate', ['--force' => true], $this->getOutput());
            $this->info('Migrations applied.');

            // Keep the world data (real cities, per-country migration) in sync.
            Artisan::call('transoria:worldsync', [], $this->getOutput());
        } catch (\Throwable $e) {
            // Never fail the deploy on a hiccup; surface it in logs.
            $this->warn('Auto-migrate/worldsync skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
