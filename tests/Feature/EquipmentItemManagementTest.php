<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\StorageLocation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentItemManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function catalog(): EquipmentCatalog
    {
        // 'Balls' is a seeded category (code 'BALL' after the code migration);
        // a matching catalog lets the serial generator resolve a code.
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    #[Test]
    public function storing_an_item_persists_its_designation(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
            'designation' => 'T-shirt n° 10',
        ])->assertRedirect();

        $this->assertSame('T-shirt n° 10', EquipmentItem::sole()->designation);
    }

    #[Test]
    public function designation_is_optional(): void
    {
        $catalog = $this->catalog();

        $this->actingAs($this->user())->post(route('equipment.items.store'), [
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-05-01',
            'condition' => 'New',
        ])->assertRedirect();

        $item = EquipmentItem::sole();
        $this->assertNull($item->designation);
        $this->assertNotNull($item->unique_identifier);
    }

    private function item(EquipmentCatalog $catalog, array $attrs = []): EquipmentItem
    {
        return EquipmentItem::create(array_merge([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'IRNB-2026-BALL-00001',
            'purchase_date' => '2026-05-01',
            'status' => 'Available',
            'condition' => 'New',
        ], $attrs));
    }

    #[Test]
    public function updating_an_item_changes_editable_fields_but_not_the_serial(): void
    {
        $item = $this->item($this->catalog(), ['designation' => 'old']);

        $this->actingAs($this->user())->put(route('equipment.items.update', $item), [
            'designation' => 'T-shirt n° 9',
            'purchase_date' => '2026-06-15',
            'condition' => 'Good',
            'location' => 'Locker A',
            'notes' => 'hem repaired',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('T-shirt n° 9', $item->designation);
        $this->assertSame('Good', $item->condition);
        $this->assertSame('Locker A', $item->location);
        $this->assertSame('hem repaired', $item->notes);
        $this->assertSame('2026-06-15', $item->purchase_date->toDateString());
        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function updating_an_item_ignores_injected_immutable_fields(): void
    {
        $item = $this->item($this->catalog());

        $this->actingAs($this->user())->put(route('equipment.items.update', $item), [
            'designation' => 'T-shirt n° 9',
            'purchase_date' => '2026-06-15',
            'condition' => 'Good',
            'location' => 'Locker A',
            'notes' => 'hem repaired',
            'unique_identifier' => 'HACKED-999',
            'status' => 'Retired',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
        $this->assertSame('Available', $item->status);
        $this->assertSame('T-shirt n° 9', $item->designation);
    }

    #[Test]
    public function deleting_an_item_removes_it_and_its_history_but_keeps_the_purchase_transaction(): void
    {
        $catalog = $this->catalog();
        $transaction = Transaction::create([
            'amount' => 100,
            'transaction_date' => '2026-05-01',
            'transaction_type' => 'expense',
            'category' => 'equipment',
            'description' => 'Equipment purchase: IRNB-2026-BALL-00001',
            'status' => 'Paid',
            'fiscal_year' => 2026,
        ]);
        $item = $this->item($catalog, ['purchase_transaction_id' => $transaction->id]);
        EquipmentHistory::create([
            'item_id' => $item->id,
            'event_type' => 'Purchase',
            'details' => [],
            'event_timestamp' => now(),
        ]);

        $this->actingAs($this->user())->delete(route('equipment.items.destroy', $item))->assertRedirect();

        $this->assertDatabaseMissing('equipment_items', ['id' => $item->id]);
        $this->assertSame(0, EquipmentHistory::where('item_id', $item->id)->count());
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    #[Test]
    public function deleting_a_rented_item_is_refused(): void
    {
        $catalog = $this->catalog();
        $item = $this->item($catalog, ['status' => 'Rented']);
        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => 'Player',
            'rentable_id' => 1,
            'checkout_date' => now(),
        ]);

        $this->actingAs($this->user())->delete(route('equipment.items.destroy', $item))->assertRedirect();

        $this->assertDatabaseHas('equipment_items', ['id' => $item->id]);
    }

    #[Test]
    public function deleting_an_item_removes_its_inventory_session_line(): void
    {
        $item = $this->item($this->catalog());
        $sessionId = DB::table('inventory_sessions')->insertGetId([
            'reference' => 'INV-2026-0001',
            'session_date' => '2026-05-01',
            'status' => 'in_progress',
            'total_expected' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_session_items')->insert([
            'inventory_session_id' => $sessionId,
            'equipment_item_id' => $item->id,
            'expected_status' => 'Available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user())->delete(route('equipment.items.destroy', $item))->assertRedirect();

        $this->assertDatabaseMissing('inventory_session_items', ['equipment_item_id' => $item->id]);
    }

    #[Test]
    public function a_lost_item_can_be_restored_to_available(): void
    {
        $item = $this->item($this->catalog(), ['status' => 'Lost']);

        $this->actingAs($this->user())
            ->post(route('equipment.items.mark-found', $item))
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('Available', $item->status);
        $this->assertSame(1, EquipmentHistory::where('item_id', $item->id)->where('event_type', 'Found')->count());
    }

    #[Test]
    public function an_item_under_repair_can_be_marked_fixed(): void
    {
        $item = $this->item($this->catalog(), ['status' => 'Under Repair']);

        $this->actingAs($this->user())
            ->post(route('equipment.items.complete-repair', $item), ['condition' => 'Good'])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('Available', $item->status);
        $this->assertSame('Good', $item->condition);
    }

    #[Test]
    public function the_catalog_page_exposes_players_for_the_rent_dropdown(): void
    {
        $catalog = $this->catalog();
        \App\Models\Player::create(['firstname' => 'Ali', 'lastname' => 'B', 'membership_id' => '202400009', 'join_year' => 2024]);

        $this->actingAs($this->user())
            ->get(route('equipment.catalogs.show', $catalog))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Equipment/Catalog/Show')
                ->has('players', 1, fn (AssertableInertia $p) => $p
                    ->where('membership_id', '202400009')
                    ->has('fullname')
                    ->has('id')
                    ->etc()));
    }

    #[Test]
    public function restoring_a_non_lost_item_is_refused(): void
    {
        $item = $this->item($this->catalog(), ['status' => 'Available']);

        $this->actingAs($this->user())
            ->post(route('equipment.items.mark-found', $item));

        $item->refresh();
        $this->assertSame('Available', $item->status);
        $this->assertSame(0, EquipmentHistory::where('item_id', $item->id)->where('event_type', 'Found')->count());
    }

    #[Test]
    public function the_catalog_page_exposes_storage_locations(): void
    {
        StorageLocation::create(['name' => 'Storage 01']);
        StorageLocation::create(['name' => 'Storage 02']);
        $catalog = $this->catalog();

        $this->actingAs($this->user())
            ->get(route('equipment.catalogs.show', $catalog))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Equipment/Catalog/Show')
                ->where('storageLocations', ['Storage 01', 'Storage 02']));
    }
}
