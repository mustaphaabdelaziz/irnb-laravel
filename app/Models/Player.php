<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        // file_number is intentionally NOT mass-assignable: it is allocated
        // once by FileNumber::assign() (via forceFill) and must never be set
        // through a create()/update() array — see app/Services/Player/FileNumber.php.
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
        'skill_level',
        'health_medical_conditions',
        'health_blood_group_rhesus',
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
     * model events and apply the same rule in SQL (see leaveDateUpdate()).
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
    }

    public static function leftStatusId(): ?int
    {
        return PlayerStatus::where('code', 'left')->value('id');
    }

    public static function isLeftStatus(mixed $statusId): bool
    {
        return $statusId !== null && $statusId !== '' && (int) $statusId === static::leftStatusId();
    }

    /**
     * The left_at column value for a bulk status change to $statusId: keep an
     * existing date (or stamp today) when moving to "left", clear otherwise.
     */
    public static function leaveDateUpdate(mixed $statusId): mixed
    {
        return static::isLeftStatus($statusId)
            ? DB::raw('COALESCE(left_at, '.DB::getPdo()->quote(now()->toDateString()).')')
            : null;
    }

    /** Normalise the stored photo URL to a host-relative /media path (web + desktop). */
    protected function pictureUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

    /**
     * Players a subscription applies to: everyone when it has no category,
     * otherwise the players of its categories. The query-side twin of
     * Subscription::appliesToCategory().
     */
    public function scopeEligibleFor(Builder $query, Subscription $subscription): void
    {
        $categoryIds = $subscription->categories->pluck('id');

        if ($categoryIds->isNotEmpty()) {
            $query->whereIn('category_id', $categoryIds);
        }
    }

    /**
     * Name search across every part of a player's name, plus the membership ID
     * and the paper-folder file number.
     *
     * The full name is spread over several columns (lastname firstname (nickname)
     * بن father grandfather), so matching the raw term against single columns fails
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

        // بن is a connector in the rendered full name, not part of any column.
        $tokens = collect(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->reject(fn ($token) => $token === 'بن')
            ->values();

        if ($tokens->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $inner) use ($token, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', '%'.$token.'%');
                    }

                    // A folder number, typed with or without its leading zeros.
                    if (ctype_digit((string) $token)) {
                        $inner->orWhere('file_number', (int) ltrim((string) $token, '0'));
                    }
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
            'skill_level' => 'integer',
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
        return $this->belongsToMany(Branch::class, 'branch_player');
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

    /** "Firstname Lastname" for lists and labels; never "Amine null". */
    public function getShortNameAttribute(): string
    {
        return trim($this->firstname.' '.($this->lastname ?? ''));
    }

    public function getFullnameAttribute(): string
    {
        $parts = [
            $this->lastname,
            $this->firstname,
        ];

        if ($this->nickname) {
            $parts[] = '('.$this->nickname.')';
        }

        $full = trim(implode(' ', array_filter($parts)));

        if ($this->father) {
            $full .= ' بن '.$this->father;

            if ($this->grandfather) {
                $full .= ' '.$this->grandfather;
            }
        }

        return trim($full);
    }

    /**
     * Outstanding debt = what is still owed across every obligation assigned to the
     * player. Optional subscriptions count too: assigning one means the player owes
     * it. Exempt obligations report a remaining_amount of 0, so they drop out here.
     */
    public function calculateTotalDebt(): float
    {
        return (float) $this->playerSubscriptions()
            ->with('payments')
            ->get()
            ->sum(fn (PlayerSubscription $sub) => $sub->remaining_amount);
    }
}
