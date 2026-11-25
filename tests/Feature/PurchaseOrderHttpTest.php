<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
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
        return array_merge([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'requested_by' => 'Section football',
            'delivery_mode' => 'Livraison au club', 'payment_mode' => 'cheque', 'observation' => null,
            'lines' => [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => null],
            ],
        ], $extra);
    }

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function draft(array $extra = []): PurchaseOrder
    {
        return $this->service()->create($this->payload($extra), User::factory()->admin()->create());
    }

    private function sent(array $extra = []): PurchaseOrder
    {
        return $this->service()->send($this->draft($extra), User::factory()->admin()->create());
    }

    private function pricedLines(): array
    {
        return ['lines' => [['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500]]];
    }

    #[Test]
    public function an_editor_creates_a_draft_and_lands_on_it(): void
    {
        $user = $this->userWith(['purchase_orders' => ['view', 'add', 'edit']]);

        $response = $this->actingAs($user)->post(route('purchase-orders.store'), $this->payload());

        $order = PurchaseOrder::firstOrFail();
        $response->assertRedirect(route('purchase-orders.show', $order))->assertSessionHas('success', 'flash.purchase_order_created');
        $this->assertSame('01/2026', $order->reference);
        $this->assertCount(2, $order->lines);
    }

    #[Test]
    public function validation_needs_a_supplier_and_valid_lines(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('purchase-orders.store'), $this->payload(['supplier_id' => null]))
            ->assertSessionHasErrors('supplier_id');
        $this->post(route('purchase-orders.store'), $this->payload(['lines' => []]))
            ->assertSessionHasErrors('lines');
        $this->post(route('purchase-orders.store'), $this->payload(['lines' => [['designation' => '', 'quantity' => 0]]]))
            ->assertSessionHasErrors(['lines.0.designation', 'lines.0.quantity']);
        $this->post(route('purchase-orders.store'), $this->payload(['payment_mode' => 'barter']))
            ->assertSessionHasErrors('payment_mode');
        $this->assertSame(0, PurchaseOrder::count());
    }

    #[Test]
    public function a_view_only_user_cannot_create_or_send(): void
    {
        $order = $this->draft();
        $viewer = $this->userWith(['purchase_orders' => ['view']]);

        $this->actingAs($viewer)->post(route('purchase-orders.store'), $this->payload())->assertForbidden();
        $this->post(route('purchase-orders.send', $order))->assertForbidden();
        $this->assertSame(PurchaseOrder::DRAFT, $order->fresh()->status);
    }

    #[Test]
    public function a_draft_is_updated_and_a_sent_order_only_changes_its_prices(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->draft();

        $this->actingAs($admin)->put(route('purchase-orders.update', $order), $this->payload(['requested_by' => 'Section natation']))
            ->assertRedirect(route('purchase-orders.show', $order))->assertSessionHas('success', 'flash.purchase_order_updated');
        $this->assertSame('Section natation', $order->fresh()->requested_by);

        $this->post(route('purchase-orders.send', $order))->assertSessionHas('success', 'flash.purchase_order_sent');
        $lines = $order->fresh()->lines;
        $this->put(route('purchase-orders.update', $order), [
            'requested_by' => 'Ignored',
            'lines' => [['id' => $lines[0]->id, 'unit_price' => 1400], ['id' => $lines[1]->id, 'unit_price' => 900]],
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('Section natation', $order->requested_by);
        $this->assertSame('1400.00', $order->lines[0]->unit_price);
        $this->assertSame('1800.00', $order->lines[1]->amount);
    }

    #[Test]
    public function the_edit_page_turns_away_a_locked_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->service()->cancel($this->draft(), $admin);

        $this->actingAs($admin)->get(route('purchase-orders.edit', $order))
            ->assertRedirect(route('purchase-orders.show', $order))
            ->assertSessionHas('error', 'flash.purchase_order_locked');
    }

    #[Test]
    public function receiving_and_cancelling_answer_with_flashes(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = $this->draft();
        $sent = $this->sent();

        $this->actingAs($admin)->post(route('purchase-orders.receive', $draft), ['received_date' => '2026-11-25'])
            ->assertSessionHas('error', 'flash.purchase_order_not_sent');
        $this->post(route('purchase-orders.receive', $sent), ['received_date' => '2026-11-25'])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame(PurchaseOrder::RECEIVED, $sent->fresh()->status);

        $this->post(route('purchase-orders.cancel', $draft))->assertSessionHas('success', 'flash.purchase_order_cancelled');
        $this->post(route('purchase-orders.cancel', $sent))->assertSessionHas('error', 'flash.purchase_order_cannot_cancel');
        $this->post(route('purchase-orders.receive', $sent), [])->assertSessionHasErrors('received_date');
    }

    #[Test]
    public function the_received_date_stays_between_the_order_date_and_today(): void
    {
        $admin = User::factory()->admin()->create();
        $sent = $this->sent();

        $this->actingAs($admin)->post(route('purchase-orders.receive', $sent), ['received_date' => '2026-11-24'])
            ->assertSessionHasErrors('received_date');
        $this->post(route('purchase-orders.receive', $sent), ['received_date' => '2026-11-26'])
            ->assertSessionHasErrors('received_date');
        $this->assertSame(PurchaseOrder::SENT, $sent->fresh()->status);

        $this->post(route('purchase-orders.receive', $sent), ['received_date' => '2026-11-25'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrder::RECEIVED, $sent->fresh()->status);
    }

    #[Test]
    public function receiving_into_stock_needs_the_equipment_receive_right(): void
    {
        $order = $this->sent();
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $storekeeper = $this->userWith(['purchase_orders' => ['view', 'edit'], 'equipment' => ['view', 'edit']]);

        $this->actingAs($buyer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-25', 'to_stock' => true])
            ->assertForbidden();
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);

        $this->actingAs($storekeeper)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-25', 'to_stock' => true])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame(1, EquipmentItem::where('purchase_order_id', $order->id)->count());
    }

    #[Test]
    public function a_payment_order_on_receive_needs_the_payment_orders_add_right(): void
    {
        $order = $this->sent($this->pricedLines());
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $treasurer = $this->userWith(['purchase_orders' => ['view', 'edit'], 'payment_orders' => ['view', 'add']]);

        $this->actingAs($buyer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-25', 'payment_order' => true])
            ->assertForbidden();

        $this->actingAs($treasurer)->post(route('purchase-orders.receive', $order), ['received_date' => '2026-11-25', 'payment_order' => true])
            ->assertSessionHas('success', 'flash.purchase_order_received');
        $this->assertSame($order->id, PaymentOrder::firstOrFail()->purchase_order_id);
    }

    #[Test]
    public function the_payment_order_button_needs_both_rights_and_lands_on_the_new_order(): void
    {
        $order = $this->service()->receive($this->sent($this->pricedLines()), User::factory()->admin()->create(), '2026-11-27');
        $buyer = $this->userWith(['purchase_orders' => ['view', 'edit']]);
        $treasurer = $this->userWith(['purchase_orders' => ['view', 'edit'], 'payment_orders' => ['view', 'add']]);
        $reader = $this->userWith(['purchase_orders' => ['view'], 'payment_orders' => ['view', 'add']]);

        $this->actingAs($buyer)->post(route('purchase-orders.payment-order.store', $order))->assertForbidden();
        $this->actingAs($reader)->post(route('purchase-orders.payment-order.store', $order))->assertForbidden();
        $this->assertSame(0, PaymentOrder::count());

        $this->actingAs($treasurer)->post(route('purchase-orders.payment-order.store', $order))
            ->assertRedirect(route('payment-orders.show', PaymentOrder::firstOrFail()))
            ->assertSessionHas('success', 'flash.payment_order_created');
        $this->post(route('purchase-orders.payment-order.store', $order))
            ->assertSessionHas('error', 'flash.purchase_order_has_payment_order');
    }

    #[Test]
    public function the_register_lists_filters_and_finds_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        $this->draft();
        $this->sent($this->pricedLines());
        $this->draft(['requested_by' => 'Section natation']);

        $this->actingAs($admin)->get(route('purchase-orders.index', ['status' => 'sent']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', '02/2026')
                ->where('orders.data.0.supplier', 'Test Sport Shop')
                ->where('orders.data.0.total', fn ($total) => (float) $total === 15000.0)
                ->has('years')->has('suppliers'));
        $this->get(route('purchase-orders.index', ['q' => '03/2026']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.requested_by', 'Section natation'));
        $this->get(route('purchase-orders.index', ['q' => 'gonfleur']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 2)->where('orders.data.0.total', null));
        $this->get(route('purchase-orders.index', ['q' => 'sport shop']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3));
    }

    #[Test]
    public function the_show_page_tells_what_the_viewer_may_do(): void
    {
        $order = $this->sent();
        $viewer = $this->userWith(['purchase_orders' => ['view']]);

        $this->actingAs($viewer)->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.reference', '01/2026')
                ->where('order.supplier.name', 'Test Sport Shop')
                ->where('order.fully_priced', false)
                ->where('can.edit', false)->where('can.receive', false)->where('can.cancel', false)
                ->where('can.receive_stock', false)->where('can.create_payment_order', false)
                ->where('can.print', true));

        $this->actingAs(User::factory()->admin()->create())->get(route('purchase-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', true)->where('can.receive', true)->where('can.cancel', true)
                ->where('can.receive_stock', true)
                // One line has no price yet.
                ->where('can.payment_order_on_receive', false));
    }

    #[Test]
    public function there_is_no_delete_route(): void
    {
        $this->assertFalse(Route::has('purchase-orders.destroy'));
    }
}
