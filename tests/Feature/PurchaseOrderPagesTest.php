<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->admin = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order()
    {
        return app(PurchaseOrderService::class)->create([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'payment_mode' => 'bank',
            'lines' => [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750],
            ],
        ], $this->admin);
    }

    #[Test]
    public function the_register_and_the_create_page_render(): void
    {
        $this->order();

        $this->actingAs($this->admin)->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Index')->has('orders.data', 1));
        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Create')
                ->has('suppliers', 1)->has('catalogs', 1)
                ->where('catalogs.0.name', 'Ballon taille 5'));
    }

    #[Test]
    public function a_draft_opens_the_full_form_and_a_sent_order_the_price_form(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get(route('purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Edit')
                ->where('pricesOnly', false)->has('order.lines', 2));

        app(PurchaseOrderService::class)->send($order, $this->admin);
        $this->get(route('purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Edit')->where('pricesOnly', true));
    }

    #[Test]
    public function the_show_page_lists_lines_total_and_its_payment_order(): void
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->receive($service->send($this->order(), $this->admin), $this->admin, '2026-11-27');
        $service->createPaymentOrder($order, $this->admin);

        $this->actingAs($this->admin)->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('PurchaseOrders/Show')
                ->has('order.lines', 2)
                ->where('order.total', fn ($total) => (float) $total === 16500.0)
                ->where('order.payment_orders.0.reference', '01/2026')
                ->where('order.payment_orders.0.status', 'draft')
                ->where('can.create_payment_order', false)
                ->where('can.edit', false));
    }

    #[Test]
    public function the_payment_order_form_offers_the_received_orders(): void
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->receive($service->send($this->order(), $this->admin), $this->admin, '2026-11-27');

        $this->actingAs($this->admin)->get(route('payment-orders.create'))
            ->assertInertia(fn (Assert $page) => $page->component('PaymentOrders/Create')
                ->where('purchaseOrders.0.id', $order->id)
                ->where('purchaseOrders.0.supplier_id', $this->supplier->id));
    }
}
