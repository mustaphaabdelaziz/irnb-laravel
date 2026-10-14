<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may be called up for a game: the competition's visible squad, or the
 * team's season roster for a friendly. Each player carries the roster's
 * shirt number; ordered by number, then name.
 */
final class CallUpCandidates
{
    /** @return Collection<int, Player> */
    public function for(Game $game): Collection
    {
        $shirts = DB::table('team_players')
            ->where('team_id', $game->team_id)->where('season_start_year', $game->season_start_year)
            ->pluck('shirt_number', 'player_id');
        $ids = $game->competition_id !== null
            ? DB::table('competition_players')->where('competition_id', $game->competition_id)->where('hidden', false)->pluck('player_id')
            : $shirts->keys();

        return Player::query()->whereIn('id', $ids)->with('category')->get()
            ->each(fn (Player $p) => $p->setAttribute('shirt_number', ($n = $shirts[$p->id] ?? null) === null ? null : (int) $n))
            ->sortBy([
                fn (Player $a, Player $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (Player $a, Player $b) => strcmp($a->fullname, $b->fullname),
            ])->values();
    }
}
