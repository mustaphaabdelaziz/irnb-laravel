<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_equipment_item_history_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $catalog = EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'BALL-001',
            'purchase_date' => now(),
            'status' => 'Available',
            'condition' => 'New',
        ]);

        EquipmentHistory::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'event_type' => 'Condition Update',
            'details' => ['condition' => 'New'],
            'event_timestamp' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('equipment.items.history', $item))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Equipment/History')
                ->where('item.unique_identifier', 'BALL-001')
                ->has('history', 1)
                ->where('history.0.event_type', 'condition_update'));
    }
}
