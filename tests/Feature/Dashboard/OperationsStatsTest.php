<?php

namespace Tests\Feature\Dashboard;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\InventorySession;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\OperationsStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function operations(array $query = []): array
    {
        return app(OperationsStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query)),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function summary(array $query = []): array
    {
        return collect($this->operations($query)['summary'])->keyBy('key')->all();
    }

    private function player(): Player
    {
        static $n = 0;
        $n++;

        return Player::create([
            'membership_id' => str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Player',
            'lastname' => "Number{$n}",
        ]);
    }

    private function item(array $attributes = []): EquipmentItem
    {
        static $n = 0;
        $n++;

        $catalog = EquipmentCatalog::create([
            'name' => "Catalog {$n}",
            'category' => 'Training',
            'purchase_price' => 100,
        ]);

        return EquipmentItem::create(array_merge([
            'catalog_id' => $catalog->id,
            'unique_identifier' => "ITEM-{$n}",
            'purchase_date' => '2026-01-01',
            'quantity' => 1,
            'unit_price' => 100,
            'status' => 'Available',
        ], $attributes));
    }

    private function rental(EquipmentItem $item, array $attributes = []): EquipmentRental
    {
        return EquipmentRental::create(array_merge([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => '2026-05-01',
        ], $attributes));
    }

    #[Test]
    public function the_subscription_funnel_counts_each_stage(): void
    {
        $subscription = Subscription::create([
            'name' => 'Annual', 'year' => 2026, 'amount_student' => 1000,
            'amount_worker' => 1500, 'is_mandatory' => true, 'is_active' => true,
        ]);

        $lines = [
            ['amount_owed' => 1000, 'amount_paid' => 1000],  // paid
            ['amount_owed' => 1000, 'amount_paid' => 400],   // partial
            ['amount_owed' => 1000, 'amount_paid' => 0],     // unpaid
            ['amount_owed' => 1000, 'amount_paid' => 0, 'is_exempt' => true],
        ];

        foreach ($lines as $line) {
            PlayerSubscription::create(array_merge([
                'player_id' => $this->player()->id,
                'subscription_id' => $subscription->id,
                'year' => 2026,
            ], $line));
        }

        $funnel = $this->operations()['subscriptionFunnel'];

        $this->assertCount(1, $funnel);
        $this->assertSame('Annual', $funnel[0]['name']);
        $this->assertSame(4, $funnel[0]['enrolled']);
        $this->assertSame(1, $funnel[0]['paid']);
        $this->assertSame(1, $funnel[0]['partial']);
        $this->assertSame(1, $funnel[0]['unpaid']);
        $this->assertSame(1, $funnel[0]['exempt']);
    }

    #[Test]
    public function a_subscriptions_collection_rate_excludes_exempt_lines(): void
    {
        $subscription = Subscription::create([
            'name' => 'Annual', 'year' => 2026, 'amount_student' => 1000,
            'amount_worker' => 1500, 'is_mandatory' => true, 'is_active' => true,
        ]);

        PlayerSubscription::create([
            'player_id' => $this->player()->id, 'subscription_id' => $subscription->id,
            'year' => 2026, 'amount_owed' => 1000, 'amount_paid' => 750,
        ]);
        PlayerSubscription::create([
            'player_id' => $this->player()->id, 'subscription_id' => $subscription->id,
            'year' => 2026, 'amount_owed' => 9000, 'amount_paid' => 0, 'is_exempt' => true,
        ]);

        $this->assertSame(75.0, $this->operations()['subscriptionFunnel'][0]['rate']);
    }

    #[Test]
    public function stock_value_multiplies_quantity_by_unit_price(): void
    {
        $this->item(['quantity' => 3, 'unit_price' => 250]);
        $this->item(['quantity' => 1, 'unit_price' => 100]);

        $this->assertSame(850.0, $this->summary()['stock_value']['value']);
    }

    #[Test]
    public function retired_and_lost_items_are_not_stock(): void
    {
        $this->item(['quantity' => 1, 'unit_price' => 500, 'status' => 'Retired']);
        $this->item(['quantity' => 1, 'unit_price' => 500, 'status' => 'Lost']);
        $this->item(['quantity' => 1, 'unit_price' => 100]);

        $this->assertSame(100.0, $this->summary()['stock_value']['value']);
    }

    #[Test]
    public function items_on_loan_and_overdue_are_counted(): void
    {
        $item = $this->item();
        $this->rental($item, ['due_date' => '2026-05-09']);   // overdue
        $this->rental($item, ['due_date' => '2026-06-30']);   // current
        $this->rental($item, ['due_date' => '2026-04-01', 'return_date' => '2026-04-02']); // closed

        $summary = $this->summary();

        $this->assertSame(2, $summary['on_loan']['value']);
        $this->assertSame(1, $summary['overdue']['value']);
    }

    #[Test]
    public function items_by_status_keeps_a_row_per_status_present(): void
    {
        $this->item(['status' => 'Available']);
        $this->item(['status' => 'Under Repair']);
        $this->item(['status' => 'Available']);

        $byStatus = collect($this->operations()['itemsByStatus'])->keyBy('status');

        $this->assertSame(2, $byStatus['Available']['count']);
        $this->assertSame(1, $byStatus['Under Repair']['count']);
    }

    #[Test]
    public function low_stock_lists_what_is_running_out(): void
    {
        $this->item(['quantity' => 1]);
        $this->item(['quantity' => 40]);

        $low = $this->operations()['lowStock'];

        $this->assertCount(1, $low);
        $this->assertSame(1, $low[0]['available']);
    }

    #[Test]
    public function rentals_per_month_returns_twelve_points(): void
    {
        $item = $this->item();
        $this->rental($item, ['checkout_date' => '2026-05-02']);
        $this->rental($item, ['checkout_date' => '2026-04-02']);

        $series = $this->operations()['rentalsPerMonth'];

        $this->assertCount(12, $series['labels']);
        $this->assertSame(1.0, $series['counts'][10]);
        $this->assertSame(1.0, $series['counts'][11]);
    }

    #[Test]
    public function inventory_coverage_reports_the_last_completed_session(): void
    {
        InventorySession::create([
            'reference' => 'INV-1', 'type' => 'yearly', 'session_date' => '2026-03-01',
            'status' => 'completed', 'completed_at' => '2026-03-01 12:00:00',
        ]);
        InventorySession::create([
            'reference' => 'INV-2', 'type' => 'ad_hoc', 'session_date' => '2026-05-01',
            'status' => 'in_progress',
        ]);

        $coverage = $this->operations()['inventory'];

        $this->assertSame('2026-03-01', $coverage['lastSession']);
        $this->assertSame(1, $coverage['inProgress']);
    }

    #[Test]
    public function inventory_coverage_is_null_when_nothing_was_ever_counted(): void
    {
        $this->assertNull($this->operations()['inventory']['lastSession']);
    }

    #[Test]
    public function an_empty_database_is_safe(): void
    {
        $operations = $this->operations();

        $this->assertSame([], $operations['subscriptionFunnel']);
        $this->assertSame([], $operations['lowStock']);
        $this->assertSame(0.0, $this->summary()['stock_value']['value']);
        $this->assertSame(0, $this->summary()['on_loan']['value']);
    }
}
