<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change of a branch registration. The registration keeps the current
 * status; these lines are its past and never drive it.
 *
 * Writes go through App\Services\Player\StatusHistory.
 */
class RegistrationStatusChange extends Model
{
    use SerializesClubDates;

    /** Written when a status was changed in the player form. */
    public const ORIGIN_FORM = 'form';

    /** A past line typed by hand on the player page. */
    public const ORIGIN_MANUAL = 'manual';

    /** Written by the system from a leave date about to be lost. No user. */
    public const ORIGIN_DERIVED = 'derived';

    protected $fillable = [
        'branch_registration_id',
        'from_status_id',
        'to_status_id',
        'changed_on',
        'note',
        'user_id',
        'origin',
    ];

    protected function casts(): array
    {
        return [
            'changed_on' => 'date:Y-m-d',
            'from_status_id' => 'integer',
            'to_status_id' => 'integer',
            'user_id' => 'integer',
            'branch_registration_id' => 'integer',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(BranchRegistration::class, 'branch_registration_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(PlayerStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(PlayerStatus::class, 'to_status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
