<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'amount_student',
        'amount_worker',
        'details',
        'is_mandatory',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount_student' => 'decimal:2',
            'amount_worker' => 'decimal:2',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_subscription');
    }

    public function playerSubscriptions(): HasMany
    {
        return $this->hasMany(PlayerSubscription::class);
    }

    public function getDesignationAttribute(): string
    {
        return trim($this->name.' - '.$this->year);
    }
}
