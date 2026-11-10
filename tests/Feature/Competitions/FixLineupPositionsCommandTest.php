<?php

namespace Tests\Feature\Competitions;

use App\Models\Formation;
use App\Models\GameFormat;
use App\Models\GamePlayer;
use App\Models\Position;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function every_row_is_filled_across_chunks(): void
    {
        [, $rows] = $this->oldLineup();

        $this->artisan('competitions:fix-lineup-positions', ['--chunk' => 2])->expectsOutputToContain('Filled 5')->assertSuccessful();
        $this->assertSame(0, GamePlayer::whereNull('position_id')->where('role', 'starter')->count());
    }

    #[Test]
    public function only_the_games_own_branch_positions_are_used_and_a_branch_without_one_is_left_empty(): void
    {
        [$game, $rows] = $this->oldLineup();
        // The team's branch has no forward; another branch does.
        Position::where('branch_id', $game->team->branch_id)->whereIn('abbreviation', ['FW', 'CF'])->delete();
        $other = $this->sportBranch('Handball');
        Position::create(['branch_id' => $other->id, 'name' => 'Forward', 'abbreviation' => 'FW']);

        $this->artisan('competitions:fix-lineup-positions')
            ->expectsOutputToContain('Filled 3 starter position(s). Left empty: 2 (the branch has no matching position), 0 (formation')->assertSuccessful();
        $this->assertNull($rows[3]->fresh()->position_id);
        $this->assertNull($rows[4]->fresh()->position_id);
        $this->assertNotNull($rows[0]->fresh()->position_id);
    }

    #[Test]
    public function a_missing_formation_is_counted_apart_and_cached(): void
    {
        [$game] = $this->oldLineup();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('games')->where('id', $game->id)->update(['formation_id' => 99999]);

        $this->artisan('competitions:fix-lineup-positions')
            ->expectsOutputToContain('Filled 0 starter position(s). Left empty: 0 (the branch has no matching position), 5 (formation or slot no longer exists)')->assertSuccessful();
    }

    #[Test]
    public function a_deleted_formation_or_an_out_of_range_slot_is_counted_apart(): void
    {
        [$game, $rows] = $this->oldLineup();
        $rows[4]->update(['slot' => 9]);

        $this->artisan('competitions:fix-lineup-positions')
            ->expectsOutputToContain('Filled 4 starter position(s). Left empty: 0 (the branch has no matching position), 1 (formation or slot no longer exists)')->assertSuccessful();
    }
}
