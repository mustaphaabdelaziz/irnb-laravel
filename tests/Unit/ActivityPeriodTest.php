<?php

namespace Tests\Unit;

use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function period(array $query = []): ActivityPeriod
    {
        return ActivityPeriod::fromRequest(Request::create('/activity', 'GET', $query));
    }

    #[Test]
    public function without_a_period_it_is_the_current_calendar_month(): void
    {
        $period = $this->period();

        $this->assertSame('month', $period->key);
        $this->assertSame('2026-10-01 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-10-31 23:59:59', $period->end->toDateTimeString());
        $this->assertSame(
            ['period' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31', 'label' => '2026-10'],
            $period->toArray(),
        );
    }

    #[Test]
    public function the_season_period_follows_the_configured_start_month(): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => 7]]);

        $period = $this->period(['period' => 'season']);

        $this->assertSame('season', $period->key);
        $this->assertSame('2026-07-01 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2027-06-30 23:59:59', $period->end->toDateTimeString());
        $this->assertSame(
            ['period' => 'season', 'from' => '2026-07-01', 'to' => '2027-06-30', 'label' => '2026/27'],
            $period->toArray(),
        );
    }

    #[Test]
    public function a_custom_range_includes_both_days(): void
    {
        $period = $this->period(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-10']);

        $this->assertSame('custom', $period->key);
        $this->assertSame('2026-03-01 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-03-10 23:59:59', $period->end->toDateTimeString());
        $this->assertSame(
            ['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-10', 'label' => '2026-03-01 – 2026-03-10'],
            $period->toArray(),
        );
    }

    #[Test]
    public function a_single_day_custom_range_is_valid(): void
    {
        $period = $this->period(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-01']);

        $this->assertSame('custom', $period->key);
        $this->assertSame('2026-03-01 23:59:59', $period->end->toDateTimeString());
    }

    #[Test]
    public function an_invalid_custom_range_falls_back_to_the_month(): void
    {
        $invalid = [
            ['period' => 'custom', 'from' => '2026-03-10', 'to' => '2026-03-01'],
            ['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'],
            ['period' => 'custom', 'from' => '01/03/2026', 'to' => '2026-03-10'],
            ['period' => 'custom', 'from' => '2026-03-01'],
            ['period' => 'custom', 'from' => ['x'], 'to' => '2026-03-10'],
            ['period' => 'custom'],
        ];

        foreach ($invalid as $query) {
            $period = $this->period($query);

            $this->assertSame('month', $period->key, json_encode($query));
            $this->assertSame('2026-10-01', $period->start->toDateString());
        }
    }

    #[Test]
    public function an_unknown_period_falls_back_to_the_month(): void
    {
        $this->assertSame('month', $this->period(['period' => 'decade'])->key);
        $this->assertSame('month', $this->period(['period' => ['season']])->key);
    }
}
