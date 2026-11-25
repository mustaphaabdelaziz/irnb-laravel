<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseOrderException;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Equipment\EquipmentStockService;
use App\Services\Finance\PaymentOrderService;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $service;

    private User $user;

    private Supplier $supplier;

    private EquipmentCatalog $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-25 10:00:00');
        app()->setLocale('en');
        $this->service = app(PurchaseOrderService::class);
        $this->user = User::factory()->admin()->create();
        $this->supplier = Supplier::create(['name' => 'Test Sport Shop', 'bank_name' => 'BNA', 'rib' => '00100000000000001234']);
        $this->balls = EquipmentCatalog::create(['name' => 'Ballon taille 5', 'category' => 'Balls']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $extra = [], ?array $lines = null): array
    {
        return array_merge([
            'order_date' => '2026-11-25', 'supplier_id' => $this->supplier->id, 'requested_by' => 'Section football',
            'delivery_mode' => 'Livraison au club', 'payment_mode' => 'bank', 'observation' => null,
            'lines' => $lines ?? [
                ['equipment_catalog_id' => $this->balls->id, 'designation' => 'Ballon taille 5', 'quantity' => 10, 'unit_price' => 1500],
                ['equipment_catalog_id' => null, 'designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750],
            ],
        ], $extra);
    }

    private function sent(array $extra = [], ?array $lines = null): PurchaseOrder
    {
        return $this->service->send($this->service->create($this->data($extra, $lines), $this->user), $this->user);
    }

    private function assertRefused(string $key, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$key}.");
        } catch (PurchaseOrderException $e) {
            $this->assertSame($key, $e->flashKey);
        }
    }

    #[Test]
    public function create_numbers_a_draft_with_server_computed_line_amounts(): void
    {
        $first = $this->service->create($this->data(), $this->user);
        $second = $this->service->create($this->data(), $this->user);

        $this->assertSame(PurchaseOrder::DRAFT, $first->status);
        $this->assertSame('01/2026', $first->reference);
        $this->assertSame('02/2026', $second->reference);
        $this->assertSame($this->user->id, $first->created_by);
        $this->assertCount(2, $first->lines);
        $this->assertSame('15000.00', $first->lines[0]->amount);
        $this->assertSame(2, $first->lines[1]->sort_order);
        $this->assertSame(16500.0, $first->total());
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_CREATED, 'subject_id' => $first->id]);
    }

    #[Test]
    public function the_year_comes_from_the_order_date(): void
    {
        $this->assertSame('01/2025', $this->service->create($this->data(['order_date' => '2025-12-30']), $this->user)->reference);
    }

    #[Test]
    public function a_draft_is_fully_editable_but_stays_in_its_year(): void
    {
        $order = $this->service->create($this->data(), $this->user);

        $updated = $this->service->update($order, $this->data(['requested_by' => 'Section natation'], [
            ['equipment_catalog_id' => null, 'designation' => 'Lunettes', 'quantity' => 6, 'unit_price' => null],
        ]), $this->user);

        $this->assertSame('Section natation', $updated->requested_by);
        $this->assertCount(1, $updated->lines);
        $this->assertSame('Lunettes', $updated->lines[0]->designation);
        $this->assertNull($updated->lines[0]->amount);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_UPDATED, 'subject_id' => $order->id]);

        $this->assertRefused('flash.purchase_order_year_locked', fn () => $this->service->update($updated, $this->data(['order_date' => '2027-01-02']), $this->user));
    }

    #[Test]
    public function sending_locks_everything_but_the_unit_prices(): void
    {
        $order = $this->sent();
        $this->assertSame(PurchaseOrder::SENT, $order->status);
        $this->assertSame($this->user->id, $order->sent_by);
        $this->assertNotNull($order->sent_at);
        $other = Supplier::create(['name' => 'Other Shop']);
        [$balls, $pump] = [$order->lines[0], $order->lines[1]];

        $this->service->update($order, [
            'supplier_id' => $other->id,
            'lines' => [
                ['id' => $balls->id, 'designation' => 'Changed', 'quantity' => 99, 'unit_price' => 1400],
                ['id' => $pump->id, 'unit_price' => null],
            ],
        ], $this->user);

        $order->refresh()->load('lines');
        $this->assertSame($this->supplier->id, $order->supplier_id);
        $this->assertSame('Ballon taille 5', $order->lines[0]->designation);
        $this->assertSame(10, $order->lines[0]->quantity);
        $this->assertSame('1400.00', $order->lines[0]->unit_price);
        $this->assertSame('14000.00', $order->lines[0]->amount);
        $this->assertNull($order->lines[1]->unit_price);
        $this->assertNull($order->lines[1]->amount);
        $this->assertFalse($order->isFullyPriced());
    }

    #[Test]
    public function prices_lock_once_an_active_payment_order_is_linked(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $payment = $this->service->createPaymentOrder($order, $this->user);
        $prices = ['lines' => [['id' => $order->lines[0]->id, 'unit_price' => 1400], ['id' => $order->lines[1]->id, 'unit_price' => 750]]];

        $this->assertRefused('flash.purchase_order_prices_locked', fn () => $this->service->update($order->fresh(), $prices, $this->user));

        app(PaymentOrderService::class)->cancel($payment, $this->user, 'Montant à revoir');
        $this->service->update($order->fresh(), $prices, $this->user);
        $this->assertSame('1400.00', $order->fresh()->lines[0]->unit_price);
    }

    #[Test]
    public function only_a_draft_with_lines_is_sent(): void
    {
        $order = $this->sent();

        $this->assertRefused('flash.purchase_order_not_draft', fn () => $this->service->send($order, $this->user));
    }

    #[Test]
    public function draft_and_sent_orders_cancel_and_keep_their_number_but_received_ones_do_not(): void
    {
        $draft = $this->service->cancel($this->service->create($this->data(), $this->user), $this->user);
        $this->assertSame(PurchaseOrder::CANCELLED, $draft->status);
        $this->assertSame('01/2026', $draft->reference);
        $this->assertSame($this->user->id, $draft->cancelled_by);

        $sent = $this->service->cancel($this->sent(), $this->user);
        $this->assertSame(PurchaseOrder::CANCELLED, $sent->status);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_CANCELLED, 'subject_id' => $sent->id]);

        $received = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $this->assertRefused('flash.purchase_order_cannot_cancel', fn () => $this->service->cancel($received, $this->user));
    }

    #[Test]
    public function receiving_without_ticks_only_changes_the_status(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');

        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertSame('2026-11-27', $order->received_at->format('Y-m-d'));
        $this->assertSame($this->user->id, $order->received_by);
        $this->assertSame(0, EquipmentItem::count());
        $this->assertSame(0, PaymentOrder::count());
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityAction::PURCHASE_ORDER_RECEIVED, 'subject_id' => $order->id]);
    }

    #[Test]
    public function only_a_sent_order_is_received(): void
    {
        $draft = $this->service->create($this->data(), $this->user);

        $this->assertRefused('flash.purchase_order_not_sent', fn () => $this->service->receive($draft, $this->user, '2026-11-27'));
    }

    #[Test]
    public function receiving_into_stock_makes_one_lot_per_catalog_line_without_an_expense(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', toStock: true);

        $items = EquipmentItem::all();
        $this->assertCount(1, $items, 'the free-text line never goes to stock');
        $lot = $items->first();
        $this->assertSame($this->balls->id, $lot->catalog_id);
        $this->assertSame(10, $lot->quantity);
        $this->assertEquals(1500, $lot->unit_price);
        $this->assertSame('Ballon taille 5', $lot->designation);
        $this->assertSame('New', $lot->condition);
        $this->assertSame('purchase', $lot->received_via);
        $this->assertSame('2026-11-27', $lot->purchase_date->format('Y-m-d'));
        $this->assertSame($order->id, $lot->purchase_order_id);
        $this->assertNull($lot->purchase_transaction_id);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_serial_catalog_line_becomes_numbered_units_all_tagged_with_the_order(): void
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();
        EquipmentCategory::updateOrCreate(['name' => 'Balls'], ['code' => 'BALL']);
        $vests = EquipmentCatalog::create(['name' => 'GPS Vest', 'category' => 'Balls', 'requires_serial' => true]);

        $order = $this->service->receive($this->sent([], [
            ['equipment_catalog_id' => $vests->id, 'designation' => 'GPS Vest', 'quantity' => 3, 'unit_price' => null],
        ]), $this->user, '2026-11-27', toStock: true);

        $items = EquipmentItem::all();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame($order->id, $item->purchase_order_id);
            $this->assertSame(1, $item->quantity);
            $this->assertNull($item->unit_price);
        }
    }

    #[Test]
    public function too_many_numbered_units_are_refused_before_anything_changes(): void
    {
        $vests = EquipmentCatalog::create(['name' => 'GPS Vest', 'category' => 'Balls', 'requires_serial' => true]);
        $order = $this->sent([], [['equipment_catalog_id' => $vests->id, 'designation' => 'GPS Vest', 'quantity' => 201, 'unit_price' => 10]]);

        $this->assertRefused('flash.purchase_order_too_many_units', fn () => $this->service->receive($order, $this->user, '2026-11-27', toStock: true));
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);
        $this->assertSame(0, EquipmentItem::count());
    }

    #[Test]
    public function receiving_with_a_payment_order_prefills_a_draft_for_the_supplier(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', makePaymentOrder: true);

        $payment = PaymentOrder::firstOrFail();
        $this->assertSame(PaymentOrder::DRAFT, $payment->status);
        $this->assertSame('supplier', $payment->beneficiary_type);
        $this->assertSame($this->supplier->id, $payment->beneficiary_id);
        $this->assertSame('BNA', $payment->beneficiary_bank);
        $this->assertSame('00100000000000001234', $payment->beneficiary_rib);
        $this->assertSame('16500.00', $payment->amount);
        $this->assertSame('Purchase order 01/2026', $payment->subject);
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame('2026-11-27', $payment->order_date->format('Y-m-d'));
        $this->assertSame($order->id, $payment->purchase_order_id);
        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
    }

    #[Test]
    public function a_payment_order_needs_every_price_and_nothing_changes_otherwise(): void
    {
        $order = $this->sent([], [['equipment_catalog_id' => null, 'designation' => 'Caméra', 'quantity' => 1, 'unit_price' => null]]);

        $this->assertRefused('flash.purchase_order_unpriced', fn () => $this->service->receive($order, $this->user, '2026-11-27', makePaymentOrder: true));
        $this->assertSame(PurchaseOrder::SENT, $order->fresh()->status);
        $this->assertSame(0, PaymentOrder::count());

        $received = $this->service->receive($order->fresh(), $this->user, '2026-11-27');
        $this->assertRefused('flash.purchase_order_unpriced', fn () => $this->service->createPaymentOrder($received, $this->user));
    }

    #[Test]
    public function a_received_order_has_at_most_one_active_payment_order(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27');
        $first = $this->service->createPaymentOrder($order, $this->user);
        $this->assertSame('2026-11-25', $first->order_date->format('Y-m-d'));

        $this->assertRefused('flash.purchase_order_has_payment_order', fn () => $this->service->createPaymentOrder($order->fresh(), $this->user));

        app(PaymentOrderService::class)->cancel($first, $this->user, 'Fournisseur a changé son RIB');
        $this->service->createPaymentOrder($order->fresh(), $this->user);
        $this->assertSame(2, PaymentOrder::count());
        $this->assertSame(1, PaymentOrder::where('status', '!=', PaymentOrder::CANCELLED)->count());
    }

    #[Test]
    public function a_payment_order_waits_for_reception(): void
    {
        $draft = $this->service->create($this->data(), $this->user);

        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($draft, $this->user));
        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($this->sent(), $this->user));
    }

    #[Test]
    public function a_stale_model_cannot_receive_twice(): void
    {
        $order = $this->sent();
        $a = $order->fresh();
        $b = $order->fresh();

        $this->service->receive($a, $this->user, '2026-11-27', toStock: true);

        $this->assertRefused('flash.purchase_order_not_sent', fn () => $this->service->receive($b, $this->user, '2026-11-27', toStock: true));
        $this->assertSame(1, EquipmentItem::count());
    }

    #[Test]
    public function a_legacy_order_refuses_every_change(): void
    {
        $legacy = PurchaseOrder::create([
            'year' => 2021, 'number' => null, 'legacy_reference' => '01/2021', 'is_legacy' => true, 'legacy_source' => 'purchase',
            'status' => PurchaseOrder::RECEIVED, 'order_date' => '2021-03-14',
        ]);
        $legacy->lines()->create(['designation' => 'Caméra', 'quantity' => 1, 'sort_order' => 1]);

        $this->assertRefused('flash.purchase_order_locked', fn () => $this->service->update($legacy, ['lines' => []], $this->user));
        $this->assertRefused('flash.purchase_order_not_draft', fn () => $this->service->send($legacy, $this->user));
        $this->assertRefused('flash.purchase_order_cannot_cancel', fn () => $this->service->cancel($legacy, $this->user));
        $this->assertRefused('flash.purchase_order_not_received', fn () => $this->service->createPaymentOrder($legacy, $this->user));
    }

    #[Test]
    public function a_split_lot_keeps_its_purchase_order(): void
    {
        $order = $this->service->receive($this->sent(), $this->user, '2026-11-27', toStock: true);
        $lot = EquipmentItem::firstOrFail();

        $part = app(EquipmentStockService::class)->splitLot($lot, 3, 'Damaged', $this->user->id);

        $this->assertSame($order->id, $part->purchase_order_id);
        $this->assertSame(10, (int) EquipmentItem::where('purchase_order_id', $order->id)->sum('quantity'));
    }
}
