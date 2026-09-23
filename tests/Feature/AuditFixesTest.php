<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressions for defects found in the post-build full audit.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600300;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(): Player
    {
        return Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => (string) ++$this->membership, 'join_year' => 2026,
        ]);
    }

    #[Test]
    public function the_catalog_show_page_query_count_does_not_grow_with_lot_count(): void
    {
        $admin = $this->admin();
        $borrower = $this->player();

        // A dozen lots, each out to the same borrower — the shape where a
        // per-lot availability query or a per-player branch lazy-load would
        // explode the query count.
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);
        for ($i = 0; $i < 12; $i++) {
            $lot = EquipmentItem::create([
                'catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20,
            ]);
            $lot->rentals()->create([
                'rentable_type' => Player::class, 'rentable_id' => $borrower->id,
                'checkout_date' => now(), 'quantity' => 5,
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('equipment.catalogs.show', $catalog))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Availability sums eager-loaded rentals in memory and the rent-dropdown
        // players eager-load their branches, so the page is a fixed handful of
        // queries. Before the fixes, 12 lots cost ~2 extra queries each.
        $this->assertLessThanOrEqual(18, $count,
            "catalog show ran {$count} queries for 12 lots — an N+1 has returned");
    }

    #[Test]
    public function available_count_is_correct_whether_or_not_rentals_are_eager_loaded(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Balls']);
        $lot = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 20,
        ]);
        $lot->rentals()->create([
            'rentable_type' => Player::class, 'rentable_id' => $this->player()->id,
            'checkout_date' => now(), 'quantity' => 8,
        ]);

        // Lazy path (no eager load).
        $this->assertSame(12, $lot->fresh()->available_quantity);

        // Eager path (the in-memory branch) must agree.
        $loaded = EquipmentItem::with('rentals')->find($lot->id);
        $this->assertSame(12, $loaded->available_quantity);
    }

    #[Test]
    public function the_overdue_report_shows_a_staff_members_name(): void
    {
        $admin = $this->admin();
        $catalog = EquipmentCatalog::create(['name' => 'Radio', 'category' => 'Accessories']);
        $lot = EquipmentItem::create([
            'catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 3,
        ]);

        // A rental (not assignment) to a User, already overdue.
        $lot->rentals()->create([
            'rentable_type' => User::class, 'rentable_id' => $admin->id,
            'type' => 'rental', 'quantity' => 1,
            'checkout_date' => now()->subMonths(2), 'due_date' => now()->subMonth(),
        ]);

        $props = $this->actingAs($admin)->get(route('equipment.inventory'))
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['overdueRentals']);
        // A User has `name`, not `firstname`; the row must not be blank.
        $this->assertSame($admin->name, trim($props['overdueRentals'][0]['rented_to']['name']));
    }

    #[Test]
    public function the_players_list_carries_the_membership_status_relation(): void
    {
        $status = PlayerStatus::where('name', 'معتزل')->first();
        $player = $this->player();
        $player->update(['status_id' => $status->id]);

        $props = $this->actingAs($this->admin())->get(route('players.index'))
            ->assertOk()->viewData('page')['props'];

        $row = collect($props['players']['data'])->firstWhere('id', $player->id);
        // The list column renders player.status.localized_name, so the relation
        // must be loaded or the column is always blank.
        $this->assertSame('معتزل', $row['status']['name']);
    }
}
