<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityPeriod;
use App\Services\Activity\ActivityReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who did what: the per-area comparison of every user (users/view), one
 * user's detail (users/view), and "My activity" — the same detail for the
 * signed-in user only, open to any approved member.
 */
class UserActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $period = ActivityPeriod::fromRequest($request);

        return Inertia::render('Users/Activity/Index', [
            'period' => $period->toArray(),
            'rows' => ActivityReport::comparison($period),
            'areas' => array_keys(ActivityAction::AREAS),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        return $this->detail($request, $user, mine: false);
    }

    /** Always the signed-in user: no route parameter, and any ?user= is ignored. */
    public function mine(Request $request): Response
    {
        return $this->detail($request, $request->user(), mine: true);
    }

    private function detail(Request $request, User $user, bool $mine): Response
    {
        $period = ActivityPeriod::fromRequest($request);
        $action = $this->validCode($request->query('action'), ActivityAction::ALL);

        $props = [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'mine' => $mine,
            'period' => $period->toArray(),
            'summary' => ActivityReport::summary($user->id, $period),
            'areas' => ActivityAction::AREAS,
            'action' => $action,
            'area' => $this->validCode($request->query('area'), array_keys(ActivityAction::AREAS)),
        ];

        // Entries only for a known action code; anything else shows the summary alone.
        if ($action !== null) {
            $props['entries'] = ActivityReport::entries($user->id, $action, $period)->withQueryString();
        }

        return Inertia::render('Users/Activity/Show', $props);
    }

    /** @param list<string> $allowed */
    private function validCode(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
