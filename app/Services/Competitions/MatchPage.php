<?php

namespace App\Services\Competitions;

use App\Models\Formation;
use App\Models\Game;
use App\Models\GameEvent;
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
        $formations = $game->format->formations
            ->map(fn (Formation $f) => ['id' => $f->id, 'name' => $f->name, 'slots' => $f->slots])->values();
        if ($formations->isEmpty()) {
            // No formations for this format: one virtual shape (id null), named on screen via comp.formation_default.
            $formations->push(['id' => null, 'name' => 'default', 'slots' => DefaultFormation::slots((int) $game->rule('players_on_field'))]);
        }

        return [
            'formations' => $formations,
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

    /** @return array<string, mixed> ratings and notes appear here only — never in a PDF. */
    public function report(Game $game): array
    {
        $game->loadMissing(['players.player']);

        return [
            'rules' => [
                'periods_count' => (int) $game->rule('periods_count'),
                'period_label' => $game->rule('period_label'),
                'scoring' => $game->rule('scoring'),
                'has_extra_time' => (bool) $game->rule('has_extra_time'),
                'extra_time_minutes' => (int) $game->rule('extra_time_minutes', 0),
                'has_shootout' => (bool) $game->rule('has_shootout'),
                'rolling_subs' => (bool) $game->rule('rolling_subs'),
                'sanction_types' => $game->rule('sanction_types', []),
                'sport' => $game->rule('sport'),
                'length' => $game->matchLength(false),
                'untimed' => (int) $game->rule('period_minutes') === 0,
            ],
            'period_scores' => $game->period_scores ?? [],
            'extra_time_played' => (bool) $game->extra_time_played,
            'our_score' => $game->our_score,
            'their_score' => $game->their_score,
            'shootout_us' => $game->shootout_us,
            'shootout_them' => $game->shootout_them,
            'notes' => $game->notes,
            'status' => $game->status->value,
            'events' => $game->events()->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get()
                ->map(fn (GameEvent $e) => $e->only(['type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id']))->values(),
            'players' => $game->players->where('called_up', true)
                ->sortBy([fn ($a, $b) => ($a->role === 'starter' ? 0 : 1) <=> ($b->role === 'starter' ? 0 : 1), fn ($a, $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999)])
                ->map(fn (GamePlayer $gp) => [
                    'player_id' => $gp->player_id,
                    'name' => $gp->player->fullname,
                    'shirt_number' => $gp->shirt_number,
                    'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                    'played' => $gp->played,
                    'minutes_played' => $gp->minutes_played,
                    'sets_played' => $gp->sets_played,
                    'rating' => $gp->rating,
                    'coach_note' => $gp->coach_note,
                    'is_mvp' => $gp->is_mvp,
                ])->values(),
            'warnings' => ReportWarnings::for($game),
        ];
    }
}
