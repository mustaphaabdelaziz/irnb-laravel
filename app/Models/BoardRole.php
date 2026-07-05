<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardRole extends Model
{
    protected $fillable = ['name', 'label', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** Members are linked by role NAME (string column), not a foreign key. */
    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class, 'role', 'name');
    }
}
