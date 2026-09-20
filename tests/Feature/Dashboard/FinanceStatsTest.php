<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\FinanceStats;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function finance(array $query = []): array
    {
        return app(FinanceStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query)),
        );
    }

    /** @return array<string, array<string, mixed>> summary tiles keyed by key */
    private function summary(array $query = []): array
    {
        return collect($this->finance($query)['summary'])->keyBy('key')->all();
    }

    private function category(string $type, string $name): FinanceCategory
    {
        return FinanceCategory::create(['type' => $type, 'name' => $name]);
    }

    private function account(array $attributes = []): FinanceAccount
    {
        static $n = 0;
        $n++;

        return FinanceAccount::create(array_merge([
            'name' => "Account {$n}",
            'type' => 'cash',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function transaction(string $type, float $amount, string $date, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'amount' => $amount,
            'transaction_date' => $date,
            'transaction_type' => $type,
            'category' => $type === 'income' ? 'donation' : 'supplies',
            'status' => 'Paid',
            'fiscal_year' => (int) substr($date, 0, 4),
        ], $attributes));
    }

    #[Test]
    public function average_transaction_size_covers_the_window_only(): void
    {
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('expense', 500, '2026-05-03');
        $this->transaction('income', 9999, '2026-01-02'); // outside the month

        $this->assertSame(750.0, $this->summary()['avg_transaction']['value']);
    }

    #[Test]
    public function archived_transactions_are_excluded_from_the_average(): void
    {
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('income', 5000, '2026-05-02', ['archived' => true]);

        $this->assertSame(1000.0, $this->summary()['avg_transaction']['value']);
    }

    #[Test]
    public function the_largest_expense_reports_its_amount_label_and_date(): void
    {
        $this->transaction('expense', 300, '2026-05-02', ['description' => 'Balls']);
        $this->transaction('expense', 1200, '2026-05-06', ['description' => 'Bus hire']);
        $this->transaction('income', 9000, '2026-05-07', ['description' => 'Grant']);

        $tile = $this->summary()['largest_expense'];

        $this->assertSame(1200.0, $tile['value']);
        $this->assertSame('Bus hire', $tile['meta']['label']);
        $this->assertSame('2026-05-06', $tile['meta']['date']);
    }

    #[Test]
    public function burn_rate_is_the_mean_monthly_expense_not_the_total(): void
    {
        // The quarter containing 2026-05-14 is April to June: three months,
        // 3 000 spent, so 1 000 a month. March deliberately sits outside it.
        $this->transaction('expense', 1000, '2026-04-05');
        $this->transaction('expense', 1000, '2026-05-05');
        $this->transaction('expense', 1000, '2026-06-05');
        $this->transaction('expense', 5000, '2026-03-05');

        $this->assertSame(1000.0, $this->summary(['range' => 'quarter'])['burn_rate']['value']);
    }

    #[Test]
    public function runway_is_the_clubs_cash_divided_by_burn_rate(): void
    {
        // Balances are derived, never set: TransactionObserver recomputes every
        // account from opening_balance plus its ledger on each save. So the
        // fixture sets an opening balance and lets the expense reduce it.
        $account = $this->account(['opening_balance' => 7000]);
        $this->transaction('expense', 1000, '2026-05-05', ['finance_account_id' => $account->id]);

        $this->assertSame(6.0, $this->summary()['runway']['value']);
    }

    #[Test]
    public function the_clubs_cash_counts_a_treasurys_children_once(): void
    {
        // A treasury's current_balance already rolls up its children. Summing
        // every account would report this branch's 3 000 as 6 000.
        $treasury = $this->account(['name' => 'Branch treasury', 'is_treasury' => true, 'opening_balance' => 1000]);
        $child = $this->account(['name' => 'Branch register', 'parent_account_id' => $treasury->id, 'opening_balance' => 2000]);

        $this->transaction('expense', 1000, '2026-05-05', ['finance_account_id' => $child->id]);

        // Treasury rolls up to 1 000 + (2 000 − 1 000) = 2 000. Burn is 1 000.
        $this->assertSame(2.0, $this->summary()['runway']['value']);
    }

    #[Test]
    public function runway_is_null_when_nothing_is_being_spent(): void
    {
        $this->account(['opening_balance' => 6000]);

        $this->assertNull($this->summary()['runway']['value']);
    }

    #[Test]
    public function expenses_split_by_finance_category_largest_first(): void
    {
        $travel = $this->category('expense', 'Travel');
        $kit = $this->category('expense', 'Kit');

        $this->transaction('expense', 400, '2026-05-02', ['finance_category_id' => $travel->id]);
        $this->transaction('expense', 100, '2026-05-03', ['finance_category_id' => $travel->id]);
        $this->transaction('expense', 200, '2026-05-04', ['finance_category_id' => $kit->id]);

        $rows = $this->finance()['expenseByCategory'];

        $this->assertSame('Travel', $rows[0]['name']);
        $this->assertSame(500.0, $rows[0]['amount']);
        $this->assertSame('Kit', $rows[1]['name']);
        $this->assertSame(200.0, $rows[1]['amount']);
    }

    #[Test]
    public function income_splits_by_category_too(): void
    {
        // A name the migration's chart of accounts does not already seed —
        // finance_categories is unique on (type, name).
        $bakeSale = $this->category('income', 'Bake sale');
        $this->transaction('income', 900, '2026-05-02', ['finance_category_id' => $bakeSale->id]);

        $rows = $this->finance()['incomeByCategory'];

        $this->assertSame('Bake sale', $rows[0]['name']);
        $this->assertSame(900.0, $rows[0]['amount']);
        $this->assertSame(100.0, $rows[0]['share']);
    }

    #[Test]
    public function transactions_without_a_category_are_grouped_not_dropped(): void
    {
        $this->transaction('expense', 250, '2026-05-02');

        $rows = $this->finance()['expenseByCategory'];

        $this->assertCount(1, $rows);
        $this->assertSame(250.0, $rows[0]['amount']);
    }

    #[Test]
    public function the_tail_folds_into_other_so_the_chart_never_grows_a_ninth_colour(): void
    {
        foreach (range(1, 12) as $i) {
            $category = $this->category('expense', "Category {$i}");
            $this->transaction('expense', 100 * $i, '2026-05-02', ['finance_category_id' => $category->id]);
        }

        $rows = $this->finance()['expenseByCategory'];

        $this->assertCount(9, $rows);
        $this->assertSame('Other', $rows[8]['name']);
        // Categories 1 to 4 are the four smallest: 100 + 200 + 300 + 400.
        $this->assertSame(1000.0, $rows[8]['amount']);
    }

    #[Test]
    public function transfers_are_reported_but_never_counted_as_income_or_expense(): void
    {
        $from = $this->account(['current_balance' => 5000]);
        $to = $this->account(['current_balance' => 1000]);

        FinanceTransfer::create([
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 750,
            'transfer_date' => '2026-05-04',
        ]);

        $finance = $this->finance();

        $this->assertSame(1, $finance['transfers']['count']);
        $this->assertSame(750.0, $finance['transfers']['total']);
        // A transfer moves money; it is not a category of spending or earning.
        $this->assertSame([], $finance['expenseByCategory']);
        $this->assertSame([], $finance['incomeByCategory']);
        $this->assertNull($this->summary()['avg_transaction']['value']);
    }

    #[Test]
    public function accounts_report_their_opening_and_current_balances(): void
    {
        $this->account(['name' => 'Main', 'opening_balance' => 1000, 'is_treasury' => true]);

        // current_balance is only written when the ledger is recomputed, which
        // in the app happens on every transaction save.
        app(FinanceService::class)->recomputeAccountBalances();

        // The migrations seed a chart of accounts, so the list is never bare.
        $main = collect($this->finance()['accounts'])->firstWhere('name', 'Main');

        $this->assertNotNull($main);
        $this->assertSame(1000.0, $main['opening']);
        $this->assertSame(1000.0, $main['current']);
        $this->assertTrue($main['is_treasury']);
    }

    #[Test]
    public function inactive_accounts_are_left_out(): void
    {
        $this->account(['name' => 'Closed', 'is_active' => false]);

        $this->assertNull(collect($this->finance()['accounts'])->firstWhere('name', 'Closed'));
    }

    #[Test]
    public function the_branch_filter_isolates_accounts_and_transactions(): void
    {
        $north = Branch::create(['name' => 'North']);
        $south = Branch::create(['name' => 'South']);

        $northAccount = $this->account(['name' => 'North cash', 'branch_id' => $north->id, 'current_balance' => 800]);
        $this->account(['name' => 'South cash', 'branch_id' => $south->id, 'current_balance' => 200]);

        $this->transaction('expense', 300, '2026-05-02', ['finance_account_id' => $northAccount->id]);

        $northOnly = $this->finance(['branch' => (string) $north->id]);
        $southOnly = $this->finance(['branch' => (string) $south->id]);

        $this->assertContains('North cash', collect($northOnly['accounts'])->pluck('name')->all());
        $this->assertNotContains('South cash', collect($northOnly['accounts'])->pluck('name')->all());
        $this->assertSame(300.0, (float) collect($northOnly['expenseByCategory'])->sum('amount'));
        $this->assertSame(0.0, (float) collect($southOnly['expenseByCategory'])->sum('amount'));
    }

    #[Test]
    public function an_empty_database_returns_empty_structures(): void
    {
        $finance = $this->finance();

        $this->assertSame([], $finance['expenseByCategory']);
        $this->assertSame([], $finance['incomeByCategory']);
        $this->assertSame(0, $finance['transfers']['count']);
        $this->assertSame(0.0, $finance['transfers']['total']);
        $this->assertNull($this->summary()['avg_transaction']['value']);
        $this->assertNull($this->summary()['runway']['value']);
    }
}
