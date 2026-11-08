<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Models\Category;
use App\Services\LiteraryReport\ActivityChapters;
use App\Support\HumanDate;
use App\Support\UiLang;
use Carbon\CarbonImmutable;

/**
 * Activities that started in the period, laid out as in the club's own report:
 * one numbered table per activity type (No / activity / place / date). Also
 * returns the counts per chapter and the report texts, for the optional details.
 */
final class ActivitiesSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $locale = app()->getLocale();
        $chapters = ActivityChapters::inPeriod($from, $to, ['categories']);
        $all = $chapters->flatMap(fn (array $chapter) => $chapter['activities']);

        return [
            'count' => $all->count(),
            'chapters' => $chapters->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'rows' => $chapter['activities']->values()->map(fn (Activity $a, int $i) => [
                    'number' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'title' => $a->title,
                    'details' => self::details($a, $locale),
                    'place' => $a->location,
                    'date' => filled($a->date_label) ? trim($a->date_label) : HumanDate::range($a->starts_on, $a->ends_on, $locale),
                    'report' => filled($a->report) ? $a->report : null,
                ])->all(),
            ])->all(),
            'summary' => $chapters->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'count' => $chapter['activities']->count(),
                'beneficiaries' => (int) $chapter['activities']->sum('beneficiaries_count'),
                'participants' => (int) $chapter['activities']->sum('participants_count'),
            ])->all(),
            'totals' => [
                'count' => $all->count(),
                'beneficiaries' => (int) $all->sum('beneficiaries_count'),
                'participants' => (int) $all->sum('participants_count'),
            ],
        ];
    }

    /** «من تنظيم …» · result · categories · «بالتنسيق مع …», whichever are filled. */
    private static function details(Activity $a, string $locale): string
    {
        $fill = fn (string $key, string $value) => str_replace('{name}', trim($value), UiLang::get($key));
        $categories = $a->categories
            ->map(fn (Category $c) => $c->localized_name ?: $c->name)
            ->filter()
            ->implode($locale === 'ar' ? '، ' : ', ');

        return collect([
            filled($a->organizer) ? $fill('literary.pdf.organized_by', $a->organizer) : null,
            filled($a->result) ? trim($a->result) : null,
            $categories !== '' ? $categories : null,
            filled($a->partners) ? $fill('literary.pdf.in_partnership', $a->partners) : null,
        ])->filter()->implode(' · ');
    }
}
