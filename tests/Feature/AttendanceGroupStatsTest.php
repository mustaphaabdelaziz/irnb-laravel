<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\GroupScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\Support\GroupAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupStatsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, GroupAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-11-30';

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function a_group_counts_only_the_marks_of_its_own_sessions(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'a' => $a, 'b' => $b, 'mover' => $mover] = $this->splitCategory();

        $first = $this->stats()->players(self::FROM, self::TO, $minime->id, null, $swim->id, $g1->id);
        $second = $this->stats()->players(self::FROM, self::TO, $minime->id, null, $swim->id, $g2->id);

        $this->assertSame([$a->id, $mover->id], array_keys($first));
        $this->assertSame(10, $first[$a->id]['expected']);
        $this->assertSame(100.0, $first[$a->id]['score_pct']);
        $this->assertSame(5, $first[$mover->id]['expected']);
        $this->assertSame(3, $first[$mover->id]['counts']['absent_unexcused']);
        $this->assertSame(2, $first[$mover->id]['counts']['present']);
        $this->assertSame(-1.0, $first[$mover->id]['score']);
        $this->assertSame(0.0, $first[$mover->id]['score_pct']);
        $this->assertSame(270, $first[$mover->id]['missed_minutes']);   // 3 × 90 min

        $this->assertSame([$b->id, $mover->id], array_keys($second));
        $this->assertSame(10, $second[$b->id]['expected']);
        $this->assertSame(40.0, $second[$b->id]['score_pct']);
        $this->assertSame(360, $second[$b->id]['missed_minutes']);      // 3 × 120 min
        $this->assertSame(5, $second[$mover->id]['expected']);
        $this->assertSame(100.0, $second[$mover->id]['score_pct']);
        $this->assertSame(0, $second[$mover->id]['missed_minutes']);
    }

    #[Test]
    public function without_a_group_the_numbers_are_the_whole_categorys(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b, 'mover' => $mover] = $this->splitCategory();
        $stats = $this->stats();

        $all = $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id);

        $this->assertSame($all, $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id, null));
        $this->assertSame(12, $all[$mover->id]['expected']);
        $this->assertSame(50.0, $all[$mover->id]['score_pct']);
        $this->assertSame(270, $all[$mover->id]['missed_minutes']);
        $this->assertSame(12, $all[$b->id]['expected']);
        $this->assertSame(360, $all[$b->id]['missed_minutes']);

        // A player who moved has the marks until the move in the old group and the rest in the new one: none is lost.
        $old = $stats->players(self::FROM, self::TO, $minime->id, $mover->id, $swim->id, $g1->id)[$mover->id];
        $new = $stats->players(self::FROM, self::TO, $minime->id, $mover->id, $swim->id, $g2->id)[$mover->id];
        $this->assertSame(12, $old['expected'] + $new['expected'] + 2);   // + the two whole-category Tuesdays
    }

    #[Test]
    public function months_sessions_and_streaks_follow_the_group(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'mover' => $mover] = $this->splitCategory();
        $stats = $this->stats();

        $months = $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id, $g1->id);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $months['labels']);
        $this->assertSame([3, 0, 0], $months['statuses']['absent_unexcused']);
        $this->assertSame([5, 6, 1], $months['statuses']['present']);

        $first = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $g1->id);
        $this->assertSame([4, 5, 1], $first['held']);
        $this->assertSame([0, 0, 0], $first['cancelled']);
        $second = $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $g2->id);
        $this->assertSame([4, 5, 1], $second['held']);
        $this->assertSame([0, 0, 1], $second['cancelled']);
        $this->assertSame([10, 10, 2], $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id)['held']);

        $this->assertSame(
            ['current' => 0, 'longest' => 3, 'last_date' => '2026-10-03'],
            $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id, $g1->id)[$mover->id],
        );
        $this->assertSame(
            ['current' => 0, 'longest' => 0, 'last_date' => '2026-11-07'],
            $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id, $g2->id)[$mover->id],
        );
        // Read together, each whole-category Tuesday breaks the streak.
        $this->assertSame(1, $stats->unexcusedStreaks(self::FROM, self::TO, $mover->id, $swim->id)[$mover->id]['longest']);
    }

    #[Test]
    public function a_guest_counts_in_the_group_of_the_session(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1, 'g2' => $g2, 'b' => $b] = $this->splitCategory();
        // b, of G2, makes up a session with G1 (added by hand on the session page).
        $withFirst = TrainingSession::where('group_id', $g1->id)->where('date', '2026-11-07')->sole();
        $this->branchMark($withFirst, $b, AttendanceStatus::Present);

        $this->assertSame(1, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id, $g1->id)[$b->id]['expected']);
        $this->assertSame(10, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id, $g2->id)[$b->id]['expected']);
        $this->assertSame(13, $this->stats()->players(self::FROM, self::TO, $minime->id, $b->id, $swim->id)[$b->id]['expected']);
    }

    #[Test]
    public function the_group_adds_no_query(): void
    {
        ['swim' => $swim, 'minime' => $minime, 'g1' => $g1] = $this->splitCategory();
        $count = function (?int $groupId) use ($swim, $minime): int {
            $stats = $this->stats();
            $stats->players(self::FROM, self::TO);   // loads the settings and the custom codes once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players(self::FROM, self::TO, $minime->id, null, $swim->id, $groupId);
            $stats->monthly(self::FROM, self::TO, $minime->id, null, $swim->id, $groupId);
            $stats->sessionsByMonth(self::FROM, self::TO, $minime->id, $swim->id, $groupId);
            $stats->unexcusedStreaks(self::FROM, self::TO, null, $swim->id, $groupId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Once::flush();
        $without = $count(null);
        Once::flush();

        $this->assertSame($without, $count($g1->id));
    }

    #[Test]
    public function the_group_condition_never_changes_the_query_plan(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('EXPLAIN QUERY PLAN is sqlite\'s; the unary plus is a no-op on the other databases.');
        }

        $marks = fn () => DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [self::FROM, self::TO]);
        $plan = fn ($query) => collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))->pluck('detail')->implode("\n");
        $scoped = GroupScope::apply($marks(), 1);

        $this->assertStringContainsString('+training_sessions.group_id = ?', $scoped->toSql());
        $this->assertStringNotContainsString('training_sessions_group_id_index', $plan($scoped));
        // The plan of "all groups": a group can only make a report cheaper, never dearer.
        $this->assertSame($plan($marks()), $plan($scoped));
        $this->assertSame($marks()->toSql(), GroupScope::apply($marks(), null)->toSql());
    }
}
