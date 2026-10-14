<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;

/** Our own record in a set of matches: no league table, just W/D/L, goals and recent form. */
final class CompetitionRecord
{
    /**
     * @param  iterable<Game>  $games
     * @return array{played: int, wins: int, draws: int, losses: int, goals_for: int, goals_against: int, form: list<string>}
     */
    public static function fromGames(iterable $games): array
    {
        $record = ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'form' => []];
        $results = [];

        foreach ($games as $game) {
            if ($game->status !== GameStatus::Played || $game->our_score === null || $game->their_score === null) {
                continue;
            }
            $outcome = $game->our_score <=> $game->their_score;
            $record['played']++;
            $record['goals_for'] += $game->our_score;
            $record['goals_against'] += $game->their_score;
            $record[match ($outcome) {
                1 => 'wins', 0 => 'draws', -1 => 'losses'
            }]++;
            $results[] = [$game->date, match ($outcome) {
                1 => 'W', 0 => 'D', -1 => 'L'
            }];
        }

        usort($results, fn (array $a, array $b) => strcmp($a[0], $b[0]));
        $record['form'] = array_column(array_slice($results, -5), 1);

        return $record;
    }
}
