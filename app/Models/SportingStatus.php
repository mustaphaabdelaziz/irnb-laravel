<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportingStatus extends Model
{
    use HasLocalizedName;
    use SerializesClubDates;

    protected $fillable = ['name', 'name_ar', 'name_fr', 'name_en', 'sort_order', 'is_active'];

    protected $appends = ['localized_name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }
}
