<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of players inside one branch + category (e.g. Swimming ·
 * Minime · «الفوج 1»). A weekly schedule line and a session may point at one;
 * their roster is then the group's members on the session date (see Roster).
 * Memberships are written only by App\Services\Attendance\GroupMembership.
 */
class TrainingGroup extends Model
{
    use SerializesClubDates;

    protected $fillable = ['branch_id', 'category_id', 'name'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'category_id' => 'integer'];
    }

    protected static function booted(): void
    {
        // Explicit, so it also holds where the database does not enforce foreign keys.
        static::deleting(fn (TrainingGroup $group) => $group->members()->delete());
    }

    /** The branch (sport) of the group; null = the whole club. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TrainingGroupMember::class);
    }

    /** A schedule line or a session points at it: deleting it would change their rosters. */
    public function isInUse(): bool
    {
        return TrainingSchedule::where('group_id', $this->id)->exists()
            || TrainingSession::where('group_id', $this->id)->exists();
    }
}
