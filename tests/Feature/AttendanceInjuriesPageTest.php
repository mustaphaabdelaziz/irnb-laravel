<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjuriesPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
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

    private function mark(Category $category, string $date, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        $training = TrainingSession::firstOrCreate(
            ['category_id' => $category->id, 'date' => $date, 'start_time' => '18:00'],
            ['end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Held],
        );
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status, 'reason' => $reason,
        ]);
    }

    /**
     * U15 X: a spell 2026-10-02 → 03, then one open since 2026-10-12 (with a detail).
     * U17 W: a spell on 2026-09-10, over. Last season, X: a spell in May 2026.
     *
     * @return array{0: Player, 1: Player}
     */
    private function seedData(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $w = $this->player($u17);
        $this->mark($u15, '2026-05-04', $x, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-05-06', $x, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-02', $x, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-10-03', $x, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u15, '2026-10-05', $x, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-12', $x, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u17, '2026-09-10', $w, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u17, '2026-09-12', $w, AttendanceStatus::Present);
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-12', 'body_part' => 'Genou']);

        return [$x, $w];
    }

    #[Test]
    public function the_page_lists_current_injuries_and_this_seasons_spells(): void
    {
        [$x, $w] = $this->seedData();

        $this->actingAs($this->admin())->get(route('attendance.injuries'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->where('period.period', 'season')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->has('current', 1)
                ->where('current.0.player_id', $x->id)
                ->where('current.0.name', $x->fullname)
                ->where('current.0.category', 'U15')
                ->where('current.0.start', '2026-10-12')
                ->where('current.0.open', true)
                ->where('current.0.note.body_part', 'Genou')
                ->has('spells', 3)
                ->where('spells.0.start', '2026-10-12')
                ->where('spells.1.start', '2026-10-02')
                ->where('spells.1.sessions', 2)
                ->where('spells.2.player_id', $w->id)
                ->where('spells.2.start', '2026-09-10'));
    }

    #[Test]
    public function a_category_and_a_period_narrow_the_lists(): void
    {
        [$x, $w] = $this->seedData();

        $this->actingAs($this->admin())->get(route('attendance.injuries', ['category_id' => $w->category_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('current', 0)
                ->has('spells', 1)
                ->where('spells.0.player_id', $w->id));

        $this->actingAs($this->admin())->get(route('attendance.injuries', ['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('current', 1)            // current injuries do not depend on the period
                ->has('spells', 1)
                ->where('spells.0.player_id', $x->id)
                ->where('spells.0.start', '2026-05-04'));
    }

    #[Test]
    public function the_page_needs_attendance_view_only(): void
    {
        $this->seedData();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.injuries'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.injuries'))->assertForbidden();
    }
}
