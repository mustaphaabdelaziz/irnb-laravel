<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use App\Models\Role;
use App\Models\Suspension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class PlayerMatchesSectionTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function it_returns_season_totals_games_and_active_suspensions(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $g = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 1]);
        GamePlayer::create(['game_id' => $g->id, 'player_id' => $p->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 75]);
        $ban = Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(), 'reason' => 'Club']);

        $this->actingAs($this->admin())->getJson(route('games.players.show', ['player' => $p, 'season' => 2026]))
            ->assertOk()
            ->assertJsonPath('totals.0.minutes', 75)
            ->assertJsonPath('games.0.game_id', $g->id)
            ->assertJsonPath('suspensions.0.id', $ban->id)
            ->assertJsonPath('suspensions.0.reason', 'Club');
    }

    #[Test]
    public function it_needs_games_view(): void
    {
        $p = $this->player($this->category());
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id]);

        $this->actingAs($user)->getJson(route('games.players.show', $p))->assertForbidden();
    }
}
