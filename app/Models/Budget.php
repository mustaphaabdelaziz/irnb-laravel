<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use SerializesClubDates;

    protected $fillable = [
        'fiscal_year_id', 'finance_category_id', 'planned_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }
}
