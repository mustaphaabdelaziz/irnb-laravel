<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchRankingTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private function season(array $extra): array
    {
        return ['type' => 'season', 'season' => 2026] + $extra;
    }

    #[Test]
    public function each_branch_ranks_its_own_marks(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();

        $this->actingAs($this->admin())->get(route('attendance.ranking', $this->season(['category_id' => $u15->id, 'branch_id' => $foot->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('branchId', $foot->id)
                ->has('branches', 2)
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$footer->id, $both->id])
                ->where('unranked', 0));

        // Both at 100 % in swimming: ties go to the lower id.
        $this->actingAs($this->admin())->get(route('attendance.ranking', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$both->id, $swimmer->id])
                ->where('rows.0.score_pct', 100));
    }

    #[Test]
    public function the_podium_certificates_name_the_branch_and_its_players(): void
    {
        ['swim' => $swim, 'u15' => $u15, 'both' => $both, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id])))->assertOk();

        $this->assertSame("certificates-{$u15->id}-b{$swim->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · U15', $seen['html']);
        $this->assertStringContainsString($both->fullname, $seen['html']);
        $this->assertStringContainsString($swimmer->fullname, $seen['html']);
        $this->assertStringNotContainsString($footer->fullname, $seen['html']);
    }

    #[Test]
    public function no_certificate_for_a_player_outside_the_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'footer' => $footer, 'swimmer' => $swimmer] = $this->twoSportClub();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $swim->id, 'player_id' => $footer->id])))
            ->assertNotFound();
        $this->actingAs($admin)->get(route('attendance.certificates', $this->season(['category_id' => $u15->id, 'branch_id' => $foot->id, 'player_id' => $swimmer->id])))
            ->assertNotFound();
    }
}
