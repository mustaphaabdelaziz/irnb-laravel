<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMemberTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function adding_a_member_from_a_player_fills_the_name(): void
    {
        $term = BoardTerm::create(['name' => '2024–2028', 'is_current' => true]);
        $player = Player::create(['membership_id' => 'M1', 'firstname' => 'Sami', 'lastname' => 'Zidane']);

        $this->actingAs($this->admin())
            ->post(route('board.members.store'), [
                'player_id' => $player->id,
                'board_term_id' => $term->id,
                'role' => 'president',
                'status' => 'active',
            ])->assertRedirect();

        $member = BoardMember::firstOrFail();
        $this->assertSame('Zidane Sami', $member->name);
        $this->assertSame($player->id, $member->player_id);
        $this->assertSame($term->id, $member->board_term_id);
    }

    #[Test]
    public function a_member_must_be_picked_from_the_players(): void
    {
        $this->actingAs($this->admin())
            ->post(route('board.members.store'), [
                'name' => 'Typed by hand',
                'role' => 'president',
                'status' => 'active',
            ])->assertSessionHasErrors('player_id');

        $this->assertDatabaseCount('board_members', 0);
    }

    #[Test]
    public function contact_details_and_photo_come_from_the_player(): void
    {
        $player = Player::create([
            'membership_id' => 'M3', 'firstname' => 'Sami', 'lastname' => 'Zidane',
            'email' => 'sami@club.test', 'phones' => ['0550000001', '0550000002'],
            'picture_url' => '/media/players/sami.jpg',
        ]);
        $member = BoardMember::create([
            'player_id' => $player->id, 'name' => 'Old copy', 'role' => 'president', 'status' => 'active',
            'email' => 'stale@club.test', 'phone' => '000', 'photo_url' => '/media/board/stale.jpg',
        ]);

        $member = BoardMember::withPlayer()->findOrFail($member->id);

        $this->assertSame('Zidane Sami', $member->name);
        $this->assertSame('sami@club.test', $member->email);
        $this->assertSame('0550000001', $member->phone);
        $this->assertSame('/media/players/sami.jpg', $member->photo_url);
    }

    #[Test]
    public function renaming_a_player_renames_their_board_member(): void
    {
        $player = Player::create(['membership_id' => 'M4', 'firstname' => 'Sami', 'lastname' => 'Zidane']);
        $member = BoardMember::create(['player_id' => $player->id, 'name' => 'Zidane Sami', 'role' => 'member', 'status' => 'active']);

        $player->update(['firstname' => 'Samir']);

        $this->assertSame('Zidane Samir', $member->fresh()->name);
    }

    #[Test]
    public function an_unknown_role_is_rejected(): void
    {
        $player = Player::create(['membership_id' => 'M2', 'firstname' => 'A', 'lastname' => 'B']);

        $this->actingAs($this->admin())
            ->post(route('board.members.store'), [
                'player_id' => $player->id,
                'role' => 'not-a-real-role',
                'status' => 'active',
            ])->assertSessionHasErrors('role');
    }

    #[Test]
    public function renaming_a_role_cascades_to_members(): void
    {
        $role = BoardRole::create(['name' => 'coordinator', 'sort_order' => 9]);
        $member = BoardMember::create(['name' => 'X', 'role' => 'coordinator', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->put(route('board-roles.update', $role), ['name' => 'lead_coordinator', 'sort_order' => 9])
            ->assertRedirect();

        $this->assertSame('lead_coordinator', $member->fresh()->role);
    }

    #[Test]
    public function a_role_in_use_cannot_be_deleted(): void
    {
        $role = BoardRole::create(['name' => 'auditor', 'sort_order' => 9]);
        BoardMember::create(['name' => 'Y', 'role' => 'auditor', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->delete(route('board-roles.destroy', $role))
            ->assertRedirect();

        $this->assertDatabaseHas('board_roles', ['id' => $role->id]);
    }
}
