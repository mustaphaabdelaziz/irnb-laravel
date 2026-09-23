<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** A non-god user whose role grants exactly $permissions. */
    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create([
                'key' => 'role-'.uniqid(),
                'name' => ['en' => 'Role'],
                'permissions' => $permissions,
            ])->id,
        ]);
    }

    private function player(): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20269'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Amine',
            'lastname' => 'Saadi',
            'birthdate' => '1995-04-04',
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function pdf(string $name = 'scan.pdf', int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function markReceived(Player $player, string $code, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->post(route('players.documents.store', $player), [
            'document_type_id' => $this->type($code)->id,
            'received_at' => '2026-10-05',
            ...$extra,
        ]);
    }

    #[Test]
    public function a_season_document_is_valid_until_the_end_of_the_season(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'medical_certificate')
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_received');

        $document = $player->documents()->firstOrFail();
        $this->assertSame(PlayerDocument::RECEIVED, $document->state);
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
        $this->assertSame('2027-08-31', $document->valid_until->toDateString());
    }

    #[Test]
    public function a_date_document_needs_its_expiry_date(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'id_card_copy')->assertSessionHasErrors('valid_until');
        $this->markReceived($player, 'id_card_copy', ['valid_until' => '2026-01-01'])->assertSessionHasErrors('valid_until');

        $this->markReceived($player, 'id_card_copy', ['valid_until' => '2031-06-30'])->assertSessionHasNoErrors();
        $this->assertSame('2031-06-30', $player->documents()->firstOrFail()->valid_until->toDateString());
    }

    #[Test]
    public function a_plain_document_never_expires_and_cannot_be_received_in_the_future(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', ['received_at' => '2026-10-06'])->assertSessionHasErrors('received_at');

        $this->markReceived($player, 'birth_certificate', ['notes' => 'Original seen'])->assertSessionHasNoErrors();
        $document = $player->documents()->firstOrFail();
        $this->assertNull($document->valid_until);
        $this->assertSame('Original seen', $document->notes);
    }

    #[Test]
    public function several_files_go_to_the_private_disk_with_their_names(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', [
            'files' => [$this->pdf('page 1.pdf'), UploadedFile::fake()->image('page-2.jpg')],
        ])->assertSessionHasNoErrors();

        $files = PlayerDocumentFile::orderBy('id')->get();
        $this->assertSame(['page 1.pdf', 'page-2.jpg'], $files->pluck('original_name')->all());

        foreach ($files as $file) {
            $this->assertStringStartsWith("player-documents/{$player->id}/", $file->path);
            Storage::disk('local')->assertExists($file->path);
            Storage::disk('public')->assertMissing($file->path);
        }
    }

    #[Test]
    public function only_scans_and_photos_up_to_ten_megabytes_are_accepted(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'birth_certificate', [
            'files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
        ])->assertSessionHasErrors('files.0');

        $this->markReceived($player, 'birth_certificate', [
            'files' => [$this->pdf('huge.pdf', 10241)],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function the_photo_comes_from_the_profile_picture_only(): void
    {
        $player = $this->player();

        $this->markReceived($player, 'photo')
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.document_photo_is_profile_picture');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function a_document_cannot_be_marked_received_twice(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');

        $this->markReceived($player, 'birth_certificate')
            ->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertSame(1, PlayerDocument::count());
    }

    #[Test]
    public function an_inactive_type_cannot_be_recorded(): void
    {
        $player = $this->player();
        $this->type('school_certificate')->update(['is_active' => false]);

        $this->markReceived($player, 'school_certificate')->assertSessionHasErrors('document_type_id');
    }

    #[Test]
    public function renewing_moves_the_dates_and_keeps_the_earlier_files(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'medical_certificate', ['received_at' => '2025-10-01', 'files' => [$this->pdf('2025.pdf')]]);
        $document = $player->documents()->firstOrFail();
        $this->assertSame('2026-08-31', $document->valid_until->toDateString());

        $this->actingAs($this->admin())->put(route('players.documents.update', [$player, $document]), [
            'received_at' => '2026-10-05',
            'notes' => 'Renewed',
            'files' => [$this->pdf('2026.pdf')],
        ])->assertRedirect()->assertSessionHas('success', 'flash.document_renewed');

        $document->refresh();
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
        $this->assertSame('2027-08-31', $document->valid_until->toDateString());
        $this->assertSame('Renewed', $document->notes);
        $this->assertSame(['2025.pdf', '2026.pdf'], $document->files()->pluck('original_name')->all());
    }

    #[Test]
    public function a_document_can_be_exempted_with_a_reason_and_the_exemption_undone(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('medical_certificate')->id,
            'reason' => 'x',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('medical_certificate')->id,
            'reason' => 'Checked by the federation doctor',
        ])->assertSessionHas('success', 'flash.document_exempted');

        $document = $player->documents()->firstOrFail();
        $this->assertSame(PlayerDocument::EXEMPT, $document->state);
        $this->assertSame('Checked by the federation doctor', $document->exempt_reason);

        $this->actingAs($this->admin())->delete(route('players.documents.unexempt', [$player, $document]))
            ->assertSessionHas('success', 'flash.document_exemption_removed');

        // Never received: undoing the exemption leaves nothing behind.
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function undoing_the_exemption_of_a_received_document_brings_it_back(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $document = $player->documents()->firstOrFail();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('birth_certificate')->id,
            'reason' => 'Not needed for this competition',
        ]);
        $this->assertSame(PlayerDocument::EXEMPT, $document->fresh()->state);
        $this->assertSame(1, $document->files()->count(), 'exempting keeps the files');

        $this->actingAs($this->admin())->delete(route('players.documents.unexempt', [$player, $document]));

        $document->refresh();
        $this->assertSame(PlayerDocument::RECEIVED, $document->state);
        $this->assertNull($document->exempt_reason);
        $this->assertSame('2026-10-05', $document->received_at->toDateString());
    }

    #[Test]
    public function the_photo_can_be_exempted(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('photo')->id,
            'reason' => 'Refuses to be photographed',
        ])->assertSessionHas('success', 'flash.document_exempted');
    }

    #[Test]
    public function files_can_be_added_to_a_received_document_only(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');
        $document = $player->documents()->firstOrFail();

        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [])
            ->assertSessionHasErrors('files');

        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')],
        ])->assertSessionHas('success', 'flash.document_files_uploaded');
        $this->assertSame(2, $document->files()->count());

        $document->update(['state' => PlayerDocument::EXEMPT]);
        $this->actingAs($this->admin())->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf('c.pdf')],
        ])->assertSessionHas('error', 'flash.document_not_received');
    }

    #[Test]
    public function a_file_is_viewed_inline_and_downloaded_under_its_original_name(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf('Acte de naissance.pdf')]]);
        $file = PlayerDocumentFile::firstOrFail();

        $inline = $this->actingAs($this->admin())->get(route('players.documents.files.show', [$player, $file]));
        $inline->assertOk();
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $inline->assertHeader('X-Content-Type-Options', 'nosniff');
        $inline->assertHeader('Content-Type', 'application/pdf');

        $download = $this->actingAs($this->admin())->get(route('players.documents.files.download', [$player, $file]));
        $download->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Acte de naissance.pdf', $download->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_stored_file_whose_bytes_dont_match_its_extension_is_never_served_as_html(): void
    {
        // Same risk as the board minutes and transaction receipts (see
        // BoardMinutesPrivateTest / TransactionReceiptPrivateTest): showFile()
        // must take its Content-Type from the extension allowlist, never from
        // the stored mime column or by sniffing the real bytes — otherwise a
        // legacy import mislabelled as .jpg would render as text/html, inline,
        // on the app's own origin, and run as script.
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $document = $player->documents()->firstOrFail();
        $file = $document->files()->firstOrFail();

        Storage::disk('local')->put($file->path, '<script>alert(1)</script>');
        $file->update(['mime' => 'text/html']);

        $response = $this->actingAs($this->admin())->get(route('players.documents.files.show', [$player, $file]));

        $response->assertOk();
        $this->assertNotSame('text/html', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_file_cannot_be_reached_through_another_player(): void
    {
        $owner = $this->player();
        $other = $this->player();
        $this->markReceived($owner, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();

        $this->actingAs($this->admin())->get(route('players.documents.files.show', [$other, $file]))->assertNotFound();
        $this->actingAs($this->admin())->delete(route('players.documents.files.destroy', [$other, $file]))->assertNotFound();
        $this->assertNotNull($file->fresh());
    }

    #[Test]
    public function the_public_media_route_never_serves_a_document(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();

        $this->get('/media/'.$file->path)->assertNotFound();
    }

    #[Test]
    public function removing_a_file_deletes_it_from_the_disk_and_keeps_the_document(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $file = PlayerDocumentFile::firstOrFail();
        $path = $file->path;

        $this->actingAs($this->admin())->delete(route('players.documents.files.destroy', [$player, $file]))
            ->assertSessionHas('success', 'flash.document_file_removed');

        $this->assertNull($file->fresh());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(PlayerDocument::RECEIVED, $player->documents()->firstOrFail()->state);
    }

    #[Test]
    public function every_document_action_needs_the_documents_module(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate', ['files' => [$this->pdf()]]);
        $document = $player->documents()->firstOrFail();
        $file = PlayerDocumentFile::firstOrFail();

        // Full player rights, no documents rights.
        $coach = $this->userWith(['players' => Role::ACTIONS]);

        $this->actingAs($coach)->get(route('players.documents.files.show', [$player, $file]))->assertForbidden();
        $this->actingAs($coach)->get(route('players.documents.files.download', [$player, $file]))->assertForbidden();
        $this->markReceived($player, 'medical_certificate', [], $coach)->assertForbidden();
        $this->actingAs($coach)->post(route('players.documents.exempt', $player), ['document_type_id' => $this->type('medical_certificate')->id, 'reason' => 'Because'])->assertForbidden();
        $this->actingAs($coach)->delete(route('players.documents.files.destroy', [$player, $file]))->assertForbidden();

        // View only: can open and download, cannot change anything.
        $viewer = $this->userWith(['players' => ['view'], 'documents' => ['view']]);

        $this->actingAs($viewer)->get(route('players.documents.files.show', [$player, $file]))->assertOk();
        $this->actingAs($viewer)->get(route('players.documents.files.download', [$player, $file]))->assertOk();
        $this->markReceived($player, 'medical_certificate', [], $viewer)->assertForbidden();
        $this->actingAs($viewer)->put(route('players.documents.update', [$player, $document]), ['received_at' => '2026-10-05'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('players.documents.files.destroy', [$player, $file]))->assertForbidden();
    }

    #[Test]
    public function the_player_page_carries_the_checklist_only_for_document_viewers(): void
    {
        $player = $this->player();
        $this->markReceived($player, 'birth_certificate');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $props['documents']['missing_count']);
        $this->assertSame('birth_certificate', $props['documents']['items'][0]['type']['code']);

        $coach = $this->userWith(['players' => Role::ACTIONS]);
        $props = $this->actingAs($coach)->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertNull($props['documents']);
    }
}
