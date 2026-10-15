<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\MatchSheets;
use App\Services\Competitions\PitchDrawing;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchSheetsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function playedMatch(): array
    {
        $team = $this->team();
        $formation = Formation::create(['game_format_id' => $team->default_game_format_id, 'name' => '1-1', 'slots' => [
            ['label' => 'GK', 'x' => 50, 'y' => 5], ['label' => 'ST', 'x' => 50, 'y' => 80],
        ]]);
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $game = $this->game($team, ['formation_id' => $formation->id, 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0, 'period_scores' => [[1, 0], [0, 0]]]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'slot' => 0, 'shirt_number' => 1, 'is_goalkeeper' => true, 'minutes_played' => 90, 'played' => true, 'rating' => 9, 'coach_note' => 'SECRET-NOTE']);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $b->id, 'called_up' => true, 'role' => 'starter', 'slot' => 1, 'shirt_number' => 9, 'is_captain' => true, 'minutes_played' => 60, 'played' => true, 'is_mvp' => true]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $c->id, 'called_up' => true, 'role' => 'bench', 'shirt_number' => 12, 'minutes_played' => 30, 'played' => true]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 20, 'player_id' => $b->id, 'related_player_id' => $a->id]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'substitution', 'minute' => 60, 'player_id' => $c->id, 'related_player_id' => $b->id]);

        return [$game, $a, $b, $c];
    }

    #[Test]
    public function the_line_up_sheet_data_lists_starters_bench_and_pitch_dots(): void
    {
        [$game, $a, $b, $c] = $this->playedMatch();
        $data = app(MatchSheets::class)->lineup($game);

        $this->assertSame([1, 9], array_column($data['starters'], 'shirt'));
        $this->assertTrue($data['starters'][1]['captain']);
        $this->assertTrue($data['starters'][0]['goalkeeper']);
        $this->assertSame('GK', $data['starters'][0]['position']);
        $this->assertSame([$c->fullname], array_column($data['bench'], 'name'));
        $this->assertStringContainsString('<svg', $data['pitch']);
    }

    #[Test]
    public function the_report_data_has_events_minutes_and_mvp_but_never_ratings_or_notes(): void
    {
        [$game, $a, $b, $c] = $this->playedMatch();
        $data = app(MatchSheets::class)->report($game);

        $this->assertSame([1, 0], [$data['score']['us'], $data['score']['them']]);
        $this->assertSame([[1, 0], [0, 0]], $data['score']['periods']);
        $this->assertSame($b->fullname, $data['goals'][0]['scorer']);
        $this->assertSame($a->fullname, $data['goals'][0]['assist']);
        $this->assertSame([$c->fullname, $b->fullname], [$data['subs'][0]['in'], $data['subs'][0]['out']]);
        $this->assertSame($b->fullname, $data['mvp']);
        $this->assertSame(60, collect($data['players'])->firstWhere('name', $b->fullname)['minutes']);
        $flat = json_encode($data);
        $this->assertStringNotContainsString('SECRET-NOTE', $flat);
        $this->assertStringNotContainsString('rating', $flat);
    }

    #[Test]
    public function both_sheets_stream_as_pdfs_for_viewers(): void
    {
        [$game] = $this->playedMatch();
        $admin = $this->admin();

        foreach (['games.lineup-sheet', 'games.report-sheet'] as $route) {
            $response = $this->actingAs($admin)->get(route($route, $game));
            $response->assertOk();
            $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
            $this->assertSame(['games', 'view'], PermissionMap::resolve($route));
        }
    }

    #[Test]
    public function the_pitch_drawing_places_dots_in_svg_coordinates(): void
    {
        $svg = PitchDrawing::svg('football', [['x' => 50, 'y' => 0, 'label' => '1']]);

        $this->assertStringContainsString('cx="34"', $svg);
        $this->assertStringContainsString('cy="105"', $svg);
        $this->assertStringContainsString('>1<', $svg);
    }
}
