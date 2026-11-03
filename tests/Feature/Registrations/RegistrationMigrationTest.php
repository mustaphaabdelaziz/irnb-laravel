<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function oldPivot(): void
    {
        Schema::drop('branch_player');
        Schema::create('branch_player', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->primary(['branch_id', 'player_id']);
        });
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_03_100003_rebuild_branch_player_as_registrations.php'))->up();
    }

    private function player(string $id, array $extra = []): Player
    {
        return Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => 2017, ...$extra]);
    }

    #[Test]
    public function it_copies_year_details_and_id_without_inventing_a_date(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player('201700012', ['category_id' => $category->id, 'licence_number' => 'L1']);
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $player->id]);

        $this->migrate();

        $row = BranchRegistration::firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame(2017, $row->legacy_join_year);
        $this->assertSame('201700012', $row->membership_id);
        $this->assertSame($category->id, $row->category_id);
        $this->assertSame('L1', $row->licence_number);
        $this->assertSame(1, BranchRegistration::needsJoinDate()->count());
    }

    #[Test]
    public function a_two_branch_player_gets_the_year_on_both_the_id_on_one_and_the_position_on_its_owner(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $other = $this->player('201700001');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $other->id]);
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $foot->id]);
        $both = $this->player('201700002', ['position_id' => $gk->id]);
        DB::table('branch_player')->insert([
            ['branch_id' => $swim->id, 'player_id' => $both->id],
            ['branch_id' => $foot->id, 'player_id' => $both->id],
        ]);

        $this->migrate();

        $byBranch = BranchRegistration::where('player_id', $both->id)->get()->keyBy('branch_id');
        $this->assertSame(2017, $byBranch[$foot->id]->legacy_join_year);
        $this->assertSame(2017, $byBranch[$swim->id]->legacy_join_year);
        $this->assertSame('201700002', $byBranch[$foot->id]->membership_id);
        $this->assertNull($byBranch[$swim->id]->membership_id);
        $this->assertSame($gk->id, $byBranch[$foot->id]->position_id);
        $this->assertNull($byBranch[$swim->id]->position_id);
    }

    #[Test]
    public function a_position_no_registration_owns_is_kept_on_the_primary(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $foot->id]);
        $player = $this->player('201700003', ['position_id' => $gk->id]);
        DB::table('branch_player')->insert(['branch_id' => $swim->id, 'player_id' => $player->id]);

        $this->migrate();

        $this->assertSame($gk->id, BranchRegistration::firstOrFail()->position_id);
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $player = $this->player('201700004');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $player->id]);

        $this->migrate();
        $before = BranchRegistration::firstOrFail()->toArray();
        BranchRegistration::query()->update(['licence_number' => 'edited']);
        $this->migrate();

        $this->assertSame(1, BranchRegistration::count());
        $this->assertSame('edited', BranchRegistration::firstOrFail()->licence_number);
        $this->assertSame($before['membership_id'], BranchRegistration::firstOrFail()->membership_id);
    }

    #[Test]
    public function existing_pivot_calls_still_work_and_inherit_the_players_details(): void
    {
        $foot = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player('201700005', ['category_id' => $category->id]);

        $player->branches()->attach($foot->id);

        $this->assertSame([$foot->id], $player->branches()->pluck('branches.id')->all());
        $row = BranchRegistration::firstOrFail();
        $this->assertSame($category->id, $row->category_id);
        $this->assertSame(2017, $row->legacy_join_year);

        $player->branches()->detach($foot->id);
        $this->assertSame(0, BranchRegistration::count());
    }

    #[Test]
    public function a_run_that_stopped_inside_a_player_finishes_on_the_next_run(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $other = $this->player('201700001');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $other->id]);
        $both = $this->player('201700002', ['licence_number' => 'L1']);
        DB::table('branch_player')->insert([
            ['branch_id' => $swim->id, 'player_id' => $both->id],
            ['branch_id' => $foot->id, 'player_id' => $both->id],
        ]);

        $this->migrate();

        // Simulate a crash after the Football row was filled and before the Swimming row was.
        DB::table('branch_player')->where('branch_id', $swim->id)->where('player_id', $both->id)
            ->update(['legacy_join_year' => null, 'licence_number' => null, 'updated_at' => null]);
        DB::table('branch_player')->where('branch_id', $foot->id)->where('player_id', $both->id)
            ->update(['licence_number' => 'edited']);

        $this->migrate();

        $byBranch = BranchRegistration::where('player_id', $both->id)->get()->keyBy('branch_id');
        $this->assertSame(2017, $byBranch[$swim->id]->legacy_join_year);
        $this->assertSame('L1', $byBranch[$swim->id]->licence_number);
        $this->assertNull($byBranch[$swim->id]->membership_id);
        $this->assertSame('edited', $byBranch[$foot->id]->licence_number);

        // Now the Football row loses its id and year: the next run must restore them.
        DB::table('branch_player')->where('branch_id', $foot->id)->where('player_id', $both->id)
            ->update(['membership_id' => null, 'legacy_join_year' => null, 'updated_at' => null]);

        $this->migrate();

        $byBranch = BranchRegistration::where('player_id', $both->id)->get()->keyBy('branch_id');
        $this->assertSame('201700002', $byBranch[$foot->id]->membership_id);
        $this->assertSame(2017, $byBranch[$foot->id]->legacy_join_year);
    }

    #[Test]
    public function a_rerun_leaves_a_filled_row_alone_when_the_player_has_no_year(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $other = $this->player('201700001');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $other->id]);
        $both = $this->player('201700002', ['join_year' => null, 'licence_number' => 'L1']);
        DB::table('branch_player')->insert([
            ['branch_id' => $swim->id, 'player_id' => $both->id],
            ['branch_id' => $foot->id, 'player_id' => $both->id],
        ]);

        $this->migrate();
        DB::table('branch_player')->where('branch_id', $swim->id)->where('player_id', $both->id)
            ->update(['licence_number' => 'edited']);

        $this->migrate();

        $swimRow = BranchRegistration::where('player_id', $both->id)->where('branch_id', $swim->id)->firstOrFail();
        $this->assertSame('edited', $swimRow->licence_number);
    }
}
