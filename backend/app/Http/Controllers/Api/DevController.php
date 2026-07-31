<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * Development conveniences. In production the world advances on a schedule
 * (see routes/console.php); this endpoint lets a local player force a tick
 * so shipments resolve immediately without waiting. Guarded to non-production.
 */
class DevController extends Controller
{
    public function tick()
    {
        if (app()->environment('production')) {
            abort(403, 'Manual ticks are disabled in production.');
        }

        Artisan::call('world:tick', ['--quiet-summary' => true]);

        return response()->json(['message' => 'World advanced one tick.']);
    }
}
