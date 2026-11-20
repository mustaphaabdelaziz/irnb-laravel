<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\RegistrationStatusChange;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class StatusHistoryFormTest extends TestCase
{
    use RefreshDatabase;
    use RegistrationFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function registered(): int
    {
        return (int) PlayerStatus::where('code', 'registered')->value('id');
    }

    private function left(): int
    {
        return (int) Player::leftStatusId();
    }

    /** A football-only player who joined on 2022-09-01, born 2008. */
    private function member(): Player
    {
        $player = Player::create(['membership_id' => 'FX-1', 'firstname' => 'Ali', 'lastname' => 'Test', 'birthdate' => '2008-04-02']);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'join_date' => '2022-09-01', 'status_id' => $this->registered()]);

        return $player->refresh();
    }

    private function football(Player $player): BranchRegistration
    {
        return $player->registrations()->where('branch_id', $this->foot->id)->firstOrFail();
    }

    private function save(Player $player, array $card): TestResponse
    {
        return $this->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'registrations' => [['branch_id' => $this->foot->id, ...$card]],
        ]);
    }

    #[Test]
    public function leaving_in_the_form_writes_a_dated_line_with_its_note_and_author(): void
    {
        $player = $this->member();

        $this->save($player, ['status_id' => $this->left(), 'status_changed_on' => '2025-06-15', 'status_note' => 'moved'])
            ->assertSessionHasNoErrors();

        $registration = $this->football($player);
        $this->assertSame('2025-06-15', $registration->left_at->toDateString());
        $lines = $registration->statusChanges;
        $this->assertCount(1, $lines);
        $this->assertSame($this->registered(), $lines[0]->from_status_id);
        $this->assertSame($this->left(), $lines[0]->to_status_id);
        $this->assertSame('2025-06-15', $lines[0]->changed_on->toDateString());
        $this->assertSame('moved', $lines[0]->note);
        $this->assertSame($this->admin->id, $lines[0]->user_id);
        $this->assertSame('form', $lines[0]->origin);
    }

    #[Test]
    public function an_unchanged_status_writes_nothing(): void
    {
        $player = $this->member();

        $this->save($player, ['status_id' => $this->registered(), 'status_changed_on' => '2025-06-15'])->assertSessionHasNoErrors();

        $this->assertSame(0, RegistrationStatusChange::count());
    }

    #[Test]
    public function a_return_keeps_the_departure_the_id_and_the_join_date(): void
    {
        $player = $this->member();
        $id = $this->football($player)->membership_id;
        $this->save($player, ['status_id' => $this->left(), 'status_changed_on' => '2025-06-15'])->assertSessionHasNoErrors();

        $this->save($player->refresh(), ['status_id' => $this->registered(), 'status_changed_on' => '2026-09-01', 'status_note' => 'back'])
            ->assertSessionHasNoErrors();

        $registration = $this->football($player);
        $this->assertNull($registration->left_at);
        $this->assertSame($id, $registration->membership_id);
        $this->assertSame('2022-09-01', $registration->join_date->toDateString());
        $this->assertSame(
            [[$this->registered(), $this->left(), '2025-06-15'], [$this->left(), $this->registered(), '2026-09-01']],
            $registration->statusChanges->map(fn ($l) => [$l->from_status_id, $l->to_status_id, $l->changed_on->toDateString()])->all(),
        );
    }

    #[Test]
    public function a_change_without_a_date_takes_today(): void
    {
        $player = $this->member();

        $this->save($player, ['status_id' => $this->left()])->assertSessionHasNoErrors();

        $this->assertSame(now()->toDateString(), RegistrationStatusChange::firstOrFail()->changed_on->toDateString());
    }

    #[Test]
    public function the_date_of_change_cannot_be_in_the_future_or_before_birth_or_joining(): void
    {
        $player = $this->member();

        foreach ([now()->addDay()->toDateString(), '2007-01-01', '2022-08-31'] as $date) {
            $this->save($player, ['status_id' => $this->left(), 'status_changed_on' => $date])
                ->assertSessionHasErrors('registrations.0.status_changed_on');
        }

        $this->assertSame(0, RegistrationStatusChange::count());
    }

    #[Test]
    public function a_bulk_status_change_writes_no_form_line(): void
    {
        $player = $this->member();

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'status_id', 'value' => $this->left(), 'branch_id' => $this->foot->id,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Player::isLeftStatus($this->football($player)->status_id));
        $this->assertSame(0, RegistrationStatusChange::where('origin', 'form')->count());
    }
}
