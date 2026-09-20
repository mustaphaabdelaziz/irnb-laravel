<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\Support\DeltaCalculator;
use App\Services\Dashboard\Support\MonthBucket;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardFiltersTest extends TestCase
{
    private function filters(array $query = []): DashboardFilters
    {
        return DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query));
    }

    #[Test]
    public function it_defaults_to_the_current_month_with_comparison_on(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $f = $this->filters();

        $this->assertSame('month', $f->range);
        $this->assertSame('2026-05-01', $f->from->toDateString());
        $this->assertSame('2026-05-31', $f->to->toDateString());
        $this->assertSame('2026-04-01', $f->prevFrom->toDateString());
        $this->assertSame('2026-04-30', $f->prevTo->toDateString());
        $this->assertTrue($f->compare);
        $this->assertSame('overview', $f->tab);
    }

    #[Test]
    public function last_month_resolves_to_the_whole_previous_month(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $f = $this->filters(['range' => 'last_month']);

        $this->assertSame('2026-04-01', $f->from->toDateString());
        $this->assertSame('2026-04-30', $f->to->toDateString());
        $this->assertSame('2026-03-01', $f->prevFrom->toDateString());
        $this->assertSame('2026-03-31', $f->prevTo->toDateString());
    }

    #[Test]
    public function quarter_and_year_resolve_to_their_whole_periods(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $quarter = $this->filters(['range' => 'quarter']);
        $this->assertSame('2026-04-01', $quarter->from->toDateString());
        $this->assertSame('2026-06-30', $quarter->to->toDateString());
        $this->assertSame('2026-01-01', $quarter->prevFrom->toDateString());
        $this->assertSame('2026-03-31', $quarter->prevTo->toDateString());

        $year = $this->filters(['range' => 'year']);
        $this->assertSame('2026-01-01', $year->from->toDateString());
        $this->assertSame('2026-12-31', $year->to->toDateString());
        $this->assertSame('2025-01-01', $year->prevFrom->toDateString());
        $this->assertSame('2025-12-31', $year->prevTo->toDateString());
    }

    #[Test]
    public function all_time_has_no_window_and_no_comparison(): void
    {
        $f = $this->filters(['range' => 'all', 'compare' => '1']);

        $this->assertTrue($f->isAllTime());
        $this->assertNull($f->from);
        $this->assertNull($f->to);
        $this->assertFalse($f->hasComparison());
    }

    #[Test]
    public function an_unknown_range_falls_back_to_month(): void
    {
        $this->assertSame('month', $this->filters(['range' => 'banana'])->range);
    }

    #[Test]
    public function an_unknown_tab_falls_back_to_overview(): void
    {
        $this->assertSame('overview', $this->filters(['tab' => 'banana'])->tab);
        $this->assertSame('finance', $this->filters(['tab' => 'finance'])->tab);
    }

    #[Test]
    public function last_twelve_months_spans_twelve_whole_months(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $f = $this->filters(['range' => 'last12']);

        $this->assertSame('2025-06-01', $f->from->toDateString());
        $this->assertSame('2026-05-31', $f->to->toDateString());
        $this->assertSame('2024-06-01', $f->prevFrom->toDateString());
        $this->assertSame('2025-05-31', $f->prevTo->toDateString());
    }

    #[Test]
    public function comparison_is_off_when_the_toggle_is_off(): void
    {
        $f = $this->filters(['compare' => '0']);

        $this->assertFalse($f->compare);
        $this->assertFalse($f->hasComparison());
    }

    #[Test]
    public function branch_is_null_when_absent_or_all(): void
    {
        $this->assertNull($this->filters()->branchId);
        $this->assertNull($this->filters(['branch' => 'all'])->branchId);
        $this->assertSame(3, $this->filters(['branch' => '3'])->branchId);
    }

    #[Test]
    public function it_round_trips_through_to_array(): void
    {
        $this->travelTo('2026-05-14 10:00:00');

        $array = $this->filters(['range' => 'quarter', 'branch' => '2', 'compare' => '0'])->toArray();

        $this->assertSame('quarter', $array['range']);
        $this->assertSame(2, $array['branch']);
        $this->assertFalse($array['compare']);
        $this->assertSame('2026-04-01', $array['from']);
        $this->assertSame('2026-06-30', $array['to']);
    }

    #[Test]
    public function month_bucket_returns_a_driver_appropriate_expression(): void
    {
        // The test suite runs on SQLite.
        $this->assertStringContainsString('strftime', MonthBucket::expression('transaction_date'));
    }

    #[Test]
    public function last_twelve_labels_end_with_the_given_month(): void
    {
        $labels = MonthBucket::lastTwelve(CarbonImmutable::parse('2026-05-14'));

        $this->assertCount(12, $labels);
        $this->assertSame('2025-06', $labels[0]);
        $this->assertSame('2026-05', $labels[11]);
    }

    #[Test]
    public function delta_tone_follows_meaning_not_sign(): void
    {
        $up = DeltaCalculator::compute(120, 100, 'up_good');
        $this->assertSame(20.0, $up['percent']);
        $this->assertSame('positive', $up['tone']);

        $debtFell = DeltaCalculator::compute(80, 100, 'down_good');
        $this->assertSame(-20.0, $debtFell['percent']);
        $this->assertSame('positive', $debtFell['tone']);

        $debtRose = DeltaCalculator::compute(120, 100, 'down_good');
        $this->assertSame('negative', $debtRose['tone']);
    }

    #[Test]
    public function delta_is_suppressed_when_there_is_no_previous_value(): void
    {
        $this->assertNull(DeltaCalculator::compute(120, null, 'up_good'));
        $this->assertNull(DeltaCalculator::compute(120, 0.0, 'up_good'));
    }

    #[Test]
    public function an_unchanged_value_is_neutral(): void
    {
        $this->assertSame('neutral', DeltaCalculator::compute(100, 100, 'up_good')['tone']);
    }
}
