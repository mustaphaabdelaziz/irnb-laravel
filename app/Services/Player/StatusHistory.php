<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\Player;
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

    /**
     * Called while a registration is being saved: when it stops being left and its
     * old leave day is known, that departure becomes a line before the day is wiped.
     * A day already recorded is not written twice.
     */
    public function keepDeparture(BranchRegistration $registration): void
    {
        $oldStatus = $registration->getRawOriginal('status_id');
        $oldLeftAt = $registration->getRawOriginal('left_at');
        if (! $registration->exists
            || ! Player::isLeftStatus($oldStatus)
            || $oldLeftAt === null
            || Player::isLeftStatus($registration->status_id)) {
            return;
        }

        $day = substr((string) $oldLeftAt, 0, 10);
        $recorded = RegistrationStatusChange::where('branch_registration_id', $registration->id)
            ->where('to_status_id', (int) $oldStatus)
            ->whereDate('changed_on', $day)
            ->exists();
        if (! $recorded) {
            $this->record($registration, null, (int) $oldStatus, $day, null, null, RegistrationStatusChange::ORIGIN_DERIVED);
        }
    }

    /** The departure line of the old leave day follows a corrected leave date. */
    public function moveDeparture(BranchRegistration $registration, ?string $oldRaw, ?string $new): void
    {
        if ($oldRaw === null || $new === null) {
            return;
        }

        RegistrationStatusChange::where('branch_registration_id', $registration->id)
            ->where('to_status_id', (int) $registration->status_id)
            ->whereDate('changed_on', substr($oldRaw, 0, 10))
            ->update(['changed_on' => $new, 'updated_at' => now()]);
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
