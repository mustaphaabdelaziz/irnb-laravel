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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAlertsPageTest extends TestCase
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

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function held(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id, 'status' => $status]);
    }

    /**
     * U15 "risky": 3 unexcused in a row, then 2 present (0 %, longest streak 3).
     * U15 "fine": always present this season. U17 "weak": 3 excused, then 2 present (40 %).
     *
     * @return array{0: Player, 1: Player, 2: Player}
     */
    private function seedRisk(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $risky = $this->player($u15);
        $fine = $this->player($u15);
        $weak = $this->player($u17);
        foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-10-05', '2026-10-12'] as $i => $date) {
            $training = $this->held($u15, $date);
            $this->mark($training, $risky, $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            $this->mark($training, $fine, AttendanceStatus::Present);
            $this->mark($this->held($u17, $date), $weak, $i < 3 ? AttendanceStatus::AbsentExcused : AttendanceStatus::Present);
        }
        // Last season: outside the default period.
        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $date) {
            $this->mark($this->held($u15, $date), $fine, AttendanceStatus::AbsentUnexcused);
        }

        return [$risky, $fine, $weak];
    }

    #[Test]
    public function the_page_lists_this_seasons_players_at_risk_by_default(): void
    {
        [$risky, , $weak] = $this->seedRisk();

        $this->actingAs($this->admin())->get(route('attendance.alerts'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('period.period', 'season')
                ->where('period.from', '2026-09-01')
                ->where('period.to', '2027-08-31')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->where('thresholds.min_score_pct', 60)
                ->where('thresholds.unexcused_streak', 3)
                ->where('thresholds.min_expected', 5)
                ->has('rows', 2)
                ->where('rows.0.player_id', $risky->id)
                ->where('rows.0.name', $risky->fullname)
                ->where('rows.0.category', 'U15')
                ->where('rows.0.expected', 5)
                ->where('rows.0.score_pct', 0)          // JSON: 0.0 comes back as 0
                ->where('rows.0.unexcused', 3)
                ->where('rows.0.current_streak', 0)
                ->where('rows.0.longest_streak', 3)
                ->where('rows.0.last_date', '2026-10-12')
                ->where('rows.0.low_score', true)
                ->where('rows.0.streak', true)
                ->where('rows.1.player_id', $weak->id)
                ->where('rows.1.score_pct', 40)
                ->where('rows.1.low_score', true)
                ->where('rows.1.streak', false));
    }

    #[Test]
    public function a_category_and_a_period_narrow_the_list(): void
    {
        [, , $weak] = $this->seedRisk();

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['category_id' => $weak->category_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $weak->category_id)
                ->has('rows', 1)
                ->where('rows.0.player_id', $weak->id));

        // October alone: both played every session.
        $this->actingAs($this->admin())->get(route('attendance.alerts', ['period' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.period', 'month')
                ->where('period.from', '2026-10-01')
                ->has('rows', 0));
    }

    #[Test]
    public function the_export_lists_the_same_rows(): void
    {
        [$risky] = $this->seedRisk();

        $response = $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.alerts.export', ['format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('attendance-at-risk-2026-09-01-2027-08-31.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Joueurs à risque — 2026/27', $csv);
        $this->assertStringContainsString('Série en cours', $csv);
        $this->assertStringContainsString('Plus longue série', $csv);
        $this->assertStringContainsString($risky->fullname, $csv);
        $this->assertStringContainsString(',U15,5,0,3,0,3,2026-10-12,', $csv);   // expected, score %, unexcused, current, longest, last
        $this->assertStringContainsString('Score faible', $csv);
    }

    #[Test]
    public function the_page_and_the_export_need_attendance_view_only(): void
    {
        $this->seedRisk();
        $viewer = $this->userWith(['attendance' => ['view']]);
        $playersOnly = $this->userWith(['players' => ['view']]);

        $this->actingAs($viewer)->get(route('attendance.alerts'))->assertOk();
        $this->actingAs($viewer)->get(route('attendance.alerts.export', ['format' => 'csv']))->assertOk();
        $this->actingAs($playersOnly)->get(route('attendance.alerts'))->assertForbidden();
        $this->actingAs($playersOnly)->get(route('attendance.alerts.export'))->assertForbidden();
    }
}
