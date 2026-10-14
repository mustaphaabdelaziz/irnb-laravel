<?php

namespace Tests\Feature\Competitions;

use App\Models\Opponent;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class OpponentVenueSettingsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function opponents_are_listed_added_renamed_and_kept_unique(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('opponents.store'), ['name' => 'MC Alger', 'city' => 'Alger'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.opponent_created');
        $this->actingAs($admin)->post(route('opponents.store'), ['name' => 'MC Alger'])->assertSessionHasErrors('name');

        $opponent = Opponent::sole();
        $this->actingAs($admin)->put(route('opponents.update', $opponent), ['name' => 'MC Alger U17', 'city' => ''])
            ->assertSessionHas('success', 'flash.opponent_updated');
        $this->assertSame('MC Alger U17', $opponent->fresh()->name);
        $this->assertNull($opponent->fresh()->city);

        $this->actingAs($admin)->get(route('opponents.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Opponents')
                ->has('opponents', 1)->where('opponents.0.games_count', 0));
    }

    #[Test]
    public function an_opponent_with_matches_cannot_be_deleted(): void
    {
        $team = $this->team();
        $this->game($team);
        $opponent = Opponent::sole();

        $this->actingAs($this->admin())->delete(route('opponents.destroy', $opponent))
            ->assertSessionHas('error', 'flash.opponent_in_use');
        $this->assertModelExists($opponent);
    }

    #[Test]
    public function venues_are_managed_and_deleting_one_keeps_its_matches(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('venues.store'), ['name' => 'Stade 5 Juillet', 'address' => 'Alger', 'is_home' => true])
            ->assertSessionHas('success', 'flash.venue_created');
        $venue = Venue::sole();
        $this->assertTrue($venue->is_home);

        $game = $this->game($this->team(), ['venue_id' => $venue->id]);
        $this->actingAs($admin)->delete(route('venues.destroy', $venue))->assertSessionHas('success', 'flash.venue_deleted');

        $this->assertNull($game->fresh()->venue_id);
        $this->actingAs($admin)->get(route('venues.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Venues')->has('venues', 0));
    }
}
