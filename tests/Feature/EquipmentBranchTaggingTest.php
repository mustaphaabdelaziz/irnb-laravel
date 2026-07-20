<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentBranchTaggingTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $quantity = 50): EquipmentItem
    {
        $catalog = EquipmentCatalog::firstOrCreate(['name' => 'Dossards'], ['category' => 'Apparel']);

        return EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => $quantity,
        ]);
    }

    private function branch(string $name): Branch
    {
        return Branch::create(['name' => $name, 'name_en' => $name]);
    }

    #[Test]
    public function a_lot_can_belong_to_several_branches(): void
    {
        $lot = $this->lot();

        $lot->branches()->sync([$this->branch('Football')->id, $this->branch('Basketball')->id]);

        $this->assertCount(2, $lot->fresh()->branches);
    }

    #[Test]
    public function filtering_by_branch_includes_club_wide_lots(): void
    {
        $football = $this->branch('Football');

        $tagged = $this->lot();
        $tagged->branches()->sync([$football->id]);

        // No branches at all: club-wide gear, usable by every branch.
        $clubWide = $this->lot();

        $ids = EquipmentItem::forBranch($football->id)->pluck('id');

        $this->assertTrue($ids->contains($tagged->id), 'tagged lot must appear');
        $this->assertTrue($ids->contains($clubWide->id), 'untagged lot is club-wide and must appear');
    }

    #[Test]
    public function filtering_by_branch_excludes_lots_tagged_to_another_branch(): void
    {
        $football = $this->branch('Football');
        $basketball = $this->branch('Basketball');

        $footballLot = $this->lot();
        $footballLot->branches()->sync([$football->id]);

        $ids = EquipmentItem::forBranch($basketball->id)->pluck('id');

        $this->assertFalse($ids->contains($footballLot->id), 'another branch\'s gear must not appear');
    }

    #[Test]
    public function no_branch_filter_returns_everything(): void
    {
        $tagged = $this->lot();
        $tagged->branches()->sync([$this->branch('Football')->id]);
        $this->lot();

        $this->assertCount(2, EquipmentItem::forBranch(null)->get());
    }

    #[Test]
    public function the_catalog_page_exposes_branches_users_and_unit_totals(): void
    {
        $lot = $this->lot(50);
        $lot->branches()->sync([$this->branch('Football')->id]);

        $user = User::factory()->admin()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get(route('equipment.catalogs.show', $lot->catalog_id));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame(50, $props['totalQuantity']);
        $this->assertSame(50, $props['availableCount']);
        $this->assertNotEmpty($props['branches'], 'branch picker needs options');
        // Equipment can be assigned to staff, not only lent to players.
        $this->assertNotEmpty($props['users'], 'staff must be assignable');
        $this->assertSame(50, $props['catalog']['items'][0]['available_quantity']);
    }

    #[Test]
    public function deleting_a_branch_unties_its_equipment_without_deleting_it(): void
    {
        $branch = $this->branch('Football');
        $lot = $this->lot();
        $lot->branches()->sync([$branch->id]);

        $branch->delete();

        $this->assertNotNull($lot->fresh(), 'the equipment must survive its branch');
        $this->assertCount(0, $lot->fresh()->branches);
    }
}
