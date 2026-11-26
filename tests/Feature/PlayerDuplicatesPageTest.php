<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerDuplicatesPageTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function merger(): User
    {
        return $this->userWith(['players' => ['view', 'merge']]);
    }

    #[Test]
    public function every_duplicates_route_needs_the_merge_right(): void
    {
        foreach (['index', 'dismiss', 'restore'] as $name) {
            $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.'.$name), $name);
        }

        $this->actingAs($this->userWith(['players' => ['view', 'add', 'edit', 'delete']]))
            ->get(route('players.duplicates.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_page_lists_pending_pairs_with_cards(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02', 'archived' => true]);

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/Duplicates')
                ->where('total', 1)
                ->where('filters.status', 'pending')
                ->where('pairs.data.0.a.id', $a->id)
                ->where('pairs.data.0.b.id', $b->id)
                ->where('pairs.data.0.b.archived', true)
                ->where('pairs.data.0.score', 140)
                ->where('pairs.data.0.reasons', ['same_name', 'same_birthdate'])
                ->where('pairs.data.0.status', 'pending'));
    }

    #[Test]
    public function criteria_filter_reaches_the_finder(): void
    {
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.index', ['criteria' => ['name', 'birthdate']]))
            ->assertInertia(fn (Assert $page) => $page->where('total', 0)->where('filters.criteria', ['name', 'birthdate']));
    }

    #[Test]
    public function dismiss_remembers_the_pair_and_undo_brings_it_back(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $user = $this->merger();

        $this->actingAs($user)
            ->post(route('players.duplicates.dismiss'), ['a' => $b->id, 'b' => $a->id])
            ->assertSessionHas('success', 'flash.dup.dismissed');

        $decision = PlayerDuplicateDecision::sole();
        $this->assertSame([$a->id, $b->id], [$decision->player_a_id, $decision->player_b_id]);
        $this->assertSame(PlayerDuplicateDecision::DISMISSED, $decision->status);
        $this->assertSame(['same_name'], $decision->reasons);
        $this->assertSame($user->id, $decision->decided_by);

        $this->actingAs($user)->get(route('players.duplicates.index'))
            ->assertInertia(fn (Assert $page) => $page->where('total', 0));
        $this->actingAs($user)->get(route('players.duplicates.index', ['status' => 'dismissed']))
            ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('pairs.data.0.decision_id', $decision->id));

        $this->actingAs($user)
            ->delete(route('players.duplicates.restore', $decision))
            ->assertSessionHas('success', 'flash.dup.restored');
        $this->assertDatabaseCount('player_duplicate_decisions', 0);
    }

    #[Test]
    public function a_player_cannot_be_dismissed_against_itself(): void
    {
        $a = $this->player();

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.dismiss'), ['a' => $a->id, 'b' => $a->id])
            ->assertSessionHasErrors('b');
    }

    #[Test]
    public function merged_decisions_cannot_be_undone(): void
    {
        $a = $this->player();
        $decision = PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => null, 'status' => PlayerDuplicateDecision::MERGED,
            'player_a_snapshot' => PlayerDuplicateDecision::snapshot($a), 'player_b_snapshot' => ['id' => 999, 'name' => 'Gone', 'membership_ids' => [], 'birthdate' => null, 'picture_url' => null, 'archived' => false],
            'kept_player_id' => $a->id, 'decided_at' => now(),
        ]);

        $this->actingAs($this->merger())->delete(route('players.duplicates.restore', $decision))->assertNotFound();
        $this->actingAs($this->merger())->get(route('players.duplicates.index', ['status' => 'merged']))
            ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('pairs.data.0.b.name', 'Gone'));
    }
}
