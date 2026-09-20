<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Category;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\HeroStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HeroStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function filters(array $query = []): DashboardFilters
    {
        return DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', $query));
    }

    /** @return array<string, array<string, mixed>> keyed by tile key */
    private function tiles(array $query = []): array
    {
        $tiles = app(HeroStats::class)->get($this->filters($query));

        return collect($tiles)->keyBy('key')->all();
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

    private function subscriptionLine(Player $player, float $owed, float $paid, string $dueDate, array $attributes = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'year' => (int) substr($dueDate, 0, 4),
            'amount_owed' => $owed,
            'amount_paid' => $paid,
            'due_date' => $dueDate,
        ], $attributes));
    }

    #[Test]
    public function it_returns_the_six_hero_tiles_each_with_a_key_and_a_format(): void
    {
        $tiles = $this->tiles();

        $this->assertSame(
            ['members', 'collection_rate', 'net_cash_flow', 'outstanding_debt', 'equipment_on_loan', 'treasury'],
            array_keys($tiles),
        );

        foreach ($tiles as $tile) {
            $this->assertArrayHasKey('value', $tile);
            $this->assertArrayHasKey('format', $tile);
            $this->assertArrayHasKey('delta', $tile);
            $this->assertArrayHasKey('spark', $tile);
        }
    }

    #[Test]
    public function it_counts_active_members_and_excludes_archived_ones(): void
    {
        $this->player();
        $this->player();
        $this->player(['archived' => true]);

        $this->assertSame(2, $this->tiles()['members']['value']);
    }

    #[Test]
    public function collection_rate_is_paid_over_paid_plus_owed(): void
    {
        $player = $this->player();
        $this->subscriptionLine($player, 1000, 750, '2026-05-10');

        $this->assertSame(75.0, $this->tiles()['collection_rate']['value']);
    }

    #[Test]
    public function collection_rate_excludes_exempt_lines(): void
    {
        $player = $this->player();
        $this->subscriptionLine($player, 1000, 1000, '2026-05-10');
        $this->subscriptionLine($this->player(), 5000, 0, '2026-05-10', ['is_exempt' => true]);

        $this->assertSame(100.0, $this->tiles()['collection_rate']['value']);
    }

    #[Test]
    public function collection_rate_is_null_when_nothing_is_billed(): void
    {
        $this->assertNull($this->tiles()['collection_rate']['value']);
    }

    #[Test]
    public function a_subscription_line_without_a_due_date_falls_back_to_its_year_end(): void
    {
        $player = $this->player();
        $this->subscriptionLine($player, 1000, 400, '2026-05-10');
        PlayerSubscription::create([
            'player_id' => $this->player()->id,
            'year' => 2026,
            'amount_owed' => 1000,
            'amount_paid' => 600,
            'due_date' => null,
        ]);

        // The year-end fallback puts the second line outside a May window.
        $this->assertSame(40.0, $this->tiles()['collection_rate']['value']);
        // ...and inside a whole-year window.
        $this->assertSame(50.0, $this->tiles(['range' => 'year'])['collection_rate']['value']);
    }

    #[Test]
    public function net_cash_flow_is_income_minus_expense_within_the_window(): void
    {
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('expense', 300, '2026-05-03');
        $this->transaction('income', 9999, '2026-04-30'); // previous month, out of window

        $this->assertSame(700.0, $this->tiles()['net_cash_flow']['value']);
    }

    #[Test]
    public function archived_transactions_are_excluded_from_cash_flow(): void
    {
        $this->transaction('income', 1000, '2026-05-02');
        $this->transaction('income', 5000, '2026-05-02', ['archived' => true]);

        $this->assertSame(1000.0, $this->tiles()['net_cash_flow']['value']);
    }

    #[Test]
    public function net_cash_flow_compares_against_the_previous_period(): void
    {
        $this->transaction('income', 1200, '2026-05-02');
        $this->transaction('income', 1000, '2026-04-02');

        $delta = $this->tiles()['net_cash_flow']['delta'];

        $this->assertSame(20.0, $delta['percent']);
        $this->assertSame('positive', $delta['tone']);
    }

    #[Test]
    public function less_new_debt_than_last_period_reads_as_good_news(): void
    {
        $player = $this->player(['outstanding_debt' => 500]);
        // 400 of new unpaid debt came due in April, only 100 in May.
        $this->subscriptionLine($player, 1000, 600, '2026-04-10');
        $this->subscriptionLine($player, 1000, 900, '2026-05-10');

        $tile = $this->tiles()['outstanding_debt'];

        $this->assertSame(500.0, $tile['value']);
        $this->assertSame(-75.0, $tile['delta']['percent']);
        $this->assertSame('positive', $tile['delta']['tone']);
    }

    #[Test]
    public function more_new_debt_than_last_period_reads_as_bad_news(): void
    {
        $player = $this->player(['outstanding_debt' => 500]);
        $this->subscriptionLine($player, 1000, 900, '2026-04-10'); // 100 unpaid
        $this->subscriptionLine($player, 1000, 600, '2026-05-10'); // 400 unpaid

        $this->assertSame('negative', $this->tiles()['outstanding_debt']['delta']['tone']);
    }

    #[Test]
    public function the_debt_sparkline_has_twelve_points(): void
    {
        $this->subscriptionLine($this->player(['outstanding_debt' => 500]), 1000, 500, '2026-03-10');

        $this->assertCount(12, $this->tiles()['outstanding_debt']['spark']);
    }

    #[Test]
    public function equipment_on_loan_counts_open_rentals_and_reports_overdue_separately(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Ball', 'category' => 'Training']);
        $item = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'unique_identifier' => 'BALL-1',
            'purchase_date' => '2026-01-01',
            'status' => 'Rented',
        ]);
        $player = $this->player();

        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $player->id,
            'checkout_date' => '2026-05-01',
            'due_date' => '2026-05-10', // overdue on 2026-05-14
        ]);
        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $player->id,
            'checkout_date' => '2026-05-01',
            'due_date' => '2026-06-30',
        ]);
        EquipmentRental::create([
            'equipment_item_id' => $item->id,
            'rentable_type' => Player::class,
            'rentable_id' => $player->id,
            'checkout_date' => '2026-04-01',
            'due_date' => '2026-04-10',
            'return_date' => '2026-04-09', // closed, not on loan
        ]);

        $tile = $this->tiles()['equipment_on_loan'];

        $this->assertSame(2, $tile['value']);
        $this->assertSame(1, $tile['meta']['overdue']);
    }

    #[Test]
    public function the_branch_filter_isolates_one_branch_from_another(): void
    {
        $north = Branch::create(['name' => 'North']);
        $south = Branch::create(['name' => 'South']);

        $this->player()->branches()->attach($north->id);
        $this->player()->branches()->attach($south->id);
        $this->player()->branches()->attach($south->id);

        $this->assertSame(3, $this->tiles()['members']['value']);
        $this->assertSame(1, $this->tiles(['branch' => (string) $north->id])['members']['value']);
        $this->assertSame(2, $this->tiles(['branch' => (string) $south->id])['members']['value']);
    }

    #[Test]
    public function an_empty_database_produces_no_division_by_zero(): void
    {
        $tiles = $this->tiles();

        $this->assertSame(0, $tiles['members']['value']);
        $this->assertNull($tiles['collection_rate']['value']);
        $this->assertSame(0.0, $tiles['net_cash_flow']['value']);
        $this->assertNull($tiles['net_cash_flow']['delta']);
        $this->assertSame(0.0, $tiles['outstanding_debt']['value']);
    }

    #[Test]
    public function a_category_scoped_player_still_counts(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $subscription = Subscription::create([
            'name' => 'Annual',
            'year' => 2026,
            'amount_student' => 1000,
            'amount_worker' => 1500,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $subscription->categories()->attach($category->id);
        $this->player(['category_id' => $category->id]);

        $this->assertSame(1, $this->tiles()['members']['value']);
    }
}
