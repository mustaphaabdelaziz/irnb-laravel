<?php

namespace Tests\Feature;

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
}
