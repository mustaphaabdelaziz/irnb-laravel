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
    public function without_an_active_club_cash_the_default_is_blank_never_a_bank_or_the_opening_fund(): void
    {
        $this->fund();
        FinanceAccount::whereNull('branch_id')->where('name', 'Cash')->update(['is_active' => false]);
        Branch::create(['name' => 'Football']);

        $props = $this->props('transactions.create');

        // The seeded "Club Bank Account" and the coffre are listed, but the
        // server would not book a blank entry there, so neither is preselected.
        $this->assertTrue(collect($props['financeAccounts'])->contains('name', 'Club Bank Account'));
        $this->assertNull($props['defaultFinanceAccountId']);
    }

    #[Test]
    public function an_entry_whose_caisse_was_deactivated_can_still_be_saved_on_it(): void
    {
        $x = FinanceAccount::create(['name' => 'Old box', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
        $tx = Transaction::create([
            'title' => 'Entry', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation',
            'status' => 'Paid', 'transaction_date' => now(), 'finance_account_id' => $x->id,
        ]);
        $category = FinanceCategory::where('type', 'income')->where('is_active', true)->firstOrFail();
        $x->update(['is_active' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('transactions.update', $tx), $this->payload($category, ['amount' => 250, 'finance_account_id' => $x->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $tx->refresh();
        $this->assertEquals(250, $tx->amount);
        $this->assertSame($x->id, $tx->finance_account_id);
    }

    #[Test]
    public function switching_an_entry_to_another_inactive_caisse_is_still_refused(): void
    {
        $x = FinanceAccount::create(['name' => 'Old box', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD']);
        $y = FinanceAccount::create(['name' => 'Other old box', 'type' => 'cash', 'is_active' => false, 'currency' => 'DZD']);
        $tx = Transaction::create([
            'title' => 'Entry', 'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation',
            'status' => 'Paid', 'transaction_date' => now(), 'finance_account_id' => $x->id,
        ]);
        $category = FinanceCategory::where('type', 'income')->where('is_active', true)->firstOrFail();
        $x->update(['is_active' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('transactions.update', $tx), $this->payload($category, ['finance_account_id' => $y->id]))
            ->assertSessionHasErrors('finance_account_id');

        $this->assertSame($x->id, $tx->fresh()->finance_account_id);
    }

    private function payload(FinanceCategory $category, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Entry', 'amount' => 100, 'transaction_type' => 'income',
            'finance_category_id' => $category->id, 'status' => 'Paid', 'transaction_date' => now()->toDateString(),
        ], $overrides);
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
