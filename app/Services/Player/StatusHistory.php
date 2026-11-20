<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\RegistrationStatusChange;
use App\Models\User;

/**
 * The past statuses of a branch registration: one dated line per change. The
 * registration row keeps the current status; this history never drives it.
 */
class StatusHistory
{
    public function record(
        BranchRegistration $registration,
        ?int $from,
        ?int $to,
        string $on,
        ?string $note,
        ?User $user,
        string $origin,
    ): RegistrationStatusChange {
        return RegistrationStatusChange::create([
            'branch_registration_id' => $registration->id,
            'from_status_id' => $from,
            'to_status_id' => $to,
            'changed_on' => substr($on, 0, 10),
            'note' => self::cleanNote($note),
            'user_id' => $user?->id,
            'origin' => $origin,
        ]);
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
