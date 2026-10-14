<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;

/** A competition's squad: the roster players entered for it. */
final class CompetitionSquad
{
    /**
     * Takes $playerIds out of the competition's squad. One who was called up
     * in a played match of this competition is hidden rather than deleted,
     * so their matches keep pointing at a squad member.
     *
     * @param  list<int>  $playerIds
     */
    public static function remove(int $competitionId, array $playerIds): void
    {
        if ($playerIds === []) {
            return;
        }
        $played = GamePlayer::query()->whereIn('player_id', $playerIds)->where('called_up', true)
            ->whereHas('game', fn ($q) => $q->where('competition_id', $competitionId)->where('status', GameStatus::Played->value))
            ->pluck('player_id')->map(fn ($id) => (int) $id)->all();

        $squad = fn () => DB::table('competition_players')->where('competition_id', $competitionId);
        $squad()->whereIn('player_id', array_values(array_diff($playerIds, $played)))->delete();
        $squad()->whereIn('player_id', $played)->update(['hidden' => true, 'updated_at' => now()]);
    }
}
