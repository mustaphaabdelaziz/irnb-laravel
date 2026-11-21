<?php

namespace Tests\Feature;

use App\Services\Attendance\AtRisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupAlertsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31, the page's default period
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Swimming · Minime, plus $extra (which wins). */
    private function filters(array $club, array $extra = []): array
    {
        return $extra + ['branch_id' => $club['swim']->id, 'category_id' => $club['minime']->id];
    }

    #[Test]
    public function the_flags_come_from_one_groups_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $risk = app(AtRisk::class);

        $first = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, $g1->id);
        $this->assertSame([$mover->id], array_column($first, 'player_id'));
        $this->assertSame(5, $first[0]['expected']);
        $this->assertSame(0.0, $first[0]['score_pct']);
        $this->assertSame(3, $first[0]['unexcused']);
        $this->assertSame(3, $first[0]['longest_streak']);
        $this->assertSame(0, $first[0]['current_streak']);
        $this->assertSame('2026-10-03', $first[0]['last_date']);
        $this->assertTrue($first[0]['low_score']);
        $this->assertTrue($first[0]['streak']);

        // With G2 the player who moved is at 100 %: only b is flagged there.
        $second = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, $g2->id);
        $this->assertSame([$b->id], array_column($second, 'player_id'));
        $this->assertSame(40.0, $second[0]['score_pct']);
        $this->assertSame(3, $second[0]['longest_streak']);

        // No group: both, for their score over the whole category; the Tuesdays break the streaks.
        $all = $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id);
        $this->assertEqualsCanonicalizing([$b->id, $mover->id], array_column($all, 'player_id'));
        $this->assertSame([false, false], array_column($all, 'streak'));
        $this->assertSame($all, $risk->list('2026-09-01', '2027-08-31', $minime->id, $swim->id, null));
    }

    #[Test]
    public function the_page_follows_the_chosen_group(): void
    {
        $club = $this->splitCategory();
        ['g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, ['group_id' => $g1->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('groupId', $g1->id)
                ->where('groups', [['id' => $g1->id, 'name' => 'G1'], ['id' => $g2->id, 'name' => 'G2']])
                ->has('rows', 1)
                ->where('rows.0.player_id', $mover->id)
                ->where('rows.0.expected', 5)
                ->where('rows.0.score_pct', 0)
                ->where('rows.0.longest_streak', 3)
                ->where('rows.0.last_date', '2026-10-03')
                ->where('rows.0.low_score', true)
                ->where('rows.0.streak', true));

        $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, ['group_id' => $g2->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groupId', $g2->id)
                ->has('rows', 1)
                ->where('rows.0.player_id', $b->id)
                ->where('rows.0.score_pct', 40)
                ->where('rows.0.longest_streak', 3));
    }

    #[Test]
    public function all_groups_is_the_default_and_what_an_unknown_group_falls_back_to(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'g1' => $g1, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        foreach ([[], ['group_id' => 999999], ['group_id' => 0]] as $extra) {
            $this->actingAs($admin)->get(route('attendance.alerts', $this->filters($club, $extra)))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('groupId', null)
                    ->has('groups', 2)
                    ->where('rows', fn ($rows) => collect($rows)->pluck('player_id')->sort()->values()->all() === [$b->id, $mover->id])
                    ->where('rows.0.longest_streak', 1)
                    ->where('rows.1.longest_streak', 1));
        }

        // All categories: no field, and a group in the URL is ignored.
        $this->actingAs($admin)->get(route('attendance.alerts', ['branch_id' => $swim->id, 'group_id' => $g1->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', null)
                ->where('groups', [])
                ->where('groupId', null)
                ->has('rows', 2));
    }

    #[Test]
    public function the_export_is_one_groups(): void
    {
        $club = $this->splitCategory();
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'b' => $b, 'mover' => $mover] = $club;
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->get(route('attendance.alerts.export', $this->filters($club, ['group_id' => $g1->id, 'format' => 'csv'])))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("-b{$swim->id}-{$minime->id}-g{$g1->id}.csv", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(' — Swimming — Minime — G1', $csv);
        $this->assertStringContainsString($mover->fullname, $csv);
        $this->assertStringNotContainsString($b->fullname, $csv);

        // Without a group the file is named as before and lists both.
        $plain = $this->actingAs($admin)
            ->get(route('attendance.alerts.export', $this->filters($club, ['format' => 'csv'])))
            ->assertOk();
        $this->assertStringContainsString("-b{$swim->id}-{$minime->id}.csv", (string) $plain->headers->get('content-disposition'));
        $this->assertStringNotContainsString(' — Minime — ', $plain->streamedContent());
        $this->assertStringContainsString($b->fullname, $plain->streamedContent());
    }
}
