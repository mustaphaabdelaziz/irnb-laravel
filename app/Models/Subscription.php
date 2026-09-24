<?php

namespace App\Models;

use App\Support\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    /** Bound to a season; `year` holds the season's END year (2026 = 2025/2026). */
    public const KIND_ANNUAL = 'annual';

    /** A one-off charge (a club t-shirt): no year, never counted as debt. */
    public const KIND_EXCEPTIONAL = 'exceptional';

    public const KINDS = [self::KIND_ANNUAL, self::KIND_EXCEPTIONAL];

    protected $fillable = [
        'name',
        'kind',
        'year',
        'amount_student',
        'amount_worker',
        'details',
        'is_mandatory',
        'is_active',
    ];

    protected $attributes = [
        'kind' => self::KIND_ANNUAL,
    ];

    protected $appends = ['year_label'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount_student' => 'decimal:2',
            'amount_worker' => 'decimal:2',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Categories this subscription is open to, each with an optional price override. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_subscription')
            ->withPivot(['amount_student', 'amount_worker']);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_subscription');
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
    }

    public function isExceptional(): bool
    {
        return $this->kind === self::KIND_EXCEPTIONAL;
    }

    /**
     * Subscriptions a player of the given category may be charged: those with no
     * category (open to all) plus those tagged with that category.
     */
    public function scopeForCategory(Builder $query, ?int $categoryId): Builder
    {
        return $query->where(function (Builder $query) use ($categoryId) {
            $query->whereDoesntHave('categories');

            if ($categoryId) {
                $query->orWhereHas('categories', fn (Builder $sub) => $sub->where('categories.id', $categoryId));
            }
        });
    }

    public function appliesToCategory(?int $categoryId): bool
    {
        $categoryIds = $this->categories->pluck('id')->map(fn ($id) => (int) $id);

        return $categoryIds->isEmpty() || ($categoryId !== null && $categoryIds->contains($categoryId));
    }

    /**
     * What this subscription charges the player: the student or the worker rate,
     * taken from their category's override when it sets one.
     */
    public function amountFor(Player $player): float
    {
        $column = $player->is_student ? 'amount_student' : 'amount_worker';

        $override = $player->category_id
            ? $this->categories->firstWhere('id', $player->category_id)?->pivot?->{$column}
            : null;

        return (float) ($override ?? $this->{$column});
    }

    /** Put a player on this subscription, owing the rate for their status. */
    public function assignTo(Player $player): PlayerSubscription
    {
        return $this->playerSubscriptions()->create([
            'player_id' => $player->id,
            'transaction_id' => null,
            // The obligation row always needs a year (dashboards group by it):
            // a one-off charge takes the year it was assigned in.
            'year' => $this->year ?? (int) now()->year,
            'status_at_time' => $player->is_student ? 'student' : 'worker',
            // A one-off charge is never debt.
            'is_mandatory' => ! $this->isExceptional() && (bool) $this->is_mandatory,
            'amount_owed' => $this->amountFor($player),
            'amount_paid' => 0,
        ]);
    }

    /** "2025/2026" for an annual subscription, null for an exceptional one. */
    public function getYearLabelAttribute(): ?string
    {
        return $this->year ? self::seasonLabel((int) $this->year) : null;
    }

    /**
     * The season that ends in the given year, spelled in full ("2025/2026"),
     * or just "2026" when the club's season is the calendar year.
     */
    public static function seasonLabel(int $endYear): string
    {
        return self::calendarSeason() ? (string) $endYear : ($endYear - 1).'/'.$endYear;
    }

    /** The end year of the season running today — the default for a new annual subscription. */
    public static function currentSeasonEndYear(): int
    {
        $season = Season::current();

        return self::calendarSeason() ? $season->startYear : $season->startYear + 1;
    }

    public function getDesignationAttribute(): string
    {
        return $this->year_label ? $this->name.' - '.$this->year_label : $this->name;
    }

    /** Whether the club's season is the calendar year — read once per request, not once per row. */
    private static function calendarSeason(): bool
    {
        return once(fn () => Season::startMonth() === 1);
    }
}
