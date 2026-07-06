<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentCategory extends Model
{
    protected $fillable = ['name', 'code', 'description'];

    /**
     * Catalogs are linked by category NAME (string column), not a foreign key —
     * the lookup was introduced after the catalogs table shipped.
     */
    public function catalogs(): HasMany
    {
        return $this->hasMany(EquipmentCatalog::class, 'category', 'name');
    }

    /**
     * Default serial code derived from a category name: first four
     * alphanumeric characters, uppercased. Falls back to CAT.
     */
    public static function deriveCode(string $name): string
    {
        $alnum = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return $alnum === '' ? 'CAT' : substr($alnum, 0, 4);
    }
}
