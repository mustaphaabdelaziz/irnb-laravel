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

        if ($request->has('archived')) {
            $query->where('archived', $request->boolean('archived'));
        } else {
            $query->where('archived', false);
        }

        $players = $query->orderBy('lastname')->orderBy('firstname')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Players/Index', [
            'players' => $players,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'category_id', 'archived']),
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
