<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The forecast settings of a subscription (see resources/js/lib/tarification.js).
 * Apart from `subscriptions` so coach pay is never serialised by accident.
 */
class SubscriptionTarification extends Model
{
    use SerializesClubDates;

    public const DEFAULT_WEEKS = 40;

    public const DEFAULT_PAYMENT_RATE = 100;

    protected $fillable = ['subscription_id', 'sessions_per_week', 'weeks', 'expected_players', 'expected_payment_rate'];

    protected function casts(): array
    {
        return [
            'sessions_per_week' => 'integer',
            'weeks' => 'integer',
            'expected_players' => 'integer',
            'expected_payment_rate' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
