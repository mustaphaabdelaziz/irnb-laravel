<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Services\Storage\FileStorageService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A social activity, recorded after it took place. `ends_on` null means a
 * single day. `cost` is informational only: finance never reads it.
 * Participants = linked players + linked board members + outside volunteers.
 */
class Activity extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'title', 'activity_type_id', 'starts_on', 'ends_on', 'location', 'partners',
        'branch_id', 'responsible_board_member_id', 'cost', 'beneficiaries_count',
        'external_volunteers_count', 'description', 'report', 'created_by_user_id',
    ];

    protected $appends = ['participants_count'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'cost' => 'decimal:2',
            'beneficiaries_count' => 'integer',
            'external_volunteers_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Rows cascade in the database; the files on disk have to go here.
        static::deleting(function (Activity $activity) {
            $public = app(FileStorageService::class);
            foreach ($activity->photos()->get() as $photo) {
                $public->delete($photo->path);
                $public->delete($photo->thumb_path);
            }

            $private = app(PrivateFileStorage::class);
            foreach ($activity->files()->get() as $file) {
                $private->delete($file->path);
            }
        });
    }

    public function photoDirectory(): string
    {
        return 'activities/'.$this->id;
    }

    public function fileDirectory(): string
    {
        return 'activities/'.$this->id;
    }

    /** withCount() attributes first, then loaded relations, and only then a query. */
    protected function participantsCount(): Attribute
    {
        return Attribute::make(get: fn () => $this->linkedCount('players')
            + $this->linkedCount('boardMembers')
            + (int) ($this->attributes['external_volunteers_count'] ?? 0));
    }

    private function linkedCount(string $relation): int
    {
        return (int) ($this->attributes[Str::snake($relation).'_count']
            ?? ($this->relationLoaded($relation) ? $this->{$relation}->count() : $this->{$relation}()->count()));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'responsible_board_member_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class);
    }

    public function boardMembers(): BelongsToMany
    {
        return $this->belongsToMany(BoardMember::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ActivityPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The gallery's first photo (lowest sort_order, then lowest id), for list cards. */
    public function cover(): HasOne
    {
        return $this->hasOne(ActivityPhoto::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ActivityFile::class)->orderBy('id');
    }
}
