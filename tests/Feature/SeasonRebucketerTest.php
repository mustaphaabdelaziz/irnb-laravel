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
        $this->assertSame(2027, Transaction::find($payment)->fiscal_year);
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

    /** A fiscal-year row as the calendar-year code left it, bypassing the observer. */
    private function legacyYear(int $year, array $extra = []): FiscalYear
    {
        // The migrations already seed a row for the current calendar year, so reset rather than duplicate.
        return FiscalYear::updateOrCreate(['year' => $year], $extra + [
            'start_date' => "$year-01-01", 'end_date' => "$year-12-31", 'status' => 'open', 'opening_balance' => 0,
        ]);
    }

    private function legacyRow(string $date, string $type, int $amount, FiscalYear $fy, bool $archived = false): int
    {
        return DB::table('transactions')->insertGetId([
            'amount' => $amount, 'transaction_date' => $date, 'transaction_type' => $type, 'category' => 'donation',
            'payment_method' => 'cash', 'status' => 'Paid', 'archived' => $archived,
            'fiscal_year' => $fy->year, 'fiscal_year_id' => $fy->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_closed_year_whose_closing_changes_hands_the_new_figure_to_the_next_open_season(): void
    {
        // Closed when it held both rows: closing 1500, copied into 2027's opening.
        $cal2026 = $this->legacyYear(2026, ['status' => 'closed', 'closing_balance' => 1500, 'total_income' => 1500]);
        $cal2027 = $this->legacyYear(2027, ['opening_balance' => 1500]);
        $this->legacyRow('2026-10-06', 'income', 1000, $cal2026);   // moves to season 2027
        $this->legacyRow('2026-03-01', 'income', 500, $cal2026);    // stays in 2026

        app(SeasonRebucketer::class)->run();

        $this->assertEquals(500, (float) $cal2026->fresh()->closing_balance);
        $this->assertEquals(500, (float) $cal2027->fresh()->opening_balance);
        $this->assertEquals(1000, (float) $cal2027->fresh()->total_income);
    }

    #[Test]
    public function a_closed_next_season_keeps_its_opening_balance(): void
    {
        $cal2026 = $this->legacyYear(2026, ['status' => 'closed', 'closing_balance' => 1500]);
        $cal2027 = $this->legacyYear(2027, ['status' => 'closed', 'opening_balance' => 777, 'closing_balance' => 777]);
        $this->legacyRow('2026-03-01', 'income', 500, $cal2026);

        app(SeasonRebucketer::class)->run();

        $this->assertEquals(500, (float) $cal2026->fresh()->closing_balance);
        $this->assertEquals(777, (float) $cal2027->fresh()->opening_balance);
    }

    #[Test]
    public function a_transaction_whose_season_has_no_row_yet_gets_one(): void
    {
        $cal2026 = $this->legacyYear(2026);
        $this->assertNull(FiscalYear::where('year', 2028)->first());
        $id = $this->legacyRow('2028-01-15', 'income', 300, $cal2026);

        app(SeasonRebucketer::class)->run();

        $s2028 = FiscalYear::where('year', 2028)->firstOrFail();
        $this->assertSame($s2028->id, Transaction::find($id)->fiscal_year_id);
        $this->assertSame(2028, Transaction::find($id)->fiscal_year);
        $this->assertSame('2027-09-01', $s2028->start_date->toDateString());
        $this->assertSame('open', $s2028->status);
        $this->assertEquals(300, (float) $s2028->total_income);
    }

    #[Test]
    public function an_archived_transaction_is_moved_but_left_out_of_the_totals(): void
    {
        $cal2026 = $this->legacyYear(2026);
        $cal2027 = $this->legacyYear(2027);
        $archived = $this->legacyRow('2026-10-06', 'income', 900, $cal2026, archived: true);
        $this->legacyRow('2026-10-07', 'income', 100, $cal2026);

        app(SeasonRebucketer::class)->run();

        $this->assertSame(2027, Transaction::find($archived)->fiscal_year);
        $this->assertSame($cal2027->id, Transaction::find($archived)->fiscal_year_id);
        $this->assertEquals(100, (float) $cal2027->fresh()->total_income);
    }
}
