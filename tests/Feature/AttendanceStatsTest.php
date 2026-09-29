<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStatsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** A session; $others join it through the pivot (a joint pre-season session). */
    private function training(
        Category $category,
        string $date,
        string $start = '18:00',
        string $end = '19:30',
        SessionState $state = SessionState::Held,
        SessionKind $kind = SessionKind::Regular,
        array $others = [],
    ): TrainingSession {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'kind' => $kind, 'state' => $state,
        ]);
        foreach ($others as $other) {
            DB::table('training_session_category')->insert(['training_session_id' => $training->id, 'category_id' => $other->id]);
        }

        return $training;
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, ?int $minutes = null, ?string $reason = null, ?string $note = null): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'status' => $status, 'minutes' => $minutes, 'reason' => $reason, 'note' => $note,
        ]);
    }

    private function stats(): AttendanceStats
    {
        return app(AttendanceStats::class);
    }

    #[Test]
    public function counts_percentages_minutes_and_hours_come_from_held_sessions_only(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);

        $s1 = $this->training($u15, '2026-10-05', '18:00', '19:30');
        $this->mark($s1, $a, AttendanceStatus::Present);
        $this->mark($s1, $b, AttendanceStatus::Late, 10);
        $s2 = $this->training($u15, '2026-10-07', '18:00', '19:00');
        $this->mark($s2, $a, AttendanceStatus::AbsentUnexcused);
        $this->mark($s2, $b, AttendanceStatus::AbsentExcused, reason: 'illness');
        $s3 = $this->training($u15, '2026-10-09', '18:00', '20:00');
        $this->mark($s3, $a, AttendanceStatus::NotTraining, reason: 'injury');
        $this->mark($s3, $b, AttendanceStatus::Late, 40);
        // Never counted: a cancelled session (its marks are kept), a planned
        // one, and a held session outside the period.
        $this->mark($this->training($u15, '2026-10-12', state: SessionState::Cancelled), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-14', state: SessionState::Planned), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-11-02'), $a, AttendanceStatus::Present);

        $rows = $this->stats()->players('2026-10-01', '2026-10-31');

        $this->assertSame([$a->id, $b->id], array_keys($rows));

        $ra = $rows[$a->id];
        $this->assertSame(3, $ra['expected']);
        $this->assertSame(
            ['present' => 1, 'late' => 0, 'left_early' => 0, 'not_training' => 1, 'absent_excused' => 0, 'absent_unexcused' => 1],
            $ra['counts'],
        );
        $this->assertSame(33.3, $ra['pct']['present']);
        $this->assertSame(0.0, $ra['pct']['late']);
        $this->assertSame(0, $ra['late_minutes']);
        $this->assertSame(180, $ra['missed_minutes']);   // 60 (unexcused) + 120 (not training)
        $this->assertSame(3.0, $ra['missed_hours']);
        $this->assertSame(0.5, $ra['score']);            // 1 - 1 + 0.5
        $this->assertSame(16.7, $ra['score_pct']);       // 0.5 / (3 x 1)

        $rb = $rows[$b->id];
        $this->assertSame(2, $rb['counts']['late']);     // raw marks: the 40-minute late stays a late
        $this->assertSame(1, $rb['counts']['absent_excused']);
        $this->assertSame(50, $rb['late_minutes']);
        $this->assertSame(1.0, $rb['missed_hours']);
        $this->assertSame(-0.25, $rb['score']);          // 0.75, then 40 > 30 scores -1, then 0
        $this->assertSame(0.0, $rb['score_pct']);        // clamped at 0

        $totals = $this->stats()->summarize($rows);
        $this->assertSame(6, $totals['expected']);
        $this->assertSame(2, $totals['counts']['late']);
        $this->assertSame(50, $totals['late_minutes']);
        $this->assertSame(4.0, $totals['missed_hours']);  // 180 + 60 minutes
        $this->assertSame(0.25, $totals['score']);        // 0.5 - 0.25
        $this->assertSame(4.2, $totals['score_pct']);     // 0.25 / 6
        $this->assertSame(2, $totals['players']);
    }

    #[Test]
    public function the_discipline_rules_change_the_score_and_never_the_counts(): void
    {
        $u15 = $this->category();
        $c = $this->player($u15);
        foreach (range(1, 6) as $day) {
            $this->mark($this->training($u15, sprintf('2026-10-%02d', $day)), $c, AttendanceStatus::Late, 5);
        }
        $this->mark($this->training($u15, '2026-10-07'), $c, AttendanceStatus::Late, 45);

        $row = $this->stats()->players('2026-10-01', '2026-10-31')[$c->id];
        $this->assertSame(7, $row['counts']['late']);
        $this->assertSame(0, $row['counts']['absent_unexcused']);
        $this->assertSame(75, $row['late_minutes']);
        // The 45-minute late scores -1; the six left make two groups of three:
        // 6 x 0.75 - 1 + 2 x (-1.75) = 0.
        $this->assertSame(0.0, $row['score']);
        $this->assertSame(0.0, $row['score_pct']);

        AttendanceSettings::save(['rules' => ['lates_per_unexcused' => 0, 'late_minutes_as_absent' => 0]]);

        $row = $this->stats()->players('2026-10-01', '2026-10-31')[$c->id];
        $this->assertSame(7, $row['counts']['late']);
        $this->assertSame(5.25, $row['score']);           // 7 x 0.75, rules off
        $this->assertSame(75.0, $row['score_pct']);
    }

    #[Test]
    public function a_joint_session_is_split_by_category_and_a_guest_stays_with_the_session(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $y = $this->player($u17);
        $joint = $this->training($u15, '2026-10-03', kind: SessionKind::Preseason, others: [$u17]);
        $this->mark($joint, $x, AttendanceStatus::Present);
        $this->mark($joint, $y, AttendanceStatus::Late, 5);
        $own = $this->training($u15, '2026-10-05');   // U15 only; Y came as a guest
        $this->mark($own, $x, AttendanceStatus::Present);
        $this->mark($own, $y, AttendanceStatus::Present);
        $this->training($u17, '2026-10-06', state: SessionState::Cancelled);
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 12]);

        $all = $this->stats()->players('2026-10-01', '2026-10-31');
        $this->assertSame(2, $all[$x->id]['expected']);
        $this->assertSame(2, $all[$y->id]['expected']);

        $u15Rows = $this->stats()->players('2026-10-01', '2026-10-31', $u15->id);
        $this->assertSame(2, $u15Rows[$x->id]['expected']);
        $this->assertSame(1, $u15Rows[$y->id]['expected']);   // the guest mark only; the joint mark is U17's
        $this->assertSame(0, $u15Rows[$y->id]['counts']['late']);

        $u17Rows = $this->stats()->players('2026-10-01', '2026-10-31', $u17->id);
        $this->assertSame([$y->id], array_keys($u17Rows));
        $this->assertSame(1, $u17Rows[$y->id]['counts']['late']);

        $categories = collect($this->stats()->categories('2026-10-01', '2026-10-31'))->keyBy('category_id');
        $this->assertSame(2, $categories[$u15->id]['held']);     // the joint session counts for both
        $this->assertSame(0, $categories[$u15->id]['cancelled']);
        $this->assertSame(3, $categories[$u15->id]['expected']);
        $this->assertSame(1, $categories[$u17->id]['held']);
        $this->assertSame(1, $categories[$u17->id]['cancelled']);
        $this->assertSame(1, $categories[$u17->id]['expected']);
        $this->assertSame(5, $categories[$u17->id]['late_minutes']);
        $this->assertSame(['season' => '2026/27', 'done' => 1, 'target' => 12], $categories[$u15->id]['preseason']);
        $this->assertSame(['season' => '2026/27', 'done' => 1, 'target' => null], $categories[$u17->id]['preseason']);
    }

    #[Test]
    public function months_carry_the_marks_by_status_and_the_sessions_held_and_cancelled(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-05'), $a, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-07'), $a, AttendanceStatus::Late, 5);
        $this->mark($this->training($u15, '2026-12-02'), $a, AttendanceStatus::AbsentUnexcused);
        $this->training($u15, '2026-10-09', state: SessionState::Cancelled);
        $this->training($u15, '2026-10-12', state: SessionState::Planned);

        $monthly = $this->stats()->monthly('2026-10-01', '2026-12-31');
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $monthly['labels']);
        $this->assertSame([1, 0, 0], $monthly['statuses']['present']);
        $this->assertSame([1, 0, 0], $monthly['statuses']['late']);
        $this->assertSame([0, 0, 1], $monthly['statuses']['absent_unexcused']);
        $this->assertSame([0, 0, 0], $monthly['statuses']['left_early']);

        $sessions = $this->stats()->sessionsByMonth('2026-10-01', '2026-12-31');
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $sessions['labels']);
        $this->assertSame([2, 0, 1], $sessions['held']);
        $this->assertSame([1, 0, 0], $sessions['cancelled']);

        $this->assertSame([0, 0, 1], $this->stats()->monthly('2026-10-01', '2026-12-31', null, $a->id)['statuses']['absent_unexcused']);
    }

    #[Test]
    public function a_players_sessions_list_newest_first_with_every_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $joint = $this->training($u17, '2026-10-03', kind: SessionKind::Preseason, others: [$u15]);
        $joint->update(['title' => 'Running 7.2 km']);
        $this->mark($joint, $x, AttendanceStatus::Late, 12, note: 'Bus');
        $this->mark($this->training($u15, '2026-10-05'), $x, AttendanceStatus::AbsentExcused, reason: 'illness');

        $sessions = $this->stats()->playerSessions($x->id, '2026-10-01', '2026-10-31');

        $this->assertCount(2, $sessions);
        $this->assertSame('2026-10-05', $sessions[0]['date']);
        $this->assertSame('absent_excused', $sessions[0]['status']);
        $this->assertSame('illness', $sessions[0]['reason']);
        $this->assertSame(['U15'], $sessions[0]['categories']);
        $this->assertSame('Running 7.2 km', $sessions[1]['title']);
        $this->assertSame('preseason', $sessions[1]['kind']);
        $this->assertSame(['U17', 'U15'], $sessions[1]['categories']);   // primary first
        $this->assertSame(12, $sessions[1]['minutes']);
        $this->assertSame('Bus', $sessions[1]['note']);
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_roster(): void
    {
        $u15 = $this->category();
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $addPlayers = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($trainings as $training) {
                    $this->mark($training, $player, AttendanceStatus::Present);
                }
            }
        };
        $measure = function (): int {
            $stats = $this->stats();
            $stats->players('2026-10-01', '2026-10-31');   // loads the settings once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stats->players('2026-10-01', '2026-10-31');
            $stats->monthly('2026-10-01', '2026-10-31');
            $stats->categories('2026-10-01', '2026-10-31');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $addPlayers(3);
        $few = $measure();
        $addPlayers(30);

        $this->assertSame($few, $measure());
        // 2 (players) + 1 (monthly) + 8 (categories: grouped counts, missed
        // minutes, sessions by state, Season::forDate's own settings read,
        // PreseasonProgress::forCategories' 3 queries, the final category
        // list) = 11, fixed regardless of roster size.
        $this->assertLessThanOrEqual(11, $few);
    }
}
