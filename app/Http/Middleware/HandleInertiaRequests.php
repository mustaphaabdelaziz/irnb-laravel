<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\WebsiteConfig;
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
        $isAdmin = $user && array_intersect(['admin', 'superadmin'], $user->privileges ?? []) !== [];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isAdmin' => $isAdmin,
            ],
            'locale' => app()->getLocale(),
            'appName' => $config->club_name ?? ['ar' => 'Sports Club', 'fr' => 'Club Sportif', 'en' => 'Sports Club'],
            'appShortName' => $config->club_short_name ?? 'IRNB',
            'branding' => $config->branding,
            // Lazily evaluated so the count query only runs for admins.
            'pendingApprovals' => $isAdmin
                ? fn () => User::where('is_user', true)->where('approved', false)->count()
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
