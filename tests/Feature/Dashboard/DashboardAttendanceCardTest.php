<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Dashboard\AttendanceCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class DashboardAttendanceCardTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // a Tuesday (ISO weekday 2)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    /** The members tab, as the client asks for it (partial reload). */
    private function membersTab(User $user): array
    {
        return $this->actingAs($user)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'members',
        ])->assertOk()->json('props.members');
    }

    private function heldSession(Category $category, string $date, ?string $title = null): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'title' => $title,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
    }

    #[Test]
    public function the_members_tab_shows_todays_sessions_and_the_last_30_days(): void
    {
        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $this->mark($this->heldSession($u15, '2026-10-20', 'Sprints'), $a, AttendanceStatus::Present);
        $this->mark($this->heldSession($u15, '2026-09-21'), $a, AttendanceStatus::Late, 5);        // day 30 of the window
        $this->mark($this->heldSession($u15, '2026-09-20'), $a, AttendanceStatus::AbsentUnexcused); // day 31: outside

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertSame('2026-10-20', $card['date']);
        $this->assertCount(1, $card['today']);
        $this->assertSame('Sprints', $card['today'][0]['title']);
        $this->assertSame('held', $card['today'][0]['state']);
        $this->assertSame('U15', $card['today'][0]['categories'][0]['name']);
        $this->assertSame('2026-09-21', $card['last30']['from']);
        $this->assertSame('2026-10-20', $card['last30']['to']);
        $this->assertSame(2, $card['last30']['expected']);
        $this->assertSame(1, $card['last30']['counts']['present']);
        $this->assertSame(1, $card['last30']['counts']['late']);
        $this->assertSame(0, $card['last30']['counts']['absent_unexcused']);
    }

    #[Test]
    public function a_missing_training_day_is_generated_even_in_a_month_marked_as_generated(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))->assertOk();
        // Removed behind the app's back: no model event forgot the month's mark.
        DB::table('training_sessions')->where('date', '2026-10-20')->delete();

        $card = $this->membersTab($admin)['attendance'];

        $this->assertCount(1, $card['today']);
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-10-20')->exists());
    }

    #[Test]
    public function a_training_day_is_generated_when_nobody_opened_the_month_yet(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertCount(1, $card['today']);
        $this->assertSame('planned', $card['today'][0]['state']);
        $this->assertSame('18:00', $card['today'][0]['start_time']);
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-10-20')->exists());
    }

    #[Test]
    public function an_extra_session_today_does_not_hide_the_regular_slot(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned, 'title' => 'Extra fitness',
        ]);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertCount(2, $card['today']);
        $this->assertSame('09:00', $card['today'][0]['start_time']);
        $this->assertSame('extra', $card['today'][0]['kind']);
        $this->assertSame('18:00', $card['today'][1]['start_time']);
        $this->assertSame('regular', $card['today'][1]['kind']);
        $this->assertSame('planned', $card['today'][1]['state']);
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-10-20')->where('kind', SessionKind::Regular->value)->exists());
    }

    #[Test]
    public function a_slot_moved_away_from_today_is_not_regenerated(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-21', 'moved_from' => '2026-10-20', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertSame([], $card['today']);
        $this->assertFalse(TrainingSession::where('category_id', $u15->id)->where('date', '2026-10-20')->exists());
    }

    #[Test]
    public function nothing_is_generated_on_a_closure_day(): void
    {
        $u15 = $this->category('U15');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01']);
        ClubClosure::create(['start_date' => '2026-10-19', 'end_date' => '2026-10-25', 'reason' => 'Holidays']);

        $card = $this->membersTab($this->admin())['attendance'];

        $this->assertSame([], $card['today']);
        $this->assertFalse(TrainingSession::where('category_id', $u15->id)->exists());
    }

    #[Test]
    public function the_card_and_the_codes_need_attendance_view(): void
    {
        $playersOnly = $this->userWith(['players' => ['view']]);
        $this->assertNull($this->membersTab($playersOnly)['attendance']);
        $this->actingAs($playersOnly)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes', null));

        $both = $this->userWith(['players' => ['view'], 'attendance' => ['view']]);
        $this->assertIsArray($this->membersTab($both)['attendance']);
        $this->actingAs($both)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes.present.code', 'P'));
    }

    #[Test]
    public function the_card_counts_this_seasons_players_at_risk_and_lists_the_five_worst(): void
    {
        $u15 = $this->category('U15');
        $sessions = array_map(fn (string $date) => $this->heldSession($u15, $date), ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05']);
        $atRisk = [];
        foreach (range(1, 7) as $i) {
            $player = $this->player($u15);
            foreach ($sessions as $n => $training) {
                $this->mark($training, $player, $n < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            }
            $atRisk[] = $player->id;
        }
        $fine = $this->player($u15);
        foreach ($sessions as $training) {
            $this->mark($training, $fine, AttendanceStatus::Present);
        }
        // Last season: not counted.
        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $date) {
            $this->mark($this->heldSession($u15, $date), $fine, AttendanceStatus::AbsentUnexcused);
        }

        $risk = $this->membersTab($this->admin())['attendance']['risk'];

        $this->assertSame('season', $risk['period']['period']);
        $this->assertSame('2026-09-01', $risk['period']['from']);
        $this->assertSame(7, $risk['count']);
        $this->assertSame(array_slice($atRisk, 0, 5), array_column($risk['worst'], 'player_id'));   // all at 0 %: by name
        $this->assertSame(0, $risk['worst'][0]['score_pct']);   // JSON: 0.0 comes back as 0
        $this->assertSame(3, $risk['worst'][0]['longest_streak']);
        $this->assertTrue($risk['worst'][0]['streak']);
        $this->assertTrue($risk['worst'][0]['low_score']);
        $this->assertSame('U15', $risk['worst'][0]['category']);
    }

    #[Test]
    public function the_risk_card_costs_the_same_queries_for_3_or_30_players_at_risk(): void
    {
        $u15 = $this->category('U15');
        $sessions = array_map(fn (string $date) => $this->heldSession($u15, $date), ['2026-09-07', '2026-09-14', '2026-09-21']);
        $flag = function (int $n) use ($u15, $sessions): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($sessions as $training) {
                    $this->mark($training, $player, AttendanceStatus::AbsentUnexcused);
                }
            }
        };
        $measure = function (): int {
            WebsiteConfig::singleton();   // loads the settings once, as AttendanceAtRiskTest does
            $card = app(AttendanceCard::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $card->get();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $flag(3);
        $few = $measure();
        $flag(27);

        $this->assertSame($few, $measure());
    }
}
