<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;

/** Non-blocking report hints shown on the Report tab (i18n keys). */
final class ReportWarnings
{
    /** @return list<string> */
    public static function for(Game $game): array
    {
        if ($game->status !== GameStatus::Played) {
            return [];
        }
        $events = $game->events()->get(['type', 'subtype']);
        $warnings = [];

        if ($game->rule('scoring') === 'goals' && $events->where('type', 'goal')->count() !== (int) $game->our_score) {
            $warnings[] = 'comp.warn.goals_mismatch';
        }
        $limit = $game->rule('max_substitutions');
        if ($limit !== null && $events->where('type', 'substitution')->count() > (int) $limit) {
            $warnings[] = 'comp.warn.too_many_subs';
        }
        if ($game->formation_id !== null && $game->players()->where('role', 'starter')->whereNull('position_id')->exists()) {
            $warnings[] = 'comp.warn.starter_without_position';
        }
        if (($game->shootout_us !== null || $game->shootout_them !== null) && $game->our_score !== $game->their_score) {
            $warnings[] = 'comp.warn.shootout_not_draw';
        }

        return $warnings;
    }
}
