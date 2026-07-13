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

    private const FIELDS = ['enabled', 'destination', 'frequency', 'retention', 'last_run_at'];

    /**
     * @return array{enabled: bool, destination: ?string, frequency: string, retention: int, last_run_at: ?string}
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

    /** A removable drive can be unplugged between launches. */
    public function destinationIsWritable(): bool
    {
        $destination = $this->destination();

        return $destination !== null && is_dir($destination) && is_writable($destination);
    }

    public function markRun(): void
    {
        $this->put(['last_run_at' => Carbon::now()->toIso8601String()]);
    }

    /** @param  string  $trigger  'launch' or 'heartbeat' */
    public function isDue(string $trigger): bool
    {
        $settings = $this->all();

        if (! $settings['enabled'] || ! $this->destinationIsWritable()) {
            return false;
        }

        $last = $settings['last_run_at'] ? Carbon::parse($settings['last_run_at']) : null;

        return match ($settings['frequency']) {
            'every_launch' => $trigger === 'launch',
            'weekly' => $last === null || $last->lte(Carbon::now()->subWeek()),
            default => $last === null || $last->lte(Carbon::now()->subDay()),
        };
    }
}
