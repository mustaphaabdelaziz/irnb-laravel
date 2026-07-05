<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySession extends Model
{
    protected $fillable = [
        'reference', 'type', 'session_date', 'status', 'conducted_by_user_id',
        'total_expected', 'total_found', 'total_missing', 'notes', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventorySessionItem::class);
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by_user_id');
    }
}
