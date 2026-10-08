<?php

namespace App\Http\Controllers;

use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Dashboard\ModuleStats;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Support\Export;
use App\Support\ListFilter;
use App\Support\UiLang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Subscription::query()->with(['categories', 'branches']);

        // Each filter may hold several values: OR within, AND across.
        if ($kinds = array_values(array_intersect(ListFilter::values($request, 'kind'), Subscription::KINDS))) {
            $query->whereIn('kind', $kinds);
        }

        if ($years = ListFilter::ids($request, 'year')) {
            $query->whereIn('year', $years);
        }

        if ($branchIds = ListFilter::ids($request, 'branch_id')) {
            $query->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds));
        }

        $subscriptions = $query->orderByDesc('year')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'strip' => fn (): array => app(ModuleStats::class)->subscriptions(),
            // Closures so filter reloads (partial) skip these queries.
            'branches' => fn () => Branch::orderBy('name')->get(),
            'branchStats' => fn () => $this->branchStats(),
            'filters' => ListFilter::echo($request, ['year' => ListFilter::IDS, 'kind' => Subscription::KINDS, 'branch_id' => ListFilter::IDS]),
            'seasons' => fn () => $this->seasonOptions(Subscription::query()->whereNotNull('year')->distinct()->pluck('year')),
        ]);
    }

    /**
     * Per-branch financial breakdown of subscription obligations. Because a subscription can
     * belong to several branches, its obligation counts under EACH of those branches, so the
     * rows overlap and do not sum to a grand total. Obligations of an untagged subscription
     * (or a manual debt with no subscription) fall into the "No branch" bucket.
     *
     * @return array<int, array{branch_id:int|null, name:string, owed:float, collected:float, outstanding:float, players:int, paid_rate:float}>
     */
    private function branchStats(): array
    {
        // subscription_id => [branch_id, ...]
        $subBranches = DB::table('branch_subscription')
            ->get()
            ->groupBy('subscription_id')
            ->map(fn ($rows) => $rows->pluck('branch_id')->all());

        $buckets = []; // branch_id (or '_none') => running totals
        $ensure = function (&$buckets, $key) {
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['owed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'players' => []];
            }
        };

        PlayerSubscription::query()
            ->get(['id', 'player_id', 'subscription_id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value'])
            ->each(function ($ps) use (&$buckets, $subBranches, $ensure) {
                $owed = (float) $ps->amount_owed;
                $paid = (float) $ps->amount_paid;
                // Via the accessor so discounts and exemptions are honoured; `owed`
                // stays gross (the real price) while `outstanding` is what is due.
                $outstanding = (float) $ps->remaining_amount;

                $branchIds = $ps->subscription_id ? ($subBranches[$ps->subscription_id] ?? []) : [];
                $targets = empty($branchIds) ? ['_none'] : $branchIds;

                foreach ($targets as $key) {
                    $ensure($buckets, $key);
                    $buckets[$key]['owed'] += $owed;
                    $buckets[$key]['collected'] += $paid;
                    $buckets[$key]['outstanding'] += $outstanding;
                    $buckets[$key]['players'][$ps->player_id] = true;
                }
            });

        $row = fn ($branchId, $name, $b) => [
            'branch_id' => $branchId,
            'name' => $name,
            'owed' => round($b['owed'], 2),
            'collected' => round($b['collected'], 2),
            'outstanding' => round($b['outstanding'], 2),
            'players' => count($b['players']),
            'paid_rate' => $b['owed'] > 0 ? round($b['collected'] / $b['owed'] * 100, 1) : 0.0,
        ];

        // Every branch (ordered by name), so admins see each even at zero.
        $stats = Branch::orderBy('name')->get()
            ->map(fn ($branch) => $row(
                $branch->id,
                $branch->localized_name,
                $buckets[$branch->id] ?? ['owed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'players' => []]
            ))
            ->values()
            ->all();

        // Append the No-branch bucket only when it actually holds obligations.
        if (isset($buckets['_none'])) {
            $stats[] = $row(null, 'No branch', $buckets['_none']);
        }

        return $stats;
    }

    /**
     * Seasons offered by the year picker, keyed by END year ("2026" => "2025/2026"):
     * a few past seasons for late entries and two ahead for planning, plus any
     * season already in use ($keep) that falls outside that window.
     *
     * @return array<int, array{value:int, label:string}>
     */
    private function seasonOptions(iterable $keep = []): array
    {
        $current = Subscription::currentSeasonEndYear();
        $years = range($current + 2, $current - 4);

        $years = array_values(array_unique([...$years, ...array_map('intval', array_filter([...$keep]))]));
        rsort($years);

        return array_map(fn (int $year) => ['value' => $year, 'label' => Subscription::seasonLabel($year)], $years);
    }

    public function show(Subscription $subscription): Response
    {
        $subscription->load(['categories', 'branches']);

        $playerSubscriptions = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->with(['player.category', 'transaction'])
            ->get();

        // Calculate stats
        $total = $playerSubscriptions->count();
        $paidCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'paid' || $ps->payment_status === 'exempt')->count();
        $unpaidCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'unpaid')->count();
        $partialCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'partial')->count();
        $totalCollected = $playerSubscriptions->sum('amount_paid');
        $paidPercentage = $total > 0 ? round(($paidCount / $total) * 100, 1) : 0;

        // Players not yet assigned to this subscription
        $assignedPlayerIds = $playerSubscriptions->pluck('player_id')->toArray();
        $availablePlayers = Player::query()->eligibleFor($subscription)
            ->where('archived', false)
            ->whereNotIn('id', $assignedPlayerIds)
            ->with('category')
            ->orderBy('lastname')
            ->get(['id', ...Player::NAME_COLUMNS, 'is_student', 'category_id', 'membership_id'])
            // What each would owe, category price override included.
            ->each(fn (Player $player) => $player->setAttribute('price', $subscription->amountFor($player)));

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
            'playerSubscriptions' => $playerSubscriptions,
            'categories' => Category::orderBy('name')->get(),
            'availablePlayers' => $availablePlayers,
            'stats' => [
                'total' => $total,
                'paid_count' => $paidCount,
                'unpaid_count' => $unpaidCount,
                'partial_count' => $partialCount,
                'total_collected' => $totalCollected,
                'paid_percentage' => $paidPercentage,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Subscriptions/Create', [
            'seasons' => $this->seasonOptions(),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function store(StoreSubscriptionRequest $request): RedirectResponse
    {
        $branchIds = $request->validated('branch_ids') ?? [];

        $subscription = DB::transaction(function () use ($request, $branchIds) {
            $subscription = Subscription::create($request->subscriptionAttributes());
            $subscription->categories()->attach($request->categorySync());
            $subscription->branches()->attach($branchIds);

            ActivityRecorder::record($request->user(), ActivityAction::SUBSCRIPTION_CREATED, $subscription, [
                'kind' => $subscription->kind,
            ]);

            return $subscription;
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'flash.subscription_created');
    }

    public function edit(Subscription $subscription): Response
    {
        $subscription->load(['categories', 'branches']);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'seasons' => $this->seasonOptions([$subscription->year]),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function update(StoreSubscriptionRequest $request, Subscription $subscription): RedirectResponse
    {
        $attributes = $request->subscriptionAttributes();

        // Existing obligations were written under the old kind (debt or not,
        // with or without a season); flipping it would leave them contradicting it.
        if ($attributes['kind'] !== $subscription->kind && $subscription->playerSubscriptions()->exists()) {
            throw ValidationException::withMessages(['kind' => __('The kind cannot change once players are assigned.')]);
        }

        DB::transaction(function () use ($request, $subscription, $attributes) {
            $subscription->update($attributes);
            $subscription->categories()->sync($request->categorySync());
            $subscription->branches()->sync($request->validated('branch_ids') ?? []);
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'flash.subscription_updated');
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('subscriptions.index')
            ->with('success', 'flash.subscription_deleted');
    }

    public function assign(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->validate([
            'player_ids' => ['nullable', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assign_all' => ['nullable', 'boolean'],
        ]);

        $query = Player::query()->eligibleFor($subscription);

        if ($request->boolean('assign_all')) {
            $query->where('archived', false);
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }
        } else {
            $query->whereIn('id', $request->input('player_ids', []));
        }

        $playerIds = $query->pluck('id');

        $existingPlayerIds = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id');

        $newPlayerIds = $playerIds->diff($existingPlayerIds);

        DB::transaction(function () use ($newPlayerIds, $subscription, $request) {
            $assigned = 0;

            foreach ($newPlayerIds as $playerId) {
                $player = Player::find($playerId);
                if (! $player) {
                    continue;
                }

                $subscription->assignTo($player);
                app(RecalculatePlayerDebtService::class)->forPlayer($player);
                $assigned++;
            }

            if ($assigned > 0) {
                ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_ASSIGNED, $subscription, [
                    'count' => $assigned,
                ]);
            }
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', ['key' => 'flash.players_assigned', 'params' => ['count' => $newPlayerIds->count()]]);
    }

    public function assignOne(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
        ]);

        $player = Player::findOrFail($request->input('player_id'));

        $alreadyAssigned = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->where('player_id', $player->id)
            ->exists();

        if ($alreadyAssigned) {
            return back()->with('error', 'flash.player_already_subscribed');
        }

        if (! $subscription->appliesToCategory($player->category_id)) {
            return back()->with('error', 'flash.player_not_eligible_for_subscription');
        }

        DB::transaction(function () use ($player, $subscription, $request) {
            $subscription->assignTo($player);
            app(RecalculatePlayerDebtService::class)->forPlayer($player);

            ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_ASSIGNED, $subscription, [
                'count' => 1,
            ]);
        });

        return back()->with('success', ['key' => 'flash.player_added_to_subscription', 'params' => ['name' => $player->fullname]]);
    }

    public function export(Request $request, Subscription $subscription): SymfonyResponse
    {
        $playerSubscriptions = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->with(['player.category', 'transaction'])
            ->get();

        // all | paid | unpaid | partial; anything else (an array, a path…) → all.
        $filter = $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'paid', 'unpaid', 'partial'], true)) {
            $filter = 'all';
        }

        if ($filter === 'paid') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => in_array($ps->payment_status, ['paid', 'exempt']));
        } elseif ($filter === 'unpaid') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'unpaid');
        } elseif ($filter === 'partial') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'partial');
        }

        // The tab label the page uses (t(tab)).
        $title = $subscription->designation.' ('.UiLang::get($filter, ucfirst($filter)).')';
        $headers = ['#', ...array_map(fn (string $key) => UiLang::get($key), [
            'col.membership_id', 'col.player_name', 'col.category', 'col.amount_owed', 'col.amount_paid', 'col.status',
        ])];

        $rows = [];
        $rowNum = 1;
        foreach ($playerSubscriptions as $ps) {
            $player = $ps->player;
            $name = $player ? $player->fullname : 'N/A';

            $rows[] = [
                $rowNum++,
                $player?->membership_id !== null ? (string) $player->membership_id : '-',
                $name,
                $player?->category?->localized_name ?? '-',
                (float) $ps->amount_owed,
                (float) $ps->amount_paid,
                // Same key the page's statusLabel('payment', ...) resolves to.
                UiLang::get((string) $ps->payment_status, strtoupper((string) $ps->payment_status)),
            ];
        }

        // Summary row.
        $rows[] = [
            null, null, null, UiLang::get('col.total'),
            (float) $playerSubscriptions->sum('amount_owed'),
            (float) $playerSubscriptions->sum('amount_paid'),
            null,
        ];

        $filename = 'subscription_'.str_replace([' ', '/', '\\'], '_', $subscription->designation).'_'.$filter;

        return Export::download(Export::format($request), $filename, $headers, $rows, $title);
    }
}
