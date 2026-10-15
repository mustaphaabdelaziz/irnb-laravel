<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Suspension;
use App\Services\Competitions\SuspensionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
