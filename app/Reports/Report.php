<?php

namespace App\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One report of the Reports center: what it filters on, which columns it
 * shows, where its rows come from and what its summary tiles and charts say.
 * Reports only read: no report may create, change or delete a record.
 *
 * Who may open it is the source module's view right (players, finance, …),
 * checked by the controller on screen and on download alike.
 */
abstract class Report
{
    /** URL slug inside the category ("directory"). */
    abstract public function key(): string;

    /** One of ReportRegistry::CATEGORIES. */
    abstract public function category(): string;

    /** The permission module whose view right opens this report. */
    abstract public function module(): string;

    /** @return list<Filter> */
    abstract public function filters(): array;

    /** @return list<Column> */
    abstract public function columns(): array;

    /**
     * The filtered rows: an Eloquent query (sorted and paginated in SQL) or a
     * collection already aggregated in PHP (sorted and paginated in memory).
     */
    abstract public function rows(ReportFilters $filters): Builder|Collection;

    /**
     * One record as column key => value. For a Collection source the record
     * is already such an array and is returned unchanged by default.
     *
     * @return array<string, mixed>
     */
    public function row(mixed $record): array
    {
        return (array) $record;
    }

    public function id(): string
    {
        return $this->category().'.'.$this->key();
    }

    /** i18n key of the title. */
    public function title(): string
    {
        return 'reports.'.$this->id().'.title';
    }

    /** i18n key of the one-line description. */
    public function description(): string
    {
        return 'reports.'.$this->id().'.description';
    }

    /** Card icon (Icon.vue name). */
    public function icon(): string
    {
        return 'chart';
    }

    /**
     * Sortable columns: column key => SQL expression for a Builder source,
     * column key => the same key for a Collection source.
     *
     * @return array<string, string>
     */
    public function sortable(): array
    {
        return [];
    }

    /** @return array{0: ?string, 1: string} column key and direction */
    public function defaultSort(): array
    {
        return [null, 'asc'];
    }

    /** @return list<array{label: string, value: mixed, format: string}> */
    public function summary(ReportFilters $filters): array
    {
        return [];
    }

    /** @return list<array{type: string, title: string, stats: list<array{key: int|string, label: string, count: float|int}>, unit?: ?string}> */
    public function charts(ReportFilters $filters): array
    {
        return [];
    }

    /**
     * The totals row over every filtered row (not just the page), keyed by
     * column. Empty when the report has no totals.
     *
     * @return array<string, mixed>
     */
    public function totals(ReportFilters $filters): array
    {
        return [];
    }

    public function landscape(): bool
    {
        return count($this->columns()) > 7;
    }

    public function allows(User $user): bool
    {
        return $user->hasPermission($this->module(), 'view');
    }
}
