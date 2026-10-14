<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\Finance\SeasonRebucketer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonRebucketerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function calendar_booked_rows_move_to_the_season_of_their_date(): void
    {
        // Simulate legacy calendar data, bypassing the observer. The migrations
        // already seed a row for the current calendar year, so reset it to
        // calendar dates rather than inserting a duplicate.
        $cal2026 = FiscalYear::updateOrCreate(['year' => 2026], ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open', 'opening_balance' => 0]);
        $cal2027 = FiscalYear::updateOrCreate(['year' => 2027], ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open', 'opening_balance' => 0]);
        $row = fn (string $date, string $type, int $amount, FiscalYear $fy) => DB::table('transactions')->insertGetId([
            'amount' => $amount, 'transaction_date' => $date, 'transaction_type' => $type, 'category' => 'donation',
            'payment_method' => 'cash', 'status' => 'Paid', 'archived' => false,
            'fiscal_year' => $fy->year, 'fiscal_year_id' => $fy->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $donation = $row('2026-10-06', 'income', 1000, $cal2026);   // calendar 2026 → season 2027
        $payment = $row('2026-10-06', 'income', 2000, $cal2027);    // subscription-year 2027 → stays 2027
        $expense = $row('2026-10-09', 'expense', 12000, $cal2026);  // → 2027
        $spring = $row('2026-03-01', 'income', 500, $cal2026);      // → 2026

        $result = app(SeasonRebucketer::class)->run();

        $this->assertSame(2, $result['moved']);
        $this->assertSame(2027, Transaction::find($donation)->fiscal_year);
        $this->assertSame($cal2027->id, Transaction::find($expense)->fiscal_year_id);
        $this->assertSame(2026, Transaction::find($spring)->fiscal_year);

        $s2027 = $cal2027->fresh();
        $this->assertSame('2026-09-01', $s2027->start_date->toDateString());
        $this->assertSame('2027-08-31', $s2027->end_date->toDateString());
        $this->assertEquals(3000, (float) $s2027->total_income);
        $this->assertEquals(12000, (float) $s2027->total_expense);
        $this->assertEquals(500, (float) $cal2026->fresh()->total_income);
        $this->assertSame('2025-09-01', $cal2026->fresh()->start_date->toDateString());
    }

    #[Test]
    public function running_twice_changes_nothing_the_second_time(): void
    {
        Transaction::create(['amount' => 100, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);

        app(SeasonRebucketer::class)->run();

        $this->assertSame(0, app(SeasonRebucketer::class)->run()['moved']);
    }
}
