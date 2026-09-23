<?php

namespace App\Models\Concerns;

/**
 * For models with a base `name` plus optional `name_ar` / `name_fr` / `name_en`
 * columns. Add 'localized_name' to the model's $appends to serialize it.
 */
trait HasLocalizedName
{
    /** The name in the current app locale, falling back to the base name. */
    public function getLocalizedNameAttribute(): string
    {
        $column = 'name_'.app()->getLocale();

        return $this->{$column} ?: $this->name;
    }
}
