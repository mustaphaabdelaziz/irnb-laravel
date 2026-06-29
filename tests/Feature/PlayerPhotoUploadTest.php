<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_stores_an_uploaded_player_photo_on_the_public_disk(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Ali',
            'lastname' => 'Brahimi',
            'picture' => UploadedFile::fake()->image('photo.jpg', 1200, 1200),
        ]);

        $response->assertRedirect();

        $player = Player::query()->firstOrFail();

        $this->assertNotNull($player->picture_filename);
        $this->assertStringStartsWith('players/', $player->picture_filename);
        $this->assertStringContainsString('/storage/players/', (string) $player->picture_url);
        Storage::disk('public')->assertExists($player->picture_filename);
    }

    #[Test]
    public function it_replaces_and_deletes_the_previous_photo_on_update(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('players.store'), [
            'firstname' => 'Ali',
            'lastname' => 'Brahimi',
            'picture' => UploadedFile::fake()->image('first.jpg', 800, 800),
        ]);

        $player = Player::query()->firstOrFail();
        $oldPath = $player->picture_filename;

        $this->actingAs($admin)->post(route('players.update', $player), [
            '_method' => 'put',
            'firstname' => 'Ali',
            'lastname' => 'Brahimi',
            'picture' => UploadedFile::fake()->image('second.jpg', 800, 800),
        ])->assertRedirect();

        $player->refresh();

        $this->assertNotSame($oldPath, $player->picture_filename);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($player->picture_filename);
    }
}
