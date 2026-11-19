<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StocktakeExportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_export_has_found_and_expected_numbers_and_the_note(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 40]);
        $session = InventorySession::create(['reference' => 'INV-9', 'type' => 'monthly', 'session_date' => '2026-10-01', 'status' => 'in_progress']);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $item->id,
            'expected_status' => 'Available', 'expected_quantity' => 40,
            'counted' => true, 'found' => true, 'found_quantity' => 38, 'note' => 'تحت المدرج',
        ]);

        $other = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 5]);
        InventorySessionItem::create([
            'inventory_session_id' => $session->id, 'equipment_item_id' => $other->id,
            'expected_status' => 'Available', 'expected_quantity' => 5, 'counted' => false,
        ]);

        $csv = $this->actingAs($admin)->get(route('inventory.export', [$session, 'format' => 'csv']))
            ->assertOk()->streamedContent();

        $lines = array_values(array_filter(preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
        // First line is the title row, then the headers, then one row per line.
        $header = str_getcsv($lines[1]);
        $foundCol = array_search(UiLang::get('col.found_quantity'), $header, true);
        $expectedCol = array_search(UiLang::get('col.expected_quantity'), $header, true);
        $this->assertNotFalse($foundCol);
        $this->assertNotFalse($expectedCol);

        $rows = array_map('str_getcsv', array_slice($lines, 2));
        $row = collect($rows)->first(fn ($r) => in_array('تحت المدرج', $r, true));
        $uncounted = collect($rows)->first(fn ($r) => ! in_array('تحت المدرج', $r, true));
        $this->assertSame('38', $row[$foundCol]);
        $this->assertSame('40', $row[$expectedCol]);
        $this->assertContains('تحت المدرج', $row);

        $this->assertSame('', $uncounted[$foundCol]);
        $this->assertSame('5', $uncounted[$expectedCol]);
    }
}
