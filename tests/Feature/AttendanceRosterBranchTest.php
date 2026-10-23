<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRosterBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeSession(int $categoryId, ?int $branchId): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function the_roster_lists_only_members_of_the_session_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $footballer = $this->player($u15);
        $swimmer = $this->player($u15);
        $both = $this->player($u15);
        $foot->players()->attach([$footballer->id, $both->id]);
        $swim->players()->attach([$swimmer->id, $both->id]);

        $roster = app(Roster::class);
        $this->assertSame([$footballer->id, $both->id], $roster->forSession($this->makeSession($u15->id, $foot->id))->pluck('id')->all());
        $this->assertSame([$swimmer->id, $both->id], $roster->forSession($this->makeSession($u15->id, $swim->id))->pluck('id')->all());
    }

    #[Test]
    public function a_club_wide_session_lists_every_member_of_the_category(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);

        $this->assertSame([$a->id, $b->id], app(Roster::class)->forSession($this->makeSession($u15->id, null))->pluck('id')->all());
    }

    #[Test]
    public function the_session_page_shows_the_branch_and_lists_its_players_first_among_candidates(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $swimmer = $this->player($u17);   // Test001: alphabetically first
        $footballer = $this->player($u17); // Test002
        $swim->players()->attach($swimmer->id);
        $foot->players()->attach($footballer->id);
        $session = $this->makeSession($u15->id, $foot->id);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.branch.id', $foot->id)
                ->where('candidates.0.id', $footballer->id)
                ->where('candidates.0.in_branch', true)
                ->where('candidates.1.id', $swimmer->id)
                ->where('candidates.1.in_branch', false)
                ->where('candidates.1.branches', 'Swim'));
    }
}
