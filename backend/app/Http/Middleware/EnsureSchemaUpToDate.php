<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-healing deploy migration.
 *
 * Many shared hosts (Hostinger native Git, in our case) pull a pre-built branch
 * and never run any Artisan command, so schema changes shipped in a release sit
 * un-applied until someone remembers to migrate by hand. That is fragile and has
 * bitten this project repeatedly (e.g. a missing `country` column 500-ing the
 * whole game).
 *
 * This middleware closes the gap: on the first request after a deploy it notices
 * the persisted schema marker no longer matches config('transoria.schema_version')
 * and runs `migrate --force` + `transoria:worldsync` exactly once, guarded by a
 * non-blocking file lock so only a single concurrent request does the work and
 * every other request sails straight through. It is deliberately defensive — any
 * failure is logged and swallowed so a migration hiccup can never take the site
 * down; the marker is only written on success, so the next request retries.
 */
class EnsureSchemaUpToDate
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->syncIfNeeded();

        return $next($request);
    }

    /** Do the tables the latest release depends on actually exist? */
    protected function criticalTablesPresent(): bool
    {
        try {
            return Schema::hasTable('trailer_models')
                && Schema::hasTable('trailers')
                && Schema::hasTable('company_achievements')
                && Schema::hasTable('news_items')
                && Schema::hasColumn('companies', 'onboarded_at')
                && Schema::hasColumn('cities', 'road_quality')
                && Schema::hasColumn('vehicles', 'registered_until')
                && Schema::hasColumn('drivers', 'licence_until')
                && Schema::hasColumn('warehouses', 'staff_level');
        } catch (\Throwable $e) {
            // DB momentarily unreachable — don't trigger a migrate storm.
            return true;
        }
    }

    protected function syncIfNeeded(): void
    {
        // Never interfere with the test suite (which manages its own schema).
        if (app()->environment('testing')) {
            return;
        }

        $target = (string) config('transoria.schema_version', '');
        if ($target === '') {
            return;
        }

        $markerFile = storage_path('app/.deploy-schema');

        // Fast path: marker current AND the critical tables actually exist.
        // Guarding on real tables (not just the marker) heals a half-applied
        // migration where the marker was written but a table is still missing.
        if (@is_file($markerFile)
            && trim((string) @file_get_contents($markerFile)) === $target
            && $this->criticalTablesPresent()) {
            return;
        }

        // Try to take a lock so only one request migrates. If the lock file
        // can't be created (odd storage permissions), we DON'T give up — the DB
        // migration is what matters, so we fall back to running it without the
        // lock. migrate --force + worldsync are both idempotent, so a rare
        // double-run is harmless.
        $lockFile = storage_path('app/.deploy-schema.lock');
        $lock = @fopen($lockFile, 'c');

        if ($lock !== false && ! flock($lock, LOCK_EX | LOCK_NB)) {
            // Another request is migrating right now — serve this one normally.
            @fclose($lock);

            return;
        }

        try {
            // Re-check under the lock in case a sibling just finished.
            if (@is_file($markerFile) && trim((string) @file_get_contents($markerFile)) === $target) {
                return;
            }

            // Only mark the schema current if migrate actually succeeded — a
            // non-zero exit (a migration that half-applied) must retry on the
            // next request rather than be recorded as done.
            $migrateCode = Artisan::call('migrate', ['--force' => true]);
            Artisan::call('transoria:worldsync');

            if ($migrateCode === 0) {
                @file_put_contents($markerFile, $target);
            } else {
                Log::error('EnsureSchemaUpToDate: migrate exited '.$migrateCode.' — will retry.');
            }
        } catch (\Throwable $e) {
            Log::error('EnsureSchemaUpToDate failed: '.$e->getMessage());
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }
}
