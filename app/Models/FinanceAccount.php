<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    protected $fillable = [
        'branch_id', 'category_id', 'parent_account_id', 'name', 'type', 'is_treasury',
        'is_opening_fund', 'account_number', 'opening_balance', 'current_balance', 'currency', 'is_active',
        'sort_order', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_treasury' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Active registers for a picker, with the branch/category the UI needs to
     * label them, in display order.
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('is_active', true)
            ->with([
                'branch:id,name,name_ar,name_fr,name_en',
                'category:id,name,name_ar,name_fr,name_en',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->select(['id', 'branch_id', 'category_id', 'parent_account_id', 'name', 'type', 'is_treasury', 'sort_order', 'current_balance']);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(FinanceTransfer::class, 'from_account_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(FinanceTransfer::class, 'to_account_id');
    }
}
