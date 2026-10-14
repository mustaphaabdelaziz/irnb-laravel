<?php

namespace Tests\Feature\Competitions;

use App\Enums\CompetitionType;
use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionSchemaTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_team_keeps_one_roster_per_season(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $b = $this->player($team->category);
        $this->onRoster($team, 2025, [$a]);
        $this->onRoster($team, 2026, [$a, $b]);

        $this->assertSame([$a->id], $team->rosterFor(2025)->pluck('players.id')->all());
        $this->assertCount(2, $team->rosterFor(2026)->get());
        $this->assertSame(2, $team->rosterFor(2026)->where('players.id', $b->id)->first()->pivot->shirt_number);
    }

    #[Test]
    public function a_shirt_number_is_unique_within_a_team_season(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $team->players()->attach($a->id, ['season_start_year' => 2026, 'shirt_number' => 10]);
        $team->players()->attach($b->id, ['season_start_year' => 2025, 'shirt_number' => 10]);

        $this->expectException(QueryException::class);
        $team->players()->attach($b->id, ['season_start_year' => 2026, 'shirt_number' => 10]);
    }

    #[Test]
    public function a_game_casts_its_enums_and_reaches_its_relations(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $game = $this->game($team, ['competition_id' => $competition->id, 'period_scores' => [[1, 0], [1, 1]]])->fresh();

        $this->assertSame(GameSide::Home, $game->side);
        $this->assertSame(GameStatus::Scheduled, $game->status);
        $this->assertSame([[1, 0], [1, 1]], $game->period_scores);
        $this->assertSame(CompetitionType::League, $game->competition->type);
        $this->assertSame('Rivals FC', $game->opponent->name);
        $this->assertSame($team->default_game_format_id, $game->format->id);
        $this->assertTrue($game->format->inUse());
    }

    #[Test]
    public function positions_are_unique_per_sport_not_club_wide(): void
    {
        $football = $this->sportBranch('Football');
        $handball = $this->sportBranch('Handball');
        Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $football->id]);
        Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $handball->id]);
        $this->assertSame(2, Position::where('abbreviation', 'GK')->count());

        $this->expectException(QueryException::class);
        Position::create(['name' => 'Keeper', 'abbreviation' => 'GK', 'branch_id' => $football->id]);
    }
}
