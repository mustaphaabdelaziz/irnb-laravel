<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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

        // A player's first registration takes over the documents held before it. Later ones
        // leave unlinked rows alone: those belong to a registration that was removed.
        static::created(function (BranchRegistration $registration) {
            if (DB::table('branch_player')->where('player_id', $registration->player_id)->where('id', '!=', $registration->id)->exists()) {
                return;
            }

            DB::table('player_documents')
                ->where('player_id', $registration->player_id)
                ->whereNull('branch_registration_id')
                ->update(['branch_registration_id' => $registration->id]);
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
        // A subquery, so it costs no lookup; NOT IN keeps everyone active when no `left` status exists.
        return $query->where(
            fn ($w) => $w->whereNull($column)->orWhereNotIn($column, PlayerStatus::query()->where('code', 'left')->select('id'))
        );
    }

    /** Still a member on that day: not left, or left that day or later. A left row with no date never counts. */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): void
    {
        static::constrainActiveOn($query, $query->getModel()->qualifyColumn('status_id'), $query->getModel()->qualifyColumn('left_at'), $date);
    }

    /** The same rule for a query on another model, where the columns must be named in full. */
    public static function constrainActiveOn(mixed $query, string $statusColumn, string $leftAtColumn, CarbonInterface|string $date): mixed
    {
        $left = Player::leftStatusId();
        // SQLite may hold a date with a time: compare on the day alone.
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);

        return $left === null ? $query : $query->where(fn ($w) => $w->whereNull($statusColumn)
            ->orWhere($statusColumn, '!=', $left)
            ->orWhere($leftAtColumn, '>=', $day));
    }

    public function scopeInBranches(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('branch_id'), collect($ids)->all());
    }

    public function scopeInCategories(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('category_id'), collect($ids)->all());
    }

    public function scopeWithStatuses(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('status_id'), collect($ids)->all());
    }

    /**
     * One row per registration, plus one with a blank branch for each player with
     * none, whose values live on players. For counts and groups that stay in SQL.
     */
    public static function everyRow(): QueryBuilder
    {
        $columns = ['category_id', 'position_id', 'licence_number', 'status_id', 'left_at', 'membership_id'];

        return DB::table('branch_player')
            ->select(['player_id', 'branch_id', ...$columns])
            ->unionAll(DB::table('players')
                ->select(['id as player_id', DB::raw('NULL as branch_id'), ...$columns])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('branch_player')->whereColumn('branch_player.player_id', 'players.id')));
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

    public function documents(): HasMany
    {
        return $this->hasMany(PlayerDocument::class, 'branch_registration_id');
    }

    public function sportingStatus(): BelongsTo
    {
        return $this->belongsTo(SportingStatus::class);
    }
}
