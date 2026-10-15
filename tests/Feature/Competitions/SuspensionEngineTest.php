<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Suspension;
use App\Services\Competitions\SuspensionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SuspensionEngineTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private const CARDS = [
        ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
        ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
        ['key' => 'two_minutes', 'ends_play' => false, 'counts_as' => null],
    ];

    /** A played competition game on $date where each [player, key] got a sanction. */
    private function played(Competition $competition, string $date, array $sanctions = [], GameStatus $status = GameStatus::Played): Game
    {
        $game = $this->game($competition->team, ['competition_id' => $competition->id, 'date' => $date, 'status' => $status, 'our_score' => 0, 'their_score' => 0]);
        $game->update(['rules' => ['sanction_types' => self::CARDS] + $game->rules]);
        foreach ($sanctions as [$player, $key]) {
            GamePlayer::firstOrCreate(['game_id' => $game->id, 'player_id' => $player->id], ['called_up' => true]);
            GameEvent::create(['game_id' => $game->id, 'type' => 'sanction', 'subtype' => $key, 'minute' => 30, 'player_id' => $player->id]);
        }

        return $game;
    }

    #[Test]
    public function a_red_card_bans_for_the_competitions_red_ban_matches(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['red_ban_matches' => 2]);
        $p = $this->player($team->category);
        $game = $this->played($competition, '2026-10-01', [[$p, 'red']]);

        app(SuspensionEngine::class)->recompute($competition);

        $ban = Suspension::sole();
        $this->assertSame(['auto', 'matches', 2, 'red', $game->id, $competition->id, $team->id],
            [$ban->source, $ban->kind, $ban->matches_count, $ban->cause, $ban->game_id, $ban->competition_id, $ban->team_id]);
    }

    #[Test]
    public function yellows_add_up_to_the_threshold_then_the_count_restarts(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['yellow_threshold' => 2, 'yellow_ban_matches' => 1]);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'yellow']]);
        $second = $this->played($competition, '2026-10-08', [[$p, 'yellow'], [$p, 'two_minutes']]);
        $this->played($competition, '2026-10-15', [[$p, 'yellow']]);

        app(SuspensionEngine::class)->recompute($competition);

        $ban = Suspension::sole();
        $this->assertSame(['yellows', $second->id, 1], [$ban->cause, $ban->game_id, $ban->matches_count]);
    }

    #[Test]
    public function no_threshold_no_ban_and_zero_matches_means_no_ban(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['yellow_threshold' => null, 'red_ban_matches' => 0]);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'yellow'], [$p, 'yellow'], [$p, 'red']]);

        app(SuspensionEngine::class)->recompute($competition);

        $this->assertSame(0, Suspension::count());
    }

    #[Test]
    public function only_played_games_count_and_manual_bans_survive_a_recompute(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'red']], GameStatus::Postponed);
        Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 3, 'starts_on' => '2026-10-01']);

        app(SuspensionEngine::class)->recompute($competition);

        $this->assertSame(['manual'], Suspension::pluck('source')->all());
    }

    #[Test]
    public function saving_a_report_or_changing_the_rules_recomputes(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['red_ban_matches' => 1]);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $game = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-01']);
        $game->update(['rules' => ['sanction_types' => self::CARDS] + $game->rules]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true, 'role' => 'starter']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('games.report.update', $game), [
            'period_scores' => [[0, 0], [0, 0]], 'extra_time_played' => false, 'our_score' => null, 'their_score' => null,
            'shootout_us' => null, 'shootout_them' => null, 'mvp_player_id' => null, 'notes' => null, 'players' => [],
            'events' => [['type' => 'sanction', 'subtype' => 'red', 'period' => 1, 'minute' => 40, 'player_id' => $p->id, 'related_player_id' => null]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Suspension::sole()->matches_count);

        $this->actingAs($admin)->put(route('competitions.update', $competition), [
            'name' => $competition->name, 'type' => 'league', 'game_format_id' => null,
            'red_ban_matches' => 3, 'yellow_threshold' => null, 'yellow_ban_matches' => 1, 'notes' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, Suspension::sole()->matches_count);

        $this->actingAs($admin)->delete(route('games.destroy', $game))->assertRedirect();
        $this->assertSame(0, Suspension::count());
    }
}
