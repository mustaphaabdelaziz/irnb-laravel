<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PlayerDocumentModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100003_create_player_documents.php';

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600501', 'firstname' => 'Amine', 'lastname' => 'Saadi']);
    }

    private function type(string $code = 'birth_certificate'): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function document(Player $player, ?DocumentType $type = null): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => ($type ?? $this->type())->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
        ]);
    }

    #[Test]
    public function a_player_has_at_most_one_row_per_type(): void
    {
        $player = $this->player();
        $this->document($player);

        $this->expectException(QueryException::class);
        $this->document($player);
    }

    #[Test]
    public function a_document_knows_its_player_type_and_files(): void
    {
        $player = $this->player();
        $document = $this->document($player);
        $document->files()->create(['path' => 'player-documents/1/a.pdf', 'original_name' => 'acte.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $this->assertTrue($document->player->is($player));
        $this->assertSame('birth_certificate', $document->type->code);
        $this->assertSame(['acte.pdf'], $document->files->pluck('original_name')->all());
        $this->assertSame([$document->id], $player->documents()->pluck('id')->all());
        $this->assertSame('2026-09-01', $document->fresh()->received_at->toDateString());
    }

    #[Test]
    public function a_file_never_sends_its_disk_path_to_the_browser(): void
    {
        $document = $this->document($this->player());
        $file = $document->files()->create(['path' => 'player-documents/1/secret.pdf', 'original_name' => 'acte.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $this->assertArrayNotHasKey('path', $file->toArray());
        $this->assertNotNull($file->created_at);
    }

    #[Test]
    public function removing_a_player_row_removes_its_documents_and_files(): void
    {
        $player = $this->player();
        $document = $this->document($player);
        $document->files()->create(['path' => 'player-documents/1/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        $player->delete();

        $this->assertSame(0, PlayerDocument::count());
        $this->assertSame(0, PlayerDocumentFile::count());
    }

    #[Test]
    public function a_type_with_records_cannot_be_deleted_underneath_them(): void
    {
        $type = $this->type();
        $this->document($this->player(), $type);

        $this->expectException(QueryException::class);
        $type->delete();
    }

    #[Test]
    public function each_player_gets_a_folder_of_their_own(): void
    {
        $this->assertSame('player-documents/42', PlayerDocument::directoryFor(42));
    }

    #[Test]
    public function the_migration_can_run_again_after_a_partial_failure(): void
    {
        (require database_path(self::MIGRATION))->up();

        $this->assertTrue(Schema::hasTable('player_documents'));
        $this->assertTrue(Schema::hasTable('player_document_files'));
    }

    #[Test]
    public function a_stored_file_lands_on_the_private_disk_under_a_random_name(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $stored = app(PrivateFileStorage::class)->store(
            UploadedFile::fake()->create('Acte de naissance.pdf', 120, 'application/pdf'),
            'player-documents/7'
        );

        $this->assertStringStartsWith('player-documents/7/', $stored['path']);
        $this->assertStringNotContainsString('Acte', $stored['path']);
        $this->assertSame('Acte de naissance.pdf', $stored['original_name']);
        $this->assertSame('application/pdf', $stored['mime']);
        $this->assertSame(120 * 1024, $stored['size']);
        Storage::disk('local')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing($stored['path']);
    }

    #[Test]
    public function private_storage_refuses_paths_that_climb_out_of_the_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('player-documents/7/a.pdf', 'PDF');
        $storage = app(PrivateFileStorage::class);

        $this->assertTrue($storage->exists('player-documents/7/a.pdf'));
        $this->assertFalse($storage->exists('../.env'));
        $this->assertFalse($storage->exists('/etc/passwd'));
        $this->assertFalse($storage->exists('C:\\Windows\\win.ini'));
        $this->assertFalse($storage->exists(null));

        $storage->delete('player-documents/7/../7/a.pdf');
        Storage::disk('local')->assertExists('player-documents/7/a.pdf');

        $storage->delete('player-documents/7/a.pdf');
        Storage::disk('local')->assertMissing('player-documents/7/a.pdf');
    }

    #[Test]
    public function a_private_file_is_streamed_inline_or_as_a_download(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('player-documents/7/a.pdf', 'PDF-BYTES');
        $storage = app(PrivateFileStorage::class);

        $inline = $storage->inline('player-documents/7/a.pdf', 'شهادة.pdf', 'application/pdf');
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $inline->headers->get('Content-Type'));

        $download = $storage->download('player-documents/7/a.pdf', 'acte.pdf');
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('acte.pdf', $download->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_missing_private_file_is_a_404(): void
    {
        Storage::fake('local');

        $this->expectException(NotFoundHttpException::class);
        app(PrivateFileStorage::class)->inline('player-documents/7/gone.pdf', 'gone.pdf');
    }

    #[Test]
    public function the_desktop_installer_never_bundles_private_files(): void
    {
        $this->assertContains('storage/app/private', config('nativephp.cleanup_exclude_files'));
    }
}
