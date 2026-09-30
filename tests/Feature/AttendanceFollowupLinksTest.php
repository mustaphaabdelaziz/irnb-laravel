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
            'stats → ranking' => ['Attendance/Stats.vue', "route('attendance.ranking')"],
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

    public static function pdfLinks(): array
    {
        return [
            'profile → letter' => ['Players/Partials/AttendanceSection.vue', "route('attendance.players.letter'", ':href="letterHref" target="_blank"'],
            'at risk → letter' => ['Attendance/Alerts.vue', "route('attendance.players.letter'", ':href="letterHref(row)" target="_blank"'],
            'stats table → letter' => ['Attendance/Partials/StatsPlayerTable.vue', "route('attendance.players.letter'", ':href="letterHref(row)" target="_blank"'],
            'ranking → podium' => ['Attendance/Ranking.vue', "route('attendance.certificates'", ':href="podiumHref" target="_blank"'],
            'ranking → one certificate' => ['Attendance/Ranking.vue', "route('attendance.certificates'", ':href="certificateHref(row)" target="_blank"'],
        ];
    }

    #[Test]
    #[DataProvider('pdfLinks')]
    public function the_pdf_opens_in_a_new_tab_from_a_plain_link(string $file, string $route, string $link): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($route, $source);
        $this->assertStringContainsString($link, $source);
        $this->assertStringNotContainsString('@click="window', $source);
    }
}
