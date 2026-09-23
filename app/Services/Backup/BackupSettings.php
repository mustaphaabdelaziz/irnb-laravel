<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Settings;

/**
 * Backup configuration, stored in Electron's userData (electron-store) rather
 * than in the database.
 *
 * This is deliberate and load-bearing: config that describes how to recover the
 * database must not live inside the database. If it did, restoring a
 * three-month-old backup would silently roll back the destination folder, the
 * frequency, and last_run_at — and the stale last_run_at would immediately
 * trigger another backup.
 *
 * This is the only class that touches the NativePHP Settings facade, so tests
 * only have to fake one seam (Settings::swap).
 */
class BackupSettings
{
    public const KEY = 'backup';

    public const FREQUENCIES = ['every_launch', 'daily', 'weekly'];

    /** The outcomes BackupService::restore() can end in. See recordRestore(). */
    public const OUTCOMES = ['success', 'failed_after_swap', 'failed'];

    private const FIELDS = ['enabled', 'destination', 'frequency', 'retention', 'last_run_at', 'last_restore'];

    /**
     * @return array{enabled: bool, destination: ?string, frequency: string, retention: int, last_run_at: ?string, last_restore: ?array{outcome: string, message: string, snapshot: ?string, leftover_media: ?string, at: ?string}}
     */
    public function all(): array
    {
        $stored = Settings::get(self::KEY, []);

        if (! is_array($stored)) {
            $stored = [];
        }

        $frequency = $stored['frequency'] ?? null;

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'destination' => ($stored['destination'] ?? null) ?: null,
            'frequency' => in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'daily',
            'retention' => max(0, (int) ($stored['retention'] ?? 10)),
            'last_run_at' => $stored['last_run_at'] ?? null,
            'last_restore' => $this->normalizeRestore($stored['last_restore'] ?? null),
        ];
    }

    public function put(array $values): array
    {
        $merged = array_merge(
            $this->all(),
            array_intersect_key($values, array_flip(self::FIELDS)),
        );

        Settings::set(self::KEY, $merged);

        return $this->all();
    }

    public function destination(): ?string
    {
        return $this->all()['destination'];
    }

    /**
     * A removable drive can be unplugged between launches.
     *
     * Pass an already-read all() to check it without paying for another Settings::get()
     * round-trip to Electron — is_dir()/is_writable() on an unplugged USB stick or an
     * offline network share can block for seconds, so callers that need this alongside
     * the settings themselves should read once and probe once.
     *
     * @param  array{destination: ?string}|null  $settings
     */
    public function destinationIsWritable(?array $settings = null): bool
    {
        $destination = ($settings ?? $this->all())['destination'] ?? null;

        return $destination !== null && is_dir($destination) && is_writable($destination);
    }

    public function markRun(): void
    {
        $this->put(['last_run_at' => Carbon::now()->toIso8601String()]);
    }

    /**
     * Records how the last restore ended, OUTSIDE the database and outside the session.
     *
     * Both of those are the wrong place, and for the same reason: a restore ends in
     * App::relaunch(). Electron tears the window and the PHP child process down, while
     * Laravel only writes the session — and with it the flash bag — in
     * StartSession::terminate(), i.e. AFTER the response has been sent. So a flashed
     * restore outcome races the relaunch it just fired and is usually lost, and the two
     * messages that matter most are precisely the ones most likely to disappear: the
     * post-swap failure whose entire job is to name the pre-restore snapshot, and the
     * success that names a leftover folder holding a full duplicate of every photo.
     *
     * The database is worse still — a restore swaps it out from underneath the running
     * app, so a row written here would land in a file that is about to be replaced.
     * electron-store is neither, which is exactly why the rest of this class lives there.
     *
     * BackupController::index() hands this to the page, which renders it as a persistent,
     * dismissible banner (clearRestore()) rather than a 4-second toast — a Windows path
     * is not something a user can read, let alone copy, before it fades.
     *
     * @param  string  $outcome  one of self::OUTCOMES
     * @param  string|null  $snapshot  the pre-restore snapshot, when the failure happened past the swap
     * @param  string|null  $leftoverMedia  the superseded media folder, when it could not be removed
     */
    public function recordRestore(string $outcome, string $message, ?string $snapshot = null, ?string $leftoverMedia = null): void
    {
        $this->put(['last_restore' => [
            'outcome' => in_array($outcome, self::OUTCOMES, true) ? $outcome : 'failed',
            'message' => $message,
            'snapshot' => $snapshot,
            'leftover_media' => $leftoverMedia,
            'at' => Carbon::now()->toIso8601String(),
        ]]);
    }

    /** @return array{outcome: string, message: string, snapshot: ?string, leftover_media: ?string, at: ?string}|null */
    public function lastRestore(): ?array
    {
        return $this->all()['last_restore'];
    }

    /** The user has read the banner and dismissed it. */
    public function clearRestore(): void
    {
        $this->put(['last_restore' => null]);
    }

    /** @param  string  $trigger  'launch' or 'heartbeat' */
    public function isDue(string $trigger): bool
    {
        $settings = $this->all();

        if (! $settings['enabled'] || ! $this->destinationIsWritable($settings)) {
            return false;
        }

        $last = $settings['last_run_at'] ? Carbon::parse($settings['last_run_at']) : null;

        return match ($settings['frequency']) {
            'every_launch' => $trigger === 'launch',
            'weekly' => $last === null || $last->lte(Carbon::now()->subWeek()),
            default => $last === null || $last->lte(Carbon::now()->subDay()),
        };
    }

    /**
     * electron-store is a JSON file on the user's disk: it can hold anything, including
     * an entry written by an older version of this app. An unrecognised outcome is
     * treated as "no banner" rather than handed to the page as a broken one.
     *
     * @return array{outcome: string, message: string, snapshot: ?string, leftover_media: ?string, at: ?string}|null
     */
    private function normalizeRestore(mixed $stored): ?array
    {
        if (! is_array($stored) || ! in_array($stored['outcome'] ?? null, self::OUTCOMES, true)) {
            return null;
        }

        return [
            'outcome' => (string) $stored['outcome'],
            'message' => (string) ($stored['message'] ?? ''),
            'snapshot' => ($stored['snapshot'] ?? null) ?: null,
            'leftover_media' => ($stored['leftover_media'] ?? null) ?: null,
            'at' => ($stored['at'] ?? null) ?: null,
        ];
    }
}
