<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Services\Equipment\EquipmentStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stock-take line used to be a yes/no tick against one physical object.
 * A lot holds many units, so a line records how many were expected and how
 * many were actually found.
 */
class InventoryQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $quantity): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Dossards'], ['category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
        ]);
    }

    private function stocktake(): InventorySession
    {
        return InventorySession::create([
            'reference' => 'INV-TEST-1',
            'type' => 'ad_hoc',
            'session_date' => '2026-07-20',
            'status' => 'in_progress',
        ]);
    }

    #[Test]
    public function a_count_line_records_expected_and_found_quantities(): void
    {
        $line = InventorySessionItem::create([
            'inventory_session_id' => $this->stocktake()->id,
            'equipment_item_id' => $this->lot(50)->id,
            'expected_status' => 'Available',
            'expected_quantity' => 50,
            'found_quantity' => 47,
            'counted' => true,
        ]);

        $line = $line->fresh();
        $this->assertSame(50, $line->expected_quantity);
        $this->assertSame(47, $line->found_quantity);
        $this->assertSame(3, $line->missing_quantity);
    }

    #[Test]
    public function nothing_is_missing_when_every_unit_is_found(): void
    {
        $line = InventorySessionItem::create([
            'inventory_session_id' => $this->stocktake()->id,
            'equipment_item_id' => $this->lot(50)->id,
            'expected_status' => 'Available',
            'expected_quantity' => 50,
            'found_quantity' => 50,
            'counted' => true,
        ]);

        $this->assertSame(0, $line->fresh()->missing_quantity);
    }

    #[Test]
    public function an_uncounted_line_reports_everything_as_missing(): void
    {
        $line = InventorySessionItem::create([
            'inventory_session_id' => $this->stocktake()->id,
            'equipment_item_id' => $this->lot(50)->id,
            'expected_status' => 'Available',
            'expected_quantity' => 50,
            'counted' => false,
        ]);

        $this->assertSame(50, $line->fresh()->missing_quantity);
    }

    #[Test]
    public function finding_most_of_a_lot_condemns_only_the_missing_units(): void
    {
        $lot = $this->lot(50);

        app(EquipmentStockService::class)->writeOffMissing($lot, 3, null);

        $this->assertSame(47, $lot->fresh()->quantity, 'the found units stay in service');
        $this->assertSame('Available', $lot->fresh()->status);

        $lost = EquipmentItem::where('status', 'Lost')->first();
        $this->assertSame(3, $lost->quantity);
        $this->assertSame(50, (int) EquipmentItem::sum('quantity'), 'no units invented or destroyed');
    }

    #[Test]
    public function finding_none_of_a_lot_condemns_all_of_it(): void
    {
        $lot = $this->lot(50);

        app(EquipmentStockService::class)->writeOffMissing($lot, 50, null);

        $this->assertSame('Lost', $lot->fresh()->status);
        $this->assertSame(50, $lot->fresh()->quantity);
        $this->assertSame(1, EquipmentItem::count(), 'no split needed when nothing was found');
    }

    #[Test]
    public function a_serialized_line_defaults_to_one_unit(): void
    {
        $line = InventorySessionItem::create([
            'inventory_session_id' => $this->stocktake()->id,
            'equipment_item_id' => $this->lot(1)->id,
            'expected_status' => 'Available',
        ]);

        $this->assertSame(1, $line->fresh()->expected_quantity);
    }
}
