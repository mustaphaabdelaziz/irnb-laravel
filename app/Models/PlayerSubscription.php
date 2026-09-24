<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PlayerSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'player_id',
        'subscription_id',
        'label',
        'transaction_id',
        'year',
        'status_at_time',
        'is_mandatory',
        'is_exempt',
        'amount_owed',
        'amount_paid',
        'discount_type',
        'discount_value',
        'is_legacy',
        'due_date',
    ];

    protected $appends = [
        'discount_amount',
        'net_owed',
        'remaining_amount',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount_owed' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'is_legacy' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_exempt' => 'boolean',
            'due_date' => 'date',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class, 'player_subscription_id');
    }

    /**
     * Keep only lines that are debt: everything except one-off charges (an
     * exceptional subscription such as a club t-shirt), which are tracked as
     * paid/unpaid but never owed to the club as a membership debt. Manual debts
     * (no subscription) stay in. Takes an Eloquent or a plain query builder so
     * the dashboard's raw queries share the rule.
     */
    public static function whereCountsAsDebt(EloquentBuilder|QueryBuilder $query): EloquentBuilder|QueryBuilder
    {
        return $query->where(fn ($q) => $q
            ->whereNull('player_subscriptions.subscription_id')
            ->orWhereNotIn('player_subscriptions.subscription_id', fn ($ids) => $ids
                ->select('id')->from('subscriptions')->where('kind', Subscription::KIND_EXCEPTIONAL)));
    }

    public function isExempt(): bool
    {
        return (bool) $this->is_exempt;
    }

    /**
     * The discount expressed in money. Clamped to [0, amount_owed] so a stored
     * percentage over 100, a negative value, or a fixed amount bigger than the
     * price can never turn into negative debt.
     */
    public function getDiscountAmountAttribute(): float
    {
        $owed = (float) $this->amount_owed;
        $value = (float) $this->discount_value;

        $discount = match ($this->discount_type) {
            'percent' => $owed * $value / 100,
            'amount' => $value,
            default => 0.0,
        };

        return round(max(0.0, min($discount, $owed)), 2);
    }

    /** What the player actually owes once the discount is applied. */
    public function getNetOwedAttribute(): float
    {
        return round(max(0.0, (float) $this->amount_owed - $this->getDiscountAmountAttribute()), 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        if ($this->isExempt()) {
            return 0.0;
        }

        return max(0.0, $this->getNetOwedAttribute() - (float) $this->amount_paid);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->isExempt()) {
            return 'exempt';
        }

        $paid = (float) $this->amount_paid;

        // Nothing left to pay. A fully-discounted obligation settles with no
        // payment at all, so a discount counts as settling it too.
        if ($this->getRemainingAmountAttribute() <= 0 && ($paid > 0 || $this->getDiscountAmountAttribute() > 0)) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }
}
