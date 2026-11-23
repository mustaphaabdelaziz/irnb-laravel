<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
    }

    private function group(string $name = 'G1'): TrainingGroup
    {
        return TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => $name]);
    }

    private function swimmer(): \App\Models\Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    #[Test]
    public function a_group_is_created_renamed_and_its_name_is_unique_in_the_category(): void
    {
        $admin = $this->admin();
        $payload = ['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => ' G1 '];

        $this->actingAs($admin)->post(route('attendance.groups.store'), $payload)
            ->assertSessionHas('success', 'flash.training_group_saved');
        $group = TrainingGroup::sole();
        $this->assertSame('G1', $group->name);

        $this->actingAs($admin)->post(route('attendance.groups.store'), $payload)
            ->assertSessionHasErrors(['name' => 'att.error.group_name_taken']);

        $this->actingAs($admin)->put(route('attendance.groups.update', $group), ['name' => 'Morning'])
            ->assertSessionHas('success', 'flash.training_group_saved');
        $this->assertSame('Morning', $group->fresh()->name);
    }

    #[Test]
    public function a_category_of_another_branch_is_refused(): void
    {
        $foot = Branch::create(['name' => 'Foot']);

        $this->actingAs($this->admin())->post(route('attendance.groups.store'), [
            'branch_id' => $foot->id, 'category_id' => $this->minime->id, 'name' => 'G1',
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, TrainingGroup::count());
    }

    #[Test]
    public function a_player_with_a_group_in_two_branches_counts_and_shows_in_both(): void
    {
        Carbon::setTestNow('2026-11-21');
        $foot = Branch::create(['name' => 'Foot']);
        $u13 = Category::create(['name' => 'U13', 'branch_id' => $foot->id]);
        $s1 = $this->group('S1');
        $f1 = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $u13->id, 'name' => 'F1']);

        $player = $this->swimmer();
        $foot->players()->attach($player->id);
        \App\Models\BranchRegistration::where('player_id', $player->id)->where('branch_id', $foot->id)->sole()
            ->forceFill(['category_id' => $u13->id])->save();

        $membership = app(GroupMembership::class);
        $membership->add($s1, $player->id, null);
        $membership->add($f1, $player->id, null);

        $swimKey = \App\Services\Attendance\GroupBoard::key($this->swim->id, $this->minime->id);
        $footKey = \App\Services\Attendance\GroupBoard::key($foot->id, $u13->id);

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.members', 1)->where('groups.1.members', 1)
                ->where("groupRosters.{$swimKey}.0.group_id", $s1->id)
                ->where("groupRosters.{$footKey}.0.group_id", $f1->id));
    }

    #[Test]
    public function a_group_in_use_cannot_be_deleted_and_an_unused_one_goes_with_its_members(): void
    {
        $admin = $this->admin();
        $used = $this->group('G1');
        $free = $this->group('G2');
        TrainingSchedule::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $used->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        app(GroupMembership::class)->add($free, $this->swimmer()->id, null);

        $this->actingAs($admin)->delete(route('attendance.groups.destroy', $used))
            ->assertSessionHas('error', 'flash.training_group_in_use');
        $this->assertModelExists($used);

        $this->actingAs($admin)->delete(route('attendance.groups.destroy', $free))
            ->assertSessionHas('success', 'flash.training_group_deleted');
        $this->assertModelMissing($free);
        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function the_tick_list_adds_moves_and_removes_players(): void
    {
        $admin = $this->admin();
        [$g1, $g2] = [$this->group('G1'), $this->group('G2')];
        [$a, $b] = [$this->swimmer(), $this->swimmer()];

        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g1), ['from' => null, 'add' => [$a->id, $b->id], 'remove' => []])
            ->assertSessionHas('success', 'flash.training_group_members_saved');
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g2), ['from' => '2027-01-10', 'add' => [$a->id], 'remove' => []])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $g1), ['from' => '2027-02-01', 'add' => [], 'remove' => [$b->id]])
            ->assertSessionHasNoErrors();

        $rows = TrainingGroupMember::orderBy('id')->get()->map(fn ($m) => [$m->player_id, $m->training_group_id, $m->from_date, $m->to_date])->all();
        $this->assertSame([
            [$a->id, $g1->id, null, '2027-01-09'],
            [$b->id, $g1->id, null, '2027-01-31'],
            [$a->id, $g2->id, '2027-01-10', null],
        ], $rows);
    }

    #[Test]
    public function saving_every_ticked_player_moves_a_late_start_back_and_keeps_an_earlier_one(): void
    {
        // The owner's case: players added with today's date when the group already existed.
        $this->travelTo('2026-11-23 10:00:00');
        $admin = $this->admin();
        $group = $this->group();
        [$early, $late] = [$this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($group, $early->id, null);
        $membership->add($group, $late->id, '2026-11-22');
        $rows = fn () => TrainingGroupMember::orderBy('player_id')->get()->map(fn ($m) => [$m->player_id, $m->from_date, $m->to_date])->all();

        // Today's date (the dialog's default) changes nobody already in the group.
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $group), ['from' => '2026-11-23', 'add' => [$early->id, $late->id], 'remove' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([[$early->id, null, null], [$late->id, '2026-11-22', null]], $rows());

        // An earlier date moves the late start back; the earlier one stays.
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $group), ['from' => '2026-11-17', 'add' => [$early->id, $late->id], 'remove' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([[$early->id, null, null], [$late->id, '2026-11-17', null]], $rows());

        // No date: everyone ticked is in the group from the beginning.
        $this->actingAs($admin)->put(route('attendance.groups.members.update', $group), ['from' => null, 'add' => [$early->id, $late->id], 'remove' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([[$early->id, null, null], [$late->id, null, null]], $rows());
    }

    #[Test]
    public function a_player_of_another_category_cannot_be_ticked(): void
    {
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $outsider = $this->player($cadet);
        $this->swim->players()->attach($outsider->id);

        $this->actingAs($this->admin())->put(route('attendance.groups.members.update', $this->group()), ['from' => null, 'add' => [$outsider->id], 'remove' => []])
            ->assertSessionHasErrors(['add' => 'att.error.group_wrong_player']);

        $this->assertSame(0, TrainingGroupMember::count());
    }

    #[Test]
    public function the_settings_page_lists_groups_their_rosters_and_who_is_waiting(): void
    {
        Carbon::setTestNow('2026-11-20');
        [$g1, $g2] = [$this->group('G1'), $this->group('G2')];
        [$a, $b, $waiting] = [$this->swimmer(), $this->swimmer(), $this->swimmer()];
        app(GroupMembership::class)->add($g1, $a->id, null);
        app(GroupMembership::class)->add($g2, $b->id, null);
        TrainingSchedule::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $g1->id, 'weekday' => 6,
            'start_time' => '09:30', 'end_time' => '11:00', 'valid_from' => '2026-11-01',
        ]);
        $key = "{$this->swim->id}:{$this->minime->id}";

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups', 2)
                ->where('groups.0.id', $g1->id)->where('groups.0.key', $key)->where('groups.0.members', 1)->where('groups.0.in_use', true)
                ->where('groups.1.id', $g2->id)->where('groups.1.members', 1)->where('groups.1.in_use', false)
                ->has("groupRosters.{$key}", 3)
                ->where("groupRosters.{$key}.0", ['id' => $a->id, 'name' => $a->fullname, 'group_id' => $g1->id])
                ->where("groupRosters.{$key}.1.group_id", $g2->id)
                ->where("groupRosters.{$key}.2", ['id' => $waiting->id, 'name' => $waiting->fullname, 'group_id' => null]));
    }

    #[Test]
    public function without_groups_the_page_sends_empty_lists(): void
    {
        $this->swimmer();

        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertInertia(fn (Assert $page) => $page->has('groups', 0)->has('groupRosters', 0));
    }

    #[Test]
    public function every_group_write_needs_the_attendance_edit_right(): void
    {
        $group = $this->group();
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view', 'add', 'delete']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->post(route('attendance.groups.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->put(route('attendance.groups.update', $group), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->put(route('attendance.groups.members.update', $group), ['from' => null, 'add' => [], 'remove' => []])->assertForbidden();
        $this->actingAs($user)->delete(route('attendance.groups.destroy', $group))->assertForbidden();
    }
}
