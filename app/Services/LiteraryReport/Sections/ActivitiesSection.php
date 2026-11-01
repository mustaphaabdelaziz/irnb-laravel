<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Support\Media;
use Carbon\CarbonImmutable;

/** Social activities that started in the period: a summary by type, then one block per activity. */
final class ActivitiesSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $activities = Activity::query()
            ->with([
                'type:id,name',
                'photos' => fn ($q) => $q->where('in_report', true),
            ])
            ->withCount(['players', 'boardMembers'])
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_on')->orderBy('id')
            ->get();

        $byType = $activities->groupBy(fn (Activity $a) => $a->type?->name ?? '—')
            ->map(fn ($group, $type) => [
                'type' => (string) $type,
                'count' => $group->count(),
                'beneficiaries' => (int) $group->sum('beneficiaries_count'),
                'participants' => (int) $group->sum('participants_count'),
            ])
            ->sortBy('type')->values()->all();

        return [
            'count' => $activities->count(),
            'by_type' => $byType,
            'totals' => [
                'count' => $activities->count(),
                'beneficiaries' => (int) $activities->sum('beneficiaries_count'),
                'participants' => (int) $activities->sum('participants_count'),
            ],
            'activities' => $activities->map(fn (Activity $a) => [
                'title' => $a->title,
                'type' => $a->type?->name,
                'starts_on' => $a->starts_on?->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'location' => $a->location,
                'partners' => $a->partners,
                'beneficiaries' => (int) $a->beneficiaries_count,
                'participants' => (int) $a->participants_count,
                'report' => $a->report,
                'photos' => $a->photos
                    ->take(ActivityPhoto::REPORT_LIMIT)
                    ->map(fn (ActivityPhoto $p) => ['file' => Media::localFile($p->url), 'caption' => $p->caption])
                    ->filter(fn (array $p) => $p['file'] !== null)
                    ->values()->all(),
            ])->all(),
        ];
    }
}
