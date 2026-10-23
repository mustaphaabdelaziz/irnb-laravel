<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSettingsBranchTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function schedulePayload(int $categoryId, array $extra = []): array
    {
        return $extra + ['category_id' => $categoryId, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-09-01'];
    }

    #[Test]
    public function a_schedule_needs_a_branch_once_branches_exist(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->schedulePayload($u15->id))->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post(route('attendance.schedules.store'), $this->schedulePayload($u15->id, ['branch_id' => $foot->id]))->assertSessionHasNoErrors();
        $this->assertSame($foot->id, TrainingSchedule::sole()->branch_id);
    }

    #[Test]
    public function targets_are_kept_per_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $admin = $this->admin();

        foreach ([[$foot->id, 10], [$swim->id, 4], [$foot->id, 12]] as [$branchId, $count]) {
            $this->actingAs($admin)->post(route('attendance.preseason-targets.store'), [
                'branch_id' => $branchId, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => $count,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame([$foot->id => 12, $swim->id => 4], PreseasonTarget::orderBy('branch_id')->pluck('target_count', 'branch_id')->all());
    }

    #[Test]
    public function the_page_lists_branches_and_each_schedule_branch(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        TrainingSchedule::create($this->schedulePayload($u15->id, ['branch_id' => $foot->id]));

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches.0.id', $foot->id)
                ->where('schedules.0.branch_id', $foot->id));
    }
}
