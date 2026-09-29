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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}
