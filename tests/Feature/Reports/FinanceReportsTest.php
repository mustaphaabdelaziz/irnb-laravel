<?php

namespace Tests\Feature\Reports;

use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\BudgetVsActual;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** R2 finance reports, each reconciled with the screen or service it must agree with. */
class FinanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    private function report(string $key, array $query = []): array
    {
        $props = [];
        $this->actingAs($this->admin())->get(route('reports.view', ['finance', $key, ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function tiles(array $props): array
    {
        return collect($props['summary'])->pluck('value', 'label')->all();
    }

    private function account(string $name, float $opening = 0): FinanceAccount
    {
        return FinanceAccount::create(['name' => $name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD', 'opening_balance' => $opening]);
    }

    private function entry(string $type, float $amount, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'title' => ucfirst($type).' '.$amount, 'amount' => $amount, 'transaction_date' => today()->toDateString(),
            'transaction_type' => $type, 'category' => $type === 'income' ? 'donation' : 'other', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    #[Test]
    public function the_journal_lists_counted_entries_and_splits_income_from_expenses(): void
    {
        $this->entry('income', 100);
        $this->entry('expense', 40);
        $this->entry('income', 999, ['archived' => true]);

        $props = $this->report('journal');
        $this->assertEquals(2, $props['rows']['total']);
        $this->assertEquals(['income' => 100, 'expense' => 40], $props['totals']);
        $this->assertEquals(1, $this->report('journal', ['type' => 'expense'])['rows']['total']);
    }

    #[Test]
    public function the_cashbook_closes_on_the_balance_the_finance_service_computes(): void
    {
        $caisse = $this->account('Caisse A', 1000);
        $other = $this->account('Caisse B');
        $start = FiscalPeriod::start(FiscalPeriod::currentKey());

        // Before the statement window: rolls into the opening balance.
        $this->entry('income', 200, ['finance_account_id' => $caisse->id, 'transaction_date' => $start->subDays(5)->toDateString()]);
        // Inside it.
        $this->entry('income', 300, ['finance_account_id' => $caisse->id]);
        $this->entry('expense', 50, ['finance_account_id' => $caisse->id]);
        $this->entry('income', 999, ['finance_account_id' => $caisse->id, 'archived' => true]);
        FinanceTransfer::create(['from_account_id' => $caisse->id, 'to_account_id' => $other->id, 'amount' => 100, 'transfer_date' => today()->toDateString()]);
        FinanceTransfer::create(['from_account_id' => $other->id, 'to_account_id' => $caisse->id, 'amount' => 25, 'transfer_date' => today()->toDateString()]);

        app(FinanceService::class)->recomputeAccountBalances();

        $props = $this->report('cashbook', ['account_id' => $caisse->id]);
        $tiles = $this->tiles($props);

        $this->assertEquals(1200, $tiles['reports.kpi.opening_balance']);
        $this->assertEquals(325, $tiles['reports.kpi.money_in']);
        $this->assertEquals(150, $tiles['reports.kpi.money_out']);
        $this->assertEquals((float) $caisse->fresh()->current_balance, $tiles['reports.kpi.closing_balance']);
        $this->assertEquals(1375, $tiles['reports.kpi.closing_balance']);
        $this->assertEquals(1375, collect($props['rows']['data'])->last()['balance']);
    }

    #[Test]
    public function the_budget_report_shows_the_finance_page_figures(): void
    {
        $this->entry('income', 500);
        $fy = FiscalYear::where('year', FiscalPeriod::currentKey())->firstOrFail();
        $category = Transaction::first()->financeCategory;
        Budget::create(['fiscal_year_id' => $fy->id, 'finance_category_id' => $category->id, 'planned_amount' => 800]);

        $row = collect($this->report('budget')['rows']['data'])->firstWhere('category', $category->localized_name);
        $this->assertEquals(['planned' => 800, 'actual' => 500, 'variance' => -300, 'used' => 62.5], collect($row)->only(['planned', 'actual', 'variance', 'used'])->all());

        $expected = collect(BudgetVsActual::for($fy))->firstWhere('category_id', $category->id);
        $this->assertEquals($expected['actual'], $row['actual']);

        // The Finance page reads the same service.
        $this->actingAs($this->admin())->get(route('finance.index', ['year' => $fy->year]))
            ->assertInertia(fn (Assert $page) => $page->where('detail.budget', fn ($budget) => collect($budget)->firstWhere('category_id', $category->id)['actual'] == 500));
    }

    private function obligation(Player $player, Subscription $sub, float $owed, float $paid, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create($extra + [
            'player_id' => $player->id, 'subscription_id' => $sub->id, 'year' => $sub->year ?? 2026, 'status_at_time' => 'student',
            'amount_owed' => $owed, 'amount_paid' => $paid,
        ]);
    }

    #[Test]
    public function outstanding_payments_add_up_to_the_players_total_debt(): void
    {
        $annual = Subscription::create(['name' => 'Season', 'kind' => 'annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000]);
        $shirt = Subscription::create(['name' => 'Shirt', 'kind' => 'exceptional', 'amount_student' => 300, 'amount_worker' => 300]);
        $ali = Player::create(['membership_id' => '1', 'firstname' => 'Ali', 'lastname' => 'A', 'phones' => ['0550']]);
        $sami = Player::create(['membership_id' => '2', 'firstname' => 'Sami', 'lastname' => 'S']);
        $gone = Player::create(['membership_id' => '3', 'firstname' => 'Gone', 'lastname' => 'G', 'archived' => true]);

        $this->obligation($ali, $annual, 1000, 400, ['due_date' => today()->subDays(10)->toDateString()]);
        $this->obligation($ali, $shirt, 300, 0);
        $this->obligation($sami, $annual, 1000, 1000);
        $this->obligation($sami, $shirt, 300, 0, ['discount_type' => 'percent', 'discount_value' => 50]);
        $this->obligation($gone, $annual, 1000, 0);
        $this->obligation($sami, $annual, 1000, 0, ['is_exempt' => true]);

        foreach ([$ali, $sami, $gone] as $p) {
            app(RecalculatePlayerDebtService::class)->forPlayer($p);
        }

        $props = $this->report('debtors');
        $playersDebt = (float) Player::where('archived', false)->sum('outstanding_debt');

        $this->assertEquals(3, $props['rows']['total']);
        $this->assertEquals($playersDebt, $props['totals']['remaining']);
        $this->assertEquals(1050, $props['totals']['remaining']);  // 600 + 300 + 150 (half off)
        $this->assertEquals(10, collect($props['rows']['data'])->firstWhere('remaining', 600)['days_overdue']);

        $overdue = $this->report('debtors', ['overdue' => 1]);
        $this->assertEquals([600], array_column($overdue['rows']['data'], 'remaining'));
        $this->assertEquals(1, $this->report('debtors', ['kind' => 'annual'])['rows']['total']);
    }

    #[Test]
    public function collection_counts_each_status_apart_and_keeps_exempt_lines_out_of_the_money(): void
    {
        $annual = Subscription::create(['name' => 'Season', 'kind' => 'annual', 'year' => 2026, 'amount_student' => 1000, 'amount_worker' => 1000]);
        $players = collect(range(1, 4))->map(fn ($i) => Player::create(['membership_id' => (string) $i, 'firstname' => 'P'.$i]));
        $this->obligation($players[0], $annual, 1000, 1000);
        $this->obligation($players[1], $annual, 1000, 250);
        $this->obligation($players[2], $annual, 1000, 0);
        $this->obligation($players[3], $annual, 1000, 0, ['is_exempt' => true]);

        $row = $this->report('collection')['rows']['data'][0];

        $this->assertEquals(
            ['enrolled' => 4, 'paid' => 1, 'partial' => 1, 'unpaid' => 1, 'exempt' => 1, 'billed' => 3000, 'collected' => 1250, 'remaining' => 1750, 'rate' => 41.7],
            collect($row)->only(['enrolled', 'paid', 'partial', 'unpaid', 'exempt', 'billed', 'collected', 'remaining', 'rate'])->all()
        );
    }

    #[Test]
    public function subscription_reports_need_the_subscriptions_right(): void
    {
        $user = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => ['finance' => ['view']]])->id,
        ]);

        $this->actingAs($user)->get(route('reports.view', ['finance', 'debtors']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.view', ['finance', 'cashbook']))->assertOk();
    }
}
