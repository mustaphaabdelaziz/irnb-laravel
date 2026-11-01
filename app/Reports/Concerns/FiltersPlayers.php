<?php

namespace App\Reports\Concerns;

use App\Models\Branch;
use App\Models\Category;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The category and branch filters every player report offers, and how they
 * narrow a query on `players` (or on a table joined to it by $playerColumn).
 */
trait FiltersPlayers
{
    protected function categoryFilter(): Filter
    {
        return Filter::multi('category_id', 'col.category', Category::orderBy('name')->get()
            ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->localized_name])->all());
    }

    protected function branchFilter(): Filter
    {
        return Filter::multi('branch_id', 'col.branches', Branch::orderBy('name')->get()
            ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->localized_name])->all());
    }

    /**
     * @param  string  $playerColumn  the column holding the player id ("id" on players)
     * @param  string  $categoryColumn  the column holding the category id
     */
    protected function applyCategoryBranch(Builder $query, ReportFilters $filters, string $playerColumn = 'id', string $categoryColumn = 'category_id'): Builder
    {
        if ($filters->filled('category_id')) {
            $query->whereIn($categoryColumn, $filters->get('category_id'));
        }
        if ($filters->filled('branch_id')) {
            $query->whereIn($playerColumn, DB::table('branch_player')->whereIn('branch_id', $filters->get('branch_id'))->select('player_id'));
        }

        return $query;
    }
}
