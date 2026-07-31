<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| One-time web installer
|--------------------------------------------------------------------------
| For hosts where CLI/SSH is inconvenient, this token-guarded endpoint runs
| the first-time setup from the browser: generate APP_KEY (if missing),
| migrate + seed the world, link storage and prime the market. Protected by
| the SETUP_TOKEN env var; returns 403 without the correct token. Safe to
| re-run (migrations are idempotent). Remove SETUP_TOKEN from .env when done.
*/
Route::get('/__setup', function () {
    $token = env('SETUP_TOKEN');
    abort_unless($token && hash_equals($token, (string) request('token')), 403, 'Forbidden');

    $log = [];

    if (! env('APP_KEY')) {
        Artisan::call('key:generate', ['--force' => true]);
        $log[] = 'APP_KEY generated.';
    }

    Artisan::call('migrate', ['--force' => true, '--seed' => true]);
    $log[] = Artisan::output();

    try {
        Artisan::call('storage:link');
        $log[] = Artisan::output();
    } catch (\Throwable $e) {
        $log[] = 'storage:link skipped: '.$e->getMessage();
    }

    Artisan::call('world:tick', ['--quiet-summary' => true]);
    $log[] = 'World primed.';

    return response(
        '<h1>Transoria setup complete ✅</h1><p>Open <a href="/">the game</a>. '
        .'Now remove SETUP_TOKEN from your .env.</p><pre style="white-space:pre-wrap">'
        .e(implode("\n", $log)).'</pre>',
        200
    )->header('Content-Type', 'text/html');
});

/*
|--------------------------------------------------------------------------
| Web routes — serve the built Vue SPA
|--------------------------------------------------------------------------
| In production the whole game is one Laravel app: the API lives under
| /api (routes/api.php) and everything else falls through to the compiled
| single-page app (frontend/dist copied into public/ at build time).
|
| Static assets (public/assets/*) are served directly by the web server;
| any remaining path returns index.html so the Vue router can take over
| (history-mode deep links like /dashboard keep working on refresh).
*/
Route::fallback(function () {
    $spa = public_path('index.html');

    if (File::exists($spa)) {
        // Return the compiled SPA shell. We send the contents (not a streamed
        // file response) so it behaves identically under Apache, nginx and the
        // PHP dev server.
        return response(File::get($spa), 200)->header('Content-Type', 'text/html');
    }

    // SPA not built yet (dev): point the developer at the frontend dev server.
    return response(
        '<h1>Transoria Online API</h1>'
        .'<p>The API is running. Build the SPA (<code>npm run build</code> in '
        .'<code>frontend/</code>, then copy <code>dist/</code> into '
        .'<code>backend/public/</code>) or run the Vite dev server for the UI.</p>',
        200
    )->header('Content-Type', 'text/html');
});
