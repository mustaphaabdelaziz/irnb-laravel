<?php

namespace App\Services\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\User;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use App\Services\LiteraryReport\Sections\BoardSection;
use App\Services\LiteraryReport\Sections\MembersSection;
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
    ];

    /** Computed sections by key; Tasks 4–6 register theirs here. */
    public const DATA_SECTIONS = [
        'members' => MembersSection::class,
        'board' => BoardSection::class,
        'activities' => ActivitiesSection::class,
    ];

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
                default => isset(self::DATA_SECTIONS[$key]) ? app(self::DATA_SECTIONS[$key])->build($from, $to) : null,
            };

            // Editorial chapters with no text are left out; computed ones always print.
            if ($data === null || (in_array($key, ['intro', 'conclusion'], true) && $data === [])) {
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
