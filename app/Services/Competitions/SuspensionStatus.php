<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Suspension;
use App\Support\Season;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each ban has been served, as of a match (or now), and who is
 * suspended for a given match. Owner rules:
 *  - a matches ban is served by PLAYED official (competition) matches only —
 *    postponed, cancelled and friendly matches never count;
 *  - a competition ban is served in its competition and, if not finished when
 *    that competition ends, in the same team's official matches of LATER
 *    seasons (carry-over); an all-competitions ban by any official match of a
 *    team the player is on that season, from its start season on;
 *  - it starts counting strictly after its trigger match (auto) or strictly
 *    after its start date (manual: a match on the start day does not count);
 *  - several matches bans of one player in one scope (a competition, or "all")
 *    are served one after the other.
 * A date ban is active between its two dates. A constant number of queries,
 * whatever the list size.
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
        $atDate = $at?->date ?? now()->toDateString();
        $served = $this->served($suspensions, [(string) $atDate, $at?->id ?? PHP_INT_MAX]);

        $out = [];
        foreach ($suspensions as $s) {
            if ($s->kind === 'dates') {
                $out[$s->id] = ['served' => null, 'remaining' => null, 'active' => $s->starts_on <= $atDate && $atDate <= $s->ends_on, 'until' => $s->ends_on];

                continue;
            }
            $count = (int) $s->matches_count;
            $done = min($served[$s->id] ?? 0, $count);
            $out[$s->id] = ['served' => $done, 'remaining' => $count - $done, 'active' => $done < $count, 'until' => null];
        }

        return $out;
    }

    /**
     * Whether a ban is already in force today (the player page): an automatic
     * ban from its match on, a manual one from its start date on.
     */
    public function started(Suspension $s): bool
    {
        return $s->source !== 'manual' || $s->starts_on === null || $s->starts_on <= now()->toDateString();
    }

    /**
     * Players banned for $game — or, without a game, for $competition, or
     * anywhere (roster view). One entry per banned player: when several bans
     * apply, the one with the most matches left (date bans: the latest end).
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{remaining: ?int, until: ?string, cause: ?string, reason: ?string, opponent: ?string, competition: ?string}>
     */
    public function forPlayers(array $playerIds, ?Game $game = null, ?Competition $competition = null): array
    {
        if ($playerIds === []) {
            return [];
        }
        $list = Suspension::query()->whereIn('player_id', $playerIds)->with(['game.opponent', 'competition'])->orderBy('id')->get();
        if ($list->isEmpty()) {
            return [];
        }
        $status = $this->evaluate($list, $game);

        $candidates = [];
        foreach ($list as $s) {
            if ($status[$s->id]['active'] && $this->applies($s, $game, $competition)) {
                $candidates[$s->player_id][] = $s;
            }
        }

        $out = [];
        foreach ($candidates as $playerId => $bans) {
            // Matches bans first (most left), then date bans (latest end); ties keep the serving order.
            usort($bans, fn (Suspension $a, Suspension $b) => [$a->kind === 'matches' ? 0 : 1, -($status[$a->id]['remaining'] ?? 0), $b->ends_on ?? '', $this->order($a)]
                <=> [$b->kind === 'matches' ? 0 : 1, -($status[$b->id]['remaining'] ?? 0), $a->ends_on ?? '', $this->order($b)]);
            $s = $bans[0];
            $out[$playerId] = [
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

    /**
     * Matches served by each matches ban, as of the point (date, id), every
     * ban of the same player and scope being served in turn.
     *
     * @param  Collection<int, Suspension>  $suspensions
     * @param  array{0: string, 1: int}  $before
     * @return array<int, int>
     */
    private function served(Collection $suspensions, array $before): array
    {
        $given = $suspensions->where('kind', 'matches');
        if ($given->isEmpty()) {
            return [];
        }
        // The rest of each player's matches bans: a ban's turn depends on the ones before it.
        $bans = new EloquentCollection($given->keyBy('id')->all());
        Suspension::query()->whereIn('player_id', $given->pluck('player_id')->unique()->values())
            ->where('kind', 'matches')->whereNotIn('id', $bans->keys()->all())->get()
            ->each(fn (Suspension $s) => $bans->put($s->id, $s));
        $bans->loadMissing(['game:id,date', 'competition:id,team_id,season_start_year']);

        $everywhere = $bans->whereNull('competition_id');
        $playerTeams = [];
        $teamIds = [];
        if ($everywhere->isNotEmpty()) {
            $pairs = DB::table('team_players')->whereIn('player_id', $everywhere->pluck('player_id')->unique()->values())
                ->get(['player_id', 'team_id', 'season_start_year']);
            foreach ($pairs as $pair) {
                $playerTeams[(int) $pair->player_id][(int) $pair->season_start_year][(int) $pair->team_id] = true;
                $teamIds[(int) $pair->team_id] = true;
            }
        }
        $competitionIds = [];
        foreach ($bans->whereNotNull('competition_id') as $s) {
            $competitionIds[$s->competition_id] = true;
            if (($team = $this->teamOf($s)) !== null) {
                $teamIds[$team] = true;
            }
        }

        $games = Game::query()->where('status', GameStatus::Played->value)->whereNotNull('competition_id')
            ->where('date', '<=', $before[0])
            ->where(fn ($q) => $q->whereIn('competition_id', array_keys($competitionIds))->orWhereIn('team_id', array_keys($teamIds)))
            ->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'team_id', 'competition_id', 'season_start_year'])
            ->map(fn (Game $g) => (object) ['id' => (int) $g->id, 'date' => (string) $g->date, 'team_id' => (int) $g->team_id,
                'competition_id' => (int) $g->competition_id, 'season' => (int) $g->season_start_year])
            ->filter(fn (object $g) => $this->isBefore([$g->date, $g->id], $before))
            ->values();

        $served = [];
        $groups = $bans->groupBy(fn (Suspension $s) => $s->player_id.':'.($s->competition_id ?? 'all'));
        foreach ($groups as $group) {
            $group = $group->sortBy(fn (Suspension $s) => $this->order($s))->values();
            $left = [];
            foreach ($group as $s) {
                $served[$s->id] = 0;
                $left[$s->id] = (int) $s->matches_count;
            }
            foreach ($games as $g) {
                foreach ($group as $s) {
                    if ($left[$s->id] > 0 && $this->isBefore($this->start($s), [$g->date, $g->id]) && $this->covers($s, $g, $playerTeams)) {
                        $served[$s->id]++;
                        $left[$s->id]--;

                        break;
                    }
                }
            }
        }

        return $served;
    }

    /** Whether a played official game (id, date, team_id, competition_id, season) can serve the matches ban. */
    private function covers(Suspension $s, object $g, array $playerTeams): bool
    {
        if ($s->competition_id !== null) {
            return $g->competition_id === $s->competition_id || $this->carriesInto($s, $g->team_id, $g->season);
        }

        return $g->season >= $this->startSeason($s) && isset($playerTeams[$s->player_id][$g->season][$g->team_id]);
    }

    /** Whether the ban keeps the player out of $game (or the competition / anywhere when there is no game). */
    private function applies(Suspension $s, ?Game $game, ?Competition $competition): bool
    {
        if ($game !== null) {
            if ($s->kind === 'matches' && ! $this->isBefore($this->start($s), [(string) $game->date, (int) $game->id])) {
                return false;
            }
            $official = $game->competition_id !== null;
            if ($s->competition_id === null) {
                // All competitions: a date ban covers every match; a matches ban only official ones.
                return $s->kind === 'dates' || $official;
            }
            if ($s->kind === 'dates') {
                return $official && (int) $game->competition_id === $s->competition_id;
            }

            return $official && ((int) $game->competition_id === $s->competition_id
                || $this->carriesInto($s, (int) $game->team_id, (int) $game->season_start_year));
        }
        if (! $this->started($s)) {
            return false;
        }
        if ($competition === null || $s->competition_id === null) {
            return true;
        }

        return (int) $competition->id === $s->competition_id
            || ($s->kind === 'matches' && $this->carriesInto($s, (int) $competition->team_id, (int) $competition->season_start_year));
    }

    /** A competition matches ban reaches the same team's official matches of later seasons. */
    private function carriesInto(Suspension $s, int $teamId, int $season): bool
    {
        $team = $this->teamOf($s);
        $from = $s->competition?->season_start_year;

        return $team !== null && $from !== null && $team === $teamId && $season > (int) $from;
    }

    /** The point a ban starts after: its trigger match, or the end of its start day. */
    private function start(Suspension $s): array
    {
        if ($s->source !== 'manual' && $s->game !== null) {
            return [(string) $s->game->date, (int) $s->game->id];
        }

        return [(string) ($s->starts_on ?? $s->created_at?->toDateString() ?? ''), PHP_INT_MAX];
    }

    /** Serving order within a player's scope: start date, then trigger match, then ban id. */
    private function order(Suspension $s): array
    {
        return [...$this->start($s), (int) $s->id];
    }

    private function startSeason(Suspension $s): int
    {
        $date = $s->starts_on ?? $s->created_at?->toDateString();

        return $date ? Season::forDate($date)->startYear : 0;
    }

    private function teamOf(Suspension $s): ?int
    {
        $team = $s->team_id ?? $s->competition?->team_id;

        return $team === null ? null : (int) $team;
    }

    /** (date, id) strictly before (date, id). */
    private function isBefore(array $a, array $b): bool
    {
        return $a[0] < $b[0] || ($a[0] === $b[0] && $a[1] < $b[1]);
    }
}
