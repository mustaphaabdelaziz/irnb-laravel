<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Models\User;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Everything that changes a player's documents. The controller validates and
 * checks permissions; this class keeps rows and files in step.
 */
class PlayerDocumentService
{
    public function __construct(private PrivateFileStorage $storage) {}

    /**
     * @param  array{received_at: string, valid_until: ?string, notes: ?string}  $receipt
     * @param  list<UploadedFile>  $files
     */
    public function markReceived(Player $player, DocumentType $type, array $receipt, array $files, ?User $by): PlayerDocument
    {
        $document = PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => $receipt['received_at'],
            'valid_until' => $type->validUntilFor($receipt['received_at'], $receipt['valid_until']),
            'notes' => $receipt['notes'],
            'recorded_by_user_id' => $by?->id,
        ]);

        $this->attach($document, $files, $by);

        return $document;
    }

    /**
     * Renewal moves the dates forward on the same row. Earlier files stay
     * attached, with their upload dates, as the document's history.
     *
     * @param  array{received_at: string, valid_until: ?string, notes: ?string}  $receipt
     * @param  list<UploadedFile>  $files
     */
    public function renew(PlayerDocument $document, array $receipt, array $files, ?User $by): void
    {
        $document->update([
            'received_at' => $receipt['received_at'],
            'valid_until' => $document->type->validUntilFor($receipt['received_at'], $receipt['valid_until']),
            'notes' => $receipt['notes'],
            'recorded_by_user_id' => $by?->id,
        ]);

        $this->attach($document, $files, $by);
    }

    /** With or without an existing row; a received row keeps its dates and files. */
    public function exempt(Player $player, DocumentType $type, string $reason, ?User $by): PlayerDocument
    {
        $document = PlayerDocument::firstOrNew([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
        ]);

        $document->fill([
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => $reason,
            'recorded_by_user_id' => $by?->id,
        ])->save();

        return $document;
    }

    /** Back to "received" when it had been received; otherwise the row goes. */
    public function unexempt(PlayerDocument $document): void
    {
        if ($document->received_at !== null) {
            $document->update(['state' => PlayerDocument::RECEIVED, 'exempt_reason' => null]);

            return;
        }

        $paths = $document->files()->pluck('path');
        $document->files()->delete();
        $document->delete();
        $paths->each(fn (string $path) => $this->storage->delete($path));
    }

    /** @param  list<UploadedFile>  $files */
    public function attach(PlayerDocument $document, array $files, ?User $by): void
    {
        foreach ($files as $file) {
            $stored = $this->storage->store($file, PlayerDocument::directoryFor($document->player_id));

            try {
                $document->files()->create([
                    ...$stored,
                    'uploaded_by_user_id' => $by?->id,
                ]);
            } catch (Throwable $e) {
                // Never leave a file on disk that no row points at.
                $this->storage->delete($stored['path']);

                throw $e;
            }
        }
    }

    public function removeFile(PlayerDocumentFile $file): void
    {
        $path = $file->path;

        $file->delete();
        $this->storage->delete($path);
    }

    /** Rows only — call inside the permanent-deletion transaction. */
    public function purgePlayerRecords(Player $player): void
    {
        PlayerDocumentFile::query()
            ->whereIn('player_document_id', $player->documents()->select('id'))
            ->delete();

        $player->documents()->delete();
    }

    /** The player's whole folder — call after the transaction has committed. */
    public function purgePlayerFiles(int $playerId): void
    {
        $this->storage->deleteDirectory(PlayerDocument::directoryFor($playerId));
    }
}
