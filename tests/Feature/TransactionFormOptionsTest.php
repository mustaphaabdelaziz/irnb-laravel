<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionFormOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function props(string $route, mixed $params = []): array
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get(route($route, $params))->assertOk()->viewData('page')['props'];
    }

    private function fund(): FinanceAccount
    {
        return FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => -1]);
    }

    #[Test]
    public function a_new_entry_starts_on_the_club_cash_the_resolver_picks(): void
    {
        $this->fund();
        // Seeded by create_finance_tables.
        $cash = FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->firstOrFail();

        $props = $this->props('transactions.create');

        $this->assertSame($cash->id, $props['defaultFinanceAccountId']);
        $this->assertNull($props['lockedAccount']);
    }

    #[Test]
    public function without_a_club_cash_the_first_caisse_listed_is_the_default_never_the_opening_fund(): void
    {
        $fund = $this->fund();
        FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->update(['is_active' => false]);
        $branch = Branch::create(['name' => 'Football']);

        $props = $this->props('transactions.create');

        // The first caisse the picker lists (the seeded club bank account sorts before the coffre).
        $this->assertSame($props['financeAccounts'][0]['id'], $props['defaultFinanceAccountId']);
        $this->assertTrue(collect($props['financeAccounts'])->contains('id', $branch->treasury->id));
        $this->assertNotSame($fund->id, $props['defaultFinanceAccountId']);
    }

    #[Test]
    public function editing_an_entry_on_the_opening_fund_keeps_it_visible_and_locked(): void
    {
        $fund = $this->fund();
        $tx = Transaction::create([
            'title' => 'Avant', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation',
            'status' => 'Paid', 'transaction_date' => now(), 'finance_account_id' => $fund->id,
        ]);

        $props = $this->props('transactions.edit', $tx);

        $this->assertSame($fund->id, $props['lockedAccount']['id']);
        $this->assertTrue($props['lockedAccount']['is_opening_fund']);
        $this->assertFalse(collect($props['financeAccounts'])->contains('id', $fund->id));
    }

    #[Test]
    public function editing_keeps_the_entrys_inactive_category_selectable(): void
    {
        $old = FinanceCategory::create(['type' => 'income', 'name' => 'Old', 'is_active' => false]);
        $tx = Transaction::create([
            'title' => 'Old one', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'old',
            'finance_category_id' => $old->id, 'status' => 'Paid', 'transaction_date' => now(),
        ]);

        $this->assertTrue(collect($this->props('transactions.edit', $tx)['financeCategories'])->contains('id', $old->id));
        $this->assertFalse(collect($this->props('transactions.create')['financeCategories'])->contains('id', $old->id));
    }
}
