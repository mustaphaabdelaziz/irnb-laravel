<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BranchFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
    }

    private function player(string $membershipId): Player
    {
        return Player::create(['firstname' => 'P'.$membershipId, 'membership_id' => $membershipId, 'join_year' => 2024]);
    }

    #[Test]
    public function members_can_be_assigned_from_the_branches_menu(): void
    {
        $branch = Branch::create(['name' => 'Swimming']);
        $a = $this->player('202400001');
        $b = $this->player('202400002');

        $this->actingAs($this->admin())
            ->post(route('branches.players.sync', $branch), ['player_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $branch->fresh()->players->pluck('id')->all());

        // Re-syncing replaces the set; an empty array clears it.
        $this->actingAs($this->admin())
            ->post(route('branches.players.sync', $branch), ['player_ids' => []])
            ->assertRedirect();
        $this->assertCount(0, $branch->fresh()->players);
    }

    #[Test]
    public function the_branches_page_exposes_players_and_member_ids(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $p = $this->player('202400003');
        $branch->players()->sync([$p->id]);

        $this->actingAs($this->admin())
            ->get(route('branches.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Branches')
                ->has('players', 1)
                ->has('branches.0.players', 1));
    }

    #[Test]
    public function it_creates_a_branch_with_locale_names(): void
    {
        $this->actingAs($this->admin())
            ->post(route('branches.store'), [
                'name' => 'Swimming',
                'name_ar' => 'السباحة',
                'name_fr' => 'Natation',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branches', ['name' => 'Swimming', 'name_ar' => 'السباحة']);
    }

    #[Test]
    public function creating_a_player_syncs_selected_branches(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $foot = Branch::create(['name' => 'Football']);

        $this->actingAs($this->admin())
            ->post(route('players.store'), [
                'firstname' => 'Ali',
                'branch_ids' => [$swim->id, $foot->id],
            ])
            ->assertRedirect();

        $player = Player::firstOrFail();
        $this->assertEqualsCanonicalizing([$swim->id, $foot->id], $player->branches->pluck('id')->all());
    }

    #[Test]
    public function updating_a_player_replaces_its_branches(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $foot = Branch::create(['name' => 'Football']);
        $player = Player::create(['firstname' => 'Ali', 'membership_id' => '202400001', 'join_year' => 2024, 'is_student' => true]);
        $player->branches()->sync([$swim->id]);

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), [
                'firstname' => 'Ali',
                'join_year' => 2024,
                'branch_ids' => [$foot->id],
            ])
            ->assertRedirect();

        $this->assertSame([$foot->id], $player->fresh()->branches->pluck('id')->all());
    }

    #[Test]
    public function the_list_can_be_filtered_by_branch(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $inSwim = Player::create(['firstname' => 'A', 'membership_id' => '202400001', 'join_year' => 2024]);
        $notInSwim = Player::create(['firstname' => 'B', 'membership_id' => '202400002', 'join_year' => 2024]);
        $inSwim->branches()->sync([$swim->id]);

        $this->actingAs($this->admin())
            ->get(route('players.export', ['branch_id' => $swim->id]))
            ->assertOk();

        $content = $this->get(route('players.export', ['branch_id' => $swim->id]))->streamedContent();
        $this->assertStringContainsString('202400001', $content);
        $this->assertStringNotContainsString('202400002', $content);
    }
}
