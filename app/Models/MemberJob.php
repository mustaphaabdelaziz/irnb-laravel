<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberJob extends Model
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

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
