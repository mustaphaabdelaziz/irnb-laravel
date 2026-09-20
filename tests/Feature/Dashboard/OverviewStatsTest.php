<?php

namespace Tests\Feature\Dashboard;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\OverviewStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OverviewStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function overview(array $query = []): array
    {
        return app(OverviewStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query)),
        );
    }

    private function player(array $attributes = []): Player
    {
        static $n = 0;
        $n++;

        return Player::create(array_merge([
            'membership_id' => str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Player',
            'lastname' => "Number{$n}",
        ], $attributes));
    }

    private function line(Player $player, float $owed, float $paid, ?string $due, array $attributes = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'year' => (int) substr($due ?? '2026-01-01', 0, 4),
            'amount_owed' => $owed,
            'amount_paid' => $paid,
            'due_date' => $due,
        ], $attributes));
    }

    private function transaction(string $type, float $amount, string $date, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'amount' => $amount,
            'transaction_date' => $date,
            'transaction_type' => $type,
            'category' => $type === 'income' ? 'donation' : 'supplies',
            'status' => 'Paid',
            'fiscal_year' => (int) substr($date, 0, 4),
        ], $attributes));
    }

    /** @return array<string, array<string, mixed>> alerts keyed by key */
    private function alerts(array $query = []): array
    {
        return collect($this->overview($query)['alerts'])->keyBy('key')->all();
    }

    #[Test]
    public function the_cash_flow_series_always_has_twelve_labelled_points(): void
    {
        $cashFlow = $this->overview()['cashFlow'];

        $this->assertCount(12, $cashFlow['labels']);
        $this->assertCount(12, $cashFlow['income']);
        $this->assertCount(12, $cashFlow['expense']);
        $this->assertCount(12, $cashFlow['net']);
        $this->assertSame('2026-05', $cashFlow['labels'][11]);
    }

    #[Test]
    public function the_cash_flow_series_separates_income_from_expense(): void
    {
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('expense', 250, '2026-05-03');
        $this->transaction('income', 500, '2026-04-02');

        $cashFlow = $this->overview()['cashFlow'];

        $this->assertSame(1000.0, $cashFlow['income'][11]);
        $this->assertSame(250.0, $cashFlow['expense'][11]);
        $this->assertSame(750.0, $cashFlow['net'][11]);
        $this->assertSame(500.0, $cashFlow['income'][10]);
    }

    #[Test]
    public function archived_transactions_stay_out_of_the_cash_flow_series(): void
    {
        $this->transaction('income', 1000, '2026-05-02', ['archived' => true]);

        $this->assertSame(0.0, $this->overview()['cashFlow']['income'][11]);
    }

    #[Test]
    public function debt_aging_buckets_by_days_past_the_effective_due_date(): void
    {
        $player = $this->player();
        $this->line($player, 1000, 0, '2026-05-01');   // 13 days => 0-30
        $this->line($player, 2000, 0, '2026-04-04');   // 40 days => 31-60
        $this->line($player, 3000, 0, '2026-03-05');   // 70 days => 61-90
        $this->line($player, 4000, 0, '2025-12-01');   // 164 days => 90+

        $buckets = collect($this->overview()['debtAging'])->keyBy('bucket');

        $this->assertSame(1000.0, $buckets['0-30']['amount']);
        $this->assertSame(2000.0, $buckets['31-60']['amount']);
        $this->assertSame(3000.0, $buckets['61-90']['amount']);
        $this->assertSame(4000.0, $buckets['90+']['amount']);
    }

    #[Test]
    public function debt_aging_reports_only_the_unpaid_remainder(): void
    {
        $this->line($this->player(), 1000, 600, '2026-05-01');

        $buckets = collect($this->overview()['debtAging'])->keyBy('bucket');

        $this->assertSame(400.0, $buckets['0-30']['amount']);
        $this->assertSame(1, $buckets['0-30']['players']);
    }

    #[Test]
    public function debt_aging_ignores_settled_exempt_and_future_lines(): void
    {
        $this->line($this->player(), 1000, 1000, '2026-05-01');                     // settled
        $this->line($this->player(), 1000, 0, '2026-05-01', ['is_exempt' => true]); // exempt
        $this->line($this->player(), 1000, 0, '2026-08-01');                        // not yet due

        $total = collect($this->overview()['debtAging'])->sum('amount');

        $this->assertSame(0.0, $total);
    }

    #[Test]
    public function debt_aging_always_returns_all_four_buckets_in_order(): void
    {
        $this->assertSame(
            ['0-30', '31-60', '61-90', '90+'],
            collect($this->overview()['debtAging'])->pluck('bucket')->all(),
        );
    }

    #[Test]
    public function an_overdue_rental_raises_an_alert(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Ball', 'category' => 'Training']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'BALL-1',
            'purchase_date' => '2026-01-01',
            'quantity' => 10,
        ]);

        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $this->player()->id,
            'checkout_date' => '2026-05-01',
            'due_date' => '2026-05-09',
        ]);

        $this->assertSame(1, $this->alerts()['overdue_rentals']['count']);
    }

    #[Test]
    public function a_negative_account_balance_raises_a_critical_alert(): void
    {
        FinanceAccount::create(['name' => 'Petty cash', 'type' => 'cash', 'current_balance' => -50]);
        FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'current_balance' => 1000]);

        $alert = $this->alerts()['negative_balance'];

        $this->assertSame(1, $alert['count']);
        $this->assertSame('critical', $alert['severity']);
    }

    #[Test]
    public function a_member_unpaid_for_over_sixty_days_raises_an_alert(): void
    {
        $this->line($this->player(), 1000, 0, '2026-03-01'); // 74 days
        $this->line($this->player(), 1000, 0, '2026-05-01'); // 13 days

        $this->assertSame(1, $this->alerts()['unpaid_over_60']['count']);
    }

    #[Test]
    public function alerts_with_nothing_to_report_are_left_out(): void
    {
        $this->assertSame([], $this->overview()['alerts']);
    }

    #[Test]
    public function every_alert_links_to_a_route_that_exists(): void
    {
        // Ziggy throws in the browser on an unknown route name, and an alert's
        // link is only rendered once that alert has something to report — so
        // without this the break would surface at the worst possible moment.
        FinanceAccount::create(['name' => 'Petty cash', 'type' => 'cash', 'current_balance' => -50]);
        $this->line($this->player(), 1000, 0, '2026-03-01');

        $alerts = $this->overview()['alerts'];

        $this->assertNotEmpty($alerts);

        foreach ($alerts as $alert) {
            $this->assertTrue(
                Route::has($alert['href']),
                "Alert {$alert['key']} points at route {$alert['href']}, which does not exist.",
            );
        }
    }

    #[Test]
    public function the_activity_feed_merges_sources_newest_first_and_caps_at_ten(): void
    {
        foreach (range(1, 8) as $i) {
            $this->transaction('income', 100 * $i, sprintf('2026-05-%02d', $i));
        }
        $this->player();
        $this->player();
        $this->player();

        $activity = $this->overview()['activity'];

        $this->assertCount(10, $activity);
        $this->assertGreaterThanOrEqual(
            $activity[1]['at'],
            $activity[0]['at'],
        );
        $this->assertContains('registration', collect($activity)->pluck('type')->all());
    }

    #[Test]
    public function an_empty_database_returns_empty_structures_not_errors(): void
    {
        $overview = $this->overview();

        $this->assertSame([], $overview['alerts']);
        $this->assertSame([], $overview['activity']);
        $this->assertSame(0.0, array_sum($overview['cashFlow']['net']));
        $this->assertCount(4, $overview['debtAging']);
    }
}
