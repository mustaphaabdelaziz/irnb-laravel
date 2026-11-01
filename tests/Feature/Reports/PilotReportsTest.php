<?php

namespace Tests\Feature\Reports;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Category;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Transaction;
use App\Services\Competitions\CompetitionRecord;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

/** The four R1 reports against records whose answer is known. */
class PilotReportsTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> the viewer's props */
    private function report(string $category, string $key, array $query = []): array
    {
        $props = [];
        $this->actingAs($this->admin())
            ->get(route('reports.view', [$category, $key, ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @return array<string, mixed> summary tiles by label */
    private function tiles(array $props): array
    {
        return collect($props['summary'])->mapWithKeys(fn ($t) => [$t['label'] => $t['value']])->all();
    }

    // Players -----------------------------------------------------------------

    #[Test]
    public function the_player_directory_filters_and_leaves_archived_players_out(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);
        Player::create(['membership_id' => '1', 'firstname' => 'A', 'lastname' => 'Alpha', 'gender' => 'Male', 'category_id' => $u15->id, 'birthdate' => today()->subYears(14)->toDateString()]);
        Player::create(['membership_id' => '2', 'firstname' => 'B', 'lastname' => 'Beta', 'gender' => 'Female', 'category_id' => $u17->id, 'birthdate' => today()->subYears(16)->toDateString()]);
        Player::create(['membership_id' => '3', 'firstname' => 'C', 'lastname' => 'Gamma', 'gender' => 'Male', 'category_id' => $u15->id, 'archived' => true]);

        $all = $this->report('players', 'directory');
        $this->assertEquals(2, $all['rows']['total']);
        $this->assertEquals(['players' => 2, 'boys' => 1, 'girls' => 1, 'average_age' => 15.0],
            collect($this->tiles($all))->mapWithKeys(fn ($v, $k) => [str_replace('reports.kpi.', '', $k) => $v])->all());

        $this->assertEquals(['1'], array_column($this->report('players', 'directory', ['category_id' => [$u15->id]])['rows']['data'], 'membership_id'));
        $this->assertEquals(['2'], array_column($this->report('players', 'directory', ['gender' => 'Female'])['rows']['data'], 'membership_id'));
        // Ages are whole years, both bounds inclusive.
        $this->assertEquals(['1'], array_column($this->report('players', 'directory', ['age_min' => 14, 'age_max' => 14])['rows']['data'], 'membership_id'));
        $this->assertEquals(['3'], array_column($this->report('players', 'directory', ['archived' => 1])['rows']['data'], 'membership_id'));
    }

    // Finance -----------------------------------------------------------------

    private function entry(string $type, float $amount, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'title' => 'Entry', 'amount' => $amount, 'transaction_date' => today()->toDateString(),
            'transaction_type' => $type, 'category' => $type === 'income' ? 'donation' : 'other',
            'payment_method' => 'cash', 'status' => 'Paid',
        ]);
    }

    #[Test]
    public function the_finance_summary_matches_the_transactions_screen_and_skips_cancelled_entries(): void
    {
        $this->entry('income', 1000);
        $this->entry('income', 500, ['payment_method' => 'bank']);
        $this->entry('expense', 300);
        $this->entry('income', 9999, ['archived' => true]);

        $report = $this->tiles($this->report('finance', 'summary'));
        $this->assertEquals([1500.0, 300.0, 1200.0], [$report['reports.kpi.income'], $report['reports.kpi.expense'], $report['reports.kpi.net']]);

        $strip = [];
        $this->actingAs($this->admin())->get(route('transactions.index'))
            ->assertInertia(function (Assert $page) use (&$strip) {
                $strip = collect($page->toArray()['props']['strip'])->pluck('value', 'key')->all();
            });
        $this->assertEquals([$strip['tx_income'], $strip['tx_expense'], $strip['tx_net']], [1500.0, 300.0, 1200.0]);

        $props = $this->report('finance', 'summary');
        $this->assertEquals(['entries' => 3, 'income' => 1500.0, 'expense' => 300.0], $props['totals']);

        $this->assertEquals(1000.0, $this->tiles($this->report('finance', 'summary', ['payment_method' => ['cash']]))['reports.kpi.income']);
    }

    #[Test]
    public function a_date_range_replaces_the_default_season(): void
    {
        $this->entry('income', 100);
        $lastSeason = FiscalPeriod::start(FiscalPeriod::currentKey())->subDays(10)->toDateString();
        $this->entry('income', 40, ['transaction_date' => $lastSeason]);

        $this->assertEquals(100.0, $this->tiles($this->report('finance', 'summary'))['reports.kpi.income']);
        $this->assertEquals(40.0, $this->tiles($this->report('finance', 'summary', ['from' => $lastSeason, 'to' => $lastSeason]))['reports.kpi.income']);
    }

    // Equipment ---------------------------------------------------------------

    #[Test]
    public function the_inventory_values_lots_like_the_inventory_page_and_splits_rentals_from_assignments(): void
    {
        $balls = EquipmentCatalog::create(['name' => 'Balls', 'category' => 'Balls', 'purchase_price' => 50]);
        $priced = EquipmentItem::create(['catalog_id' => $balls->id, 'quantity' => 10, 'unit_price' => 80, 'status' => 'Available', 'purchase_date' => '2026-01-01']);
        EquipmentItem::create(['catalog_id' => $balls->id, 'quantity' => 4, 'status' => 'Available', 'purchase_date' => '2026-01-01']);
        EquipmentItem::create(['catalog_id' => $balls->id, 'quantity' => 3, 'status' => 'Lost', 'purchase_date' => '2026-01-01']);
        EquipmentRental::create(['equipment_item_id' => $priced->id, 'external_name' => 'X', 'type' => 'rental', 'quantity' => 2, 'returned_quantity' => 0, 'checkout_date' => '2026-10-01']);
        EquipmentRental::create(['equipment_item_id' => $priced->id, 'external_name' => 'Y', 'type' => 'assignment', 'quantity' => 3, 'returned_quantity' => 1, 'checkout_date' => '2026-10-01']);

        $props = $this->report('equipment', 'inventory');
        $tiles = $this->tiles($props);

        // 10 × 80 (own price) + 4 × 50 (catalog price); the lost lot carries no value.
        $this->assertEquals(1000.0, $tiles['reports.kpi.asset_value']);
        $this->assertEquals(17, $tiles['reports.kpi.units']);
        $this->assertEquals(2, $tiles['reports.kpi.units_rented']);
        $this->assertEquals(2, $tiles['reports.kpi.units_assigned']);
        $this->assertEquals(['quantity' => 17], $props['totals']);
        // A lot without its own price shows the catalog's.
        $this->assertEqualsCanonicalizing([80.0, 50.0, 50.0], array_column($props['rows']['data'], 'unit_price'));

        $this->assertEquals(1, $this->report('equipment', 'inventory', ['status' => ['Lost']])['rows']['total']);
    }

    // Competitions ------------------------------------------------------------

    #[Test]
    public function season_results_list_played_matches_only_with_our_record(): void
    {
        $team = $this->team();
        $season = $this->competition($team, 2026)->season_start_year;
        $won = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 1, 'date' => '2026-09-10']);
        $this->game($team, ['status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0, 'date' => '2026-09-20', 'side' => GameSide::Away]);
        $this->game($team, ['status' => GameStatus::Scheduled, 'date' => '2026-12-01']);
        $this->game($team, ['status' => GameStatus::Cancelled, 'date' => '2026-11-01']);

        $props = $this->report('competitions', 'results', ['season' => $season]);
        $this->assertEquals(2, $props['rows']['total']);
        // Newest first by default.
        $this->assertEquals(['2026-09-20', '2026-09-10'], array_column($props['rows']['data'], 'date'));

        $record = CompetitionRecord::fromGames([$won, $won->newQuery()->where('date', '2026-09-20')->first()]);
        $tiles = $this->tiles($props);
        $this->assertEquals([$record['played'], $record['wins'], $record['draws'], $record['losses']],
            [$tiles['reports.kpi.played'], $tiles['reports.kpi.wins'], $tiles['reports.kpi.draws'], $tiles['reports.kpi.losses']]);

        $this->assertEquals(1, $this->report('competitions', 'results', ['season' => $season, 'side' => 'away'])['rows']['total']);
    }
}
