<?php

namespace Tests\Feature;

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
    public function availability_never_goes_negative(): void
    {
        $lot = $this->lot(5);
        $this->rent($lot, 5);
        // A stray over-issue must clamp at zero rather than report -3.
        $this->rent($lot, 3);

        $this->assertSame(0, $this->stock()->availableQuantity($lot->fresh()));
    }
}
