<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerStatusLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_default_statuses_are_seeded(): void
    {
        $this->assertSame(6, PlayerStatus::count());
        $this->assertNotNull(PlayerStatus::where('name', 'منخرط')->first());
    }

    #[Test]
    public function a_status_reads_in_the_active_locale(): void
    {
        $status = PlayerStatus::where('name', 'منخرط')->first();

        app()->setLocale('fr');
        $this->assertSame('Inscrit', $status->localized_name);

        app()->setLocale('en');
        $this->assertSame('Registered', $status->localized_name);

        app()->setLocale('ar');
        $this->assertSame('منخرط', $status->localized_name);
    }

    #[Test]
    public function a_player_belongs_to_a_status(): void
    {
        $status = PlayerStatus::where('name', 'معتزل')->first();

        $player = Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600001', 'join_year' => 2026,
            'status_id' => $status->id,
        ]);

        $this->assertSame('معتزل', $player->fresh()->status->name);
    }

    #[Test]
    public function the_player_form_receives_the_active_statuses(): void
    {
        PlayerStatus::create(['name' => 'legacy junk', 'is_active' => false]);

        $props = $this->actingAs($this->admin())->get(route('players.create'))
            ->assertOk()->viewData('page')['props'];

        $names = collect($props['playerStatuses'])->pluck('name');

        $this->assertContains('منخرط', $names);
        // Inactive statuses exist only to hold imported values; offering them
        // when editing would reintroduce the junk.
        $this->assertNotContains('legacy junk', $names);
    }

    #[Test]
    public function a_new_player_defaults_to_the_registered_status(): void
    {
        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'B', 'join_year' => 2026,
        ])->assertRedirect();

        $this->assertSame('منخرط', Player::first()->status->name);
    }

    #[Test]
    public function the_list_can_be_filtered_by_status(): void
    {
        $retired = PlayerStatus::where('name', 'معتزل')->first();

        Player::create(['firstname' => 'A', 'lastname' => 'X', 'membership_id' => '202600010', 'join_year' => 2026, 'status_id' => $retired->id]);
        Player::create(['firstname' => 'B', 'lastname' => 'Y', 'membership_id' => '202600011', 'join_year' => 2026,
            'status_id' => PlayerStatus::where('name', 'منخرط')->value('id')]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['status' => $retired->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['players']['data']);
        $this->assertSame('A', $props['players']['data'][0]['firstname']);
    }

    #[Test]
    public function the_status_statistics_report_the_localised_name(): void
    {
        Player::create(['firstname' => 'A', 'lastname' => 'X', 'membership_id' => '202600012', 'join_year' => 2026,
            'status_id' => PlayerStatus::where('name', 'معتزل')->value('id')]);

        $this->actingAs($this->admin());

        app()->setLocale('fr');
        $props = $this->get(route('players.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame('Retraité', collect($props['statusStats'])->firstWhere('count', 1)['name']);
    }

    #[Test]
    public function deleting_a_status_leaves_its_players_intact(): void
    {
        $status = PlayerStatus::where('name', 'معاقب')->first();

        $player = Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600002', 'join_year' => 2026,
            'status_id' => $status->id,
        ]);

        $status->delete();

        $this->assertNotNull($player->fresh(), 'the player must survive its status');
        $this->assertNull($player->fresh()->status_id);
    }
}
