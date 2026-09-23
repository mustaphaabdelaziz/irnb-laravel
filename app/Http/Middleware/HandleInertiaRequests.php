<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\ClubIdentity;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $config = WebsiteConfig::singleton();
        $user = $request->user();
        $permissions = $user ? $user->effectivePermissions() : [];
        $isSuperadmin = $user?->isSuperadmin() ?? false;
        // Anyone who can view the members module counts as an "admin" for legacy
        // UI checks (pending-approval badge, header role label, etc.).
        $isAdmin = $isSuperadmin || ($user && $user->hasPermission('users', 'view'));

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isAdmin' => $isAdmin,
                'isSuperadmin' => $isSuperadmin,
                'permissions' => $permissions,
            ],
            'locale' => app()->getLocale(),
            // Gates desktop-only UI (the Backup page) — there is no folder picker on the web.
            'isDesktop' => (bool) config('nativephp-internal.running'),
            'appName' => ClubIdentity::name($config),
            'appShortName' => ClubIdentity::shortName($config),
            'branding' => $config->branding,
            // Lazily evaluated so the count query only runs for admins.
            'pendingApprovals' => $isAdmin
                ? fn () => User::where('is_user', true)->where('approved', false)->count()
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Breeze's auth flows flash 'status'; without this it was
                // set on every password reset and never reached the toast.
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
