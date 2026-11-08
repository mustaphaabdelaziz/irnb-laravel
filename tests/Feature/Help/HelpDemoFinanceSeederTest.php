<?php

namespace Tests\Feature\Help;

use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Database\Seeders\HelpDemoFinanceSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSubscriptionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoFinanceSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-season seed day so no current-season entry is clamped to the season start.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);
    }

    #[Test]
    public function the_bank_account_exists_and_holds_the_expected_balance(): void
    {
        $bank = FinanceAccount::where('name', Demo::BANK)->firstOrFail();
        $this->assertSame('bank', $bank->type);
        $this->assertSame(1, FinanceAccount::where('type', 'bank')->count());
        $this->assertNull($bank->branch_id);
        $this->assertSame(Demo::BANK_RIB, $bank->account_number);
        $this->assertEqualsWithDelta(211000, (float) $bank->current_balance, 0.01);
    }

    #[Test]
    public function the_previous_season_is_closed_and_carries_its_balance(): void
    {
        $current = FiscalPeriod::currentKey();
        $previous = FiscalYear::where('year', $current - 1)->firstOrFail();
        $this->assertSame('closed', $previous->status);
        $this->assertEqualsWithDelta(Demo::OPENING, (float) $previous->closing_balance, 0.01);

        $now = FiscalYear::where('year', $current)->firstOrFail();
        $this->assertSame('open', $now->status);
        $this->assertEqualsWithDelta(Demo::OPENING, (float) $now->opening_balance, 0.01);
    }

    #[Test]
    public function current_season_entries_transfers_and_budget_are_seeded(): void
    {
        $key = FiscalPeriod::currentKey();
        $this->assertSame(2, Transaction::where('title', Demo::DUPLICATE)->where('archived', false)->count());
        $this->assertSame(2, FinanceTransfer::count());
        $this->assertSame(5, Budget::whereHas('fiscalYear', fn ($q) => $q->where('year', $key))->count());

        $totals = app(FinanceService::class)->yearTotals($key);
        $this->assertEqualsWithDelta(100700, (float) $totals['expense'], 0.01);
        // 255 000 of hand entries + 11 000 of subscription payments.
        $this->assertEqualsWithDelta(266000, (float) $totals['income'], 0.01);
    }

    #[Test]
    public function caisse_balances_match_the_demo_facts(): void
    {
        $cash = FinanceAccount::whereNull('branch_id')->where('type', 'cash')
            ->where('is_opening_fund', false)->orderBy('id')->firstOrFail();
        $this->assertEqualsWithDelta(31800, (float) $cash->current_balance, 0.01);

        $u14 = FinanceAccount::where('name', HelpDemoSubscriptionsSeeder::CAISSE)->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $u14->current_balance, 0.01);

        // Roll-up: 31 500 hand entries and the bank transfer + 1 000 subscription money paid in + 6 000 transferred from the caisse + the caisse itself (1 000).
        $coffre = FinanceAccount::where('is_treasury', true)->where('branch_id', $u14->branch_id)->firstOrFail();
        $this->assertEqualsWithDelta(39500, (float) $coffre->current_balance, 0.01);
    }
}
