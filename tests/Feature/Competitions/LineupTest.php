<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class LineupTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    /** A team with a 3-slot formation and $n called-up players. */
    private function matchWithCallUp(int $n = 4): array
    {
        $team = $this->team();
        $team->defaultFormat->update(['players_on_field' => 3, 'max_bench' => 1]);
        $formation = Formation::create(['game_format_id' => $team->default_game_format_id, 'name' => '1-2', 'slots' => [
            ['label' => 'GK', 'x' => 50, 'y' => 5], ['label' => 'LB', 'x' => 30, 'y' => 30], ['label' => 'ST', 'x' => 50, 'y' => 80],
        ]]);
        $players = array_map(fn () => $this->player($team->category), range(1, $n));
        $this->onRoster($team, 2026, $players);
        $game = $this->game($team);
        foreach ($players as $i => $p) {
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true, 'shirt_number' => $i + 1]);
        }

        return [$game, $formation, $players, $team];
    }

    private function row($player, string $role, ?int $slot = null, array $extra = []): array
    {
        return $extra + ['player_id' => $player->id, 'role' => $role, 'slot' => $slot, 'pos_x' => null, 'pos_y' => null,
            'shirt_number' => null, 'is_captain' => false, 'is_goalkeeper' => false];
    }

    #[Test]
    public function the_match_page_carries_the_line_up_with_formations_and_the_bench(): void
    {
        [$game, $formation, $players] = $this->matchWithCallUp(2);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')
                ->where('lineup.players_on_field', 3)
                ->where('lineup.has_goalkeeper', true)
                ->where('lineup.formations.0.id', $formation->id)
                ->has('lineup.players', 2)
                ->where('lineup.players.0.role', 'bench'));
    }

    #[Test]
    public function the_line_up_is_saved_with_slot_positions_shirt_overrides_and_free_placement(): void
    {
        [$game, $formation, [$a, $b, $c, $d], $team] = $this->matchWithCallUp();
        $lb = Position::create(['name' => 'Left back', 'abbreviation' => 'LB', 'branch_id' => $team->branch_id]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0, ['is_goalkeeper' => true, 'shirt_number' => 1]),
            $this->row($b, 'starter', 1, ['pos_x' => 20, 'pos_y' => 40, 'shirt_number' => 14]),
            $this->row($c, 'starter', 2, ['is_captain' => true, 'shirt_number' => 3]),
            $this->row($d, 'bench'),
        ]])->assertSessionHas('success', 'flash.lineup_saved');

        $bRow = GamePlayer::where('game_id', $game->id)->where('player_id', $b->id)->sole();
        $this->assertSame('starter', $bRow->role);
        $this->assertSame(1, $bRow->slot);
        $this->assertSame([20, 40], [$bRow->pos_x, $bRow->pos_y]);
        $this->assertSame($lb->id, $bRow->position_id);
        $this->assertSame(14, $bRow->shirt_number);
        $this->assertTrue($bRow->shirt_override);
        $this->assertFalse(GamePlayer::where('player_id', $a->id)->sole()->shirt_override);
        $this->assertSame($formation->id, $game->fresh()->formation_id);
    }

    #[Test]
    public function line_up_integrity_rules_block_the_save(): void
    {
        [$game, $formation, [$a, $b, $c, $d]] = $this->matchWithCallUp();
        $outsider = $this->player($game->team->category);
        $admin = $this->admin();
        $save = fn (array $players) => $this->actingAs($admin)->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => $players]);

        $save([$this->row($a, 'starter', 0), $this->row($b, 'starter', 1), $this->row($c, 'starter', 2), $this->row($d, 'starter', 2)])
            ->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0), $this->row($b, 'starter', 0)])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['is_captain' => true]), $this->row($b, 'starter', 1, ['is_captain' => true])])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['is_goalkeeper' => true]), $this->row($b, 'starter', 1, ['is_goalkeeper' => true])])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['shirt_number' => 7]), $this->row($b, 'starter', 1, ['shirt_number' => 7])])->assertSessionHasErrors('players.1.shirt_number');
        $save([$this->row($outsider, 'starter', 0)])->assertSessionHasErrors('players.0.player_id');
    }

    #[Test]
    public function players_left_out_of_the_payload_go_to_the_bench(): void
    {
        [$game, $formation, [$a, $b]] = $this->matchWithCallUp(2);
        GamePlayer::where('player_id', $b->id)->update(['role' => 'starter', 'slot' => 2]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0),
        ]])->assertSessionHasNoErrors();

        $this->assertSame('bench', GamePlayer::where('player_id', $b->id)->sole()->role);
        $this->assertNull(GamePlayer::where('player_id', $b->id)->sole()->slot);
    }

    #[Test]
    public function a_saved_line_up_recomputes_minutes_once_the_match_has_a_report(): void
    {
        [$game, $formation, [$a, $b, $c, $d]] = $this->matchWithCallUp();
        $game->update(['status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'substitution', 'minute' => 60, 'player_id' => $d->id, 'related_player_id' => $c->id]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0), $this->row($b, 'starter', 1), $this->row($c, 'starter', 2), $this->row($d, 'bench'),
        ]])->assertSessionHasNoErrors();

        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertSame(90, $rows[$a->id]->minutes_played);
        $this->assertSame(60, $rows[$c->id]->minutes_played);
        $this->assertSame(30, $rows[$d->id]->minutes_played);
        $this->assertTrue($rows[$d->id]->played);
    }

    #[Test]
    public function only_the_assigned_coach_saves_the_line_up(): void
    {
        [$game, $formation, [$a]] = $this->matchWithCallUp(1);
        $coach = $this->coach();

        $this->actingAs($coach)->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [$this->row($a, 'bench')]])
            ->assertForbidden();
    }

    #[Test]
    public function the_call_up_keeps_a_per_match_shirt_and_un_calling_deletes_the_players_events(): void
    {
        [$game, , [$a, $b]] = $this->matchWithCallUp(2);
        GamePlayer::where('player_id', $a->id)->update(['shirt_number' => 9, 'shirt_override' => true]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $b->id, 'related_player_id' => $a->id]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id, 'related_player_id' => $b->id]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true],
            ['player_id' => $b->id, 'called_up' => false],
        ]])->assertSessionHas('success', 'flash.call_up_saved_events_removed');

        $this->assertSame(9, GamePlayer::where('player_id', $a->id)->sole()->shirt_number);
        $events = GameEvent::where('game_id', $game->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame($a->id, $events[0]->player_id);
        $this->assertNull($events[0]->related_player_id);
    }
}
