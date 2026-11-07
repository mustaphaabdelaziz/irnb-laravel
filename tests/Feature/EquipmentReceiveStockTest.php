<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording what equipment is worth and spending money on it used to be the
 * same action: adding an item with a price silently created an expense
 * Transaction. Purchasing is now a deliberate act with its own screen and an
 * explicit "record as expense" choice.
 */
class EquipmentReceiveStockTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);
    }

    #[Test]
    public function receiving_stock_with_the_expense_box_ticked_creates_one_transaction(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 50,
            'unit_price' => 120,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => true,
        ])->assertRedirect();

        $this->assertSame(1, Transaction::count());

        $transaction = Transaction::first();
        $this->assertSame('expense', $transaction->transaction_type);
        $this->assertEquals(6000, $transaction->amount, 'amount is unit price x quantity');
        $this->assertSame(2026, (int) $transaction->fiscal_year, 'fiscal year follows the purchase date');

        $lot = EquipmentItem::first();
        $this->assertSame(50, $lot->quantity);
        $this->assertEquals(120, $lot->unit_price);
        $this->assertSame($transaction->id, $lot->purchase_transaction_id);
    }

    #[Test]
    public function the_fiscal_year_follows_the_purchase_date_not_today(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 10,
            'unit_price' => 100,
            'purchase_date' => '2024-05-02',
            'condition' => 'Good',
            'record_expense' => true,
        ])->assertRedirect();

        $this->assertSame(2024, (int) Transaction::first()->fiscal_year);
    }

    #[Test]
    public function receiving_stock_without_the_expense_box_touches_no_finance(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 30,
            'unit_price' => 120,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => false,
            'received_via' => 'donation',
        ])->assertRedirect();

        $this->assertSame(0, Transaction::count());

        $lot = EquipmentItem::first();
        $this->assertSame('donation', $lot->received_via);
        $this->assertNull($lot->purchase_transaction_id);
    }

    #[Test]
    public function received_stock_can_be_tagged_to_branches(): void
    {
        $branch = Branch::create(['name' => 'Football', 'name_en' => 'Football']);

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 20,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => false,
            'branch_ids' => [$branch->id],
        ])->assertRedirect();

        $this->assertCount(1, EquipmentItem::first()->branches);
    }

    #[Test]
    public function receiving_stock_is_recorded_in_the_history(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 20,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('equipment_histories', [
            'item_id' => EquipmentItem::first()->id,
            'event_type' => 'Received',
        ]);
    }

    private function serialCatalog(): EquipmentCatalog
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();

        // The equipment_categories migration seeds defaults, so no plain create().
        EquipmentCategory::updateOrCreate(['name' => 'Balls'], ['code' => 'BALL']);

        return EquipmentCatalog::create([
            'name' => 'GPS Vest',
            'category' => 'Balls',
            'requires_serial' => true,
        ]);
    }

    #[Test]
    public function receiving_stock_on_a_serial_catalog_creates_one_numbered_unit_per_quantity(): void
    {
        $catalog = $this->serialCatalog();
        $branch = Branch::create(['name' => 'Football', 'name_en' => 'Football']);

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 3,
            'unit_price' => 12000,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'location' => 'Store room',
            'record_expense' => false,
            'branch_ids' => [$branch->id],
        ])->assertRedirect(route('equipment.catalogs.show', $catalog->id));

        $items = EquipmentItem::orderBy('id')->get();
        $this->assertCount(3, $items, 'one row per unit, not one lot of 3');

        $prefix = sprintf('IRNB-2026-BALL-%03d-', $catalog->id);
        $this->assertSame(
            [$prefix.'00001', $prefix.'00002', $prefix.'00003'],
            $items->pluck('unique_identifier')->all(),
        );

        foreach ($items as $item) {
            $this->assertSame(1, $item->quantity);
            $this->assertEquals(12000, $item->unit_price);
            $this->assertSame('Store room', $item->location);
            $this->assertSame([$branch->id], $item->branches->pluck('id')->all());
        }

        $this->assertSame(3, EquipmentHistory::where('event_type', 'Received')->count(), 'each unit has its own history');
    }

    #[Test]
    public function the_serial_counter_continues_after_units_already_in_the_catalog(): void
    {
        $catalog = $this->serialCatalog();
        $user = $this->user();

        $this->actingAs($user)->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 2,
            'purchase_date' => '2026-06-01',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertRedirect();

        $this->assertSame(
            ['00001', '00002', '00003'],
            EquipmentItem::orderBy('id')->pluck('unique_identifier')->map(fn ($uid) => substr($uid, -5))->all(),
        );
    }

    #[Test]
    public function receiving_numbered_units_with_the_expense_box_ticked_records_one_expense_for_all(): void
    {
        $catalog = $this->serialCatalog();

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 5,
            'unit_price' => 12000,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'record_expense' => true,
        ])->assertRedirect();

        $this->assertSame(1, Transaction::count(), 'one purchase, one expense');
        $transaction = Transaction::first();
        $this->assertEquals(60000, $transaction->amount);

        $this->assertSame(5, EquipmentItem::where('purchase_transaction_id', $transaction->id)->count());
    }

    #[Test]
    public function deleting_one_numbered_unit_keeps_the_shared_expense(): void
    {
        $catalog = $this->serialCatalog();
        $user = $this->user();

        $this->actingAs($user)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 2,
            'unit_price' => 100,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'record_expense' => true,
        ])->assertRedirect();

        $this->actingAs($user)->delete(route('equipment.items.destroy', EquipmentItem::first()))->assertRedirect();

        $this->assertSame(1, EquipmentItem::count());
        $this->assertSame(1, Transaction::count(), 'the other unit still points at the expense');
    }

    #[Test]
    public function a_serial_catalog_receives_at_most_two_hundred_units_at_once(): void
    {
        $catalog = $this->serialCatalog();

        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 201,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(0, EquipmentItem::count());
    }

    #[Test]
    public function a_plain_catalog_keeps_receiving_one_lot_without_a_serial(): void
    {
        $this->actingAs($this->user())->post(route('equipment.stock.receive'), [
            'catalog_id' => $this->catalog()->id,
            'quantity' => 500,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertRedirect();

        $lot = EquipmentItem::sole();
        $this->assertSame(500, $lot->quantity);
        $this->assertNull($lot->unique_identifier);
    }

    #[Test]
    public function adding_a_single_item_no_longer_creates_a_transaction_as_a_side_effect(): void
    {
        $catalog = EquipmentCatalog::create([
            'name' => 'Match Ball',
            'category' => 'Balls',
            'requires_serial' => true,
        ]);

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'condition' => 'New',
            'purchase_price' => 4500,
        ])->assertRedirect();

        $this->assertSame(0, Transaction::count(), 'adding an item must not spend money');
        $this->assertEquals(4500, EquipmentItem::first()->unit_price, 'but the value is still recorded');
    }
}
