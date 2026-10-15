<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\MatchStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function playedGame($team, array $extra = []): Game
    {
        $game = $this->game($team, $extra + ['status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        $game->update(['rules' => ['sanction_types' => [
            ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
            ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
            ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
        ]] + $game->rules]);

        return $game;
    }

    #[Test]
    public function totals_count_appearances_starts_minutes_goals_assists_cards_ratings_and_motm(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $g1 = $this->playedGame($team, ['date' => '2026-10-01']);
        $g2 = $this->playedGame($team, ['date' => '2026-10-08']);
        $scheduled = $this->game($team, ['date' => '2026-10-20']);
        foreach ([$g1, $g2] as $g) {
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90, 'rating' => 8, 'is_mvp' => $g->is($g1)]);
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $b->id, 'called_up' => true, 'role' => 'bench', 'played' => $g->is($g2), 'minutes_played' => $g->is($g2) ? 20 : 0]);
        }
        GamePlayer::create(['game_id' => $scheduled->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90]);
        GameEvent::create(['game_id' => $g1->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id, 'related_player_id' => $b->id]);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'goal', 'subtype' => 'opponent_own_goal']);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'sanction', 'subtype' => 'yellow', 'player_id' => $a->id]);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'sanction', 'subtype' => 'foul', 'player_id' => $a->id]);

        $rows = collect(app(MatchStats::class)->players(2026, $team->id))->keyBy('player_id');

        $this->assertSame([2, 2, 180, 1, 0, 1, 0, 1, 8.0, 2, 1], [
            $rows[$a->id]['appearances'], $rows[$a->id]['starts'], $rows[$a->id]['minutes'], $rows[$a->id]['goals'], $rows[$a->id]['assists'],
            $rows[$a->id]['yellows'], $rows[$a->id]['reds'], $rows[$a->id]['other_sanctions'], $rows[$a->id]['avg_rating'], $rows[$a->id]['rated'], $rows[$a->id]['motm'],
        ]);
        $this->assertSame([1, 0, 20, 1], [$rows[$b->id]['appearances'], $rows[$b->id]['starts'], $rows[$b->id]['minutes'], $rows[$b->id]['assists']]);
        $this->assertSame('football', $rows[$a->id]['sport']);

        $leaders = MatchStats::leaderboards($rows->values()->all());
        $this->assertSame($a->id, $leaders['goals'][0]['player_id']);
        $this->assertSame([], $leaders['rating'], 'rating board needs 3 rated matches');

        $games = app(MatchStats::class)->games($a->id, 2026);
        $this->assertCount(2, $games);
        $this->assertSame(1, collect($games)->firstWhere('game_id', $g1->id)['goals']);
    }

    #[Test]
    public function volleyball_sets_have_their_own_board_and_never_enter_minutes(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $g = $this->playedGame($team);
        $g->update(['rules' => ['sport' => 'volleyball'] + $g->rules]);
        GamePlayer::create(['game_id' => $g->id, 'player_id' => $p->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'sets_played' => 3]);

        $leaders = MatchStats::leaderboards(app(MatchStats::class)->players(2026, $team->id));

        $this->assertSame($p->id, $leaders['sets'][0]['player_id']);
        $this->assertNotContains($p->id, array_column($leaders['minutes'], 'player_id'));
    }

    #[Test]
    public function team_and_competition_pages_carry_stats(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $a = $this->player($team->category);
        $g = $this->playedGame($team, ['competition_id' => $competition->id]);
        GamePlayer::create(['game_id' => $g->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90]);
        GameEvent::create(['game_id' => $g->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('teams.show', ['team' => $team, 'season' => 2026]))
            ->assertInertia(fn (Assert $page) => $page->where('stats.leaders.goals.0.player_id', $a->id)->where('stats.record.wins', 1));
        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->where('stats.leaders.goals.0.goals', 1));
    }
}
