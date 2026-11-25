<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->category = FinanceCategory::where('type', 'expense')->first()
            ?? FinanceCategory::updateOrCreate(['type' => 'expense', 'name' => 'Equipment'], ['is_active' => true]);
        $this->account = FinanceAccount::selectable()->first()
            ?? FinanceAccount::create([
                'name' => 'Caisse', 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
                'currency' => 'DZD', 'is_active' => true,
            ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function payload(array $extra = []): array
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Sport Shop'], ['bank_name' => 'BNA', 'rib' => '00100000000000001234']);

        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Ballons', 'amount' => 16050,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id,
        ], $extra);
    }

    #[Test]
    public function an_accountant_creates_a_draft_and_lands_on_it(): void
    {
        $user = $this->userWith(['payment_orders' => ['view', 'add', 'edit']]);

        $response = $this->actingAs($user)->post(route('payment-orders.store'), $this->payload());

        $order = PaymentOrder::firstOrFail();
        $response->assertRedirect(route('payment-orders.show', $order))->assertSessionHas('success', 'flash.payment_order_created');
        $this->assertSame('01/2026', $order->reference);
    }

    #[Test]
    public function validation_rejects_a_beneficiary_that_does_not_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('payment-orders.store'), $this->payload(['beneficiary_type' => 'user', 'beneficiary_id' => 999999]))
            ->assertSessionHasErrors('beneficiary_id');
    }

    #[Test]
    public function approving_needs_the_approve_right(): void
    {
        $order = app(PaymentOrderService::class)->create($this->payload(), User::factory()->admin()->create());
        $editor = $this->userWith(['payment_orders' => ['view', 'add', 'edit']]);
        $approver = $this->userWith(['payment_orders' => ['view', 'approve']]);

        $this->actingAs($editor)->post(route('payment-orders.approve', $order))->assertForbidden();
        $this->actingAs($approver)->post(route('payment-orders.approve', $order))->assertSessionHas('success', 'flash.payment_order_approved');
        $this->assertSame(PaymentOrder::APPROVED, $order->fresh()->status);
    }

    #[Test]
    public function paying_creates_the_expense_and_a_refusal_comes_back_as_a_flash(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PaymentOrderService::class);
        $order = $service->approve($service->create($this->payload(), $admin), $admin);

        $this->actingAs($admin)->post(route('payment-orders.pay', $order), ['payment_date' => '2026-11-26'])
            ->assertSessionHas('success', 'flash.payment_order_paid');
        $this->assertNotNull($order->fresh()->transaction_id);

        $this->post(route('payment-orders.pay', $order))->assertSessionHas('error', 'flash.payment_order_not_approved');
        $this->assertSame(1, Transaction::count());
    }

    #[Test]
    public function cancelling_needs_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $order = app(PaymentOrderService::class)->create($this->payload(), $admin);

        $this->actingAs($admin)->post(route('payment-orders.cancel', $order), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('payment-orders.cancel', $order), ['reason' => 'Doublon'])->assertSessionHas('success', 'flash.payment_order_cancelled');
    }

    #[Test]
    public function the_register_lists_and_filters_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PaymentOrderService::class);
        $service->create($this->payload(['subject' => 'Ballons']), $admin);
        $service->approve($service->create($this->payload(['subject' => 'Maillots']), $admin), $admin);

        $this->actingAs($admin)->get(route('payment-orders.index', ['status' => 'approved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.subject', 'Maillots')
                ->where('orders.data.0.reference', '02/2026')
                ->has('years'));
    }

    #[Test]
    public function the_show_page_tells_what_the_viewer_may_do(): void
    {
        $order = app(PaymentOrderService::class)->create($this->payload(), User::factory()->admin()->create());
        $viewer = $this->userWith(['payment_orders' => ['view']]);

        $this->actingAs($viewer)->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Show')
                ->where('order.reference', '01/2026')
                ->where('order.beneficiary_label', 'Sport Shop')
                ->where('prints', ['payment', 'transfer']));
    }

    #[Test]
    public function the_approve_button_waits_for_the_category_and_register(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PaymentOrderService::class);
        $ready = $service->create($this->payload(), $admin);
        $missing = $service->create($this->payload(['finance_account_id' => null]), $admin);

        $this->actingAs($admin)->get(route('payment-orders.show', $ready))
            ->assertInertia(fn (Assert $page) => $page->where('can.approve', true));
        $this->get(route('payment-orders.show', $missing))
            ->assertInertia(fn (Assert $page) => $page->where('can.approve', false));
        $this->post(route('payment-orders.approve', $missing))->assertSessionHas('error', 'flash.payment_order_missing_finance');
    }

    #[Test]
    public function the_show_page_does_not_leak_account_balances_to_a_view_only_user(): void
    {
        $order = app(PaymentOrderService::class)->create($this->payload(), User::factory()->admin()->create());
        $viewer = $this->userWith(['payment_orders' => ['view']]);

        $this->actingAs($viewer)->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Show')
                ->has('order.finance_account.name')
                ->missing('order.finance_account.current_balance')
                ->missing('order.finance_account.opening_balance'));
    }

    #[Test]
    public function an_expense_gets_its_order_from_the_expense_page(): void
    {
        $admin = User::factory()->admin()->create();
        $transaction = Transaction::create([
            'title' => 'Impression', 'amount' => 12300, 'transaction_date' => '2026-10-12', 'transaction_type' => 'expense',
            'finance_category_id' => $this->category->id, 'category' => 'equipment', 'status' => 'Paid',
        ]);

        $this->actingAs($admin)->post(route('transactions.payment-order.store', $transaction))
            ->assertRedirect(route('payment-orders.show', PaymentOrder::firstOrFail()));
    }

    #[Test]
    public function there_is_no_delete_route(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('payment-orders.destroy'));
    }
}
