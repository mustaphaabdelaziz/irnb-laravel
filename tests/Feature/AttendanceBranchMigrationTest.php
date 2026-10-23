<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchMigrationTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_23_100001_add_branch_to_attendance_tables.php');
    }

    private function makeSession(int $categoryId, ?int $branchId, string $start = '18:00'): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'date' => '2026-10-05', 'start_time' => $start,
            'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function backfill_assigns_the_branch_with_the_most_players(): void
    {
        $u15 = $this->category();
        $swim = Branch::create(['name' => 'Swim']);
        $foot = Branch::create(['name' => 'Foot']);
        $foot->players()->attach([$this->player($u15)->id, $this->player($u15)->id]);
        $swim->players()->attach([$this->player($u15)->id]);

        $session = $this->makeSession($u15->id, null);
        $schedule = TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $target = PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        DB::table('deleted_session_slots')->insert(['category_id' => $u15->id, 'date' => '2026-10-12', 'start_time' => '18:00', 'created_at' => now()]);

        $this->migration()->backfill();

        $this->assertSame($foot->id, $session->fresh()->branch_id);
        $this->assertSame($foot->id, $schedule->fresh()->branch_id);
        $this->assertSame($foot->id, $target->fresh()->branch_id);
        $this->assertSame($foot->id, (int) DB::table('deleted_session_slots')->value('branch_id'));
    }

    #[Test]
    public function backfill_leaves_rows_club_wide_when_there_is_no_branch(): void
    {
        $session = $this->makeSession($this->category()->id, null);

        $this->migration()->backfill();

        $this->assertNull($session->fresh()->branch_id);
    }

    #[Test]
    public function two_branches_may_share_a_category_slot_but_one_branch_may_not(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);

        $this->makeSession($u15->id, $foot->id);
        $this->makeSession($u15->id, $swim->id);
        $this->assertSame(2, TrainingSession::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeSession($u15->id, $foot->id);
    }

    #[Test]
    public function club_wide_sessions_still_collide_on_the_same_slot(): void
    {
        $u15 = $this->category();
        $this->makeSession($u15->id, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeSession($u15->id, null);
    }

    #[Test]
    public function a_branch_with_sessions_or_schedules_cannot_be_deleted(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $this->makeSession($u15->id, $foot->id);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))
            ->assertSessionHas('error', 'flash.branch_has_sessions');
        $this->assertNotNull($foot->fresh());

        TrainingSession::query()->delete();
        TrainingSchedule::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))
            ->assertSessionHas('error', 'flash.branch_has_sessions');
    }

    #[Test]
    public function deleting_an_unused_branch_drops_its_targets_and_tombstones(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        DB::table('deleted_session_slots')->insert(['branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-12', 'start_time' => '18:00', 'created_at' => now()]);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $foot))->assertSessionHas('success');

        $this->assertSame(0, PreseasonTarget::count());
        $this->assertSame(0, DB::table('deleted_session_slots')->count());
    }
}
