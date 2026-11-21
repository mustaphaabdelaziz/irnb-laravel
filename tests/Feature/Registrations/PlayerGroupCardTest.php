<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerGroupCardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private Category $cadet;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private TrainingGroup $c1;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 10:00:00');
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->c1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1']);
        $this->player = Player::create(['firstname' => 'Sara', 'lastname' => 'Test', 'membership_id' => '202400001', 'join_year' => 2024]);
        app(RegistrationService::class)->register($this->player, [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'join_date' => '2024-09-01',
        ]);
    }

    /** The player form's payload with one swimming card; $extra adds or overrides the card's fields. */
    private function formWith(array $extra = []): array
    {
        return ['firstname' => 'Sara', 'lastname' => 'Test', 'registrations' => [
            $extra + ['branch_id' => $this->swim->id, 'join_date' => '2024-09-01', 'category_id' => $this->minime->id],
        ]];
    }

    /** @return list<array{int, ?string, ?string}> [group id, from, to] of the player's memberships, oldest first */
    private function memberships(?Player $player = null): array
    {
        return TrainingGroupMember::where('player_id', ($player ?? $this->player)->id)->orderBy('id')->get()
            ->map(fn (TrainingGroupMember $m) => [$m->training_group_id, $m->from_date, $m->to_date])->all();
    }

    #[Test]
    public function a_first_assignment_has_no_start_date(): void
    {
        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g1->id, 'group_from' => '2026-11-10']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function changing_the_group_from_a_date_keeps_the_history(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g2->id, 'group_from' => '2026-11-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-14'], [$this->g2->id, '2026-11-15', null]], $this->memberships());
    }

    #[Test]
    public function changing_the_group_with_no_date_replaces_the_history(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g2->id, 'group_from' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g2->id, null, null]], $this->memberships());
    }

    #[Test]
    public function clearing_the_group_takes_the_player_out_from_the_date(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => null, 'group_from' => '2026-11-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-14']], $this->memberships());
    }

    #[Test]
    public function a_card_that_does_not_speak_of_the_group_leaves_the_membership_open(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['licence_number' => 'L9']))
            ->assertSessionHasNoErrors();

        $this->assertSame('L9', $this->player->registrations()->sole()->licence_number);
        $this->assertSame([[$this->g1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function sending_the_group_already_held_changes_nothing(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, '2026-10-01');

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g1->id, 'group_from' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, '2026-10-01', null]], $this->memberships());
    }

    #[Test]
    public function a_group_of_another_category_is_refused(): void
    {
        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->c1->id]))
            ->assertSessionHasErrors('registrations.0.group_id');

        $this->assertSame([], $this->memberships());
    }

    #[Test]
    public function a_category_change_with_a_new_group_moves_the_player_that_day(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith([
            'category_id' => $this->cadet->id, 'group_id' => $this->c1->id, 'group_from' => '2026-11-20',
        ]))->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-19'], [$this->c1->id, '2026-11-20', null]], $this->memberships());
    }

    #[Test]
    public function a_category_change_needs_the_date_so_the_old_group_history_stays(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        foreach ([$this->c1->id, null] as $groupId) {
            $this->put(route('players.update', $this->player), $this->formWith([
                'category_id' => $this->cadet->id, 'group_id' => $groupId, 'group_from' => null,
            ]))->assertSessionHasErrors('registrations.0.group_from');
        }

        $this->assertSame($this->minime->id, $this->player->registrations()->sole()->category_id);
        $this->assertSame([[$this->g1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function a_category_change_with_no_group_held_needs_no_date(): void
    {
        $this->put(route('players.update', $this->player), $this->formWith([
            'category_id' => $this->cadet->id, 'group_id' => $this->c1->id, 'group_from' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertSame([[$this->c1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function a_new_player_can_start_in_a_group(): void
    {
        $this->post(route('players.store'), [
            'firstname' => 'Lina', 'lastname' => 'New',
            'registrations' => [[
                'branch_id' => $this->swim->id, 'join_date' => '2026-11-01', 'category_id' => $this->minime->id, 'group_id' => $this->g2->id,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lina = Player::where('firstname', 'Lina')->sole();
        $this->assertSame([[$this->g2->id, null, null]], $this->memberships($lina));
    }

    #[Test]
    public function the_form_and_the_player_page_carry_the_groups(): void
    {
        $this->get(route('players.create'))->assertInertia(fn (Assert $page) => $page
            ->has('trainingGroups', 3)
            ->where('trainingGroups.0', ['id' => $this->c1->id, 'branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1'])
            ->etc());

        // Nothing held: an empty object, not a list.
        $this->get(route('players.edit', $this->player))->assertInertia(fn (Assert $page) => $page
            ->has('trainingGroups', 3)->where('playerGroups', [])->etc());

        app(GroupMembership::class)->add($this->g1, $this->player->id, null);
        $key = "playerGroups.{$this->swim->id}";

        $this->get(route('players.edit', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where("{$key}.id", $this->g1->id)->where("{$key}.name", 'G1')->etc());
        $this->get(route('players.show', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where("{$key}.name", 'G1')->etc());
    }

    #[Test]
    public function a_membership_left_in_another_category_is_not_shown(): void
    {
        app(GroupMembership::class)->add($this->c1, $this->player->id, null);

        $this->get(route('players.show', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where('playerGroups', [])->etc());
    }
}
