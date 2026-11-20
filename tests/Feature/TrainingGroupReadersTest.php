<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingGroupReadersTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function options_list_the_groups_by_name_and_can_keep_one_category(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $b = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'B']);
        $a = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'A']);
        $c = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C']);

        $this->assertSame([
            ['id' => $a->id, 'branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'A'],
            ['id' => $b->id, 'branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'B'],
            ['id' => $c->id, 'branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C'],
        ], TrainingGroup::options()->all());
        $this->assertSame([$a->id, $b->id], TrainingGroup::options($minime->id)->pluck('id')->all());
        $this->assertSame([], TrainingGroup::options(999999)->all());
    }

    #[Test]
    public function the_groups_of_a_player_are_read_per_branch_on_a_date(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $foot = Branch::create(['name' => 'Foot']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $foot->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $g2 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G2']);
        $f1 = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'name' => 'F1']);
        $player = $this->player($minime);
        $membership = app(GroupMembership::class);
        $membership->add($g1, $player->id, null);
        $membership->add($g2, $player->id, '2026-11-10');
        $membership->add($f1, $player->id, null);

        $ids = fn (array $groups) => array_map(fn (TrainingGroup $group) => $group->id, $groups);

        $this->assertEquals([$swim->id => $g1->id, $foot->id => $f1->id], $ids($membership->groupsOf($player->id, '2026-11-09')));
        $this->assertEquals([$swim->id => $g2->id, $foot->id => $f1->id], $ids($membership->groupsOf($player->id, '2026-11-10')));
        $this->assertSame([], $membership->groupsOf($this->player($minime)->id, '2026-11-10'));
    }

    #[Test]
    public function removing_from_the_beginning_deletes_the_membership_and_from_a_date_closes_it(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        [$a, $b] = [$this->player($minime), $this->player($minime)];
        $membership = app(GroupMembership::class);
        $membership->add($g1, $a->id, null);
        $membership->add($g1, $b->id, null);

        $membership->remove($g1, $a->id, null);
        $membership->remove($g1, $b->id, '2026-11-10');

        $this->assertSame([[$b->id, '2026-11-09']], $g1->members()->orderBy('id')->get()->map(fn ($m) => [$m->player_id, $m->to_date])->all());
    }
}
