<?php

namespace App\Services\Dashboard;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;

/** The Overview's matches card: next 3 and last 3 results — a coach's own teams first. */
final class MatchesCard
{
    /** @return array{upcoming: list<array<string, mixed>>, results: list<array<string, mixed>>} */
    public function get(User $user): array
    {
        $mine = TeamAccess::manageableTeamIds($user, Season::current()->startYear);
        $today = now()->toDateString();
        $first = fn ($q) => $mine ? $q->orderByRaw('CASE WHEN team_id IN ('.implode(',', array_map('intval', $mine)).') THEN 0 ELSE 1 END') : $q;

        $upcoming = $first(Game::query()->with(['team:id,name', 'opponent:id,name'])
            ->where('date', '>=', $today)->whereIn('status', [GameStatus::Scheduled->value, GameStatus::Postponed->value]))
            ->orderBy('date')->orderBy('kickoff_time')->limit(3)->get();
        $results = $first(Game::query()->with(['team:id,name', 'opponent:id,name'])->where('status', GameStatus::Played->value))
            ->orderByDesc('date')->orderByDesc('id')->limit(3)->get();

        return ['upcoming' => $upcoming->map(fn (Game $g) => self::row($g))->all(), 'results' => $results->map(fn (Game $g) => self::row($g))->all()];
    }

    /** @return array<string, mixed> */
    private static function row(Game $g): array
    {
        $outcome = $g->status === GameStatus::Played && $g->our_score !== null && $g->their_score !== null
            ? match ($g->our_score <=> $g->their_score) { 1 => 'W', 0 => 'D', -1 => 'L' } : null;

        return [
            'id' => $g->id, 'date' => $g->date, 'kickoff_time' => $g->kickoff_time, 'team' => $g->team->name,
            'opponent' => $g->opponent->name, 'side' => $g->side->value, 'status' => $g->status->value,
            'our_score' => $g->our_score, 'their_score' => $g->their_score, 'outcome' => $outcome,
        ];
    }
}
