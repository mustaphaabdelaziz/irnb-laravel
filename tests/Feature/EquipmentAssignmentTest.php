<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function lot(int $quantity = 10): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Training bibs', 'category' => 'Apparel']);

        return EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => $quantity]);
    }

    private function player(string $membership = '202600001'): Player
    {
        return Player::create(['firstname' => 'Ali', 'lastname' => 'B', 'membership_id' => $membership, 'join_year' => 2026]);
    }

    private function open(EquipmentItem $lot, array $attributes): EquipmentRental
    {
        return EquipmentRental::create(array_merge([
            'equipment_item_id' => $lot->id,
            'checkout_date' => now(),
            'type' => 'rental',
            'quantity' => 1,
        ], $attributes));
    }

    #[Test]
    public function equipment_cannot_be_assigned_to_an_external_person(): void
    {
        $this->actingAs($this->admin())->post(route('equipment.items.rent'), [
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => 'External',
            'external_name' => 'Karim Visitor',
            'type' => 'assignment',
            'quantity' => 1,
        ])->assertSessionHasErrors('rentable_type');

        $this->assertSame(0, EquipmentRental::count());
    }

    #[Test]
    public function a_player_can_be_assigned_work_equipment_without_a_due_date(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('equipment.items.rent'), [
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => 'Player',
            'rentable_id' => $player->id,
            'type' => 'assignment',
            'quantity' => 2,
            'expected_days' => 10,
        ])->assertSessionHasNoErrors();

        $rental = EquipmentRental::firstOrFail();
        $this->assertSame('assignment', $rental->type);
        $this->assertNull($rental->due_date);
    }

    #[Test]
    public function the_catalog_page_lists_every_holder_of_a_lot(): void
    {
        $lot = $this->lot();
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player()->id, 'quantity' => 3, 'type' => 'assignment']);
        $this->open($lot, ['external_name' => 'Karim Visitor', 'quantity' => 2]);

        $props = $this->actingAs($this->admin())->get(route('equipment.catalogs.show', $lot->catalog_id))
            ->assertOk()->viewData('page')['props'];

        $holders = collect($props['catalog']['items'][0]['open_rentals']);
        $this->assertSame(['Ali B', 'Karim Visitor'], $holders->pluck('recipient_name')->all());
        $this->assertSame(['assignment', 'rental'], $holders->pluck('type')->all());
    }

    #[Test]
    public function the_history_page_says_whether_the_holder_was_assigned_or_lent(): void
    {
        $lot = $this->lot(1);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player()->id, 'type' => 'assignment']);

        $props = $this->actingAs($this->admin())->get(route('equipment.items.history', $lot))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('assignment', $props['item']['rented_to']['type']);
    }

    #[Test]
    public function equipment_out_splits_rentals_from_assignments_and_hides_returned_items(): void
    {
        $lot = $this->lot();
        $player = $this->player();
        $this->open($lot, ['external_name' => 'Karim Visitor']);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $player->id, 'type' => 'assignment']);
        $this->open($lot, ['external_name' => 'Returned Person', 'return_date' => now(), 'returned_quantity' => 1]);

        $admin = $this->admin();
        $rentals = $this->actingAs($admin)->get(route('equipment.out'))->assertOk()->viewData('page')['props'];
        $this->assertSame(['rental' => 1, 'assignment' => 1], $rentals['counts']);
        $this->assertSame(['Karim Visitor'], collect($rentals['rentals']['data'])->pluck('recipient_name')->all());

        $assignments = $this->actingAs($admin)->get(route('equipment.out', ['type' => 'assignment']))->viewData('page')['props'];
        $this->assertSame([$player->id], collect($assignments['rentals']['data'])->pluck('player_id')->all());
    }

    #[Test]
    public function equipment_out_searches_holders_by_name_or_membership_id(): void
    {
        $lot = $this->lot();
        $this->open($lot, ['external_name' => 'Karim Visitor']);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player('202600042')->id]);

        $admin = $this->admin();
        $byName = $this->actingAs($admin)->get(route('equipment.out', ['search' => 'karim']))->viewData('page')['props'];
        $this->assertSame(['Karim Visitor'], collect($byName['rentals']['data'])->pluck('recipient_name')->all());

        $byId = $this->actingAs($admin)->get(route('equipment.out', ['search' => '202600042']))->viewData('page')['props'];
        $this->assertSame(['202600042'], collect($byId['rentals']['data'])->pluck('membership_id')->all());
    }

    #[Test]
    public function a_players_profile_exposes_whether_each_rental_is_overdue(): void
    {
        $lot = $this->lot();
        $player = $this->player();
        $this->open($lot, [
            'rentable_type' => Player::class,
            'rentable_id' => $player->id,
            'due_date' => now()->subDay(),
        ]);

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['player']['equipment_rentals'][0]['is_overdue']);
    }

    #[Test]
    public function viewing_equipment_out_needs_only_equipment_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['equipment' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->get(route('equipment.out'))->assertOk();
    }
}
