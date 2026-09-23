<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The dashboard's view state, resolved once per request.
 *
 * Every stat provider reads its window, its branch and its comparison window
 * from here, so "what does this period mean" is answered in one place instead
 * of once per widget. The state lives in the query string, which makes a
 * dashboard view shareable and survivable across a refresh.
 */
class DashboardFilters
{
    public const RANGES = ['month', 'last_month', 'quarter', 'year', 'last12', 'all'];

    public const TABS = ['overview', 'finance', 'members', 'operations'];

    public function __construct(
        public readonly string $range,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?CarbonImmutable $prevFrom,
        public readonly ?CarbonImmutable $prevTo,
        public readonly ?int $branchId,
        public readonly bool $compare,
        public readonly string $tab,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $range = (string) $request->query('range', 'month');
        if (! in_array($range, self::RANGES, true)) {
            $range = 'month';
        }

        $tab = (string) $request->query('tab', 'overview');
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'overview';
        }

        $branch = $request->query('branch');
        $branchId = ($branch === null || $branch === '' || $branch === 'all') ? null : (int) $branch;

        // "All time" has no previous period to compare against, so the toggle
        // cannot be on regardless of what the query string asks for.
        $compare = $range !== 'all' && filter_var(
            $request->query('compare', '1'),
            FILTER_VALIDATE_BOOLEAN,
        );

        [$from, $to, $prevFrom, $prevTo] = self::resolveWindow($range);

        return new self($range, $from, $to, $prevFrom, $prevTo, $branchId, $compare, $tab);
    }

    /** @return array{0:?CarbonImmutable,1:?CarbonImmutable,2:?CarbonImmutable,3:?CarbonImmutable} */
    private static function resolveWindow(string $range): array
    {
        $now = CarbonImmutable::now();

        return match ($range) {
            'month' => [
                $from = $now->startOfMonth(),
                $now->endOfMonth(),
                $from->subMonth()->startOfMonth(),
                $from->subMonth()->endOfMonth(),
            ],
            'last_month' => [
                $from = $now->subMonth()->startOfMonth(),
                $now->subMonth()->endOfMonth(),
                $from->subMonth()->startOfMonth(),
                $from->subMonth()->endOfMonth(),
            ],
            'quarter' => [
                $from = $now->startOfQuarter(),
                $now->endOfQuarter(),
                $from->subQuarter()->startOfQuarter(),
                $from->subQuarter()->endOfQuarter(),
            ],
            'year' => [
                $from = $now->startOfYear(),
                $now->endOfYear(),
                $from->subYear()->startOfYear(),
                $from->subYear()->endOfYear(),
            ],
            'last12' => [
                $from = $now->startOfMonth()->subMonths(11),
                $now->endOfMonth(),
                $from->subYear(),
                $from->subDay()->endOfDay(),
            ],
            default => [null, null, null, null],
        };
    }

    public function isAllTime(): bool
    {
        return $this->range === 'all';
    }

    public function hasComparison(): bool
    {
        return $this->compare && $this->prevFrom !== null;
    }

    /** The end of the window, or now when the range is all-time. */
    public function anchor(): CarbonImmutable
    {
        return $this->to ?? CarbonImmutable::now();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'range' => $this->range,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'branch' => $this->branchId,
            'compare' => $this->compare,
            'tab' => $this->tab,
            'ranges' => self::RANGES,
        ];
    }
}
