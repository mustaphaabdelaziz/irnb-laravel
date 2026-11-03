<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A player's membership of one branch, with its own join date, ID, category,
 * position, licence and statuses. A NULL join_date means it needs verifying.
 *
 * Production writes go through App\Services\Player\RegistrationService.
 */
class BranchRegistration extends Pivot
{
    use SerializesClubDates;

    protected $table = 'branch_player';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'join_date' => 'date:Y-m-d',
            'left_at' => 'date:Y-m-d',
            'legacy_join_year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A bare attach() names no details: start from what the player holds.
        static::creating(function (BranchRegistration $registration) {
            if (array_key_exists('category_id', $registration->getAttributes())) {
                return;
            }
            $player = Player::find($registration->player_id);
            if ($player === null) {
                return;
            }
            $ownsPosition = $player->position_id !== null
                && (int) Position::whereKey($player->position_id)->value('branch_id') === (int) $registration->branch_id;
            $registration->fill([
                'category_id' => $player->category_id,
                'position_id' => $ownsPosition ? $player->position_id : null,
                'licence_number' => $player->licence_number,
                'status_id' => $player->status_id,
                'left_at' => $player->left_at?->toDateString(),
                'legacy_join_year' => $player->join_year,
            ]);
        });

        // Same rule as Player: only a "left" registration has a leave date.
        static::saving(function (BranchRegistration $registration) {
            if (! $registration->isDirty(['status_id', 'left_at'])) {
                return;
            }
            if (! Player::isLeftStatus($registration->status_id)) {
                $registration->left_at = null;
            } elseif ($registration->left_at === null) {
                $registration->left_at = now()->toDateString();
            }
        });
    }

    /** Not left: no status at all, or any status but the one coded `left`. Nothing extra when that status is missing. */
    public function scopeActive(Builder $query): void
    {
        static::constrainActive($query, $query->getModel()->qualifyColumn('status_id'));
    }

    /**
     * The same rule for a query on another model that joins this table, where
     * the status column must be named in full (e.g. branch_player.status_id).
     */
    public static function constrainActive(mixed $query, string $column): mixed
    {
        $left = Player::leftStatusId();

        return $left === null ? $query : $query->where(
            fn ($w) => $w->whereNull($column)->orWhere($column, '!=', $left)
        );
    }

    public function scopeNeedsJoinDate(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('join_date'));
    }

    /** The exact date's year, else the year-only value; null when neither is known. */
    public function effectiveYear(): ?int
    {
        return $this->join_date?->year ?? $this->legacy_join_year;
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PlayerStatus::class, 'status_id');
    }

    public function sportingStatus(): BelongsTo
    {
        return $this->belongsTo(SportingStatus::class);
    }
}
