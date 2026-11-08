<?php

namespace App\Services\LiteraryReport;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The period's activities grouped by type, as the chapters of the literary
 * report. The type list's sort order is the chapter order.
 */
final class ActivityChapters
{
    /**
     * Activities that started in the period. Chapters are ordered by type sort
     * order, then name; rows by start date, then id.
     *
     * @param  array<int|string, mixed>  $with  extra relations to eager-load
     * @return Collection<int, array{type: string, activities: Collection<int, Activity>}>
     */
    public static function inPeriod(CarbonImmutable $from, CarbonImmutable $to, array $with = []): Collection
    {
        return Activity::query()
            ->with(['type:id,name,sort_order', ...$with])
            ->withCount(['players', 'boardMembers'])
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_on')->orderBy('id')
            ->get()
            ->groupBy('activity_type_id')
            ->map(fn (Collection $group) => [
                'type' => (string) ($group->first()->type?->name ?? '—'),
                'order' => [$group->first()->type?->sort_order ?? PHP_INT_MAX, (string) $group->first()->type?->name],
                'activities' => $group->values(),
            ])
            ->sortBy('order')
            ->map(fn (array $chapter) => ['type' => $chapter['type'], 'activities' => $chapter['activities']])
            ->values();
    }
}
