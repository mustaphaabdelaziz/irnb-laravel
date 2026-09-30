<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The follow-up pages are reachable from the pages people already use, and
 * never through a global called from a template.
 */
class AttendanceFollowupLinksTest extends TestCase
{
    public static function pageLinks(): array
    {
        return [
            'stats → at risk' => ['Attendance/Stats.vue', "route('attendance.alerts')"],
            'dashboard card → at risk' => ['Dashboard/Partials/AttendanceCard.vue', "route('attendance.alerts')"],
        ];
    }

    #[Test]
    #[DataProvider('pageLinks')]
    public function the_page_links_to_the_follow_up_page(string $file, string $needle): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($needle, $source);
        $this->assertStringNotContainsString('@click="window', $source);
    }
}
