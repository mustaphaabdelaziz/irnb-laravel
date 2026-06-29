<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authenticated-but-unapproved (or deactivated) accounts from the app.
 * Self-registered members land here until an admin approves them, which makes
 * the member-approval workflow actually enforce access.
 */
class EnsureAccountApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->approved || ! $user->is_active)) {
            return redirect()->route('account.pending');
        }

        return $next($request);
    }
}
