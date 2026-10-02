<?php

namespace App\Services\Attendance;

/**
 * Players at risk over a period, from the attendance settings' alerts:
 *  - a low score: score % strictly below `alerts.min_score_pct`, only for
 *    players expected at RANKING_MIN_EXPECTED sessions or more (the ranking's
 *    minimum, so one early absence does not raise an alert);
 *  - an unexcused streak: `alerts.unexcused_streak` or more unexcused
 *    absences in a row at any time in the period (the longest streak; the
 *    current one is reported beside it).
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats.
 */
final class AtRisk
{
    private const NO_STREAK = ['current' => 0, 'longest' => 0, 'last_date' => null];

    /** $stats is public so the dashboard card shares this instance and the settings are read once. */
    public function __construct(
        public readonly AttendanceStats $stats,
        private readonly PlayerNames $names,
    ) {}

    /** @return array{min_score_pct: int, unexcused_streak: int, min_expected: int} */
    public function thresholds(): array
    {
        $alerts = $this->stats->settings()['alerts'];

        return [
            'min_score_pct' => (int) $alerts['min_score_pct'],
            'unexcused_streak' => (int) $alerts['unexcused_streak'],
            'min_expected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ];
    }

    /**
     * @param  array{expected: int, score_pct: ?float}  $row  a players() row
     * @param  array{longest: int}  $streak  an unexcusedStreaks() row
     * @param  array{min_score_pct: int, unexcused_streak: int, min_expected: int}  $thresholds
     * @return array{at_risk: bool, low_score: bool, streak: bool}
     */
    public static function evaluate(array $row, array $streak, array $thresholds): array
    {
        $min = $thresholds['min_score_pct'];
        $lowScore = $min > 0
            && $row['expected'] >= $thresholds['min_expected']
            && $row['score_pct'] !== null
            && $row['score_pct'] < $min;
        $inARow = $thresholds['unexcused_streak'];
        $longStreak = $inARow > 0 && $streak['longest'] >= $inARow;

        return ['at_risk' => $lowScore || $longStreak, 'low_score' => $lowScore, 'streak' => $longStreak];
    }

    /**
     * Every active player (not archived, not left) at risk over the period,
     * worst first: lowest score % (none last), then longest streak, then
     * name. $categoryId keeps the players now in that category; their numbers
     * still cover all their marks, since the risk is the person's. Six
     * queries whatever the roster (the custom codes: 1, players(): 2, the
     * streak scan: 1, names: 2), four when nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $from, string $to, ?int $categoryId = null): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to);

        $flagged = [];
        foreach ($this->stats->players($from, $to) as $playerId => $row) {
            $streak = $streaks[$playerId] ?? self::NO_STREAK;
            $flags = self::evaluate($row, $streak, $thresholds);
            if (! $flags['at_risk']) {
                continue;
            }
            $flagged[] = [
                'player_id' => $playerId,
                'expected' => $row['expected'],
                'score_pct' => $row['score_pct'],
                'unexcused' => $row['scored']['absent_unexcused'],
                'current_streak' => $streak['current'],
                'longest_streak' => $streak['longest'],
                'last_date' => $streak['last_date'],
                'low_score' => $flags['low_score'],
                'streak' => $flags['streak'],
            ];
        }

        $rows = array_values(array_filter(
            $this->names->attach($flagged),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
        usort($rows, fn (array $a, array $b) => [$a['score_pct'] ?? 101, $b['longest_streak'], $a['name']]
            <=> [$b['score_pct'] ?? 101, $a['longest_streak'], $b['name']]);

        return $rows;
    }

    /**
     * One player's flags for the profile card, over the card's period.
     * $row is the card's own players() row (or emptyRow()).
     *
     * @return array{at_risk: bool, low_score: bool, streak: bool, score_pct: ?float, current_streak: int, longest_streak: int, min_score_pct: int, unexcused_streak: int, min_expected: int}
     */
    public function forPlayer(int $playerId, string $from, string $to, array $row): array
    {
        $streak = $this->stats->unexcusedStreaks($from, $to, $playerId)[$playerId] ?? self::NO_STREAK;
        $thresholds = $this->thresholds();

        return [
            ...self::evaluate($row, $streak, $thresholds),
            'score_pct' => $row['score_pct'],
            'current_streak' => $streak['current'],
            'longest_streak' => $streak['longest'],
            ...$thresholds,
        ];
    }
}
