<?php

namespace App\Services\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\User;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use App\Services\LiteraryReport\Sections\AttendanceSection;
use App\Services\LiteraryReport\Sections\BoardSection;
use App\Services\LiteraryReport\Sections\FinanceSection;
use App\Services\LiteraryReport\Sections\MembersSection;
use App\Services\LiteraryReport\Sections\PhotosSection;
use App\Services\LiteraryReport\Sections\SportsSection;
use Carbon\CarbonImmutable;

final class LiteraryReportBuilder
{
    /** The module whose view right each data section needs; other sections need none. */
    public const MODULES = [
        'members' => 'players',
        'board' => 'board',
        'attendance' => 'attendance',
        'sports' => 'games',
        'activities' => 'activities',
        'finance' => 'finance',
        'photos' => 'activities',
    ];

    /** Computed sections by key. */
    public const DATA_SECTIONS = [
        'members' => MembersSection::class,
        'board' => BoardSection::class,
        'attendance' => AttendanceSection::class,
        'sports' => SportsSection::class,
        'activities' => ActivitiesSection::class,
        'finance' => FinanceSection::class,
        'photos' => PhotosSection::class,
    ];

    /** Sections left out when they have nothing to print; the other computed ones always print. */
    private const SKIPPED_WHEN_EMPTY = ['intro', 'conclusion', 'photos', 'signatures'];

    public static function allows(string $key, User $user): bool
    {
        return ! isset(self::MODULES[$key]) || $user->hasPermission(self::MODULES[$key], 'view');
    }

    /** @return list<array{key: string, data: array<string, mixed>}> enabled, permitted sections in print order */
    public function build(LiteraryReport $report, User $user): array
    {
        $from = CarbonImmutable::instance($report->period_from)->startOfDay();
        $to = CarbonImmutable::instance($report->period_to)->startOfDay();

        $sections = [];
        foreach ($report->enabledSections() as $key) {
            if (! self::allows($key, $user)) {
                continue;
            }

            $data = match ($key) {
                'cover' => [],
                'intro' => self::texts(['president_word' => $report->president_word, 'introduction' => $report->introduction]),
                'conclusion' => self::texts(['conclusion' => $report->conclusion]),
                'activities' => app(ActivitiesSection::class)->build($from, $to) + [
                    'note' => trim((string) $report->note),
                    'include_details' => (bool) $report->include_details,
                ],
                default => isset(self::DATA_SECTIONS[$key]) ? app(self::DATA_SECTIONS[$key])->build($from, $to) : null,
            };

            // Editorial chapters, the appendix and the signatures are left out when empty; computed chapters always print.
            if ($data === null || (in_array($key, self::SKIPPED_WHEN_EMPTY, true) && $data === [])) {
                continue;
            }

            $sections[] = ['key' => $key, 'data' => $data];
        }

        return $sections;
    }

    /** @param array<string, ?string> $texts @return array<string, string> the non-blank texts */
    private static function texts(array $texts): array
    {
        return array_filter($texts, fn (?string $text) => trim((string) $text) !== '');
    }
}
