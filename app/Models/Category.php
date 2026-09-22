<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Observers\CategoryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(CategoryObserver::class)]
class Category extends Model
{
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'description',
    ];

    protected $appends = [
        'localized_name',
    ];

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'category_subscription');
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function financeAccounts(): HasMany
    {
        return $this->hasMany(FinanceAccount::class);
    }
}
