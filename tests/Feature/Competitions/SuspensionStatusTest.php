<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Suspension;
use App\Services\Competitions\SuspensionStatus;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SuspensionStatusTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_match_ban_counts_down_with_the_teams_played_competition_matches(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $trigger = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-01', 'status' => GameStatus::Played]);
        $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-08', 'status' => GameStatus::Played]);
        $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-12', 'status' => GameStatus::Postponed]);
        $this->game($team, ['date' => '2026-10-13', 'status' => GameStatus::Played]); // friendly: never counts
        $next = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-15']);
        $ban = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 2, 'cause' => 'red', 'game_id' => $trigger->id, 'starts_on' => '2026-10-01']);
        $status = app(SuspensionStatus::class);

        $this->assertSame(['served' => 1, 'remaining' => 1, 'active' => true, 'until' => null], $status->evaluate(collect([$ban]), $next)[$ban->id]);
        $this->assertArrayHasKey($p->id, $status->forPlayers([$p->id], $next));
        $this->assertSame([], $status->forPlayers([$p->id], $trigger), 'a ban never applies to the match that caused it');
        $friendly = $this->game($team, ['date' => '2026-10-20']);
        $this->assertSame([], $status->forPlayers([$p->id], $friendly), 'competition bans skip friendlies');
    }

    #[Test]
    public function date_bans_and_all_competition_bans(): void
    {
        $team = $this->team();
        $league = $this->competition($team, 2026);
        $cup = $this->competition($team, 2026, ['name' => 'Cup', 'type' => 'cup']);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);
        $this->game($team, ['competition_id' => $cup->id, 'date' => '2026-10-05', 'status' => GameStatus::Played]);
        $later = $this->game($team, ['competition_id' => $league->id, 'date' => '2026-10-10']);
        $status = app(SuspensionStatus::class);

        $this->assertSame([], $status->forPlayers([$p->id], $later), 'served by the cup match');

        Suspension::create(['player_id' => $p->id, 'competition_id' => null, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-30']);
        $this->assertSame('2026-10-30', $status->forPlayers([$p->id], $later)[$p->id]['until']);
        $friendly = $this->game($team, ['date' => '2026-10-11']);
        $this->assertArrayHasKey($p->id, $status->forPlayers([$p->id], $friendly), 'an all-competitions date ban covers friendlies too');
    }

    #[Test]
    public function an_unserved_ban_carries_over_to_the_teams_next_official_matches_of_a_later_season(): void
    {
        $team = $this->team();
        $league = $this->competition($team, 2026);
        $cup = $this->competition($team, 2027, ['name' => 'Cup 2027', 'type' => 'cup']);
        $p = $this->player($team->category);
        $last = $this->game($team, ['competition_id' => $league->id, 'date' => '2027-05-20', 'status' => GameStatus::Played]);
        $ban = Suspension::create(['player_id' => $p->id, 'competition_id' => $league->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 1, 'cause' => 'red', 'game_id' => $last->id, 'starts_on' => '2027-05-20']);
        $this->game($team, ['season_start_year' => 2027, 'date' => '2027-09-15', 'status' => GameStatus::Played]); // friendly: never counts
        $first = $this->game($team, ['competition_id' => $cup->id, 'season_start_year' => 2027, 'date' => '2027-10-01', 'status' => GameStatus::Played]);
        $second = $this->game($team, ['competition_id' => $cup->id, 'season_start_year' => 2027, 'date' => '2027-10-08']);
        $status = app(SuspensionStatus::class);

        $this->assertSame(1, $status->forPlayers([$p->id], $first)[$p->id]['remaining'] ?? null, 'the 2027 cup match is the first official match after the ban');
        $this->assertSame(['served' => 1, 'remaining' => 0, 'active' => false, 'until' => null], $status->evaluate(collect([$ban]), $second)[$ban->id]);
        $this->assertSame([], $status->forPlayers([$p->id], $second));
    }

    #[Test]
    public function several_bans_in_one_scope_are_served_one_after_the_other(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $trigger = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-01', 'status' => GameStatus::Played]);
        $red = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 1, 'cause' => 'red', 'game_id' => $trigger->id, 'starts_on' => '2026-10-01']);
        $yellows = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 1, 'cause' => 'yellows', 'game_id' => $trigger->id, 'starts_on' => '2026-10-01']);
        $g1 = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-08', 'status' => GameStatus::Played]);
        $g2 = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-15', 'status' => GameStatus::Played]);
        $g3 = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-22']);
        $status = app(SuspensionStatus::class);

        $first = $status->forPlayers([$p->id], $g1)[$p->id] ?? [];
        $this->assertSame(['red', 1], [$first['cause'] ?? null, $first['remaining'] ?? null], 'the first match is served by the red card ban');
        $second = $status->forPlayers([$p->id], $g2)[$p->id] ?? [];
        $this->assertSame(['yellows', 1], [$second['cause'] ?? null, $second['remaining'] ?? null], 'then the yellow cards ban');
        $this->assertSame([], $status->forPlayers([$p->id], $g3), 'free on the third match');
        $eval = $status->evaluate(collect([$red, $yellows]), $g3);
        $this->assertSame([1, 1], [$eval[$red->id]['served'], $eval[$yellows->id]['served']]);
    }

    #[Test]
    public function a_manual_ban_starts_with_the_first_match_after_its_start_date(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $sameDay = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-08', 'status' => GameStatus::Played]);
        $next = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-15', 'status' => GameStatus::Played]);
        $after = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-22']);
        $ban = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-08']);
        $status = app(SuspensionStatus::class);

        $this->assertSame([], $status->forPlayers([$p->id], $sameDay), 'a match on the start day does not count');
        $this->assertSame(['served' => 0, 'remaining' => 1, 'active' => true, 'until' => null], $status->evaluate(collect([$ban]), $next)[$ban->id]);
        $this->assertArrayHasKey($p->id, $status->forPlayers([$p->id], $next));
        $this->assertSame([], $status->forPlayers([$p->id], $after));
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_list(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $game = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-20']);
        $shirt = 0;
        $count = function (int $players) use ($team, $competition, $game, &$shirt): int {
            $ids = [];
            for ($i = 0; $i < $players; $i++) {
                $p = $this->player($team->category);
                $team->players()->attach($p->id, ['season_start_year' => 2026, 'shirt_number' => ++$shirt]);
                $trigger = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-0'.($i + 1), 'status' => GameStatus::Played]);
                Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 2, 'cause' => 'red', 'game_id' => $trigger->id, 'starts_on' => $trigger->date]);
                Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-09-01']);
                $ids[] = $p->id;
            }
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            app(SuspensionStatus::class)->forPlayers($ids, $game);
            $n = count(\DB::getQueryLog());
            \DB::disableQueryLog();

            return $n;
        };
        Season::startMonth(); // memoised once per request: warm it so both counts compare like with like

        $this->assertSame($count(1), $count(4));
    }

    #[Test]
    public function the_player_page_lists_only_bans_already_in_force(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 2, 'starts_on' => now()->addDays(10)->toDateString(), 'reason' => 'Later']);

        $this->actingAs($this->admin())->getJson(route('games.players.show', ['player' => $p]))
            ->assertOk()->assertJsonCount(0, 'suspensions');
    }
}
