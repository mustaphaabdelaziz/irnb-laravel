<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchPagesTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

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

    #[Test]
    public function the_injuries_page_is_one_branchs(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->injuredFootballer();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.injuries', ['branch_id' => $foot->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->has('current', 1)
                ->where('current.0.player_id', $both->id)
                ->where('current.0.start', '2026-10-05'));

        $this->actingAs($admin)->get(route('attendance.injuries', ['branch_id' => $swim->id]))
            ->assertInertia(fn (Assert $page) => $page->has('current', 0)->has('spells', 0));
    }

    #[Test]
    public function the_session_sheet_names_its_branch(): void
    {
        [, $swim] = $this->twoSports();
        $training = $this->branchSession($swim, $this->category('U15'), '2026-10-05', ['state' => SessionState::Planned]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringContainsString('<b>Swimming</b>', $seen['html']);
    }

    #[Test]
    public function a_club_wide_session_sheet_names_no_branch(): void
    {
        $training = TrainingSession::create([
            'category_id' => $this->category('U15')->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.session', $training))->assertOk();

        $this->assertStringNotContainsString('&middot; <b>U15</b>', $seen['html']);
        $this->assertStringContainsString('<b>U15</b>', $seen['html']);
    }
}
