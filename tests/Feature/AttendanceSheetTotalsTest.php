<?php

namespace Tests\Feature;

use App\Models\AttendanceCustomStatus;
use App\Services\Attendance\SheetTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSheetTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function custom(string $code, string $behaviour): string
    {
        return AttendanceCustomStatus::createWithKey([
            'code' => $code, 'color' => '#7c3aed', 'label_fr' => $code, 'behaviour' => $behaviour, 'is_active' => true,
        ])->key;
    }

    #[Test]
    public function present_late_and_left_early_count_as_present_absences_as_absent(): void
    {
        $totals = app(SheetTotals::class)->of(['present', 'late', 'left_early', 'absent_excused', 'absent_unexcused', 'not_training']);

        $this->assertSame(['present' => 3, 'absent' => 2, 'expected' => 6, 'rate' => 50.0], $totals);
    }

    #[Test]
    public function a_custom_code_counts_as_its_behaviour_and_not_counted_is_left_out(): void
    {
        $trip = $this->custom('VO', 'present');
        $off = $this->custom('NC', 'not_counted');

        $totals = app(SheetTotals::class)->of([$trip, $off, 'absent_unexcused']);

        $this->assertSame(['present' => 1, 'absent' => 1, 'expected' => 2, 'rate' => 50.0], $totals);
    }

    #[Test]
    public function nothing_marked_gives_a_null_rate(): void
    {
        $this->assertSame(['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => null], app(SheetTotals::class)->of([]));
    }

    #[Test]
    public function sum_adds_rows_and_recomputes_the_rate(): void
    {
        $sum = SheetTotals::sum([
            ['present' => 2, 'absent' => 1, 'expected' => 3, 'rate' => 66.7],
            ['present' => 1, 'absent' => 0, 'expected' => 3, 'rate' => 33.3],
        ]);

        $this->assertSame(['present' => 3, 'absent' => 1, 'expected' => 6, 'rate' => 50.0], $sum);
    }

    #[Test]
    public function the_rate_label_has_one_decimal_or_a_dash(): void
    {
        $this->assertSame('90.0%', SheetTotals::rateLabel(90.0));
        $this->assertSame('66.7%', SheetTotals::rateLabel(66.7));
        $this->assertSame('—', SheetTotals::rateLabel(null));
    }
}
