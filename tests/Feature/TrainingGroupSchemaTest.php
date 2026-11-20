<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingGroupSchemaTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_group_holds_dated_members_and_links_schedules_and_sessions(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $player = $this->player($minime);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $group->members()->create(['player_id' => $player->id, 'from_date' => '2026-11-01', 'to_date' => null]);

        $this->assertFalse($group->isInUse());

        $schedule = TrainingSchedule::create([
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $group->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        $session = TrainingSession::create([
            'branch_id' => $swim->id, 'category_id' => $minime->id, 'group_id' => $group->id, 'date' => '2026-11-21',
            'start_time' => '09:30', 'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->assertTrue($schedule->group->is($group));
        $this->assertTrue($session->fresh()->group->is($group));
        $this->assertSame($group->id, $session->fresh()->group_id);
        $this->assertTrue($group->isInUse());
        $this->assertSame([$player->id], TrainingGroupMember::activeOn('2026-11-21')->pluck('player_id')->all());
        $this->assertSame([], TrainingGroupMember::activeOn('2026-10-31')->pluck('player_id')->all());
        $this->assertSame('2026-11-01', TrainingGroupMember::sole()->from_date);
    }

    #[Test]
    public function a_category_with_a_group_is_in_use_and_deleting_a_group_removes_its_members(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $group->members()->create(['player_id' => $this->player($minime)->id]);

        $this->assertTrue($minime->isInUse());

        $group->delete();

        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function deleting_a_branch_removes_its_unused_groups(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $group = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);

        $this->actingAs($this->admin())->delete(route('branches.destroy', $swim))
            ->assertSessionHas('success', 'flash.branch_deleted');

        $this->assertModelMissing($group);
        $this->assertModelMissing($minime);
    }

    #[Test]
    public function the_first_branch_adopts_club_wide_training_groups(): void
    {
        $category = Category::create(['name' => 'Minime']);
        $group = TrainingGroup::create(['branch_id' => null, 'category_id' => $category->id, 'name' => 'G1']);

        $branch = Branch::create(['name' => 'Swim']);

        $this->assertSame($branch->id, $group->fresh()->branch_id);
    }
}
