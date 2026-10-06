<?php

namespace App\Support;

use App\Models\User;

/**
 * "Log in as": an admin signs in as another user to see exactly what that
 * user sees. The session remembers who really is at the keyboard, so every
 * recorded event keeps the real person and the way back.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public static function impersonatorId(): ?int
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return null;
        }

        $id = session(self::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function active(): bool
    {
        return self::impersonatorId() !== null;
    }

    /** Why $actor may not log in as $target, or null when allowed. */
    public static function refusal(User $actor, User $target): ?string
    {
        return match (true) {
            self::active() => 'flash.impersonate_nested',
            ! $actor->isGodAdmin() => 'flash.impersonate_forbidden',
            $actor->is($target) => 'flash.impersonate_self',
            $target->isSuperadmin() => 'flash.impersonate_superadmin',
            ! $target->is_active || ! $target->approved => 'flash.impersonate_inactive',
            default => null,
        };
    }
}
