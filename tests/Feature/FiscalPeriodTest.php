<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Subscription;
use App\Models\WebsiteConfig;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function startMonth(int $month): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => $month]]);
    }

    #[Test]
    public function a_date_belongs_to_the_season_ending_in_the_key_year(): void
    {
        $this->assertSame(2026, FiscalPeriod::keyForDate('2026-08-31'));
        $this->assertSame(2027, FiscalPeriod::keyForDate('2026-09-01'));
        $this->assertSame(2027, FiscalPeriod::keyForDate('2026-10-14'));
    }

    #[Test]
    public function a_key_knows_its_bounds_label_and_month_order(): void
    {
        $this->assertSame('2026-09-01', FiscalPeriod::start(2027)->toDateString());
        $this->assertSame('2027-08-31', FiscalPeriod::end(2027)->toDateString());
        $this->assertSame('2026/2027', FiscalPeriod::label(2027));
        $this->assertSame([9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8], FiscalPeriod::months());
        $this->assertSame('-08-31', FiscalPeriod::endSuffix());
    }

    #[Test]
    public function a_calendar_season_keys_by_the_plain_year(): void
    {
        $this->startMonth(1);

        $this->assertSame(2026, FiscalPeriod::keyForDate('2026-10-14'));
        $this->assertSame('2026-01-01', FiscalPeriod::start(2026)->toDateString());
        $this->assertSame('2026-12-31', FiscalPeriod::end(2026)->toDateString());
        $this->assertSame('2026', FiscalPeriod::label(2026));
        $this->assertSame('-12-31', FiscalPeriod::endSuffix());
    }

    #[Test]
    public function the_current_key_matches_the_subscription_default(): void
    {
        Carbon::setTestNow('2026-10-14');

        $this->assertSame(2027, FiscalPeriod::currentKey());
        $this->assertSame(2027, Subscription::currentSeasonEndYear());
        $this->assertSame('2026/2027', Subscription::seasonLabel(2027));
    }

    #[Test]
    public function a_fiscal_year_serializes_its_season_label(): void
    {
        $fy = FiscalYear::create(['year' => 2027, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'status' => 'open', 'opening_balance' => 0]);

        $this->assertSame('2026/2027', $fy->toArray()['season_label']);
    }
}
