<?php

namespace App\Reports\Concerns;

use App\Models\Branch;
use App\Models\Player;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use App\Services\Player\RegistrationDisplay;
use App\Support\CategoryLabels;
use Illuminate\Database\Eloquent\Builder;

/**
 * The category and branch filters every player report offers, and how they
 * narrow a query on `players` (or on a table joined to it by $playerColumn).
 */
trait FiltersPlayers
{
    protected function categoryFilter(): Filter
    {
        return Filter::multi('category_id', 'col.category', CategoryLabels::make()->options());
    }

    protected function branchFilter(): Filter
    {
        return Filter::multi('branch_id', 'col.branches', Branch::orderBy('name')->get()
            ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all());
    }

    /** Narrow a query on players by the shared list rule. Other tables use their own query. */
    protected function applyCategoryBranch(Builder $query, ReportFilters $filters): Builder
    {
        return $query->listedWhere(...$this->listFilters($filters));
    }

    /** @return array{branches: ?array, categories: ?array} */
    protected function listFilters(ReportFilters $filters): array
    {
        return [
            'branches' => $filters->filled('branch_id') ? (array) $filters->get('branch_id') : null,
            'categories' => $filters->filled('category_id') ? (array) $filters->get('category_id') : null,
        ];
    }

    /** The branch whose values the rows show: exactly one selected. */
    protected function shownBranch(ReportFilters $filters): ?int
    {
        return RegistrationDisplay::singleBranch($filters->filled('branch_id') ? (array) $filters->get('branch_id') : null);
    }

    /** Whether the status filter asks for left people. */
    protected function includesLeft(ReportFilters $filters): bool
    {
        return $filters->filled('status_id') && in_array(Player::leftStatusId(), (array) $filters->get('status_id'), false);
    }

    /** The remark for per-registration category figures, when they can exceed the people total. */
    protected function overlapNote(ReportFilters $filters): ?string
    {
        return ! $filters->filled('branch_id') && Branch::count() > 1 ? 'chart_note.bars_can_exceed_total' : null;
    }
}
