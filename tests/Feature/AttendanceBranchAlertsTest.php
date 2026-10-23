<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\TrainingSession;
use App\Models\WebsiteConfig;
use App\Services\Attendance\AtRisk;
use App\Services\Dashboard\AttendanceCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchAlertsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

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

    /** "both" also misses three swimming sessions in a row: flagged in swimming too (25 %, streak 3), worse in football (0 %). */
    private function flaggedInBoth(): array
    {
        $club = $this->twoSportClub();
        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
            $this->branchMark($this->branchSession($club['swim'], $club['u15'], $date), $club['both'], AttendanceStatus::AbsentUnexcused);
        }

        return $club;
    }

    #[Test]
    public function the_alerts_page_lists_one_branchs_players_at_risk(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $foot->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->has('rows', 1)
                ->where('rows.0.player_id', $both->id)
                ->where('rows.0.longest_streak', 3));

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id)->has('rows', 0));
    }

    #[Test]
    public function a_guest_is_judged_on_the_marks_in_the_sessions_branch(): void
    {
        ['foot' => $foot, 'swimmer' => $swimmer] = $this->twoSportClub();
        // The swimmer, hand-added to three football sessions, missed them all.
        foreach (TrainingSession::where('branch_id', $foot->id)->orderBy('date')->limit(3)->get() as $training) {
            $this->branchMark($training, $swimmer, AttendanceStatus::AbsentUnexcused);
        }

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('player_id', $swimmer->id)['longest_streak'] === 3));
    }

    #[Test]
    public function the_export_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('attendance.alerts.export', ['branch_id' => $foot->id, 'format' => 'csv']))->assertOk();
        $this->assertStringContainsString("-b{$foot->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Football — ', $response->streamedContent());
        $this->assertStringContainsString($both->fullname, $response->streamedContent());

        $swimCsv = $this->actingAs($admin)->get(route('attendance.alerts.export', ['branch_id' => $swim->id, 'format' => 'csv']))->streamedContent();
        $this->assertStringNotContainsString($both->fullname, $swimCsv);
    }

    #[Test]
    public function the_dashboard_counts_a_player_flagged_in_two_branches_once_with_the_worst_branch(): void
    {
        ['foot' => $foot, 'both' => $both] = $this->flaggedInBoth();

        $risk = app(AttendanceCard::class)->get()['risk'];

        $this->assertSame(1, $risk['count']);
        $this->assertSame($both->id, $risk['worst'][0]['player_id']);
        $this->assertSame($foot->id, $risk['worst'][0]['branch_id']);
        $this->assertSame('Football', $risk['worst'][0]['branch']);
    }

    #[Test]
    public function any_branch_costs_a_fixed_number_of_queries(): void
    {
        $this->flaggedInBoth();
        $risk = app(AtRisk::class);
        WebsiteConfig::singleton();
        $risk->thresholds();   // loads the settings once

        DB::flushQueryLog();
        DB::enableQueryLog();
        $risk->anyBranch('2026-09-01', '2027-08-31');
        DB::disableQueryLog();

        // The branches: 1, the custom codes: 1, per branch players() 2 + the streak scan 1 (× 2), names: 2.
        $this->assertSame(10, count(DB::getQueryLog()));
    }
}
