<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSheetTranslationsTest extends TestCase
{
    public const KEYS = [
        'att.sheet.month_title', 'att.sheet.session_title', 'att.sheet.month', 'att.sheet.file_no',
        'att.sheet.legend', 'att.sheet.legend_help', 'att.sheet.off_roster', 'att.sheet.blank_columns',
        'att.sheet.blank_rows', 'att.sheet.tick_help', 'att.sheet.kind_mark.preseason', 'att.sheet.kind_mark.extra',
        'att.sheet.part', 'att.sheet.page', 'att.sheet.coach_name', 'att.sheet.signature',
        'att.sheet.entered_on', 'att.sheet.entered_by', 'att.sheet.filled_note',
        'att.sheet.print', 'att.sheet.print_filled', 'att.sheet.print_session', 'att.sheet.print_hint',
    ];

    #[Test]
    public function every_sheet_label_exists_in_the_three_catalogs(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            foreach (self::KEYS as $key) {
                $this->assertNotEmpty($catalog[$key] ?? null, "{$locale}: {$key} is missing");
            }
        }
    }
}
