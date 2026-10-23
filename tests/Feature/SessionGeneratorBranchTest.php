<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\GenerationMarks;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class SessionGeneratorBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedule(int $categoryId, ?int $branchId): TrainingSchedule
    {
        return TrainingSchedule::create([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'weekday' => 1,
            'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01',
        ]);
    }

    /** @return list<string> "branchId date" of every session, sorted */
    private function slots(): array
    {
        return TrainingSession::orderBy('date')->orderBy('branch_id')->get()
            ->map(fn ($s) => ($s->branch_id ?? 0).' '.$s->date)->all();
    }

    #[Test]
    public function each_branch_gets_its_own_sessions_at_the_same_slot(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $foot->id);
        $this->schedule($u15->id, $swim->id);

        // October 2026 Mondays: 5, 12, 19, 26.
        $this->assertSame(8, app(SessionGenerator::class)->forMonth($u15->id, 2026, 10));
        $this->assertSame(4, TrainingSession::where('branch_id', $foot->id)->count());
        $this->assertSame(4, TrainingSession::where('branch_id', $swim->id)->count());
    }

    #[Test]
    public function a_deleted_or_moved_slot_of_one_branch_leaves_the_other_branch_alone(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $foot->id);
        $this->schedule($u15->id, $swim->id);
        DB::table('deleted_session_slots')->insert(['branch_id' => $foot->id, 'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'created_at' => now()]);
        TrainingSession::create([
            'branch_id' => $swim->id, 'category_id' => $u15->id, 'date' => '2026-10-13', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned, 'moved_from' => '2026-10-12',
        ]);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        $this->assertSame(0, TrainingSession::where('branch_id', $foot->id)->where('date', '2026-10-05')->count());
        $this->assertSame(1, TrainingSession::where('branch_id', $swim->id)->where('date', '2026-10-05')->count());
        $this->assertSame(1, TrainingSession::where('branch_id', $foot->id)->where('date', '2026-10-12')->count());
        $this->assertSame(0, TrainingSession::where('branch_id', $swim->id)->where('date', '2026-10-12')->count());
    }

    #[Test]
    public function club_wide_schedules_stay_idempotent_after_marks_are_forgotten(): void
    {
        $u15 = $this->category();
        $this->schedule($u15->id, null);
        $generator = app(SessionGenerator::class);

        $generator->forMonth($u15->id, 2026, 10);
        GenerationMarks::forgetAll();
        $this->assertSame(0, $generator->forMonth($u15->id, 2026, 10));
        $this->assertSame(['0 2026-10-05', '0 2026-10-12', '0 2026-10-19', '0 2026-10-26'], $this->slots());
    }

    #[Test]
    public function for_range_copies_the_schedule_branch(): void
    {
        $u15 = $this->category();
        $swim = Branch::create(['name' => 'Swim']);
        $this->schedule($u15->id, $swim->id);

        app(SessionGenerator::class)->forRange('2026-10-01', '2026-10-31');

        $this->assertSame([$swim->id], TrainingSession::distinct()->pluck('branch_id')->all());
    }
}
