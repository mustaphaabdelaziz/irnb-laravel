<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scanned page or photo of a player document, on the private disk. The
 * browser only ever sees its id and original name; the file itself is served
 * by PlayerDocumentController behind the documents permission.
 */
class PlayerDocumentFile extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'player_document_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by_user_id',
    ];

    /** The disk path is an implementation detail and must never reach the page. */
    protected $hidden = [
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PlayerDocument::class, 'player_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
