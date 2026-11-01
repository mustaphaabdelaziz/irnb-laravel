<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Turns a report's row source into what the viewer and the exports need:
 * sorted on a whitelisted column, then one page (screen) or every row
 * (download). Builders sort and page in SQL; collections in memory.
 */
final class ReportRunner
{
    /** Rows fetched per chunk when a download walks a Builder. */
    private const CHUNK = 500;

    public function page(Report $report, ReportFilters $filters): LengthAwarePaginator
    {
        $source = $this->sorted($report, $filters);

        if ($source instanceof Builder) {
            return $source->paginate($filters->perPage)
                ->withQueryString()
                ->through(fn ($record) => $report->row($record));
        }

        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $source->forPage($page, $filters->perPage)->values(),
            $source->count(),
            $filters->perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    /** @return iterable<array<string, mixed>> */
    public function all(Report $report, ReportFilters $filters): iterable
    {
        $source = $this->sorted($report, $filters);

        if ($source instanceof Builder) {
            return $source->lazy(self::CHUNK)->map(fn ($record) => $report->row($record));
        }

        return $source;
    }

    /** @return Builder|Collection<int, array<string, mixed>> */
    private function sorted(Report $report, ReportFilters $filters): Builder|Collection
    {
        $source = $report->rows($filters);
        $sortable = $report->sortable();
        $key = $filters->sort !== null && isset($sortable[$filters->sort]) ? $filters->sort : null;

        if ($source instanceof Builder) {
            if ($key !== null) {
                // The expression comes from the report's own whitelist, the
                // direction from a validated asc|desc: nothing user-typed reaches SQL.
                $source->reorder()->orderByRaw($sortable[$key].' '.$filters->dir);
            }

            // A stable order across pages.
            return $source->orderBy($source->getModel()->getQualifiedKeyName());
        }

        $rows = $source->map(fn ($record) => $report->row($record))->values();

        if ($key === null) {
            return $rows;
        }

        return $rows->sortBy(fn (array $row) => $row[$sortable[$key]] ?? null, SORT_NATURAL | SORT_FLAG_CASE, $filters->dir === 'desc')->values();
    }
}
