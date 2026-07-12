<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::query()
            ->withCount('players')
            ->with('players:id')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Branches', [
            'branches' => $branches,
            // For assigning members directly from the branches menu.
            'players' => Player::where('archived', false)->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    public function syncPlayers(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
        ]);

        $branch->players()->sync($validated['player_ids']);

        return back()->with('success', 'Branch members updated.');
    }

    public function store(Request $request): RedirectResponse
    {
        Branch::create($this->validated($request));

        return back()->with('success', 'Branch created successfully.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $branch->update($this->validated($request, $branch->id));

        return back()->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $branch->delete();

        return back()->with('success', 'Branch deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:branches,name'.($ignoreId ? ','.$ignoreId : '')],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
