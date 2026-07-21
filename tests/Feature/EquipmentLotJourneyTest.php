<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole journey, driven through the HTTP routes a user actually hits.
 *
 * This is the scenario the lot model exists for: 100 dossards as one row,
 * issued in part, returned in part, some reclassified as damaged, a second
 * batch received at a different price — with the books untouched unless the
 * expense box is ticked.
 */
class EquipmentLotJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(?Branch $branch = null): Player
    {
        $player = Player::create([
            'firstname' => 'Karim',
            'lastname' => 'B',
            'membership_id' => (string) random_int(202600001, 202699999),
            'join_year' => 2026,
        ]);

        if ($branch) {
            $player->branches()->sync([$branch->id]);
        }

        return $player;
    }

    #[Test]
    public function a_club_can_run_counted_stock_end_to_end(): void
    {
        $admin = $this->user();
        $football = Branch::create(['name' => 'Football', 'name_en' => 'Football']);

        // 1. A count-tracked catalog — no serials demanded.
        $this->actingAs($admin)->post(route('equipment.catalogs.store'), [
            'name' => 'Dossards rouges',
            'category' => 'Apparel',
            'requires_serial' => false,
        ])->assertRedirect();

        $catalog = EquipmentCatalog::first();
        $this->assertFalse($catalog->requires_serial);

        // 2. Receive 100 as a purchase — one row, one expense.
        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 100,
            'unit_price' => 120,
            'purchase_date' => '2026-03-15',
            'condition' => 'New',
            'record_expense' => true,
            'branch_ids' => [$football->id],
        ])->assertRedirect();

        $this->assertSame(1, EquipmentItem::count(), '100 dossards is one row, not one hundred');
        $this->assertSame(1, Transaction::count());
        $this->assertEquals(12000, Transaction::first()->amount);
        $this->assertSame(100, $catalog->fresh()->total_quantity);

        // 3. Receive 50 more as a donation — no finance record.
        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 50,
            'unit_price' => 135,
            'purchase_date' => '2026-06-01',
            'condition' => 'New',
            'record_expense' => false,
            'received_via' => 'donation',
        ])->assertRedirect();

        $this->assertSame(1, Transaction::count(), 'a donation must not spend money');
        $this->assertSame(150, $catalog->fresh()->total_quantity);

        // 4. Issue 10 to a player.
        $lot = EquipmentItem::orderBy('id')->first();
        $player = $this->player($football);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'Player',
            'rentable_id' => $player->id,
            'type' => 'rental',
            'quantity' => 10,
            'checkout_date' => '2026-07-01',
            'due_date' => '2026-07-31',
        ])->assertRedirect();

        $this->assertSame(140, $catalog->fresh()->available_count);
        $this->assertSame('Available', $lot->fresh()->status, 'the lot is not wholly rented');

        // 5. Return 6 of the 10 — the other 4 stay out.
        $rental = $lot->fresh()->activeRental;

        $this->actingAs($admin)->post(route('equipment.rentals.return', $rental->id), [
            'quantity' => 6,
            'condition' => 'Good',
        ])->assertRedirect();

        $this->assertNull($rental->fresh()->return_date, 'still open with 4 outstanding');
        $this->assertSame(146, $catalog->fresh()->available_count);

        // 6. Reclassify 3 as damaged.
        $this->actingAs($admin)->post(route('equipment.stock.split', $lot->id), [
            'quantity' => 3,
            'condition' => 'Damaged',
            'notes' => 'torn',
        ])->assertRedirect();

        $this->assertSame(150, (int) EquipmentItem::sum('quantity'), 'no units invented or destroyed');
        $this->assertSame(3, EquipmentItem::where('condition', 'Damaged')->sum('quantity'));

        // 7. The report agrees with all of it.
        $props = $this->actingAs($admin)->get(route('equipment.inventory'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(150, $props['summary']['total']);
        $this->assertSame(4, $props['summary']['rented'], '4 units still out');
        $this->assertSame(146, $props['summary']['available']);

        // 100 x 120 + 50 x 135 = 18 750, whatever the lots were split into.
        $this->assertEqualsWithDelta(18750, $props['totalValue'], 0.01);
    }

    #[Test]
    public function serialized_equipment_still_behaves_exactly_as_before(): void
    {
        $admin = $this->user();

        $catalog = EquipmentCatalog::create([
            'name' => 'GPS Vest',
            'category' => 'Training Equipment',
            'requires_serial' => true,
        ]);

        $this->actingAs($admin)->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-10',
            'condition' => 'New',
            'purchase_price' => 45000,
        ])->assertRedirect();

        $item = EquipmentItem::first();
        $this->assertNotNull($item->unique_identifier, 'a serial is still generated');
        $this->assertSame(1, $item->quantity);

        $player = $this->player();

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $item->id,
            'rentable_type' => 'Player',
            'rentable_id' => $player->id,
            'type' => 'rental',
            'quantity' => 1,
            'due_date' => '2026-12-31',
        ])->assertRedirect();

        // The single-unit lot still flips status, as the old code did.
        $this->assertSame('Rented', $item->fresh()->status);
        $this->assertSame(0, $catalog->fresh()->available_count);

        $this->actingAs($admin)->post(route('equipment.rentals.return', $item->fresh()->activeRental->id), [
            'condition' => 'Fair',
        ])->assertRedirect();

        $this->assertSame('Available', $item->fresh()->status);
        $this->assertSame('Fair', $item->fresh()->condition);
        $this->assertSame(1, $catalog->fresh()->available_count);
    }

    #[Test]
    public function equipment_can_be_rented_to_an_external_person(): void
    {
        $admin = $this->user();
        $catalog = EquipmentCatalog::create(['name' => 'Radio', 'category' => 'Accessories']);

        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 4,
            'purchase_date' => '2026-01-01',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertRedirect();

        $lot = EquipmentItem::first();

        // The recipient can be someone from outside the club — captured as
        // free text, not a Player or an account.
        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'External',
            'external_name' => 'Karim Visitor',
            'external_phone' => '0555 12 34 56',
            'type' => 'rental',
            'quantity' => 1,
            'expected_days' => 7,
            'checkout_date' => '2026-03-01',
        ])->assertRedirect();

        $rental = $lot->fresh()->activeRental;
        $this->assertNull($rental->rentable_id, 'an external person has no id');
        $this->assertSame('Karim Visitor', $rental->external_name);
        $this->assertSame('0555 12 34 56', $rental->external_phone);
        $this->assertSame('Karim Visitor', $rental->recipient_name);
        // Expected period drives the due date (7 days after checkout).
        $this->assertSame('2026-03-08', $rental->due_date->toDateString());
    }

    #[Test]
    public function an_external_rental_requires_a_name(): void
    {
        $admin = $this->user();
        $catalog = EquipmentCatalog::create(['name' => 'Cone', 'category' => 'Training Equipment']);
        $lot = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 5]);

        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'External',
            'type' => 'rental',
            'quantity' => 1,
        ])->assertSessionHasErrors('external_name');
    }

    #[Test]
    public function issuing_more_than_available_is_refused_with_a_message(): void
    {
        $admin = $this->user();
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training Equipment']);

        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id,
            'quantity' => 5,
            'purchase_date' => '2026-01-01',
            'condition' => 'New',
            'record_expense' => false,
        ])->assertRedirect();

        $lot = EquipmentItem::first();

        // A rejected quantity must surface as a flash, not a 500.
        $this->actingAs($admin)->post(route('equipment.items.rent'), [
            'equipment_item_id' => $lot->id,
            'rentable_type' => 'Player',
            'rentable_id' => $this->player()->id,
            'type' => 'rental',
            'quantity' => 9,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(5, $catalog->fresh()->available_count, 'nothing was issued');
    }
}
