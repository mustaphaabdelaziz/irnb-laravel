<?php

namespace App\Http\Controllers;

use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Dashboard\ModuleStats;
use App\Services\Finance\PruneOutOfBranchObligations;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\SaveSubscriptionTarification;
use App\Services\Finance\TarificationData;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Services\Player\RegistrationDisplay;
use App\Support\ClubIdentity;
use App\Support\Export;
use App\Support\ListFilter;
use App\Support\Media;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

        // Plain rows, not models: this spans every season's obligations, and
        // hydrating them all took most of the page's time.
        DB::table('player_subscriptions')
            ->get(['id', 'player_id', 'subscription_id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value'])
            ->each(function ($ps) use (&$buckets, $subBranches, $ensure) {
                // The model's own arithmetic, so discounts and exemptions are honoured;
                // `owed` stays gross (the real price) while `outstanding` is what is due.
                $money = PlayerSubscription::moneyFor($ps->amount_owed, $ps->amount_paid, (bool) $ps->is_exempt, $ps->discount_type, $ps->discount_value);
                $owed = $money['owed'];
                $paid = $money['paid'];
                $outstanding = $money['remaining'];

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
     * Every obligation behind the "No branch" row of the index: manual debts
     * and obligations of a subscription with no branch. Read-only; paying,
     * editing and deleting stay on the player's page.
     *
     * "Remaining" depends on discounts and exemptions, so status filtering and
     * ordering happen in PHP on plain rows. The bucket is small: it only holds
     * debts typed by hand.
     */
    public function manualDebts(Request $request): Response
    {
        $status = in_array($request->query('status'), ['unpaid', 'paid'], true) ? $request->query('status') : null;
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';

        $columns = ['id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value', 'created_at'];
        $withMoney = fn ($row) => ['id' => (int) $row->id, 'created_at' => (string) $row->created_at]
            + PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, (bool) $row->is_exempt, $row->discount_type, $row->discount_value);

        $bucket = PlayerSubscription::query()->withoutBranch()->toBase()->get($columns)->map($withMoney);

        // The page shows the name with بن / بنت connectors (and the nickname in
        // brackets), so a pasted name must still match: drop those, like Player::scopeSearch.
        $words = collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $word) => trim($word, '()'))
            ->reject(fn (string $word) => $word === '' || $word === 'بن' || $word === 'بنت')
            ->values();

        $rows = $words->isEmpty() ? $bucket : PlayerSubscription::query()->withoutBranch()
            // Every word must be found somewhere: in the label, the subscription
            // name, or one of the player's names / membership id.
            ->where(function (Builder $query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $query->where(fn (Builder $q) => $q
                        ->where('label', 'like', $like)
                        ->orWhereHas('subscription', fn (Builder $s) => $s->where('name', 'like', $like))
                        ->orWhereHas('player', fn (Builder $p) => $p->where(fn (Builder $p) => $p
                            ->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('nickname', 'like', $like)
                            ->orWhere('father', 'like', $like)
                            ->orWhere('grandfather', 'like', $like)
                            ->orWhere('membership_id', 'like', $like))));
                }
            })
            ->toBase()->get($columns)->map($withMoney);

        if ($status !== null) {
            $rows = $rows->filter(fn (array $row) => ($row['remaining'] > 0) === ($status === 'unpaid'));
        }

        // Something left to pay first, then the newest.
        $rows = $rows->sort(fn (array $a, array $b) => [$b['remaining'] > 0, $b['created_at'], $b['id']]
            <=> [$a['remaining'] > 0, $a['created_at'], $a['id']])->values();

        $perPage = 25;
        $rawPage = $request->query('page', 1);
        $page = is_string($rawPage) && preg_match('/^\d{1,9}$/', $rawPage) ? max(1, (int) $rawPage) : 1;
        $page = min($page, max(1, (int) ceil($rows->count() / $perPage)));
        $ids = $rows->forPage($page, $perPage)->pluck('id');

        $models = PlayerSubscription::query()
            ->with(['subscription', 'player:id,membership_id,'.implode(',', Player::NAME_COLUMNS)])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = $ids->map(function (int $id) use ($models) {
            $debt = $models[$id];
            $money = $debt->money();

            return [
                'id' => $debt->id,
                'player' => $debt->player ? [
                    'id' => $debt->player->id,
                    'fullname' => $debt->player->fullname,
                    'membership_id' => $debt->player->membership_id,
                ] : null,
                'label' => $debt->subscription?->name ?? $debt->label,
                'season' => $debt->subscription ? $debt->subscription->year_label : ($debt->year ? (string) $debt->year : null),
                'owed' => $money['owed'],
                'paid' => $money['paid'],
                'remaining' => $money['remaining'],
                'status' => $money['status'],
                // Through the model's serializer, so it is on the club's clock.
                'created_at' => $debt->attributesToArray()['created_at'] ?? null,
            ];
        })->all();

        return Inertia::render('Subscriptions/ManualDebts', [
            'debts' => new LengthAwarePaginator($items, $rows->count(), $perPage, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
            // The whole bucket, whatever the filters: these equal the index row.
            'strip' => [
                ['key' => 'debts_count', 'value' => $bucket->count(), 'format' => 'number'],
                ['key' => 'debts_owed', 'value' => round($bucket->sum('owed'), 2), 'format' => 'money'],
                ['key' => 'debts_collected', 'value' => round($bucket->sum('paid'), 2), 'format' => 'money'],
                ['key' => 'debts_left', 'value' => round($bucket->sum('remaining'), 2), 'format' => 'money'],
            ],
            'filters' => array_filter(['status' => $status, 'search' => $search], fn ($v) => $v !== null && $v !== ''),
        ]);
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

    public function show(Request $request, Subscription $subscription): Response
    {
        $subscription->load(['categories', 'branches']);

        $rows = $this->showRows($this->assignedQuery($subscription)->get(), $this->pageBranch($subscription));
        $statuses = array_count_values(array_column($rows, 'payment_status'));

        // Calculate stats
        $total = count($rows);
        $paidCount = ($statuses['paid'] ?? 0) + ($statuses['exempt'] ?? 0);
        $unpaidCount = $statuses['unpaid'] ?? 0;
        $partialCount = $statuses['partial'] ?? 0;
        $totalCollected = array_sum(array_map(fn (array $row) => (float) $row['amount_paid'], $rows));
        $paidPercentage = $total > 0 ? round(($paidCount / $total) * 100, 1) : 0;

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
            'playerSubscriptions' => $rows,
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(),
            // Names for the player status filter; the page offers only those in the list.
            'playerStatuses' => PlayerStatus::orderBy('sort_order')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en', 'code']),
            'availablePlayers' => $this->unassignedPlayers($subscription),
            // Active members of its branches it doesn't otherwise apply to (a footballer
            // of category A who also swims in B), loaded when the add dialog asks.
            'outsidePlayers' => Inertia::optional(fn () => $subscription->categories->isEmpty() ? [] : $this->unassignedPlayers($subscription, outside: true)),
            'stats' => [
                'total' => $total,
                'paid_count' => $paidCount,
                'unpaid_count' => $unpaidCount,
                'partial_count' => $partialCount,
                'total_collected' => $totalCollected,
                'paid_percentage' => $paidPercentage,
            ],
            ...$this->tarificationProp($request, $subscription),
        ]);
    }

    /** The branch this subscription's page speaks for: its one branch, otherwise none (main values). */
    private function pageBranch(Subscription $subscription): ?int
    {
        return RegistrationDisplay::singleBranch($subscription->branches->pluck('id'));
    }

    /**
     * What the page and export show for a player: that branch's registration, else
     * the main one. A player with no registration shows their own values.
     *
     * @return array{membership_id: ?string, category: ?Category, status_id: ?int}
     */
    private function shownValues(Player $player, ?int $branchId, RegistrationDisplay $display): array
    {
        $row = $display->shown($player, $branchId);
        // That branch's status, else the player's overall one (left only when left everywhere).
        $status = $display->values($player, $branchId)['status_id'];

        return [
            'membership_id' => $row === null ? $player->membership_id : $row->membership_id,
            'category' => $row ? $row->category : $player->category,
            'status_id' => $status === null ? null : (int) $status,
        ];
    }

    /**
     * Active players not yet on the subscription, each with what they would owe
     * (category price override included). $outside drops the category rule.
     *
     * @return Collection<int, Player>
     */
    private function unassignedPlayers(Subscription $subscription, bool $outside = false): Collection
    {
        $display = RegistrationDisplay::make();
        $branchId = $this->pageBranch($subscription);

        return Player::query()->eligibleFor($subscription, anyCategory: $outside)
            ->when($outside, fn ($query) => $query->whereNotIn('id', Player::query()->select('id')->eligibleFor($subscription)))
            ->where('archived', false)
            ->whereNotIn('id', PlayerSubscription::query()->select('player_id')->where('subscription_id', $subscription->id))
            ->with(['category', 'registrations.category'])
            ->orderBy('lastname')
            ->get(['id', ...Player::NAME_COLUMNS, 'is_student', 'category_id', 'membership_id'])
            ->each(function (Player $player) use ($subscription, $display, $branchId) {
                $shown = $this->shownValues($player, $branchId, $display);
                $player->setAttribute('price', $subscription->amountFor($player, $display));
                $player->setAttribute('membership_id', $shown['membership_id']);
                $player->setRelation('category', $shown['category']);
                $player->unsetRelation('registrations');
            });
    }

    /**
     * The assignee table's rows, A→Z: only the fields the page reads, each
     * read once. Serialising whole models (timestamps on the club clock, the
     * same category once per row) took most of the time of a season's
     * subscription with a full club.
     *
     * @param  Collection<int, PlayerSubscription>  $playerSubscriptions
     * @return list<array<string, mixed>>
     */
    private function showRows(Collection $playerSubscriptions, ?int $branchId): array
    {
        $categories = [];
        $category = function (?Category $category) use (&$categories): ?array {
            if ($category === null) {
                return null;
            }

            return $categories[$category->id] ??= [
                'id' => $category->id,
                'name' => $category->name,
                'localized_name' => $category->localized_name,
            ];
        };

        $display = RegistrationDisplay::make();

        return $playerSubscriptions
            ->map(function (PlayerSubscription $ps) use ($category, $display, $branchId) {
                $money = $ps->money();
                $player = $ps->player;
                $shown = $player === null ? null : $this->shownValues($player, $branchId, $display);

                return [
                    'id' => $ps->id,
                    'player_id' => $ps->player_id,
                    'amount_owed' => $ps->amount_owed,
                    'amount_paid' => $ps->amount_paid,
                    'remaining_amount' => $money['remaining'],
                    'payment_status' => $money['status'],
                    'player' => $player === null ? null : [
                        'id' => $player->id,
                        'membership_id' => $shown['membership_id'],
                        'lastname' => $player->lastname,
                        'fullname' => $player->fullname,
                        'category' => $category($shown['category']),
                        'status_id' => $shown['status_id'],
                    ],
                ];
            })
            ->sortBy('player.fullname', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Subscriptions/Create', [
            'seasons' => $this->seasonOptions(),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            ...$this->tarificationProp($request),
        ]);
    }

    public function store(StoreSubscriptionRequest $request): RedirectResponse
    {
        $branchIds = $request->validated('branch_ids') ?? [];

        $subscription = DB::transaction(function () use ($request, $branchIds) {
            $subscription = Subscription::create($request->subscriptionAttributes());
            $subscription->categories()->attach($request->categorySync());
            $subscription->branches()->attach($branchIds);
            $this->saveTarification($request, $subscription);

            ActivityRecorder::record($request->user(), ActivityAction::SUBSCRIPTION_CREATED, $subscription, [
                'kind' => $subscription->kind,
            ]);

            return $subscription;
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'flash.subscription_created');
    }

    public function edit(Request $request, Subscription $subscription): Response
    {
        $subscription->load(['categories', 'branches']);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'seasons' => $this->seasonOptions([$subscription->year]),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::with('branch:id,name,name_ar,name_fr,name_en')->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            ...$this->tarificationProp($request, $subscription),
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
            $this->saveTarification($request, $subscription);

            // Players outside the (new) branches lose the obligation while it is untouched.
            app(PruneOutOfBranchObligations::class)->forSubscription($subscription);
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'flash.subscription_updated');
    }

    private function saveTarification(StoreSubscriptionRequest $request, Subscription $subscription): void
    {
        if (is_array($data = $request->validated('tarification'))) {
            app(SaveSubscriptionTarification::class)->save($subscription, $data);
        }
    }

    /**
     * Coach pay: without Finance → view the prop does not exist at all.
     *
     * @return array<string, mixed>
     */
    private function tarificationProp(Request $request, ?Subscription $subscription = null): array
    {
        return $request->user()?->hasPermission('finance', 'view')
            ? ['tarification' => fn (): array => app(TarificationData::class)->for($subscription)]
            : [];
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

        $query = $request->boolean('assign_all')
            ? Player::query()
                ->eligibleFor($subscription, onlyCategory: $request->filled('category_id') ? $request->integer('category_id') : null)
                ->where('archived', false)
            : Player::query()->eligibleFor($subscription)->whereIn('id', $request->input('player_ids', []));

        $players = $query->with('registrations')->get();

        $existingPlayerIds = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('player_id', $players->modelKeys())
            ->pluck('player_id');

        $newPlayers = $players->reject(fn (Player $player) => $existingPlayerIds->contains($player->id));

        DB::transaction(function () use ($newPlayers, $subscription, $request) {
            $display = RegistrationDisplay::make();

            foreach ($newPlayers as $player) {
                $subscription->assignTo($player, $display);
                app(RecalculatePlayerDebtService::class)->forPlayer($player);
            }

            if ($newPlayers->isNotEmpty()) {
                ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_ASSIGNED, $subscription, [
                    'count' => $newPlayers->count(),
                ]);
            }
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', ['key' => 'flash.players_assigned', 'params' => ['count' => $newPlayers->count()]]);
    }

    public function assignOne(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
            // Sent by the subscription page when the player was picked from
            // another category: only the branch rule is checked then.
            'outside_category' => ['nullable', 'boolean'],
        ]);

        $player = Player::findOrFail($request->input('player_id'));

        $alreadyAssigned = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->where('player_id', $player->id)
            ->exists();

        if ($alreadyAssigned) {
            return back()->with('error', 'flash.player_already_subscribed');
        }

        if (! $subscription->appliesTo($player, anyCategory: $request->boolean('outside_category'))) {
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

    /**
     * The players on a subscription, A→Z by full name (lastname first), as the
     * page lists them. A search narrows them to the matching players.
     *
     * @return Collection<int, PlayerSubscription>
     */
    private function assignedPlayers(Subscription $subscription, ?string $search = null): Collection
    {
        return $this->assignedQuery($subscription, $search)
            ->get()
            ->sortBy(fn (PlayerSubscription $ps) => $ps->player?->fullname, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** @return Builder<PlayerSubscription> */
    private function assignedQuery(Subscription $subscription, ?string $search = null): Builder
    {
        return PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->when($search, fn ($query) => $query->whereHas('player', fn ($player) => $player->search($search)))
            // Only what the page and the export show: the full player row (contact,
            // health…) of every assignee made this page seconds slow and megabytes big.
            ->with([
                'player' => fn ($player) => $player->select(['id', 'membership_id', 'category_id', 'status_id', 'left_at', ...Player::NAME_COLUMNS]),
                'player.category',
                'player.registrations.category',
            ]);
    }

    public function export(Request $request, Subscription $subscription): SymfonyResponse
    {
        [$listed, $filter] = $this->listedAssignees($request, $subscription);
        $playerSubscriptions = $listed->pluck('ps');

        // The tab label the page uses (t(tab)).
        $title = $subscription->designation.' ('.UiLang::get($filter, ucfirst($filter)).')';
        $headers = ['#', ...array_map(fn (string $key) => UiLang::get($key), [
            'col.membership_id', 'col.player_name', 'col.category', 'col.amount_owed', 'col.amount_paid', 'col.status',
        ])];

        $rows = [];
        $rowNum = 1;
        foreach ($listed as ['ps' => $ps, 'shown' => $shown]) {
            $player = $ps->player;
            $name = $player ? $player->fullname : 'N/A';

            $rows[] = [
                $rowNum++,
                $shown['membership_id'] !== null ? (string) $shown['membership_id'] : '-',
                $name,
                $shown['category']?->localized_name ?? '-',
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

    /**
     * The listed assignees as an A4 sheet, laid out like the players list:
     * the branch logo (the club's when the rows span several branches or
     * none), the Arabic club name, then the subscription and its figures.
     */
    public function printList(Request $request, Subscription $subscription, PdfService $pdf): SymfonyResponse
    {
        [$listed, $filter] = $this->listedAssignees($request, $subscription);

        $branchIds = $listed->map(fn (array $item) => $item['shown']['category']?->branch_id)->filter()->unique();
        $branch = $branchIds->count() === 1 ? Branch::find($branchIds->first()) : null;

        $categoryIds = ListFilter::ids($request, 'category_id');
        $categories = $categoryIds === [] ? [] : Category::whereIn('id', $categoryIds)->orderBy('name')->get()
            ->map(fn (Category $c) => $c->localized_name ?: $c->name)->all();

        $rows = $listed->map(function (array $item) {
            $money = $item['ps']->money();
            $player = $item['ps']->player;

            return [
                'membership_id' => $item['shown']['membership_id'],
                // Player::fullname, minus the nickname, like the players list.
                'name' => $player === null ? '-' : Player::fullnameOf($player->lastname, $player->firstname, null, $player->father, $player->grandfather, $player->gender),
                'owed' => (float) $item['ps']->amount_owed,
                'paid' => (float) $item['ps']->amount_paid,
                'remaining' => (float) $money['remaining'],
                'status' => $money['status'],
            ];
        });

        $counts = array_count_values($rows->pluck('status')->all());
        $total = $rows->count();
        $paid = ($counts['paid'] ?? 0) + ($counts['exempt'] ?? 0);

        $club = ClubHeader::data();
        $config = WebsiteConfig::singleton();
        // The header always reads the Arabic club name, whatever the app language.
        $names = ClubIdentity::name($config);

        $html = view('pdf.subscription-list', [
            'clubName' => $names['ar'] ?? reset($names),
            'shortName' => trim((string) $config->club_short_name),
            'logo' => Media::localFile($branch?->logo_url) ?? $club['logo'],
            'subscription' => $subscription->designation,
            'currency' => $club['currency'] ?? null,
            'tab' => $filter === 'all' ? null : $filter,
            'categories' => $categories,
            'stats' => [
                'total' => $total,
                'paid' => $paid,
                'partial' => $counts['partial'] ?? 0,
                'unpaid' => $counts['unpaid'] ?? 0,
                'percent' => $total > 0 ? round($paid / $total * 100, 1) : 0,
            ],
            'rows' => $rows,
            'printedOn' => now()->format('d/m/Y'),
        ])->render();

        return $pdf->stream($html, 'subscription-'.$subscription->id.'-'.now()->format('Y-m-d').'.pdf', app()->getLocale() === 'ar');
    }

    /**
     * The assignee list as the page shows it, A→Z: the search, the tab
     * (all | paid | unpaid | partial), and the categories and player statuses
     * checked in its filters. Each item pairs the obligation with the values
     * the page shows for its player (that branch's registration, else the
     * main one).
     *
     * @return array{0: Collection<int, array{ps: PlayerSubscription, shown: array{membership_id: ?string, category: ?Category, status_id: ?int}}>, 1: string}
     */
    private function listedAssignees(Request $request, Subscription $subscription): array
    {
        $search = trim((string) $request->query('search', ''));

        // all | paid | unpaid | partial; anything else (an array, a path…) → all.
        $filter = $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'paid', 'unpaid', 'partial'], true)) {
            $filter = 'all';
        }

        $categories = ListFilter::get($request, 'category_id', 'ids|none');
        $statuses = ListFilter::get($request, 'status_id', 'ids|none');
        $display = RegistrationDisplay::make();
        $branchId = $this->pageBranch($subscription);
        $none = ['membership_id' => null, 'category' => null, 'status_id' => null];

        $listed = $this->assignedPlayers($subscription, $search !== '' ? $search : null)
            ->filter(fn (PlayerSubscription $ps) => match ($filter) {
                'paid' => in_array($ps->payment_status, ['paid', 'exempt'], true),
                'unpaid' => $ps->payment_status === 'unpaid',
                'partial' => $ps->payment_status === 'partial',
                default => true,
            })
            ->map(fn (PlayerSubscription $ps) => [
                'ps' => $ps,
                'shown' => $ps->player ? $this->shownValues($ps->player, $branchId, $display) : $none,
            ])
            ->filter(fn (array $item) => self::checked($categories, $item['shown']['category']?->id)
                && self::checked($statuses, $item['shown']['status_id']))
            ->values();

        return [$listed, $filter];
    }

    /**
     * Whether a value passes a multi-select: nothing checked, its id checked,
     * or "none" checked for a row without one.
     *
     * @param  list<string>  $checked
     */
    private static function checked(array $checked, ?int $id): bool
    {
        return $checked === [] || in_array($id === null ? 'none' : (string) $id, $checked, true);
    }
}
