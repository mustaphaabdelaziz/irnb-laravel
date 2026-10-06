<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Log in as another user (admins only) and come back. While logged in as
 * someone, the admin has that user's access exactly; recorded events keep
 * the admin as impersonator.
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($refusal = Impersonation::refusal($actor, $user)) {
            return back()->with('error', $refusal);
        }

        // Recorded as the admin, before the switch.
        ActivityRecorder::record($actor, ActivityAction::USER_IMPERSONATED, $user);

        Auth::guard('web')->login($user);
        $request->session()->put(Impersonation::SESSION_KEY, $actor->id);

        return redirect()->route('dashboard')->with('success', 'flash.impersonate_started');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $impersonatorId = Impersonation::impersonatorId();
        $request->session()->forget(Impersonation::SESSION_KEY);

        $admin = $impersonatorId ? User::find($impersonatorId) : null;

        if (! $admin || ! $admin->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Auth::guard('web')->login($admin);

        return redirect()->route('users.index')->with('success', 'flash.impersonate_stopped');
    }
}
