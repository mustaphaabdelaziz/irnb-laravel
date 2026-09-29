<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every attendance number in one place: per player, per category, per month.
 *
 * Only held sessions count: a cancelled session keeps its marks but is
 * ignored, a planned one has none. "Expected" is the number of marks a player
 * has in those sessions, so later roster changes never rewrite history.
 * Counts are the raw marks; the discipline rules change the score only.
 *
 * Every method runs a fixed number of grouped queries, whatever the roster
 * size, with the query builder only (same SQL on sqlite and MySQL).
 *
 * Category attribution: a single-category session keeps every mark in it,
 * guests included. A joint (multi-category) session attributes each mark to
 * `attendances.category_id` — the category the player was in when the mark
 * was recorded (see MarkRecorder), fixed forever after — as long as that
 * category still takes part in the session; otherwise (a guest whose own
 * category never took part) the mark falls back to the session's primary
 * `training_sessions.category_id`. Either way a mark lands in exactly one
 * category, so the category totals always sum to the global total, and a
 * later roster move never rewrites which category a past mark belongs to.
 */
final class AttendanceStats
{
    /** Players expected at fewer sessions than this are not ranked. */
    public const RANKING_MIN_EXPECTED = 5;

    public const RANKING_SIZE = 5;

    /** Statuses that cost the player the session's time (missed hours). */
    public const MISSED = ['absent_excused', 'absent_unexcused', 'not_training'];

    private ?array $settings = null;

    public function __construct(private readonly PreseasonProgress $preseason) {}

    /** @return array<int, array<string, mixed>> keyed and ordered by player id */
    public function players(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array
    {
        $settings = $this->settings();
        $longLate = max(0, (int) $settings['rules']['late_minutes_as_absent']);

        $grouped = $this->marks($from, $to, $categoryId, $playerId)
            ->select('attendances.player_id', 'attendances.status')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(attendances.minutes), 0) as minutes')
            ->selectRaw('sum(case when attendances.minutes > ? then 1 else 0 end) as long_marks', [$longLate])
            ->groupBy('attendances.player_id', 'attendances.status')
            ->get();

        $counts = [];
        $lateMinutes = [];
        $longLates = [];
        foreach ($grouped as $row) {
            $id = (int) $row->player_id;
            $counts[$id][$row->status] = (int) $row->total;
            if ($row->status === AttendanceStatus::Late->value) {
                $lateMinutes[$id] = (int) $row->minutes;
                $longLates[$id] = $longLate > 0 ? (int) $row->long_marks : 0;
            }
        }
        ksort($counts);

        $missed = $this->missedMinutes($this->marks($from, $to, $categoryId, $playerId), 'attendances.player_id');
        $points = $settings['points'];

        $rows = [];
        foreach ($counts as $id => $byStatus) {
            $base = $this->base($byStatus, $lateMinutes[$id] ?? 0, $missed[$id] ?? 0);
            $score = self::score($base['counts'], $longLates[$id] ?? 0, $points, $settings['rules']);
            $rows[$id] = [
                'player_id' => $id,
                ...$base,
                'score' => $score,
                'score_pct' => self::scorePct($score, $base['expected'], (float) $points['present']),
            ];
        }

        return $rows;
    }

    /** A player with no mark in the period. */
    public function emptyRow(int $playerId): array
    {
        return ['player_id' => $playerId, ...$this->base([], 0, 0), 'score' => 0.0, 'score_pct' => null];
    }

    /** Totals over players() rows; the score adds each player's own score. */
    public function summarize(array $rows): array
    {
        $counts = array_fill_keys(AttendanceStatus::values(), 0);
        $lateMinutes = 0;
        $missedMinutes = 0;
        $score = 0.0;
        foreach ($rows as $row) {
            foreach ($counts as $status => $n) {
                $counts[$status] = $n + $row['counts'][$status];
            }
            $lateMinutes += $row['late_minutes'];
            $missedMinutes += $row['missed_minutes'];
            $score += $row['score'];
        }

        $base = $this->base($counts, $lateMinutes, $missedMinutes);
        $score = round($score, 2);

        return [
            ...$base,
            'score' => $score,
            'score_pct' => self::scorePct($score, $base['expected'], (float) $this->settings()['points']['present']),
            'players' => count($rows),
        ];
    }

