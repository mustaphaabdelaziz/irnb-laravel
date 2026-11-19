<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
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

        $csv = $this->actingAs($admin)->get(route('inventory.export', [$session, 'format' => 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('38', $csv);
        $this->assertStringContainsString('40', $csv);
        $this->assertStringContainsString('تحت المدرج', $csv);
    }
}
