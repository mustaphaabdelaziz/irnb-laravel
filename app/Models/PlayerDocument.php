<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Services\Player\RegistrationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document of one player's registration (of the player alone when they
 * have none): received (with its dates) or exempt (with a reason).
 * Renewing moves the dates forward on this same row; the files uploaded
 * over time stay attached as history.
 */
class PlayerDocument extends Model
{
    use SerializesClubDates;

    public const RECEIVED = 'received';

    public const EXEMPT = 'exempt';

    /** Folder on the private disk that holds every player's files. */
    public const ROOT = 'player-documents';

    protected $fillable = [
        'player_id',
        'branch_registration_id',
        'document_type_id',
        'state',
        'received_at',
        'valid_until',
        'copies_received',
        'exempt_reason',
        'notes',
        'recorded_by_user_id',
    ];

    /** A row that names no registration belongs to the main one. A player with none keeps it blank. */
    protected static function booted(): void
    {
        static::creating(function (PlayerDocument $document) {
            if (! array_key_exists('branch_registration_id', $document->getAttributes())) {
                $document->branch_registration_id = app(RegistrationService::class)->primary($document->player)?->id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'valid_until' => 'date',
            'copies_received' => 'integer',
        ];
    }

    /** Where a player's files live on the private disk. */
    public static function directoryFor(int $playerId): string
    {
        return self::ROOT.'/'.$playerId;
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(BranchRegistration::class, 'branch_registration_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PlayerDocumentFile::class)->orderBy('id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
