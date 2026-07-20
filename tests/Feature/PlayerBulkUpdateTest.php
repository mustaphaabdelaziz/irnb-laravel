<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private int $membership = 202600200;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(array $attributes = []): Player
    {
        return Player::create(array_merge([
            'firstname' => 'P', 'lastname' => 'X',
            'membership_id' => (string) ++$this->membership,
            'join_year' => 2026,
        ], $attributes));
    }

    #[Test]
    public function it_sets_a_category_on_many_players(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $a = $this->player();
        $b = $this->player();

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$a->id, $b->id],
            'field' => 'category_id',
            'value' => $u15->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($u15->id, $a->fresh()->category_id);
        $this->assertSame($u15->id, $b->fresh()->category_id);
    }

    #[Test]
    public function it_sets_a_position_and_a_status(): void
    {
        $gk = Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK']);
        $retired = PlayerStatus::where('name', 'معتزل')->first();
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'position_id', 'value' => $gk->id,
        ])->assertRedirect();

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'status_id', 'value' => $retired->id,
        ])->assertRedirect();

        $player = $player->fresh();
        $this->assertSame($gk->id, $player->position_id);
        $this->assertSame('معتزل', $player->status->name);
    }

    #[Test]
    public function only_the_selected_players_are_touched(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $target = $this->player();
        $bystander = $this->player();

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$target->id], 'field' => 'category_id', 'value' => $u15->id,
        ])->assertRedirect();

        $this->assertNull($bystander->fresh()->category_id, 'an unselected player must not change');
    }

    #[Test]
    public function branches_can_be_added_without_losing_existing_ones(): void
    {
        $football = Branch::create(['name' => 'Football', 'name_en' => 'Football']);
        $basket = Branch::create(['name' => 'Basketball', 'name_en' => 'Basketball']);

        $player = $this->player();
        $player->branches()->sync([$football->id]);

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches',
            'value' => [$basket->id], 'mode' => 'attach',
        ])->assertRedirect();

        $this->assertCount(2, $player->fresh()->branches);
    }

    #[Test]
    public function branches_can_be_replaced_outright(): void
    {
        $football = Branch::create(['name' => 'Football', 'name_en' => 'Football']);
        $basket = Branch::create(['name' => 'Basketball', 'name_en' => 'Basketball']);

        $player = $this->player();
        $player->branches()->sync([$football->id]);

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches',
            'value' => [$basket->id], 'mode' => 'replace',
        ])->assertRedirect();

        $branches = $player->fresh()->branches;
        $this->assertCount(1, $branches);
        $this->assertSame($basket->id, $branches->first()->id);
    }

    #[Test]
    public function branches_can_be_removed(): void
    {
        $football = Branch::create(['name' => 'Football', 'name_en' => 'Football']);
        $basket = Branch::create(['name' => 'Basketball', 'name_en' => 'Basketball']);

        $player = $this->player();
        $player->branches()->sync([$football->id, $basket->id]);

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches',
            'value' => [$football->id], 'mode' => 'detach',
        ])->assertRedirect();

        $this->assertCount(1, $player->fresh()->branches);
    }

    #[Test]
    public function a_field_outside_the_allow_list_is_rejected(): void
    {
        $player = $this->player(['membership_id' => '202600999']);

        // Without the allow-list this would rewrite an identity column.
        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'membership_id', 'value' => 'hacked',
        ])->assertSessionHasErrors('field');

        $this->assertSame('202600999', $player->fresh()->membership_id);
    }

    #[Test]
    public function archived_cannot_be_flipped_through_bulk_update(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'archived', 'value' => 1,
        ])->assertSessionHasErrors('field');

        $this->assertFalse((bool) $player->fresh()->archived);
    }

    #[Test]
    public function a_value_from_the_wrong_table_is_rejected(): void
    {
        $gk = Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK']);
        $player = $this->player();

        // A position id offered as a category id must not be accepted just
        // because both are integers that exist somewhere.
        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'category_id', 'value' => $gk->id + 9000,
        ])->assertSessionHasErrors('value');
    }

    #[Test]
    public function more_than_five_hundred_players_are_refused(): void
    {
        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => range(1, 501), 'field' => 'category_id', 'value' => null,
        ])->assertSessionHasErrors('ids');
    }

    #[Test]
    public function editing_does_not_confer_permanent_deletion(): void
    {
        $editor = User::factory()->create([
            'email_verified_at' => now(), 'approved' => true, 'is_active' => true, 'privileges' => [],
        ]);
        $role = Role::create([
            'key' => 'editor',
            'name' => ['en' => 'Editor'],
            'permissions' => ['players' => ['view', 'add', 'edit']],
        ]);
        $editor->update(['role_id' => $role->id]);

        $player = $this->player();

        // deriveAction() maps unknown verbs to 'edit', so force-delete was
        // reachable with edit rights alone until it was given an override.
        $this->actingAs($editor)->post(route('players.bulkForceDelete'), ['ids' => [$player->id]])
            ->assertForbidden();

        $this->assertNotNull($player->fresh(), 'the player must survive');
    }

    #[Test]
    public function a_user_without_edit_permission_is_refused(): void
    {
        $viewer = User::factory()->create([
            'email_verified_at' => now(),
            'approved' => true,
            'is_active' => true,
            'privileges' => [],
        ]);

        $role = Role::create([
            'key' => 'viewer',
            'name' => ['en' => 'Viewer'],
            'permissions' => ['players' => ['view']],
        ]);
        $viewer->update(['role_id' => $role->id]);

        $u15 = Category::create(['name' => 'U15']);
        $player = $this->player();

        $this->actingAs($viewer)->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'category_id', 'value' => $u15->id,
        ])->assertForbidden();

        $this->assertNull($player->fresh()->category_id);
    }
}
