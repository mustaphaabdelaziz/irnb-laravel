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

    #[Test]
    public function saving_marks_costs_no_query_per_player(): void
    {
        // Saving writes each mark with its own updateOrCreate, so only the queries
        // that look the categories up (everything off the attendances table) are counted.
        $lookups = function (int $players): int {
            $session = $this->sessionIn($this->swim, [$this->u15], sprintf('2026-04-%02d', $players));
            $rows = [];
            foreach (range(1, $players) as $n) {
                $rows[] = ['player_id' => $this->bothActive("Q{$players}x{$n}")->id, 'status' => 'present'];
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(MarkRecorder::class)->save($session, $rows, null);
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter($log, fn (array $q) => ! str_contains($q['query'], 'attendances')));
        };

        $small = $lookups(2);
        $large = $lookups(20);

        $this->assertGreaterThan(0, $small);
        $this->assertSame($small, $large);
    }

    #[Test]
    public function a_mixed_save_keeps_the_old_mark_and_categorises_the_new_one(): void
    {
        $old = $this->bothActive('Old');
        $new = $this->bothActive('New');
        $session = $this->sessionIn($this->swim, [$this->u15]);
        $this->storedCategory($session, $old);

        DB::table('branch_player')->whereIn('player_id', [$old->id, $new->id])->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);

        app(MarkRecorder::class)->save($session, [
            ['player_id' => $old->id, 'status' => 'absent_excused'],
            ['player_id' => $new->id, 'status' => 'present'],
        ], null);

        $marks = Attendance::where('training_session_id', $session->id)->pluck('category_id', 'player_id');
        $this->assertSame($this->u15->id, $marks[$old->id]);
        $this->assertSame($this->senior->id, $marks[$new->id]);
    }
}
