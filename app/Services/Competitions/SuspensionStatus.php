<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Suspension;
use App\Support\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each ban has been served, as of a match (or now), and who is
 * suspended for a given match. A matches ban is served by the team's PLAYED
 * matches after it starts — in its competition, or (all competitions) any
 * competition match of a team the player is on that season; postponed,
 * cancelled and friendly matches never count. A date ban is active between
 * its two dates. A constant number of queries, whatever the list size.
 */
final class SuspensionStatus
{
    /**
     * @param  Collection<int, Suspension>  $suspensions
     * @return array<int, array{served: ?int, remaining: ?int, active: bool, until: ?string}>
     */
    public function evaluate(Collection $suspensions, ?Game $at = null): array
    {
        if ($suspensions->isEmpty()) {
            return [];
        }
        // Plain collections (tests) work too: wrap, then load the trigger matches once.
        $suspensions = (new \Illuminate\Database\Eloquent\Collection($suspensions->all()))->loadMissing('game');
        $atDate = $at?->date ?? now()->toDateString();
        $atId = $at?->id ?? PHP_INT_MAX;
        $before = fn ($g) => $g->date < $atDate || ($g->date === $atDate && $g->id < $atId);

        $matchBans = $suspensions->where('kind', 'matches');
        $competitionIds = $matchBans->whereNotNull('competition_id')->pluck('competition_id')->unique()->values();
        $byCompetition = $competitionIds->isEmpty() ? collect() : Game::query()
            ->whereIn('competition_id', $competitionIds)->where('status', GameStatus::Played->value)
            ->get(['id', 'date', 'competition_id'])->groupBy('competition_id');

        $everywhere = $matchBans->whereNull('competition_id');
        $playerTeams = [];
        $teamGames = collect();
        if ($everywhere->isNotEmpty()) {
            $pairs = DB::table('team_players')->whereIn('player_id', $everywhere->pluck('player_id')->unique())
                ->get(['player_id', 'team_id', 'season_start_year']);
            foreach ($pairs as $pair) {
                $playerTeams[(int) $pair->player_id][(int) $pair->season_start_year][] = (int) $pair->team_id;
            }
            $teamGames = Game::query()->whereIn('team_id', $pairs->pluck('team_id')->unique())
                ->whereNotNull('competition_id')->where('status', GameStatus::Played->value)
                ->get(['id', 'date', 'team_id'])->groupBy('team_id');
        }

        $out = [];
        foreach ($suspensions as $s) {
            if ($s->kind === 'dates') {
                $out[$s->id] = ['served' => null, 'remaining' => null, 'active' => $s->starts_on <= $atDate && $atDate <= $s->ends_on, 'until' => $s->ends_on];

                continue;
            }
            if ($s->competition_id !== null) {
                $trigger = $s->game;
                $after = $trigger
                    ? fn ($g) => $g->date > $trigger->date || ($g->date === $trigger->date && $g->id > $trigger->id)
                    : fn ($g) => $g->date >= (string) $s->starts_on;
                $served = collect($byCompetition->get($s->competition_id, []))->filter(fn ($g) => $after($g) && $before($g))->count();
            } else {
                $season = Season::forDate((string) $s->starts_on)->startYear;
                $served = collect($playerTeams[$s->player_id][$season] ?? [])
                    ->flatMap(fn (int $teamId) => $teamGames->get($teamId, []))->unique('id')
                    ->filter(fn ($g) => $g->date >= (string) $s->starts_on && $before($g))->count();
            }
            $served = min($served, (int) $s->matches_count);
            $out[$s->id] = ['served' => $served, 'remaining' => (int) $s->matches_count - $served, 'active' => $served < (int) $s->matches_count, 'until' => null];
        }

        return $out;
    }

    /**
     * Players banned for $game — or, without a game, for $competition, or
     * anywhere (roster view). One entry per banned player.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{remaining: ?int, until: ?string, cause: ?string, reason: ?string, opponent: ?string, competition: ?string}>
     */
    public function forPlayers(array $playerIds, ?Game $game = null, ?Competition $competition = null): array
    {
        if ($playerIds === []) {
            return [];
        }
        $list = Suspension::query()->whereIn('player_id', $playerIds)->with(['game.opponent', 'competition'])->get();
        if ($list->isEmpty()) {
            return [];
        }
        $competitionId = $game !== null ? $game->competition_id : $competition?->id;
        $atDate = $game?->date ?? now()->toDateString();
        $status = $this->evaluate($list, $game);

        $out = [];
        foreach ($list as $s) {
            if (! $status[$s->id]['active'] || ! $this->applies($s, $game, $competitionId, $atDate)) {
                continue;
            }
            $out[$s->player_id] ??= [
                'remaining' => $status[$s->id]['remaining'],
                'until' => $status[$s->id]['until'],
                'cause' => $s->cause,
                'reason' => $s->reason,
                'opponent' => $s->game?->opponent?->name,
                'competition' => $s->competition?->name,
            ];
        }

        return $out;
    }

    private function applies(Suspension $s, ?Game $game, ?int $competitionId, string $atDate): bool
    {
        // Earned in this match (or a later one): it starts after it.
        if ($s->game !== null && $game !== null
            && ($s->game->date > $game->date || ($s->game->date === $game->date && $s->game->id >= $game->id))) {
            return false;
        }
        if ($s->source === 'manual' && $s->starts_on !== null && $s->starts_on > $atDate) {
            return false;
        }
        if ($game === null && $competitionId === null) {
            return true;
        }
        if ($s->competition_id !== null) {
            return $s->competition_id === $competitionId;
        }

        // All competitions: a date ban covers every match; a matches ban only competition matches.
        return $s->kind === 'dates' || $competitionId !== null;
    }
}