    /** @return array{labels: list<string>, statuses: array<string, list<int>>} marks per month and status */
    public function monthly(string $from, string $to, ?int $categoryId = null, ?int $playerId = null): array
    {
        $labels = self::months($from, $to);
        $index = array_flip($labels);
        $statuses = array_fill_keys(AttendanceStatus::values(), array_fill(0, count($labels), 0));

        $rows = $this->marks($from, $to, $categoryId, $playerId)
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('attendances.status')
            ->selectRaw('count(*) as total')
            ->groupByRaw('substr(training_sessions.date, 1, 7), attendances.status')
            ->get();

        foreach ($rows as $row) {
            if (isset($index[$row->ym], $statuses[$row->status])) {
                $statuses[$row->status][$index[$row->ym]] = (int) $row->total;
            }
        }

        return ['labels' => $labels, 'statuses' => $statuses];
    }

    /** @return array{labels: list<string>, held: list<int>, cancelled: list<int>} a joint session counts once */
    public function sessionsByMonth(string $from, string $to, ?int $categoryId = null): array
    {
        $labels = self::months($from, $to);
        $index = array_flip($labels);
        $held = SessionState::Held->value;
        $cancelled = SessionState::Cancelled->value;
        $series = [$held => array_fill(0, count($labels), 0), $cancelled => array_fill(0, count($labels), 0)];

        $rows = DB::table('training_sessions')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [$held, $cancelled])
            ->when($categoryId !== null, fn (Builder $q) => $q->whereIn('training_sessions.id', $this->sessionsOf($categoryId)))
            ->selectRaw('substr(training_sessions.date, 1, 7) as ym')
            ->addSelect('training_sessions.state')
            ->selectRaw('count(*) as total')
            ->groupByRaw('substr(training_sessions.date, 1, 7), training_sessions.state')
            ->get();

        foreach ($rows as $row) {
            if (isset($index[$row->ym])) {
                $series[$row->state][$index[$row->ym]] = (int) $row->total;
            }
        }

