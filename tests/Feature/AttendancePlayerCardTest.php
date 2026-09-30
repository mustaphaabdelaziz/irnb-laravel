<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendancePlayerCardTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function heldSession(Category $category, string $date, SessionKind $kind = SessionKind::Regular, ?string $title = null, array $others = []): TrainingSession
    {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => $kind, 'state' => SessionState::Held, 'title' => $title,
        ]);
        foreach ($others as $other) {
            DB::table('training_session_category')->insert(['training_session_id' => $training->id, 'category_id' => $other->id]);
        }

        return $training;
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null, ?string $reason = null, ?string $note = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'status' => $status, 'minutes' => $minutes, 'reason' => $reason, 'note' => $note,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    /** U15 player with a joint pre-season late, an excused absence, and a mark last season. */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $player = $this->player($u15);
        $this->mark($this->heldSession($u15, '2026-10-03', SessionKind::Preseason, 'Running 7.2 km', [$u17]), $player, AttendanceStatus::Late, 12, note: 'Bus');
        $this->mark($this->heldSession($u15, '2026-10-05'), $player, AttendanceStatus::AbsentExcused, reason: 'illness');
        $this->mark($this->heldSession($u15, '2026-08-20'), $player, AttendanceStatus::Present);   // season 2025/26
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);

        return $player;
    }

    #[Test]
    public function the_card_shows_the_current_season_by_default(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('period.period', 'season')
            ->assertJsonPath('period.from', '2026-09-01')
            ->assertJsonPath('period.to', '2027-08-31')
            ->assertJsonPath('summary.expected', 2)
            ->assertJsonPath('summary.counts.late', 1)
            ->assertJsonPath('summary.counts.absent_excused', 1)
            ->assertJsonPath('summary.late_minutes', 12)
            ->assertJsonPath('preseason.season', '2026/27')
            ->assertJsonPath('preseason.done', 1)
            ->assertJsonPath('preseason.target', 12)
            ->assertJsonCount(12, 'monthly.labels')
            ->assertJsonPath('monthly.labels.1', '2026-10')
            ->assertJsonPath('monthly.statuses.late.1', 1)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.date', '2026-10-05')
            ->assertJsonPath('sessions.0.status', 'absent_excused')
            ->assertJsonPath('sessions.0.reason', 'illness')
            ->assertJsonPath('sessions.1.title', 'Running 7.2 km')
            ->assertJsonPath('sessions.1.kind', 'preseason')
            ->assertJsonPath('sessions.1.categories', ['U15', 'U17'])
            ->assertJsonPath('sessions.1.minutes', 12)
            ->assertJsonPath('sessions.1.note', 'Bus');
    }

    #[Test]
    public function a_period_can_be_picked(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertJsonPath('period.period', 'custom')
            ->assertJsonPath('summary.expected', 1)
            ->assertJsonPath('summary.counts.present', 1)
            ->assertJsonPath('preseason.season', '2025/26')
            ->assertJsonPath('sessions.0.date', '2026-08-20');

        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'month']))
            ->assertJsonPath('period.from', '2026-10-01')
            ->assertJsonPath('summary.expected', 2);
    }

    #[Test]
    public function a_player_without_marks_gets_zeros(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('summary.expected', 0)
            ->assertJsonPath('summary.score_pct', null)
            ->assertJsonPath('sessions', []);
    }

    #[Test]
    public function the_card_needs_attendance_view_not_just_players_view(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->userWith(['players' => ['view']]))->getJson(route('attendance.players.show', $player))->assertForbidden();
        $this->actingAs($this->userWith(['attendance' => ['view']]))->getJson(route('attendance.players.show', $player))->assertOk();
    }

    #[Test]
    public function the_profile_sends_the_codes_only_to_attendance_viewers(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->userWith(['players' => ['view'], 'attendance' => ['view']]))
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/Show')
                ->where('attendanceCodes.present.code', 'P')
                ->where('attendanceCodes.late.color', '#f59e0b'));

        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attendanceCodes', null));
    }

    #[Test]
    public function the_card_flags_a_player_at_risk_for_its_period(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        foreach (['2026-10-01', '2026-10-03', '2026-10-05'] as $date) {
            $this->mark($this->heldSession($u15, $date), $player, AttendanceStatus::AbsentUnexcused);
        }
        $this->mark($this->heldSession($u15, '2026-10-07'), $player, AttendanceStatus::Present);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('risk.at_risk', true)
            ->assertJsonPath('risk.streak', true)
            ->assertJsonPath('risk.low_score', false)      // 4 sessions: below the score rule's minimum of 5
            ->assertJsonPath('risk.current_streak', 0)
            ->assertJsonPath('risk.longest_streak', 3)
            ->assertJsonPath('risk.unexcused_streak', 3)
            ->assertJsonPath('risk.min_score_pct', 60);

        // September holds none of it.
        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertJsonPath('risk.at_risk', false)
            ->assertJsonPath('risk.longest_streak', 0);
    }
}
