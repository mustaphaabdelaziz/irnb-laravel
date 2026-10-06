<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The print buttons are plain links opening the PDF in a new tab (they work
 * in the desktop shell and never call a global from the template).
 */
class AttendanceSheetLinksTest extends TestCase
{
    public static function pages(): array
    {
        return [
            'calendar' => ['Attendance/Index.vue', "route('attendance.sheets.month'", false],
            'grid' => ['Attendance/Grid.vue', "route('attendance.sheets.month'", true],
            'session' => ['Attendance/Session.vue', "route('attendance.sheets.session'", true],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function the_page_links_to_its_sheet_in_a_new_tab(string $file, string $route, bool $withMarks): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($route, $source);
        $this->assertMatchesRegularExpression(AttendanceFollowupLinksTest::newTabLink(':href="sheetHref" target="_blank"'), $source);
        $this->assertSame($withMarks, (bool) preg_match(AttendanceFollowupLinksTest::newTabLink(':href="filledSheetHref" target="_blank"'), $source));
        $this->assertStringNotContainsString('@click="window', $source);
    }
}