        return ['labels' => $labels, 'held' => $series[$held], 'cancelled' => $series[$cancelled]];
    }

    /** @return list<array<string, mixed>> one row per category, by id */
    public function categories(string $from, string $to): array
    {
        // Each mark exactly once, under the category it is attributed to
        // (see the class comment and attributedTo()).
        $attributed = fn (): Builder => $this->attributedTo(
            DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->join('training_session_category', 'training_session_category.training_session_id', '=', 'attendances.training_session_id')
                ->where('training_sessions.state', SessionState::Held->value)
                ->whereBetween('training_sessions.date', [$from, $to]),
            'training_session_category.category_id',
        );

        $counts = [];
        $lateMinutes = [];
        $grouped = $attributed()
            ->select('training_session_category.category_id', 'attendances.status')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(attendances.minutes), 0) as minutes')
            ->groupBy('training_session_category.category_id', 'attendances.status')
            ->get();
        foreach ($grouped as $row) {
            $counts[(int) $row->category_id][$row->status] = (int) $row->total;
            if ($row->status === AttendanceStatus::Late->value) {
                $lateMinutes[(int) $row->category_id] = (int) $row->minutes;
            }
        }
        $missed = $this->missedMinutes($attributed(), 'training_session_category.category_id');

        $sessions = [];
        $byState = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->whereBetween('training_sessions.date', [$from, $to])
            ->whereIn('training_sessions.state', [SessionState::Held->value, SessionState::Cancelled->value])
            ->select('training_session_category.category_id', 'training_sessions.state')
            ->selectRaw('count(*) as total')
            ->groupBy('training_session_category.category_id', 'training_sessions.state')
            ->get();
        foreach ($byState as $row) {
            $sessions[(int) $row->category_id][$row->state] = (int) $row->total;
        }

        $preseason = $this->preseason->forCategories($to);

        return Category::orderBy('id')->get()
            ->map(fn (Category $category) => [
                'category_id' => $category->id,
                'name' => $category->localized_name,
                'held' => $sessions[$category->id][SessionState::Held->value] ?? 0,
                'cancelled' => $sessions[$category->id][SessionState::Cancelled->value] ?? 0,
                'preseason' => $preseason[$category->id] ?? null,
                ...$this->base($counts[$category->id] ?? [], $lateMinutes[$category->id] ?? 0, $missed[$category->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> the player's marks in held sessions, newest first */
    public function playerSessions(int $playerId, string $from, string $to): array
    {
        $marks = $this->marks($from, $to, null, $playerId)
            ->orderByDesc('training_sessions.date')
            ->orderByDesc('training_sessions.start_time')
            ->orderByDesc('training_sessions.id')
            ->get([
                'training_sessions.id', 'training_sessions.date', 'training_sessions.start_time', 'training_sessions.end_time',
                'training_sessions.kind', 'training_sessions.title', 'training_sessions.category_id',
                'attendances.status', 'attendances.minutes', 'attendances.reason', 'attendances.note',
            ]);
        if ($marks->isEmpty()) {
            return [];
        }

        $links = DB::table('training_session_category')
            ->whereIn('training_session_id', $marks->pluck('id'))
            ->get(['training_session_id', 'category_id']);
        $names = Category::whereIn('id', $links->pluck('category_id')->unique())->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->localized_name]);
        $bySession = $links->groupBy('training_session_id');

        return $marks->map(fn ($mark) => [
            'session_id' => (int) $mark->id,
            'date' => $mark->date,
            'start_time' => $mark->start_time,
            'end_time' => $mark->end_time,
            'kind' => $mark->kind,
            'title' => $mark->title,
            // Primary category first, then by id (as TrainingSession::orderedCategories()).
            'categories' => collect($bySession[$mark->id] ?? [])
                ->sortBy(fn ($link) => (int) $link->category_id === (int) $mark->category_id ? 0 : (int) $link->category_id)
                ->map(fn ($link) => $names[(int) $link->category_id] ?? '')
                ->values()
                ->all(),
            'status' => $mark->status,
            'minutes' => $mark->minutes === null ? null : (int) $mark->minutes,
            'reason' => $mark->reason,
            'note' => $mark->note,
        ])->values()->all();
    }

    /**
     * Σ points per mark with the discipline rules applied, in this order:
     * (1) a late over `late_minutes_as_absent` minutes scores as an unexcused
     * absence ($longLates counts them); (2) of the lates left after (1),
     * every full group of `lates_per_unexcused` turns one late into an
     * unexcused absence. A rule set to 0 is off. Left-early marks are never
     * touched.
     *
     * @param  array<string, int>  $counts  raw marks per status
     */
    public static function score(array $counts, int $longLates, array $points, array $rules): float
    {
        $late = (int) ($counts['late'] ?? 0);
        $longLates = min(max(0, $longLates), $late);
        $effective = $counts;
        $effective['late'] = $late - $longLates;
        $effective['absent_unexcused'] = (int) ($counts['absent_unexcused'] ?? 0) + $longLates;

        $score = 0.0;
        foreach (AttendanceStatus::values() as $status) {
            $score += (float) ($points[$status] ?? 0) * (int) ($effective[$status] ?? 0);
        }

        $perGroup = (int) ($rules['lates_per_unexcused'] ?? 0);
        if ($perGroup > 0) {
            $score += intdiv($effective['late'], $perGroup) * ((float) $points['absent_unexcused'] - (float) $points['late']);
        }

        return round($score, 2);
    }

    /** score ÷ (expected × present points), 0–100 with one decimal; null when it cannot be computed. */
    public static function scorePct(float $score, int $expected, float $presentPoints): ?float
    {
        if ($expected <= 0 || $presentPoints <= 0) {
            return null;
        }

        return round(max(0.0, min(100.0, $score / ($expected * $presentPoints) * 100)), 1);
    }

    /** Minutes between two 'H:i' times; 0 if the end is not after the start. */
    public static function duration(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));

        return max(0, ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute));
    }

    /** @return list<string> every 'Y-m' month from $from's to $to's, both included */
    public static function months(string $from, string $to): array
    {
        $labels = [];
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();
        for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $labels[] = $month->format('Y-m');
        }

        return $labels;
    }

    /**
     * Top and bottom RANKING_SIZE by score %, among players with at least
     * RANKING_MIN_EXPECTED expected sessions. Ties: top = fewer unexcused,
     * then fewer lates; bottom = more unexcused, then more lates; then id.
     * The bottom list never repeats a player from the top list.
     */
    public static function ranking(array $rows): array
    {
        $eligible = array_values(array_filter(
            $rows,
            fn (array $row) => $row['expected'] >= self::RANKING_MIN_EXPECTED && $row['score_pct'] !== null,
        ));

        $best = $eligible;
        usort($best, fn (array $a, array $b) => [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $a['player_id']]
            <=> [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $b['player_id']]);
        $top = array_slice($best, 0, self::RANKING_SIZE);
        $topIds = array_column($top, 'player_id');

        $worst = array_values(array_filter($eligible, fn (array $row) => ! in_array($row['player_id'], $topIds, true)));
        usort($worst, fn (array $a, array $b) => [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $a['player_id']]
            <=> [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $b['player_id']]);

        return [
            'min_expected' => self::RANKING_MIN_EXPECTED,
            'top' => $top,
            'bottom' => array_slice($worst, 0, self::RANKING_SIZE),
        ];
    }

    /** Marks in held sessions of the period, optionally one player's and/or one category's. */
    private function marks(string $from, string $to, ?int $categoryId, ?int $playerId): Builder
    {
        return DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->when($playerId !== null, fn (Builder $q) => $q->where('attendances.player_id', $playerId))
            ->when($categoryId !== null, fn (Builder $q) => $this->attributedTo(
                $q->whereIn('attendances.training_session_id', $this->sessionsOf($categoryId)),
                $categoryId,
            ));
    }

    /** Ids of the sessions a category takes part in (the pivot, so joint sessions too). */
    private function sessionsOf(int $categoryId): Builder
    {
        return DB::table('training_session_category')->select('training_session_id')->where('category_id', $categoryId);
    }

    /**
     * The one attribution rule (see the class doc), applied as a where() on
     * $query: a mark belongs to $category — either a column expression (a
     * joined `training_session_category.category_id`, one row per category
     * in the session) or a literal category id — when `attendances.category_id`
     * matches it directly, or the mark's own recorded category never took
     * part in this session and $category is the session's primary
     * (`training_sessions.category_id`). $query must already join
     * `attendances`/`training_sessions` (and, for a column expression,
     * `training_session_category`); this only adds the matching condition.
     */
    private function attributedTo(Builder $query, string|int $category): Builder
    {
        return $query->where(fn (Builder $w) => $this->matchesCategory($w, 'attendances.category_id', $category)
            ->orWhere(fn (Builder $w) => $this->matchesCategory($w, 'training_sessions.category_id', $category)
                ->whereNotExists(fn (Builder $sub) => $sub->select(DB::raw(1))
                    ->from('training_session_category as tsc_attr')
                    ->whereColumn('tsc_attr.training_session_id', 'attendances.training_session_id')
                    ->whereColumn('tsc_attr.category_id', 'attendances.category_id'))));
    }

    /** $category compared to $column: a column match for a column expression, a bound value for a literal id. */
    private function matchesCategory(Builder $query, string $column, string|int $category): Builder
    {
        return is_int($category) ? $query->where($column, $category) : $query->whereColumn($column, $category);
    }

    /** @return array<int, int> minutes missed (absences and not-training × session length) per group key */
    private function missedMinutes(Builder $marks, string $key): array
    {
        $rows = $marks->whereIn('attendances.status', self::MISSED)
            ->select($key.' as group_key', 'training_sessions.start_time', 'training_sessions.end_time')
            ->selectRaw('count(*) as total')
            ->groupBy($key, 'training_sessions.start_time', 'training_sessions.end_time')
            ->get();

        $minutes = [];
        foreach ($rows as $row) {
            $group = (int) $row->group_key;
            $minutes[$group] = ($minutes[$group] ?? 0) + (int) $row->total * self::duration($row->start_time, $row->end_time);
        }

        return $minutes;
    }

    /** Counts of all six statuses, % of expected, late minutes and missed time. */
    private function base(array $byStatus, int $lateMinutes, int $missedMinutes): array
    {
        $counts = [];
        foreach (AttendanceStatus::values() as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }
        $expected = array_sum($counts);

        return [
            'expected' => $expected,
            'counts' => $counts,
            'pct' => array_map(fn (int $n) => $expected > 0 ? round($n / $expected * 100, 1) : null, $counts),
            'late_minutes' => $lateMinutes,
            'missed_minutes' => $missedMinutes,
            'missed_hours' => round($missedMinutes / 60, 1),
        ];
    }

    private function settings(): array
    {
        return $this->settings ??= AttendanceSettings::get();
    }
}
