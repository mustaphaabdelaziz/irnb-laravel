<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorageLocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'privileges' => ['admin']]);
    }

    #[Test]
    public function an_admin_can_create_a_storage_location(): void
    {
        $this->actingAs($this->admin())
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertRedirect();

        $this->assertDatabaseHas('storage_locations', ['name' => 'Storage 01']);
    }

    #[Test]
    public function location_names_must_be_unique(): void
    {
        StorageLocation::create(['name' => 'Storage 01']);

        $this->actingAs($this->admin())
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, StorageLocation::where('name', 'Storage 01')->count());
    }

    #[Test]
    public function an_admin_can_rename_and_delete_a_location(): void
    {
        $loc = StorageLocation::create(['name' => 'Storage 01']);

        $this->actingAs($this->admin())
            ->put(route('storage-locations.update', $loc), ['name' => 'Storage 02'])
            ->assertRedirect();
        $this->assertDatabaseHas('storage_locations', ['id' => $loc->id, 'name' => 'Storage 02']);

        $this->actingAs($this->admin())
            ->delete(route('storage-locations.destroy', $loc))
            ->assertRedirect();
        $this->assertDatabaseMissing('storage_locations', ['id' => $loc->id]);
    }

    #[Test]
    public function a_non_admin_cannot_manage_locations(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertForbidden();

        $this->assertDatabaseMissing('storage_locations', ['name' => 'Storage 01']);
    }

    #[Test]
    public function renaming_a_storage_place_renames_it_on_its_units(): void
    {
        $loc = StorageLocation::create(['name' => 'Magasin']);
        $catalog = EquipmentCatalog::create(['name' => 'Ballons', 'category' => 'Balls']);
        $mk = fn (string $where) => EquipmentItem::create(['catalog_id' => $catalog->id, 'quantity' => 1, 'condition' => 'New', 'status' => 'Available', 'location' => $where, 'purchase_date' => '2026-01-10']);
        $a = $mk('Magasin');
        $b = $mk('Vestiaire');

        $this->actingAs($this->admin())
            ->put(route('storage-locations.update', $loc), ['name' => 'Magasin central'])
            ->assertRedirect();

        $this->assertSame('Magasin central', $a->fresh()->location);
        $this->assertSame('Vestiaire', $b->fresh()->location);
    }
}
