<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'amount_student',
        'amount_worker',
        'details',
        'is_mandatory',
        'is_active',
    ];

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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_subscription');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_subscription');
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
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

    /** What this subscription charges the player: the student or the worker rate. */
    public function amountFor(Player $player): float
    {
        return $player->is_student ? (float) $this->amount_student : (float) $this->amount_worker;
    }

    /** Put a player on this subscription, owing the rate for their status. */
    public function assignTo(Player $player): PlayerSubscription
    {
        return $this->playerSubscriptions()->create([
            'player_id' => $player->id,
            'transaction_id' => null,
            'year' => $this->year,
            'status_at_time' => $player->is_student ? 'student' : 'worker',
            'is_mandatory' => (bool) $this->is_mandatory,
            'amount_owed' => $this->amountFor($player),
            'amount_paid' => 0,
        ]);
    }

    public function getDesignationAttribute(): string
    {
        return trim($this->name.' - '.$this->year);
    }
}
