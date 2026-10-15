<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;

/**
 * Stores minutes played and appearances for a match from its line-up and
 * timeline. Rolling-substitution formats are skipped: there the coach types
 * minutes and ticks "played" in the report grid.
 */
final class GameMinutes
{
    public function refresh(Game $game): void
    {
        if ($game->rule('rolling_subs')) {
            return;
        }
        $players = $game->players()->where('called_up', true)->get();
        $starters = $players->where('role', 'starter')->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $events = $game->events()->get()->map(fn (GameEvent $e) => [
            'type' => $e->type,
            'minute' => $e->minute,
            'player_id' => $e->player_id,
            'related_player_id' => $e->related_player_id,
            'ends_play' => $e->type === 'sanction' && $game->endsPlay($e->subtype),
        ])->all();

        $result = MinutesCalculator::compute($starters, $events, $game->matchLength());
        $appeared = array_flip($result['appeared']);

        $players->each(fn (GamePlayer $gp) => $gp->update([
            'minutes_played' => $result['minutes'][$gp->player_id] ?? 0,
            'played' => isset($appeared[$gp->player_id]),
        ]));
    }
}
