<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function item(EquipmentCatalog $catalog, string $identifier, array $attributes = []): EquipmentItem
    {
        return EquipmentItem::create(array_merge([
            'catalog_id' => $catalog->id,
            'unique_identifier' => $identifier,
            'purchase_date' => '2026-07-01',
            'status' => 'Available',
            'condition' => 'Good',
        ], $attributes));
    }

    #[Test]
    public function selected_materials_can_be_deleted_together(): void
    {
        $first = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $second = EquipmentCatalog::create(['name' => 'Bibs', 'category' => 'Apparel']);
        $kept = EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Balls']);
        $this->item($first, 'CONE-001');
        $this->item($second, 'BIB-001');

        $this->actingAs($this->user())
            ->post(route('equipment.catalogs.bulk-destroy'), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('equipment.catalogs.index'))
            ->assertSessionHas('success', [
                'key' => 'flash.equipment_catalogs_deleted',
                'params' => ['count' => 2],
            ]);

        $this->assertDatabaseMissing('equipment_catalogs', ['id' => $first->id]);
        $this->assertDatabaseMissing('equipment_catalogs', ['id' => $second->id]);
        $this->assertDatabaseHas('equipment_catalogs', ['id' => $kept->id]);
    }

    #[Test]
    public function selected_material_items_and_their_history_can_be_deleted_together(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Radios', 'category' => 'Accessories']);
        $first = $this->item($catalog, 'RADIO-001');
        $second = $this->item($catalog, 'RADIO-002');
        $kept = $this->item($catalog, 'RADIO-003');

        EquipmentHistory::create([
            'item_id' => $first->id,
            'event_type' => 'Purchase',
            'details' => [],
            'event_timestamp' => now(),
        ]);

        $this->actingAs($this->user())
            ->post(route('equipment.items.bulk-destroy'), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('equipment.catalogs.show', $catalog))
            ->assertSessionHas('success', [
                'key' => 'flash.equipment_items_deleted',
                'params' => ['count' => 2],
            ]);

        $this->assertDatabaseMissing('equipment_items', ['id' => $first->id]);
        $this->assertDatabaseMissing('equipment_items', ['id' => $second->id]);
        $this->assertDatabaseMissing('equipment_histories', ['item_id' => $first->id]);
        $this->assertDatabaseHas('equipment_items', ['id' => $kept->id]);
    }

    #[Test]
    public function a_bulk_item_delete_is_refused_when_the_selection_contains_a_rented_item(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'GPS vests', 'category' => 'Training']);
        $available = $this->item($catalog, 'GPS-001');
        $rented = $this->item($catalog, 'GPS-002', ['status' => 'Rented']);
        EquipmentRental::create([
            'equipment_item_id' => $rented->id,
            'rentable_type' => 'Player',
            'rentable_id' => 1,
            'checkout_date' => now(),
        ]);

        $this->actingAs($this->user())
            ->post(route('equipment.items.bulk-destroy'), [
                'ids' => [$available->id, $rented->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.bulk_items_include_rented');

        $this->assertDatabaseHas('equipment_items', ['id' => $available->id]);
        $this->assertDatabaseHas('equipment_items', ['id' => $rented->id]);
    }
}
