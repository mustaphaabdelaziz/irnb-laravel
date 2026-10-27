<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BoardCommittee extends Model
{
    use SerializesClubDates;

    /** Seats per position; plain members are unlimited. */
    public const LIMITS = ['head' => 1, 'deputy' => 2];

    public const POSITIONS = ['head', 'deputy', 'member'];

    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(BoardMember::class, 'board_committee_member')
            ->withPivot('position')
            ->withTimestamps();
    }
}
