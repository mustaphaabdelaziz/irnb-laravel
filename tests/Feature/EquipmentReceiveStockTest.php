<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Transaction;
use App\Models\User;
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
