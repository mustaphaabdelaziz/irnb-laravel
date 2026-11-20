<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of a player's membership of a training group. `from_date` and
 * `to_date` are 'Y-m-d' strings; null from = since always, null to = still a
 * member. Written only by App\Services\Attendance\GroupMembership.
 */
class TrainingGroupMember extends Model
{
    protected $fillable = ['training_group_id', 'player_id', 'from_date', 'to_date'];

    protected function casts(): array
    {
        return ['training_group_id' => 'integer', 'player_id' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Memberships that cover the day. (Not named `on`: Model::on() picks a connection.) */
    public function scopeActiveOn(Builder $query, string $date): void
    {
        static::constrainActiveOn($query, $date);
    }

    /** The same rule for any query that reads training_group_members (Roster's subquery). */
    public static function constrainActiveOn(mixed $query, string $date): mixed
    {
        $day = substr($date, 0, 10);

        return $query
            ->where(fn ($w) => $w->whereNull('training_group_members.from_date')->orWhere('training_group_members.from_date', '<=', $day))
            ->where(fn ($w) => $w->whereNull('training_group_members.to_date')->orWhere('training_group_members.to_date', '>=', $day));
    }
}
