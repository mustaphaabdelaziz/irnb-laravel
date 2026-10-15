<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class ClubStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function seedStats(): array
    {
        [$t1, $t2] = [$this->team(), $this->team()];
        [$a, $b] = [$this->player($t1->category), $this->player($t2->category)];
        foreach ([[$t1, $a], [$t2, $b]] as [$team, $player]) {
            $g = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $player->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90, 'rating' => 7, 'coach_note' => 'NOTE-X']);
        }

        return [$t1, $t2, $a, $b];
    }

    #[Test]
    public function the_page_lists_all_players_and_filters_by_team(): void
    {
        [$t1, , $a] = $this->seedStats();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.stats', ['season' => 2026]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Stats')->has('rows', 2));
        $this->actingAs($admin)->get(route('games.stats', ['season' => 2026, 'team_id' => $t1->id]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.player_id', $a->id));
    }

    #[Test]
    public function exports_never_carry_ratings_or_notes(): void
    {
        $this->seedStats();
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get(route('games.stats.export', ['season' => 2026, 'format' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringNotContainsString('NOTE-X', $body);
        $this->assertStringNotContainsString('7.0', $body);

        $pdf = $this->actingAs($admin)->get(route('games.stats.export', ['season' => 2026, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.stats'));
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.stats.export'));
    }
}
