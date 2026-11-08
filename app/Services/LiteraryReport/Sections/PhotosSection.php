<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Services\LiteraryReport\ActivityChapters;
use App\Support\Media;
use Carbon\CarbonImmutable;

/**
 * The photo appendix: per chapter, one photo per activity, as in the club's
 * own report. Empty when no photo prints, so the builder leaves it out.
 */
final class PhotosSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $chapters = ActivityChapters::inPeriod($from, $to, ['photos'])
            ->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'photos' => $chapter['activities']->map(fn (Activity $a) => self::photo($a))->filter()->values()->all(),
            ])
            ->filter(fn (array $chapter) => $chapter['photos'] !== [])
            ->values();

        if ($chapters->isEmpty()) {
            return [];
        }

        return [
            'chapters' => $chapters->map(fn (array $chapter, int $i) => [
                'number' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            ] + $chapter)->all(),
        ];
    }

    /** The activity's report photo, else its first photo. Null when it has none on disk. */
    private static function photo(Activity $a): ?array
    {
        $photo = $a->photos->firstWhere('in_report', true) ?? $a->photos->first();
        $file = $photo ? Media::localFile($photo->url) : null;

        return $file === null ? null : ['file' => $file, 'caption' => filled($photo->caption) ? $photo->caption : $a->title];
    }
}
