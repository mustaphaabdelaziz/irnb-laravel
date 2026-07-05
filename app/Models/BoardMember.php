<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardMember extends Model
{
    protected $fillable = [
        'user_id', 'player_id', 'board_term_id', 'name', 'role', 'photo_url', 'photo_filename',
        'email', 'phone', 'term_start', 'term_end', 'status', 'bio', 'sort_order',
    ];

    /** Normalise the stored photo URL to a host-relative /media path (web + desktop). */
    protected function photoUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

    protected function casts(): array
    {
        return [
            'term_start' => 'date',
            'term_end' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(BoardTerm::class, 'board_term_id');
    }

    /** Role lookup, linked by name (board_members.role → board_roles.name). */
    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(BoardRole::class, 'role', 'name');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BoardTask::class);
    }
}
