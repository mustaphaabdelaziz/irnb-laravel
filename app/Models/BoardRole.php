<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardRole extends Model
{
    use SerializesClubDates;

    /** Organigram rows: top (president), bureau (secretary-general, treasurer…), member. */
    public const LEVELS = ['top', 'bureau', 'member'];

    protected $fillable = ['name', 'label', 'level', 'sort_order'];

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
