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

    #[Test]
    public function a_partly_found_line_prints_found_of_expected(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 38, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-2', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'completed', 'total_expected' => 40, 'total_found' => 38, 'total_missing' => 2]);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38, 'note' => 'تحت المدرج',
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $this->assertStringContainsString('38 / 40', $html);
        $this->assertStringContainsString('تحت المدرج', $html);
    }

    #[Test]
    public function an_old_found_line_without_a_quantity_prints_every_unit_found(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 50, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-4', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'completed']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 50,
            'counted' => true, 'found' => true, 'found_quantity' => null, 'actual_condition' => 'Damaged',
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $this->assertStringContainsString('50 / 50', $html);
        $this->assertStringNotContainsString('0 / 50', str_replace('50 / 50', '', $html));
        $this->assertStringNotContainsString('class="partial"', $html);
    }

    #[Test]
    public function a_partly_found_line_uses_the_partial_colour(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 40, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-5', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'completed']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38,
        ]);

        app()->setLocale('ar');
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
        $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $session])->render();

        $this->assertStringContainsString('<td class="partial">', $html);
    }

    #[Test]
    public function the_arabic_stocktake_pdf_has_no_english_words(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'كرة', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 1, 'condition' => 'Good']);
        $session = InventorySession::create(['reference' => 'INV-3', 'type' => 'ad_hoc', 'session_date' => '2026-10-01', 'status' => 'in_progress']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 1,
            'counted' => true, 'found' => false, 'found_quantity' => 0,
        ]);

        $done = InventorySession::create(['reference' => 'INV-6', 'type' => 'monthly', 'session_date' => '2026-10-02', 'status' => 'completed']);
        InventorySessionItem::create([
            'inventory_session_id' => $done->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_condition' => 'Good', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38,
        ]);

        app()->setLocale('ar');
        $text = '';
        foreach ([$session, $done] as $s) {
            $s->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
            $html = view('pdf.inventory-report', ['club' => ClubHeader::data(), 'session' => $s])->render();
            if ($s->is($done)) {
                $this->assertStringContainsString('38 / 40', $html);
            }
            $text .= ' '.html_entity_decode(strip_tags(preg_replace('#<(style|script)\b.*?</\1>#s', '', $html)));
        }
        $text = preg_replace('/INV-\d+|\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?/', '', $text);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', $text, 'English left in: '.$text);
    }
}
