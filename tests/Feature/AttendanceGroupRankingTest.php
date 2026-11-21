<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupRankingTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    /** Season 2026/27 of Swimming · Minime, plus $extra (which wins). */
    private function seasonOf(array $club, array $extra = []): array
    {
        return $extra + ['type' => 'season', 'season' => 2026, 'branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function each_group_ranks_the_marks_of_its_own_sessions(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $mover->id])
                ->where('rows.0.expected', 10)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.rank', 2)
                ->where('rows.1.expected', 5)
                ->where('rows.1.score_pct', 0)
                ->where('unranked', 0));

        // The player who moved is last with G1 and first with G2.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$mover->id, $b->id])
                ->where('rows.0.rank', 1)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.score_pct', 40));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $foreign = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $benjamin = Category::create(['name' => 'Benjamin', 'branch_id' => $swim->id]);
        $admin = $this->admin();

        // No value, an id that does not exist, 0 (the month grid's «whole category»), another category's group.
        foreach ([[], ['group_id' => 999999], ['group_id' => 0], ['group_id' => $foreign->id]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->has('groups', 2)
                    ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $b->id, $mover->id])
                    ->where('rows.2.expected', 12)
                    ->where('rows.2.score_pct', 50));
        }

        // Another category shows its own groups, and starts on all of them.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['category_id' => $cadet->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', null)
                ->where('groups', [['id' => $foreign->id, 'name' => 'C1']]));

        // A category without groups offers no field, whatever the URL says.
        $this->actingAs($admin)->get(route('attendance.ranking', $this->seasonOf($club, ['category_id' => $benjamin->id, 'group_id' => $foreign->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', null)
                ->where('groups', []));
    }

    #[Test]
    public function the_podium_certificates_name_the_group(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id])))->assertOk();

        $this->assertSame("certificates-{$minime->id}-b{$swim->id}-g{$g2->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime · G2', $seen['html']);
        $this->assertStringContainsString($mover->fullname, $seen['html']);
        $this->assertStringContainsString($b->fullname, $seen['html']);
        $this->assertStringNotContainsString($a->fullname, $seen['html']);
    }

    #[Test]
    public function one_players_certificate_carries_the_score_of_the_group(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g2' => $g2, 'mover' => $mover] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id, 'player_id' => $mover->id])))
            ->assertOk();

        $this->assertSame("certificate-{$mover->membership_id}-b{$swim->id}-g{$g2->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime · G2', $seen['html']);
        $this->assertStringContainsString('100.0%', $seen['html']);       // with G2; 50.0% over the whole category
        $this->assertStringNotContainsString('50.0%', $seen['html']);
    }

    #[Test]
    public function no_group_certificate_without_a_mark_in_the_groups_sessions(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b] = $club;
        $admin = $this->admin();

        // a never trained with G2, b never with G1: both are in the category, neither gets that group's certificate.
        $this->actingAs($admin)->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g2->id, 'player_id' => $a->id])))
            ->assertNotFound();
        $this->actingAs($admin)->get(route('attendance.certificates', $this->seasonOf($club, ['group_id' => $g1->id, 'player_id' => $b->id])))
            ->assertNotFound();
    }

    #[Test]
    public function without_a_group_the_certificates_are_named_as_before(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.certificates', $this->seasonOf($club)))->assertOk();

        $this->assertSame("certificates-{$minime->id}-b{$swim->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('Swimming · Minime', $seen['html']);
        $this->assertStringNotContainsString('Minime · G', $seen['html']);
    }

    #[Test]
    public function a_group_of_another_branch_is_ignored_and_a_group_name_is_escaped_in_the_certificates(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $judo = Branch::create(['name' => 'Judo']);
        $open = Category::create(['name' => 'Open']);          // club-wide: branch_id null
        $foreign = TrainingGroup::create(['branch_id' => $judo->id, 'category_id' => $open->id, 'name' => '<b>G</b>']);
        $player = $this->player($open);
        $swim->players()->attach($player->id, ['membership_id' => $player->membership_id]);
        foreach (['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03'] as $date) {
            $this->branchMark($this->branchSession($swim, $open, $date), $player, AttendanceStatus::Present);
        }
        $admin = $this->admin();
        $query = ['type' => 'season', 'season' => 2026, 'branch_id' => $swim->id, 'category_id' => $open->id, 'group_id' => $foreign->id];

        // The group belongs to the other branch: the swimming page offers none and does not use it.
        $this->actingAs($admin)->get(route('attendance.ranking', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('groups', [])->where('groupId', null));

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('attendance.certificates', $query))->assertOk();
        $this->assertSame("certificates-{$open->id}-b{$swim->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringNotContainsString('G</b>', $seen['html']);
        $this->assertStringNotContainsString('G&lt;/b&gt;', $seen['html']);

    }

    #[Test]
    public function a_group_name_is_escaped_in_the_certificates(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $open = Category::create(['name' => 'Open']);
        $player = $this->player($open);
        $swim->players()->attach($player->id, ['membership_id' => $player->membership_id]);
        $own = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $open->id, 'name' => '<b>G</b>']);
        foreach (['2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07'] as $date) {
            $this->branchMark($this->branchSession($swim, $open, $date, ['group_id' => $own->id]), $player, AttendanceStatus::Present);
        }
        $seen = $this->spyPdf();
        $this->actingAs($this->admin())->get(route('attendance.certificates', ['type' => 'season', 'season' => 2026, 'branch_id' => $swim->id, 'category_id' => $open->id, 'group_id' => $own->id]))->assertOk();
        $this->assertStringContainsString('&lt;b&gt;G&lt;/b&gt;', $seen['html']);
        $this->assertStringNotContainsString('<b>G</b>', $seen['html']);
    }
}
