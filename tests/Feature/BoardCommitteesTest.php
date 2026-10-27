<?php

namespace Tests\Feature;

use App\Models\BoardCommittee;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\Player;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardCommitteesTest extends TestCase
{
    use RefreshDatabase;

    private BoardTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->term = BoardTerm::create(['name' => '2024–2028', 'is_current' => true]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $first = 'Sami'): Player
    {
        static $n = 0;
        $n++;

        return Player::create(['membership_id' => "M{$n}", 'firstname' => $first, 'lastname' => 'Zidane']);
    }

    private function seat(BoardCommittee $committee, Player $player, string $position)
    {
        return $this->post(route('board.committees.members.store', $committee), [
            'player_id' => $player->id, 'board_term_id' => $this->term->id, 'position' => $position,
        ]);
    }

    #[Test]
    public function default_roles_get_organigram_levels(): void
    {
        $this->assertSame('top', BoardRole::where('name', 'president')->value('level'));
        $this->assertSame('bureau', BoardRole::where('name', 'secretary')->value('level'));
        $this->assertSame('bureau', BoardRole::where('name', 'treasurer')->value('level'));
        $this->assertSame('member', BoardRole::where('name', 'member')->value('level'));
    }

    #[Test]
    public function seating_a_new_player_puts_them_on_the_board_with_a_member_role(): void
    {
        $committee = BoardCommittee::create(['name' => 'Finance']);
        $player = $this->player();

        $this->actingAs($this->admin());
        $this->seat($committee, $player, 'head')->assertSessionHasNoErrors();

        $member = BoardMember::firstOrFail();
        $this->assertSame($player->id, $member->player_id);
        $this->assertSame($this->term->id, $member->board_term_id);
        $this->assertSame('member', $member->role);
        $this->assertSame('head', $member->committees->first()->pivot->position);
    }

    #[Test]
    public function a_board_member_can_sit_on_several_committees(): void
    {
        $finance = BoardCommittee::create(['name' => 'Finance']);
        $media = BoardCommittee::create(['name' => 'Media']);
        $player = $this->player();

        $this->actingAs($this->admin());
        $this->seat($finance, $player, 'head');
        $this->seat($media, $player, 'member');

        $this->assertDatabaseCount('board_members', 1);
        $this->assertSame(2, BoardMember::firstOrFail()->committees()->count());
    }

    #[Test]
    public function a_committee_has_one_head_and_two_deputies(): void
    {
        $committee = BoardCommittee::create(['name' => 'Sports']);
        $this->actingAs($this->admin());

        $this->seat($committee, $this->player('A'), 'head')->assertSessionHasNoErrors();
        $this->seat($committee, $this->player('B'), 'head')->assertSessionHasErrors('position');

        $this->seat($committee, $this->player('C'), 'deputy')->assertSessionHasNoErrors();
        $this->seat($committee, $this->player('D'), 'deputy')->assertSessionHasNoErrors();
        $this->seat($committee, $this->player('E'), 'deputy')->assertSessionHasErrors('position');

        $this->seat($committee, $this->player('F'), 'member')->assertSessionHasNoErrors();
        $this->seat($committee, $this->player('G'), 'member')->assertSessionHasNoErrors();

        $this->assertSame(1, $committee->members()->wherePivot('position', 'head')->count());
        $this->assertSame(2, $committee->members()->wherePivot('position', 'deputy')->count());
    }

    #[Test]
    public function seat_limits_count_only_the_same_term(): void
    {
        $committee = BoardCommittee::create(['name' => 'Sports']);
        $old = BoardTerm::create(['name' => '2020–2024']);
        $oldMember = BoardMember::create(['name' => 'Old', 'player_id' => $this->player('Old')->id, 'board_term_id' => $old->id, 'role' => 'member', 'status' => 'former']);
        $committee->members()->attach($oldMember->id, ['position' => 'head']);

        $this->actingAs($this->admin());
        $this->seat($committee, $this->player('New'), 'head')->assertSessionHasNoErrors();
    }

    #[Test]
    public function changing_a_position_respects_the_limits(): void
    {
        $committee = BoardCommittee::create(['name' => 'Sports']);
        $this->actingAs($this->admin());
        $this->seat($committee, $this->player('A'), 'head');
        $this->seat($committee, $this->player('B'), 'member');
        $b = BoardMember::where('name', 'like', '%B%')->firstOrFail();

        $this->put(route('board.committees.members.update', [$committee, $b]), ['position' => 'head'])
            ->assertSessionHasErrors('position');
        $this->put(route('board.committees.members.update', [$committee, $b]), ['position' => 'deputy'])
            ->assertSessionHasNoErrors();

        $this->assertSame('deputy', $committee->members()->whereKey($b->id)->first()->pivot->position);
    }

    #[Test]
    public function leaving_a_committee_keeps_the_board_member(): void
    {
        $committee = BoardCommittee::create(['name' => 'Media']);
        $this->actingAs($this->admin());
        $this->seat($committee, $this->player(), 'member');
        $member = BoardMember::firstOrFail();

        $this->delete(route('board.committees.members.destroy', [$committee, $member]))->assertRedirect();

        $this->assertSame(0, $committee->members()->count());
        $this->assertModelExists($member);
    }

    #[Test]
    public function picking_a_seated_player_for_a_role_slot_changes_their_role(): void
    {
        $committee = BoardCommittee::create(['name' => 'Finance']);
        $player = $this->player();
        $this->actingAs($this->admin());
        $this->seat($committee, $player, 'head');

        $this->post(route('board.members.store'), [
            'player_id' => $player->id, 'board_term_id' => $this->term->id, 'role' => 'treasurer', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('board_members', 1);
        $member = BoardMember::firstOrFail();
        $this->assertSame('treasurer', $member->role);
        $this->assertSame(1, $member->committees()->count());
    }

    #[Test]
    public function a_committee_in_use_cannot_be_deleted(): void
    {
        $committee = BoardCommittee::create(['name' => 'Finance']);
        $empty = BoardCommittee::create(['name' => 'Media']);
        $this->actingAs($this->admin());
        $this->seat($committee, $this->player(), 'member');

        $this->delete(route('board-committees.destroy', $committee))->assertSessionHas('error', 'flash.committee_in_use');
        $this->delete(route('board-committees.destroy', $empty))->assertSessionHas('success', 'flash.committee_deleted');

        $this->assertModelExists($committee);
        $this->assertModelMissing($empty);
    }

    #[Test]
    public function committees_are_their_own_configuration_list(): void
    {
        $this->assertSame(['board_committees', 'view'], PermissionMap::resolve('board-committees.index'));
        $this->assertSame(['board', 'add'], PermissionMap::resolve('board.committees.members.store'));
        $this->assertSame(['board', 'delete'], PermissionMap::resolve('board.meetings.destroy'));
    }

    #[Test]
    public function the_members_page_carries_committees_and_role_levels(): void
    {
        $committee = BoardCommittee::create(['name' => 'Finance']);
        $this->actingAs($this->admin());
        $this->seat($committee, $this->player(), 'deputy');

        $this->get(route('board.members'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Board/Members')
                ->where('committees.0.name', 'Finance')
                ->where('members.0.committees.0.pivot.position', 'deputy')
                ->has('roles.0.level'));
    }
}
