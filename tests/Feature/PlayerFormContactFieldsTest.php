<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerEmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The player form posts as multipart FormData, which cannot carry an empty
 * array: a cleared list arrives as '' (null after ConvertEmptyStringsToNull).
 * These payloads mirror what the form sends.
 */
class PlayerFormContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(array $attributes = []): Player
    {
        return Player::create(array_merge([
            'membership_id' => '202600001',
            'firstname' => 'Ali',
            'lastname' => 'Benali',
        ], $attributes));
    }

    private function save(Player $player, array $payload)
    {
        return $this->actingAs($this->admin())->put(route('players.update', $player), array_merge([
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
        ], $payload));
    }

    #[Test]
    public function the_emergency_contact_phone_is_updated(): void
    {
        $player = $this->player();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim', 'relationship' => 'father', 'phones' => ['0555000000']]);

        $this->save($player, [
            'emergency_contacts' => [['name' => 'Karim', 'relationship' => 'father', 'phones' => ['0666111222']]],
        ])->assertRedirect();

        $contact = $player->emergencyContacts()->sole();
        $this->assertSame(['0666111222'], $contact->phones);
        $this->assertSame('father', $contact->relationship);
    }

    #[Test]
    public function the_emergency_contact_phone_can_be_removed(): void
    {
        $player = $this->player();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim', 'phones' => ['0555000000']]);

        // An emptied phone list is absent from the contact's FormData entry.
        $this->save($player, ['emergency_contacts' => [['name' => 'Karim', 'relationship' => '']]])->assertRedirect();

        $this->assertEmpty($player->emergencyContacts()->sole()->phones);
    }

    #[Test]
    public function clearing_the_emergency_contact_removes_it(): void
    {
        $player = $this->player();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim', 'phones' => ['0555000000']]);

        $this->save($player, ['emergency_contacts' => ''])->assertRedirect();

        $this->assertSame(0, $player->emergencyContacts()->count());
    }

    #[Test]
    public function an_update_that_never_mentions_emergency_contacts_keeps_them(): void
    {
        $player = $this->player();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim', 'phones' => ['0555000000']]);

        $this->save($player, [])->assertRedirect();

        $this->assertSame(1, $player->emergencyContacts()->count());
    }

    #[Test]
    public function an_emergency_phone_without_a_name_is_reported_not_dropped(): void
    {
        $player = $this->player();

        $this->save($player, ['emergency_contacts' => [['name' => '', 'phones' => ['0666111222']]]])
            ->assertSessionHasErrors('emergency_contacts.0.name');
    }

    #[Test]
    public function an_emergency_phone_must_be_a_short_string(): void
    {
        $player = $this->player();

        $this->save($player, ['emergency_contacts' => [['name' => 'Karim', 'phones' => [str_repeat('1', 21)]]]])
            ->assertSessionHasErrors('emergency_contacts.0.phones.0');
    }

    #[Test]
    public function the_player_phone_can_be_removed(): void
    {
        $player = $this->player(['phones' => ['0555000000']]);

        $this->save($player, ['phones' => ''])->assertRedirect();

        $this->assertEmpty($player->fresh()->phones);
    }

    #[Test]
    public function text_fields_can_be_emptied(): void
    {
        $player = $this->player([
            'nickname' => 'Lolo', 'father' => 'Omar', 'grandfather' => 'Said', 'email' => 'ali@example.com',
            'city' => 'Ghardaia', 'health_medical_conditions' => 'Asthma', 'health_blood_group_rhesus' => 'A+',
            'birthdate' => '2010-01-01',
        ]);

        $this->save($player, [
            'nickname' => '', 'father' => '', 'grandfather' => '', 'email' => '', 'city' => '',
            'health_medical_conditions' => '', 'health_blood_group_rhesus' => '', 'birthdate' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $player->fresh();
        foreach (['nickname', 'father', 'grandfather', 'email', 'health_medical_conditions', 'health_blood_group_rhesus', 'birthdate'] as $field) {
            $this->assertNull($fresh->{$field}, $field);
        }
        $this->assertNotSame('Ghardaia', $fresh->city);
    }

    #[Test]
    public function a_new_player_is_stored_with_an_emergency_contact_and_no_phone(): void
    {
        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Benali', 'phones' => '', 'city' => '',
            'emergency_contacts' => [['name' => 'Karim', 'phones' => ['0666111222']]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $player = Player::sole();
        $this->assertEmpty($player->phones);
        $this->assertSame(['0666111222'], $player->emergencyContacts()->sole()->phones);
    }
}
