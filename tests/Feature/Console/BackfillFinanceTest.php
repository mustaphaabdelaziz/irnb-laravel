<?php

namespace Tests\Feature\Console;

use App\Models\FiscalYear;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillFinanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backfill_links_transactions_to_the_season_of_their_date(): void
    {
        $tx = Transaction::create([
            'amount' => 1000, 'transaction_date' => '2026-10-06', 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
        Transaction::whereKey($tx->id)->update(['fiscal_year' => 2026, 'fiscal_year_id' => null]);

        $this->artisan('finance:backfill')->assertSuccessful();

        $this->assertSame(2027, $tx->fresh()->fiscal_year);
        $fy = FiscalYear::where('year', 2027)->firstOrFail();
        $this->assertSame($fy->id, $tx->fresh()->fiscal_year_id);
        $this->assertSame('2026-09-01', $fy->start_date->toDateString());
        $this->assertSame('2027-08-31', $fy->end_date->toDateString());
    }
}
