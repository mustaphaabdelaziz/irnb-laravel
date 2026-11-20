<?php

namespace Tests\Feature\Reports;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** The Joined/Left report reads the status history: a return never erases a departure. */
class PlayerMovementHistoryTest extends TestCase
{
    use RefreshDatabase;
    use RegistrationFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    private function report(array $query): array
    {
        $props = [];
        $this->actingAs($this->admin)->get(route('reports.view', ['players', 'movement', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @return array<string, array{left_at: ?string, returned_at: ?string}> by membership ID */
    private function rows(array $query): array
    {
        return collect($this->report($query)['rows']['data'])
            ->mapWithKeys(fn ($r) => [$r['membership_id'] => ['left_at' => $r['left_at'], 'returned_at' => $r['returned_at']]])
            ->all();
    }

    private function registered(): int
    {
        return (int) PlayerStatus::where('code', 'registered')->value('id');
    }

    /** A status change through the form's path, on that day. */
    private function change(Player $player, int $branchId, int $statusId, string $on): void
    {
        app(RegistrationService::class)->sync($player->refresh(), [
            ['branch_id' => $branchId, 'status_id' => $statusId, 'status_changed_on' => $on],
        ], $this->admin);
    }

    private function footballOnly(string $id): Player
    {
        $player = Player::create(['membership_id' => $id, 'firstname' => 'P'.$id, 'lastname' => 'L']);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'join_date' => '2020-09-01', 'category_id' => $this->senior->id]);

        return $player->refresh();
    }

    #[Test]
    public function a_returned_player_still_counts_in_the_year_he_left(): void
    {
        $player = $this->footballOnly('R1');
        $this->change($player, $this->foot->id, Player::leftStatusId(), '2025-06-15');
        $this->change($player, $this->foot->id, $this->registered(), '2026-09-01');
        $id = $player->refresh()->membership_id;

        $this->assertSame([$id => ['left_at' => '2025-06-15', 'returned_at' => '2026-09-01']], $this->rows(['year' => 2025, 'movement' => 'left']));
        $this->assertSame([], $this->rows(['year' => 2026, 'movement' => 'left']));
        $this->assertSame([$id => ['left_at' => '2025-06-15', 'returned_at' => '2026-09-01']], $this->rows(['year' => 2026, 'movement' => 'returned']));
        $this->assertSame([$id => ['left_at' => '2025-06-15', 'returned_at' => '2026-09-01']], $this->rows(['year' => 2025, 'movement' => 'left', 'branch_id' => [$this->foot->id]]));
    }

    #[Test]
    public function leaving_one_branch_while_staying_in_another_is_not_leaving_the_club(): void
    {
        $player = $this->bothActive();
        $this->change($player, $this->foot->id, Player::leftStatusId(), '2025-06-15');
        $id = $player->refresh()->membership_id;

        $this->assertSame([], $this->rows(['year' => 2025, 'movement' => 'left']));
        $this->assertSame([$id => ['left_at' => '2025-06-15', 'returned_at' => null]], $this->rows(['year' => 2025, 'movement' => 'left', 'branch_id' => [$this->foot->id]]));
        $this->assertSame([], $this->rows(['year' => 2025, 'movement' => 'left', 'branch_id' => [$this->swim->id]]));
    }

    #[Test]
    public function leaving_the_last_branch_is_leaving_the_club(): void
    {
        $player = $this->bothActive();
        $this->change($player, $this->foot->id, Player::leftStatusId(), '2024-06-15');
        $this->change($player, $this->swim->id, Player::leftStatusId(), '2025-02-01');
        $id = $player->refresh()->membership_id;

        $this->assertSame([], $this->rows(['year' => 2024, 'movement' => 'left']));
        $this->assertSame([$id => ['left_at' => '2025-02-01', 'returned_at' => null]], $this->rows(['year' => 2025, 'movement' => 'left']));
    }

    #[Test]
    public function a_bulk_departure_with_no_line_still_counts(): void
    {
        $player = $this->leftBoth();

        $this->assertSame([$player->membership_id => ['left_at' => $this->leaveDate, 'returned_at' => null]], $this->rows(['year' => 2026, 'movement' => 'left']));
    }

    #[Test]
    public function the_summary_counts_joined_left_and_returned(): void
    {
        $player = $this->footballOnly('R1');
        $this->change($player, $this->foot->id, Player::leftStatusId(), '2025-06-15');
        $this->change($player, $this->foot->id, $this->registered(), '2026-09-01');

        $props = $this->report(['year' => 2026, 'movement' => 'returned']);

        $this->assertSame(['reports.movement.joined', 'reports.movement.left', 'reports.movement.returned'], array_column($props['summary'], 'label'));
        $this->assertEquals([0, 0, 1], array_column($props['summary'], 'value'));
        $leavers = collect($props['charts'])->firstWhere('title', 'reports.chart.leavers_by_year')['stats'];
        $this->assertEquals([['key' => '2025', 'label' => '2025', 'count' => 1]], $leavers);
    }
}
