<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the packaged desktop app.
 *
 * 404 rather than 403: on the web build these routes are not "forbidden", they
 * do not exist — there is no native folder picker and no local userData to back up.
 */
class EnsureDesktop
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('nativephp-internal.running'), 404);

        return $next($request);
    }
}
