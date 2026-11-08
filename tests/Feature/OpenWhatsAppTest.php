<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Shell;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function desktop_opens_the_wa_me_chat_in_the_os_browser(): void
    {
        config(['nativephp-internal.running' => true]);
        $shell = Shell::fake();

        $this->actingAs(User::factory()->create())
            ->from('/players/1')
            ->post(route('desktop.whatsapp'), ['phone' => '213559453948'])
            ->assertRedirect('/players/1');

        $shell->assertOpenedExternal('https://wa.me/213559453948');
    }

    #[Test]
    public function only_digits_are_accepted_never_a_url(): void
    {
        config(['nativephp-internal.running' => true]);
        Shell::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('desktop.whatsapp'), ['phone' => 'https://evil.example'])
            ->assertSessionHasErrors('phone');
    }

    #[Test]
    public function the_route_does_not_exist_on_the_web_build(): void
    {
        config(['nativephp-internal.running' => false]);

        $this->actingAs(User::factory()->create())
            ->post(route('desktop.whatsapp'), ['phone' => '213559453948'])
            ->assertNotFound();
    }
}
