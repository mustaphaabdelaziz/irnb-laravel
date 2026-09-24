<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\BulkUpdatePlayersRequest;
use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Requests\Player\UpdatePlayerRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CountryState;
use App\Models\DocumentType;
use App\Models\FinanceAccount;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerEmergencyContact;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Dashboard\ModuleStats;
use App\Services\Export\ExcelExporter;
use App\Services\Finance\DefaultRegisterResolver;
use App\Services\Player\DocumentChecklist;
use App\Services\Player\FileNumber;
use App\Services\Player\MembershipNumber;
use App\Services\Player\PlayerDocumentService;
use App\Services\Player\RegisterPlayerService;
use App\Services\Storage\FileStorageService;
use App\Support\TransactionTitle;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function index(Request $request): Response
    {
        // Missing documents per row, computed in SQL — the twin of the player
        // page's checklist (DocumentChecklist; the two are tested to agree).
        [$missingSql, $missingBindings] = DocumentChecklist::missingCountSql();

        $query = Player::query()
            ->select('players.*')
            ->selectRaw('players.outstanding_debt as total_debt')
            ->selectRaw("{$missingSql} as missing_documents_count", $missingBindings)
            ->with(['category', 'position', 'otherPositions', 'memberJob', 'status', 'wilaya']);

        $this->applyPlayerFilters($query, $request);

        $players = $query->orderBy('lastname')->orderBy('firstname')
            ->paginate(25)
            ->withQueryString();

        // Prefer the current-locale name, falling back to the base name.
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $localeCol = 'categories.name_'.$locale;

        // Every chart describes the same population the list is showing, but
        // each one ignores its OWN filter — otherwise picking a category
        // collapses the category chart to a single 100% slice and the user can
        // no longer switch category by clicking it.
        $categoryStats = $this->statsQuery($request, 'category_id')
            ->leftJoin('categories', 'categories.id', '=', 'players.category_id')
            ->groupBy('players.category_id', 'categories.name', $localeCol)
            ->selectRaw("players.category_id, COALESCE(NULLIF({$localeCol}, ''), categories.name) as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
            ->values();

        $statusCol = 'player_statuses.name_'.$locale;
        $statusStats = $this->statsQuery($request, 'status')
            ->leftJoin('player_statuses', 'player_statuses.id', '=', 'players.status_id')
            ->groupBy('players.status_id', 'player_statuses.name', $statusCol)
            ->selectRaw("players.status_id, COALESCE(NULLIF({$statusCol}, ''), player_statuses.name) as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status_id' => $row->status_id,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
            ->values();

        $positionStats = $this->statsQuery($request, 'position_id')
            ->leftJoin('positions', 'positions.id', '=', 'players.position_id')
            ->groupBy('players.position_id', 'positions.name')
            ->selectRaw('players.position_id, positions.name, COUNT(*) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'position_id' => $row->position_id,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
            ->values();

        // Age buckets in SQL. This previously loaded every player's birthdate
        // into PHP and counted them in a loop on each request.
        $ageStats = $this->ageStats($request);

        // Lookup lists are closures: the page's filter reloads ask only for the
        // filter-dependent props, so these queries are skipped on every search.
        return Inertia::render('Players/Index', [
            'players' => $players,
            // A closure like the lookups below: the strip is unfiltered, so a
            // filter reload should not pay to recompute it.
            'strip' => fn (): array => app(ModuleStats::class)->players(),
            'categories' => fn () => Category::orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'branches' => fn () => Branch::orderBy('name')->get(),
            // positions feeds the bulk-edit field picker as well as the filter.
            'positions' => fn () => Position::orderBy('name')->get(['id', 'name']),
            'playerStatuses' => fn () => PlayerStatus::orderBy('sort_order')->get(),
            // Rows with no `code` are stray legacy rows (see algeriaGeo()) and are
            // excluded so the filter never offers an uncoded wilaya.
            'wilayas' => fn () => CountryState::query()->whereNotNull('code')->orderBy('code')
                ->get(['id', 'code', 'name', 'name_fr', 'name_ar'])
                ->map(fn (CountryState $state) => [
                    'id' => $state->id,
                    'code' => $state->code,
                    'name' => $state->name_fr ?: $state->name,
                    'localized_name' => $state->localized_name,
                ]),
            // The "missing type X" options: only types that can be missing.
            'documentTypes' => fn () => DocumentType::query()
                ->where('is_active', true)
                ->where('is_required', true)
                ->ordered()
                ->get(['id', 'code', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categoryStats' => $categoryStats,
            'statusStats' => $statusStats,
            'positionStats' => $positionStats,
            'ageStats' => $ageStats,
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'archived', 'wilaya_id', 'documents']),
        ]);
    }

    public function show(Request $request, Player $player): Response
    {
        $player->load([
            'category',
            'wilaya',
            'position',
            'otherPositions',
            'memberJob',
            'status',
            'branches',
            'emergencyContacts',
            'achievements',
            'playerSubscriptions.subscription',
            'playerSubscriptions.transaction',
            'playerSubscriptions.payments',
            'equipmentRentals.equipmentItem.catalog',
        ]);

        // Instance-only append (not $appends on the model): is_overdue re-implements
        // EquipmentRental::getIsOverdueAttribute() so the page doesn't have to, without
        // making every serialized rental elsewhere carry the extra attribute.
        $player->equipmentRentals->each->append('is_overdue');

        $transactions = Transaction::query()
            ->with([...Transaction::FINANCE_ACCOUNT_LABEL, ...TransactionTitle::RELATIONS])
            ->where('related_entity_type', 'Player')
            ->where('related_entity_id', $player->id)
            ->where('archived', false)
            ->orderByDesc('transaction_date')
            ->get()
            ->each(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));

        $financeAccounts = FinanceAccount::selectable()->get();
        $registers = new DefaultRegisterResolver($financeAccounts);

        return Inertia::render('Players/Show', [
            'player' => $player,
            'transactions' => $transactions,
            'availableSubscriptions' => $this->availableSubscriptions($player, $registers),
            'totalDebt' => $player->calculateTotalDebt(),
            'financeAccounts' => $financeAccounts,
            'defaultFinanceAccountId' => $registers->forPlayer($player)?->id,
            'fileDrawerSize' => FileNumber::drawerSize(),
            // Owner decision: documents have their own permission. Without
            // documents/view the checklist is not even sent to the page.
            'documents' => $request->user()?->hasPermission('documents', 'view')
                ? DocumentChecklist::for($player)
                : null,
        ]);
    }

    /**
     * The subscription catalog this player may pay, with their status for each:
     * every subscription open to the player's category (mandatory and optional),
     * plus any other subscription they are already assigned to, so existing
     * debts stay payable.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function availableSubscriptions(Player $player, DefaultRegisterResolver $registers): Collection
    {
        $assigned = $player->playerSubscriptions->keyBy('subscription_id');

        return Subscription::query()
            ->where(fn ($query) => $query
                ->forCategory($player->category_id)
                ->orWhereIn('id', $assigned->keys()->filter()))
            ->with(['branches:id', 'categories:id'])
            ->orderByDesc('year')
            ->orderBy('name')
            ->get()
            ->map(function (Subscription $s) use ($player, $assigned, $registers) {
                $ps = $assigned->get($s->id);
                $owed = $s->amountFor($player);

                return [
                    'subscription_id' => $s->id,
                    'player_subscription_id' => $ps?->id,
                    'name' => $s->name,
                    'year' => $s->year,
                    'is_mandatory' => (bool) $s->is_mandatory,
                    'is_exempt' => (bool) ($ps?->is_exempt ?? false),
                    'amount_owed' => $ps ? (float) $ps->amount_owed : $owed,
                    'amount_paid' => $ps ? (float) $ps->amount_paid : 0.0,
                    'remaining_amount' => $ps ? (float) $ps->remaining_amount : $owed,
                    'default_finance_account_id' => $registers->forSubscription($s)?->id,
                ];
            })
            ->values();
    }

    /**
     * The wilaya list for the player form, from the table the migration owns,
     * plus the commune lists keyed by the SAME id the form submits.
     *
     * Rows with no `code` are stray/duplicate legacy rows the wilaya-sync
     * migration could not identify (see 2026_09_23_100003_official_wilayas.php)
     * — they are excluded so the form never offers an uncoded row.
     *
     * @return array{wilayas: array<int, array{id:int,code:?string,name:string,ar_name:?string,localized_name:string}>, communes: array<int, array<int,string>>}
     */
    private function algeriaGeo(): array
    {
        $states = CountryState::query()->whereNotNull('code')->orderBy('code')->get();

        $wilayas = $states->map(fn (CountryState $state) => [
            'id' => $state->id,
            'code' => $state->code,
            'name' => $state->name_fr ?: $state->name,
            'ar_name' => $state->name_ar ?: $state->ar_name,
            'localized_name' => $state->localized_name,
        ])->values()->all();

        // The commune file is keyed by the official wilaya number; the form works
        // in row ids, so translate the keys once here.
        $data = json_decode(File::get(database_path('seeders/algeria_wilayas.json')), true);
        $communes = [];

        foreach ($states as $state) {
            $list = $data['communes'][(string) $state->external_id] ?? [];
            if ($list !== []) {
                $communes[$state->id] = $list;
            }
        }

        return ['wilayas' => $wilayas, 'communes' => $communes];
    }

    /**
     * Next membership sequence per join year (years present in players + current year).
     *
     * @return array<int, int>
     */
    private function nextSequenceByYear(): array
    {
        $years = Player::query()->whereNotNull('join_year')->distinct()->pluck('join_year')
            ->push((int) now()->year)->unique();

        $map = [];
        foreach ($years as $year) {
            $map[(int) $year] = MembershipNumber::nextSequence((int) $year);
        }

        return $map;
    }

    public function create(): Response
    {
        $geo = $this->algeriaGeo();

        return Inertia::render('Players/Create', [
            'categories' => Category::orderBy('name')->get(),
            'positions' => Position::orderBy('name')->get(),
            'playerStatuses' => PlayerStatus::where('is_active', true)->orderBy('sort_order')->get(),
            'jobs' => MemberJob::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            'wilayas' => $geo['wilayas'],
            'communes' => $geo['communes'],
            'nextSequenceByYear' => $this->nextSequenceByYear(),
            'defaultJoinYear' => (int) now()->year,
        ]);
    }

    public function store(StorePlayerRequest $request, RegisterPlayerService $service, FileStorageService $files): RedirectResponse
    {
        $attributes = $request->validated();
        $emergencyContacts = $attributes['emergency_contacts'] ?? [];
        $branchIds = $attributes['branch_ids'] ?? [];
        $otherPositionIds = $attributes['other_position_ids'] ?? [];
        unset($attributes['emergency_contacts'], $attributes['picture'], $attributes['branch_ids'], $attributes['other_position_ids']);

        if ($request->hasFile('picture')) {
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $attributes['picture_url'] = $stored['url'];
            $attributes['picture_filename'] = $stored['filename'];
        }

        $player = $service->handle($attributes, $request->user()?->id);

        $player->branches()->sync($branchIds);
        $player->otherPositions()->sync($otherPositionIds);

        // The main position can never also sit in the "other positions" pivot.
        if ($player->position_id) {
            $player->otherPositions()->detach($player->position_id);
        }

        foreach ($emergencyContacts as $contact) {
            $player->emergencyContacts()->create($contact);
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.player_created');
    }

    public function edit(Player $player): Response
    {
        $player->load(['emergencyContacts', 'branches', 'otherPositions']);
        $geo = $this->algeriaGeo();

        return Inertia::render('Players/Edit', [
            'player' => $player,
            'categories' => Category::orderBy('name')->get(),
            'positions' => Position::orderBy('name')->get(),
            'playerStatuses' => PlayerStatus::where('is_active', true)->orderBy('sort_order')->get(),
            'jobs' => MemberJob::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            'wilayas' => $geo['wilayas'],
            'communes' => $geo['communes'],
            'nextSequenceByYear' => $this->nextSequenceByYear(),
            'defaultJoinYear' => (int) now()->year,
        ]);
    }

    public function update(UpdatePlayerRequest $request, Player $player, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();

        $emergencyContacts = $validated['emergency_contacts'] ?? null;
        // The key must be PRESENT (not just non-null) to trigger a sync: Inertia's
        // forceFormData conversion drops empty arrays entirely, so a form that
        // clears every branch (or every other position) sends the field as '' —
        // which ConvertEmptyStringsToNull turns into a present-but-null key.
        // Omitted entirely (e.g. another client that never touches this field)
        // must leave the existing branches/other positions untouched.
        $branchIdsPresent = array_key_exists('branch_ids', $validated);
        $branchIds = $validated['branch_ids'] ?? null;
        $otherPositionIdsPresent = array_key_exists('other_position_ids', $validated);
        $otherPositionIds = $validated['other_position_ids'] ?? null;
        unset($validated['emergency_contacts'], $validated['picture'], $validated['branch_ids'], $validated['other_position_ids']);

        if ($request->hasFile('picture')) {
            $files->delete($player->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $validated['picture_url'] = $stored['url'];
            $validated['picture_filename'] = $stored['filename'];
        }

        // The membership id is NOT regenerated when the join year changes: it is
        // printed on the member card and written on the paper folder. The year it
        // encodes is the year the member was first enrolled, which never changes.

        $player->update($validated);

        if ($branchIdsPresent) {
            $player->branches()->sync($branchIds ?? []);
        }

        if ($otherPositionIdsPresent) {
            $player->otherPositions()->sync($otherPositionIds ?? []);
        }

        // The main position can never also sit in the "other positions" pivot
        // (e.g. the main changed from MF to WG while WG was listed as "other").
        if ($player->position_id) {
            $player->otherPositions()->detach($player->position_id);
        }

        if ($emergencyContacts !== null) {
            $player->emergencyContacts()->delete();
            foreach ($emergencyContacts as $contact) {
                PlayerEmergencyContact::create([
                    'player_id' => $player->id,
                    ...$contact,
                ]);
            }
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.player_updated');
    }

    public function destroy(Player $player): RedirectResponse
    {
        $player->update(['archived' => true]);

        return redirect()->route('players.index')
            ->with('success', 'flash.player_archived');
    }

    public function restore(Player $player): RedirectResponse
    {
        $player->update(['archived' => false]);

        return back()->with('success', 'flash.player_restored');
    }

    public function forceDelete(Player $player, FileStorageService $files): RedirectResponse
    {
        $this->permanentlyDelete($player, $files);

        return redirect()->route('players.index', ['archived' => 1])
            ->with('success', 'flash.player_permanently_deleted');
    }

    public function bulkArchive(Request $request): RedirectResponse
    {
        $ids = $this->validatedIds($request);
        Player::whereIn('id', $ids)->update(['archived' => true]);

        return back()->with('success', ['key' => 'flash.players_archived', 'params' => ['count' => count($ids)]]);
    }

    public function bulkRestore(Request $request): RedirectResponse
    {
        $ids = $this->validatedIds($request);
        Player::whereIn('id', $ids)->update(['archived' => false]);

        return back()->with('success', ['key' => 'flash.players_restored', 'params' => ['count' => count($ids)]]);
    }

    /**
     * Set one field on many players at once.
     *
     * The field is an allow-list (BulkUpdatePlayersRequest::FIELDS) and the
     * value is validated against whichever field was named, so this cannot be
     * steered into rewriting an arbitrary column.
     */
    public function bulkUpdate(BulkUpdatePlayersRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $data['ids'];
        $field = $data['field'];
        $value = $data['value'] ?? null;

        DB::transaction(function () use ($ids, $field, $value, $data) {
            if ($field !== 'branches') {
                Player::whereIn('id', $ids)->update([$field => $value ?: null]);

                if ($field === 'position_id' && $value) {
                    // The main position can never also sit in the "other
                    // positions" pivot — e.g. a player whose "other" was
                    // already GK must not keep GK there once GK becomes main.
                    DB::table('player_other_positions')
                        ->whereIn('player_id', $ids)
                        ->where('position_id', $value)
                        ->delete();
                }

                return;
            }

            $branchIds = array_map('intval', (array) $value);
            $mode = $data['mode'] ?? 'replace';

            // Branches are many-to-many, so "set" is ambiguous: replace the
            // whole set, add to it, or remove from it.
            Player::whereIn('id', $ids)->get()->each(fn (Player $player) => match ($mode) {
                'attach' => $player->branches()->syncWithoutDetaching($branchIds),
                'detach' => $player->branches()->detach($branchIds),
                default => $player->branches()->sync($branchIds),
            });
        });

        return back()->with('success', [
            'key' => 'flash.players_updated',
            'params' => ['count' => count($ids)],
        ]);
    }

    public function bulkForceDelete(Request $request, FileStorageService $files): RedirectResponse
    {
        $ids = $this->validatedIds($request);
        Player::whereIn('id', $ids)->get()->each(fn (Player $p) => $this->permanentlyDelete($p, $files));

        return back()->with('success', ['key' => 'flash.players_deleted', 'params' => ['count' => count($ids)]]);
    }

    public function export(Request $request, ExcelExporter $exporter): StreamedResponse
    {
        $query = Player::query()->with(['category', 'position', 'otherPositions', 'branches', 'status', 'wilaya']);
        $this->applyPlayerFilters($query, $request);

        $rows = $query->orderBy('lastname')->orderBy('firstname')->get()->map(fn (Player $p) => [
            $p->membership_id,
            FileNumber::format($p->file_number),
            $p->wilaya?->localized_name,
            $p->position?->abbreviation,
            $p->otherPositions->pluck('abbreviation')->implode(', '),
            $p->fullname,
            $p->category?->localized_name,
            $p->status?->localized_name,
            $p->is_student ? 'student' : 'worker',
            $p->join_year,
            (string) $p->outstanding_debt,
            collect($p->phones ?? [])->implode(' / '),
            $p->branches->map(fn (Branch $b) => $b->localized_name)->implode(' / '),
        ]);

        $headers = ['membership_id', 'File number', 'Wilaya', 'Main position', 'Other positions', 'name', 'category', 'status', 'type', 'join_year', 'debt', 'phones', 'branches'];

        return $exporter->download('Players', $headers, $rows->all(), 'players-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Base query for a stat block: the same population the list is showing,
     * minus its own dimension.
     *
     * `$except` names the filter this chart drives, so the chart keeps
     * offering its other options. Without it, filtering by category leaves
     * the category chart with a single slice and nothing to click.
     */
    private function statsQuery(Request $request, ?string $except = null): Builder
    {
        $filters = $request->query();
        unset($filters[$except]);

        // A fresh Request carrying only the remaining filters, so
        // applyPlayerFilters stays the single definition of what each means.
        $scoped = new Request($filters);

        $query = Player::query();
        $this->applyPlayerFilters($query, $scoped);

        return $query->getQuery()->from('players');
    }

    /**
     * Age distribution bucketed by decade, in SQL. Bucket keys mirror the
     * `age` filter so a chip click round-trips.
     *
     * @return Collection<int, array{bucket:string,count:int}>
     */
    private function ageStats(Request $request): Collection
    {
        $today = Carbon::today();
        $at = fn (int $years) => $today->copy()->subYears($years)->toDateString();

        $bucket = "CASE
            WHEN players.birthdate IS NULL THEN 'unknown'
            WHEN players.birthdate > '{$at(10)}' THEN 'u10'
            WHEN players.birthdate > '{$at(20)}' THEN '10-19'
            WHEN players.birthdate > '{$at(30)}' THEN '20-29'
            WHEN players.birthdate > '{$at(40)}' THEN '30-39'
            ELSE '40+' END";

        $counts = $this->statsQuery($request, 'age')
            ->selectRaw("{$bucket} as bucket, COUNT(*) as total")
            ->groupBy(DB::raw($bucket))
            ->pluck('total', 'bucket');

        // Fixed order regardless of what the data happens to contain.
        return collect(['u10', '10-19', '20-29', '30-39', '40+', 'unknown'])
            ->map(fn ($key) => ['bucket' => $key, 'count' => (int) ($counts[$key] ?? 0)])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();
    }

    /**
     * Apply the shared list filters (used by index + export).
     */
    private function applyPlayerFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $query->search((string) $request->input('search'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status_id', $request->input('status'));
        }

        if ($request->filled('position_id')) {
            $positionId = (int) $request->input('position_id');

            // "Plays X" means the main position or one of the others. Wrapped in
            // its own closure so orWhereHas doesn't escape into an OR against
            // whatever other filters (category, branch, ...) are ANDed around it.
            $query->where(fn ($q) => $q->where('position_id', $positionId)
                ->orWhereHas('otherPositions', fn ($p) => $p->where('positions.id', $positionId)));
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $request->input('branch_id')));
        }

        if ($request->filled('wilaya_id')) {
            // "none" finds the players whose wilaya was never set or was "Unknown"
            // before the P2 migration (the list's filter drops empty values, so
            // "no wilaya" needs a value of its own).
            $request->input('wilaya_id') === 'none'
                ? $query->whereNull('wilaya_id')
                : $query->where('wilaya_id', $request->input('wilaya_id'));
        }

        if ($request->filled('documents')) {
            $this->applyDocumentsFilter($query, (string) $request->input('documents'));
        }

        if ($request->filled('age')) {
            $today = Carbon::today();
            match ($request->input('age')) {
                'unknown' => $query->whereNull('birthdate'),
                'u10' => $query->where('birthdate', '>', $today->copy()->subYears(10)),
                '10-19' => $query->where('birthdate', '<=', $today->copy()->subYears(10))->where('birthdate', '>', $today->copy()->subYears(20)),
                '20-29' => $query->where('birthdate', '<=', $today->copy()->subYears(20))->where('birthdate', '>', $today->copy()->subYears(30)),
                '30-39' => $query->where('birthdate', '<=', $today->copy()->subYears(30))->where('birthdate', '>', $today->copy()->subYears(40)),
                '40+' => $query->where('birthdate', '<=', $today->copy()->subYears(40)),
                default => null,
            };
        }

        if ($request->has('archived')) {
            $query->where('archived', $request->boolean('archived'));
        } else {
            $query->where('archived', false);
        }
    }

    /**
     * missing | expiring | missing-{typeId}. Uses the SQL twin of the
     * checklist, so the list, its charts and the export agree with the
     * player page. An unknown value filters nothing.
     */
    private function applyDocumentsFilter($query, string $filter): void
    {
        if ($filter === 'missing') {
            [$sql, $bindings] = DocumentChecklist::missingCountSql();
            $query->whereRaw("{$sql} > 0", $bindings);

            return;
        }

        if ($filter === 'expiring') {
            [$sql, $bindings] = DocumentChecklist::expiringSoonSql();
            $query->whereRaw($sql, $bindings);

            return;
        }

        if (preg_match('/^missing-(\d+)$/', $filter, $match)) {
            [$sql, $bindings] = DocumentChecklist::missingCountSql(null, (int) $match[1]);
            $query->whereRaw("{$sql} > 0", $bindings);
        }
    }

    /**
     * @return array<int, int>
     */
    private function validatedIds(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:players,id'],
        ])['ids'];
    }

    /**
     * Permanently remove a player and its owned records. Finance transactions are
     * archived (kept for audit), not deleted.
     */
    private function permanentlyDelete(Player $player, FileStorageService $files): void
    {
        $documents = app(PlayerDocumentService::class);

        DB::transaction(function () use ($player, $documents) {
            Transaction::where('related_entity_type', 'Player')
                ->where('related_entity_id', $player->id)
                ->update(['archived' => true]);

            $player->emergencyContacts()->delete();
            $player->achievements()->delete();
            $player->playerSubscriptions()->delete();
            $player->equipmentRentals()->delete();
            $documents->purgePlayerRecords($player);
            $player->delete();
        });

        $files->delete($player->picture_filename);
        // After the commit, so a deletion that rolled back never loses the scans.
        $documents->purgePlayerFiles($player->id);
    }
}
