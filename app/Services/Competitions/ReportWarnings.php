<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;

/** Non-blocking report hints shown on the Report tab (i18n keys). */
final class ReportWarnings
{
    /** @return list<string> */
    public static function for(Game $game): array
    {
        if ($game->status !== GameStatus::Played) {
            return [];
        }
        $events = $game->events()->orderBy('id')->get(['type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id']);
        $roles = $game->players()->where('called_up', true)->get(['player_id', 'role'])
            ->mapWithKeys(fn (GamePlayer $gp) => [$gp->player_id => $gp->role === 'starter' ? 'starter' : 'bench'])->all();
        $warnings = [];

        if ($game->rule('scoring') === 'goals' && $events->where('type', 'goal')->count() !== (int) $game->our_score) {
            $warnings[] = 'comp.warn.goals_mismatch';
        }
        $limit = $game->rule('max_substitutions');
        if ($limit !== null && $events->where('type', 'substitution')->count() > (int) $limit) {
            $warnings[] = 'comp.warn.too_many_subs';
        }
        if (! $game->rule('rolling_subs') && ! in_array('starter', $roles, true)) {
            $warnings[] = 'comp.warn.no_starters';
        }
        if ($game->formation_id !== null && $game->players()->where('role', 'starter')->whereNull('position_id')->exists()) {
            $warnings[] = 'comp.warn.starter_without_position';
        }
        if (($game->shootout_us !== null || $game->shootout_them !== null) && $game->our_score !== $game->their_score) {
            $warnings[] = 'comp.warn.shootout_not_draw';
        }
        // The line-up (or call-up) changed after the report was saved: the stored
        // timeline is checked again against today's starters and bench.
        $stored = [
            'extra_time_played' => (bool) $game->extra_time_played,
            'events' => $events->map(fn (GameEvent $e) => $e->only(['type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id']))->all(),
            'players' => [],
            'mvp_player_id' => null,
        ];
        if ((new ReportValidator)->errors($game, $roles, $stored) !== []) {
            $warnings[] = 'comp.warn.timeline_out_of_sync';
        }

        return $warnings;
    }
}
