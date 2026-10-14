<?php

namespace App\Services\Competitions;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/** A team's roster for one season: who is on it and with which shirt number. */
final class TeamRoster
{
    /**
     * Makes the season's roster exactly $rows. Numbers are cleared first, so
     * swapping two players' numbers never trips the unique (team, season, number) index.
     *
     * @param  list<array{player_id: int, shirt_number: ?int}>  $rows
     */
    public function replace(Team $team, int $season, array $rows): void
    {
        DB::transaction(function () use ($team, $season, $rows) {
            $scope = fn () => DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season);
            $ids = array_map(fn (array $row) => (int) $row['player_id'], $rows);

            $scope()->whereNotIn('player_id', $ids)->delete();
            $scope()->update(['shirt_number' => null]);
            $existing = $scope()->pluck('player_id')->map(fn ($id) => (int) $id)->all();

            $now = now();
            foreach ($rows as $row) {
                $values = ['shirt_number' => $row['shirt_number'] ?? null, 'updated_at' => $now];
                if (in_array((int) $row['player_id'], $existing, true)) {
                    $scope()->where('player_id', $row['player_id'])->update($values);
                } else {
                    DB::table('team_players')->insert($values + [
                        'team_id' => $team->id, 'season_start_year' => $season,
                        'player_id' => $row['player_id'], 'created_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Copies last season's roster (and numbers) onto an empty $season, leaving out archived players and those who left. */
    public function copyFromPreviousSeason(Team $team, int $season): int
    {
        $rows = DB::table('team_players')
            ->where('team_id', $team->id)->where('season_start_year', $season - 1)
            ->whereIn('player_id', Player::query()->where('archived', false)->notLeft()->select('id'))
            ->get(['player_id', 'shirt_number'])
            ->map(fn ($row) => ['player_id' => (int) $row->player_id, 'shirt_number' => $row->shirt_number === null ? null : (int) $row->shirt_number])
            ->all();

        $this->replace($team, $season, $rows);

        return count($rows);
    }
}
