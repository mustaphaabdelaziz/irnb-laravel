<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentLotModelTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): EquipmentCatalog
    {
        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
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
}
