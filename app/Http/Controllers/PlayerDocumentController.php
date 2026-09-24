<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Player\PlayerDocumentService;
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

        if ($type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

        if ($player->documents()->where('document_type_id', $type->id)->exists()) {
            return back()->with('error', 'flash.document_already_recorded');
        }

        $this->documents->markReceived($player, $type, $this->receipt($request, $type), $this->files($request), $request->user());

        return back()->with('success', 'flash.document_received');
    }

    public function update(Request $request, Player $player, PlayerDocument $document): RedirectResponse
    {
        $this->ensureBelongs($player, $document);

        if ($document->state !== PlayerDocument::RECEIVED) {
            return back()->with('error', 'flash.document_not_received');
        }

        if ($document->type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

        $this->documents->renew($document, $this->receipt($request, $document->type), $this->files($request), $request->user());

        return back()->with('success', 'flash.document_renewed');
    }

    public function exempt(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->documents->exempt($player, $type, $data['reason'], $request->user());

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

        if ($document->type->isPhoto()) {
            return back()->with('error', 'flash.document_photo_is_profile_picture');
        }

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

    private function activeType(Request $request): DocumentType
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
        ]);

        return DocumentType::findOrFail($data['document_type_id']);
    }

    /** @return array{received_at: string, valid_until: ?string, notes: ?string} */
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
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ]);

        return [
            'received_at' => $data['received_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
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
