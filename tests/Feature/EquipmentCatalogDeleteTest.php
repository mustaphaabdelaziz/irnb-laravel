<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCatalogDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    /** A catalog with one lot of 5, 2 of them out on an open rental. */
    private function catalogWithUnitsOut(): EquipmentCatalog
    {
        $catalog = EquipmentCatalog::create(['name' => 'Ballons', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'quantity' => 5, 'condition' => 'New', 'status' => 'Available', 'purchase_date' => '2026-01-10']);
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'B', 'membership_id' => '1', 'join_year' => 2026]);
        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $player->id,
            'checkout_date' => now(),
            'quantity' => 2,
        ]);

        return $catalog;
    }

    #[Test]
    public function a_catalog_with_units_out_cannot_be_deleted(): void
    {
        $catalog = $this->catalogWithUnitsOut();

        $this->actingAs($this->admin())->delete(route('equipment.catalogs.destroy', $catalog))
            ->assertSessionHas('error', 'flash.equipment_catalog_units_out');

        $this->assertModelExists($catalog);
    }

    #[Test]
    public function a_bulk_delete_containing_such_a_catalog_deletes_nothing(): void
    {
        $out = $this->catalogWithUnitsOut();
        $free = EquipmentCatalog::create(['name' => 'Cônes', 'category' => 'Training Equipment']);

        $this->actingAs($this->admin())->post(route('equipment.catalogs.bulk-destroy'), ['ids' => [$out->id, $free->id]])
            ->assertSessionHas('error', 'flash.equipment_catalog_units_out');

        $this->assertModelExists($out);
        $this->assertModelExists($free);
    }

    #[Test]
    public function a_catalog_whose_units_are_all_back_can_be_deleted(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Chasubles', 'category' => 'Apparel']);

        $this->actingAs($this->admin())->delete(route('equipment.catalogs.destroy', $catalog))
            ->assertRedirect(route('equipment.catalogs.index'));

        $this->assertModelMissing($catalog);
    }
}
