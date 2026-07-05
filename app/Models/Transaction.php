<?php

namespace App\Models;

use App\Observers\TransactionObserver;
use App\Support\Media;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'transaction_date',
        'transaction_type',
        'category',
        'sub_category',
        'payment_method',
        'payment_account',
        'payment_ccp_key',
        'payment_bank_name',
        'payment_holder',
        'payment_reference',
        'related_entity_type',
        'related_entity_id',
        'player_subscription_id',
        'description',
        'recorded_by_user_id',
        'received_by_user_id',
        'status',
        'archived',
        'receipt_url',
        'receipt_filename',
        'fiscal_year',
        'fiscal_year_id',
        'finance_category_id',
        'finance_account_id',
    ];

    /** Normalise the stored receipt URL to a host-relative /media path (web + desktop). */
    protected function receiptUrl(): Attribute
    {
        return Attribute::make(get: fn ($value) => Media::path($value));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'datetime',
            'archived' => 'boolean',
            'fiscal_year' => 'integer',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function financeCategory(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function financeAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function playerSubscription(): BelongsTo
    {
        return $this->belongsTo(PlayerSubscription::class);
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
    }
}
