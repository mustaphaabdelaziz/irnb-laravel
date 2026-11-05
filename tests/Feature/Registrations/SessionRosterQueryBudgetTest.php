<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

/** A club of 1,920 players costs the session screen no more queries than a club of three. */
class SessionRosterQueryBudgetTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    private const CLUB_SIZE = 1920;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** Every tenth member also swims. Rows go straight in: a model per player would take minutes. */
    private function addMembers(int $count): void
    {
        $registered = PlayerStatus::where('code', 'registered')->value('id');
        $after = (int) Player::max('id');

        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            DB::table('players')->insert(array_map(fn (int $n) => [
                'membership_id' => 'BUD-'.($after + $n), 'firstname' => 'P'.$n, 'lastname' => 'Budget', 'category_id' => $this->senior->id, 'status_id' => $registered,
            ], $chunk));
        }

        $rows = [];
        foreach (Player::where('id', '>', $after)->orderBy('id')->pluck('id') as $i => $id) {
            $row = ['player_id' => $id, 'status_id' => $registered];
            $rows[] = $row + ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id];
            if ($i % 10 === 0) {
                $rows[] = $row + ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('branch_player')->insert($chunk);
        }
    }

    private function queriesFor(TrainingSession $session): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('attendance.sessions.show', $session))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    #[Test]
    public function the_session_screen_costs_the_same_at_any_club_size(): void
    {
        $this->addMembers(3);
        // Joint, so everyone is on the roster and the category tags are read.
        $joint = $this->sessionIn($this->foot, [$this->senior, $this->u15]);
        // A category nobody is in, so everyone is a candidate instead.
        $empty = $this->sessionIn($this->foot, [Category::create(['name' => 'U17'])]);
        $this->actingAs($this->admin());
        $this->get(route('attendance.sessions.show', $joint))->assertOk();   // settle what is read once
        $this->get(route('attendance.sessions.show', $empty))->assertOk();

        $fewOnRoster = $this->queriesFor($joint);
        $fewCandidates = $this->queriesFor($empty);
        $this->addMembers(self::CLUB_SIZE - 3);

        $this->assertSame($fewOnRoster, $this->queriesFor($joint));
        $this->assertSame($fewCandidates, $this->queriesFor($empty));
    }

    #[Test]
    public function the_expected_roster_is_one_query_at_any_club_size(): void
    {
        $this->addMembers(self::CLUB_SIZE);
        $roster = app(Roster::class);
        $roster->defaultStatusIds();
        $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);   // loads the statuses once

        DB::flushQueryLog();
        DB::enableQueryLog();
        $players = $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);
        DB::disableQueryLog();

        $this->assertCount(self::CLUB_SIZE, $players);
        $this->assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function the_tags_cost_one_load_at_any_club_size(): void
    {
        $this->addMembers(self::CLUB_SIZE);
        $roster = app(Roster::class);
        $players = $roster->expected($this->senior->id, '2026-03-10', null, $this->foot->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $roster->categoryIdsIn($players, $this->swim->id);
        DB::disableQueryLog();

        // The registrations, the main branch, the left status.
        $this->assertCount(3, DB::getQueryLog());
    }
}
