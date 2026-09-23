<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** A player with a received birth certificate and one scanned file on the private disk. */
    private function playerWithScan(): array
    {
        $this->sequence++;
        $player = Player::create([
            'membership_id' => '20267'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Test',
            'lastname' => 'Player'.$this->sequence,
            'archived' => true,
        ]);

        $this->actingAs($this->admin())->post(route('players.documents.store', $player), [
            'document_type_id' => DocumentType::where('code', 'birth_certificate')->value('id'),
            'received_at' => now()->toDateString(),
            'files' => [UploadedFile::fake()->create('acte.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $path = PlayerDocumentFile::query()
            ->whereIn('player_document_id', $player->documents()->select('id'))
            ->value('path');
        Storage::disk('local')->assertExists($path);

        return [$player, $path];
    }

    #[Test]
    public function permanently_deleting_a_player_removes_their_documents_and_files(): void
    {
        [$player, $path] = $this->playerWithScan();
        [$other, $otherPath] = $this->playerWithScan();

        $this->actingAs($this->admin())->delete(route('players.forceDelete', $player))->assertRedirect();

        $this->assertNull(Player::find($player->id));
        $this->assertSame(0, PlayerDocument::where('player_id', $player->id)->count());
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing(PlayerDocument::directoryFor($player->id));

        // Someone else's file is untouched.
        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame(1, PlayerDocument::where('player_id', $other->id)->count());
    }

    #[Test]
    public function bulk_permanent_deletion_removes_every_players_files(): void
    {
        [$first, $firstPath] = $this->playerWithScan();
        [$second, $secondPath] = $this->playerWithScan();

        $this->actingAs($this->admin())->post(route('players.bulkForceDelete'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertSame(0, PlayerDocument::count());
        $this->assertSame(0, PlayerDocumentFile::count());
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertMissing($secondPath);
    }

    #[Test]
    public function archiving_a_player_keeps_their_documents(): void
    {
        [$player, $path] = $this->playerWithScan();
        $player->update(['archived' => false]);

        $this->actingAs($this->admin())->delete(route('players.destroy', $player))->assertRedirect();

        $this->assertTrue($player->fresh()->archived);
        $this->assertSame(1, PlayerDocument::where('player_id', $player->id)->count());
        Storage::disk('local')->assertExists($path);
    }
}
