<?php

namespace Tests\Feature\LiteraryReport;

use App\Enums\GameStatus;
use App\Models\Category;
use App\Models\GameEvent;
use App\Models\PlayerAchievement;
use App\Services\LiteraryReport\Sections\SportsSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SportsSectionTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(SportsSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function it_builds_records_per_competition_scorers_and_achievements_from_the_period(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $team = $this->team($u15, ['name' => 'U15 A']);
        $league = $this->competition($team, 2025, ['name' => 'Ligue']);
        $scorer = $this->player($u15, ['firstname' => 'Riyad', 'lastname' => 'Mahrez']);

        $win = $this->game($team, ['competition_id' => $league->id, 'date' => '2025-10-05', 'status' => GameStatus::Played, 'our_score' => 3, 'their_score' => 1]);
        $this->game($team, ['competition_id' => $league->id, 'date' => '2026-02-01', 'status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0]);
        $this->game($team, ['date' => '2026-05-01', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 2]); // friendly
        $this->game($team, ['competition_id' => $league->id, 'date' => '2025-06-01', 'status' => GameStatus::Played, 'our_score' => 9, 'their_score' => 0]); // before
        $this->game($team, ['competition_id' => $league->id, 'date' => '2026-03-01']); // scheduled

        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 10, 'player_id' => $scorer->id]);
        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'penalty', 'minute' => 50, 'player_id' => $scorer->id]);
        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'opponent_own_goal', 'minute' => 70, 'player_id' => null]);
        PlayerAchievement::create(['player_id' => $scorer->id, 'title' => 'Wilaya top scorer', 'date' => '2026-06-15']);
        PlayerAchievement::create(['player_id' => $scorer->id, 'title' => 'Too old', 'date' => '2024-06-15']);

        $data = $this->build();

        $this->assertSame(3, $data['played']);
        $this->assertSame(['played' => 3, 'wins' => 1, 'draws' => 1, 'losses' => 1, 'goals_for' => 4, 'goals_against' => 3], $data['record']);
        $this->assertSame(['Ligue', null], array_column($data['competitions'], 'name')); // friendlies last, name null
        $this->assertSame(2, $data['competitions'][0]['played']);
        $this->assertSame([['name' => $scorer->fresh()->fullname, 'goals' => 2]], $data['scorers']);
        $this->assertSame([['date' => '2026-06-15', 'title' => 'Wilaya top scorer', 'player' => $scorer->fresh()->fullname]], $data['achievements']);
    }

    #[Test]
    public function the_friendlies_row_lists_every_team_that_played_one(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $teamA = $this->team($u15, ['name' => 'Team Alpha']);
        $teamB = $this->team($u15, ['name' => 'Team Beta']);
        $this->game($teamA, ['date' => '2026-05-01', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        $this->game($teamB, ['date' => '2026-05-02', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);

        $friendlies = collect($this->build()['competitions'])->firstWhere('name', null);

        $this->assertStringContainsString('Team Alpha', $friendlies['team']);
        $this->assertStringContainsString('Team Beta', $friendlies['team']);
    }

    #[Test]
    public function boundary_days_of_the_period_are_included(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $team = $this->team($u15);
        $p = $this->player($u15);
        $this->game($team, ['date' => '2026-08-31', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        PlayerAchievement::create(['player_id' => $p->id, 'title' => 'Last day', 'date' => '2026-08-31']);
        PlayerAchievement::create(['player_id' => $p->id, 'title' => 'First day', 'date' => '2025-09-01']);
        PlayerAchievement::create(['player_id' => $p->id, 'title' => 'After', 'date' => '2026-09-01']);

        $data = $this->build();

        $this->assertSame(1, $data['played']);
        $this->assertSame(['First day', 'Last day'], array_column($data['achievements'], 'title'));
    }

    #[Test]
    public function a_played_game_without_scores_is_not_counted(): void
    {
        $team = $this->team(Category::create(['name' => 'U15']));
        $this->game($team, ['date' => '2026-03-01', 'status' => GameStatus::Played, 'our_score' => null, 'their_score' => null]);
        $this->game($team, ['date' => '2026-03-02', 'status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 0]);

        $this->assertSame(1, $this->build()['played']);
    }

    #[Test]
    public function no_games_gives_an_empty_record(): void
    {
        $data = $this->build();
        $this->assertSame(0, $data['played']);
        $this->assertSame([], $data['competitions']);
    }
}
