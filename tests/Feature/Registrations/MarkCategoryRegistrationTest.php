<?php

namespace Tests\Feature\Registrations;

use App\Models\Attendance;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class MarkCategoryRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function storedCategory(TrainingSession $session, Player $player): ?int
    {
        app(MarkRecorder::class)->save($session, [['player_id' => $player->id, 'status' => 'present']], null);

        return Attendance::where('training_session_id', $session->id)->where('player_id', $player->id)->value('category_id');
    }

    #[Test]
    public function a_new_mark_stores_the_category_of_the_sessions_branch(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->u15->id, $this->storedCategory($this->sessionIn($this->swim, [$this->u15]), $player));
        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->foot, [$this->senior]), $player));
    }

    #[Test]
    public function a_left_branch_still_stores_its_own_category(): void
    {
        $player = $this->leftFootball();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->foot, [$this->senior]), $player));
    }

    #[Test]
    public function a_player_added_by_hand_with_no_row_there_stores_the_main_category(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'lastname' => 'Test', 'join_year' => 2020, 'membership_id' => $this->fixtureId()]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn($this->swim, [$this->u15]), $player));
    }

    #[Test]
    public function a_session_with_no_branch_stores_the_main_category(): void
    {
        $player = $this->bothActive();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn(null, [$this->u15]), $player));
    }

    #[Test]
    public function no_registration_stores_the_players_own_category(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame($this->senior->id, $this->storedCategory($this->sessionIn(null, [$this->senior]), $flat));
    }

    #[Test]
    public function an_existing_mark_never_changes_its_category(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->swim, [$this->u15]);
        $this->storedCategory($session, $player);

        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);

        $this->assertSame($this->u15->id, $this->storedCategory($session, $player));
    }
}
