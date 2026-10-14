<?php

namespace App\Services\Competitions;

use App\Models\Formation;
use App\Models\Game;
use App\Models\GamePlayer;

/** The match page's line-up (and, from the report task, report) props. */
final class MatchPage
{
    public function __construct(private readonly PlayerFlags $flags) {}

    /** @return array<string, mixed> */
    public function lineup(Game $game): array
    {
        $game->loadMissing(['format.formations', 'players.player.position']);
        $injured = $this->flags->injured();

        return [
            'formations' => $game->format->formations
                ->map(fn (Formation $f) => ['id' => $f->id, 'name' => $f->name, 'slots' => $f->slots])->values(),
            'formation_id' => $game->formation_id,
            'has_goalkeeper' => $game->hasGoalkeeper(),
            'players_on_field' => (int) $game->rule('players_on_field'),
            'max_bench' => (int) $game->rule('max_bench'),
            'sport' => $game->rule('sport'),
            'players' => $game->players->where('called_up', true)
                ->sortBy(fn (GamePlayer $gp) => $gp->shirt_number ?? 999)
                ->map(fn (GamePlayer $gp) => [
                    'player_id' => $gp->player_id,
                    'name' => $gp->player->fullname,
                    'shirt_number' => $gp->shirt_number,
                    'usual_position' => $gp->player->position?->abbreviation,
                    'injured' => isset($injured[$gp->player_id]),
                    'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                    'slot' => $gp->slot,
                    'pos_x' => $gp->pos_x,
                    'pos_y' => $gp->pos_y,
                    'is_captain' => $gp->is_captain,
                    'is_goalkeeper' => $gp->is_goalkeeper,
                ])->values(),
        ];
    }
}
