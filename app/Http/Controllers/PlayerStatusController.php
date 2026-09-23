<?php

namespace App\Http\Controllers;

use App\Models\PlayerStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayerStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/PlayerStatuses', [
            // withCount so the UI can warn before an in-use status is deleted.
            'playerStatuses' => PlayerStatus::withCount('players')
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PlayerStatus::create($this->validated($request));

        return back()->with('success', 'flash.player_status_created');
    }

    public function update(Request $request, PlayerStatus $playerStatus): RedirectResponse
    {
        $playerStatus->update($this->validated($request, $playerStatus));

        return back()->with('success', 'flash.player_status_updated');
    }

    public function destroy(PlayerStatus $playerStatus): RedirectResponse
    {
        // Players would be left with a dangling status. The FK is nullOnDelete,
        // so this would silently blank their status rather than error.
        if ($playerStatus->players()->exists()) {
            return back()->with('error', 'flash.player_status_in_use');
        }

        $playerStatus->delete();

        return back()->with('success', 'flash.player_status_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PlayerStatus $existing = null): array
    {
        $unique = 'unique:player_statuses,name'.($existing ? ','.$existing->id : '');

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
