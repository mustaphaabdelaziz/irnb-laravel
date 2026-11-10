<?php

namespace Tests\Feature\Competitions;

use App\Models\Formation;
use App\Models\GameFormat;
use App\Models\GamePlayer;
use App\Models\Position;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class FixLineupPositionsCommandTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    /** A Football 5 «2-2» match whose five starters were saved before the slot map (no position). */
    private function oldLineup(): array
    {
        $this->seed(PositionSeeder::class);
        $team = $this->team();
        Position::whereNull('branch_id')->update(['branch_id' => $team->branch_id]);
        $five = GameFormat::where('is_preset', true)->where('name', 'Football 5')->firstOrFail();
        $team->update(['default_game_format_id' => $five->id]);
        $formation = Formation::where('game_format_id', $five->id)->where('name', '2-2')->firstOrFail();
        $game = $this->game($team, ['formation_id' => $formation->id]);
        $rows = [];
        foreach (range(0, 4) as $slot) {
            $rows[] = GamePlayer::create([
                'game_id' => $game->id, 'player_id' => $this->player($team->category)->id,
                'called_up' => true, 'role' => 'starter', 'slot' => $slot,
            ]);
        }
        $bench = GamePlayer::create(['game_id' => $game->id, 'player_id' => $this->player($team->category)->id, 'called_up' => true, 'role' => 'bench']);

        return [$game, $rows, $bench];
    }

    #[Test]
    public function it_fills_missing_starter_positions_from_the_formation_and_is_idempotent(): void
    {
        [, $rows, $bench] = $this->oldLineup();
        $abbr = fn (GamePlayer $gp) => Position::find($gp->fresh()->position_id)?->abbreviation;

        $this->artisan('competitions:fix-lineup-positions')->expectsOutputToContain('Filled 5')->assertSuccessful();
        $this->assertSame(['GK', 'DF', 'DF', 'FW', 'FW'], array_map($abbr, $rows));
        $this->assertNull($bench->fresh()->position_id);

        $this->artisan('competitions:fix-lineup-positions')->expectsOutputToContain('Filled 0')->assertSuccessful();
        $this->assertSame(['GK', 'DF', 'DF', 'FW', 'FW'], array_map($abbr, $rows));
    }

    #[Test]
    public function a_dry_run_counts_without_writing_and_existing_positions_are_kept(): void
    {
        [, $rows] = $this->oldLineup();
        $cb = Position::where('abbreviation', 'CB')->firstOrFail();
        $rows[1]->update(['position_id' => $cb->id]);

        $this->artisan('competitions:fix-lineup-positions', ['--dry-run' => true])->expectsOutputToContain('Would fill 4')->assertSuccessful();
        $this->assertSame(4, GamePlayer::whereNull('position_id')->where('role', 'starter')->count());

        $this->artisan('competitions:fix-lineup-positions')->assertSuccessful();
        $this->assertSame($cb->id, $rows[1]->fresh()->position_id);
        $this->assertSame(0, GamePlayer::whereNull('position_id')->where('role', 'starter')->count());
    }
}
