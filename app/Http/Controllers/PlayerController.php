<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Requests\Player\UpdatePlayerRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerEmergencyContact;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Export\ExcelExporter;
use App\Services\Player\MembershipNumber;
use App\Services\Player\RegisterPlayerService;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Player::query()
            ->select('players.*')
            ->selectRaw('players.outstanding_debt as total_debt')
            ->with(['category', 'position', 'memberJob']);

        $this->applyPlayerFilters($query, $request);

        $players = $query->orderBy('lastname')->orderBy('firstname')
            ->paginate(25)
            ->withQueryString();

        // Category distribution over active players (stat chips above the list).
        // Prefer the current-locale name, falling back to the base name.
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $localeCol = 'categories.name_'.$locale;
        $categoryStats = \Illuminate\Support\Facades\DB::table('players')
            ->leftJoin('categories', 'categories.id', '=', 'players.category_id')
            ->where('players.archived', false)
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

        // Status distribution over active players, from the lookup so the
        // label follows the interface language.
        $statusCol = 'player_statuses.name_'.$locale;
        $statusStats = \Illuminate\Support\Facades\DB::table('players')
            ->leftJoin('player_statuses', 'player_statuses.id', '=', 'players.status_id')
            ->where('players.archived', false)
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

        // Position distribution over active players.
        $positionStats = \Illuminate\Support\Facades\DB::table('players')
            ->leftJoin('positions', 'positions.id', '=', 'players.position_id')
            ->where('players.archived', false)
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

        // Age distribution bucketed by decade (bucket keys mirror the `age` filter).
        $ageBuckets = ['u10' => 0, '10-19' => 0, '20-29' => 0, '30-39' => 0, '40+' => 0, 'unknown' => 0];
        Player::where('archived', false)->get(['birthdate'])->each(function (Player $p) use (&$ageBuckets) {
            if (! $p->birthdate) {
                $ageBuckets['unknown']++;

                return;
            }
            $age = $p->birthdate->age;
            $key = $age < 10 ? 'u10' : ($age < 20 ? '10-19' : ($age < 30 ? '20-29' : ($age < 40 ? '30-39' : '40+')));
            $ageBuckets[$key]++;
        });
        $ageStats = collect($ageBuckets)
            ->filter(fn ($count) => $count > 0)
            ->map(fn ($count, $bucket) => ['bucket' => $bucket, 'count' => $count])
            ->values();

        return Inertia::render('Players/Index', [
            'players' => $players,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'branches' => Branch::orderBy('name')->get(),
            'playerStatuses' => PlayerStatus::orderBy('sort_order')->get(),
            'categoryStats' => $categoryStats,
            'statusStats' => $statusStats,
            'positionStats' => $positionStats,
            'ageStats' => $ageStats,
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'archived']),
        ]);
    }

    public function show(Player $player): Response
    {
        $player->load([
            'category',
            'position',
            'memberJob',
            'branches',
            'emergencyContacts',
            'achievements',
            'playerSubscriptions.subscription',
            'playerSubscriptions.transaction',
            'playerSubscriptions.payments',
            'equipmentRentals.equipmentItem.catalog',
        ]);

        $transactions = Transaction::query()
            ->where('related_entity_type', 'Player')
            ->where('related_entity_id', $player->id)
            ->where('archived', false)
            ->orderByDesc('transaction_date')
            ->get();

        return Inertia::render('Players/Show', [
            'player' => $player,
            'transactions' => $transactions,
            'availableSubscriptions' => $this->availableSubscriptions($player),
            'totalDebt' => $player->calculateTotalDebt(),
        ]);
    }

    /**
     * The full subscription catalog with this player's status for each, so the
     * add-payment form can list every subscription (mandatory and optional),
     * whether or not the player is already assigned to it.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function availableSubscriptions(Player $player): \Illuminate\Support\Collection
    {
        $assigned = $player->playerSubscriptions->keyBy('subscription_id');

        return Subscription::query()
            ->orderByDesc('year')
            ->orderBy('name')
            ->get()
            ->map(function (Subscription $s) use ($player, $assigned) {
                $ps = $assigned->get($s->id);
                $owed = $player->is_student ? (float) $s->amount_student : (float) $s->amount_worker;

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
                ];
            })
            ->values();
    }

    /**
     * Algeria wilayas (bilingual) + communes keyed by wilaya id, from the seed json.
     *
     * @return array{wilayas: array<int, array{id:int,name:string,ar_name:string}>, communes: array<int|string, array<int,string>>}
     */
    private function algeriaGeo(): array
    {
        $data = json_decode(File::get(database_path('seeders/algeria_wilayas.json')), true);

        $wilayas = collect($data['states'] ?? [])
            ->map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'ar_name' => $s['ar_name']])
            ->values()->all();

        return ['wilayas' => $wilayas, 'communes' => $data['communes'] ?? []];
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
        unset($attributes['emergency_contacts'], $attributes['picture'], $attributes['branch_ids']);

        if ($request->hasFile('picture')) {
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $attributes['picture_url'] = $stored['url'];
            $attributes['picture_filename'] = $stored['filename'];
        }

        $player = $service->handle($attributes, $request->user()?->id);

        $player->branches()->sync($branchIds);

        foreach ($emergencyContacts as $contact) {
            $player->emergencyContacts()->create($contact);
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'flash.player_created');
    }

    public function edit(Player $player): Response
    {
        $player->load(['emergencyContacts', 'branches']);
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
        $branchIds = $validated['branch_ids'] ?? null;
        unset($validated['emergency_contacts'], $validated['picture'], $validated['branch_ids']);

        if ($request->hasFile('picture')) {
            $files->delete($player->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $validated['picture_url'] = $stored['url'];
            $validated['picture_filename'] = $stored['filename'];
        }

        // Membership id encodes the enrollment year (YYYYNNNNN). If the year changes,
        // regenerate the id for the new year so the two stay consistent.
        if (array_key_exists('join_year', $validated)
            && (int) $validated['join_year'] !== (int) $player->join_year) {
            $validated['membership_id'] = MembershipNumber::generateUnique((int) $validated['join_year']);
        }

        $player->update($validated);

        if ($branchIds !== null) {
            $player->branches()->sync($branchIds);
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

    public function bulkForceDelete(Request $request, FileStorageService $files): RedirectResponse
    {
        $ids = $this->validatedIds($request);
        Player::whereIn('id', $ids)->get()->each(fn (Player $p) => $this->permanentlyDelete($p, $files));

        return back()->with('success', ['key' => 'flash.players_deleted', 'params' => ['count' => count($ids)]]);
    }

    public function export(Request $request, ExcelExporter $exporter): StreamedResponse
    {
        $query = Player::query()->with(['category', 'position', 'branches', 'status']);
        $this->applyPlayerFilters($query, $request);

        $rows = $query->orderBy('lastname')->orderBy('firstname')->get()->map(fn (Player $p) => [
            $p->membership_id,
            $p->fullname,
            $p->category?->localized_name,
            $p->status?->localized_name,
            $p->is_student ? 'student' : 'worker',
            $p->join_year,
            (string) $p->outstanding_debt,
            collect($p->phones ?? [])->implode(' / '),
            $p->branches->map(fn (Branch $b) => $b->localized_name)->implode(' / '),
        ]);

        $headers = ['membership_id', 'name', 'category', 'status', 'type', 'join_year', 'debt', 'phones', 'branches'];

        return $exporter->download('Players', $headers, $rows->all(), 'players-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Name search across every part of a player's name.
     *
     * The full name is spread over several columns (lastname firstname (nickname)
     * بن father grandfather), so matching the raw term against single columns fails
     * for anything but one word. Instead each whitespace-separated token must match
     * SOME name column (AND across tokens, OR across columns). That makes the search
     * order-independent and works with a full name, a partial one, and with or
     * without the بن connector — while still requiring all tokens to land on the
     * same player.
     */
    private function applySearch($query, string $search): void
    {
        $columns = ['firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id'];

        // بن is a connector in the rendered full name, not part of any column.
        $tokens = collect(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->reject(fn ($token) => $token === 'بن')
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $query->where(function ($outer) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $outer->where(function ($inner) use ($token, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', '%'.$token.'%');
                    }
                });
            }
        });
    }

    /**
     * Apply the shared list filters (used by index + export).
     */
    private function applyPlayerFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $this->applySearch($query, (string) $request->input('search'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status_id', $request->input('status'));
        }

        if ($request->filled('position_id')) {
            $query->where('position_id', $request->input('position_id'));
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $request->input('branch_id')));
        }

        if ($request->filled('age')) {
            $today = \Carbon\Carbon::today();
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
        \Illuminate\Support\Facades\DB::transaction(function () use ($player) {
            Transaction::where('related_entity_type', 'Player')
                ->where('related_entity_id', $player->id)
                ->update(['archived' => true]);

            $player->emergencyContacts()->delete();
            $player->achievements()->delete();
            $player->playerSubscriptions()->delete();
            $player->equipmentRentals()->delete();
            $player->delete();
        });

        $files->delete($player->picture_filename);
    }
}
