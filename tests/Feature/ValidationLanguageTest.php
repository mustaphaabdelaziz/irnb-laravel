<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidationLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function adminSpeaking(string $locale): User
    {
        return User::factory()->admin()->create([
            'email_verified_at' => now(),
            'preferred_lng' => $locale,
        ]);
    }

    #[Test]
    public function validation_errors_speak_arabic_and_name_the_field_as_the_form_does(): void
    {
        $this->actingAs($this->adminSpeaking('ar'))
            ->post(route('players.store'), ['firstname' => 'Ali'])
            ->assertSessionHasErrors(['lastname' => 'حقل اللقب مطلوب.']);
    }

    #[Test]
    public function validation_errors_speak_french(): void
    {
        $this->actingAs($this->adminSpeaking('fr'))
            ->post(route('players.store'), ['firstname' => 'Ali'])
            ->assertSessionHasErrors(['lastname' => 'Le champ Nom est obligatoire.']);
    }

    #[Test]
    public function english_keeps_the_framework_wording_with_the_ui_field_name(): void
    {
        $this->actingAs($this->adminSpeaking('en'))
            ->post(route('players.store'), ['firstname' => 'Ali'])
            ->assertSessionHasErrors(['lastname' => 'The Lastname field is required.']);
    }
}
