<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function supplier(string $name = 'Test Sport Shop'): Supplier
    {
        return Supplier::firstOrCreate(['name' => $name], ['bank_name' => 'BNA', 'rib' => '00100000000000001234']);
    }

    /** @param list<array{designation: string, quantity: int, unit_price: ?float}> $lines */
    private function order(array $attributes = [], array $lines = [['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0]]): PurchaseOrder
    {
        $order = PurchaseOrder::create(array_merge([
            'year' => 2026, 'number' => 1, 'status' => PurchaseOrder::DRAFT, 'order_date' => '2026-11-25',
            'supplier_id' => $this->supplier()->id,
        ], $attributes));
        foreach ($lines as $i => $line) {
            $order->lines()->create([
                ...$line,
                'amount' => PurchaseOrderLine::amountFor($line['quantity'], $line['unit_price']),
                'sort_order' => $i + 1,
            ]);
        }

        return $order->fresh('lines');
    }

    private function paymentOrder(PurchaseOrder $purchaseOrder, string $status = PaymentOrder::DRAFT): PaymentOrder
    {
        return PaymentOrder::create([
            'year' => 2026, 'number' => (int) PaymentOrder::max('number') + 1, 'status' => $status,
            'order_date' => '2026-11-25', 'subject' => 'Bon', 'amount' => 100,
            'beneficiary_type' => 'supplier', 'beneficiary_id' => $purchaseOrder->supplier_id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);
    }

    #[Test]
    public function the_reference_is_number_slash_year_or_the_legacy_text(): void
    {
        $this->assertSame('03/2026', $this->order(['number' => 3])->reference);
        $this->assertSame('114/2026', $this->order(['number' => 114])->reference);
        $this->assertSame('01/2021', $this->order([
            'number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'supplier_id' => null,
        ])->reference);
    }

    #[Test]
    public function the_sequence_is_per_year_ignores_legacy_rows_and_a_number_is_used_once(): void
    {
        $this->order(['number' => 1]);
        $this->order(['number' => 2]);
        $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '07/2026']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2026)));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('purchase_orders', 2027)));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order(['number' => 2]);
    }

    #[Test]
    public function line_amounts_and_the_total_follow_the_prices(): void
    {
        $this->assertSame(4501.5, PurchaseOrderLine::amountFor(3, 1500.5));
        $this->assertSame(4501.5, PurchaseOrderLine::amountFor(3, '1500.50'));
        $this->assertNull(PurchaseOrderLine::amountFor(3, null));

        $priced = $this->order([], [
            ['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0],
            ['designation' => 'Gonfleur', 'quantity' => 2, 'unit_price' => 750.0],
        ]);
        $this->assertSame('15000.00', $priced->lines[0]->amount);
        $this->assertSame(16500.0, $priced->total());
        $this->assertTrue($priced->isFullyPriced());

        $partly = $this->order(['number' => 2], [
            ['designation' => 'Ballon', 'quantity' => 10, 'unit_price' => 1500.0],
            ['designation' => 'Caméra', 'quantity' => 1, 'unit_price' => null],
        ]);
        $this->assertFalse($partly->isFullyPriced());
        $this->assertSame(15000.0, $partly->total());
    }

    #[Test]
    public function only_a_draft_is_editable_and_prices_stay_open_until_an_active_payment_order(): void
    {
        $this->assertTrue($this->order()->isEditable());

        $sent = $this->order(['number' => 2, 'status' => PurchaseOrder::SENT]);
        $this->assertFalse($sent->isEditable());
        $this->assertTrue($sent->canEditPrices());

        $received = $this->order(['number' => 3, 'status' => PurchaseOrder::RECEIVED]);
        $payment = $this->paymentOrder($received);
        $this->assertTrue($received->hasActivePaymentOrder());
        $this->assertFalse($received->canEditPrices());

        $payment->update(['status' => PaymentOrder::CANCELLED]);
        $this->assertFalse($received->hasActivePaymentOrder());
        $this->assertTrue($received->canEditPrices());

        $this->assertFalse($this->order(['number' => 4, 'status' => PurchaseOrder::CANCELLED])->canEditPrices());
        $legacy = $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'status' => PurchaseOrder::RECEIVED]);
        $this->assertFalse($legacy->isEditable());
        $this->assertFalse($legacy->canEditPrices());
    }

    #[Test]
    public function only_a_received_order_of_the_supplier_without_an_active_payment_order_is_linkable(): void
    {
        $received = $this->order(['status' => PurchaseOrder::RECEIVED]);
        $other = $this->supplier('Other Shop');

        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($received->id, $other->id));
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));

        $active = $this->paymentOrder($received);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));
        // The payment order that already holds it may keep it (editing that draft).
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id, $active->id));

        $active->update(['status' => PaymentOrder::CANCELLED]);
        $this->assertTrue(PurchaseOrder::claimForPaymentOrder($received->id, $received->supplier_id));

        $sent = $this->order(['number' => 2, 'status' => PurchaseOrder::SENT]);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($sent->id, $sent->supplier_id));
        $legacy = $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2021', 'year' => 2021, 'status' => PurchaseOrder::RECEIVED]);
        $this->assertFalse(PurchaseOrder::claimForPaymentOrder($legacy->id, $legacy->supplier_id));

        $this->assertSame([$received->id], PurchaseOrder::query()->linkable()->pluck('id')->all());
        $this->assertSame([], PurchaseOrder::query()->linkable($other->id)->pluck('id')->all());
    }

    #[Test]
    public function the_links_are_real_foreign_keys_that_blank_on_delete(): void
    {
        $paymentKeys = collect(Schema::getForeignKeys('payment_orders'));
        $this->assertTrue($paymentKeys->contains(fn (array $fk) => $fk['columns'] === ['purchase_order_id'] && $fk['foreign_table'] === 'purchase_orders'));
        $equipmentKeys = collect(Schema::getForeignKeys('equipment_items'));
        $this->assertTrue($equipmentKeys->contains(fn (array $fk) => $fk['columns'] === ['purchase_order_id'] && $fk['foreign_table'] === 'purchase_orders'));

        $order = $this->order(['status' => PurchaseOrder::RECEIVED]);
        $payment = $this->paymentOrder($order);
        $catalog = EquipmentCatalog::create(['name' => 'Ballon', 'category' => 'Balls']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-11-25', 'quantity' => 10, 'purchase_order_id' => $order->id,
        ]);
        $this->assertSame($order->id, $item->fresh()->purchase_order_id);

        // Orders are never deleted by the app; the keys only guarantee nothing dangles.
        $order->delete();

        $this->assertSame(0, PurchaseOrderLine::count());
        $this->assertNull($payment->fresh()->purchase_order_id);
        $this->assertNull($item->fresh()->purchase_order_id);
    }

    #[Test]
    public function a_supplier_with_a_purchase_order_is_in_use(): void
    {
        $supplier = $this->supplier();
        $this->assertFalse($supplier->isInUse());

        $this->order();

        $this->assertTrue($supplier->fresh()->isInUse());
    }
}
