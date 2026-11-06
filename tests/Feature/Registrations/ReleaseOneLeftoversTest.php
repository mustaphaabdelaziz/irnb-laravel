<?php

namespace Tests\Feature\Registrations;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ReleaseOneLeftoversTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create());
        $this->service = app(RegistrationService::class);
    }

    private function left(): int
    {
        return (int) PlayerStatus::where('code', 'left')->value('id');
    }

    private function base(array $extra = []): array
    {
        return ['firstname' => 'Both', 'lastname' => 'Test', ...$extra];
    }

    /** What the form sends for a card. */
    private function card(Branch $branch, array $extra = []): array
    {
        return [
            'branch_id' => $branch->id, 'join_date' => null, 'category_id' => null, 'position_id' => null,
            'other_position_ids' => '', 'licence_number' => null, 'status_id' => (int) PlayerStatus::where('code', 'registered')->value('id'),
            'left_at' => null, 'sporting_status_id' => null, ...$extra,
        ];
    }

    #[Test]
    public function a_stale_form_leaves_a_newer_registration_alone(): void
    {
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'membership_id' => $this->fixtureId(), 'join_year' => 2020]);
        $this->service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        // The form opens here, showing football only. Someone else then adds swimming.
        $this->service->register($player, ['branch_id' => $this->swim->id, 'join_date' => '2026-01-01']);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['category_id' => $this->senior->id])],
        ]))->assertSessionHasNoErrors();

        $this->assertNotSame($this->left(), $player->registrations()->where('branch_id', $this->swim->id)->value('status_id'));
        $this->assertNull($player->registrations()->where('branch_id', $this->swim->id)->value('left_at'));
    }

    #[Test]
    public function an_explicit_removal_is_the_left_status_on_the_card(): void
    {
        $player = $this->bothActive();

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['category_id' => $this->senior->id]),
                $this->card($this->swim, ['category_id' => $this->u15->id, 'status_id' => $this->left(), 'left_at' => '2026-03-15']),
            ],
        ]))->assertSessionHasNoErrors();

        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame($this->left(), (int) $swim->status_id);
        $this->assertSame('2026-03-15', $swim->left_at->toDateString());
    }

    #[Test]
    public function a_first_and_second_card_together_keep_the_second_branchs_position(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $crawl->id]);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['join_date' => '2026-01-01']),
                $this->card($this->swim, ['join_date' => '2026-01-01']),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$crawl->id], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function a_position_the_second_card_picks_is_not_also_an_other_one(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = Player::create(['firstname' => 'Both', 'lastname' => 'Test', 'membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $crawl->id]);

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot, ['join_date' => '2026-01-01']),
                $this->card($this->swim, ['join_date' => '2026-01-01', 'position_id' => $crawl->id]),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([], $player->otherPositions()->pluck('positions.id')->all());
        $this->assertSame($crawl->id, $player->registrations()->where('branch_id', $this->swim->id)->value('position_id'));
    }

    #[Test]
    public function the_members_dialog_keeps_a_left_player_undated(): void
    {
        $player = Player::create(['firstname' => 'Gone', 'lastname' => 'Test', 'membership_id' => $this->fixtureId()]);
        $player->forceFill(['status_id' => $this->left(), 'left_at' => null])->saveQuietly();

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$player->id], 'join_date' => '2020-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertNull($player->registrations()->where('branch_id', $this->foot->id)->value('left_at'));
        $this->assertNull(DB::table('players')->where('id', $player->id)->value('left_at'));
    }

    #[Test]
    public function a_new_card_with_the_left_status_and_no_date_still_stamps_today(): void
    {
        $this->travelTo('2026-05-14 10:00:00');
        $player = Player::create(['firstname' => 'New', 'lastname' => 'Test', 'membership_id' => $this->fixtureId()]);

        $registration = $this->service->register($player, ['branch_id' => $this->foot->id, 'status_id' => $this->left(), 'left_at' => null]);

        $this->assertSame('2026-05-14', $registration->fresh()->left_at->toDateString());
    }

    #[Test]
    public function the_activity_log_names_the_player_on_a_queue_save(): void
    {
        $player = $this->bothActive();
        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->put(route('players.join-dates.update', $row), ['join_date' => '2022-05-05'])->assertSessionHasNoErrors();

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertSame((new Player)->getMorphClass(), $log->subject_type);
        $this->assertSame($player->id, $log->subject_id);
        $this->assertSame($this->swim->id, $log->properties['branch_id']);
    }

    #[Test]
    public function the_activity_log_names_the_player_on_a_registration_delete(): void
    {
        $player = $this->bothActive();
        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $row]))->assertSessionHas('success');

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertSame($player->id, $log->subject_id);
        $this->assertSame((new Player)->getMorphClass(), $log->subject_type);
    }

    #[Test]
    public function the_bulk_message_counts_only_who_changed(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'membership_id' => $this->fixtureId(), 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'status_id', 'value' => PlayerStatus::where('code', 'registered')->value('id'), 'branch_id' => $this->swim->id,
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }

    #[Test]
    public function the_bulk_message_skips_a_position_no_registration_can_hold(): void
    {
        $player = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'membership_id' => $this->fixtureId(), 'join_year' => 2020]);
        $this->service->register($player, ['branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);

        $this->post(route('players.bulkUpdate'), ['ids' => [$player->id], 'field' => 'position_id', 'value' => $crawl->id])
            ->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 0]]);
    }

    #[Test]
    public function the_bulk_message_skips_removing_from_a_branch_they_are_not_in(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'membership_id' => $this->fixtureId(), 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'branches', 'value' => [$this->swim->id], 'mode' => 'detach',
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }

    #[Test]
    public function the_bulk_message_skips_adding_a_branch_they_already_hold(): void
    {
        $inBoth = $this->bothActive('A');
        $onlyFoot = Player::create(['firstname' => 'B', 'lastname' => 'Test', 'membership_id' => $this->fixtureId(), 'join_year' => 2020]);
        $this->service->register($onlyFoot, ['branch_id' => $this->foot->id]);

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$inBoth->id, $onlyFoot->id], 'field' => 'branches', 'value' => [$this->swim->id], 'mode' => 'attach', 'join_date' => '2026-01-01',
        ])->assertSessionHas('success', ['key' => 'flash.players_updated', 'params' => ['count' => 1]]);
    }
}
