<?php

namespace App\Services\Dashboard;

use App\Enums\AcademicCertificate;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Services\Dashboard\Support\BranchScope;
use App\Services\Dashboard\Support\DeltaCalculator;
use App\Services\Dashboard\Support\MonthBucket;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who the club's members are, and whether there are more of them than before.
 *
 * Headcount is a snapshot; growth, renewal and churn are the figures that say
 * whether the snapshot is going the right way.
 */
class MemberStats
{
    /** Upper bound of each age band, in years. The last band is open-ended. */
    private const AGE_BANDS = [
        'u12' => 12,
        '12_15' => 16,
        '16_18' => 19,
        '19_25' => 26,
        '26_35' => 36,
        '36_plus' => null,
    ];

    private const DEBT_BANDS = ['none', 'under_5k', '5k_20k', 'over_20k'];

    private const TOP_CITIES = 6;

    public function get(DashboardFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'growth' => $this->growth($filters),
            'byCategory' => $this->byCategory($filters),
            'byStatus' => $this->byStatus($filters),
            'byAge' => $this->byAge($filters),
            'debtBands' => $this->debtBands($filters),
            'split' => $this->split($filters),
            'academic' => $this->academic($filters),
            'topCities' => $this->topCities($filters),
        ];
    }

    private function summary(DashboardFilters $filters): array
    {
        $total = (int) $this->active($filters)->count();

        $joins = $this->joinsPerWindow($filters);
        $archived = $this->archivedInWindow($filters);
        $renewal = $this->renewalRate($filters);

        return [
            [
                'key' => 'total_members',
                'value' => $total,
                'format' => 'number',
                'delta' => null,
                'meta' => null,
            ],
            [
                'key' => 'new_members',
                'value' => $joins['current'],
                'format' => 'number',
                'delta' => $filters->hasComparison()
                    ? DeltaCalculator::compute((float) $joins['current'], (float) $joins['previous'])
                    : null,
                'meta' => null,
            ],
            [
                'key' => 'renewal_rate',
                'value' => $renewal,
                'format' => 'percent',
                'delta' => null,
                'meta' => null,
            ],
            [
                'key' => 'median_debt',
                'value' => $this->medianDebt($filters),
                'format' => 'money',
                'delta' => null,
                'meta' => null,
            ],
            [
                // Leaving is as much a fact about the club as joining, so it
                // gets a figure of its own rather than being silently dropped
                // from the active headcount.
                'key' => 'archived',
                'value' => $archived,
                'format' => 'number',
                'delta' => null,
                'meta' => null,
            ],
        ];
    }

    /** @return array{current: int, previous: int} */
    private function joinsPerWindow(DashboardFilters $filters): array
    {
        if ($filters->from === null) {
            return ['current' => (int) $this->active($filters)->count(), 'previous' => 0];
        }

        $row = $this->active($filters)
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN players.created_at BETWEEN ? AND ? THEN 1 END) as current_window, '
                .'COUNT(CASE WHEN players.created_at BETWEEN ? AND ? THEN 1 END) as previous_window',
                [$filters->from, $filters->to, $filters->prevFrom, $filters->prevTo],
            )
            ->first();

        return [
            'current' => (int) ($row->current_window ?? 0),
            'previous' => (int) ($row->previous_window ?? 0),
        ];
    }

    private function archivedInWindow(DashboardFilters $filters): int
    {
        $query = BranchScope::players(Player::query()->where('archived', true), $filters->branchId);

        if ($filters->from !== null) {
            // No archived_at column exists, so the row's last update stands in
            // for when it was archived. It is the only date the schema offers.
            $query->whereBetween('updated_at', [$filters->from, $filters->to]);
        }

        return (int) $query->count();
    }

    /**
     * How many of last year's subscribed members subscribed again.
     *
     * Null when there is no previous year on record — a first season has
     * nothing to renew from, and reporting 0% would read as total collapse.
     */
    private function renewalRate(DashboardFilters $filters): ?float
    {
        $year = $filters->anchor()->year;

        $previous = $this->subscribedPlayerIds($filters, $year - 1);

        if ($previous->isEmpty()) {
            return null;
        }

        $current = $this->subscribedPlayerIds($filters, $year);
        $returned = $previous->intersect($current)->count();

        return round($returned / $previous->count() * 100, 1);
    }

    /** @return Collection<int, int> */
    private function subscribedPlayerIds(DashboardFilters $filters, int $year): Collection
    {
        $query = DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->where('player_subscriptions.year', $year);

        if ($branchIds = BranchScope::playerIdsQuery($filters->branchId)) {
            $query->whereIn('player_subscriptions.player_id', $branchIds);
        }

        return $query->distinct()->pluck('player_subscriptions.player_id');
    }

    /**
     * The middle member's debt, not the average.
     *
     * One member owing 300 000 drags a mean far above anything a real member
     * owes; the median says what the typical member's position actually is.
     */
    private function medianDebt(DashboardFilters $filters): ?float
    {
        $debts = $this->active($filters)
            ->orderBy('outstanding_debt')
            ->pluck('outstanding_debt')
            ->map(fn ($value): float => (float) $value)
            ->values();

        if ($debts->isEmpty()) {
            return null;
        }

        $middle = intdiv($debts->count(), 2);

        return $debts->count() % 2 === 1
            ? round($debts[$middle], 2)
            : round(($debts[$middle - 1] + $debts[$middle]) / 2, 2);
    }

    private function growth(DashboardFilters $filters): array
    {
        $labels = MonthBucket::lastTwelve($filters->anchor());
        $bucket = MonthBucket::expression('players.created_at');

        $joinedByMonth = $this->active($filters)
            ->toBase()
            ->selectRaw("{$bucket} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        $joined = MonthBucket::series($joinedByMonth, $labels);

        // Members who joined before the window still count towards the running
        // total, otherwise the line would restart from zero every year.
        $before = (float) $this->active($filters)
            ->where('players.created_at', '<', CarbonImmutable::parse($labels[0].'-01')->startOfMonth())
            ->count();

        $cumulative = [];
        $running = $before;
        foreach ($joined as $count) {
            $running += $count;
            $cumulative[] = $running;
        }

        return [
            'labels' => $labels,
            'joined' => $joined,
            'cumulative' => $cumulative,
        ];
    }

    private function byCategory(DashboardFilters $filters): array
    {
        return $this->groupedByRelation(
            $filters,
            'categories',
            'players.category_id',
            'categories.id',
        );
    }

    private function byStatus(DashboardFilters $filters): array
    {
        return $this->groupedByRelation(
            $filters,
            'player_statuses',
            'players.status_id',
            'player_statuses.id',
        );
    }

    /**
     * Headcount grouped by a localised lookup table.
     *
     * The locale column is chosen in SQL rather than hydrating a model per row
     * to read its localized_name accessor, and falls back to the base name in
     * exactly the same way.
     */
    private function groupedByRelation(DashboardFilters $filters, string $table, string $foreignKey, string $ownerKey): array
    {
        $localeColumn = "{$table}.name_".app()->getLocale();

        return $this->active($filters)
            ->toBase()
            ->leftJoin($table, $ownerKey, '=', $foreignKey)
            ->groupBy("{$table}.id", "{$table}.name", $localeColumn)
            ->orderByDesc('total')
            ->selectRaw(
                "COALESCE(NULLIF({$localeColumn}, ''), {$table}.name) as name, COUNT(*) as total"
            )
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->name ?: null,
                'labelKey' => $row->name ? null : 'uncategorised',
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Age distribution, every band always present.
     *
     * Bands are computed in PHP from the birthdate: SQLite and MySQL disagree
     * on date arithmetic, and a member count is small enough that one pass
     * over the column costs nothing.
     */
    private function byAge(DashboardFilters $filters): array
    {
        $today = CarbonImmutable::now();

        $counts = array_fill_keys(array_keys(self::AGE_BANDS), 0);
        $counts['unknown'] = 0;

        $this->active($filters)
            ->toBase()
            ->select('players.birthdate')
            ->get()
            ->each(function (object $row) use (&$counts, $today): void {
                if (empty($row->birthdate)) {
                    $counts['unknown']++;

                    return;
                }

                $age = CarbonImmutable::parse($row->birthdate)->diffInYears($today);

                foreach (self::AGE_BANDS as $band => $upperBound) {
                    if ($upperBound === null || $age < $upperBound) {
                        $counts[$band]++;

                        return;
                    }
                }
            });

        return collect($counts)
            ->map(fn (int $count, string $band): array => ['band' => $band, 'count' => $count])
            ->values()
            ->all();
    }

    private function debtBands(DashboardFilters $filters): array
    {
        $row = $this->active($filters)
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN outstanding_debt <= 0 THEN 1 END) as none, '
                .'COUNT(CASE WHEN outstanding_debt > 0 AND outstanding_debt < 5000 THEN 1 END) as under_5k, '
                .'COUNT(CASE WHEN outstanding_debt >= 5000 AND outstanding_debt < 20000 THEN 1 END) as band_5k_20k, '
                .'COUNT(CASE WHEN outstanding_debt >= 20000 THEN 1 END) as over_20k'
            )
            ->first();

        $values = [
            'none' => (int) ($row->none ?? 0),
            'under_5k' => (int) ($row->under_5k ?? 0),
            '5k_20k' => (int) ($row->band_5k_20k ?? 0),
            'over_20k' => (int) ($row->over_20k ?? 0),
        ];

        return array_map(
            fn (string $band): array => ['band' => $band, 'count' => $values[$band]],
            self::DEBT_BANDS,
        );
    }

    private function split(DashboardFilters $filters): array
    {
        $row = $this->active($filters)
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN is_student = 1 THEN 1 END) as students, '
                .'COUNT(CASE WHEN is_student = 0 THEN 1 END) as workers, '
                ."COUNT(CASE WHEN gender = 'Male' THEN 1 END) as male, "
                ."COUNT(CASE WHEN gender = 'Female' THEN 1 END) as female"
            )
            ->first();

        return [
            'students' => (int) ($row->students ?? 0),
            'workers' => (int) ($row->workers ?? 0),
            'male' => (int) ($row->male ?? 0),
            'female' => (int) ($row->female ?? 0),
        ];
    }

    private function topCities(DashboardFilters $filters): array
    {
        return $this->active($filters)
            ->toBase()
            ->whereNotNull('players.city')
            ->where('players.city', '!=', '')
            ->groupBy('players.city')
            ->orderByDesc('total')
            ->limit(self::TOP_CITIES)
            ->selectRaw('players.city as name, COUNT(*) as total')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /** How the club's students are doing at school, judged on each one's latest trimester converted to /20. */
    private function academic(DashboardFilters $filters): array
    {
        $latest = $this->active($filters)
            ->where('players.is_student', true)
            ->toBase()
            ->selectRaw('('.PlayerAcademicRecord::latestOn20Sql().') as latest_on20')
            ->pluck('latest_on20');

        $graded = $latest->filter(fn ($grade) => $grade !== null)->map(fn ($grade) => (float) $grade);

        return [
            'students' => $latest->count(),
            'average' => $graded->isEmpty() ? null : round($graded->avg(), 2),
            'at_risk' => $graded->filter(fn (float $grade) => $grade < PlayerAcademicRecord::PASS_MARK_ON_20)->count(),
            'missing' => $latest->count() - $graded->count(),
            // Computed once and passed down: Season::current() reads website_configs on
            // every call by design (no caching), so a second call here would cost an
            // extra query for no reason — the two blocks share the same school year.
            'certificates' => $this->certificateCounts($filters, Season::current()->startYear),
        ];
    }

    /**
     * How many trimester records carried each certificate this school year, among
     * branch-scoped active students. A student with two excellence trimesters in
     * the same year counts as 2 — this is a count of awards, not of students.
     *
     * @return array<string, int>
     */
    private function certificateCounts(DashboardFilters $filters, int $currentYear): array
    {
        $counts = $this->active($filters)
            ->where('players.is_student', true)
            ->toBase()
            ->join('player_academic_years', 'player_academic_years.player_id', '=', 'players.id')
            ->join('player_academic_records', 'player_academic_records.player_academic_year_id', '=', 'player_academic_years.id')
            ->where('player_academic_years.academic_year', $currentYear)
            ->whereIn('player_academic_records.certificate', AcademicCertificate::values())
            ->groupBy('player_academic_records.certificate')
            ->selectRaw('player_academic_records.certificate as certificate, COUNT(*) as total')
            ->pluck('total', 'certificate');

        return collect(AcademicCertificate::values())
            ->mapWithKeys(fn (string $certificate) => [$certificate => (int) ($counts[$certificate] ?? 0)])
            ->all();
    }

    private function active(DashboardFilters $filters): Builder
    {
        return BranchScope::players(
            Player::query()->where('players.archived', false),
            $filters->branchId,
        );
    }
}
