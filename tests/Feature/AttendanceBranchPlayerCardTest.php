<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\PreseasonTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchPlayerCardTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

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

    #[Test]
    public function a_two_sport_player_gets_a_tab_per_branch_with_its_own_numbers(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();

        $football = $this->actingAs($admin)->getJson(route('attendance.players.show', $both))->assertOk();
        $football->assertJsonPath('branches', [['id' => $foot->id, 'name' => 'Football'], ['id' => $swim->id, 'name' => 'Swimming']])
            ->assertJsonPath('branchId', $foot->id)
            ->assertJsonPath('branch', 'Football')
            ->assertJsonPath('summary.expected', 5)
            ->assertJsonPath('summary.score_pct', 0)
            ->assertJsonPath('risk.at_risk', true)
            ->assertJsonPath('risk.longest_streak', 3);

        $swimming = $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();
        $swimming->assertJsonPath('branchId', $swim->id)
            ->assertJsonPath('summary.score_pct', 100)
            ->assertJsonPath('risk.at_risk', false)
            ->assertJsonCount(5, 'sessions');
        $this->assertSame(0, array_sum($swimming->json('monthly.statuses.absent_unexcused')));
        $this->assertSame(3, array_sum($football->json('monthly.statuses.absent_unexcused')));
    }

    #[Test]
    public function the_default_tab_is_the_remembered_branch_and_the_card_never_changes_it(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.stats', ['branch_id' => $swim->id]))->assertOk();

        $this->actingAs($admin)->getJson(route('attendance.players.show', $both))->assertJsonPath('branchId', $swim->id);
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $foot->id]))->assertJsonPath('branchId', $foot->id);

        $this->assertSame($swim->id, session('attendance.branch_id'));
    }

    #[Test]
    public function tabs_cover_membership_and_marks_and_a_foreign_branch_falls_back(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $swimmer, AttendanceStatus::Present);
        $admin = $this->admin();

        // One branch: one tab (the card hides a single tab) and that branch's numbers.
        $this->actingAs($admin)->getJson(route('attendance.players.show', $footer))
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branchId', $foot->id);
        // A guest mark in football gives the swimmer a football tab too.
        $this->actingAs($admin)->getJson(route('attendance.players.show', $swimmer))
            ->assertJsonCount(2, 'branches');
        // Asking for a branch the player has nothing in shows their first branch instead.
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $footer, 'branch_id' => $swim->id]))
            ->assertJsonPath('branchId', $foot->id);
    }

    #[Test]
    public function injuries_and_preseason_follow_the_tab(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $both, AttendanceStatus::NotTraining, 'injury');
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $foot->id]))
            ->assertJsonCount(1, 'injuries.spells')
            ->assertJsonPath('injuries.spells.0.open', true)
            ->assertJsonPath('preseason.target', 10);
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $swim->id]))
            ->assertJsonCount(0, 'injuries.spells')
            ->assertJsonPath('preseason.target', 4);
    }

    #[Test]
    public function the_report_prints_the_tab_on_screen(): void
    {
        ['swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.report', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();

        $this->assertSame("attendance-{$both->membership_id}-2026-09-01-2027-08-31-b{$swim->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming', $seen['html']);
    }

    #[Test]
    public function the_letter_lists_the_tabs_absences_only(): void
    {
        ['foot' => $foot, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $both, 'branch_id' => $foot->id]))->assertOk();

        $this->assertSame("attendance-letter-{$both->membership_id}-2026-09-01-2027-08-31-b{$foot->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Football', $seen['html']);
        $this->assertStringContainsString('07/09/2026', $seen['html']);   // a football unexcused absence
    }

    #[Test]
    public function the_swimming_letter_has_no_football_absence(): void
    {
        ['swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.players.letter', ['player' => $both, 'branch_id' => $swim->id]))->assertOk();

        $this->assertStringNotContainsString('07/09/2026', $seen['html']);
    }

    #[Test]
    public function a_remembered_branch_the_player_is_not_in_is_ignored(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'footer' => $footer] = $this->twoSportClub();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('attendance.stats', ['branch_id' => $swim->id]))->assertOk();

        $this->actingAs($admin)->getJson(route('attendance.players.show', $footer))->assertJsonPath('branchId', $foot->id);
        $this->assertSame($swim->id, session('attendance.branch_id'));
    }

    #[Test]
    public function a_report_page_link_opens_the_profile_on_its_branch_tab(): void
    {
        // Report pages and the dashboard link to the profile with ?attendance_branch=;
        // the card reads it from the page URL (branchFromUrl, tests/js) and asks for that tab.
        ['swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('players.show', ['player' => $both, 'attendance_branch' => $swim->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Players/Show')->url("/players/{$both->id}?attendance_branch={$swim->id}"));
        $this->actingAs($admin)->getJson(route('attendance.players.show', ['player' => $both, 'branch_id' => $swim->id]))
            ->assertJsonPath('branchId', $swim->id);
    }

    #[Test]
    public function a_club_without_branches_has_no_tabs(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertJsonPath('branches', [])
            ->assertJsonPath('branchId', null)
            ->assertJsonPath('branch', null);
    }
}
