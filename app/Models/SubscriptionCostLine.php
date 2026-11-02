<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One forecast cost of a subscription: coach pay, a bus. Never a real expense. */
class SubscriptionCostLine extends Model
{
    use SerializesClubDates;

    public const UNIT_PER_SESSION = 'per_session';

    public const UNITS = [self::UNIT_PER_SESSION, 'per_trip', 'per_month', 'per_season', 'per_player'];

    protected $fillable = ['subscription_id', 'label', 'unit', 'amount', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
