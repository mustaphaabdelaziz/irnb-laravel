<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_expense_page_links_its_payment_order(): void
    {
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::create(['name' => 'Equipment', 'type' => 'expense', 'is_active' => true]);
        $transaction = Transaction::create([
            'title' => 'Impression', 'amount' => 12300, 'transaction_date' => now()->format('Y-m-d'), 'transaction_type' => 'expense',
            'finance_category_id' => $category->id, 'category' => 'equipment', 'status' => 'Paid',
        ]);

        $this->actingAs($admin)->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page->where('paymentOrder', null));

        $order = app(PaymentOrderService::class)->fromExpense($transaction, $admin);

        $this->get(route('transactions.show', $transaction))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentOrder.id', $order->id)
                ->where('paymentOrder.reference', $order->reference));

        // The order page shows the expense's amount, and says when the expense was cancelled.
        $this->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.transaction.id', $transaction->id)
                ->where('order.transaction.amount', '12300.00')
                ->where('order.transaction.archived', false));
        $transaction->forceFill(['archived' => true])->save();
        $this->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.transaction.archived', true));
    }

    #[Test]
    public function the_create_page_has_its_pickers(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('payment-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Create')
                ->has('financeCategories')->has('financeAccounts')->has('suppliers')
                ->has('users')->has('boardMembers')->has('boardMeetings'));
    }

    #[Test]
    public function the_form_shows_role_labels_and_hides_register_balances(): void
    {
        BoardRole::updateOrCreate(['name' => 'media_officer'], ['label' => 'Chargé de communication', 'level' => 'member', 'sort_order' => 50]);
        $termId = BoardTerm::current()?->id;
        BoardMember::create(['board_term_id' => $termId, 'name' => 'Amine', 'role' => 'media_officer', 'status' => 'active', 'sort_order' => 1]);
        BoardMember::create(['board_term_id' => $termId, 'name' => 'Karim', 'role' => 'president', 'status' => 'active', 'sort_order' => 2]);
        FinanceAccount::create([
            'name' => 'Caisse', 'type' => 'cash', 'opening_balance' => 100, 'current_balance' => 250, 'currency' => 'DZD', 'is_active' => true,
        ]);
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);

        $this->actingAs($admin)->get(route('payment-orders.create'))
            ->assertInertia(function (Assert $page) {
                $roles = collect($page->toArray()['props']['boardMembers'])->pluck('role', 'name');
                $this->assertSame('Chargé de communication', $roles['Amine']);
                $this->assertNotSame('president', $roles['Karim']);
                foreach ($page->toArray()['props']['financeAccounts'] as $account) {
                    $this->assertArrayNotHasKey('current_balance', $account);
                    $this->assertArrayNotHasKey('opening_balance', $account);
                }
                $this->assertNotEmpty($page->toArray()['props']['financeAccounts']);
            });
    }
}
