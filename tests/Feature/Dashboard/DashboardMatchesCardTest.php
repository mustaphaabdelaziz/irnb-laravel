<?php

namespace Tests\Feature\Dashboard;

use App\Enums\GameStatus;
use App\Services\Dashboard\MatchesCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class DashboardMatchesCardTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function it_shows_the_next_three_and_last_three_with_a_coachs_teams_first(): void
    {
        $mine = $this->team();
        $other = $this->team();
        foreach (range(1, 4) as $i) {
            $this->game($other, ['date' => now()->addDays($i)->toDateString()]);
            $this->game($other, ['date' => now()->subDays($i)->toDateString(), 'status' => GameStatus::Played, 'our_score' => $i + 1, 'their_score' => 1]);
        }
        $myNext = $this->game($mine, ['date' => now()->addDays(10)->toDateString()]);
        $coach = $this->coach();
        $this->assignCoach($mine, $coach, \App\Support\Season::current()->startYear);

        $admin = app(MatchesCard::class)->get($this->admin());
        $this->assertCount(3, $admin['upcoming']);
        $this->assertCount(3, $admin['results']);
        $this->assertSame('W', $admin['results'][0]['outcome']);

        $forCoach = app(MatchesCard::class)->get($coach);
        $this->assertSame($myNext->id, $forCoach['upcoming'][0]['id']);
    }

    #[Test]
    public function the_overview_carries_the_card_only_with_games_view(): void
    {
        $this->game($this->team(), ['date' => now()->addDay()->toDateString()]);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('overview.matches.upcoming', 1));
    }
}
