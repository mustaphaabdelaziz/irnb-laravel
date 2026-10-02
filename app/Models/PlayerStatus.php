<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerStatus extends Model
{
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'sort_order',
        'is_active',
    ];

    protected $appends = [
        'localized_name',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return list<array{id: int, name: string}> every status, in the lookup's order, as {id, name} in the current locale */
    public static function options(): array
    {
        return static::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PlayerStatus $s) => ['id' => $s->id, 'name' => $s->localized_name])->values()->all();
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'status_id');
    }
}
