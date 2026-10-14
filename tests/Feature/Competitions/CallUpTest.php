<?php

namespace Tests\Feature\Competitions;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CallUpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_competition_match_calls_up_from_the_visible_squad_and_flags_injured_players(): void
    {
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $competition = $this->competition($team, 2026);
        $competition->players()->attach([$a->id => ['hidden' => false], $b->id => ['hidden' => false], $c->id => ['hidden' => true]]);
        $game = $this->game($team, ['competition_id' => $competition->id]);

        $session = TrainingSession::create([
            'category_id' => $team->category_id, 'date' => now()->subDays(2)->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'category_id' => $team->category_id, 'status' => AttendanceStatus::NotTraining->value]);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')
                ->has('candidates', 2)
                ->where('candidates.0.player_id', $a->id)->where('candidates.0.shirt_number', 1)->where('candidates.0.injured', false)
                ->where('candidates.1.player_id', $b->id)->where('candidates.1.injured', true));
    }

    #[Test]
    public function a_squad_member_no_longer_on_the_roster_is_not_a_candidate(): void
    {
        $team = $this->team();
        [$a, $gone] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a]);
        $competition = $this->competition($team, 2026);
        $competition->players()->attach([$a->id => ['hidden' => false], $gone->id => ['hidden' => false]]);
        $game = $this->game($team, ['competition_id' => $competition->id]);

        $this->actingAs($this->admin())->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 1)->where('candidates.0.player_id', $a->id));
    }

    #[Test]
    public function re_saving_an_old_call_up_keeps_a_departed_players_row(): void
    {
        $team = $this->team();
        [$a, $gone] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $gone]);
        $game = $this->game($team);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'shirt_number' => 1]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $gone->id, 'called_up' => true, 'shirt_number' => 2]);
        DB::table('team_players')->where('team_id', $team->id)->where('player_id', $gone->id)->delete();

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true],
        ]])->assertSessionHas('success', 'flash.call_up_saved');

        $row = GamePlayer::where('game_id', $game->id)->where('player_id', $gone->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->called_up);
        $this->assertSame(2, $row->shirt_number);
    }

    #[Test]
    public function a_friendly_calls_up_from_the_team_roster(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 1)->where('candidates.0.player_id', $a->id));
    }

    #[Test]
    public function the_call_up_is_saved_with_roster_shirts_and_reasons(): void
    {
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $game = $this->game($team);
        $admin = $this->admin();
        $save = fn (array $players) => $this->actingAs($admin)->put(route('games.call-up.update', $game), ['players' => $players]);

        $save([['player_id' => $outsider->id, 'called_up' => true]])->assertSessionHasErrors('players.0.player_id');

        $save([
            ['player_id' => $a->id, 'called_up' => true],
            ['player_id' => $b->id, 'called_up' => false, 'not_called_reason' => 'Exams'],
            ['player_id' => $c->id, 'called_up' => false, 'not_called_reason' => ''],
        ])->assertSessionHas('success', 'flash.call_up_saved');

        $rows = GamePlayer::where('game_id', $game->id)->orderBy('player_id')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]->called_up);
        $this->assertSame(1, $rows[0]->shirt_number);
        $this->assertFalse($rows[1]->called_up);
        $this->assertSame('Exams', $rows[1]->not_called_reason);
        $this->assertNull($rows[1]->shirt_number);
    }

    #[Test]
    public function a_call_up_after_the_roster_is_renumbered_follows_the_new_numbers(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b]);
        $game = $this->game($team);
        $admin = $this->admin();
        $save = fn (array $players) => $this->actingAs($admin)->put(route('games.call-up.update', $game), ['players' => $players]);

        $save([['player_id' => $a->id, 'called_up' => true]])->assertSessionHas('success');
        $this->assertSame(1, GamePlayer::where('player_id', $a->id)->value('shirt_number'));

        $roster = fn () => DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', 2026);
        $roster()->update(['shirt_number' => null]);
        $roster()->where('player_id', $a->id)->update(['shirt_number' => 5]);
        $roster()->where('player_id', $b->id)->update(['shirt_number' => 1]);

        $save([['player_id' => $a->id, 'called_up' => true], ['player_id' => $b->id, 'called_up' => true]])
            ->assertSessionHas('success', 'flash.call_up_saved');

        $this->assertSame(5, GamePlayer::where('game_id', $game->id)->where('player_id', $a->id)->value('shirt_number'));
        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('player_id', $b->id)->value('shirt_number'));
    }

    #[Test]
    public function uncalling_a_player_clears_the_lineup_fields(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team);
        GamePlayer::create([
            'game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'shirt_number' => 1,
            'role' => 'starter', 'slot' => 3, 'is_captain' => true, 'is_goalkeeper' => true,
        ]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => false, 'not_called_reason' => 'Injured'],
        ]])->assertSessionHas('success');

        $row = GamePlayer::where('game_id', $game->id)->where('player_id', $a->id)->firstOrFail();
        $this->assertFalse($row->called_up);
        $this->assertSame('none', $row->role);
        $this->assertNull($row->slot);
        $this->assertNull($row->shirt_number);
        $this->assertFalse($row->is_captain);
        $this->assertFalse($row->is_goalkeeper);
    }

    #[Test]
    public function the_call_up_respects_the_formats_maximum(): void
    {
        $team = $this->team();
        $team->defaultFormat->update(['max_called_up' => 1]);
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true], ['player_id' => $b->id, 'called_up' => true],
        ]])->assertSessionHasErrors('players');
        $this->assertSame(0, GamePlayer::count());
    }

    #[Test]
    public function only_the_assigned_coach_saves_the_call_up(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team);
        $coach = $this->coach();

        $this->actingAs($coach)->put(route('games.call-up.update', $game), ['players' => [['player_id' => $a->id, 'called_up' => true]]])
            ->assertForbidden();
    }

    #[Test]
    public function the_convocation_sheet_is_a_pdf(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team, ['meeting_time' => '13:30', 'meeting_place' => 'Club house']);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'shirt_number' => 1]);

        $response = $this->actingAs($this->admin())->get(route('games.convocation', $game));
        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function un_calling_a_player_refreshes_minutes_of_a_played_match(): void
    {
        $team = $this->team();
        $players = array_map(fn () => $this->player($team->category), range(1, 4));
        [$a, $b, $c, $d] = $players;
        $this->onRoster($team, 2026, $players);
        $game = $this->game($team);
        $game->update(['status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0]);
        foreach ($players as $p) {
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true,
                'role' => $p->id === $d->id ? 'bench' : 'starter']);
        }
        GameEvent::create(['game_id' => $game->id, 'type' => 'substitution', 'minute' => 60, 'player_id' => $d->id, 'related_player_id' => $c->id]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true],
            ['player_id' => $b->id, 'called_up' => true],
            ['player_id' => $c->id, 'called_up' => false],
            ['player_id' => $d->id, 'called_up' => true],
        ]])->assertSessionHasNoErrors();

        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertSame(90, $rows[$a->id]->minutes_played);
        $this->assertSame(0, $rows[$d->id]->minutes_played);
        $this->assertFalse($rows[$d->id]->played);
    }
}
