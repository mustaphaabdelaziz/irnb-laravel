<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authenticated-but-unapproved (or deactivated) accounts from the app.
 * Old self-registered members wait on the pending page until an admin approves
 * them; an account disabled while signed in is signed out to the login page,
 * which says the account is disabled.
 */
class EnsureAccountApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => __('auth.disabled')]);
        }

        if ($user && ! $user->approved) {
            return redirect()->route('account.pending');
        }

        return $next($request);
    }
}
