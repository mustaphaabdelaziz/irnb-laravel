<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Support\FinanceCategoryTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    use HasLocalizedName;

    protected $fillable = [
        'type', 'name', 'name_ar', 'name_fr', 'name_en', 'code', 'parent_id', 'color', 'sort_order', 'is_active', 'is_system',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected static function booted(): void
    {
        // Categories created later (TransactionObserver, finance:backfill, the UI)
        // get the stock ar/fr names for a known base name; values already set win.
        static::creating(function (FinanceCategory $category) {
            foreach (FinanceCategoryTranslations::for((string) $category->name) ?? [] as $column => $value) {
                if ($category->{$column} === null) {
                    $category->{$column} = $value;
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }
}
