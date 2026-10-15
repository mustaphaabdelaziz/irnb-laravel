<?php

namespace App\Services\Competitions;

use App\Models\Formation;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Pdf\ClubHeader;
use Carbon\CarbonImmutable;

/**
 * Data for the two printable match sheets. Ratings and coach notes are
 * deliberately never read here: the sheets get posted and shared.
 */
final class MatchSheets
{
    /** @return array<string, mixed> */
    public function lineup(Game $game): array
    {
        $game->loadMissing(['team.category', 'competition', 'opponent', 'venue', 'players.player']);
        $formation = $game->formation_id ? Formation::find($game->formation_id) : null;
        $called = $game->players->where('called_up', true);
        $starters = $called->where('role', 'starter')->sortBy('slot');
        // No formation: the spots come from the format's automatic shape, as on screen.
        $spots = $formation?->slots ?? DefaultFormation::slots((int) $game->rule('players_on_field'));

        return [
            'club' => ClubHeader::data(),
            'game' => $this->header($game),
            'pitch' => PitchDrawing::svg((string) $game->rule('sport'), $starters->map(fn (GamePlayer $gp) => [
                'x' => (int) ($gp->pos_x ?? ($gp->slot !== null ? $spots[$gp->slot]['x'] ?? null : null) ?? 50),
                'y' => (int) ($gp->pos_y ?? ($gp->slot !== null ? $spots[$gp->slot]['y'] ?? null : null) ?? 50),
                'label' => (string) ($gp->shirt_number ?? ''),
            ])->values()->all()),
            'formation' => $formation?->name,
            'starters' => $starters->map(fn (GamePlayer $gp) => [
                'shirt' => $gp->shirt_number,
                'name' => $gp->player->fullname,
                'position' => $formation?->slots[$gp->slot]['label'] ?? null,
                'captain' => $gp->is_captain,
                'goalkeeper' => $gp->is_goalkeeper,
            ])->values()->all(),
            'bench' => $called->where('role', '!=', 'starter')->sortBy(fn (GamePlayer $gp) => $gp->shirt_number ?? 999)
                ->map(fn (GamePlayer $gp) => ['shirt' => $gp->shirt_number, 'name' => $gp->player->fullname])->values()->all(),
            'coaches' => $this->coaches($game),
        ];
    }

    /** @return array<string, mixed> */
    public function report(Game $game): array
    {
        $game->loadMissing(['team.category', 'competition', 'opponent', 'venue', 'players.player']);
        $events = $game->events()->with(['player', 'relatedPlayer'])->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get();
        $name = fn (?object $player) => $player?->fullname;
        $called = $game->players->where('called_up', true);
        $goalsBy = $events->where('type', 'goal')->countBy('player_id');
        $cardsBy = $events->where('type', 'sanction')->groupBy('player_id')->map(fn ($list) => $list->pluck('subtype')->all());
        $sets = $game->rule('scoring') === 'sets';
        $regular = (int) $game->rule('periods_count');
        $periods = array_slice($game->period_scores ?? [], 0, $regular);

        return [
            'club' => ClubHeader::data(),
            'game' => $this->header($game),
            'sets' => $sets, // the player table's column: sets played (true) or minutes
            'score' => [
                'us' => $game->our_score,
                'them' => $game->their_score,
                'period_label' => $game->rule('period_label'),
                'periods' => $periods,
                'extra_time' => $game->extra_time_played ? (($game->period_scores ?? [])[$regular] ?? null) : null,
                'shootout' => $game->shootout_us !== null ? [$game->shootout_us, $game->shootout_them] : null,
            ],
            'goals' => $events->where('type', 'goal')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'kind' => $e->subtype, 'scorer' => $name($e->player), 'assist' => $name($e->relatedPlayer),
            ])->values()->all(),
            'sanctions' => $events->where('type', 'sanction')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'kind' => $e->subtype, 'player' => $name($e->player),
            ])->values()->all(),
            'subs' => $events->where('type', 'substitution')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'in' => $name($e->player), 'out' => $name($e->relatedPlayer),
            ])->values()->all(),
            'players' => $called->filter(fn (GamePlayer $gp) => $gp->played || $gp->role === 'starter')
                ->sortBy([fn ($a, $b) => ($a->role === 'starter' ? 0 : 1) <=> ($b->role === 'starter' ? 0 : 1), fn ($a, $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999)])
                ->map(fn (GamePlayer $gp) => [
                    'shirt' => $gp->shirt_number,
                    'name' => $gp->player->fullname,
                    'starter' => $gp->role === 'starter',
                    'minutes' => $sets ? null : $gp->minutes_played,
                    'sets' => $sets ? $gp->sets_played : null,
                    'goals' => $goalsBy[$gp->player_id] ?? 0,
                    'cards' => $cardsBy[$gp->player_id] ?? [],
                ])->values()->all(),
            'mvp' => $name($called->firstWhere('is_mvp', true)?->player),
            'coaches' => $this->coaches($game),
        ];
    }

    /** @return array<string, mixed> */
    private function header(Game $game): array
    {
        return [
            'team' => $game->team->name.' · '.$game->team->category?->localized_name,
            'opponent' => $game->opponent->name,
            'side' => $game->side->value,
            'competition' => $game->competition?->name,
            'round' => $game->round_label,
            'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $game->date)->locale(app()->getLocale())->translatedFormat('l j F Y'),
            'kickoff' => $game->kickoff_time,
            'venue' => $game->venue?->name,
        ];
    }

    /** @return list<string> */
    private function coaches(Game $game): array
    {
        return $game->team->coachesFor($game->season_start_year)->get()
            ->sortBy(fn (User $u) => $u->pivot->role === 'head' ? 0 : 1)
            ->map(fn (User $u) => $u->fullname)->values()->all();
    }
}
