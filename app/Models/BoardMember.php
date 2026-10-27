<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardMember extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'user_id', 'player_id', 'board_term_id', 'name', 'role', 'photo_url', 'photo_filename',
        'email', 'phone', 'term_start', 'term_end', 'status', 'bio', 'sort_order',
    ];

    /** The player columns a member reads its identity from (see withPlayer()). */
    public const PLAYER_COLUMNS = ['id', ...Player::NAME_COLUMNS, 'email', 'phones', 'picture_url'];

    /**
     * The linked player carries the member's identity; it is read through the
     * accessors below rather than serialised a second time.
     */
    protected $hidden = ['player'];

    /** Load the linked player so name, contact and photo come from it, live. */
    public function scopeWithPlayer(Builder $query): void
    {
        $query->with(['player' => fn ($q) => $q->select(self::PLAYER_COLUMNS)]);
    }

    /**
     * The stored name is a copy kept for narrow `member:id,name` lookups (and in
     * step with renames by Player); with the player loaded, its full name wins.
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn ($value) => $this->loadedPlayer()?->fullname ?? $value);
    }

    protected function email(): Attribute
    {
        return Attribute::make(get: fn ($value) => ($player = $this->loadedPlayer()) ? $player->email : $value);
    }

    protected function phone(): Attribute
    {
        return Attribute::make(get: fn ($value) => ($player = $this->loadedPlayer()) ? (($player->phones ?? [])[0] ?? null) : $value);
    }

    /** Normalise the stored photo URL to a host-relative /media path (web + desktop). */
    protected function photoUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => ($player = $this->loadedPlayer()) ? $player->picture_url : Media::path($value));
    }

    private function loadedPlayer(): ?Player
    {
        return $this->relationLoaded('player') ? $this->player : null;
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

    /** Committees this member sits on; pivot `position` = head | deputy | member. */
    public function committees(): BelongsToMany
    {
        return $this->belongsToMany(BoardCommittee::class, 'board_committee_member')
            ->withPivot('position')
            ->withTimestamps();
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
