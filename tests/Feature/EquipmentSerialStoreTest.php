<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentSerialStoreTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): EquipmentCatalog
    {
        $config = WebsiteConfig::singleton();
        $config->club_short_name = 'IRNB';
        $config->save();

        // updateOrCreate (not create): the equipment_categories migration
        // seeds default categories (including 'Balls'), so a plain create()
        // would collide with a unique-constraint violation on name.
        EquipmentCategory::updateOrCreate(['name' => 'Balls'], ['code' => 'BALL']);

        return EquipmentCatalog::create(['name' => 'Match Ball', 'category' => 'Balls']);
    }

    #[Test]
    public function storing_an_item_generates_the_serial_and_ignores_any_client_identifier(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        $this->actingAs($user)
            ->post(route('equipment.items.store'), [
                'catalog_id' => $catalog->id,
                'purchase_date' => '2026-05-01',
                'condition' => 'New',
                'unique_identifier' => 'HACKED-999', // must be ignored
            ])
            ->assertRedirect();

        $item = EquipmentItem::sole();
        $this->assertSame('IRNB-2026-BALL-00001', $item->unique_identifier);
    }

    #[Test]
    public function storing_a_second_item_increments_the_counter(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        foreach (['2026-05-01', '2026-06-01'] as $date) {
            $this->actingAs($user)->post(route('equipment.items.store'), [
                'catalog_id' => $catalog->id,
                'purchase_date' => $date,
                'condition' => 'New',
            ]);
        }

        $this->assertSame(
            ['IRNB-2026-BALL-00001', 'IRNB-2026-BALL-00002'],
            EquipmentItem::orderBy('id')->pluck('unique_identifier')->all(),
        );
    }

    #[Test]
    public function the_preview_endpoint_returns_the_next_serial(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $catalog = $this->seedCatalog();

        $this->actingAs($user)
            ->getJson(route('equipment.items.preview-serial', [
                'catalog_id' => $catalog->id,
                'purchase_date' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertJson(['serial' => 'IRNB-2026-BALL-00001']);
    }
}
