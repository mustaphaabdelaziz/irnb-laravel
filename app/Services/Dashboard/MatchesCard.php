<?php

namespace App\Services\Dashboard;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;
use Illuminate\Database\Eloquent\Builder;

/** The Overview's matches card: next 3 and last 3 results — a coach's own teams first. */
final class MatchesCard
{
    /** @return array{upcoming: list<array<string, mixed>>, results: list<array<string, mixed>>} */
    public function get(User $user): array
    {
        $mine = TeamAccess::manageableTeamIds($user, Season::current()->startYear);
        $today = now()->toDateString();
        $first = fn ($q) => $mine ? $q->orderByRaw('CASE WHEN games.team_id IN ('.implode(',', array_map('intval', $mine)).') THEN 0 ELSE 1 END') : $q;

        $upcoming = $first($this->base()->where('games.date', '>=', $today)
            ->whereIn('games.status', [GameStatus::Scheduled->value, GameStatus::Postponed->value]))
            ->orderBy('games.date')->orderBy('games.kickoff_time')->limit(3)->get();
        $results = $first($this->base()->where('games.status', GameStatus::Played->value))
            ->orderByDesc('games.date')->orderByDesc('games.id')->limit(3)->get();

        return ['upcoming' => $upcoming->map(fn (Game $g) => self::row($g))->all(), 'results' => $results->map(fn (Game $g) => self::row($g))->all()];
    }

    /** One joined query per list: team and opponent names without eager-load round trips. */
    private function base(): Builder
    {
        return Game::query()->join('teams', 'teams.id', '=', 'games.team_id')->join('opponents', 'opponents.id', '=', 'games.opponent_id')
            ->select('games.id', 'games.date', 'games.kickoff_time', 'games.side', 'games.status', 'games.our_score', 'games.their_score', 'games.team_id', 'teams.name as team_name', 'opponents.name as opponent_name');
    }

    /** @return array<string, mixed> */
    private static function row(Game $g): array
    {
        $outcome = $g->status === GameStatus::Played && $g->our_score !== null && $g->their_score !== null
            ? match ($g->our_score <=> $g->their_score) {
                1 => 'W', 0 => 'D', -1 => 'L'
            } : null;

        return [
            'id' => $g->id, 'date' => $g->date, 'kickoff_time' => $g->kickoff_time, 'team' => $g->team_name,
            'opponent' => $g->opponent_name, 'side' => $g->side->value, 'status' => $g->status->value,
            'our_score' => $g->our_score, 'their_score' => $g->their_score, 'outcome' => $outcome,
        ];
    }
}
