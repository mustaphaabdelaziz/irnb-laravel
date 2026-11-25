<?php

namespace Tests\Feature;

use App\Exceptions\PaymentOrderException;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentOrderService $service;

    private User $user;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->service = app(PaymentOrderService::class);
        $this->user = User::factory()->admin()->create();
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

    private function data(array $extra = []): array
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Sport Shop'], ['bank_name' => 'BNA', 'rib' => '00100000000000001234']);

        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Ballons U15', 'amount' => 16050,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id,
        ], $extra);
    }

    #[Test]
    public function create_numbers_the_order_as_a_draft(): void
    {
        $first = $this->service->create($this->data(), $this->user);
        $second = $this->service->create($this->data(), $this->user);

        $this->assertSame(PaymentOrder::DRAFT, $first->status);
        $this->assertSame('01/2026', $first->reference);
        $this->assertSame('02/2026', $second->reference);
        $this->assertSame($this->user->id, $first->created_by);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PAYMENT_ORDER_CREATED, 'subject_id' => $first->id]);
    }

    #[Test]
    public function the_year_comes_from_the_order_date(): void
    {
        $order = $this->service->create($this->data(['order_date' => '2025-12-30']), $this->user);

        $this->assertSame('01/2025', $order->reference);
    }

    #[Test]
    public function a_draft_can_be_edited_but_not_moved_to_another_year(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $this->service->update($order, $this->data(['subject' => 'Ballons U17']), $this->user);
        $this->assertSame('Ballons U17', $order->fresh()->subject);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_year_locked'));
        $this->service->update($order->fresh(), $this->data(['order_date' => '2027-01-02']), $this->user);
    }

    #[Test]
    public function approval_freezes_the_beneficiary_and_locks_the_order(): void
    {
        $order = $this->service->create($this->data(['beneficiary_bank' => null, 'beneficiary_rib' => null]), $this->user);

        $approved = $this->service->approve($order, $this->user);

        $this->assertSame(PaymentOrder::APPROVED, $approved->status);
        $this->assertSame('Sport Shop', $approved->beneficiary_name);
        $this->assertSame('BNA', $approved->beneficiary_bank);
        $this->assertSame('00100000000000001234', $approved->beneficiary_rib);
        $this->assertNotNull($approved->approved_at);

        Supplier::first()->update(['name' => 'Renamed Shop']);
        $this->assertSame('Sport Shop', $approved->fresh()->beneficiaryLabel());

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_locked'));
        $this->service->update($approved, $this->data(['subject' => 'Changed']), $this->user);
    }

    #[Test]
    public function an_order_without_beneficiary_cannot_be_approved(): void
    {
        $order = $this->service->create($this->data(['beneficiary_type' => null, 'beneficiary_id' => null]), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_no_beneficiary'));
        $this->service->approve($order, $this->user);
    }

    #[Test]
    public function marking_paid_creates_exactly_one_linked_expense(): void
    {
        $order = $this->service->approve($this->service->create($this->data([
            'invoice_number' => 'FA-9', 'invoice_date' => '2026-11-20', 'payment_reference' => 'VIR-77',
        ]), $this->user), $this->user);

        $paid = $this->service->markPaid($order, $this->user, '2026-11-26');

        $this->assertSame(PaymentOrder::PAID, $paid->status);
        $transaction = Transaction::findOrFail($paid->transaction_id);
        $this->assertSame('expense', $transaction->transaction_type);
        $this->assertSame('Ballons U15', $transaction->title);
        $this->assertSame('16050.00', $transaction->amount);
        $this->assertSame('2026-11-26', $transaction->transaction_date->format('Y-m-d'));
        $this->assertSame(Supplier::first()->id, $transaction->supplier_id);
        $this->assertSame('FA-9', $transaction->invoice_number);
        $this->assertSame('VIR-77', $transaction->payment_reference);
        $this->assertSame('00100000000000001234', $transaction->payment_account);
        $this->assertSame('Sport Shop', $transaction->payment_holder);
        $this->assertSame('Paid', $transaction->status);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_approved'));
        $this->service->markPaid($paid, $this->user);
    }

    #[Test]
    public function a_draft_cannot_be_paid(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_approved'));
        $this->service->markPaid($order, $this->user);
    }

    #[Test]
    public function paying_needs_a_category_and_a_register(): void
    {
        $order = $this->service->approve($this->service->create($this->data(['finance_account_id' => null]), $this->user), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_missing_finance'));
        $this->service->markPaid($order, $this->user);
    }

    #[Test]
    public function draft_and_approved_orders_can_be_cancelled_but_not_paid_ones(): void
    {
        $draft = $this->service->cancel($this->service->create($this->data(), $this->user), $this->user, 'Doublon');
        $this->assertSame(PaymentOrder::CANCELLED, $draft->status);
        $this->assertSame('Doublon', $draft->cancel_reason);
        $this->assertSame('01/2026', $draft->reference);   // keeps its number

        $paid = $this->service->markPaid($this->service->approve($this->service->create($this->data(), $this->user), $this->user), $this->user);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_cannot_cancel'));
        $this->service->cancel($paid, $this->user, 'Trop tard');
    }

    #[Test]
    public function an_existing_expense_gets_a_paid_order_once(): void
    {
        $supplier = Supplier::create(['name' => 'Printer', 'rib' => '00700000000000005678']);
        $transaction = Transaction::create([
            'title' => 'Impression maillots', 'amount' => 12300, 'transaction_date' => '2026-10-12',
            'transaction_type' => 'expense', 'finance_category_id' => $this->category->id, 'category' => 'equipment',
            'finance_account_id' => $this->account->id, 'payment_method' => 'bank', 'payment_account' => '00700000000000005678',
            'payment_holder' => 'Printer', 'supplier_id' => $supplier->id, 'status' => 'Paid',
        ]);

        $order = $this->service->fromExpense($transaction, $this->user);

        $this->assertSame(PaymentOrder::PAID, $order->status);
        $this->assertSame($transaction->id, $order->transaction_id);
        $this->assertSame('01/2026', $order->reference);
        $this->assertSame('Printer', $order->beneficiary_name);
        $this->assertSame('supplier', $order->beneficiary_type);
        $this->assertSame(1, Transaction::count());

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_exists'));
        $this->service->fromExpense($transaction, $this->user);
    }

    #[Test]
    public function an_income_never_gets_a_payment_order(): void
    {
        $transaction = Transaction::create([
            'title' => 'Cotisation', 'amount' => 1500, 'transaction_date' => '2026-10-12',
            'transaction_type' => 'income', 'category' => 'subscription', 'status' => 'Paid',
        ]);

        $this->expectExceptionObject(new PaymentOrderException('flash.payment_order_not_expense'));
        $this->service->fromExpense($transaction, $this->user);
    }
}
