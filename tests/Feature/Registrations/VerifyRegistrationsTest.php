<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Player
    {
        $branch = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($branch->id, ['membership_id' => '201700001', 'category_id' => null, 'legacy_join_year' => 2017]);

        return $player;
    }

    #[Test]
    public function clean_data_passes(): void
    {
        $this->member();

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_wrong_copy_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['licence_number' => 'WRONG']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player copy differs from primary')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_year_mismatch_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['legacy_join_year' => 1999]);
        DB::table('players')->where('id', $player->id)->update(['join_year' => 2017]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('join year differs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_player_copy_of_the_id_that_differs_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['membership_id' => '201799999']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player copy differs from primary')
            ->expectsOutputToContain('membership_id')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_duplicate_id_fails(): void
    {
        $player = $this->member();
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        Schema::table('branch_player', fn ($table) => $table->dropUnique('branch_reg_membership_unique'));
        DB::table('branch_player')->insert([
            'branch_id' => $swim->id, 'player_id' => $player->id, 'membership_id' => '201700001', 'legacy_join_year' => 2017,
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('duplicate IDs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_player_left_while_the_only_registration_is_active_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['status_id' => Player::leftStatusId(), 'left_at' => '2024-02-03']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('player status or leave date differs')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_leave_date_on_a_registration_that_has_not_left_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['left_at' => '2024-02-03']);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('leave date on a registration that has not left')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_left_registration_without_a_leave_date_is_allowed(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['status_id' => Player::leftStatusId()]);
        DB::table('players')->where('id', $player->id)->update(['status_id' => Player::leftStatusId(), 'left_at' => null]);

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_player_with_no_branch_is_reported_but_does_not_fail(): void
    {
        Player::create(['firstname' => 'Solo', 'membership_id' => '201700002', 'join_year' => 2017]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('no branch: 1')
            ->assertExitCode(0);
    }
}
