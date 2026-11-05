<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Services\Player\RegistrationDisplay;
use App\Support\FiscalPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    use HasFactory;
    use SerializesClubDates;

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

    /** Forecast settings; coach pay stays out of every prop unless loaded on purpose. */
    public function tarification(): HasOne
    {
        return $this->hasOne(SubscriptionTarification::class);
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(SubscriptionCostLine::class)->orderBy('sort_order');
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

    /**
     * Subscriptions a player may be newly charged: open to the branch and the
     * category of one active registration. Left everywhere: none. A player with no
     * registration at all has only their own category, and no branch.
     */
    public function scopeOpenTo(Builder $query, Player $player): Builder
    {
        $rows = $player->registrations()->active()->get(['branch_id', 'category_id']);

        if ($rows->isEmpty()) {
            if ($player->registrations()->exists() || Player::isLeftStatus($player->status_id)) {
                return $query->whereRaw('1 = 0');
            }
            $rows = collect([(object) ['branch_id' => null, 'category_id' => $player->category_id]]);
        }

        return $query->where(function (Builder $any) use ($rows) {
            foreach ($rows as $row) {
                $any->orWhere(fn (Builder $one) => $one
                    ->forCategory($row->category_id)
                    ->where(function (Builder $open) use ($row) {
                        $open->whereDoesntHave('branches');

                        if ($row->branch_id) {
                            $open->orWhereHas('branches', fn (Builder $sub) => $sub->where('branches.id', $row->branch_id));
                        }
                    }));
            }
        });
    }

    /**
     * Whether the player may be put on this subscription: Player::scopeEligibleFor,
     * so the rule exists once. $anyCategory waives the category (a hand-picked
     * player from another category); the branch must still match.
     */
    public function appliesTo(Player $player, bool $anyCategory = false): bool
    {
        return Player::query()->whereKey($player->id)->eligibleFor($this, $anyCategory)->exists();
    }

    /**
     * What this subscription charges the player: the student or the worker rate,
     * from the override of the category that prices them (see pricingCategoryId).
     * A category not on its list pays the price of its one category when it has
     * just one, the default otherwise. A loop passes one $display.
     */
    public function amountFor(Player $player, ?RegistrationDisplay $display = null): float
    {
        $column = $player->is_student ? 'amount_student' : 'amount_worker';

        $categoryId = $this->pricingCategoryId($player, $display ?? RegistrationDisplay::make());
        $category = $categoryId ? $this->categories->firstWhere('id', $categoryId) : null;
        $category ??= $this->categories->count() === 1 ? $this->categories->first() : null;

        return (float) ($category?->pivot?->{$column} ?? $this->{$column});
    }

    /**
     * The one active registration matching a subscription with branches; the main
     * registration when several match or it has no branch. Nothing when a
     * hand-added player matches none. A player with no registration: their own.
     */
    private function pricingCategoryId(Player $player, RegistrationDisplay $display): ?int
    {
        $main = $display->main($player);
        if ($main === null) {
            return $player->category_id;
        }

        $branchIds = $this->branches->pluck('id');
        if ($branchIds->isEmpty()) {
            return $main->category_id;
        }

        $categoryIds = $this->categories->pluck('id');
        $matching = $player->registrations->filter(fn ($row) => $branchIds->contains($row->branch_id)
            && $display->isActiveIn($player, (int) $row->branch_id)
            && ($categoryIds->isEmpty() || $categoryIds->contains($row->category_id)));

        return match ($matching->count()) {
            0 => null,
            1 => $matching->first()->category_id,
            default => $main->category_id,
        };
    }

    /** Put a player on this subscription, owing the rate for their status. */
    public function assignTo(Player $player, ?RegistrationDisplay $display = null): PlayerSubscription
    {
        return $this->playerSubscriptions()->create([
            'player_id' => $player->id,
            'transaction_id' => null,
            // The obligation row always needs a year (dashboards group by it):
            // a one-off charge takes the season it was assigned in.
            'year' => $this->year ?? FiscalPeriod::currentKey(),
            'status_at_time' => $player->is_student ? 'student' : 'worker',
            // A one-off charge is never mandatory (it still counts as debt).
            'is_mandatory' => ! $this->isExceptional() && (bool) $this->is_mandatory,
            'amount_owed' => $this->amountFor($player, $display),
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
        return FiscalPeriod::label($endYear);
    }

    /** The end year of the season running today — the default for a new annual subscription. */
    public static function currentSeasonEndYear(): int
    {
        return FiscalPeriod::currentKey();
    }

    public function getDesignationAttribute(): string
    {
        return $this->year_label ? $this->name.' - '.$this->year_label : $this->name;
    }
}
