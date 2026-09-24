<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $fillable = [
        'year', 'label', 'start_date', 'end_date', 'status',
        'opening_balance', 'closing_balance', 'total_income', 'total_expense',
        'closed_at', 'closed_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Every transaction booked in this year, archived or not. Matches the FK and
     * the plain `fiscal_year` column alike: the closed-year checks and totals
     * key on the integer, so a row missing the FK still belongs here.
     */
    public function ownTransactions(): Builder
    {
        return Transaction::query()->where(fn (Builder $q) => $q
            ->where('fiscal_year_id', $this->id)
            ->orWhere('fiscal_year', $this->year));
    }

    /** An open year holding no active transaction can be deleted. */
    public function isDeletable(): bool
    {
        return ! $this->isClosed()
            && ! $this->ownTransactions()->where('archived', false)->exists();
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
