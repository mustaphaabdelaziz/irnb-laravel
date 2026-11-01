<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceSeasonPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_finance_page_defaults_to_the_current_season_and_orders_months_from_september(): void
    {
        Carbon::setTestNow('2026-10-14');
        Transaction::create(['amount' => 700, 'transaction_date' => '2026-10-06', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page
                ->where('selectedYear', 2027)
                ->where('years.0.season_label', '2026/2027')
                ->where('detail.season_label', '2026/2027')
                ->where('detail.monthly.0.month', 9)
                ->where('detail.monthly.1.month', 10)
                ->where('detail.monthly.1.income', 700)
                ->where('detail.monthly.11.month', 8));
    }

    #[Test]
    public function the_season_kpis_compare_with_the_season_before(): void
    {
        Carbon::setTestNow('2026-10-14');
        // The finance migration seeds a fiscal year for the current year; start from a clean slate.
        FiscalYear::query()->delete();
        FiscalYear::create(['year' => 2026, 'status' => 'closed', 'opening_balance' => 0, 'total_income' => 1000, 'total_expense' => 400]);
        FiscalYear::create(['year' => 2027, 'status' => 'open', 'opening_balance' => 600, 'total_income' => 1500, 'total_expense' => 300]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index', ['year' => 2027]))
            ->assertInertia(fn ($page) => $page
                // Whole percents come back as ints: Inertia props round-trip through JSON.
                ->where('detail.deltas.income.percent', 50)
                ->where('detail.deltas.income.tone', 'positive')
                // Spending less is the good direction.
                ->where('detail.deltas.expense.percent', -25)
                ->where('detail.deltas.expense.tone', 'positive')
                ->where('detail.deltas.net.percent', 100)
                ->where('detail.deltas.net.tone', 'positive'));
    }

    #[Test]
    public function the_first_season_has_nothing_to_compare_with(): void
    {
        Carbon::setTestNow('2026-10-14');
        // The finance migration seeds a fiscal year for the current year; start from a clean slate.
        FiscalYear::query()->delete();
        FiscalYear::create(['year' => 2027, 'status' => 'open', 'opening_balance' => 0, 'total_income' => 1500, 'total_expense' => 300]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index', ['year' => 2027]))
            ->assertInertia(fn ($page) => $page
                ->where('detail.deltas.income', null)
                ->where('detail.deltas.expense', null)
                ->where('detail.deltas.net', null));
    }
}
