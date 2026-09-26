<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Explicit activity recording only: call record() in the controller/service
 * at the point the business action succeeds. Never from model observers,
 * migrations, seeders, console fixes, or an import's per-row loop (an import
 * records one summary event). When the action runs inside DB::transaction,
 * call record() in that same transaction.
 */
class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $properties  Small context only (amount,
     *                                            quantity, count, found/missing, file count, category code, kind).
     *                                            Never names, phone numbers, recipients, free text or file names.
     */
    public static function record(
        ?User $user,
        string $action,
        ?Model $subject = null,
        array $properties = [],
    ): ActivityLog {
        if (! in_array($action, ActivityAction::ALL, true)) {
            throw new InvalidArgumentException("Unknown activity action [{$action}].");
        }

        $properties = array_filter($properties, static fn ($value) => $value !== null);

        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'occurred_at' => now(),
        ]);
    }
}
