<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Requests\Player\UpdatePlayerRequest;
use App\Models\Category;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerEmergencyContact;
use App\Models\Position;
use App\Services\Player\RegisterPlayerService;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function index(Request $request): Response
    {
        // Outstanding debt per player as a correlated subquery (avoids N+1 / per-row accessors).
        $debtSubquery = <<<'SQL'
            (SELECT COALESCE(SUM(
                CASE
                    WHEN t.category = 'donation' THEN 0
                    WHEN ps.amount_owed > ps.amount_paid THEN ps.amount_owed - ps.amount_paid
                    ELSE 0
                END
            ), 0)
            FROM player_subscriptions ps
            LEFT JOIN transactions t ON t.id = ps.transaction_id
            WHERE ps.player_id = players.id)
        SQL;

        $query = Player::query()
            ->select('players.*')
            ->selectRaw("$debtSubquery as total_debt")
            ->with(['category', 'position', 'memberJob']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhere('membership_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status_value', $request->input('status'));
        }

        if ($request->filled('position_id')) {
            $query->where('position_id', $request->input('position_id'));
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

        $players = $query->orderBy('lastname')->orderBy('firstname')
            ->paginate(25)
            ->withQueryString();

        // Category distribution over active players (stat chips above the list).
        $categoryStats = \Illuminate\Support\Facades\DB::table('players')
            ->leftJoin('categories', 'categories.id', '=', 'players.category_id')
            ->where('players.archived', false)
            ->groupBy('players.category_id', 'categories.name')
            ->selectRaw('players.category_id, categories.name, COUNT(*) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
            ->values();

        // Status distribution (status_value, e.g. منخرط / معتزل) over active players.
        $statusStats = \Illuminate\Support\Facades\DB::table('players')
            ->where('archived', false)
            ->groupBy('status_value')
            ->selectRaw('status_value, COUNT(*) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status_value,
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
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'categoryStats' => $categoryStats,
            'statusStats' => $statusStats,
            'positionStats' => $positionStats,
            'ageStats' => $ageStats,
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'age', 'archived']),
        ]);
    }

    public function show(Player $player): Response
    {
        $player->load([
            'category',
            'position',
            'memberJob',
            'emergencyContacts',
            'achievements',
            'playerSubscriptions.subscription',
            'playerSubscriptions.transaction',
            'equipmentRentals.equipmentItem.catalog',
        ]);

        return Inertia::render('Players/Show', [
            'player' => $player,
            'totalDebt' => $player->calculateTotalDebt(),
        ]);
    }

    private function getAlgeriaStates(): array
    {
        $data = json_decode(File::get(database_path('seeders/algeria_wilayas.json')), true);

        return collect($data['states'])->pluck('name')->toArray();
    }

    public function create(): Response
    {
        return Inertia::render('Players/Create', [
            'categories' => Category::orderBy('name')->get(),
            'positions' => Position::orderBy('name')->get(),
            'jobs' => MemberJob::orderBy('name')->get(),
            'states' => $this->getAlgeriaStates(),
        ]);
    }

    public function store(StorePlayerRequest $request, RegisterPlayerService $service, FileStorageService $files): RedirectResponse
    {
        $attributes = $request->validated();
        $emergencyContacts = $attributes['emergency_contacts'] ?? [];
        unset($attributes['emergency_contacts'], $attributes['picture']);

        if ($request->hasFile('picture')) {
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $attributes['picture_url'] = $stored['url'];
            $attributes['picture_filename'] = $stored['filename'];
        }

        $player = $service->handle($attributes, $request->user()?->id);

        foreach ($emergencyContacts as $contact) {
            $player->emergencyContacts()->create($contact);
        }

        return redirect()->route('players.show', $player)
            ->with('success', 'Player created successfully.');
    }

    public function edit(Player $player): Response
    {
        $player->load(['emergencyContacts']);

        return Inertia::render('Players/Edit', [
            'player' => $player,
            'categories' => Category::orderBy('name')->get(),
            'positions' => Position::orderBy('name')->get(),
            'jobs' => MemberJob::orderBy('name')->get(),
            'states' => $this->getAlgeriaStates(),
        ]);
    }

    public function update(UpdatePlayerRequest $request, Player $player, FileStorageService $files): RedirectResponse
    {
        $validated = $request->validated();

        $emergencyContacts = $validated['emergency_contacts'] ?? null;
        unset($validated['emergency_contacts'], $validated['picture']);

        if ($request->hasFile('picture')) {
            $files->delete($player->picture_filename);
            $stored = $files->storeImage($request->file('picture'), 'players', 512);
            $validated['picture_url'] = $stored['url'];
            $validated['picture_filename'] = $stored['filename'];
        }

        $player->update($validated);

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
            ->with('success', 'Player updated successfully.');
    }

    public function destroy(Player $player): RedirectResponse
    {
        $player->update(['archived' => true]);

        return redirect()->route('players.index')
            ->with('success', 'Player archived successfully.');
    }
}
