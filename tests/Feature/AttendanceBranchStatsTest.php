<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PreseasonTarget;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\BranchScope;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceBranchStatsTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-10-31';

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function a_two_sport_players_numbers_come_from_one_branch_at_a_time(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();

        $football = $this->stats()->players(self::FROM, self::TO, null, $both->id, $foot->id)[$both->id];
        $swimming = $this->stats()->players(self::FROM, self::TO, null, $both->id, $swim->id)[$both->id];
        $mixed = $this->stats()->players(self::FROM, self::TO, null, $both->id)[$both->id];

        $this->assertSame(5, $football['expected']);
        $this->assertSame(3, $football['counts']['absent_unexcused']);
        $this->assertSame(0.0, $football['score_pct']);
        $this->assertSame(270, $football['missed_minutes']);   // 3 × 90 min
        $this->assertSame(5, $swimming['expected']);
        $this->assertSame(0, $swimming['counts']['absent_unexcused']);
        $this->assertSame(100.0, $swimming['score_pct']);
        $this->assertSame(0, $swimming['missed_minutes']);
        // No branch = no condition (a club without branches): every session.
        $this->assertSame(10, $mixed['expected']);
        $this->assertSame(40.0, $mixed['score_pct']);
    }

    #[Test]
    public function months_sessions_list_and_streaks_follow_the_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $stats = $this->stats();

        $this->assertSame([3, 0], $stats->monthly(self::FROM, self::TO, null, $both->id, $foot->id)['statuses']['absent_unexcused']);
        $this->assertSame([4, 1], $stats->monthly(self::FROM, self::TO, null, $both->id, $swim->id)['statuses']['present']);
        $this->assertSame([0, 0], $stats->monthly(self::FROM, self::TO, null, $both->id, $swim->id)['statuses']['absent_unexcused']);

        $swimSessions = $stats->playerSessions($both->id, self::FROM, self::TO, $swim->id);
        $this->assertCount(5, $swimSessions);
        $this->assertSame(['present'], array_values(array_unique(array_column($swimSessions, 'status'))));

        $this->assertSame(3, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id, $foot->id)[$both->id]['longest']);
        $this->assertSame(0, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id, $swim->id)[$both->id]['longest']);
        // Mixed, each swimming mark would break the football streak.
        $this->assertSame(1, $stats->unexcusedStreaks(self::FROM, self::TO, $both->id)[$both->id]['longest']);
    }

    #[Test]
    public function sessions_categories_and_preseason_count_one_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15] = $this->twoSportClub();
        $this->branchSession($swim, $u15, '2026-10-12', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Pool closed']);
        $this->branchSession($foot, $u15, '2026-10-03', ['kind' => SessionKind::Preseason]);
        PreseasonTarget::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 10]);
        PreseasonTarget::create(['branch_id' => $swim->id, 'category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 4]);
        $stats = $this->stats();

        $swimMonths = $stats->sessionsByMonth(self::FROM, self::TO, null, $swim->id);
        $this->assertSame([4, 1], $swimMonths['held']);
        $this->assertSame([0, 1], $swimMonths['cancelled']);
        $this->assertSame([4, 2], $stats->sessionsByMonth(self::FROM, self::TO, $u15->id, $foot->id)['held']);

        $football = $stats->categories(self::FROM, self::TO, $foot->id)[0];
        $this->assertSame(6, $football['held']);
        $this->assertSame(0, $football['cancelled']);
        $this->assertSame(10, $football['expected']);   // both × 5 + footer × 5
        $this->assertSame(1, $football['preseason']['done']);
        $this->assertSame(10, $football['preseason']['target']);

        $swimming = $stats->categories(self::FROM, self::TO, $swim->id)[0];
        $this->assertSame(5, $swimming['held']);
        $this->assertSame(1, $swimming['cancelled']);
        $this->assertSame(0, $swimming['preseason']['done']);
        $this->assertSame(4, $swimming['preseason']['target']);

        $this->assertSame(['done' => 0, 'target' => 4], array_intersect_key(
            app(PreseasonProgress::class)->forCategories('2026-10-05', $swim->id)[$u15->id],
            ['done' => 0, 'target' => 0],
        ));
    }

    #[Test]
    public function a_guest_from_another_branch_counts_in_the_sessions_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'u15' => $u15, 'swimmer' => $swimmer] = $this->twoSportClub();
        $this->branchMark($this->branchSession($foot, $u15, '2026-10-12'), $swimmer, AttendanceStatus::Present);

        $this->assertSame(1, $this->stats()->players(self::FROM, self::TO, null, null, $foot->id)[$swimmer->id]['expected']);
        $this->assertSame(5, $this->stats()->players(self::FROM, self::TO, null, null, $swim->id)[$swimmer->id]['expected']);
    }

    #[Test]
    public function at_risk_flags_come_from_one_branch(): void
    {
        ['foot' => $foot, 'swim' => $swim, 'both' => $both] = $this->twoSportClub();
        $risk = app(AtRisk::class);

        $football = $risk->list(self::FROM, self::TO, null, $foot->id);
        $this->assertSame([$both->id], array_column($football, 'player_id'));
        $this->assertSame(3, $football[0]['longest_streak']);
        $this->assertTrue($football[0]['streak']);
        $this->assertTrue($football[0]['low_score']);
        $this->assertSame([], $risk->list(self::FROM, self::TO, null, $swim->id));

        $swimRow = $this->stats()->players(self::FROM, self::TO, null, $both->id, $swim->id)[$both->id];
        $this->assertFalse($risk->forPlayer($both->id, self::FROM, self::TO, $swimRow, $swim->id)['at_risk']);
        $footRow = $this->stats()->players(self::FROM, self::TO, null, $both->id, $foot->id)[$both->id];
        $flags = $risk->forPlayer($both->id, self::FROM, self::TO, $footRow, $foot->id);
        $this->assertTrue($flags['streak']);
        $this->assertSame(3, $flags['longest_streak']);
    }

    #[Test]
    public function the_branch_adds_no_query(): void
    {
        ['foot' => $foot] = $this->twoSportClub();
        $count = function (?int $branchId): int {
            $stats = $this->stats();
            $stats->players(self::FROM, self::TO);   // loads the settings and the custom codes once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players(self::FROM, self::TO, branchId: $branchId);
            $stats->monthly(self::FROM, self::TO, branchId: $branchId);
            $stats->sessionsByMonth(self::FROM, self::TO, branchId: $branchId);
            $stats->categories(self::FROM, self::TO, $branchId);
            $stats->unexcusedStreaks(self::FROM, self::TO, branchId: $branchId);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Once::flush();
        $without = $count(null);
        Once::flush();

        $this->assertSame($without, $count($foot->id));
    }

    #[Test]
    public function the_branch_condition_never_leads_the_query_plan(): void
    {
        $query = DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [self::FROM, self::TO]);
        BranchScope::apply($query, 1);

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))->pluck('detail')->implode("\n");

        $this->assertStringContainsString('+training_sessions.branch_id = ?', $query->toSql());
        $this->assertStringNotContainsString('training_sessions_branch_id_index', $plan);
        $this->assertSame(BranchScope::apply(DB::table('attendances'), null)->toSql(), DB::table('attendances')->toSql());
    }
}
