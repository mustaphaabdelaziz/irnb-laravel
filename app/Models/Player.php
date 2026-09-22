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
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Player extends Model
{
    use HasFactory;

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
        'state',
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
     * Name search across every part of a player's name, plus the membership ID.
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
                });
            }
        });
    }

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
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

    public function memberJob(): BelongsTo
    {
        return $this->belongsTo(MemberJob::class, 'member_job_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
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
