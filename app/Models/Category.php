<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Models\Concerns\SerializesClubDates;
use App\Observers\CategoryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[ObservedBy(CategoryObserver::class)]
class Category extends Model
{
    use HasFactory, HasLocalizedName;
    use SerializesClubDates;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'description',
        'branch_id',
        'is_active',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Everywhere a category can be stored. A row here means deleting it would lose something. */
    private const USED_IN = [
        'branch_player', 'teams', 'training_sessions', 'training_session_category', 'training_schedules',
        'finance_accounts', 'category_subscription', 'activity_category', 'attendances', 'players', 'users',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }

    /** That branch's categories, plus any with no owner. Null means every category. */
    public function scopeForBranch(Builder $query, ?int $branchId): void
    {
        $query->when($branchId !== null, fn (Builder $q) => $q->where(
            fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)
        ));
    }

    public function isInUse(): bool
    {
        foreach (self::USED_IN as $table) {
            if (DB::table($table)->where('category_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

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
