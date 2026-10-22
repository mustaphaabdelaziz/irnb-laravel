<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerSubscription extends Model
{
    use HasFactory;
    use SerializesClubDates;

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

    public function isExempt(): bool
    {
        return (bool) $this->is_exempt;
    }

    public function getDiscountAmountAttribute(): float
    {
        return $this->money()['discount'];
    }

    /** What the player actually owes once the discount is applied. */
    public function getNetOwedAttribute(): float
    {
        return $this->money()['net_owed'];
    }

    public function getRemainingAmountAttribute(): float
    {
        return $this->money()['remaining'];
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->money()['status'];
    }

    /**
     * Every money figure of this obligation at once (see moneyFor), for code
     * that needs several of them per row.
     *
     * @return array{owed: float, paid: float, discount: float, net_owed: float, remaining: float, status: string}
     */
    public function money(): array
    {
        return self::moneyFor(
            $this->attributes['amount_owed'] ?? null,
            $this->attributes['amount_paid'] ?? null,
            $this->isExempt(),
            $this->discount_type,
            $this->attributes['discount_value'] ?? null,
        );
    }

    /**
     * Discount, net owed, remaining and payment status from the stored values,
     * in one pass. Also used on plain query rows (per-branch totals), so every
     * screen agrees with the model without hydrating thousands of models.
     *
     * The discount is clamped to [0, amount_owed] so a stored percentage over
     * 100, a negative value, or a fixed amount bigger than the price can never
     * turn into negative debt.
     *
     * @return array{owed: float, paid: float, discount: float, net_owed: float, remaining: float, status: string}
     */
    public static function moneyFor(mixed $amountOwed, mixed $amountPaid, bool $exempt, ?string $discountType, mixed $discountValue): array
    {
        $owed = self::decimal($amountOwed);
        $paid = self::decimal($amountPaid);
        $value = self::decimal($discountValue);

        $discount = match ($discountType) {
            'percent' => $owed * $value / 100,
            'amount' => $value,
            default => 0.0,
        };
        $discount = round(max(0.0, min($discount, $owed)), 2);
        $net = round(max(0.0, $owed - $discount), 2);
        $remaining = $exempt ? 0.0 : max(0.0, $net - $paid);

        $status = match (true) {
            $exempt => 'exempt',
            // Nothing left to pay. A fully-discounted obligation settles with no
            // payment at all, so a discount counts as settling it too.
            $remaining <= 0 && ($paid > 0 || $discount > 0) => 'paid',
            $paid > 0 => 'partial',
            default => 'unpaid',
        };

        return ['owed' => $owed, 'paid' => $paid, 'discount' => $discount, 'net_owed' => $net, 'remaining' => $remaining, 'status' => $status];
    }

    /**
     * A stored money value read exactly as the `decimal:2` cast reads it, as a
     * float. Values with at most two decimals (all of them, in practice) skip
     * the cast's arbitrary-precision parse, which cost most of the time of a
     * list of obligations.
     */
    private static function decimal(mixed $raw): float
    {
        if ($raw === null) {
            return 0.0;
        }

        $value = (string) $raw;
        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            return (float) $value;
        }

        return (float) (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
    }
}
