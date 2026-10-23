<?php

namespace App\Services\Attendance;

use App\Models\Branch;

/**
 * Players at risk over a period, from the attendance settings' alerts:
 *  - a low score: score % strictly below `alerts.min_score_pct`, only for
 *    players expected at RANKING_MIN_EXPECTED sessions or more (the ranking's
 *    minimum, so one early absence does not raise an alert);
 *  - an unexcused streak: `alerts.unexcused_streak` or more unexcused
 *    absences in a row at any time in the period (the longest streak; the
 *    current one is reported beside it).
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats,
 * over one branch's sessions ($branchId; null = no branch condition, a club
 * without branches): a two-sport player is judged per sport.
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
     * Every active player (not archived, not left) at risk over the period
     * in one branch, worst first: lowest score % (none last), then longest
     * streak, then name. $categoryId keeps the players now in that category;
     * their numbers still cover all their marks in the branch, since the
     * risk is the person's. Six queries whatever the roster (the custom
     * codes: 1, players(): 2, the streak scan: 1, names: 2), four when
     * nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $from, string $to, ?int $categoryId = null, ?int $branchId = null): array
    {
        return $this->named($this->flagged($from, $to, $branchId), $categoryId);
    }

    /**
     * The players at risk in any branch, each once (the dashboard card): a
     * player flagged in two branches is kept with the branch where they fare
     * worst, this list's order. Each row is a list() row plus `branch_id`
     * and `branch` (its name, only when the club has two or more branches).
     * A club without branches is one pass with no branch condition.
     * 1 (the branches) + 3 per pass + the custom codes 1 + names 2 queries,
     * the names skipped when nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function anyBranch(string $from, string $to): array
    {
        $branches = Branch::orderBy('id')->get();
        $names = $branches->count() > 1
            ? $branches->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->localized_name])->all()
            : [];

        $flagged = [];
        foreach ($branches->isEmpty() ? [null] : $branches->modelKeys() as $branchId) {
            foreach ($this->flagged($from, $to, $branchId) as $row) {
                $flagged[] = [...$row, 'branch_id' => $branchId, 'branch' => $names[$branchId] ?? null];
            }
        }

        $rows = [];
        foreach ($this->named($flagged, null) as $row) {
            $rows[$row['player_id']] ??= $row;   // worst first: a player's first row is their worst branch
        }

        return array_values($rows);
    }

    /**
     * One player's flags for the profile card, over the card's period and branch.
     * $row is the card's own players() row (or emptyRow()), for the same branch.
     *
     * @return array{at_risk: bool, low_score: bool, streak: bool, score_pct: ?float, current_streak: int, longest_streak: int, min_score_pct: int, unexcused_streak: int, min_expected: int}
     */
    public function forPlayer(int $playerId, string $from, string $to, array $row, ?int $branchId = null): array
    {
        $streak = $this->stats->unexcusedStreaks($from, $to, $playerId, $branchId)[$playerId] ?? self::NO_STREAK;
        $thresholds = $this->thresholds();

        return [
            ...self::evaluate($row, $streak, $thresholds),
            'score_pct' => $row['score_pct'],
            'current_streak' => $streak['current'],
            'longest_streak' => $streak['longest'],
            ...$thresholds,
        ];
    }

    /**
     * The flagged players of one branch (null: no branch condition), without
     * names. Three queries (players(): 2, the streak scan: 1).
     *
     * @return list<array<string, mixed>>
     */
    private function flagged(string $from, string $to, ?int $branchId): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to, null, $branchId);

        $flagged = [];
        foreach ($this->stats->players($from, $to, null, null, $branchId) as $playerId => $row) {
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

        return $flagged;
    }

    /**
     * Names attached (PlayerNames), active players only, optionally those now
     * in $categoryId, worst first (usort is stable: equal rows keep their order).
     *
     * @return list<array<string, mixed>>
     */
    private function named(array $flagged, ?int $categoryId): array
    {
        $rows = array_values(array_filter(
            $this->names->attach($flagged),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
        usort($rows, fn (array $a, array $b) => [$a['score_pct'] ?? 101, $b['longest_streak'], $a['name']]
            <=> [$b['score_pct'] ?? 101, $a['longest_streak'], $b['name']]);

        return $rows;
    }
}
