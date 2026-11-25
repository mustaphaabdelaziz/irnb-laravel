<?php

namespace Tests\Feature;

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
}
