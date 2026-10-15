<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchReportTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    /** Two starters, two on the bench. */
    private function match(array $format = []): array
    {
        $team = $this->team();
        if ($format !== []) {
            $team->defaultFormat->update($format);
        }
        $players = array_map(fn () => $this->player($team->category), range(1, 4));
        $this->onRoster($team, 2026, $players);
        $game = $this->game($team);
        foreach ($players as $i => $p) {
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true,
                'shirt_number' => $i + 1, 'role' => $i < 2 ? 'starter' : 'bench']);
        }

        return [$game, $players];
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'period_scores' => [[1, 0], [1, 1]], 'extra_time_played' => false,
            'our_score' => null, 'their_score' => null, 'shootout_us' => null, 'shootout_them' => null,
            'events' => [], 'players' => [], 'mvp_player_id' => null, 'notes' => null,
        ];
    }

    #[Test]
    public function saving_the_report_plays_the_match_derives_the_score_and_computes_minutes(): void
    {
        [$game, [$a, $b, $c, $d]] = $this->match();

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload([
            'events' => [
                ['type' => 'goal', 'subtype' => 'normal', 'period' => 1, 'minute' => 12, 'player_id' => $a->id, 'related_player_id' => $b->id],
                ['type' => 'goal', 'subtype' => 'penalty', 'period' => 2, 'minute' => null, 'player_id' => $b->id, 'related_player_id' => null],
                ['type' => 'substitution', 'subtype' => null, 'period' => 2, 'minute' => 70, 'player_id' => $c->id, 'related_player_id' => $a->id],
            ],
            'players' => [
                ['player_id' => $a->id, 'rating' => 8, 'coach_note' => 'Sharp'],
                ['player_id' => $c->id, 'rating' => 6, 'coach_note' => null],
            ],
            'mvp_player_id' => $a->id,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.report_saved');

        $game->refresh();
        $this->assertSame(GameStatus::Played, $game->status);
        $this->assertSame([2, 1], [$game->our_score, $game->their_score]);
        $this->assertSame(3, $game->events()->count());
        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertSame(70, $rows[$a->id]->minutes_played);
        $this->assertSame(90, $rows[$b->id]->minutes_played);
        $this->assertSame(20, $rows[$c->id]->minutes_played);
        $this->assertFalse($rows[$d->id]->played);
        $this->assertTrue($rows[$a->id]->is_mvp);
        $this->assertSame(8, $rows[$a->id]->rating);
        $this->assertSame('Sharp', $rows[$a->id]->coach_note);
    }

    #[Test]
    public function without_period_rows_the_typed_score_is_required(): void
    {
        [$game] = $this->match();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('games.report.update', $game), $this->payload(['period_scores' => [[null, null], [null, null]]]))
            ->assertSessionHasErrors('our_score');
        $this->actingAs($admin)->put(route('games.report.update', $game), $this->payload(['period_scores' => [], 'our_score' => 3, 'their_score' => 2]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $game->fresh()->our_score);
    }

    #[Test]
    public function rolling_formats_take_typed_minutes_and_volleyball_counts_sets(): void
    {
        [$game, [$a, $b]] = $this->match(['rolling_subs' => true, 'scoring' => 'sets', 'periods_count' => 5, 'period_minutes' => 0, 'sets_to_win' => 3]);

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload([
            'period_scores' => [[25, 20], [25, 22], [20, 25], [25, 18], [null, null]],
            'players' => [
                ['player_id' => $a->id, 'played' => true, 'sets_played' => 4],
                ['player_id' => $b->id, 'played' => false],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([3, 1], [$game->fresh()->our_score, $game->fresh()->their_score]);
        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertTrue($rows[$a->id]->played);
        $this->assertSame(4, $rows[$a->id]->sets_played);
        $this->assertFalse($rows[$b->id]->played);
    }

    #[Test]
    public function the_page_shows_the_report_with_warnings(): void
    {
        [$game, [$a]] = $this->match(['max_substitutions' => 0]);
        $game->update(['status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.rules.length', 90)
                ->has('report.events', 1)
                ->has('report.players', 4)
                ->where('report.warnings', ['comp.warn.goals_mismatch']));
    }

    #[Test]
    public function a_re_saved_report_replaces_its_events_and_coaches_are_scoped(): void
    {
        [$game, [$a]] = $this->match();
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload(['period_scores' => [[0, 0], [0, 0]]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $game->events()->count());

        $this->actingAs($this->coach())->put(route('games.report.update', $game), $this->payload())->assertForbidden();
    }
}
