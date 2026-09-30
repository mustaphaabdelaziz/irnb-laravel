<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceScoreTest extends TestCase
{
    /** present 1, late 0.75, left_early 0.75, not_training 0.5, absent_excused 0, absent_unexcused -1 */
    private const POINTS = AttendanceSettings::DEFAULTS['points'];

    /** 3 lates = 1 unexcused; a late over 30 minutes = unexcused */
    private const RULES = AttendanceSettings::DEFAULTS['rules'];

    private const OFF = ['lates_per_unexcused' => 0, 'late_minutes_as_absent' => 0];

    private static function counts(array $counts): array
    {
        return $counts + array_fill_keys(AttendanceStatus::values(), 0);
    }

    private static function row(int $id, int $expected, ?float $pct, int $unexcused = 0, int $late = 0): array
    {
        return [
            'player_id' => $id, 'expected' => $expected, 'score_pct' => $pct,
            'counts' => self::counts(['absent_unexcused' => $unexcused, 'late' => $late]),
        ];
    }

    #[Test]
    public function points_add_up_per_status(): void
    {
        $counts = self::counts(['present' => 4, 'left_early' => 2, 'not_training' => 1, 'absent_excused' => 1, 'absent_unexcused' => 1]);

        // 4 + 1.5 + 0.5 + 0 - 1
        $this->assertSame(5.0, AttendanceStats::score($counts, 0, self::POINTS, self::RULES));
    }

    #[Test]
    public function a_long_late_scores_as_an_unexcused_absence(): void
    {
        $counts = self::counts(['present' => 1, 'late' => 2]);

        // One of the two lates is over the threshold: 1 + 0.75 - 1.
        $this->assertSame(0.75, AttendanceStats::score($counts, 1, self::POINTS, self::RULES));
        // Rules off: 1 + 2 x 0.75.
        $this->assertSame(2.5, AttendanceStats::score($counts, 0, self::POINTS, self::OFF));
    }

    #[Test]
    public function every_full_group_of_lates_turns_one_late_into_an_unexcused_absence(): void
    {
        // Seven lates, groups of three: two groups; the seventh stays a late.
        // 7 x 0.75 + 2 x (-1 - 0.75)
        $this->assertSame(1.75, AttendanceStats::score(self::counts(['late' => 7]), 0, self::POINTS, self::RULES));
        $this->assertSame(1.5, AttendanceStats::score(self::counts(['late' => 2]), 0, self::POINTS, self::RULES));
        $this->assertSame(5.25, AttendanceStats::score(self::counts(['late' => 7]), 0, self::POINTS, self::OFF));
    }

    #[Test]
    public function lates_already_scored_as_absences_are_not_grouped_again(): void
    {
        // Seven lates, one of them long: it scores -1 on its own; the six left make two groups.
        // 6 x 0.75 - 1 + 2 x (-1.75) = 0
        $this->assertSame(0.0, AttendanceStats::score(self::counts(['late' => 7]), 1, self::POINTS, self::RULES));
        // Three lates, one long: two remain, no full group. 2 x 0.75 - 1
        $this->assertSame(0.5, AttendanceStats::score(self::counts(['late' => 3]), 1, self::POINTS, self::RULES));
    }

    #[Test]
    public function left_early_marks_are_never_touched_by_the_late_rules(): void
    {
        $this->assertSame(4.5, AttendanceStats::score(self::counts(['left_early' => 6]), 0, self::POINTS, self::RULES));
    }

    #[Test]
    public function score_percent_is_clamped_and_null_when_it_cannot_be_computed(): void
    {
        $this->assertSame(50.0, AttendanceStats::scorePct(5.0, 10, 1.0));
        $this->assertSame(33.3, AttendanceStats::scorePct(1.0, 3, 1.0));
        $this->assertSame(25.0, AttendanceStats::scorePct(1.0, 2, 2.0));   // present worth 2: the maximum is 4
        $this->assertSame(0.0, AttendanceStats::scorePct(-4.0, 4, 1.0));   // below zero: clamped
        $this->assertSame(100.0, AttendanceStats::scorePct(8.0, 4, 1.0));  // a status worth more than present: clamped
        $this->assertNull(AttendanceStats::scorePct(0.0, 0, 1.0));         // nothing expected
        $this->assertNull(AttendanceStats::scorePct(3.0, 3, 0.0));         // present worth nothing
        $this->assertNull(AttendanceStats::scorePct(3.0, 3, -1.0));
    }

    #[Test]
    public function session_length_and_months(): void
    {
        $this->assertSame(90, AttendanceStats::duration('18:00', '19:30'));
        $this->assertSame(60, AttendanceStats::duration('09:15', '10:15'));
        $this->assertSame(0, AttendanceStats::duration('19:30', '18:00'));

        $this->assertSame(['2026-10'], AttendanceStats::months('2026-10-01', '2026-10-31'));
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], AttendanceStats::months('2026-11-15', '2027-02-03'));
    }

    #[Test]
    public function the_ranking_keeps_players_with_enough_sessions_and_breaks_ties(): void
    {
        $rows = [
            self::row(1, 10, 100.0),
            self::row(2, 10, 80.0),
            self::row(3, 5, 80.0, late: 2),     // same % as 2 but more lates: after 2
            self::row(4, 6, 50.0, unexcused: 1),
            self::row(5, 6, 50.0, unexcused: 2),
            self::row(6, 8, 10.0, unexcused: 3),
            self::row(7, 8, 10.0, unexcused: 5),
            self::row(8, 4, 100.0),             // 4 sessions: below the minimum
            self::row(9, 10, null),             // no score %
        ];

        $ranking = AttendanceStats::ranking($rows);

        $this->assertSame(5, $ranking['min_expected']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($ranking['top'], 'player_id'));
        // The bottom list never repeats the top one; ties: more unexcused first.
        $this->assertSame([7, 6], array_column($ranking['bottom'], 'player_id'));

        $few = AttendanceStats::ranking([self::row(1, 5, 90.0), self::row(2, 5, 40.0)]);
        $this->assertSame([1, 2], array_column($few['top'], 'player_id'));
        $this->assertSame([], $few['bottom']);
    }

    #[Test]
    public function the_full_ranking_numbers_every_eligible_player_with_the_same_tie_rules(): void
    {
        $rows = [
            self::row(1, 10, 100.0),
            self::row(2, 10, 80.0),
            self::row(3, 5, 80.0, late: 2),     // same % as 2 but more lates: after 2
            self::row(6, 8, 10.0, unexcused: 3),
            self::row(7, 8, 10.0, unexcused: 5),
            self::row(8, 4, 100.0),             // below the minimum
            self::row(9, 10, null),             // no score %
        ];

        $ranked = AttendanceStats::ranked($rows);

        $this->assertSame([1, 2, 3, 6, 7], array_column($ranked, 'player_id'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($ranked, 'rank'));
        $this->assertSame([], AttendanceStats::ranked([self::row(1, 4, 90.0)]));
    }
}
