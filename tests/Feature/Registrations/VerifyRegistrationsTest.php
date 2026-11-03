<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->artisan('registrations:verify')->assertExitCode(1);
    }

    #[Test]
    public function a_year_mismatch_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['legacy_join_year' => 1999]);
        DB::table('players')->where('id', $player->id)->update(['join_year' => 2017]);

        $this->artisan('registrations:verify')->assertExitCode(1);
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
