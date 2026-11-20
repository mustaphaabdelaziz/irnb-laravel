<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\RegistrationStatusChange;
use App\Models\Role;
use App\Models\User;
use App\Services\Player\RegistrationService;
use App\Services\Player\StatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class StatusHistoryManualTest extends TestCase
{
    use RefreshDatabase;
    use RegistrationFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['firstname' => 'Amine', 'lastname' => 'Admin']);
        $this->actingAs($this->admin);
    }

    private function registered(): int
    {
        return (int) PlayerStatus::where('code', 'registered')->value('id');
    }

    private function member(string $id = 'FX-1'): Player
    {
        $player = Player::create(['membership_id' => $id, 'firstname' => 'Ali', 'lastname' => 'Test', 'birthdate' => '2008-04-02']);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'join_date' => '2022-09-01', 'status_id' => $this->registered()]);

        return $player->refresh();
    }

    private function football(Player $player): BranchRegistration
    {
        return $player->registrations()->where('branch_id', $this->foot->id)->firstOrFail();
    }

    private function line(Player $player, string $origin = RegistrationStatusChange::ORIGIN_MANUAL): RegistrationStatusChange
    {
        return app(StatusHistory::class)->record($this->football($player), $this->registered(), Player::leftStatusId(), '2024-06-15', 'old', $this->admin, $origin);
    }

    #[Test]
    public function a_past_line_can_be_added_by_hand(): void
    {
        $player = $this->member();

        $this->post(route('players.status-changes.store', [$player, $this->football($player)]), [
            'from_status_id' => $this->registered(), 'to_status_id' => Player::leftStatusId(),
            'changed_on' => '2024-06-15', 'note' => 'studies abroad',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $line = RegistrationStatusChange::firstOrFail();
        $this->assertSame('manual', $line->origin);
        $this->assertSame('2024-06-15', $line->changed_on->toDateString());
        $this->assertSame('studies abroad', $line->note);
        $this->assertSame($this->admin->id, $line->user_id);
        // History only: the current status stays.
        $this->assertSame($this->registered(), (int) $this->football($player)->status_id);
    }

    #[Test]
    public function a_line_follows_the_same_date_rules(): void
    {
        $player = $this->member();
        $url = route('players.status-changes.store', [$player, $this->football($player)]);

        foreach ([now()->addDay()->toDateString(), '2007-01-01', '2022-08-31'] as $date) {
            $this->post($url, ['to_status_id' => Player::leftStatusId(), 'changed_on' => $date])->assertSessionHasErrors('changed_on');
        }
        $this->post($url, ['changed_on' => '2024-01-01'])->assertSessionHasErrors('to_status_id');

        $this->assertSame(0, RegistrationStatusChange::count());
    }

    #[Test]
    public function another_players_registration_or_line_is_refused(): void
    {
        $player = $this->member();
        $other = $this->member('FX-2');
        $line = $this->line($other);

        $this->post(route('players.status-changes.store', [$player, $this->football($other)]), [
            'to_status_id' => Player::leftStatusId(), 'changed_on' => '2024-06-15',
        ])->assertNotFound();
        $this->put(route('players.status-changes.update', [$player, $line]), ['changed_on' => '2024-06-16'])->assertNotFound();
        $this->delete(route('players.status-changes.destroy', [$player, $line]))->assertNotFound();

        $this->assertSame('2024-06-15', $line->fresh()->changed_on->toDateString());
    }

    #[Test]
    public function a_manual_line_can_be_corrected_in_full(): void
    {
        $player = $this->member();
        $line = $this->line($player);

        $this->put(route('players.status-changes.update', [$player, $line]), [
            'from_status_id' => null, 'to_status_id' => $this->registered(), 'changed_on' => '2024-07-01', 'note' => '',
        ])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame('2024-07-01', $line->changed_on->toDateString());
        $this->assertNull($line->note);
        $this->assertNull($line->from_status_id);
        $this->assertSame($this->registered(), $line->to_status_id);
    }

    #[Test]
    public function a_form_line_keeps_its_statuses_when_corrected(): void
    {
        $player = $this->member();
        $line = $this->line($player, RegistrationStatusChange::ORIGIN_FORM);

        $this->put(route('players.status-changes.update', [$player, $line]), [
            'to_status_id' => $this->registered(), 'changed_on' => '2024-07-01', 'note' => 'fixed',
        ])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(Player::leftStatusId(), $line->to_status_id);
        $this->assertSame('2024-07-01', $line->changed_on->toDateString());
        $this->assertSame('fixed', $line->note);
    }

    #[Test]
    public function moving_the_departure_line_moves_the_leave_date(): void
    {
        $player = $this->member();
        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->foot->id, 'status_id' => Player::leftStatusId(), 'status_changed_on' => '2025-06-15']],
        ])->assertSessionHasNoErrors();
        $line = RegistrationStatusChange::firstOrFail();

        $this->put(route('players.status-changes.update', [$player, $line]), ['changed_on' => '2025-05-31'])->assertSessionHasNoErrors();

        $this->assertSame('2025-05-31', $this->football($player)->left_at->toDateString());
        $this->assertSame('2025-05-31', $player->fresh()->left_at->toDateString());
    }

    #[Test]
    public function a_line_can_be_deleted_without_touching_the_current_status(): void
    {
        $player = $this->member();
        $line = $this->line($player);

        $this->delete(route('players.status-changes.destroy', [$player, $line]))->assertRedirect();

        $this->assertSame(0, RegistrationStatusChange::count());
        $this->assertSame($this->registered(), (int) $this->football($player)->status_id);
    }

    #[Test]
    public function changing_the_history_needs_the_players_edit_right(): void
    {
        $player = $this->member();
        $line = $this->line($player);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view', 'add', 'delete']]])->id,
        ]);
        $this->actingAs($viewer);

        $this->post(route('players.status-changes.store', [$player, $this->football($player)]), ['to_status_id' => Player::leftStatusId(), 'changed_on' => '2024-01-01'])->assertForbidden();
        $this->put(route('players.status-changes.update', [$player, $line]), ['changed_on' => '2024-01-01'])->assertForbidden();
        $this->delete(route('players.status-changes.destroy', [$player, $line]))->assertForbidden();
    }

    #[Test]
    public function the_player_page_lists_each_registrations_lines_with_their_author(): void
    {
        $player = $this->member();
        $this->line($player);

        $this->get(route('players.show', $player))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('player.registrations.0.status_history.0.note', 'old')
            ->where('player.registrations.0.status_history.0.changed_on', '2024-06-15')
            ->where('player.registrations.0.status_history.0.user', 'Amine Admin')
            ->where('player.registrations.0.status_history.0.origin', 'manual')
            ->where('player.registrations.0.status_history.0.to_status.id', Player::leftStatusId())
            ->has('playerStatuses'));
    }
}
