<?php

namespace Tests\Feature\Competitions;

use App\Models\Suspension;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 2, 'reason' => 'Federation letter 12/26',
        ])->assertSessionHas('success', 'flash.suspension_added');
        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'all', 'kind' => 'dates', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame([['competition', 2], ['all', null]],
            Suspension::orderBy('id')->get()->map(fn ($s) => [$s->competition_id ? 'competition' : 'all', $s->matches_count])->all());
        $this->assertSame($admin->id, Suspension::first()->created_by);

        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->has('suspensions', 2)->where('suspensions.0.reason', 'Federation letter 12/26')); // active first; the date ban starts in November
    }

    #[Test]
    public function validation_and_only_manual_bans_can_be_deleted(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
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
}
