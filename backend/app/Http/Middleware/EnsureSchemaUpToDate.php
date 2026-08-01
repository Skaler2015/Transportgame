<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

        // Fast path: already up to date. Avoids touching the lock on every hit.
        if (@is_file($markerFile) && trim((string) @file_get_contents($markerFile)) === $target) {
            return;
        }

        $lockFile = storage_path('app/.deploy-schema.lock');
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            return; // storage not writable — nothing we can safely do
        }

        try {
            // Non-blocking: if another request already holds the lock it is
            // migrating right now, so we skip and serve this request normally.
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            // Re-check under the lock in case a sibling just finished.
            if (@is_file($markerFile) && trim((string) @file_get_contents($markerFile)) === $target) {
                return;
            }

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('transoria:worldsync');

            @file_put_contents($markerFile, $target);
        } catch (\Throwable $e) {
            Log::error('EnsureSchemaUpToDate failed: '.$e->getMessage());
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
