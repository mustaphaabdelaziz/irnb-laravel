<?php

namespace Tests\Feature;

use App\Exceptions\PaymentOrderException;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderPurchaseLinkTest extends TestCase
{
    use RefreshDatabase;

    private PaymentOrderService $service;

    private User $admin;

    private Supplier $supplier;

    private FinanceCategory $category;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->service = app(PaymentOrderService::class);
        $this->admin = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
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

    private function purchaseOrder(string $status = PurchaseOrder::RECEIVED, ?Supplier $supplier = null, int $number = 1): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'year' => 2026, 'number' => $number, 'status' => $status, 'order_date' => '2026-11-20',
            'supplier_id' => ($supplier ?? $this->supplier)->id,
        ]);
        $order->lines()->create(['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500, 'amount' => 15000, 'sort_order' => 1]);

        return $order;
    }

    private function data(array $extra = []): array
    {
        return array_merge([
            'order_date' => '2026-11-25', 'subject' => 'Bon de commande 01/2026', 'amount' => 15000,
            'finance_category_id' => $this->category->id, 'finance_account_id' => $this->account->id,
            'payment_method' => 'bank', 'beneficiary_type' => 'supplier', 'beneficiary_id' => $this->supplier->id,
        ], $extra);
    }

    private function assertUnavailable(callable $call): void
    {
        try {
            $call();
            $this->fail('The purchase order should be refused.');
        } catch (PaymentOrderException $e) {
            $this->assertSame('flash.payment_order_purchase_order_unavailable', $e->flashKey);
        }
    }

    #[Test]
    public function a_payment_order_links_a_received_purchase_order_of_its_supplier(): void
    {
        $purchaseOrder = $this->purchaseOrder();

        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertSame($purchaseOrder->id, $order->purchase_order_id);
        $this->assertTrue($purchaseOrder->fresh()->hasActivePaymentOrder());
    }

    #[Test]
    public function a_second_active_payment_order_is_refused_until_the_first_is_cancelled(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $first = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin));
        $this->assertSame(1, PaymentOrder::count());

        $this->service->cancel($first, $this->admin, 'Erreur de montant');
        $second = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);
        $this->assertSame($purchaseOrder->id, $second->purchase_order_id);
    }

    #[Test]
    public function another_supplier_or_an_order_not_received_is_refused(): void
    {
        $other = Supplier::create(['name' => 'Other Shop']);
        $theirs = $this->purchaseOrder(PurchaseOrder::RECEIVED, $other, 1);
        $sent = $this->purchaseOrder(PurchaseOrder::SENT, null, 2);

        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $theirs->id]), $this->admin));
        $this->assertUnavailable(fn () => $this->service->create($this->data(['purchase_order_id' => $sent->id]), $this->admin));
        $this->assertUnavailable(fn () => $this->service->create($this->data([
            'beneficiary_type' => 'user', 'beneficiary_id' => $this->admin->id, 'purchase_order_id' => $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 3)->id,
        ]), $this->admin));
        $this->assertSame(0, PaymentOrder::count());
    }

    #[Test]
    public function editing_the_draft_keeps_its_own_link(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $updated = $this->service->update($order, $this->data(['subject' => 'Ballons U15', 'purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->assertSame('Ballons U15', $updated->subject);
        $this->assertSame($purchaseOrder->id, $updated->purchase_order_id);
    }

    #[Test]
    public function paying_points_the_received_equipment_to_the_expense(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $catalog = EquipmentCatalog::create(['name' => 'Ballon', 'category' => 'Balls']);
        $earlier = Transaction::create([
            'title' => 'Achat antérieur', 'amount' => 10, 'transaction_date' => '2026-11-01', 'transaction_type' => 'expense',
            'category' => 'equipment', 'status' => 'Paid',
        ]);
        $fresh = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 10, 'purchase_order_id' => $purchaseOrder->id]);
        $alreadyLinked = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 1,
            'purchase_order_id' => $purchaseOrder->id, 'purchase_transaction_id' => $earlier->id,
        ]);
        $unrelated = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-11-22', 'quantity' => 5]);

        $order = $this->service->approve($this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin), $this->admin);
        $paid = $this->service->markPaid($order, $this->admin, '2026-11-26');

        $this->assertSame($paid->transaction_id, $fresh->fresh()->purchase_transaction_id);
        $this->assertSame($earlier->id, $alreadyLinked->fresh()->purchase_transaction_id);
        $this->assertNull($unrelated->fresh()->purchase_transaction_id);
    }

    #[Test]
    public function the_form_offers_only_linkable_purchase_orders(): void
    {
        $free = $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 1);
        $taken = $this->purchaseOrder(PurchaseOrder::RECEIVED, null, 2);
        $this->purchaseOrder(PurchaseOrder::SENT, null, 3);
        $holder = $this->service->create($this->data(['purchase_order_id' => $taken->id]), $this->admin);

        $this->actingAs($this->admin)->get(route('payment-orders.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders', 1)
                ->where('purchaseOrders.0.id', $free->id)
                ->where('purchaseOrders.0.reference', '01/2026')
                ->where('purchaseOrders.0.supplier_id', $this->supplier->id)
                ->where('purchaseOrders.0.total', fn ($total) => (float) $total === 15000.0));

        // Its own draft still lists the order it holds.
        $this->get(route('payment-orders.edit', $holder))
            ->assertInertia(fn (Assert $page) => $page->has('purchaseOrders', 2));
    }

    #[Test]
    public function the_form_refuses_a_purchase_order_of_another_supplier(): void
    {
        $theirs = $this->purchaseOrder(PurchaseOrder::RECEIVED, Supplier::create(['name' => 'Other Shop']));

        $this->actingAs($this->admin)->post(route('payment-orders.store'), $this->data(['purchase_order_id' => $theirs->id]))
            ->assertSessionHasErrors('purchase_order_id');
        $this->assertSame(0, PaymentOrder::count());
    }

    #[Test]
    public function the_payment_order_page_links_back_to_its_purchase_order(): void
    {
        $purchaseOrder = $this->purchaseOrder();
        $order = $this->service->create($this->data(['purchase_order_id' => $purchaseOrder->id]), $this->admin);

        $this->actingAs($this->admin)->get(route('payment-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.purchase_order.id', $purchaseOrder->id)
                ->where('order.purchase_order.reference', '01/2026'));
    }
}
