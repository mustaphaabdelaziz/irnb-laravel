<?php

namespace Tests\Feature\Dashboard;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Services\Dashboard\ModuleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModuleStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    /** @return array<string, array<string, mixed>> */
    private function strip(string $module): array
    {
        return collect(app(ModuleStats::class)->{$module}())->keyBy('key')->all();
    }

    private function player(array $attributes = [], ?string $joinedAt = null): Player
    {
        static $n = 0;
        $n++;

        $player = Player::create(array_merge([
            'membership_id' => str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Player',
            'lastname' => "Number{$n}",
        ], $attributes));

        if ($joinedAt !== null) {
            $player->forceFill(['created_at' => $joinedAt])->save();
        }

        return $player;
    }

    #[Test]
    public function the_players_strip_counts_members_debt_and_joins(): void
    {
        $this->player(['outstanding_debt' => 1500], '2026-05-02 09:00:00');
        $this->player(['outstanding_debt' => 0], '2026-01-02 09:00:00');
        $this->player(['archived' => true, 'outstanding_debt' => 9999], '2026-05-02 09:00:00');

        $strip = $this->strip('players');

        $this->assertSame(2, $strip['players_active']['value']);
        $this->assertSame(1, $strip['players_with_debt']['value']);
        $this->assertSame(1500.0, $strip['players_debt_total']['value']);
        $this->assertSame(1, $strip['players_new']['value']);
    }

    #[Test]
    public function the_subscriptions_strip_reports_collection(): void
    {
        $player = $this->player();
        PlayerSubscription::create([
            'player_id' => $player->id, 'year' => 2026,
            'amount_owed' => 1000, 'amount_paid' => 750,
        ]);
        PlayerSubscription::create([
            'player_id' => $player->id, 'year' => 2026,
            'amount_owed' => 5000, 'amount_paid' => 0, 'is_exempt' => true,
        ]);

        $strip = $this->strip('subscriptions');

        $this->assertSame(1, $strip['subs_enrolled']['value']);
        $this->assertSame(750.0, $strip['subs_collected']['value']);
        $this->assertSame(250.0, $strip['subs_owed']['value']);
        $this->assertSame(75.0, $strip['subs_rate']['value']);
    }

    #[Test]
    public function the_subscriptions_rate_is_null_when_nothing_is_billed(): void
    {
        $this->assertNull($this->strip('subscriptions')['subs_rate']['value']);
    }

    #[Test]
    public function the_equipment_strip_counts_catalogs_units_value_and_loans(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Training']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'BALL-1',
            'purchase_date' => '2026-01-01',
            'quantity' => 10,
            'unit_price' => 50,
        ]);

        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => '2026-05-01',
            'quantity' => 3,
        ]);

        $strip = $this->strip('equipment');

        $this->assertSame(1, $strip['equip_catalogs']['value']);
        $this->assertSame(10, $strip['equip_units']['value']);
        $this->assertSame(500.0, $strip['equip_value']['value']);
        $this->assertSame(3, $strip['equip_on_loan']['value']);
    }

    #[Test]
    public function retired_equipment_is_not_counted(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Old kit', 'category' => 'Training']);
        EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'OLD-1',
            'purchase_date' => '2020-01-01',
            'quantity' => 5,
            'unit_price' => 100,
            'status' => 'Retired',
        ]);

        $this->assertSame(0, $this->strip('equipment')['equip_catalogs']['value']);
    }

    #[Test]
    public function every_strip_is_four_tiles_with_a_key_and_a_format(): void
    {
        foreach (['players', 'subscriptions', 'equipment'] as $module) {
            $tiles = app(ModuleStats::class)->{$module}();

            $this->assertCount(4, $tiles, "{$module} strip should have four tiles");

            foreach ($tiles as $tile) {
                $this->assertArrayHasKey('key', $tile);
                $this->assertArrayHasKey('value', $tile);
                $this->assertContains($tile['format'], ['number', 'money', 'percent']);
            }
        }
    }

    #[Test]
    public function an_empty_database_returns_zeroes_not_errors(): void
    {
        $this->assertSame(0, $this->strip('players')['players_active']['value']);
        $this->assertSame(0.0, $this->strip('players')['players_debt_total']['value']);
        $this->assertSame(0, $this->strip('equipment')['equip_units']['value']);
    }
}
