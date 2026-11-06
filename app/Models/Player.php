<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Support\Media;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Player extends Model
{
    use HasFactory;
    use SerializesClubDates;

    /** What the fullname accessor reads: add these to any query that narrows its columns. */
    public const NAME_COLUMNS = ['firstname', 'lastname', 'nickname', 'father', 'grandfather', 'gender'];

    protected $fillable = [
        'membership_id',
        'firstname',
        'lastname',
        'nickname',
        'father',
        'grandfather',
        'birthdate',
        'gender',
        'picture_url',
        'picture_filename',
        'phones',
        'email',
        'status_class',
        'status_value',
        'status_id',
        'left_at',
        'state',
        'wilaya_id',
        'city',
        'is_student',
        'member_job_id',
        'join_year',
        'archived',
        'category_id',
        'position_id',
        'team',
        'health_medical_conditions',
        'health_blood_group_rhesus',
        'licence_number',
        'outstanding_debt',
    ];

    /** Expose the computed Arabic full name to the frontend (tables, profile). */
    protected $appends = [
        'fullname',
        'age',
    ];

    /**
     * Keep the leave date in step with the status: only a member whose status
     * is "left" (code `left`) has one. Moving to "left" without a date stamps
     * today; moving to any other status clears it. Bulk status changes bypass
     * model events, so a code path that uses them must apply this rule itself.
     */
    protected static function booted(): void
    {
        static::saving(function (Player $player) {
            if (! $player->isDirty(['status_id', 'left_at'])) {
                return;
            }

            if (! static::isLeftStatus($player->status_id)) {
                $player->left_at = null;
            } elseif ($player->left_at === null) {
                $player->left_at = now()->toDateString();
            }
        });

        // Board members keep a copy of their player's name for narrow lookups.
        static::updated(function (Player $player) {
            if ($player->wasChanged(self::NAME_COLUMNS)) {
                BoardMember::where('player_id', $player->id)->update(['name' => $player->fullname]);
            }
        });
    }

    public static function leftStatusId(): ?int
    {
        return PlayerStatus::where('code', 'left')->value('id');
    }

    /**
     * Players who have not left the club: no status at all, or any status but
     * the one coded `left`. A subquery, so it costs no extra lookup. Shared by
     * the players list default and the dashboard figures that drill into it.
     */
    public function scopeNotLeft(Builder $query): void
    {
        $column = $query->getModel()->qualifyColumn('status_id');

        $query->where(fn (Builder $q) => $q->whereNull($column)
            ->orWhereNotIn($column, PlayerStatus::query()->select('id')->where('code', 'left')));
    }

    /**
     * The one answer to "is this player in this branch, with this category and
     * status, on this date": all conditions hold on the same registration. A
     * player with no registration at all is judged on their own columns, and has
     * no branch. Null means no condition; an empty list matches nothing, so a
     * computed list can never silently widen to everyone.
     *
     * $also adds a rule of the caller's to that same row, or to a player's own
     * columns: called with the query and the status and leave-date column names.
     */
    public function scopeRegisteredWhere(
        Builder $query,
        iterable|int|null $branches = null,
        iterable|int|null $categories = null,
        iterable|int|null $statuses = null,
        CarbonInterface|string|null $activeOn = null,
        bool $active = false,
        ?Closure $also = null,
        bool $noStatus = false,
    ): void {
        $playerKey = $query->getModel()->qualifyColumn('id');

        $rows = BranchRegistration::query()
            ->selectRaw('1')
            ->whereColumn('branch_player.player_id', $playerKey)
            ->when($branches !== null, fn ($q) => $q->inBranches($branches))
            ->when($categories !== null, fn ($q) => $q->inCategories($categories))
            ->when($statuses !== null && ! $noStatus, fn ($q) => $q->withStatuses($statuses))
            ->when($noStatus, fn ($q) => $q->where(fn ($s) => $s->whereNull('branch_player.status_id')
                ->when($statuses !== null, fn ($s) => $s->orWhereIn('branch_player.status_id', collect($statuses)->all()))))
            ->when($activeOn !== null, fn ($q) => $q->activeOn($activeOn))
            ->when($active, fn ($q) => $q->active())
            ->when($also !== null, fn ($q) => $also($q, 'branch_player.status_id', 'branch_player.left_at'));

        $query->where(function (Builder $either) use ($rows, $playerKey, $branches, $categories, $statuses, $activeOn, $active, $also, $noStatus) {
            $either->whereExists($rows);

            if ($branches !== null) {
                return;
            }

            $either->orWhere(function (Builder $flat) use ($playerKey, $categories, $statuses, $activeOn, $active, $also, $noStatus) {
                $column = $flat->getModel()->qualifyColumn(...);
                $flat->whereNotExists(fn ($q) => $q->selectRaw('1')->from('branch_player')->whereColumn('branch_player.player_id', $playerKey))
                    ->when($categories !== null, fn ($q) => $q->whereIn($column('category_id'), collect($categories)->all()))
                    ->when($statuses !== null && ! $noStatus, fn ($q) => $q->whereIn($column('status_id'), collect($statuses)->all()))
                    ->when($noStatus, fn ($q) => $q->where(fn ($s) => $s->whereNull($column('status_id'))
                        ->when($statuses !== null, fn ($s) => $s->orWhereIn($column('status_id'), collect($statuses)->all()))))
                    ->when($activeOn !== null, fn ($q) => BranchRegistration::constrainActiveOn($q, $column('status_id'), $column('left_at'), $activeOn))
                    ->when($active, fn ($q) => $q->notLeft())
                    ->when($also !== null, fn ($q) => $also($q, $column('status_id'), $column('left_at')));
            });
        });
    }

    /**
     * What a list's filters mean. With a branch, every condition holds on that
     * branch's registration. Club-wide, a status is the person's overall status
     * (left only when left everywhere), and a category is any registration still
     * active, or any at all when the list asks for left people or shows everyone.
     */
    public function scopeListedWhere(
        Builder $query,
        ?array $branches = null,
        ?array $categories = null,
        ?array $statuses = null,
        bool $noStatus = false,
        bool $hideLeft = false,
    ): void {
        $statusAsked = $statuses !== null || $noStatus;

        if ($branches !== null) {
            $query->registeredWhere(branches: $branches, categories: $categories, statuses: $statuses, active: $hideLeft && ! $statusAsked, noStatus: $noStatus);

            return;
        }

        $column = $query->getModel()->qualifyColumn('status_id');
        if ($statusAsked) {
            $query->where(fn (Builder $q) => $q
                ->when($noStatus, fn ($q) => $q->orWhereNull($column))
                ->when($statuses !== null, fn ($q) => $q->orWhereIn($column, $statuses)));
        } elseif ($hideLeft) {
            $query->notLeft();
        }

        if ($categories !== null) {
            $askedLeft = in_array(static::leftStatusId(), $statuses ?? [], false);
            $query->registeredWhere(categories: $categories, active: $statusAsked ? ! $askedLeft : $hideLeft);
        }
    }

    /** This player's registration in a branch, from the loaded rows: no query per row when eager-loaded. */
    public function registrationIn(?int $branchId): ?BranchRegistration
    {
        return $branchId === null ? null : $this->registrations->firstWhere('branch_id', $branchId);
    }

    public static function isLeftStatus(mixed $statusId): bool
    {
        return $statusId !== null && $statusId !== '' && (int) $statusId === static::leftStatusId();
    }

    /** Normalise the stored photo URL to a host-relative /media path (web + desktop). */
    protected function pictureUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

    /**
     * Players a subscription applies to: one active registration in one of its
     * branches (any branch when it has none) with one of its categories (any
     * when it has none). Subscription::appliesTo asks this same query.
     *
     * With $anyCategory only the branch rule holds: a player of another category
     * can still be put on it by hand. $onlyCategory narrows to one category,
     * on that same registration.
     */
    public function scopeEligibleFor(Builder $query, Subscription $subscription, bool $anyCategory = false, ?int $onlyCategory = null): void
    {
        $own = $subscription->categories->pluck('id');
        $branches = $subscription->branches->pluck('id');

        $categories = match (true) {
            $onlyCategory !== null => $anyCategory || $own->isEmpty() || $own->contains($onlyCategory) ? [$onlyCategory] : [],
            $anyCategory || $own->isEmpty() => null,
            default => $own,
        };

        $query->registeredWhere(
            branches: $branches->isEmpty() ? null : $branches,
            categories: $categories,
            active: true,
        );
    }

    /**
     * Name search across every part of a player's name, plus the membership ID
     * and the paper-folder file number.
     *
     * The full name is spread over several columns (lastname firstname (nickname)
     * بن father بن grandfather), so matching the raw term against single columns fails
     * for anything but one word. Instead each whitespace-separated token must match
     * SOME column (AND across tokens, OR across columns). That makes the search
     * order-independent and works with a full name, a partial one, and with or
     * without the بن connector — while still requiring all tokens to land on the
     * same player.
     *
     * A search left with no usable token (e.g. just "بن") matches NO players, not
     * every player: this scope also backs an id subquery (transaction search), where
     * an unfiltered query would silently widen the result to everyone instead of no one.
     */
    public function scopeSearch(Builder $query, string $search): void
    {
        $columns = ['firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id'];

        // بن / بنت are connectors in the rendered full name, not part of any column.
        $tokens = collect(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->reject(fn ($token) => $token === 'بن' || $token === 'بنت')
            ->values();

        if ($tokens->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $inner) use ($token, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhereLike($column, '%'.$token.'%');
                    }

                    // Every branch's ID, and the old one from before conversion.
                    $inner->orWhereExists(fn ($q) => $q->from('branch_player')
                        ->whereColumn('branch_player.player_id', 'players.id')
                        ->where(fn ($ids) => $ids
                            ->whereLike('branch_player.membership_id', '%'.$token.'%')
                            ->orWhereLike('branch_player.legacy_membership_id', '%'.$token.'%')));
                });
            }
        });
    }

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'left_at' => 'date:Y-m-d',
            'phones' => 'array',
            'is_student' => 'boolean',
            'join_year' => 'integer',
            'archived' => 'boolean',
            'outstanding_debt' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(CountryState::class, 'wilaya_id');
    }

    public function memberJob(): BelongsTo
    {
        return $this->belongsTo(MemberJob::class, 'member_job_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Positions the player also covers; the main one is position_id and is never in here. */
    public function otherPositions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'player_other_positions');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PlayerStatus::class, 'status_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_player')->using(BranchRegistration::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(PlayerEmergencyContact::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(PlayerAchievement::class);
    }

    /** School years, oldest first. */
    public function academicYears(): HasMany
    {
        return $this->hasMany(PlayerAcademicYear::class)->orderBy('academic_year');
    }

    public function academicRecords(): HasManyThrough
    {
        return $this->hasManyThrough(PlayerAcademicRecord::class, PlayerAcademicYear::class);
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PlayerDocument::class);
    }

    public function equipmentRentals(): MorphMany
    {
        return $this->morphMany(EquipmentRental::class, 'rentable');
    }

    /** Age in whole years, or null when no birthdate is on record. */
    public function getAgeAttribute(): ?int
    {
        return $this->birthdate?->age;
    }

    /**
     * The one name every screen, export and PDF shows:
     * "lastname firstname (nickname) بن father بن grandfather".
     * A girl is "بنت" her father; the grandfather link stays "بن".
     * Queries that narrow their columns must select NAME_COLUMNS.
     */
    public function getFullnameAttribute(): string
    {
        return self::fullnameOf($this->lastname, $this->firstname, $this->nickname, $this->father, $this->grandfather, $this->gender);
    }

    /**
     * Player::fullname from the NAME_COLUMNS values, for long lists read as
     * plain rows (hydrating a model per row cost more than the rest of the
     * page). Same rule as the accessor, which uses it.
     */
    public static function fullnameOf(?string $lastname, ?string $firstname, ?string $nickname, ?string $father, ?string $grandfather, ?string $gender): string
    {
        $parts = [
            $lastname,
            $firstname,
        ];

        if ($nickname) {
            $parts[] = '('.$nickname.')';
        }

        $full = trim(implode(' ', array_filter($parts)));

        if ($father) {
            $full .= (strtolower(trim((string) $gender)) === 'female' ? ' بنت ' : ' بن ').$father;

            if ($grandfather) {
                $full .= ' بن '.$grandfather;
            }
        }

        return trim($full);
    }

    /**
     * Outstanding debt = what is still owed across every obligation assigned to the
     * player. Optional subscriptions and one-off (exceptional) charges count too:
     * assigning one means the player owes it. Exempt obligations report a
     * remaining_amount of 0, so they drop out here.
     */
    public function calculateTotalDebt(): float
    {
        return (float) $this->playerSubscriptions()
            ->with('payments')
            ->get()
            ->sum(fn (PlayerSubscription $sub) => $sub->remaining_amount);
    }
}
