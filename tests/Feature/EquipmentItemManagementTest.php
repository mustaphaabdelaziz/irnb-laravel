<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentItemManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
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
}
