<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentCategory extends Model
{
    protected $fillable = ['name', 'description'];

    /**
     * Catalogs are linked by category NAME (string column), not a foreign key —
     * the lookup was introduced after the catalogs table shipped.
     */
    public function catalogs(): HasMany
    {
        return $this->hasMany(EquipmentCatalog::class, 'category', 'name');
    }
}
