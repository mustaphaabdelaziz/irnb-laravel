<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchStatsPageTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const PERIOD = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31'];

    #[Test]
    public function the_page_shows_one_branchs_numbers(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both, 'swimmer' => $swimmer] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('branchId', $swim->id)
                ->has('branches', 2)
                ->where('branches.0.name', 'Football')
                ->where('totals.held', 5)
                ->where('totals.expected', 10)
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$both->id, $swimmer->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $both->id)['score_pct'] == 100));

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => $foot->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $both->id)['score_pct'] == 0)
                ->where('categoryRows.0.expected', 10));
    }

    #[Test]
    public function the_branch_defaults_to_the_first_then_is_shared_with_the_calendar(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15] = $this->twoSportClub();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $foot->id));
        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id]))->assertOk();

        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id]))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', $swim->id));
    }

    #[Test]
    public function an_unknown_branch_is_refused(): void
    {
        $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD + ['branch_id' => 999]))
            ->assertSessionHasErrors('branch_id');
    }

    #[Test]
    public function the_spreadsheet_is_one_branchs(): void
    {
        ['swim' => $swim, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', self::PERIOD + ['branch_id' => $swim->id, 'format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("attendance-stats-2026-09-01-2026-10-31-b{$swim->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Swimming — ', $csv);
        $this->assertStringContainsString($both->fullname, $csv);
        $this->assertStringContainsString($swimmer->fullname, $csv);
        $this->assertStringNotContainsString($footer->fullname, $csv);
    }

    #[Test]
    public function the_pdf_names_the_branch(): void
    {
        ['foot' => $foot] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.stats.export', self::PERIOD + ['branch_id' => $foot->id, 'format' => 'pdf']))->assertOk();

        $this->assertSame("attendance-stats-2026-09-01-2026-10-31-b{$foot->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Football', $seen['html']);
    }

    #[Test]
    public function a_club_without_branches_has_no_switch(): void
    {
        $this->category('U15');

        $this->actingAs($this->admin())->get(route('attendance.stats', self::PERIOD))
            ->assertInertia(fn (Assert $page) => $page->where('branchId', null)->has('branches', 0));
    }
}
