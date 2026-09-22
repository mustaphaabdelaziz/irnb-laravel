<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Observers\BranchObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy(BranchObserver::class)]
class Branch extends Model
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

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'branch_player');
    }

    /** Equipment lots assigned to this branch. Untagged lots are club-wide. */
    public function equipmentItems(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'branch_equipment_item');
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'branch_subscription');
    }

    public function financeAccounts(): HasMany
    {
        return $this->hasMany(FinanceAccount::class);
    }

    public function treasury(): HasOne
    {
        return $this->hasOne(FinanceAccount::class)->where('is_treasury', true);
    }
}
