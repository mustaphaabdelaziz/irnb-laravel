<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\MemberStats;
use App\Services\Dashboard\ModuleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerLeaveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-14 10:00:00');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function statusId(string $code): int
    {
        return PlayerStatus::where('code', $code)->value('id');
    }

    private function player(array $attributes = []): Player
    {
        static $n = 0;
        $n++;

        return Player::create(array_merge([
            'membership_id' => 'L'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'firstname' => 'Player',
            'lastname' => "Number{$n}",
            'status_id' => $this->statusId('registered'),
        ], $attributes));
    }

    #[Test]
    public function moving_to_left_without_a_date_stamps_today(): void
    {
        $player = $this->player();

        $player->update(['status_id' => $this->statusId('left')]);

        $this->assertSame('2026-05-14', $player->fresh()->left_at->toDateString());
    }

    #[Test]
    public function a_given_leave_date_is_kept(): void
    {
        $player = $this->player();

        $player->update(['status_id' => $this->statusId('left'), 'left_at' => '2026-03-01']);

        $this->assertSame('2026-03-01', $player->fresh()->left_at->toDateString());
    }

    #[Test]
    public function leaving_the_left_status_clears_the_date(): void
    {
        $player = $this->player(['status_id' => $this->statusId('left'), 'left_at' => '2026-03-01']);

        $player->update(['status_id' => $this->statusId('registered')]);

        $this->assertNull($player->fresh()->left_at);
    }

    #[Test]
    public function a_leave_date_on_another_status_is_dropped(): void
    {
        $player = $this->player(['left_at' => '2026-03-01']);

        $this->assertNull($player->fresh()->left_at);
    }

    #[Test]
    public function the_update_form_saves_the_leave_date(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'status_id' => $this->statusId('left'),
            'left_at' => '2026-04-20',
        ])->assertRedirect();

        $this->assertSame('2026-04-20', $player->fresh()->left_at->toDateString());
    }

    #[Test]
    public function a_future_leave_date_is_rejected(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'status_id' => $this->statusId('left'),
            'left_at' => '2026-06-01',
        ])->assertSessionHasErrors('left_at');
    }

    #[Test]
    public function bulk_status_change_follows_the_same_rule(): void
    {
        $stays = $this->player();
        $keepsDate = $this->player(['status_id' => $this->statusId('left'), 'left_at' => '2026-02-02']);
        $comesBack = $this->player(['status_id' => $this->statusId('left'), 'left_at' => '2026-02-02']);

        $admin = $this->admin();

        $this->actingAs($admin)->post(route('players.bulkUpdate'), [
            'ids' => [$stays->id, $keepsDate->id],
            'field' => 'status_id',
            'value' => $this->statusId('left'),
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('players.bulkUpdate'), [
            'ids' => [$comesBack->id],
            'field' => 'status_id',
            'value' => $this->statusId('registered'),
        ])->assertRedirect();

        $this->assertSame('2026-05-14', $stays->fresh()->left_at->toDateString());
        $this->assertSame('2026-02-02', $keepsDate->fresh()->left_at->toDateString());
        $this->assertNull($comesBack->fresh()->left_at);
    }

    #[Test]
    public function the_dashboard_counts_leavers_in_the_window_by_category(): void
    {
        $u17 = Category::create(['name' => 'U17']);
        $left = $this->statusId('left');

        $this->player(['status_id' => $left, 'left_at' => '2026-05-02', 'category_id' => $u17->id]);
        // Archived later: still a leaver.
        $this->player(['status_id' => $left, 'left_at' => '2026-05-10', 'category_id' => $u17->id, 'archived' => true]);
        $this->player(['status_id' => $left, 'left_at' => '2026-04-10']); // last month
        $this->player(); // still here

        $stats = app(MemberStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET', ['range' => 'month'])),
        );

        $summary = collect($stats['summary'])->keyBy('key');
        $this->assertSame(2, $summary['left']['value']);

        $this->assertSame([['name' => 'U17', 'labelKey' => null, 'count' => 2]], $stats['leftByCategory']);

        $april = array_search('2026-04', $stats['growth']['labels'], true);
        $may = array_search('2026-05', $stats['growth']['labels'], true);
        $this->assertSame(1.0, $stats['growth']['left'][$april]);
        $this->assertSame(2.0, $stats['growth']['left'][$may]);
    }

    #[Test]
    public function the_players_strip_counts_leavers_this_season(): void
    {
        $left = $this->statusId('left');
        $this->player(['status_id' => $left, 'left_at' => '2026-05-02']);
        $this->player(['status_id' => $left, 'left_at' => '2020-01-01']); // an old season

        $tiles = collect(app(ModuleStats::class)->players())->keyBy('key');

        $this->assertSame(1, $tiles['players_left']['value']);
    }
}
