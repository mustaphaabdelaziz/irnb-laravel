<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupGridTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private Player $a;

    private Player $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $this->a->id, null);
        $membership->add($this->g2, $this->b->id, null);
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function sessionOf(?TrainingGroup $group, string $date, string $start, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    private function gridUrl(array $query = []): string
    {
        return route('attendance.grid', $query + ['category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]);
    }

    #[Test]
    public function the_month_sheet_keeps_only_the_chosen_groups_sessions(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $second = $this->sessionOf($this->g2, '2026-11-21', '11:00');
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $sheet = app(MonthSheet::class);
        $ids = fn (?int $groupId) => $sheet->build($this->minime->id, 2026, 11, $this->swim->id, $groupId)['sessions']->modelKeys();

        $this->assertSame([$first->id], $ids($this->g1->id));
        $this->assertSame([$second->id], $ids($this->g2->id));
        $this->assertSame([$plain->id], $ids(MonthSheet::NO_GROUP));
        $this->assertSame([$first->id, $second->id, $plain->id], $ids(null));

        $one = $sheet->build($this->minime->id, 2026, 11, $this->swim->id, $this->g1->id);
        $this->assertSame([$this->a->id], $one['players']->modelKeys());
        $whole = $sheet->build($this->minime->id, 2026, 11, $this->swim->id, MonthSheet::NO_GROUP);
        $this->assertSame([$this->a->id, $this->b->id], $whole['players']->modelKeys());
    }

    #[Test]
    public function the_switch_lists_the_groups_and_the_whole_category_only_when_the_month_has_plain_sessions(): void
    {
        $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $this->sessionOf(null, '2026-11-24', '17:00', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        $sheet = app(MonthSheet::class);
        $switch = fn (?int $requested = null) => $sheet->groupSwitch($this->minime->id, 2026, 11, $this->swim->id, $requested);
        $groups = [['id' => $this->g1->id, 'name' => 'G1'], ['id' => $this->g2->id, 'name' => 'G2']];

        // A cancelled plain session is not on the sheet, so it brings no «whole category» entry.
        $this->assertSame(['options' => $groups, 'selected' => $this->g1->id], $switch());
        $this->assertSame($this->g2->id, $switch($this->g2->id)['selected']);
        $this->assertSame($this->g1->id, $switch(999999)['selected']);
        $this->assertSame($this->g1->id, $switch(MonthSheet::NO_GROUP)['selected']);

        $this->sessionOf(null, '2026-11-26', '17:00');

        $this->assertSame([...$groups, ['id' => MonthSheet::NO_GROUP, 'name' => null]], $switch()['options']);
        $this->assertSame($this->g1->id, $switch()['selected']);
        $this->assertSame(MonthSheet::NO_GROUP, $switch(MonthSheet::NO_GROUP)['selected']);
    }

    #[Test]
    public function a_category_without_groups_has_no_switch_and_shows_every_session(): void
    {
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
        TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $u15->id, 'date' => '2026-11-21', 'start_time' => '09:30',
            'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->assertSame(['options' => [], 'selected' => null], app(MonthSheet::class)->groupSwitch($u15->id, 2026, 11, $this->swim->id, 5));

        $this->actingAs($this->admin())
            ->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-11', 'branch_id' => $this->swim->id, 'group_id' => 5]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('groups', [])->where('groupId', null)->has('sessions', 1));
    }

    #[Test]
    public function the_grid_opens_on_the_first_group_and_switches_by_the_url(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $second = $this->sessionOf($this->g2, '2026-11-21', '11:00');
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->gridUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->where('groupId', $this->g1->id)
                ->where('groups', [
                    ['id' => $this->g1->id, 'name' => 'G1'],
                    ['id' => $this->g2->id, 'name' => 'G2'],
                    ['id' => 0, 'name' => null],
                ])
                ->has('sessions', 1)->where('sessions.0.id', $first->id)
                ->has('rows', 1)->where('rows.0.id', $this->a->id));

        $this->actingAs($admin)->get($this->gridUrl(['group_id' => $this->g2->id]))
            ->assertInertia(fn (Assert $page) => $page->where('groupId', $this->g2->id)
                ->has('sessions', 1)->where('sessions.0.id', $second->id)
                ->has('rows', 1)->where('rows.0.id', $this->b->id));

        $this->actingAs($admin)->get($this->gridUrl(['group_id' => 0]))
            ->assertInertia(fn (Assert $page) => $page->where('groupId', 0)
                ->has('sessions', 1)->where('sessions.0.id', $plain->id)
                ->has('rows', 2));
    }

    #[Test]
    public function saving_a_group_column_takes_its_members_and_refuses_the_others(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $first->id, 'codes' => [$this->a->id => 'P', $this->b->id => 'P']]],
        ])->assertSessionHasErrors(["columns.0.codes.{$this->b->id}"]);
        $this->assertSame(0, Attendance::count());

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $first->id, 'codes' => [$this->a->id => 'P']]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');
        $this->assertSame([$this->a->id], Attendance::where('training_session_id', $first->id)->pluck('player_id')->map(fn ($id) => (int) $id)->all());
    }
}
