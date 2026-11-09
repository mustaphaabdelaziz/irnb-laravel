<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Services\Pdf\ClubHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentStocktakePdfTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_stocktake_pdf_prints_conditions_in_the_readers_language(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Ball', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 1, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-1', 'type' => 'ad_hoc', 'session_date' => '2026-10-01', 'status' => 'completed']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 1,
            'counted' => true, 'found' => true, 'found_quantity' => 1, 'actual_condition' => 'Damaged',
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $this->assertStringContainsString('<td>جيد</td>', $html);
        $this->assertStringContainsString('<td>تالف</td>', $html);
        $this->assertStringNotContainsString('Damaged', $html);
    }
}
