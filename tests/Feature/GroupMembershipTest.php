<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class GroupMembershipTest extends TestCase
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

    /** @return list<array{int, ?string, ?string}> [group id, from, to] of the player's rows, oldest first */
    private function rows(int $playerId): array
    {
        return TrainingGroupMember::where('player_id', $playerId)->orderBy('id')->get()
            ->map(fn (TrainingGroupMember $m) => [$m->training_group_id, $m->from_date, $m->to_date])->all();
    }

    #[Test]
    public function a_first_assignment_has_no_start_date(): void
    {
        $p = $this->player($this->minime);

        app(GroupMembership::class)->add($this->g1, $p->id, null);

        $this->assertSame([[$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function a_move_from_a_date_closes_the_old_group_the_day_before(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);

        $membership->add($this->g1, $p->id, null);
        $membership->add($this->g2, $p->id, '2027-01-10');

        $this->assertSame([[$this->g1->id, null, '2027-01-09'], [$this->g2->id, '2027-01-10', null]], $this->rows($p->id));
    }

    #[Test]
    public function from_the_beginning_replaces_the_history_in_the_branch(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, null);
        $membership->add($this->g2, $p->id, '2027-01-10');

        $membership->add($this->g2, $p->id, null);

        $this->assertSame([[$this->g2->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function adding_to_the_group_the_player_is_already_in_changes_nothing(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, null);

        $membership->add($this->g1, $p->id, '2027-01-10');

        $this->assertSame([[$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function a_membership_that_would_end_before_it_starts_is_deleted(): void
    {
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $p->id, '2027-01-10');

        $membership->add($this->g2, $p->id, '2027-01-05');

        $this->assertSame([[$this->g2->id, '2027-01-05', null]], $this->rows($p->id));
    }

    #[Test]
    public function removing_from_a_date_closes_and_from_the_beginning_deletes(): void
    {
        $a = $this->player($this->minime);
        $b = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g1, $b->id, null);

        $membership->remove($this->g1, $a->id, '2027-02-01');
        $membership->remove($this->g1, $b->id, null);

        $this->assertSame([[$this->g1->id, null, '2027-01-31']], $this->rows($a->id));
        $this->assertSame([], $this->rows($b->id));
    }

    #[Test]
    public function a_group_of_another_branch_is_left_alone(): void
    {
        $foot = Branch::create(['name' => 'Foot']);
        $u13 = Category::create(['name' => 'U13', 'branch_id' => $foot->id]);
        $footGroup = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $u13->id, 'name' => 'A']);
        $p = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($footGroup, $p->id, null);

        $membership->add($this->g1, $p->id, null);

        $this->assertSame([[$footGroup->id, null, null], [$this->g1->id, null, null]], $this->rows($p->id));
    }

    #[Test]
    public function current_group_ids_reads_the_members_on_a_date(): void
    {
        $a = $this->player($this->minime);
        $b = $this->player($this->minime);
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $a->id, null);
        $membership->add($this->g2, $a->id, '2027-01-10');
        $membership->add($this->g1, $b->id, null);
        $ids = [$this->g1->id, $this->g2->id];

        $this->assertSame([$a->id => $this->g1->id, $b->id => $this->g1->id], $membership->currentGroupIds($ids, '2027-01-09'));
        $this->assertSame([$a->id => $this->g2->id, $b->id => $this->g1->id], $membership->currentGroupIds($ids, '2027-01-10'));
        $this->assertSame([], $membership->currentGroupIds([], '2027-01-10'));
    }

    #[Test]
    public function a_category_change_closes_the_membership_the_day_before(): void
    {
        Carbon::setTestNow('2026-12-01');
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $p = $this->player($this->minime);
        $this->swim->players()->attach($p->id);
        app(GroupMembership::class)->add($this->g1, $p->id, null);

        $registration = BranchRegistration::where('player_id', $p->id)->where('branch_id', $this->swim->id)->sole();
        $registration->category_id = $cadet->id;
        $registration->save();

        $this->assertSame([[$this->g1->id, null, '2026-11-30']], $this->rows($p->id));
    }
}
