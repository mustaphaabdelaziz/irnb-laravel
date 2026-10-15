<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransfer;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\DefaultRegisterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashRegisterManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function a_new_branch_automatically_gets_a_cash_register(): void
    {
        $branch = Branch::create(['name' => 'Swimming']);

        $register = FinanceAccount::where('branch_id', $branch->id)->first();

        $this->assertNotNull($register);
        $this->assertSame('cash', $register->type);
        $this->assertTrue($register->is_active);
        $this->assertSame('0.00', $register->current_balance);
    }

    #[Test]
    public function a_new_branch_gets_only_its_coffre(): void
    {
        Category::create(['name' => 'Junior']);
        $branch = Branch::create(['name' => 'Football']);

        $accounts = FinanceAccount::where('branch_id', $branch->id)->get();

        $this->assertCount(1, $accounts);
        $this->assertTrue($accounts->first()->is_treasury);
        $this->assertNull($accounts->first()->category_id);
    }

    #[Test]
    public function a_new_category_creates_no_caisse(): void
    {
        Branch::create(['name' => 'Football']);
        $before = FinanceAccount::count();

        Category::create(['name' => 'Cadet']);

        $this->assertSame($before, FinanceAccount::count());
    }

    #[Test]
    public function deleting_a_category_unlinks_its_caisses_but_keeps_them_active(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Cadet']);
        $caisse = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $category->delete();

        $caisse->refresh();
        $this->assertNull($caisse->category_id);
        $this->assertTrue($caisse->is_active);
        $this->assertSame('Caisse cadets', $caisse->name);
    }

    #[Test]
    public function renaming_a_category_does_not_rename_a_hand_named_caisse(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Cadet']);
        $caisse = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse cadets', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $category->update(['name' => 'Cadets U17']);

        $this->assertSame('Caisse cadets', $caisse->fresh()->name);
    }

    #[Test]
    public function treasury_rolls_up_category_registers_without_double_counting_internal_transfers(): void
    {
        $junior = Category::create(['name' => 'Junior']);
        $senior = Category::create(['name' => 'Senior']);
        $branch = Branch::create(['name' => 'Football']);
        $treasury = $branch->treasury()->sole();
        $juniorRegister = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $junior->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse '.$junior->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $seniorRegister = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $senior->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse '.$senior->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $income = FinanceCategory::where('type', 'income')->firstOrFail();

        Transaction::create([
            'amount' => 500,
            'transaction_type' => 'income',
            'category' => 'subscriptions',
            'finance_category_id' => $income->id,
            'finance_account_id' => $juniorRegister->id,
            'status' => 'Paid',
            'transaction_date' => now(),
        ]);
        Transaction::create([
            'amount' => 300,
            'transaction_type' => 'income',
            'category' => 'subscriptions',
            'finance_category_id' => $income->id,
            'finance_account_id' => $seniorRegister->id,
            'status' => 'Paid',
            'transaction_date' => now(),
        ]);

        $this->assertEqualsWithDelta(800, (float) $treasury->fresh()->current_balance, 0.01);

        $this->actingAs($this->admin())->post(route('finance.transfers.store'), [
            'from_account_id' => $juniorRegister->id,
            'to_account_id' => $treasury->id,
            'amount' => 200,
            'transfer_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertEqualsWithDelta(300, (float) $juniorRegister->fresh()->current_balance, 0.01);
        $this->assertEqualsWithDelta(800, (float) $treasury->fresh()->current_balance, 0.01);
    }

    #[Test]
    public function a_player_payment_defaults_to_the_players_branch_category_register(): void
    {
        $category = Category::create(['name' => 'Junior']);
        $branch = Branch::create(['name' => 'Football']);
        $player = Player::create([
            'membership_id' => '202600001',
            'firstname' => 'Ada',
            'category_id' => $category->id,
            'join_year' => 2026,
        ]);
        $player->branches()->attach($branch);
        $register = FinanceAccount::create([
            'branch_id' => $branch->id, 'category_id' => $category->id, 'parent_account_id' => $branch->treasury->id,
            'name' => 'Caisse '.$category->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $this->actingAs($this->admin())->post(route('players.transactions.store', $player), [
            'category' => 'donation',
            'amount' => 250,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame($register->id, Transaction::latest('id')->value('finance_account_id'));
        $this->assertEqualsWithDelta(250, (float) $branch->treasury()->firstOrFail()->current_balance, 0.01);
    }

    #[Test]
    public function selecting_a_subscription_defaults_to_its_branch_category_register(): void
    {
        $junior = Category::create(['name' => 'Junior']);
        $senior = Category::create(['name' => 'Senior']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $football = Branch::create(['name' => 'Football']);
        $player = Player::create([
            'membership_id' => '202600002',
            'firstname' => 'Lina',
            'category_id' => $junior->id,
            'join_year' => 2026,
            'is_student' => true,
        ]);
        $player->branches()->attach($swimming);

        $subscription = Subscription::create([
            'name' => 'Senior Football',
            'year' => 2026,
            'amount_student' => 500,
            'amount_worker' => 700,
            'is_mandatory' => false,
            'is_active' => true,
        ]);
        $subscription->branches()->attach($football);
        $subscription->categories()->attach($senior);
        // Already owed (e.g. charged before moving to Junior), so still payable
        // even though it is outside the player's current category.
        PlayerSubscription::create([
            'player_id' => $player->id,
            'subscription_id' => $subscription->id,
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => false,
            'amount_owed' => 500,
            'amount_paid' => 0,
        ]);

        $register = FinanceAccount::create([
            'branch_id' => $football->id, 'category_id' => $senior->id, 'parent_account_id' => $football->treasury->id,
            'name' => 'Caisse '.$senior->name, 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('players.show', $player))
            ->assertInertia(fn ($page) => $page
                ->where('availableSubscriptions.0.default_finance_account_id', $register->id));

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'subscription_id' => $subscription->id,
            'category' => 'subscription',
            'amount' => 500,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame($register->id, Transaction::latest('id')->value('finance_account_id'));
    }

    #[Test]
    public function income_and_expense_are_applied_to_the_selected_register(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $register = $branch->financeAccounts()->firstOrFail();
        $other = FinanceAccount::create([
            'name' => 'Secondary Cash',
            'type' => 'cash',
            'opening_balance' => 50,
            'current_balance' => 50,
            'is_active' => true,
        ]);
        $income = FinanceCategory::where('type', 'income')->firstOrFail();
        $expense = FinanceCategory::where('type', 'expense')->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Sponsor gift',
            'transaction_type' => 'income',
            'finance_category_id' => $income->id,
            'finance_account_id' => $register->id,
            'amount' => 800,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Printer ink',
            'transaction_type' => 'expense',
            'finance_category_id' => $expense->id,
            'finance_account_id' => $register->id,
            'amount' => 275,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(525, (float) $register->fresh()->current_balance, 0.01);
        $this->assertEqualsWithDelta(50, (float) $other->fresh()->current_balance, 0.01);
        $this->assertSame(
            [$register->id, $register->id],
            Transaction::orderBy('id')->pluck('finance_account_id')->all(),
        );
    }

    #[Test]
    public function money_can_move_between_registers_without_changing_income_or_expense(): void
    {
        $source = FinanceAccount::create([
            'name' => 'Main Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'current_balance' => 1000,
            'is_active' => true,
        ]);
        $destination = FinanceAccount::create([
            'name' => 'Branch Cash',
            'type' => 'cash',
            'opening_balance' => 100,
            'current_balance' => 100,
            'is_active' => true,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $source->id,
            'to_account_id' => $destination->id,
            'amount' => 350,
            'transfer_date' => '2026-07-31',
            'reference' => 'TR-001',
            'notes' => 'Branch float',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(650, (float) $source->fresh()->current_balance, 0.01);
        $this->assertEqualsWithDelta(450, (float) $destination->fresh()->current_balance, 0.01);
        $this->assertDatabaseHas('finance_transfers', [
            'from_account_id' => $source->id,
            'to_account_id' => $destination->id,
            'amount' => 350,
            'reference' => 'TR-001',
            'created_by_user_id' => $admin->id,
        ]);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_transfer_cannot_exceed_the_source_balance(): void
    {
        $source = FinanceAccount::create([
            'name' => 'Small Cash',
            'type' => 'cash',
            'opening_balance' => 100,
            'current_balance' => 100,
            'is_active' => true,
        ]);
        $destination = FinanceAccount::create([
            'name' => 'Other Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())->post(route('finance.transfers.store'), [
            'from_account_id' => $source->id,
            'to_account_id' => $destination->id,
            'amount' => 101,
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, FinanceTransfer::count());
        $this->assertEqualsWithDelta(100, (float) $source->fresh()->current_balance, 0.01);
        $this->assertEqualsWithDelta(0, (float) $destination->fresh()->current_balance, 0.01);
    }

    #[Test]
    public function a_coffre_cannot_send_money_that_sits_in_its_caisses(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $coffre = $branch->treasury;
        $caisse = FinanceAccount::create(['branch_id' => $branch->id, 'parent_account_id' => $coffre->id, 'name' => 'Caisse seniors', 'type' => 'cash', 'opening_balance' => 1000, 'is_active' => true, 'currency' => 'DZD']);
        $bank = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 0, 'is_active' => true, 'currency' => 'DZD']);
        $admin = $this->admin();

        // The coffre shows 1000 (its caisse rolled up) but holds nothing itself.
        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $coffre->id,
            'to_account_id' => $bank->id,
            'amount' => 500,
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, FinanceTransfer::count());

        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $caisse->id,
            'to_account_id' => $bank->id,
            'amount' => 500,
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, FinanceTransfer::count());
        $this->assertEqualsWithDelta(500, (float) $caisse->fresh()->current_balance, 0.01);
        $this->assertEqualsWithDelta(500, (float) $bank->fresh()->current_balance, 0.01);
    }

    #[Test]
    public function money_cannot_be_moved_into_the_opening_fund(): void
    {
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);
        $source = FinanceAccount::create(['name' => 'Cash', 'type' => 'cash', 'opening_balance' => 100, 'is_active' => true, 'currency' => 'DZD']);

        $this->actingAs($this->admin())->post(route('finance.transfers.store'), [
            'from_account_id' => $source->id,
            'to_account_id' => $fund->id,
            'amount' => 50,
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasErrors(['to_account_id' => __('Money cannot be moved into the opening fund.')]);

        $this->assertSame(0, FinanceTransfer::count());
    }

    #[Test]
    public function the_default_register_is_never_the_opening_fund(): void
    {
        FinanceAccount::query()->delete();
        FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 0]);
        $cash = FinanceAccount::create(['name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 5]);

        $this->assertSame($cash->id, DefaultRegisterResolver::load()->for(null, null)?->id);
    }

    #[Test]
    public function an_orphaned_coffre_is_never_the_club_default(): void
    {
        FinanceAccount::query()->delete();
        FinanceAccount::create(['name' => 'Old Treasury', 'type' => 'cash', 'is_treasury' => true, 'branch_id' => null, 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 0]);
        $cash = FinanceAccount::create(['name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD', 'sort_order' => 5]);

        $this->assertSame($cash->id, DefaultRegisterResolver::load()->for(null, null)?->id);
    }

    #[Test]
    public function pickers_and_lists_hide_the_opening_fund(): void
    {
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);

        $this->assertFalse(FinanceAccount::selectable()->get()->contains('id', $fund->id));

        $this->actingAs($this->admin())->get(route('transactions.index'))
            ->assertInertia(fn ($page) => $page->where('financeAccounts', fn ($accounts) => collect($accounts)->doesntContain('id', $fund->id)));
        $this->actingAs($this->admin())->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page->where('accounts', fn ($accounts) => collect($accounts)->doesntContain('id', $fund->id)));
    }
}
