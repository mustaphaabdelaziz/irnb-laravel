<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who the club pays: shops, printers, leagues, coaches under contract.
 * Picked on payment orders and expenses so the name and RIB are typed once.
 */
class Supplier extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name', 'activity', 'address', 'phone', 'bank_name', 'rib', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** A supplier something points at stays (deactivate it instead). */
    public function isInUse(): bool
    {
        return $this->transactions()->exists();
    }
}
