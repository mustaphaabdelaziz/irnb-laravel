<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document of one player: received (with its dates) or exempt (with a
 * reason). Renewing moves the dates forward on this same row; the files
 * uploaded over time stay attached as history.
 */
class PlayerDocument extends Model
{
    public const RECEIVED = 'received';

    public const EXEMPT = 'exempt';

    /** Folder on the private disk that holds every player's files. */
    public const ROOT = 'player-documents';

    protected $fillable = [
        'player_id',
        'document_type_id',
        'state',
        'received_at',
        'valid_until',
        'exempt_reason',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'valid_until' => 'date',
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
