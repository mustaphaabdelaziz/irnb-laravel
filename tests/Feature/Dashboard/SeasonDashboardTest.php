<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\HeroStats;
use App\Services\Dashboard\OverviewStats;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_subscription_without_due_date_is_due_at_the_end_of_its_season(): void
    {
        $sql = (new \ReflectionMethod(OverviewStats::class, 'effectiveDueDate'))->invoke(app(OverviewStats::class));
        $this->assertStringContainsString("'".FiscalPeriod::endSuffix()."'", $sql);
        $this->assertStringContainsString("'-08-31'", $sql);

        $sql = (new \ReflectionMethod(HeroStats::class, 'effectiveDueDate'))->invoke(app(HeroStats::class));
        $this->assertStringContainsString("'-08-31'", $sql);
    }
}
