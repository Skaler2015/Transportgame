<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

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
