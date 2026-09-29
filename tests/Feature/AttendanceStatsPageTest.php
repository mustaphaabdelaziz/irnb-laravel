<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function heldSession(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'status' => $status, 'minutes' => $minutes]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_page_shows_this_months_figures_by_default(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $october = $this->heldSession($u15, '2026-10-05');
        $this->mark($october, $a, AttendanceStatus::Present);
        $this->mark($october, $b, AttendanceStatus::AbsentUnexcused);   // a guest in U15's session
        $this->mark($this->heldSession($u15, '2026-09-28'), $a, AttendanceStatus::Late, 5);   // last month

        $this->actingAs($this->admin())->get(route('attendance.stats'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('period.period', 'month')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->where('totals.expected', 2)
                ->where('totals.held', 1)
                ->where('totals.cancelled', 0)
                ->where('totals.counts.present', 1)
                ->where('totals.counts.late', 0)
                ->where('monthly.labels', ['2026-10'])
                ->where('monthly.statuses.absent_unexcused', [1])
                ->where('sessionsByMonth.held', [1])
                ->has('players', 2)
                ->where('players.0.player_id', $a->id)
                ->where('players.0.name', $a->fullname)
                ->where('players.0.category', 'U15')
                // JSON has no 100 vs 100.0 distinction; Inertia assertions round-trip through json, so a whole-number float compares as an int.
                ->where('players.0.score_pct', 100)
                ->where('players.1.player_id', $b->id)
                ->where('players.1.category', 'U17')
                ->where('ranking.min_expected', 5)
                ->where('ranking.top', [])
                ->has('categoryRows', 2)
                ->where('categoryRows.0.held', 1)
                ->where('categoryRows.0.expected', 2)
                ->where('categoryRows.1.expected', 0)
                ->where('attendanceCodes.present.code', 'P'));
    }

    #[Test]
    public function a_category_and_a_custom_period_narrow_the_figures(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $own = $this->heldSession($u15, '2026-10-05');
        $this->mark($own, $a, AttendanceStatus::Present);
        $this->mark($own, $b, AttendanceStatus::Present);   // a guest: belongs to U15's figures
        $this->mark($this->heldSession($u17, '2026-09-15'), $b, AttendanceStatus::Late, 10);

        $this->actingAs($this->admin())
            ->get(route('attendance.stats', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31', 'category_id' => $u17->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.period', 'custom')
                ->where('period.label', '2026-09-01 – 2026-10-31')
                ->where('categoryId', $u17->id)
                ->where('monthly.labels', ['2026-09', '2026-10'])
                ->has('players', 1)
                ->where('players.0.player_id', $b->id)
                ->where('players.0.expected', 1)
                ->where('players.0.late_minutes', 10)
                ->where('sessionsByMonth.held', [1, 0]));
    }

    #[Test]
    public function the_page_needs_attendance_view(): void
    {
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.stats'));

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.stats'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.stats'))->assertForbidden();
    }
}
