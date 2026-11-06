<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\PlayerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentWritersRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private User $admin;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->type = DocumentType::where('code', 'birth_certificate')->firstOrFail();
    }

    private function receive($player, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('players.documents.store', $player), [
            'document_type_id' => $this->type->id,
            'received_at' => now()->toDateString(),
            ...$extra,
        ]);
    }

    #[Test]
    public function receiving_goes_to_the_registration_named(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);

        $this->receive($player, ['branch_registration_id' => $swim->id])->assertSessionHas('success', 'flash.document_received');

        $this->assertSame($swim->id, PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function naming_none_means_the_main_registration(): void
    {
        $player = $this->bothActive();

        $this->receive($player)->assertSessionHas('success');

        $this->assertSame($player->registrationIn($this->foot->id)->id, PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function the_same_type_can_be_received_on_each_registration_once(): void
    {
        $player = $this->bothActive();
        $foot = $player->registrationIn($this->foot->id)->id;
        $swim = $player->registrationIn($this->swim->id)->id;

        $this->receive($player, ['branch_registration_id' => $foot])->assertSessionHas('success');
        $this->receive($player, ['branch_registration_id' => $swim])->assertSessionHas('success');
        $this->receive($player, ['branch_registration_id' => $swim])->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertSame(2, PlayerDocument::count());
    }

    #[Test]
    public function another_players_registration_is_refused(): void
    {
        $player = $this->bothActive();
        $other = $this->bothActive('Other')->registrationIn($this->foot->id);

        $this->receive($player, ['branch_registration_id' => $other->id])->assertSessionHasErrors('branch_registration_id');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function a_player_with_no_registration_keeps_one_blank_row_per_type(): void
    {
        $flat = $this->noRegistration();

        $this->receive($flat)->assertSessionHas('success');
        $this->receive($flat)->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertNull(PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function an_exemption_goes_to_the_registration_named_and_reuses_its_row(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);
        $exempt = fn () => $this->actingAs($this->admin)->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type->id, 'reason' => 'Lost', 'branch_registration_id' => $swim->id,
        ])->assertSessionHas('success');

        $exempt();
        $exempt();

        $this->assertSame($swim->id, PlayerDocument::sole()->branch_registration_id);
        $this->assertSame(PlayerDocument::EXEMPT, PlayerDocument::sole()->state);
    }

    #[Test]
    public function an_exemption_for_a_player_with_no_registration_reuses_the_blank_row(): void
    {
        $flat = $this->noRegistration();
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->admin)->post(route('players.documents.exempt', $flat), [
                'document_type_id' => $this->type->id, 'reason' => 'Lost',
            ])->assertSessionHas('success');
        }

        $this->assertNull(PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function an_exemption_on_another_players_registration_is_refused(): void
    {
        $player = $this->bothActive();
        $other = $this->bothActive('Other')->registrationIn($this->foot->id);

        $this->actingAs($this->admin)->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type->id, 'reason' => 'Lost', 'branch_registration_id' => $other->id,
        ])->assertSessionHasErrors('branch_registration_id');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function a_registration_holding_documents_cannot_be_removed_as_a_mistake(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);
        $this->receive($player, ['branch_registration_id' => $swim->id]);

        $this->actingAs($this->admin)->delete(route('players.registrations.destroy', [$player, $swim]))
            ->assertSessionHas('error', 'flash.registration_has_documents');

        $this->assertSame(2, $player->registrations()->count());
    }

    #[Test]
    public function an_empty_one_still_can(): void
    {
        $player = $this->bothActive();
        $this->receive($player, ['branch_registration_id' => $player->registrationIn($this->foot->id)->id]);

        $this->actingAs($this->admin)->delete(route('players.registrations.destroy', [$player, $player->registrationIn($this->swim->id)]))
            ->assertSessionHas('success');

        $this->assertSame(1, $player->registrations()->count());
        $this->assertSame(1, PlayerDocument::count());
    }
}
