<?php

namespace Tests\Unit;

use App\Services\Attendance\AtRisk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceRiskRulesTest extends TestCase
{
    private const ON = ['min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5];

    private static function row(int $expected, ?float $pct): array
    {
        return ['expected' => $expected, 'score_pct' => $pct];
    }

    private static function streak(int $longest, int $current = 0): array
    {
        return ['current' => $current, 'longest' => $longest, 'last_date' => '2026-10-01'];
    }

    #[Test]
    public function the_score_rule_is_strictly_below_the_threshold_with_enough_sessions(): void
    {
        $this->assertFalse(AtRisk::evaluate(self::row(5, 60.0), self::streak(0), self::ON)['low_score'], 'equal is not below');
        $this->assertTrue(AtRisk::evaluate(self::row(5, 59.9), self::streak(0), self::ON)['low_score']);
        $this->assertFalse(AtRisk::evaluate(self::row(4, 0.0), self::streak(0), self::ON)['low_score'], 'fewer than 5 expected sessions');
        $this->assertFalse(AtRisk::evaluate(self::row(0, null), self::streak(0), self::ON)['low_score']);
        $this->assertFalse(AtRisk::evaluate(self::row(10, 0.0), self::streak(0), array_merge(self::ON, ['min_score_pct' => 0]))['low_score'], '0 turns it off');
    }

    #[Test]
    public function the_streak_rule_uses_the_longest_streak_and_zero_turns_it_off(): void
    {
        $this->assertTrue(AtRisk::evaluate(self::row(10, 100.0), self::streak(3, 3), self::ON)['streak'], 'equal to the threshold');
        $this->assertTrue(AtRisk::evaluate(self::row(10, 100.0), self::streak(3, 0), self::ON)['at_risk'], 'an earlier streak in the period still counts');
        $this->assertFalse(AtRisk::evaluate(self::row(10, 100.0), self::streak(2, 2), self::ON)['streak']);
        $this->assertTrue(AtRisk::evaluate(self::row(2, 100.0), self::streak(3), self::ON)['streak'], 'no minimum of sessions for the streak rule');
        $this->assertFalse(AtRisk::evaluate(self::row(10, 100.0), self::streak(9), array_merge(self::ON, ['unexcused_streak' => 0]))['at_risk'], '0 turns it off');
    }

    #[Test]
    public function either_rule_puts_the_player_at_risk(): void
    {
        $this->assertSame(['at_risk' => true, 'low_score' => true, 'streak' => true], AtRisk::evaluate(self::row(6, 0.0), self::streak(3, 3), self::ON));
        $this->assertSame(['at_risk' => true, 'low_score' => true, 'streak' => false], AtRisk::evaluate(self::row(6, 55.0), self::streak(1), self::ON));
        $this->assertSame(['at_risk' => false, 'low_score' => false, 'streak' => false], AtRisk::evaluate(self::row(6, 100.0), self::streak(0), self::ON));
    }
}
