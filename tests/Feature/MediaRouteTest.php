<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaRouteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_public_file_is_served_without_touching_the_session(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'image');

        $response = $this->get('/media/branding/logo.png');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function a_missing_file_is_a_plain_404_without_a_cookie(): void
    {
        Storage::fake('public');

        $response = $this->get('/media/branding/nope.png');

        $response->assertNotFound();
        $this->assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function a_private_folder_stays_refused(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/a.pdf', 'pdf');

        $this->get('/media/receipts/a.pdf')->assertNotFound();
    }

    #[Test]
    public function a_logged_in_user_stays_logged_in_after_an_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'image');
        $user = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/media/branding/logo.png')->assertOk();

        $this->get(route('players.index'))->assertOk();
    }
}
