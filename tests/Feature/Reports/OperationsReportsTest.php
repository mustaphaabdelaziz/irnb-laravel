<?php

namespace Tests\Feature\Reports;

use App\Enums\GameStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\Suspension;
use App\Models\TrainingSession;
use App\Services\Competitions\MatchStats;
use App\Services\Competitions\SuspensionStatus;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

/** R3: equipment, competition and attendance reports. */
class OperationsReportsTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function report(string $category, string $key, array $query = []): array
    {
        $props = [];
        $this->actingAs($this->admin())->get(route('reports.view', [$category, $key, ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function tiles(array $props): array
    {
        return collect($props['summary'])->pluck('value', 'label')->all();
    }

    private function lot(string $category, int $quantity, array $extra = []): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cat '.uniqid(), 'category' => $category, 'purchase_price' => 10]);

        return EquipmentItem::create($extra + ['catalog_id' => $catalog->id, 'quantity' => $quantity, 'status' => 'Available', 'purchase_date' => '2026-01-01']);
    }

    // Equipment ---------------------------------------------------------------

    #[Test]
    public function equipment_out_keeps_rentals_and_assignments_apart_and_only_rentals_run_late(): void
    {
        $lot = $this->lot('Balls', 20);
        $out = fn (array $a) => EquipmentRental::create($a + ['equipment_item_id' => $lot->id, 'external_name' => 'X', 'returned_quantity' => 0, 'checkout_date' => '2026-09-01']);
        $out(['type' => 'rental', 'quantity' => 3, 'due_date' => today()->subDays(4)->toDateString()]);
        $out(['type' => 'rental', 'quantity' => 2, 'due_date' => today()->addDays(4)->toDateString()]);
        $out(['type' => 'assignment', 'quantity' => 5, 'due_date' => today()->subDays(30)->toDateString()]);
        $out(['type' => 'rental', 'quantity' => 1, 'return_date' => today()->toDateString()]);

        $props = $this->report('equipment', 'out');
        $tiles = $this->tiles($props);

        $this->assertEquals(3, $props['rows']['total']);
        $this->assertEquals([5, 5, 1], [$tiles['reports.kpi.units_rented'], $tiles['reports.kpi.units_assigned'], $tiles['reports.kpi.overdue_rentals']]);
        $this->assertEquals(['quantity' => 10], $props['totals']);
        $this->assertEquals([3], array_column($this->report('equipment', 'out', ['overdue' => 1])['rows']['data'], 'quantity'));
        $this->assertEquals(4, collect($props['rows']['data'])->firstWhere('quantity', 3)['days_overdue']);
    }

    #[Test]
    public function valuation_agrees_with_the_inventory_report(): void
    {
        $this->lot('Balls', 10, ['unit_price' => 30]);
        $this->lot('Balls', 4);
        $this->lot('Nets', 2, ['status' => 'Under Repair']);
        $this->lot('Nets', 5, ['status' => 'Lost']);

        $props = $this->report('equipment', 'valuation');
        $rows = collect($props['rows']['data'])->keyBy('category');

        $this->assertEquals(['units' => 14, 'value' => 340], collect($rows['Balls'])->only(['units', 'value'])->all());
        $this->assertEquals(['under_repair' => 2, 'lost_retired' => 5, 'value' => 20, 'available' => 0], collect($rows['Nets'])->only(['under_repair', 'lost_retired', 'value', 'available'])->all());

        $inventory = $this->tiles($this->report('equipment', 'inventory'));
        $this->assertEquals($inventory['reports.kpi.asset_value'], $props['totals']['value']);
    }

    #[Test]
    public function lifecycle_lists_history_events_with_the_item_page_labels(): void
    {
        $lot = $this->lot('Balls', 1);
        EquipmentHistory::create(['item_id' => $lot->id, 'event_type' => 'Repair Complete', 'event_timestamp' => now()]);
        EquipmentHistory::create(['item_id' => $lot->id, 'event_type' => 'Lost', 'event_timestamp' => now()->subMonth()]);

        $props = $this->report('equipment', 'lifecycle');
        $this->assertEquals(2, $props['rows']['total']);
        $this->assertEquals(UiLang::get('event_repair_complete'), $props['rows']['data'][0]['event']);
        $this->assertEquals(1, $this->report('equipment', 'lifecycle', ['event_type' => ['Lost']])['rows']['total']);
    }

    #[Test]
    public function stocktake_losses_count_missing_units_and_their_value(): void
    {
        $priced = $this->lot('Balls', 10, ['unit_price' => 25]);
        $plain = $this->lot('Cones', 6);
        $session = InventorySession::create(['reference' => 'INV-1', 'type' => 'ad_hoc', 'session_date' => '2026-10-01', 'status' => 'completed']);
        InventorySessionItem::create(['inventory_session_id' => $session->id, 'equipment_item_id' => $priced->id, 'expected_status' => 'Available', 'expected_quantity' => 10, 'counted' => true, 'found' => true, 'found_quantity' => 7]);
        InventorySessionItem::create(['inventory_session_id' => $session->id, 'equipment_item_id' => $plain->id, 'expected_status' => 'Available', 'expected_quantity' => 6, 'counted' => false]);
        InventorySession::create(['reference' => 'INV-2', 'type' => 'ad_hoc', 'session_date' => '2026-10-05', 'status' => 'in_progress']);

        $props = $this->report('equipment', 'stocktake');
        $this->assertEquals(1, $props['rows']['total']);
        // 3 balls × 25 + 6 uncounted cones × 10 (catalog price).
        $this->assertEquals(['expected' => 16, 'found' => 7, 'missing' => 9, 'missing_value' => 135], collect($props['rows']['data'][0])->only(['expected', 'found', 'missing', 'missing_value'])->all());
    }

    // Competitions ------------------------------------------------------------

    private function rules(): array
    {
        return ['sport' => 'football', 'sanction_types' => [
            ['key' => 'yellow', 'counts_as' => 'yellow'], ['key' => 'red', 'counts_as' => 'red'], ['key' => 'two_minutes', 'counts_as' => null],
        ]];
    }

    #[Test]
    public function fixtures_list_the_season_in_date_order_and_filter_by_status(): void
    {
        $team = $this->team();
        $this->game($team, ['date' => '2026-11-20']);
        $this->game($team, ['date' => '2026-10-02', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        $this->game($team, ['date' => '2026-10-09', 'status' => GameStatus::Postponed]);

        $props = $this->report('competitions', 'fixtures', ['season' => 2026]);
        $this->assertEquals(['2026-10-02', '2026-10-09', '2026-11-20'], array_column($props['rows']['data'], 'date'));
        $this->assertEquals(1, $this->report('competitions', 'fixtures', ['season' => 2026, 'status' => ['scheduled']])['rows']['total']);
    }

    #[Test]
    public function discipline_counts_cards_under_each_matchs_rules(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $game = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 1, 'rules' => $this->rules()]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true, 'played' => true]);
        foreach (['yellow', 'yellow', 'red', 'two_minutes'] as $minute => $card) {
            GameEvent::create(['game_id' => $game->id, 'type' => 'sanction', 'subtype' => $card, 'minute' => $minute + 10, 'player_id' => $p->id]);
        }

        $tiles = $this->tiles($this->report('competitions', 'discipline', ['season' => 2026]));
        $this->assertEquals([2, 1, 1], [$tiles['comp.stats.col_yellows'], $tiles['comp.stats.col_reds'], $tiles['reports.kpi.other_sanctions']]);

        // Same yellow/red split as the statistics page.
        $stats = app(MatchStats::class)->players(2026)[0];
        $this->assertEquals([$stats['yellows'], $stats['reds']], [2, 1]);
    }

    #[Test]
    public function suspensions_show_what_suspension_status_says(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $competition = $this->competition($team, 2026);
        $ban = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 2, 'reason' => 'Letter']);

        $eval = app(SuspensionStatus::class)->evaluate(collect([$ban]))[$ban->id];
        $row = $this->report('competitions', 'suspensions')['rows']['data'][0];

        $this->assertEquals([$eval['served'], $eval['remaining'], $eval['active']], [$row['served'], $row['remaining'], $row['active_flag']]);
        $this->assertEquals('Letter', $row['cause']);
        $this->assertEquals($eval['active'] ? 1 : 0, $this->report('competitions', 'suspensions', ['active' => 1])['rows']['total']);
    }

    #[Test]
    public function appearances_join_call_ups_with_match_stats(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        foreach (['2026-09-01', '2026-09-08'] as $date) {
            $game = $this->game($team, ['date' => $date, 'status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0, 'rules' => $this->rules()]);
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'played' => true, 'role' => 'starter', 'minutes_played' => 90]);
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $b->id, 'called_up' => false, 'not_called_reason' => 'injured']);
        }

        $rows = collect($this->report('competitions', 'appearances', ['season' => 2026])['rows']['data'])->keyBy('name');

        $this->assertEquals(['listed' => 2, 'called' => 2, 'appearances' => 2, 'starts' => 2, 'minutes' => 180], collect($rows[$a->fullname])->only(['listed', 'called', 'appearances', 'starts', 'minutes'])->all());
        $this->assertEquals(['not_called' => 2, 'appearances' => 0, 'reasons' => 'injured'], collect($rows[$b->fullname])->only(['not_called', 'appearances', 'reasons'])->all());
    }

    // Attendance --------------------------------------------------------------

    #[Test]
    public function the_sessions_overview_counts_by_state_and_finds_joint_sessions_by_category(): void
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $session = fn (array $a) => TrainingSession::create($a + ['category_id' => $u15->id, 'date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'coach' => 'Karim']);
        $session([]);
        $session(['state' => SessionState::Cancelled, 'start_time' => '10:00', 'end_time' => '11:00']);
        $joint = $session(['kind' => SessionKind::Preseason, 'state' => SessionState::Planned, 'start_time' => '08:00', 'end_time' => '09:00']);
        $joint->categories()->syncWithoutDetaching([$u15->id, $u17->id]);

        $tiles = $this->tiles($this->report('attendance', 'sessions'));
        $this->assertEquals([1, 1, 1], [$tiles['att.stats.held_sessions'], $tiles['att.stats.cancelled_sessions'], $tiles['reports.kpi.planned_sessions']]);

        // The joint pre-season session belongs to U17 too.
        $this->assertEquals(1, $this->report('attendance', 'sessions', ['category_id' => [$u17->id]])['rows']['total']);
    }
}
