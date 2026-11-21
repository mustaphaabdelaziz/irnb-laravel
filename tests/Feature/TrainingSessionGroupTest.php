<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private Category $cadet;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private TrainingGroup $c1;

    private Player $a;

    private Player $b;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->c1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $this->a->id, null);
        $membership->add($this->g2, $this->b->id, null);
        $this->staff = $this->admin();
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function training(array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'date' => '2026-11-25', 'start_time' => '18:00',
            'end_time' => '19:30', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    private function newSession(array $extra = [])
    {
        return $this->actingAs($this->staff)->post(route('attendance.sessions.store'), $extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'kind' => 'extra',
            'date' => '2026-11-25', 'start_time' => '18:00', 'end_time' => '19:30',
        ]);
    }

    private function edit(TrainingSession $training, array $data)
    {
        return $this->actingAs($this->staff)->put(route('attendance.sessions.update', $training), $data);
    }

    #[Test]
    public function an_extra_session_can_be_added_for_one_group(): void
    {
        $this->newSession(['group_id' => $this->g1->id])->assertSessionHasNoErrors();

        $created = TrainingSession::sole();
        $this->assertSame($this->g1->id, $created->group_id);
        $this->assertSame([$this->a->id], app(Roster::class)->forSession($created)->modelKeys());
    }

    #[Test]
    public function an_extra_session_without_a_group_lists_the_whole_category(): void
    {
        $this->newSession()->assertSessionHasNoErrors();

        $created = TrainingSession::sole();
        $this->assertNull($created->group_id);
        $this->assertSame([$this->a->id, $this->b->id], app(Roster::class)->forSession($created)->modelKeys());
    }

    #[Test]
    public function a_group_of_another_category_is_refused_on_a_new_session(): void
    {
        $this->newSession(['group_id' => $this->c1->id])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->newSession(['group_id' => 999999])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);

        $this->assertSame(0, TrainingSession::count());
    }

    #[Test]
    public function a_pre_season_session_never_takes_a_group(): void
    {
        $this->newSession(['kind' => 'preseason', 'group_id' => $this->g1->id])->assertSessionHasNoErrors();

        $this->assertNull(TrainingSession::sole()->group_id);
    }

    #[Test]
    public function the_group_of_a_hand_added_session_can_change_until_marks_are_saved(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasNoErrors();
        $this->assertSame($this->g2->id, $training->fresh()->group_id);
        $this->assertContains('group_id', ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields']);

        $this->edit($training, ['group_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($training->fresh()->group_id);

        $this->edit($training, ['group_id' => $this->c1->id])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->assertNull($training->fresh()->group_id);
    }

    #[Test]
    public function the_group_is_locked_once_marks_are_saved_but_details_still_change(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $this->a->id, 'category_id' => $this->minime->id,
            'status' => AttendanceStatus::Present->value,
        ]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasErrors(['group_id' => 'att.error.already_marked']);
        $this->edit($training, ['group_id' => null])->assertSessionHasErrors(['group_id' => 'att.error.already_marked']);
        $this->edit($training, ['title' => 'Relay'])->assertSessionHasNoErrors();

        $fresh = $training->fresh();
        $this->assertSame([$this->g1->id, 'Relay'], [$fresh->group_id, $fresh->title]);
    }

    #[Test]
    public function a_regular_session_keeps_the_group_of_its_schedule_line(): void
    {
        $training = $this->training(['kind' => SessionKind::Regular, 'group_id' => $this->g1->id]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasErrors(['group_id' => 'att.error.group_follows_schedule']);
        $this->edit($training, ['title' => 'Relay'])->assertSessionHasNoErrors();

        $this->assertSame($this->g1->id, $training->fresh()->group_id);
    }

    #[Test]
    public function turning_the_session_into_pre_season_or_moving_its_branch_drops_the_group(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);
        $this->edit($training, ['kind' => 'preseason'])->assertSessionHasNoErrors();
        $this->assertNull($training->fresh()->group_id);

        // A category with no owner can follow the session to another branch; a group cannot.
        $open = Category::create(['name' => 'Open']);
        $group = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $open->id, 'name' => 'O1']);
        $other = Branch::create(['name' => 'Polo']);
        $moving = $this->training(['category_id' => $open->id, 'group_id' => $group->id, 'start_time' => '20:00']);

        $this->edit($moving, ['branch_id' => $other->id])->assertSessionHasNoErrors();

        $fresh = $moving->fresh();
        $this->assertSame([$other->id, null], [(int) $fresh->branch_id, $fresh->group_id]);
    }

    #[Test]
    public function the_session_page_carries_its_group_and_the_groups_of_its_category(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);

        $this->actingAs($this->staff)->get(route('attendance.sessions.show', $training))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.group_id', $this->g1->id)
                ->where('session.group', 'G1')
                ->where('groups', [
                    ['id' => $this->g1->id, 'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1'],
                    ['id' => $this->g2->id, 'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2'],
                ]));
    }

    #[Test]
    public function the_calendar_sends_the_groups_only_when_the_add_dialog_asks_for_them(): void
    {
        $params = ['view' => 'month', 'category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id];
        $first = $this->actingAs($this->staff)->get(route('attendance.index', $params));
        $first->assertInertia(fn (Assert $page) => $page->missing('groups'));

        $props = $this->actingAs($this->staff)->get(route('attendance.index', $params), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $first->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Attendance/Index',
            'X-Inertia-Partial-Data' => 'playerStatuses,rosterStatusIds,groups',
        ])->assertOk()->json('props');

        $this->assertSame(['C1', 'G1', 'G2'], array_column($props['groups'], 'name'));
        $this->assertArrayHasKey('playerStatuses', $props);
    }
}
