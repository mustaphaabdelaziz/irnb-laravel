<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\MonthSheet;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRosterGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
    }

    private function swimmer(): \App\Models\Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function sessionOf(?TrainingGroup $group, string $date, string $start): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    #[Test]
    public function a_group_session_lists_only_its_members_and_a_plain_session_lists_the_category(): void
    {
        [$a, $b, $waiting] = [$this->swimmer(), $this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $roster = app(Roster::class);

        $this->assertSame([$a->id], $roster->forSession($this->sessionOf($this->g1, '2026-11-21', '09:30'))->pluck('id')->all());
        $this->assertSame([$b->id], $roster->forSession($this->sessionOf($this->g2, '2026-11-21', '11:00'))->pluck('id')->all());
        $this->assertSame([$a->id, $b->id, $waiting->id], $roster->forSession($this->sessionOf(null, '2026-11-21', '14:00'))->pluck('id')->all());
    }

    #[Test]
    public function a_moved_player_is_listed_in_the_old_group_before_the_date_and_the_new_one_from_it(): void
    {
        $a = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $a->id, '2027-01-10');
        $roster = app(Roster::class);

        $this->assertSame([$a->id], $roster->forSession($this->sessionOf($this->g1, '2027-01-09', '09:30'))->pluck('id')->all());
        $this->assertSame([], $roster->forSession($this->sessionOf($this->g2, '2027-01-09', '11:00'))->pluck('id')->all());
        $this->assertSame([], $roster->forSession($this->sessionOf($this->g1, '2027-01-10', '09:30'))->pluck('id')->all());
        $this->assertSame([$a->id], $roster->forSession($this->sessionOf($this->g2, '2027-01-10', '11:00'))->pluck('id')->all());
    }

    #[Test]
    public function a_saved_session_keeps_its_list_when_the_group_changes(): void
    {
        $a = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $session = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        app(MarkRecorder::class)->save($session, [['player_id' => $a->id, 'status' => 'present']], $this->admin());

        $membership->add($this->g2, $a->id, null);

        $this->assertSame([$a->id], app(Roster::class)->forSession($session->fresh())->pluck('id')->all());
    }

    #[Test]
    public function the_month_sheet_gives_a_cell_only_to_the_members_of_each_session_group(): void
    {
        [$a, $b] = [$this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $second = $this->sessionOf($this->g2, '2026-11-21', '11:00');

        $cells = app(MonthSheet::class)->build($this->minime->id, 2026, 11, $this->swim->id)['cells'];

        $this->assertSame([$first->id], array_keys($cells[$a->id]));
        $this->assertSame([$second->id], array_keys($cells[$b->id]));
    }

    #[Test]
    public function the_grid_refuses_a_code_for_a_player_outside_the_session_group(): void
    {
        [$a, $b] = [$this->swimmer(), $this->swimmer()];
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $b->id, null);
        $session = $this->sessionOf($this->g1, '2026-11-21', '09:30');

        $this->actingAs($this->admin())->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $session->id, 'codes' => [$b->id => 'P']]],
        ])->assertSessionHasErrors(["columns.0.codes.{$b->id}"]);
    }
}
