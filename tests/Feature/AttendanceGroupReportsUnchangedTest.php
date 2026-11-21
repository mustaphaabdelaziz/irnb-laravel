<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\TrainingSession;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Dashboard\AttendanceCard;
use App\Services\LiteraryReport\Sections\AttendanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

/**
 * What the reports' group filter must never change: a category's numbers
 * while no group is chosen, and the pages that have no group filter at all
 * (injuries, profile card, player PDF, parent letter, dashboard, literary
 * report). Written before the filter existed; it must pass before and after.
 */
class AttendanceGroupReportsUnchangedTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-11-30';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_services_count_every_session_of_the_category(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $stats = app(AttendanceStats::class);

        $rows = $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id);
        $this->assertSame([$a->id, $b->id, $mover->id], array_keys($rows));
        $this->assertSame([12, 12, 12], array_column($rows, 'expected'));
        $this->assertSame([100.0, 50.0, 50.0], array_column($rows, 'score_pct'));
        $this->assertSame([0, 360, 270], array_column($rows, 'missed_minutes'));

        $sessions = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id);
        $this->assertSame([10, 10, 2], $sessions['held']);
        $this->assertSame([0, 0, 1], $sessions['cancelled']);
        $this->assertSame([6, 0, 0], $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id)['statuses']['absent_unexcused']);

        // Read together, the whole-category Tuesdays break both streaks.
        $streaks = $stats->unexcusedStreaks(self::FROM, self::TO, null, $swim->id);
        $this->assertSame(1, $streaks[$b->id]['longest']);
        $this->assertSame(1, $streaks[$mover->id]['longest']);

        $atRisk = app(AtRisk::class)->list(self::FROM, self::TO, $minime->id, $swim->id);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($atRisk, 'player_id'));
        $this->assertSame([false, false], array_column($atRisk, 'streak'));
    }

    #[Test]
    public function the_three_report_pages_show_the_whole_category_when_no_group_is_asked(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $admin = $this->admin();
        $filters = ['branch_id' => $swim->id, 'category_id' => $minime->id];

        $this->actingAs($admin)->get(route('attendance.stats', $filters + ['period' => 'custom', 'from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('totals.held', 22)
                ->where('totals.cancelled', 1)
                ->where('totals.expected', 36)
                ->where('totals.score_pct', 66.7)
                ->where('totals.missed_hours', 10.5)
                ->where('categoryRows.0.held', 22)
                ->where('categoryRows.0.expected', 36));

        $this->actingAs($admin)->get(route('attendance.ranking', $filters + ['type' => 'season', 'season' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $b->id, $mover->id])
                ->where('rows.1.score_pct', 50)
                ->where('unranked', 0));

        $this->actingAs($admin)->get(route('attendance.alerts', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                ->where('rows.0.longest_streak', 1)
                ->where('rows.1.longest_streak', 1));
    }

    #[Test]
    public function an_injury_spell_runs_across_group_and_whole_category_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1] = $this->splitCategory();
        $hurt = $this->player($minime);
        $swim->players()->attach($hurt->id, ['membership_id' => $hurt->membership_id]);
        $saturday = TrainingSession::where('group_id', $g1->id)->where('date', '2026-11-07')->sole();
        $this->branchMark($saturday, $hurt, AttendanceStatus::NotTraining, 'injury');
        $tuesday = $this->branchSession($swim, $minime, '2026-11-10', ['start_time' => '17:00', 'end_time' => '18:30']);
        $this->branchMark($tuesday, $hurt, AttendanceStatus::AbsentExcused, 'injury');

        // The injuries page has no group filter: a group_id in its URL changes nothing.
        $this->actingAs($this->admin())
            ->get(route('attendance.injuries', ['branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->has('current', 1)
                ->where('current.0.player_id', $hurt->id)
                ->where('current.0.start', '2026-11-07')
                ->where('current.0.end', '2026-11-10')
                ->where('current.0.sessions', 2)
                ->where('current.0.open', true)
                ->missing('groups')
                ->missing('groupId'));
    }

    #[Test]
    public function the_profile_card_counts_both_groups_of_a_moved_player(): void
    {
        ['swim' => $swim, 'g1' => $g1, 'mover' => $mover] = $this->splitCategory();

        $card = $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertJsonPath('summary.expected', 12)
            ->assertJsonPath('summary.score_pct', 50)
            ->assertJsonPath('summary.missed_minutes', 270)
            ->assertJsonPath('risk.low_score', true)
            ->assertJsonPath('risk.streak', false)
            ->assertJsonPath('risk.longest_streak', 1);

        $this->assertCount(12, $card->json('sessions'));
    }

    #[Test]
    public function the_player_pdf_lists_the_sessions_of_both_groups(): void
    {
        ['swim' => $swim, 'g1' => $g1, 'mover' => $mover] = $this->splitCategory();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.players.report', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk();

        $this->assertStringEndsWith("-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('<td class="num">12</td>', $seen['html']);   // expected
        foreach (['2026-09-05', '2026-09-08', '2026-11-07'] as $date) {               // G1, whole category, G2
            $this->assertStringContainsString($date, $seen['html']);
        }
    }

    #[Test]
    public function the_parent_letter_lists_the_absences_of_every_session(): void
    {
        ['swim' => $swim, 'g2' => $g2, 'mover' => $mover] = $this->splitCategory();
        $seen = $this->spyPdf();

        // Asked "for G2", where the player missed nothing: the letter still lists the three absences with G1.
        $this->actingAs($this->admin())
            ->get(route('attendance.players.letter', ['player' => $mover->id, 'branch_id' => $swim->id, 'group_id' => $g2->id]))
            ->assertOk();

        $this->assertStringEndsWith("-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        foreach (['05/09/2026', '12/09/2026', '19/09/2026'] as $day) {
            $this->assertStringContainsString($day, $seen['html']);
        }
    }

    #[Test]
    public function the_dashboard_card_counts_every_group(): void
    {
        ['b' => $b, 'mover' => $mover] = $this->splitCategory();

        $card = app(AttendanceCard::class)->get();

        // The last 30 days hold three Saturdays: one G1 mark and two G2 marks each.
        $this->assertSame(9, $card['last30']['expected']);
        $this->assertSame(9, $card['last30']['counts']['present']);
        $this->assertSame(2, $card['risk']['count']);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($card['risk']['worst'], 'player_id'));
        $this->assertSame([1, 1], array_column($card['risk']['worst'], 'longest_streak'));
    }

    #[Test]
    public function the_literary_report_counts_every_group(): void
    {
        $this->splitCategory();

        $data = app(AttendanceSection::class)->build(CarbonImmutable::parse(self::FROM), CarbonImmutable::parse(self::TO));

        // 30 of the 36 marks are present: 83.3 %.
        $this->assertSame(22, $data['held']);
        $this->assertSame([['name' => 'Swimming', 'held' => 22, 'rate' => 83.3]], $data['branches']);
        $this->assertSame([['name' => 'Minime', 'held' => 22, 'expected' => 36, 'rate' => 83.3]], $data['categories']);
    }
}
