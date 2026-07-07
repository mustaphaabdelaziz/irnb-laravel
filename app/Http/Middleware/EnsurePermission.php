<?php

namespace App\Http\Middleware;

use App\Support\PermissionMap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = PermissionMap::resolve($request->route()?->getName());

        if ($resolved === null) {
            return $next($request); // unguarded / unmapped route
        }

        [$module, $action] = $resolved;

        if (! $request->user()?->hasPermission($module, $action)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
