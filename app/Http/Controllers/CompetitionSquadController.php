<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\GamePlayer;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompetitionSquadController extends Controller
{
    /**
     * Makes the squad exactly the given roster players. A removed player who
     * already played in this competition is hidden rather than deleted, so
     * their matches keep pointing at a squad member.
     */
    public function update(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $roster = DB::table('team_players')
            ->where('team_id', $competition->team_id)->where('season_start_year', $competition->season_start_year)
            ->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'distinct', Rule::in($roster)],
        ]);
        $ids = array_map('intval', $data['player_ids']);

        DB::transaction(function () use ($competition, $ids) {
            $squad = fn () => DB::table('competition_players')->where('competition_id', $competition->id);
            $current = $squad()->pluck('player_id')->map(fn ($id) => (int) $id)->all();
            $removed = array_values(array_diff($current, $ids));
            $played = GamePlayer::query()->whereIn('player_id', $removed)->where('called_up', true)
                ->whereHas('game', fn ($q) => $q->where('competition_id', $competition->id)->where('status', GameStatus::Played->value))
                ->pluck('player_id')->map(fn ($id) => (int) $id)->all();

            $now = now();
            $squad()->whereIn('player_id', array_diff($removed, $played))->delete();
            $squad()->whereIn('player_id', $played)->update(['hidden' => true, 'updated_at' => $now]);
            $squad()->whereIn('player_id', $ids)->update(['hidden' => false, 'updated_at' => $now]);
            foreach (array_diff($ids, $current) as $id) {
                DB::table('competition_players')->insert([
                    'competition_id' => $competition->id, 'player_id' => $id, 'hidden' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return back()->with('success', 'flash.squad_saved');
    }
}
