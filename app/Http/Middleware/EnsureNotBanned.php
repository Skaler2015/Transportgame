<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended (banned) account cannot use the API until the ban is lifted. */
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->isBanned()) {
            abort(403, 'This account has been suspended.');
        }

        return $next($request);
    }
}
