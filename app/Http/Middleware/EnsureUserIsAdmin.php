<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $privileges = $request->user()?->privileges ?? [];

        if (! in_array('admin', $privileges) && ! in_array('superadmin', $privileges)) {
            abort(403, 'Unauthorized: admin access required.');
        }

        return $next($request);
    }
}
