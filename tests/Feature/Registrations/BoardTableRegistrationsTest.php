<?php

namespace Tests\Feature\Registrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BoardTableRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    #[Test]
    public function the_board_table_lists_a_second_branchs_category_with_that_id(): void
    {
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        $csv = $this->get(route('players.board-table', ['category_id' => $this->u15->id, 'format' => 'csv']))->streamedContent();

        $this->assertStringContainsString('SW-2020-00001', $csv);
        $this->assertStringNotContainsString('FB-2020', $csv);
    }

    #[Test]
    public function a_player_with_no_registration_still_prints(): void
    {
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $flat = $this->noRegistration();
        $flat->update(['membership_id' => '202000001']);

        $csv = $this->get(route('players.board-table', ['category_id' => $this->senior->id, 'format' => 'csv']))->streamedContent();

        $this->assertStringContainsString('202000001', $csv);
    }
}
