<?php

namespace App\Http\Controllers;

use App\Models\BranchRegistration;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Player\PlayerDocumentService;
use App\Services\Player\RegistrationService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A player's documents. Every route here is players.documents.*, which
 * config/permissions.php maps to the `documents` module (owner decision), so
 * the `permission` middleware has already checked view/add/edit/delete.
 */
class PlayerDocumentController extends Controller
{
    /** Scans and phone photos, nothing executable; 10 MB like the meeting minutes. */
    private const FILE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'];

    private const MAX_FILES = 10;

    public function __construct(private PlayerDocumentService $documents) {}

    public function store(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $registration = $this->registration($request, $player);
        if (! $this->requiredIn($registration, $type)) {
            return back()->with('error', 'flash.document_not_required_in_branch');
        }

        $held = $player->documents()
            ->where('branch_registration_id', $registration?->id)
            ->where('document_type_id', $type->id)
            ->exists();
        if ($held) {
            return back()->with('error', 'flash.document_already_recorded');
        }

        $this->documents->markReceived($player, $type, $this->receipt($request, $type), $this->files($request), $request->user(), $registration);

        return back()->with('success', 'flash.document_received');
    }

    public function update(Request $request, Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::RECEIVED) {
            return back()->with('error', 'flash.document_not_received');
        }

        if (! $this->requiredIn($document->registration, $document->type)) {
            return back()->with('error', 'flash.document_not_required_in_branch');
        }

        $this->documents->renew($document, $this->receipt($request, $document->type), $this->files($request), $request->user());

        return back()->with('success', 'flash.document_renewed');
    }

    public function exempt(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $registration = $this->registration($request, $player);
        if (! $this->requiredIn($registration, $type)) {
            return back()->with('error', 'flash.document_not_required_in_branch');
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->documents->exempt($player, $type, $data['reason'], $request->user(), $registration);

        return back()->with('success', 'flash.document_exempted');
    }

    public function unexempt(Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::EXEMPT) {
            return back()->with('error', 'flash.document_not_exempt');
        }

        $this->documents->unexempt($document);

        return back()->with('success', 'flash.document_exemption_removed');
    }

    public function storeFiles(Request $request, Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::RECEIVED) {
            return back()->with('error', 'flash.document_not_received');
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ]);

        $this->documents->attach($document, $this->files($request), $request->user());

        return back()->with('success', 'flash.document_files_uploaded');
    }

    public function showFile(Player $player, PlayerDocumentFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        $this->ensureFileBelongs($player, $file);

        return $storage->serve($file->path, $file->original_name);
    }

    public function downloadFile(Player $player, PlayerDocumentFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        $this->ensureFileBelongs($player, $file);

        return $storage->download($file->path, $file->original_name);
    }

    public function destroyFile(Player $player, PlayerDocumentFile $file): RedirectResponse
    {
        $this->ensureFileBelongs($player, $file);

        $this->documents->removeFile($file);

        return back()->with('success', 'flash.document_file_removed');
    }

    /** The registration the page's tab names, otherwise the main one; none for a player without any. */
    private function registration(Request $request, Player $player): ?BranchRegistration
    {
        $id = $request->validate([
            'branch_registration_id' => ['nullable', 'integer', Rule::exists('branch_player', 'id')->where('player_id', $player->id)],
        ])['branch_registration_id'] ?? null;

        return $id === null ? app(RegistrationService::class)->primary($player) : BranchRegistration::findOrFail($id);
    }

    /** A registration owes only the types its branch ticks; a player with none owes every active type. */
    private function requiredIn(?BranchRegistration $registration, DocumentType $type): bool
    {
        return $registration === null || $type->branches()->whereKey($registration->branch_id)->exists();
    }

    private function activeType(Request $request): DocumentType
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
        ]);

        return DocumentType::findOrFail($data['document_type_id']);
    }

    /** @return array{received_at: string, valid_until: ?string, notes: ?string, copies_received: int} */
    private function receipt(Request $request, DocumentType $type): array
    {
        $data = $request->validate([
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => [
                Rule::requiredIf($type->validity === DocumentType::VALIDITY_DATE),
                'nullable',
                'date',
                'after_or_equal:received_at',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'copies_received' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) $type->copies)],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ]);

        return [
            'received_at' => $data['received_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
            // Left out = every copy came in (the form always sends it).
            'copies_received' => (int) ($data['copies_received'] ?? $type->copies),
        ];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        return array_values(array_filter(
            (array) $request->file('files', []),
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    private function ensureBelongs(Player $player, PlayerDocument $document): void
    {
        abort_unless((int) $document->player_id === (int) $player->id, 404);
    }

    private function ensureFileBelongs(Player $player, PlayerDocumentFile $file): void
    {
        abort_unless((int) $file->document?->player_id === (int) $player->id, 404);
    }
}
