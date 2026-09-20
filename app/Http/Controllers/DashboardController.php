<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\HeroStats;
use App\Services\Dashboard\OverviewStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A resolver, not a calculator.
 *
 * Every number on the dashboard comes from a stat provider that can be tested
 * on its own. Tab payloads are optional props, so switching tabs costs one
 * partial reload and a first paint never pays for the three tabs nobody is
 * looking at.
 */
class DashboardController extends Controller
{
    /** Which permission module each tab needs before its data will be computed. */
    private const TAB_MODULES = [
        'finance' => ['finance'],
        'members' => ['players'],
        'operations' => ['equipment', 'subscriptions'],
    ];

    public function __construct(
        private readonly HeroStats $hero,
        private readonly OverviewStats $overview,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = DashboardFilters::fromRequest($request);
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'filters' => $filters->toArray(),

            // Closures, not values: Inertia skips a closure prop on a partial
            // reload that did not ask for it. Passing the array directly would
            // recompute the whole hero row every time someone switches tab.
            'branches' => fn () => Branch::query()
                ->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'can' => fn (): array => $this->visibleTabs($user),
            'hero' => fn (): array => $this->hero->get($filters),

            'overview' => Inertia::optional(fn (): array => $this->overview->get($filters)),

            // Phase 2 and 3 fill these in. The props exist now so the client's
            // tab machinery has one shape to code against from the start.
            'finance' => Inertia::optional(
                fn (): ?array => $this->guard($user, 'finance') ? [] : null,
            ),
            'members' => Inertia::optional(
                fn (): ?array => $this->guard($user, 'members') ? [] : null,
            ),
            'operations' => Inertia::optional(
                fn (): ?array => $this->guard($user, 'operations') ? [] : null,
            ),
        ]);
    }

    /** @return array<string, bool> */
    private function visibleTabs(?User $user): array
    {
        return [
            'overview' => true,
            'finance' => $this->guard($user, 'finance'),
            'members' => $this->guard($user, 'members'),
            'operations' => $this->guard($user, 'operations'),
        ];
    }

    /**
     * Whether a tab may be computed for this user.
     *
     * Hiding the tab in the client is a courtesy; this is the part that means
     * a hand-written ?tab=finance returns nothing.
     */
    private function guard(?User $user, string $tab): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperadmin()) {
            return true;
        }

        foreach (self::TAB_MODULES[$tab] ?? [] as $module) {
            if ($user->hasPermission($module, 'view')) {
                return true;
            }
        }

        return false;
    }
}
