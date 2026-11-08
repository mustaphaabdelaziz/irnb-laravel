<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved edition of the club's year-end literary report: its period, the
 * sections to print and the editorial texts. Figures are never stored; the
 * PDF is computed live from the club's data at export.
 */
class LiteraryReport extends Model
{
    use SerializesClubDates;

    /** Every section, in the fixed print order. */
    public const SECTIONS = ['cover', 'intro', 'members', 'board', 'attendance', 'sports', 'activities', 'finance', 'conclusion', 'signatures', 'photos'];

    protected $fillable = [
        'title', 'period_from', 'period_to', 'sections',
        'president_word', 'introduction', 'conclusion', 'note', 'include_details',
        'signer_1_board_member_id', 'signer_2_board_member_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'sections' => 'array',
            'include_details' => 'boolean',
            'signer_1_board_member_id' => 'integer',
            'signer_2_board_member_id' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return list<string> the enabled sections in print order (unknown keys dropped) */
    public function enabledSections(): array
    {
        return array_values(array_intersect(self::SECTIONS, $this->sections ?? []));
    }
}
