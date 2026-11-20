<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\RegistrationStatusChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Correct a line by hand: date and note on any line, the statuses on a manual one.
     * The leave date and its line are one fact: moving the line of the current
     * departure moves the registration's leave date with it.
     *
     * @param  array<string, mixed>  $data
     */
    public function correct(RegistrationStatusChange $line, array $data): void
    {
        DB::transaction(function () use ($line, $data) {
            $oldDay = $line->changed_on->toDateString();
            $line->changed_on = $data['changed_on'];
            if (array_key_exists('note', $data)) {
                $line->note = self::cleanNote($data['note']);
            }
            if ($line->origin === RegistrationStatusChange::ORIGIN_MANUAL) {
                if (array_key_exists('to_status_id', $data)) {
                    $line->to_status_id = $data['to_status_id'];
                }
                if (array_key_exists('from_status_id', $data)) {
                    $line->from_status_id = $data['from_status_id'];
                }
            }
            $line->save();

            $registration = $line->registration;
            if (Player::isLeftStatus($registration->status_id)
                && (int) $line->to_status_id === (int) $registration->status_id
                && $registration->left_at?->toDateString() === $oldDay) {
                $registration->forceFill(['left_at' => $line->changed_on->toDateString()])->saveQuietly();
                app(RegistrationService::class)->refreshMirror($registration->player);
            }
        });
    }

    /**
     * Give each registration its lines for the player page, as `status_history`,
     * in one query. Users go out as a name only.
     *
     * @param  Collection<int, BranchRegistration>  $registrations
     */
    public function attachTo(Collection $registrations): void
    {
        $lines = RegistrationStatusChange::query()
            ->whereIn('branch_registration_id', $registrations->modelKeys())
            ->with(['fromStatus', 'toStatus', 'user:id,name,firstname,lastname'])
            ->orderBy('changed_on')
            ->orderBy('id')
            ->get()
            ->groupBy('branch_registration_id');

        $registrations->each(fn (BranchRegistration $registration) => $registration->setAttribute(
            'status_history',
            ($lines[$registration->id] ?? collect())->map(fn (RegistrationStatusChange $line) => $this->present($line))->values()->all(),
        ));
    }

    /** @return array<string, mixed> */
    private function present(RegistrationStatusChange $line): array
    {
        $status = fn ($s) => $s === null ? null : ['id' => $s->id, 'code' => $s->code, 'localized_name' => $s->localized_name];

        return [
            'id' => $line->id,
            'from_status_id' => $line->from_status_id,
            'to_status_id' => $line->to_status_id,
            'from_status' => $status($line->fromStatus),
            'to_status' => $status($line->toStatus),
            'changed_on' => $line->changed_on->toDateString(),
            'note' => $line->note,
            'origin' => $line->origin,
            'user' => $line->user?->fullname,
            'recorded_at' => $line->created_at?->toIso8601String(),
        ];
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
