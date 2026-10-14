<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonStartMonthChangeTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $date): Transaction
    {
        return Transaction::create([
            'amount' => 1000, 'transaction_date' => $date, 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    private function saveStartMonth(int $month)
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        return $this->actingAs($admin)->from(route('settings.show'))
            ->put(route('settings.update'), ['settings' => ['seasonStartMonth' => $month]]);
    }

    #[Test]
    public function a_new_start_month_re_sorts_transactions_into_their_new_seasons(): void
    {
        $october = $this->tx('2026-10-06');
        $this->assertSame(2027, $october->fiscal_year);

        $this->saveStartMonth(1)->assertSessionHasNoErrors();

        $this->assertSame(1, Season::startMonth());
        $moved = $october->fresh();
        $this->assertSame(2026, $moved->fiscal_year);
        $season = FiscalYear::findOrFail($moved->fiscal_year_id);
        $this->assertSame(2026, $season->year);
        $this->assertSame('2026-01-01', $season->start_date->toDateString());
        $this->assertSame('2026-12-31', $season->end_date->toDateString());
        $this->assertEquals(1000, (float) $season->total_income);
    }

    #[Test]
    public function the_start_month_cannot_change_while_a_season_is_closed(): void
    {
        $october = $this->tx('2026-10-06');
        FiscalYear::where('year', 2027)->update(['status' => 'closed']);

        $this->saveStartMonth(1)->assertSessionHasErrors('settings.seasonStartMonth');

        $this->assertSame(9, (int) WebsiteConfig::singleton()->fresh()->settings['seasonStartMonth']);
        $this->assertSame(2027, $october->fresh()->fiscal_year);
    }

    #[Test]
    public function saving_the_same_start_month_with_a_closed_season_is_fine(): void
    {
        $this->tx('2026-10-06');
        FiscalYear::where('year', 2027)->update(['status' => 'closed']);

        $this->saveStartMonth(9)->assertSessionHasNoErrors();
    }
}
