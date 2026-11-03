<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A date shared by bulk edit or the Members dialog is for a genuinely new registration only. */
class SharedDateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $this->service = app(RegistrationService::class);
    }

    /** A player who left football holding a dateless row. */
    private function leaver(string $id = '201800005'): Player
    {
        $player = Player::create(['firstname' => 'L', 'membership_id' => $id, 'join_year' => 2018]);
        $player->branches()->attach($this->foot->id, ['membership_id' => $id, 'category_id' => null, 'legacy_join_year' => 2018]);
        $this->service->leave($player, [$this->foot->id], '2024-01-01');

        return $player;
    }

    private function bulk(array $ids, ?string $date, int $branchId): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('players.bulkUpdate'), array_filter([
            'ids' => $ids, 'field' => 'branches', 'mode' => 'attach', 'value' => [$branchId], 'join_date' => $date,
        ], fn ($v) => $v !== null));
    }

    private function dialog(Branch $branch, array $ids, ?string $date): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('branches.players.sync', $branch), array_filter([
            'player_ids' => $ids, 'join_date' => $date,
        ], fn ($v) => $v !== null));
    }

    private function assertUntouched(Player $player): void
    {
        $row = $player->registrations()->firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame('201800005', $row->membership_id);
        $this->assertSame(2018, $row->legacy_join_year);
        $fresh = $player->fresh();
        $this->assertSame('201800005', $fresh->membership_id);
        $this->assertSame(2018, (int) $fresh->join_year);
    }

    #[Test]
    public function bulk_rejoin_leaves_date_id_and_year_alone(): void
    {
        $player = $this->leaver();

        $this->bulk([$player->id], '2026-11-01', $this->foot->id)->assertSessionHasNoErrors();

        $this->assertUntouched($player);
        $this->assertFalse(Player::isLeftStatus($player->registrations()->firstOrFail()->status_id));
    }

    #[Test]
    public function dialog_rejoin_leaves_date_id_and_year_alone_even_beside_a_new_member(): void
    {
        $player = $this->leaver();
        $newcomer = Player::create(['firstname' => 'N', 'membership_id' => '202000001', 'join_year' => null]);

        $this->dialog($this->foot, [$player->id, $newcomer->id], '2026-11-01')->assertSessionHasNoErrors();

        $this->assertUntouched($player);
        $this->assertSame('2026-11-01', $newcomer->registrations()->firstOrFail()->join_date->toDateString());
    }

    #[Test]
    public function bulk_first_registration_of_a_year_holder_keeps_year_and_id(): void
    {
        $holder = Player::create(['firstname' => 'H', 'membership_id' => '201500001', 'join_year' => 2015]);

        $this->bulk([$holder->id], null, $this->foot->id)->assertSessionHasNoErrors();

        $row = $holder->registrations()->firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame(2015, $row->legacy_join_year);
        $this->assertSame('201500001', $row->membership_id);
        $this->assertSame(2015, (int) $holder->fresh()->join_year);
    }

    #[Test]
    public function dialog_first_registration_of_a_year_holder_keeps_year_and_id(): void
    {
        $holder = Player::create(['firstname' => 'H', 'membership_id' => '201500001', 'join_year' => 2015]);

        $this->dialog($this->foot, [$holder->id], '2026-11-01')->assertSessionHasNoErrors();

        $row = $holder->registrations()->firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame(2015, $row->legacy_join_year);
        $this->assertSame('201500001', $row->membership_id);
        $this->assertSame(2015, (int) $holder->fresh()->join_year);
    }

    #[Test]
    public function a_player_with_no_year_takes_the_date_on_both_screens(): void
    {
        $a = Player::create(['firstname' => 'A', 'membership_id' => '202000001', 'join_year' => null]);
        $b = Player::create(['firstname' => 'B', 'membership_id' => '202000002', 'join_year' => null]);

        $this->bulk([$a->id], '2026-11-01', $this->foot->id)->assertSessionHasNoErrors();
        $this->dialog($this->swim, [$b->id], '2026-11-02')->assertSessionHasNoErrors();

        $this->assertSame('2026-11-01', $a->registrations()->firstOrFail()->join_date->toDateString());
        $this->assertSame('2026-11-02', $b->registrations()->firstOrFail()->join_date->toDateString());
    }

    #[Test]
    public function a_player_already_in_one_branch_takes_the_date_for_another(): void
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        $this->bulk([$player->id], '2025-03-10', $this->swim->id)->assertSessionHasNoErrors();

        $this->assertSame('2025-03-10', $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail()->join_date->toDateString());
    }

    #[Test]
    public function only_rejoiners_selected_needs_no_date(): void
    {
        $player = $this->leaver();

        $this->bulk([$player->id], null, $this->foot->id)->assertSessionHasNoErrors();
        $this->assertUntouched($player);

        $this->service->leave($player, [$this->foot->id]);
        $this->dialog($this->foot, [$player->id], null)->assertSessionHasNoErrors();
        $this->assertUntouched($player);
    }

    #[Test]
    public function a_new_member_with_no_date_is_refused_and_nothing_is_written(): void
    {
        $rejoiner = $this->leaver();
        $newcomer = Player::create(['firstname' => 'N', 'membership_id' => '202000001', 'join_year' => null]);

        $this->bulk([$rejoiner->id, $newcomer->id], null, $this->foot->id)->assertSessionHasErrors('join_date');
        $this->assertSame(0, $newcomer->registrations()->count());
        $this->assertTrue(Player::isLeftStatus($rejoiner->registrations()->firstOrFail()->status_id));

        $this->dialog($this->foot, [$rejoiner->id, $newcomer->id], null)->assertSessionHasErrors('join_date');
        $this->assertSame(0, $newcomer->registrations()->count());
        $this->assertTrue(Player::isLeftStatus($rejoiner->registrations()->firstOrFail()->status_id));
    }

    #[Test]
    public function a_date_before_a_takers_birth_is_refused_and_nothing_is_written(): void
    {
        $young = Player::create(['firstname' => 'Y', 'membership_id' => '202000001', 'join_year' => null, 'birthdate' => '2015-06-01']);
        $other = Player::create(['firstname' => 'O', 'membership_id' => '202000002', 'join_year' => null, 'birthdate' => '1990-01-01']);

        $this->bulk([$other->id, $young->id], '2001-01-01', $this->foot->id)->assertSessionHasErrors('join_date');
        $this->dialog($this->foot, [$other->id, $young->id], '2001-01-01')->assertSessionHasErrors('join_date');

        $this->assertSame(0, $young->registrations()->count());
        $this->assertSame(0, $other->registrations()->count());
    }

    #[Test]
    public function a_birth_date_after_the_shared_date_does_not_block_a_player_who_takes_none(): void
    {
        $holder = Player::create(['firstname' => 'H', 'membership_id' => '201500001', 'join_year' => 2015, 'birthdate' => '2015-06-01']);

        $this->bulk([$holder->id], '2001-01-01', $this->foot->id)->assertSessionHasNoErrors();

        $this->assertSame(1, $holder->registrations()->count());
    }

    #[Test]
    public function the_edit_form_always_offers_the_left_status_even_when_inactive(): void
    {
        PlayerStatus::where('code', 'left')->update(['is_active' => false]);
        $player = $this->leaver();
        $left = PlayerStatus::where('code', 'left')->value('id');

        foreach ([route('players.create'), route('players.edit', $player)] as $url) {
            $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
                ->where('playerStatuses', fn ($statuses) => collect($statuses)->contains('id', $left))
                ->etc());
        }
    }

    #[Test]
    public function the_main_branch_cannot_be_deleted_while_another_exists(): void
    {
        $this->delete(route('branches.destroy', $this->foot))->assertSessionHas('error', 'flash.branch_is_main');

        $this->assertNotNull($this->foot->fresh());
    }

    #[Test]
    public function the_only_branch_can_be_deleted_even_when_main(): void
    {
        $this->swim->delete();

        $this->delete(route('branches.destroy', $this->foot))->assertSessionHas('success', 'flash.branch_deleted');

        $this->assertNull($this->foot->fresh());
    }
}
