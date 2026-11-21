<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TrainingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupStatsPageTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const PERIOD = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-11-30'];

    /** Swimming · Minime over the fixture's period, plus $extra (which wins). */
    private function filters(array $club, array $extra = []): array
    {
        return $extra + self::PERIOD + ['branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function the_page_follows_the_chosen_group(): void
    {
        $club = $this->splitCategory();
        ['minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Stats')
                ->where('categoryId', $minime->id)
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->where('totals.held', 10)
                ->where('totals.cancelled', 0)
                ->where('totals.expected', 15)
                ->where('totals.players', 2)
                ->where('totals.score_pct', 60)
                ->where('totals.missed_hours', 4.5)
                ->where('sessionsByMonth.held', [4, 5, 1])
                ->where('monthly.statuses.absent_unexcused', [3, 0, 0])
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$a->id, $mover->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $mover->id)['score_pct'] == 0)
                ->where('ranking.top', fn ($rows) => collect($rows)->pluck('player_id')->all() === [$a->id, $mover->id])
                // The table of categories compares whole categories: it never follows the group.
                ->where('categoryRows.0.held', 22)
                ->where('categoryRows.0.expected', 36));

        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->where('totals.held', 10)
                ->where('totals.cancelled', 1)
                ->where('totals.expected', 15)
                ->where('totals.score_pct', 60)
                ->where('totals.missed_hours', 6)
                ->where('players', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                ->where('players', fn ($rows) => collect($rows)->firstWhere('player_id', $mover->id)['score_pct'] == 100));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1, 'g2' => $g2] = $club;
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $foreign = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C1']);
        $admin = $this->admin();

        // No value, an id that does not exist, 0 (the month grid's «whole category»), another category's group.
        foreach ([[], ['group_id' => 999999], ['group_id' => 0], ['group_id' => $foreign->id]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                    ->where('totals.held', 22)
                    ->where('totals.expected', 36)
                    ->where('totals.score_pct', 66.7));
        }
    }

    #[Test]
    public function the_group_field_needs_a_category_that_has_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1] = $club;
        $benjamin = Category::create(['name' => 'Benjamin', 'branch_id' => $swim->id]);
        $admin = $this->admin();

        // All categories: no field, and a group in the URL is ignored.
        $this->actingAs($admin)->get(route('attendance.stats', self::PERIOD + ['branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', null)
                ->where('groups', [])
                ->where('groupId', null)
                ->where('totals.expected', 36));

        // A category without groups: no field either.
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, ['category_id' => $benjamin->id, 'group_id' => $g1->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $benjamin->id)
                ->where('groups', [])
                ->where('groupId', null)
                ->where('totals.expected', 0));
    }

    #[Test]
    public function a_group_that_is_not_a_number_is_refused(): void
    {
        $club = $this->splitCategory();

        $this->actingAs($this->admin())->get(route('attendance.stats', $this->filters($club, ['group_id' => 'abc'])))
            ->assertSessionHasErrors('group_id');
    }

    #[Test]
    public function the_comparison_with_the_period_before_follows_the_group(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1] = $club;
        $october = ['from' => '2026-10-01', 'to' => '2026-10-31'];   // the 31 days before: 2026-08-31 → 09-30
        $admin = $this->admin();

        // G1: 5 sessions in October against 4 in September.
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $october + ['group_id' => $g1->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.held', 5)
                ->where('deltas.held.raw', 1)
                ->where('deltas.held.percent', 25));

        // The whole category: 10 in October against 10 in September (4 + 4 + the two Tuesdays).
        $this->actingAs($admin)->get(route('attendance.stats', $this->filters($club, $october)))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.held', 10)
                ->where('deltas.held.raw', 0));
    }

    #[Test]
    public function the_spreadsheet_is_one_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'a' => $a, 'b' => $b, 'mover' => $mover] = $club;

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['group_id' => $g1->id, 'format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString(
            "attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}-g{$g1->id}.csv",
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(' — Swimming — Minime — G1', $csv);
        $this->assertStringContainsString($a->fullname, $csv);
        $this->assertStringContainsString($mover->fullname, $csv);
        $this->assertStringNotContainsString($b->fullname, $csv);
    }

    #[Test]
    public function the_pdf_names_the_group_after_the_category(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g2' => $g2, 'a' => $a, 'b' => $b] = $club;
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['group_id' => $g2->id, 'format' => 'pdf'])))
            ->assertOk();

        $this->assertSame("attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}-g{$g2->id}.pdf", $seen['filename']);
        $this->assertStringContainsString('Minime · G2', $seen['html']);
        $this->assertStringContainsString($b->fullname, $seen['html']);
        $this->assertStringNotContainsString($a->fullname, $seen['html']);
    }

    #[Test]
    public function without_a_group_the_export_is_named_as_before(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'b' => $b] = $club;

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.stats.export', $this->filters($club, ['format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString(
            "attendance-stats-2026-09-01-2026-11-30-b{$swim->id}-{$minime->id}.csv",
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(' — Swimming — Minime', $csv);
        $this->assertStringNotContainsString(' — Minime — ', $csv);
        $this->assertStringContainsString($b->fullname, $csv);
    }
}
