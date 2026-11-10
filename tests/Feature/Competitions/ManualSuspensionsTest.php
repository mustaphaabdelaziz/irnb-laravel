<?php

namespace Tests\Feature\Competitions;

use App\Models\Suspension;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class ManualSuspensionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_manual_ban_is_added_in_matches_or_dates_and_listed(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $this->inSquad($competition, $p);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 2, 'reason' => 'Federation letter 12/26',
        ])->assertSessionHas('success', 'flash.suspension_added');
        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'all', 'kind' => 'dates', 'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame([['competition', 2], ['all', null]],
            Suspension::orderBy('id')->get()->map(fn ($s) => [$s->competition_id ? 'competition' : 'all', $s->matches_count])->all());
        $this->assertSame($admin->id, Suspension::first()->created_by);

        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->has('suspensions', 2)->where('suspensions.1.reason', 'Federation letter 12/26')->where('suspensions.0.upcoming', true)); // both listed with the active bans, newest first
    }

    #[Test]
    public function validation_and_only_manual_bans_can_be_deleted(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $this->inSquad($competition, $p);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), ['player_id' => $outsider->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 1])
            ->assertSessionHasErrors('player_id');
        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), ['player_id' => $p->id, 'scope' => 'competition', 'kind' => 'dates', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-01'])
            ->assertSessionHasErrors('ends_on');

        $auto = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 1]);
        $this->actingAs($admin)->delete(route('competitions.suspensions.destroy', [$competition, $auto]))->assertForbidden();

        $manual = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1]);
        $this->actingAs($admin)->delete(route('competitions.suspensions.destroy', [$competition, $manual]))->assertSessionHas('success', 'flash.suspension_removed');
        $this->assertModelMissing($manual);
    }

    #[Test]
    public function the_tab_marks_only_this_competitions_manual_bans_deletable_and_others_are_refused(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $other = $this->competition($this->team(), 2026, ['name' => 'Other league']);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $admin = $this->admin();
        $mine = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);
        $teamWide = Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);
        $elsewhere = Suspension::create(['player_id' => $p->id, 'team_id' => $other->team_id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);
        $foreign = Suspension::create(['player_id' => $p->id, 'competition_id' => $other->id, 'team_id' => $other->team_id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);

        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->has('suspensions', 3)
                ->where('suspensions', fn ($rows) => collect($rows)->pluck('deletable', 'id')->sortKeys()->all() === [$mine->id => true, $teamWide->id => true, $elsewhere->id => false]));

        $this->actingAs($admin)->delete(route('competitions.suspensions.destroy', [$competition, $foreign]))->assertForbidden();
        $this->assertModelExists($foreign);
    }

    #[Test]
    public function coaches_are_scoped_and_routes_need_competition_edit(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);

        $this->actingAs($this->coach())->post(route('competitions.suspensions.store', $competition), ['player_id' => $p->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 1])
            ->assertForbidden();
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.suspensions.store'));
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.suspensions.destroy'));
    }

    private function inSquad($competition, $player, bool $hidden = false): void
    {
        DB::table('competition_players')->insert([
            'competition_id' => $competition->id, 'player_id' => $player->id, 'hidden' => $hidden,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_ban_for_this_competition_takes_squad_players_only_while_all_competitions_takes_the_roster(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        [$inSquad, $hidden, $rosterOnly] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$inSquad, $hidden, $rosterOnly]);
        $this->inSquad($competition, $inSquad);
        $this->inSquad($competition, $hidden, true);
        $admin = $this->admin();
        $ban = fn ($player, string $scope) => $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $player->id, 'scope' => $scope, 'kind' => 'matches', 'matches_count' => 1,
        ]);

        $ban($rosterOnly, 'competition')->assertSessionHasErrors('player_id');
        $ban($hidden, 'competition')->assertSessionHasErrors('player_id');
        $this->assertSame(0, Suspension::count());

        $ban($inSquad, 'competition')->assertSessionHasNoErrors();
        $ban($rosterOnly, 'all')->assertSessionHasNoErrors();
        $this->assertSame(2, Suspension::count());
    }
}
