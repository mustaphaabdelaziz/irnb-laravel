<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'player_id',
        'subscription_id',
        'transaction_id',
        'year',
        'status_at_time',
        'amount_owed',
        'amount_paid',
        'is_legacy',
        'due_date',
    ];

    protected $appends = [
        'remaining_amount',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount_owed' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'is_legacy' => 'boolean',
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

    public function getRemainingAmountAttribute(): float
    {
        if ($this->transaction?->category === 'donation') {
            return 0.0;
        }

        return max(0.0, (float) $this->amount_owed - (float) $this->amount_paid);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->transaction?->category === 'donation') {
            return 'exempt';
        }
        $remaining = $this->getRemainingAmountAttribute();
        if ($remaining <= 0) {
            return 'paid';
        }
        if ((float) $this->amount_paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }
}
