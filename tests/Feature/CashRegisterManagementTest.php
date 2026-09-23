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
    public function every_branch_gets_one_treasury_and_one_register_per_category(): void
    {
        $junior = Category::create(['name' => 'Junior']);
        $senior = Category::create(['name' => 'Senior']);
        $branch = Branch::create(['name' => 'Football']);

        $treasury = FinanceAccount::where('branch_id', $branch->id)
            ->where('is_treasury', true)
            ->sole();
        $registers = FinanceAccount::where('branch_id', $branch->id)
            ->whereNotNull('category_id')
            ->get();

        $this->assertCount(2, $registers);
        $this->assertEqualsCanonicalizing([$junior->id, $senior->id], $registers->pluck('category_id')->all());
        $this->assertTrue($registers->every(fn (FinanceAccount $register) => $register->parent_account_id === $treasury->id));
    }

    #[Test]
    public function a_new_category_gets_one_register_in_every_existing_branch(): void
    {
        $swimming = Branch::create(['name' => 'Swimming']);
        $football = Branch::create(['name' => 'Football']);

        $category = Category::create(['name' => 'Under 18']);

        $registers = FinanceAccount::where('category_id', $category->id)->get();
        $this->assertCount(2, $registers);
        $this->assertEqualsCanonicalizing([$swimming->id, $football->id], $registers->pluck('branch_id')->all());
        $this->assertTrue($registers->every(fn (FinanceAccount $register) => $register->parentAccount?->is_treasury));
    }

    #[Test]
    public function treasury_rolls_up_category_registers_without_double_counting_internal_transfers(): void
    {
        $junior = Category::create(['name' => 'Junior']);
        $senior = Category::create(['name' => 'Senior']);
        $branch = Branch::create(['name' => 'Football']);
        $treasury = $branch->treasury()->sole();
        $juniorRegister = FinanceAccount::where('branch_id', $branch->id)->where('category_id', $junior->id)->sole();
        $seniorRegister = FinanceAccount::where('branch_id', $branch->id)->where('category_id', $senior->id)->sole();
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
        $register = FinanceAccount::where('branch_id', $branch->id)->where('category_id', $category->id)->sole();

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

        $register = FinanceAccount::where('branch_id', $football->id)
            ->where('category_id', $senior->id)
            ->sole();
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
}
