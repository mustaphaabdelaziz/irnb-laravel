<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The sidebar's copy of which modules open each Reports category must match the registry. */
class ReportCategoriesMirrorTest extends TestCase
{
    #[Test]
    public function the_sidebar_mirror_matches_the_registry(): void
    {
        $source = (string) file_get_contents(resource_path('js/lib/reportCategories.js'));
        $this->assertSame(1, preg_match('/REPORT_CATEGORY_MODULES = (\{.*?\});/s', $source, $m));

        $this->assertSame(app(ReportRegistry::class)->categoryModules(), json_decode($m[1], true));
        $this->assertSame(ReportRegistry::CATEGORIES, array_keys(json_decode($m[1], true)));
    }
}
