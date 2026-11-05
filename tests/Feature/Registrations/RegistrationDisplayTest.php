<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Player\RegistrationDisplay;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class RegistrationDisplayTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function loaded(Player $player): Player
    {
        return Player::with('registrations')->findOrFail($player->id);
    }

    #[Test]
    public function no_branch_shows_the_main_registration(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->bothActive()));

        $this->assertSame($this->foot->id, $values['branch_id']);
        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
    }

    #[Test]
    public function one_branch_shows_that_registration(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->bothActive()), $this->swim->id);

        $this->assertSame($this->swim->id, $values['branch_id']);
        $this->assertSame($this->u15->id, $values['category_id']);
        $this->assertTrue($values['in_branch']);
        $this->assertFalse($values['left_branch']);
    }

    #[Test]
    public function a_branch_the_player_left_is_flagged_and_keeps_its_date(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->leftFootball()), $this->foot->id);

        $this->assertTrue($values['left_branch']);
        $this->assertSame($this->leaveDate, $values['left_at']);
        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $values['status_id']);
    }

    #[Test]
    public function no_branch_after_leaving_one_shows_the_one_still_active(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->leftFootball()));

        $this->assertSame($this->swim->id, $values['branch_id']);
        $this->assertNull($values['left_at']);
    }

    #[Test]
    public function left_everywhere_shows_left_with_the_latest_date(): void
    {
        $player = $this->leftBoth();
        app(RegistrationService::class)->refreshMirror($player);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['left_at' => '2026-04-01']);

        $values = RegistrationDisplay::make()->values($this->loaded($player));

        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $values['status_id']);
        $this->assertSame('2026-04-01', $values['left_at']);
    }

    #[Test]
    public function a_branch_the_player_never_joined_shows_main_values_not_in_branch(): void
    {
        $player = Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'Solo', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $values = RegistrationDisplay::make()->values($this->loaded($player), $this->swim->id);

        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
        $this->assertFalse($values['left_branch']);
    }

    #[Test]
    public function no_registration_falls_back_to_the_players_columns(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->noRegistration()), $this->foot->id);

        $this->assertNull($values['registration_id']);
        $this->assertNull($values['branch_id']);
        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
    }

    #[Test]
    public function a_registration_without_an_id_shows_none_but_no_registration_shows_the_players(): void
    {
        $player = $this->loaded($this->bothActive());
        $this->assertNull($player->registrations->firstWhere('branch_id', $this->swim->id)->membership_id);

        $values = RegistrationDisplay::make()->values($player, $this->swim->id);
        $this->assertNull($values['membership_id']);

        $flat = $this->loaded($this->noRegistration());
        $this->assertSame($flat->membership_id, RegistrationDisplay::make()->values($flat)['membership_id']);
    }

    #[Test]
    public function active_in_means_a_registration_there_not_left(): void
    {
        $display = RegistrationDisplay::make();
        $player = $this->loaded($this->leftFootball());

        $this->assertFalse($display->isActiveIn($player, $this->foot->id));
        $this->assertTrue($display->isActiveIn($player, $this->swim->id));
        $this->assertFalse($display->isActiveIn($this->loaded($this->noRegistration()), $this->foot->id));
    }

    #[Test]
    public function values_cost_no_query_per_row(): void
    {
        foreach (range(1, 5) as $n) {
            $this->bothActive("P$n");
        }
        $players = Player::with('registrations')->get();
        $display = RegistrationDisplay::make();

        DB::enableQueryLog();
        $players->each(fn (Player $p) => $display->values($p, $this->swim->id));
        $players->each(fn (Player $p) => $display->values($p));
        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function the_main_row_matches_the_service(): void
    {
        $service = app(RegistrationService::class);
        foreach ([$this->bothActive(), $this->leftFootball(), $this->leftBoth()] as $player) {
            $this->assertSame($service->primary($player)->id, RegistrationDisplay::make()->main($this->loaded($player))->id);
        }
    }

    #[Test]
    public function single_branch_needs_exactly_one(): void
    {
        $this->assertSame(4, RegistrationDisplay::singleBranch([4]));
        $this->assertSame(4, RegistrationDisplay::singleBranch(4));
        $this->assertSame(4, RegistrationDisplay::singleBranch(['4']));
        $this->assertNull(RegistrationDisplay::singleBranch([4, 5]));
        $this->assertNull(RegistrationDisplay::singleBranch([]));
        $this->assertNull(RegistrationDisplay::singleBranch(null));
    }
}
