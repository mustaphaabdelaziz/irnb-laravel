<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerStatusLookupTest extends TestCase
{
    use RefreshDatabase;

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
