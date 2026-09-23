<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Services\Equipment\EquipmentStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentLotModelTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600000;

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    private function stock(): EquipmentStockService
    {
        return app(EquipmentStockService::class);
    }

    private function lot(int $quantity, string $status = 'Available'): EquipmentItem
    {
        return EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
            'status' => $status,
        ]);
    }

    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Ali',
            'lastname' => 'B',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ]);
    }

    private function rent(EquipmentItem $item, int $quantity, int $returned = 0): EquipmentRental
    {
        return EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => now(),
            'quantity' => $quantity,
            'returned_quantity' => $returned,
        ]);
    }

    #[Test]
    public function an_item_defaults_to_a_lot_of_one(): void
    {
        $item = EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'unique_identifier' => 'IRNB-2026-BALL-00001',
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame('purchase', $item->fresh()->received_via);
    }

    #[Test]
    public function a_lot_can_hold_many_units_without_a_serial(): void
    {
        $item = EquipmentItem::create([
            'catalog_id' => $this->catalog()->id,
            'unique_identifier' => null,
            'purchase_date' => '2026-01-01',
            'quantity' => 20,
        ]);

        $this->assertSame(20, $item->fresh()->quantity);
        $this->assertNull($item->fresh()->unique_identifier);
    }

    #[Test]
    public function new_catalogs_default_to_count_tracking(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        $this->assertFalse($catalog->fresh()->requires_serial);
    }

    #[Test]
    public function availability_subtracts_open_rentals(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 10);
        $this->rent($lot, 3);

        $this->assertSame(7, $this->stock()->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function availability_counts_partially_returned_units_as_back(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 10, returned: 6);

        $this->assertSame(16, $this->stock()->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function a_closed_rental_frees_the_units(): void
    {
        $lot = $this->lot(20);
        $rental = $this->rent($lot, 10);
        $rental->update(['return_date' => now(), 'returned_quantity' => 10]);

        $this->assertSame(20, $this->stock()->availableQuantity($lot->fresh()));
    }

    #[Test]
    public function a_lot_level_disposition_zeroes_availability(): void
    {
        foreach (['Under Repair', 'Lost', 'Retired', 'Out of Service'] as $status) {
            $lot = $this->lot(20, $status);

            $this->assertSame(0, $this->stock()->availableQuantity($lot), "status {$status} should zero availability");
        }
    }

    #[Test]
    public function a_serialized_item_reduces_to_the_old_behaviour(): void
    {
        $item = $this->lot(1);

        $this->assertSame(1, $this->stock()->availableQuantity($item));

        $this->rent($item, 1);
        $item->update(['status' => 'Rented']);

        $this->assertSame(0, $this->stock()->availableQuantity($item->fresh()));
    }

    #[Test]
    public function catalog_counts_are_measured_in_units_not_rows(): void
    {
        $catalog = $this->catalog();

        EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20]);
        EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 5, 'condition' => 'Damaged']);

        $catalog = $catalog->fresh();

        $this->assertSame(25, $catalog->total_quantity);
        $this->assertSame(25, $catalog->available_count);
    }

    #[Test]
    public function rented_units_leave_the_catalog_available_count(): void
    {
        $catalog = $this->catalog();
        $lot = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20]);
        $this->rent($lot, 8);

        $this->assertSame(20, $catalog->fresh()->total_quantity);
        $this->assertSame(12, $catalog->fresh()->available_count);
    }

    #[Test]
    public function splitting_a_lot_moves_units_into_a_new_row(): void
    {
        $lot = $this->lot(20);

        $damaged = $this->stock()->splitLot($lot, 3, 'Damaged', null, 'punctured');

        $this->assertSame(17, $lot->fresh()->quantity);
        $this->assertSame(3, $damaged->quantity);
        $this->assertSame('Damaged', $damaged->condition);
        $this->assertSame($lot->catalog_id, $damaged->catalog_id);
    }

    #[Test]
    public function a_split_preserves_the_total_unit_count(): void
    {
        $lot = $this->lot(20);

        $this->stock()->splitLot($lot, 3, 'Damaged', null, null);

        $this->assertSame(20, (int) EquipmentItem::where('catalog_id', $lot->catalog_id)->sum('quantity'));
    }

    #[Test]
    public function a_split_cannot_exceed_available_units(): void
    {
        $lot = $this->lot(20);
        $this->rent($lot, 18);

        // Units out on rental are not in your hands to inspect or reclassify.
        $this->expectException(\InvalidArgumentException::class);

        $this->stock()->splitLot($lot->fresh(), 5, 'Damaged', null, null);
    }

    #[Test]
    public function a_split_carries_the_branch_tags_over(): void
    {
        $branch = Branch::create(['name' => 'Football', 'name_en' => 'Football']);
        $lot = $this->lot(20);
        $lot->branches()->sync([$branch->id]);

        $damaged = $this->stock()->splitLot($lot, 3, 'Damaged', null, null);

        $this->assertCount(1, $damaged->branches);
    }

    #[Test]
    public function splitting_records_history_on_both_lots(): void
    {
        $lot = $this->lot(20);

        $damaged = $this->stock()->splitLot($lot, 3, 'Damaged', null, null);

        $this->assertDatabaseHas('equipment_histories', ['item_id' => $lot->id, 'event_type' => 'Split Out']);
        $this->assertDatabaseHas('equipment_histories', ['item_id' => $damaged->id, 'event_type' => 'Split In']);
    }

    #[Test]
    public function availability_never_goes_negative(): void
    {
        $lot = $this->lot(5);
        $this->rent($lot, 5);
        // A stray over-issue must clamp at zero rather than report -3.
        $this->rent($lot, 3);

        $this->assertSame(0, $this->stock()->availableQuantity($lot->fresh()));
    }
}
