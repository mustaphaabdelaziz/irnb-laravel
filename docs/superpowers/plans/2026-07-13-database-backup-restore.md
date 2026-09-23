# Database Backup & Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the SPORT_CLUB desktop app an in-app Backup page: back up the database and uploaded media to a folder the user picks, automatically on a schedule, and restore from any backup with an undo path.

**Architecture:** Four units. `BackupSettings` is the only class that touches the NativePHP `Settings` facade (config is stored in Electron's userData, *outside* the database, so restoring an old backup cannot roll back the backup configuration itself). `BackupService` does all filesystem and zip work with no HTTP and no facades. `CreateBackup` is a queued job used only by the automatic path. `BackupController` exposes both to Inertia. The page is desktop-only and superadmin-only.

**Tech Stack:** Laravel 13, Inertia + Vue 3, PHPUnit 12 (attribute style, `Tests\TestCase`), NativePHP `nativephp/desktop` v2 (`Native\Desktop\*`), SQLite, `ZipArchive`.

## Global Constraints

- **Namespace is `Native\Desktop\...`**, NOT `Native\Laravel\...`. This project uses `nativephp/desktop` v2.
- **The DB snapshot MUST be taken with `VACUUM INTO ?`** (verified working on the bundled PHP: SQLite 3.45.2, WAL-safe, atomic). Never copy `database.sqlite` + `-wal` + `-shm` by hand.
- **Restore is never queued.** The queue tables live inside the database being replaced.
- **No RBAC changes.** `PermissionMap::resolve()` returns `null` for unmapped route names and `EnsurePermission` passes those through. `backups.*` is unmapped by design. Do NOT add it to `Role::MODULES` or `config/permissions.php`.
- **Backup config must never be stored in the database.** See Architecture above.
- **Tests:** PHPUnit 12 with `#[Test]` attributes, extending `Tests\TestCase`. Run with `php artisan config:clear` first (the project's `TestCase` aborts if a cached config exists). Use `composer test`, which clears it.
- **PHP/artisan/npm are on PATH in PowerShell, not in the Bash tool.** Run commands through PowerShell.
- Every user-facing string goes through i18n (`t('key')` in Vue, `__('key')` in PHP) with entries added to **all three** of `resources/js/i18n/{en,fr,ar}.json`.
- Manifest identity is `config('app.name')` (`SPORT_CLUB`), **not** `config('nativephp.app_id')` — `NATIVEPHP_APP_ID` is not reliably present in the packaged runtime's env, but `APP_NAME` is bundled in `.env`.

---

### Task 1: `BackupSettings` — configuration that lives outside the database

**Files:**
- Create: `app/Services/Backup/BackupSettings.php`
- Create: `tests/Support/FakeNativeSettings.php`
- Test: `tests/Unit/Services/BackupSettingsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `BackupSettings::FREQUENCIES = ['every_launch', 'daily', 'weekly']`
  - `all(): array` → `['enabled' => bool, 'destination' => ?string, 'frequency' => string, 'retention' => int, 'last_run_at' => ?string]`
  - `put(array $values): array` (returns the merged, normalized settings)
  - `destination(): ?string`
  - `destinationIsWritable(): bool`
  - `markRun(): void`
  - `isDue(string $trigger): bool` where `$trigger` is `'launch'` or `'heartbeat'`

- [ ] **Step 1: Write the test double for the NativePHP Settings store**

`Native\Desktop\Settings` talks to Electron over HTTP, so it cannot run in tests. It is resolved from the container by the `Settings` facade, which means `Settings::swap()` replaces it cleanly. There is no `SettingsFake` shipped in the package, so write one.

Create `tests/Support/FakeNativeSettings.php`:

```php
<?php

namespace Tests\Support;

/**
 * Stand-in for Native\Desktop\Settings (electron-store). The real one posts to
 * the Electron client over HTTP, which does not exist under test.
 * Swap it in with: Settings::swap(new FakeNativeSettings);
 */
class FakeNativeSettings
{
    public array $store = [];

    public function set(string $key, $value): void
    {
        $this->store[$key] = $value;
    }

    public function get(string $key, $default = null): mixed
    {
        $value = $this->store[$key] ?? null;

        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }

        return $value;
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Services/BackupSettingsTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupSettings;
use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    private BackupSettings $settings;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-dest-'.uniqid();
        mkdir($this->dir, 0777, true);

        $this->settings = new BackupSettings;
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function it_returns_sane_defaults_when_nothing_is_stored(): void
    {
        $this->assertSame([
            'enabled' => false,
            'destination' => null,
            'frequency' => 'daily',
            'retention' => 10,
            'last_run_at' => null,
        ], $this->settings->all());
    }

    #[Test]
    public function it_merges_partial_updates_and_normalizes_values(): void
    {
        $this->settings->put(['enabled' => true, 'retention' => 3]);
        $this->settings->put(['destination' => $this->dir]);

        $all = $this->settings->all();

        $this->assertTrue($all['enabled']);
        $this->assertSame(3, $all['retention']);
        $this->assertSame($this->dir, $all['destination']);
        $this->assertSame('daily', $all['frequency']);
    }

    #[Test]
    public function it_rejects_an_unknown_frequency_and_a_negative_retention(): void
    {
        $this->settings->put(['frequency' => 'hourly', 'retention' => -5]);

        $all = $this->settings->all();

        $this->assertSame('daily', $all['frequency']);
        $this->assertSame(0, $all['retention']);
    }

    #[Test]
    public function it_is_never_due_when_disabled_or_without_a_writable_destination(): void
    {
        $this->settings->put(['enabled' => false, 'destination' => $this->dir]);
        $this->assertFalse($this->settings->isDue('launch'));

        $this->settings->put(['enabled' => true, 'destination' => null]);
        $this->assertFalse($this->settings->isDue('launch'));

        $this->settings->put(['enabled' => true, 'destination' => $this->dir.'-gone']);
        $this->assertFalse($this->settings->isDue('launch'));
    }

    #[Test]
    public function every_launch_is_due_on_launch_but_not_on_a_heartbeat(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'every_launch',
        ]);

        $this->assertTrue($this->settings->isDue('launch'));
        $this->assertFalse($this->settings->isDue('heartbeat'));
    }

    #[Test]
    public function daily_is_due_once_a_day_regardless_of_trigger(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'daily',
        ]);

        // Never run before -> due.
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-13 10:00:00');
        $this->settings->markRun();

        Carbon::setTestNow('2026-07-13 20:00:00');
        $this->assertFalse($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-14 10:01:00');
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow();
    }

    #[Test]
    public function weekly_is_due_once_a_week(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'weekly',
        ]);

        Carbon::setTestNow('2026-07-13 10:00:00');
        $this->settings->markRun();

        Carbon::setTestNow('2026-07-18 10:00:00');
        $this->assertFalse($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-20 10:01:00');
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run (PowerShell): `php artisan config:clear; php artisan test --filter=BackupSettingsTest`
Expected: FAIL — `Class "App\Services\Backup\BackupSettings" not found`.

- [ ] **Step 4: Write the implementation**

Create `app/Services/Backup/BackupSettings.php`:

```php
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
            'destination' => $stored['destination'] ?: null,
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=BackupSettingsTest`
Expected: PASS — 7 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Backup/BackupSettings.php tests/Support/FakeNativeSettings.php tests/Unit/Services/BackupSettingsTest.php
git commit -m "feat: backup settings stored outside the database"
```

---

### Task 2: `BackupService` — create, list, prune

**Files:**
- Create: `app/Services/Backup/BackupService.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind the service with runtime paths)
- Test: `tests/Unit/Services/BackupServiceTest.php`

**Interfaces:**
- Consumes: `BackupSettings::all()`, `destination()`, `markRun()` from Task 1.
- Produces:
  - `BackupService::PREFIX = 'sport-club-backup-'`
  - `BackupService::SNAPSHOT_DIR = 'pre-restore'`
  - `__construct(BackupSettings $settings, string $databasePath, string $mediaPath)`
  - `create(): string` — returns the zip path
  - `snapshot(): string` — returns the pre-restore zip path (Task 3 uses this)
  - `list(): array` — newest first; each entry `['name' => string, 'path' => string, 'bytes' => int, 'created_at' => string]`
  - `prune(): int`
  - `resolve(string $name): string` — filename → absolute path, rejects traversal
  - `schemaSignature(): string`
  - `restore(string $zipPath): void` — **stubbed in this task, implemented in Task 3**

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BackupServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    private string $root;

    private string $dbPath;

    private string $mediaPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-svc-'.uniqid();
        $this->dbPath = $this->root.'/data/database.sqlite';
        $this->mediaPath = $this->root.'/media';
        $this->destination = $this->root.'/dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath.'/logos', 0777, true);
        mkdir($this->destination, 0777, true);

        // A realistic source DB: WAL mode, a migrations table, and a data table.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec("INSERT INTO migrations (migration) VALUES ('0001_create_users')");
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");

        file_put_contents($this->mediaPath.'/logos/club.png', 'PNG-BYTES');

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->settings, $this->dbPath, $this->mediaPath);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    #[Test]
    public function create_writes_a_zip_holding_the_database_the_media_and_a_manifest(): void
    {
        $path = $this->service()->create();

        $this->assertFileExists($path);
        $this->assertStringStartsWith(BackupService::PREFIX, basename($path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $this->assertNotFalse($zip->locateName('database.sqlite'));
        $this->assertNotFalse($zip->locateName('media/logos/club.png'));
        $this->assertSame('PNG-BYTES', $zip->getFromName('media/logos/club.png'));

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(config('app.name'), $manifest['app']);
        $this->assertSame(1, $manifest['media_files']);
        $this->assertSame($this->service()->schemaSignature(), $manifest['schema_signature']);
        $this->assertGreaterThan(0, $manifest['db_bytes']);
    }

    #[Test]
    public function the_snapshotted_database_is_consistent_and_readable(): void
    {
        $path = $this->service()->create();

        $extracted = $this->root.'/extracted';
        mkdir($extracted, 0777, true);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->extractTo($extracted);
        $zip->close();

        $pdo = new \PDO('sqlite:'.$extracted.'/database.sqlite');

        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('Ali', $pdo->query('SELECT name FROM players')->fetchColumn());
    }

    #[Test]
    public function create_stamps_last_run_at(): void
    {
        $this->assertNull($this->settings->all()['last_run_at']);

        $this->service()->create();

        $this->assertNotNull($this->settings->all()['last_run_at']);
    }

    #[Test]
    public function create_fails_loudly_when_no_destination_is_set(): void
    {
        $this->settings->put(['destination' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->create();
    }

    #[Test]
    public function list_returns_backups_newest_first_and_ignores_the_pre_restore_folder(): void
    {
        $service = $this->service();

        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip', 'a');
        file_put_contents($this->destination.'/'.BackupService::PREFIX.'2026-07-03_100000.zip', 'b');
        file_put_contents($this->destination.'/unrelated.zip', 'c');
        mkdir($this->destination.'/'.BackupService::SNAPSHOT_DIR, 0777, true);
        file_put_contents($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/pre-restore-2026-07-02_100000.zip', 'd');

        $names = array_column($service->list(), 'name');

        $this->assertSame([
            BackupService::PREFIX.'2026-07-03_100000.zip',
            BackupService::PREFIX.'2026-07-01_100000.zip',
        ], $names);
    }

    #[Test]
    public function prune_keeps_only_the_newest_n_and_never_touches_the_snapshot_folder(): void
    {
        $this->settings->put(['retention' => 2]);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $day) {
            file_put_contents($this->destination.'/'.BackupService::PREFIX.$day.'_100000.zip', 'x');
        }

        $snapshotDir = $this->destination.'/'.BackupService::SNAPSHOT_DIR;
        mkdir($snapshotDir, 0777, true);
        file_put_contents($snapshotDir.'/pre-restore-2026-07-01_090000.zip', 'keep-me');

        $deleted = $this->service()->prune();

        $this->assertSame(2, $deleted);
        $this->assertCount(2, $this->service()->list());
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-04_100000.zip');
        $this->assertFileExists($this->destination.'/'.BackupService::PREFIX.'2026-07-03_100000.zip');
        $this->assertFileDoesNotExist($this->destination.'/'.BackupService::PREFIX.'2026-07-01_100000.zip');
        $this->assertFileExists($snapshotDir.'/pre-restore-2026-07-01_090000.zip');
    }

    #[Test]
    public function a_retention_of_zero_keeps_everything(): void
    {
        $this->settings->put(['retention' => 0]);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $day) {
            file_put_contents($this->destination.'/'.BackupService::PREFIX.$day.'_100000.zip', 'x');
        }

        $this->assertSame(0, $this->service()->prune());
        $this->assertCount(3, $this->service()->list());
    }

    #[Test]
    public function resolve_rejects_path_traversal_and_foreign_names(): void
    {
        $service = $this->service();

        foreach (['../../evil.zip', 'evil.zip', BackupService::PREFIX.'x.txt'] as $name) {
            try {
                $service->resolve($name);
                $this->fail("Expected {$name} to be rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function resolve_returns_the_absolute_path_of_a_real_backup(): void
    {
        $name = BackupService::PREFIX.'2026-07-01_100000.zip';
        file_put_contents($this->destination.'/'.$name, 'x');

        $this->assertSame(
            $this->destination.DIRECTORY_SEPARATOR.$name,
            $this->service()->resolve($name),
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BackupServiceTest`
Expected: FAIL — `Class "App\Services\Backup\BackupService" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Backup/BackupService.php`. (`restore()` is a stub here; Task 3 fills it in.)

```php
<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Creates and restores full backups: the SQLite database plus the uploaded media
 * tree, in a single zip.
 *
 * Pure filesystem + zip. No HTTP, no Inertia, and no facades other than through
 * BackupSettings — so it is fully testable against temp directories.
 */
class BackupService
{
    public const PREFIX = 'sport-club-backup-';

    public const SNAPSHOT_DIR = 'pre-restore';

    public function __construct(
        private BackupSettings $settings,
        private string $databasePath,
        private string $mediaPath,
    ) {}

    /** Writes a backup to the destination, prunes old ones, returns the new path. */
    public function create(): string
    {
        $path = $this->requireDestination()
            .DIRECTORY_SEPARATOR.self::PREFIX.$this->timestamp().'.zip';

        $this->writeArchive($path);

        $this->settings->markRun();
        $this->prune();

        return $path;
    }

    /**
     * Writes an undo point before a restore. Lives in its own subfolder and is
     * never pruned — it is the only thing standing between a mis-clicked restore
     * and permanent data loss.
     */
    public function snapshot(): string
    {
        $dir = $this->requireDestination().DIRECTORY_SEPARATOR.self::SNAPSHOT_DIR;

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the snapshot folder: {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.'pre-restore-'.$this->timestamp().'.zip';

        $this->writeArchive($path);

        return $path;
    }

    /** @return array<int, array{name: string, path: string, bytes: int, created_at: string}> */
    public function list(): array
    {
        $destination = $this->settings->destination();

        if (! $destination || ! is_dir($destination)) {
            return [];
        }

        // glob() does not descend, so the pre-restore/ subfolder is excluded for free.
        $files = glob($destination.DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];

        $backups = array_map(fn (string $file) => [
            'name' => basename($file),
            'path' => $file,
            'bytes' => filesize($file) ?: 0,
            'created_at' => Carbon::createFromTimestamp(filemtime($file))->toIso8601String(),
        ], $files);

        // The timestamp is in the filename in Y-m-d_His form, so lexical sort == chronological.
        usort($backups, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /** Enforces the retention setting. Returns how many files were deleted. */
    public function prune(): int
    {
        $retention = $this->settings->all()['retention'];

        if ($retention <= 0) {
            return 0;
        }

        $deleted = 0;

        foreach (array_slice($this->list(), $retention) as $backup) {
            if (@unlink($backup['path'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Turns a bare filename from the UI into an absolute path inside the destination. */
    public function resolve(string $name): string
    {
        $destination = $this->requireDestination();

        if (basename($name) !== $name
            || ! str_starts_with($name, self::PREFIX)
            || ! str_ends_with($name, '.zip')) {
            throw new RuntimeException('That is not a valid backup file name.');
        }

        $path = $destination.DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new RuntimeException('That backup no longer exists.');
        }

        return $path;
    }

    public function restore(string $zipPath): void
    {
        throw new RuntimeException('Not implemented yet.');
    }

    /**
     * Identifies the schema the backup was taken against. Same value
     * NativeAppServiceProvider::firstRunSetup() uses to decide whether to migrate.
     */
    public function schemaSignature(): string
    {
        $files = glob(base_path('database/migrations/*.php')) ?: [];
        sort($files);

        return md5(implode('|', array_map('basename', $files)));
    }

    private function requireDestination(): string
    {
        $destination = $this->settings->destination();

        if (! $destination) {
            throw new RuntimeException('No backup folder has been chosen yet.');
        }

        if (! is_dir($destination) || ! is_writable($destination)) {
            throw new RuntimeException("The backup folder is not available: {$destination}");
        }

        return $destination;
    }

    private function writeArchive(string $target): void
    {
        $tempDb = $this->tempPath('db-'.$this->timestamp().'.sqlite');

        $this->vacuumInto($tempDb);

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempDb);

            throw new RuntimeException("Could not write the backup file: {$target}");
        }

        try {
            $zip->addFile($tempDb, 'database.sqlite');

            $mediaFiles = 0;

            foreach ($this->mediaFiles() as $absolute => $relative) {
                $zip->addFile($absolute, 'media/'.$relative);
                $mediaFiles++;
            }

            $zip->addFromString('manifest.json', json_encode([
                'app' => config('app.name'),
                'app_id' => config('nativephp.app_id'),
                'app_version' => config('nativephp.version'),
                'created_at' => Carbon::now()->toIso8601String(),
                'schema_signature' => $this->schemaSignature(),
                'db_bytes' => filesize($tempDb) ?: 0,
                'media_files' => $mediaFiles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException("Could not finish writing the backup file: {$target}");
            }
        } catch (\Throwable $e) {
            // Never leave a half-written zip behind — it would look like a valid backup.
            @$zip->close();
            @unlink($target);

            throw $e;
        } finally {
            @unlink($tempDb);
        }
    }

    /**
     * Snapshots the live database through SQLite itself. VACUUM INTO is atomic and
     * WAL-safe: it needs no write lock and produces a single consistent file, so
     * there is no torn copy and no -wal/-shm to reconcile.
     */
    private function vacuumInto(string $target): void
    {
        @unlink($target);

        $pdo = new \PDO('sqlite:'.$this->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->prepare('VACUUM INTO ?')->execute([$target]);
    }

    /** @return iterable<string, string> absolute path => path relative to the media root */
    private function mediaFiles(): iterable
    {
        if (! is_dir($this->mediaPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->mediaPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->mediaPath) + 1));

            yield $file->getPathname() => $relative;
        }
    }

    private function tempPath(string $name): string
    {
        $dir = storage_path('app/backup-tmp');

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the working folder: {$dir}");
        }

        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    private function timestamp(): string
    {
        return Carbon::now()->format('Y-m-d_His');
    }
}
```

- [ ] **Step 4: Bind the service with the runtime paths**

The service must not guess where the DB and media live — on desktop they are redirected into Electron's userData. Bind it once, centrally.

In `app/Providers/AppServiceProvider.php`, add these imports at the top:

```php
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
```

and add this to the `register()` method:

```php
        // On desktop the DB lives in Electron's userData, not in database/.
        $this->app->singleton(BackupService::class, fn ($app) => new BackupService(
            $app->make(BackupSettings::class),
            config('nativephp-internal.database_path') ?: database_path('database.sqlite'),
            storage_path('app/public'),
        ));
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=BackupServiceTest`
Expected: PASS — 8 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Backup/BackupService.php app/Providers/AppServiceProvider.php tests/Unit/Services/BackupServiceTest.php
git commit -m "feat: create, list and prune full backups (db + media)"
```

---

### Task 3: `BackupService::restore()` — validate, snapshot, swap, self-heal

**Files:**
- Modify: `app/Services/Backup/BackupService.php` (replace the `restore()` stub, add private helpers)
- Test: `tests/Unit/Services/BackupRestoreTest.php`

**Interfaces:**
- Consumes: `create()`, `snapshot()`, `resolve()` from Task 2.
- Produces: `restore(string $zipPath): void`. Throws `RuntimeException` on every failure. Deletes the `.migrated` marker next to the database on success.

Order matters and is the whole point of this task: **everything that can fail is done before anything is written.** A backup from another app, or one with a corrupt database inside it, is rejected while the live data is still untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BackupRestoreTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;
use ZipArchive;

class BackupRestoreTest extends TestCase
{
    private string $root;

    private string $dbPath;

    private string $mediaPath;

    private string $destination;

    private BackupSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-restore-'.uniqid();
        $this->dbPath = $this->root.'/data/database.sqlite';
        $this->mediaPath = $this->root.'/media';
        $this->destination = $this->root.'/dest';

        mkdir(dirname($this->dbPath), 0777, true);
        mkdir($this->mediaPath, 0777, true);
        mkdir($this->destination, 0777, true);

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $pdo->exec('CREATE TABLE players (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO players (name) VALUES ('Ali')");

        file_put_contents($this->mediaPath.'/photo.jpg', 'ORIGINAL');

        $this->settings = new BackupSettings;
        $this->settings->put(['destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->settings, $this->dbPath, $this->mediaPath);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function players(): array
    {
        $pdo = new \PDO('sqlite:'.$this->dbPath);

        return $pdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    #[Test]
    public function it_restores_both_the_database_and_the_media(): void
    {
        $backup = $this->service()->create();

        // Diverge from the backup: add a row, overwrite the photo.
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");
        file_put_contents($this->mediaPath.'/photo.jpg', 'CHANGED');

        $this->assertSame(['Ali', 'Omar'], $this->players());

        $this->service()->restore($backup);

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.'/photo.jpg'));
    }

    #[Test]
    public function it_writes_a_pre_restore_snapshot_before_overwriting_anything(): void
    {
        $backup = $this->service()->create();

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->exec("INSERT INTO players (name) VALUES ('Omar')");

        $this->service()->restore($backup);

        $snapshots = glob($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/pre-restore-*.zip');
        $this->assertCount(1, $snapshots);

        // The snapshot holds the data as it was *just before* the restore — Omar included.
        $extracted = $this->root.'/snap';
        mkdir($extracted, 0777, true);

        $zip = new ZipArchive;
        $zip->open($snapshots[0]);
        $zip->extractTo($extracted);
        $zip->close();

        $snapshotPdo = new \PDO('sqlite:'.$extracted.'/database.sqlite');
        $names = $snapshotPdo->query('SELECT name FROM players ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['Ali', 'Omar'], $names);
    }

    #[Test]
    public function it_deletes_the_migrated_marker_so_the_next_launch_re_migrates(): void
    {
        $marker = dirname($this->dbPath).DIRECTORY_SEPARATOR.'.migrated';
        file_put_contents($marker, 'old-signature');

        $backup = $this->service()->create();

        $this->service()->restore($backup);

        $this->assertFileDoesNotExist($marker);
    }

    #[Test]
    public function it_rejects_a_backup_belonging_to_another_application(): void
    {
        $foreign = $this->destination.'/'.BackupService::PREFIX.'2026-01-01_000000.zip';

        $zip = new ZipArchive;
        $zip->open($foreign, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => 'SomeOtherApp']));
        $zip->addFromString('database.sqlite', 'irrelevant');
        $zip->close();

        try {
            $this->service()->restore($foreign);
            $this->fail('Expected a foreign backup to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different application', $e->getMessage());
        }

        // Untouched.
        $this->assertSame(['Ali'], $this->players());
        $this->assertSame([], glob($this->destination.'/'.BackupService::SNAPSHOT_DIR.'/*.zip') ?: []);
    }

    #[Test]
    public function it_rejects_a_backup_whose_database_is_corrupt_without_touching_the_live_data(): void
    {
        $corrupt = $this->destination.'/'.BackupService::PREFIX.'2026-01-02_000000.zip';

        $zip = new ZipArchive;
        $zip->open($corrupt, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->addFromString('database.sqlite', 'this is definitely not a sqlite file');
        $zip->close();

        try {
            $this->service()->restore($corrupt);
            $this->fail('Expected a corrupt backup to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }

        $this->assertSame(['Ali'], $this->players());
        $this->assertSame('ORIGINAL', file_get_contents($this->mediaPath.'/photo.jpg'));
    }

    #[Test]
    public function it_rejects_a_zip_that_is_missing_the_database(): void
    {
        $incomplete = $this->destination.'/'.BackupService::PREFIX.'2026-01-03_000000.zip';

        $zip = new ZipArchive;
        $zip->open($incomplete, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['app' => config('app.name')]));
        $zip->close();

        $this->expectException(RuntimeException::class);

        $this->service()->restore($incomplete);
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_a_zip(): void
    {
        $junk = $this->destination.'/'.BackupService::PREFIX.'2026-01-04_000000.zip';
        file_put_contents($junk, 'not a zip at all');

        $this->expectException(RuntimeException::class);

        $this->service()->restore($junk);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BackupRestoreTest`
Expected: FAIL — `Not implemented yet.`

- [ ] **Step 3: Implement `restore()`**

In `app/Services/Backup/BackupService.php`, add the import:

```php
use Illuminate\Support\Facades\DB;
```

Replace the `restore()` stub with:

```php
    /**
     * Replaces the live database and media with the contents of a backup.
     *
     * Ordering is the whole safety story: validate, verify, and snapshot BEFORE
     * writing anything. Steps 1-3 are non-destructive — a foreign or corrupt
     * backup is rejected with the live data still intact. From step 4 on, the
     * pre-restore snapshot is the undo path, and its location is surfaced in any
     * error message.
     *
     * Never queue this: the queue tables live inside the database being replaced.
     */
    public function restore(string $zipPath): void
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('That backup file could not be found.');
        }

        // 1. Open and identify.
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That file is not a readable backup archive.');
        }

        $manifestRaw = $zip->getFromName('manifest.json');

        if ($manifestRaw === false || $zip->locateName('database.sqlite') === false) {
            $zip->close();

            throw new RuntimeException('That archive is not a backup — it has no manifest or database inside.');
        }

        $manifest = json_decode($manifestRaw, true);

        if (! is_array($manifest) || ($manifest['app'] ?? null) !== config('app.name')) {
            $zip->close();

            throw new RuntimeException('That backup belongs to a different application.');
        }

        // 2. Unpack to a staging area and verify the database before trusting it.
        $staging = $this->tempPath('restore-'.$this->timestamp());
        $this->deleteDirectory($staging);

        if (! @mkdir($staging, 0777, true) && ! is_dir($staging)) {
            $zip->close();

            throw new RuntimeException("Could not create the working folder: {$staging}");
        }

        $extracted = $zip->extractTo($staging);
        $zip->close();

        if (! $extracted) {
            $this->deleteDirectory($staging);

            throw new RuntimeException('The backup archive could not be unpacked.');
        }

        $stagedDb = $staging.DIRECTORY_SEPARATOR.'database.sqlite';

        try {
            $this->assertHealthyDatabase($stagedDb);
        } catch (\Throwable $e) {
            $this->deleteDirectory($staging);

            throw $e;
        }

        // 3. Undo point. Still non-destructive.
        $snapshot = $this->snapshot();

        // 4. Past here, the snapshot is the only way back.
        try {
            DB::disconnect();

            @unlink($this->databasePath.'-wal');
            @unlink($this->databasePath.'-shm');

            if (! @copy($stagedDb, $this->databasePath)) {
                throw new RuntimeException('Could not write the restored database into place.');
            }

            $stagedMedia = $staging.DIRECTORY_SEPARATOR.'media';

            if (is_dir($stagedMedia)) {
                $retired = $this->mediaPath.'.old-'.$this->timestamp();

                if (is_dir($this->mediaPath) && ! @rename($this->mediaPath, $retired)) {
                    throw new RuntimeException('Could not move the current media folder aside.');
                }

                if (! @rename($stagedMedia, $this->mediaPath)) {
                    // Put the originals back before giving up.
                    @rename($retired, $this->mediaPath);

                    throw new RuntimeException('Could not write the restored media into place.');
                }

                $this->deleteDirectory($retired);
            }

            // Force NativeAppServiceProvider::firstRunSetup() to re-run migrate on the
            // next launch. Without this, restoring a backup taken on an older schema
            // into a newer app leaves the DB missing tables the code expects.
            @unlink(dirname($this->databasePath).DIRECTORY_SEPARATOR.'.migrated');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'The restore failed partway through. Your previous data was saved first and is safe in: '.$snapshot,
                0,
                $e,
            );
        } finally {
            $this->deleteDirectory($staging);
        }
    }
```

Then add these private helpers to the same class:

```php
    /** Rejects a database that SQLite cannot read, or that is not one of ours. */
    private function assertHealthyDatabase(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException('That backup does not contain a database.');
        }

        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('integrity_check failed');
            }

            $hasMigrations = (int) $pdo
                ->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")
                ->fetchColumn();

            if ($hasMigrations === 0) {
                throw new RuntimeException('no migrations table');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'The database inside that backup is corrupt or unreadable. Nothing was changed.',
                0,
                $e,
            );
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=BackupRestoreTest`
Expected: PASS — 7 tests.

- [ ] **Step 5: Run the whole backup suite**

Run: `php artisan test --filter=Backup`
Expected: PASS — 22 tests (7 settings + 8 service + 7 restore).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Backup/BackupService.php tests/Unit/Services/BackupRestoreTest.php
git commit -m "feat: restore a backup with validation and a pre-restore snapshot"
```

---

### Task 4: `EnsureDesktop` middleware — the feature does not exist on the web build

**Files:**
- Create: `app/Http/Middleware/EnsureDesktop.php`
- Modify: `bootstrap/app.php:27-32` (add the `desktop` alias)

**Interfaces:**
- Produces: middleware alias `desktop`, which 404s when `config('nativephp-internal.running')` is falsy.

There is no native folder picker on the web, and server installs back up the way servers do. A half-working web path is worse than none, so the routes simply do not exist there.

- [ ] **Step 1: Write the middleware**

Create `app/Http/Middleware/EnsureDesktop.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the packaged desktop app.
 *
 * 404 rather than 403: on the web build these routes are not "forbidden", they
 * do not exist — there is no native folder picker and no local userData to back up.
 */
class EnsureDesktop
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('nativephp-internal.running'), 404);

        return $next($request);
    }
}
```

- [ ] **Step 2: Register the alias**

In `bootstrap/app.php`, add the import next to the others:

```php
use App\Http\Middleware\EnsureDesktop;
```

and add the alias to the `$middleware->alias([...])` array:

```php
            'desktop' => EnsureDesktop::class,
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/EnsureDesktop.php bootstrap/app.php
git commit -m "feat: add the desktop-only route middleware"
```

---

### Task 5: `CreateBackup` job, `BackupController` and routes

**Files:**
- Create: `app/Jobs/CreateBackup.php`
- Create: `app/Http/Controllers/BackupController.php`
- Modify: `routes/web.php` (import the controller; add the route group next to the superadmin `roles` group at `:223-228`)
- Test: `tests/Feature/BackupPageTest.php`

**Interfaces:**
- Consumes: `BackupService` (Task 2/3), `BackupSettings` (Task 1), the `desktop` alias (Task 4).
- Produces these route names, all under `middleware(['desktop', 'superadmin'])`:
  - `backups.index` — `GET /backups`
  - `backups.store` — `POST /backups` (manual backup, synchronous)
  - `backups.tick` — `POST /backups/tick` (heartbeat, JSON)
  - `backups.settings` — `PUT /backups/settings`
  - `backups.folder` — `POST /backups/folder` (native folder picker)
  - `backups.restore` — `POST /backups/restore`
  - `backups.reveal` — `POST /backups/reveal`
  - `backups.destroy` — `DELETE /backups/{name}`
- Produces the Inertia page props for `Backups/Index`: `settings`, `destinationWritable`, `backups`.

Manual backup runs **synchronously** (the user is watching and wants the error message). Automatic backup goes through the **queued** job so it never blocks a request — `QUEUE_CONNECTION=database` and NativePHP already auto-starts a worker that currently has no jobs to process. A `Cache::lock` serializes backup against restore; the file cache store implements `LockProvider`, so this works as configured.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\User;
use App\Services\Backup\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;

class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-feat-'.uniqid();
        mkdir($this->destination, 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->destination);

        parent::tearDown();
    }

    private function desktop(): void
    {
        config(['nativephp-internal.running' => true]);
    }

    // Mirrors tests/Feature/RoleManagementTest.php:15-18 — `privileges` is an array,
    // and the `verified` middleware needs email_verified_at.
    private function superadmin(): User
    {
        return User::factory()->create([
            'privileges' => ['superadmin'],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function member(): User
    {
        return User::factory()->create([
            'privileges' => [],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function the_backup_page_does_not_exist_on_the_web_build(): void
    {
        config(['nativephp-internal.running' => false]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertNotFound();
    }

    #[Test]
    public function a_non_superadmin_cannot_reach_the_backup_page_on_desktop(): void
    {
        $this->desktop();

        $this->actingAs($this->member())
            ->get('/backups')
            ->assertForbidden();
    }

    #[Test]
    public function a_superadmin_sees_the_backup_page_with_its_settings(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination,
            'frequency' => 'weekly',
            'retention' => 5,
        ]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Backups/Index')
                ->where('settings.enabled', true)
                ->where('settings.frequency', 'weekly')
                ->where('settings.retention', 5)
                ->where('destinationWritable', true)
                ->has('backups', 0));
    }

    #[Test]
    public function it_reports_an_unavailable_destination(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination.'-unplugged',
        ]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('destinationWritable', false));
    }

    #[Test]
    public function settings_can_be_updated(): void
    {
        $this->desktop();

        $this->actingAs($this->superadmin())
            ->put('/backups/settings', [
                'enabled' => true,
                'frequency' => 'weekly',
                'retention' => 4,
            ])
            ->assertRedirect();

        $settings = app(BackupSettings::class)->all();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('weekly', $settings['frequency']);
        $this->assertSame(4, $settings['retention']);
    }

    #[Test]
    public function settings_reject_an_unknown_frequency(): void
    {
        $this->desktop();

        $this->actingAs($this->superadmin())
            ->put('/backups/settings', [
                'enabled' => true,
                'frequency' => 'hourly',
                'retention' => 4,
            ])
            ->assertSessionHasErrors('frequency');
    }

    #[Test]
    public function the_heartbeat_dispatches_a_backup_only_when_one_is_due(): void
    {
        $this->desktop();
        Queue::fake();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination,
            'frequency' => 'every_launch',
        ]);

        $user = $this->superadmin();

        $this->actingAs($user)
            ->postJson('/backups/tick', ['trigger' => 'heartbeat'])
            ->assertOk()
            ->assertJson(['dispatched' => false]);

        Queue::assertNothingPushed();

        $this->actingAs($user)
            ->postJson('/backups/tick', ['trigger' => 'launch'])
            ->assertOk()
            ->assertJson(['dispatched' => true]);

        Queue::assertPushed(CreateBackup::class);
    }

    #[Test]
    public function the_heartbeat_does_nothing_when_automatic_backup_is_off(): void
    {
        $this->desktop();
        Queue::fake();

        app(BackupSettings::class)->put([
            'enabled' => false,
            'destination' => $this->destination,
        ]);

        $this->actingAs($this->superadmin())
            ->postJson('/backups/tick', ['trigger' => 'launch'])
            ->assertOk()
            ->assertJson(['dispatched' => false]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function restore_refuses_a_request_without_the_typed_confirmation(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);

        $this->actingAs($this->superadmin())
            ->from('/backups')
            ->post('/backups/restore', [
                'name' => 'sport-club-backup-2026-07-01_100000.zip',
                'confirmation' => 'yes please',
            ])
            ->assertSessionHasErrors('confirmation');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BackupPageTest`
Expected: FAIL — 404 on every route; `Class "App\Jobs\CreateBackup" not found`.

The `superadmin()` / `member()` helpers above already match this project's convention (`tests/Feature/RoleManagementTest.php:15-18`). Use them as written.

- [ ] **Step 3: Write the job**

Create `app/Jobs/CreateBackup.php`:

```php
<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The automatic backup path. Queued so a scheduled backup never blocks a request
 * — NativePHP already auto-starts a worker for the `default` queue.
 *
 * Manual backups do NOT go through this: the user is watching and wants the error.
 */
class CreateBackup implements ShouldQueue
{
    use Queueable;

    public function handle(BackupService $backups): void
    {
        // Skips silently if a manual backup or a restore is already running.
        Cache::lock('backup', 300)->get(function () use ($backups) {
            $backups->create();
        });
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/BackupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\CreateBackup;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Shell;

/**
 * Desktop-only, superadmin-only. Route names are deliberately unmapped in
 * config/permissions.php: PermissionMap::resolve() returns null for them and
 * EnsurePermission passes them through, so the `superadmin` alias is the only gate.
 */
class BackupController extends Controller
{
    public function index(BackupSettings $settings, BackupService $backups)
    {
        return Inertia::render('Backups/Index', [
            'settings' => $settings->all(),
            'destinationWritable' => $settings->destinationIsWritable(),
            'backups' => $settings->destinationIsWritable() ? $backups->list() : [],
        ]);
    }

    /** Manual backup. Synchronous on purpose — the user is waiting on the result. */
    public function store(BackupService $backups)
    {
        $lock = Cache::lock('backup', 300);

        if (! $lock->get()) {
            return back()->with('error', __('backup_busy'));
        }

        try {
            $backups->create();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        } finally {
            $lock->release();
        }

        return back()->with('success', __('backup_created'));
    }

    /**
     * Heartbeat from the layout: once on launch, then every 30 minutes while the
     * app stays open. There is no scheduler on desktop — the app only runs when
     * it is open — so this is how an automatic backup actually fires.
     */
    public function tick(Request $request, BackupSettings $settings)
    {
        $trigger = $request->string('trigger')->toString() === 'launch' ? 'launch' : 'heartbeat';

        if (! $settings->isDue($trigger)) {
            return response()->json(['dispatched' => false]);
        }

        CreateBackup::dispatch();

        return response()->json(['dispatched' => true]);
    }

    public function updateSettings(Request $request, BackupSettings $settings)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(BackupSettings::FREQUENCIES)],
            'retention' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $settings->put($data);

        return back()->with('success', __('saved'));
    }

    /** Opens the OS folder picker and stores whatever the user chose. */
    public function chooseFolder(BackupSettings $settings)
    {
        $path = Dialog::new()
            ->title(__('choose_backup_folder'))
            ->button(__('select'))
            ->folders()
            ->open();

        if (! $path) {
            return back();
        }

        $settings->put(['destination' => $path]);

        return back()->with('success', __('backup_destination_set'));
    }

    public function restore(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ]);

        if (strtoupper(trim($data['confirmation'])) !== 'RESTORE') {
            throw ValidationException::withMessages([
                'confirmation' => __('backup_type_restore_to_confirm'),
            ]);
        }

        $lock = Cache::lock('backup', 600);

        if (! $lock->get()) {
            return back()->with('error', __('backup_busy'));
        }

        try {
            $backups->restore($backups->resolve($data['name']));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        } finally {
            $lock->release();
        }

        // The DB underneath the running app has just been swapped. Reopen on it cleanly.
        App::relaunch();

        return back();
    }

    public function reveal(Request $request, BackupService $backups)
    {
        $settings = app(BackupSettings::class);

        $name = $request->string('name')->toString();

        $path = $name !== ''
            ? $backups->resolve($name)
            : $settings->destination();

        if ($path) {
            Shell::showInFolder($path);
        }

        return back();
    }

    public function destroy(string $name, BackupService $backups)
    {
        try {
            @unlink($backups->resolve($name));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('deleted'));
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import next to the other controller imports:

```php
use App\Http\Controllers\BackupController;
```

Then, immediately after the existing superadmin `roles` group (`routes/web.php:223-228`) and still **inside** the `['auth', 'verified', 'approved', 'permission']` group, add:

```php
    // Database backup & restore — desktop app only, superadmin only.
    // Route names are intentionally unmapped in config/permissions.php: the
    // `permission` middleware passes unmapped names through, and `superadmin` gates them.
    Route::middleware(['desktop', 'superadmin'])->group(function () {
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::post('/backups/tick', [BackupController::class, 'tick'])->name('backups.tick');
        Route::put('/backups/settings', [BackupController::class, 'updateSettings'])->name('backups.settings');
        Route::post('/backups/folder', [BackupController::class, 'chooseFolder'])->name('backups.folder');
        Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::post('/backups/reveal', [BackupController::class, 'reveal'])->name('backups.reveal');
        Route::delete('/backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy');
    });
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=BackupPageTest`
Expected: PASS — 9 tests.

- [ ] **Step 7: Confirm no RBAC regression**

The new route names must resolve to `null` (unmapped) so `EnsurePermission` lets them through to the `superadmin` gate.

Run: `php artisan test --filter="PermissionMapTest|ModuleAccessTest|PermissionMiddlewareTest"`
Expected: PASS, unchanged.

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/CreateBackup.php app/Http/Controllers/BackupController.php routes/web.php tests/Feature/BackupPageTest.php
git commit -m "feat: backup routes, controller and queued auto-backup job"
```

---

### Task 6: The Backup page, the sidebar entry and the heartbeat

**Files:**
- Create: `resources/js/Pages/Backups/Index.vue`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php:29-49` (share `isDesktop`)
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (sidebar entry, `desktopOnly` gating, heartbeat)
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:**
- Consumes: the routes and page props from Task 5.
- Produces: the shared Inertia prop `isDesktop: bool`.

- [ ] **Step 1: Share `isDesktop` with the frontend**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the array returned by `share()`, right after `'locale' => app()->getLocale(),`:

```php
            // Gates desktop-only UI (the Backup page) — there is no folder picker on the web.
            'isDesktop' => (bool) config('nativephp-internal.running'),
```

- [ ] **Step 2: Add the i18n keys**

Add these to `resources/js/i18n/en.json` (keep the file's alphabetical ordering):

```json
    "automatic_backup": "Automatic backup",
    "backup": "Backup",
    "backup_busy": "Another backup or restore is already running.",
    "backup_created": "Backup created.",
    "backup_destination": "Backup folder",
    "backup_destination_hint": "Choose a folder on an external drive. Backups written to the same disk as your data will not survive a disk failure.",
    "backup_destination_set": "Backup folder saved.",
    "backup_destination_unavailable": "The backup folder is not available. If it is on a USB drive, plug it back in. Automatic backups are not running.",
    "backup_no_destination": "No backup folder chosen yet. Pick one to start backing up.",
    "backup_now": "Back up now",
    "backup_restore_warning": "This replaces ALL current data with the contents of this backup. Everything entered since it was taken will be lost. A copy of your current data is saved first, so this can be undone.",
    "backup_type_restore_to_confirm": "Type RESTORE to confirm.",
    "backups": "Backups",
    "choose_backup_folder": "Choose the backup folder",
    "choose_folder": "Choose folder",
    "daily": "Daily",
    "every_launch": "Every launch",
    "frequency": "Frequency",
    "keep_last_backups": "Backups to keep",
    "keep_last_backups_hint": "Older backups are deleted automatically. 0 keeps everything.",
    "last_backup": "Last backup",
    "never": "Never",
    "no_backups": "No backups yet.",
    "open_folder": "Open folder",
    "restore": "Restore",
    "size": "Size",
    "weekly": "Weekly"
```

Add the same keys with French values to `fr.json` and Arabic values to `ar.json`. Any key already present (e.g. `select`, `save`, `delete`, `cancel`, `saved`, `deleted`, `actions`, `are_you_sure`) must not be duplicated — check before adding.

- [ ] **Step 3: Add the sidebar entry and the heartbeat**

In `resources/js/Layouts/AuthenticatedLayout.vue`:

Add to the module-scope block at the top (line 1-4), so the interval survives layout remounts across Inertia navigations without stacking:

```js
<script>
// Module scope: survives layout remounts across Inertia navigations.
let sidebarScrollTop = 0;
let backupHeartbeat = null;
</script>
```

Add a computed next to the other `page.props` computeds (around line 37):

```js
const isDesktop = computed(() => page.props.isDesktop ?? false);
```

Add the menu item to the `administration` section (around line 92-99), after `settings`:

```js
            { label: t('backup'), href: '/backups', icon: 'archive', prefix: '/backups', superadminOnly: true, desktopOnly: true },
```

Widen the section filter (line 105-106) so `desktopOnly` items disappear on the web build:

```js
            items: section.items.filter((i) =>
                (!i.desktopOnly || isDesktop.value)
                && (i.always || (i.superadminOnly ? isSuperadmin.value : can(i.module, 'view')))),
```

Add the heartbeat, and extend the existing `onMounted` (line 32-34):

```js
// A desktop app only runs when it is open, and there is no scheduler. So the
// automatic backup is driven from here: once per app launch, then every 30
// minutes while the window stays open. The server decides whether one is
// actually due — this just knocks on the door.
function tickBackup(trigger) {
    window.axios.post('/backups/tick', { trigger }).catch(() => {});
}

onMounted(() => {
    if (navEl.value) navEl.value.scrollTop = sidebarScrollTop;

    if (!isDesktop.value || !isSuperadmin.value) return;

    // sessionStorage is cleared when the Electron window closes, so this fires
    // exactly once per app launch — not on every Inertia navigation.
    if (!sessionStorage.getItem('backupLaunchTick')) {
        sessionStorage.setItem('backupLaunchTick', '1');
        tickBackup('launch');
    }

    if (backupHeartbeat === null) {
        backupHeartbeat = setInterval(() => tickBackup('heartbeat'), 30 * 60 * 1000);
    }
});
```

- [ ] **Step 4: Write the page**

Create `resources/js/Pages/Backups/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, computed } from 'vue';

const { t, locale } = useI18n();

const props = defineProps({
    settings: Object,
    destinationWritable: Boolean,
    backups: Array,
});

const settingsForm = useForm({
    enabled: props.settings.enabled,
    frequency: props.settings.frequency,
    retention: props.settings.retention,
});

const restoreForm = useForm({ name: '', confirmation: '' });

const restoring = ref(null);
const deleting = ref(null);
const backingUp = ref(false);

const lastBackup = computed(() =>
    props.settings.last_run_at ? formatDate(props.settings.last_run_at) : t('never'));

function formatDate(iso) {
    return new Date(iso).toLocaleString(locale.value);
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function chooseFolder() {
    router.post(route('backups.folder'), {}, { preserveScroll: true });
}

function openFolder(name = '') {
    router.post(route('backups.reveal'), { name }, { preserveScroll: true });
}

function saveSettings() {
    settingsForm.put(route('backups.settings'), { preserveScroll: true });
}

function backupNow() {
    backingUp.value = true;
    router.post(route('backups.store'), {}, {
        preserveScroll: true,
        onFinish: () => { backingUp.value = false; },
    });
}

function askRestore(backup) {
    restoring.value = backup;
    restoreForm.reset();
    restoreForm.name = backup.name;
}

function confirmRestore() {
    restoreForm.post(route('backups.restore'), {
        preserveScroll: true,
        onSuccess: () => { restoring.value = null; },
    });
}

function destroy() {
    router.delete(route('backups.destroy', deleting.value.name), {
        preserveScroll: true,
        onSuccess: () => { deleting.value = null; },
    });
}
</script>

<template>
    <Head :title="t('backup')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('backup') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Destination -->
            <section class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('backup_destination') }}</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('backup_destination_hint') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <code v-if="settings.destination" class="flex-1 truncate rounded-lg bg-slate-50 dark:bg-slate-950 px-3 py-2 text-xs text-slate-700 dark:text-slate-300">
                        {{ settings.destination }}
                    </code>
                    <p v-else class="flex-1 text-sm text-slate-500 dark:text-slate-400">{{ t('backup_no_destination') }}</p>

                    <SecondaryButton @click="chooseFolder">{{ t('choose_folder') }}</SecondaryButton>
                    <SecondaryButton v-if="settings.destination" @click="openFolder()">{{ t('open_folder') }}</SecondaryButton>
                </div>

                <p v-if="settings.destination && !destinationWritable" class="mt-3 rounded-lg bg-rose-50 dark:bg-rose-950 px-3 py-2 text-sm text-rose-700 dark:text-rose-300">
                    {{ t('backup_destination_unavailable') }}
                </p>
            </section>

            <!-- Automatic backup -->
            <section class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('automatic_backup') }}</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" v-model="settingsForm.enabled" class="rounded border-slate-300 text-primary-600" />
                        {{ t('automatic_backup') }}
                    </label>

                    <div>
                        <label class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('frequency') }}</label>
                        <select v-model="settingsForm.frequency" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950 text-sm">
                            <option value="every_launch">{{ t('every_launch') }}</option>
                            <option value="daily">{{ t('daily') }}</option>
                            <option value="weekly">{{ t('weekly') }}</option>
                        </select>
                        <InputError :message="settingsForm.errors.frequency" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('keep_last_backups') }}</label>
                        <TextInput v-model.number="settingsForm.retention" type="number" min="0" class="mt-1 w-full" />
                        <p class="mt-1 text-xs text-slate-400">{{ t('keep_last_backups_hint') }}</p>
                        <InputError :message="settingsForm.errors.retention" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('last_backup') }}: {{ lastBackup }}</p>
                    <PrimaryButton :disabled="settingsForm.processing" @click="saveSettings">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Backups -->
            <section class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex items-center justify-between p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('backups') }}</h2>
                    <PrimaryButton :disabled="backingUp || !destinationWritable" @click="backupNow">{{ t('backup_now') }}</PrimaryButton>
                </div>

                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('size') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="backup in backups" :key="backup.name">
                            <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ formatDate(backup.created_at) }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ formatSize(backup.bytes) }}</td>
                            <td class="px-4 py-3 text-end">
                                <div class="flex justify-end gap-3">
                                    <button @click="askRestore(backup)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('restore') }}</button>
                                    <button @click="openFolder(backup.name)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('open_folder') }}</button>
                                    <button @click="deleting = backup" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!backups?.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_backups') }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- Restore: typed confirmation, because this is the one irreversible action in the app -->
        <Modal :show="!!restoring" @close="restoring = null">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('restore') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ t('backup_restore_warning') }}</p>
                <p class="mt-2 text-sm font-medium text-slate-900 dark:text-slate-100">{{ restoring?.name }}</p>

                <TextInput v-model="restoreForm.confirmation" class="mt-4 w-full" placeholder="RESTORE" />
                <InputError :message="restoreForm.errors.confirmation" class="mt-1" />

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="restoring = null">{{ t('cancel') }}</SecondaryButton>
                    <DangerButton :disabled="restoreForm.processing" @click="confirmRestore">{{ t('restore') }}</DangerButton>
                </div>
            </div>
        </Modal>

        <ConfirmModal :show="!!deleting" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleting = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Build the frontend to verify it compiles**

Run: `npm run build`
Expected: build succeeds, no unresolved imports.

If `date` is not already an i18n key, add it. Confirm `SecondaryButton`, `DangerButton`, `Modal`, `ConfirmModal`, `TextInput`, `InputError`, `PrimaryButton` all exist in `resources/js/Components/` — they do.

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: PASS — everything green, no regressions.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Backups/Index.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat: backup page, sidebar entry and auto-backup heartbeat"
```

---

### Task 7: Verify against the real packaged app

Unit and feature tests run on Laragon's PHP against temp directories. The thing that actually ships is a static PHP binary writing to Electron's userData on a customer's PC. This task proves the feature works there. Do not skip it — the two previous desktop-only bugs in this project (missing VC++ runtime, missing `ext-xmlwriter`) both passed every test and still broke on the customer device.

**Files:** none — this is verification.

- [ ] **Step 1: Confirm the bundled PHP can do the two things this feature depends on**

Run (PowerShell), from the repo root:

```powershell
$php = "nativephp\electron\dist\win-unpacked\resources\build\php\php.exe"
& $php -r "echo 'sqlite: '.(new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn().PHP_EOL; echo 'zip: '.(class_exists('ZipArchive') ? 'yes' : 'NO').PHP_EOL;"
```

Expected: `sqlite: 3.45.2` (any version ≥ 3.27 is fine — `VACUUM INTO` needs it) and `zip: yes`.

If `win-unpacked` does not exist yet, build first: `php artisan config:clear; php artisan native:build win x64 --no-interaction`.

- [ ] **Step 2: Build and install**

```powershell
php artisan config:clear
php artisan native:build win x64 --no-interaction
```

Kill any stray `sport-club.exe` / `php.exe` / `electron` processes and delete `nativephp\electron\dist` first if the build fails with `Access is denied`.

Install the resulting `nativephp\electron\dist\SPORT_CLUB-<version>-setup.exe`.

- [ ] **Step 3: Walk the flow in the installed app**

Log in as superadmin, then:

1. Backup appears in the sidebar under Administration. (If it does not: `isDesktop` is not being shared, or the user is not superadmin.)
2. Choose folder → the native Windows folder picker opens → pick a folder on an external drive → the path shows on the page.
3. Back up now → a `sport-club-backup-<timestamp>.zip` appears in that folder. Open it: it must contain `database.sqlite`, `media/`, and `manifest.json`.
4. Enable automatic backup, set frequency to Every launch, close the app, reopen it. Within a few seconds a second zip appears. (This proves the queue worker is actually running the job — if it does not appear, check `storage/logs`.)
5. Add a player. Restore the FIRST backup: type `RESTORE`, confirm. The app relaunches, and the player is gone.
6. Confirm `pre-restore/pre-restore-<timestamp>.zip` exists in the backup folder and contains the player you just added. This is the undo path — if it is missing, stop and fix it.
7. Confirm player photos and the club logo still render after the restore. (This is what a DB-only backup would have broken.)

- [ ] **Step 4: Unplug the drive and confirm it fails safely**

With the destination on a USB drive, unplug it and open the Backup page. Expected: the red "backup folder is not available" banner, `Back up now` disabled, and no crash. A backup that fails silently forever is the actual hazard this guards against.

- [ ] **Step 5: Commit anything the verification forced you to change, then update the project memory**

The NativePHP desktop memory note (`nativephp-desktop-build.md`) should gain a line about where backups live and the fact that restore deletes the `.migrated` marker to force a re-migrate.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Full zip: DB + media | 2 |
| `VACUUM INTO` snapshot | 2 |
| manifest.json with schema signature | 2 |
| Manual backup to a chosen folder | 5, 6 |
| Native folder picker | 5, 6 |
| Auto-backup on launch + 30-min heartbeat | 5, 6 |
| Frequency: every_launch / daily / weekly | 1 |
| Retention, default 10, 0 = keep all | 1, 2 |
| Restore: validate → verify → snapshot → swap → relaunch | 3, 5 |
| Typed `RESTORE` confirmation | 5, 6 |
| Delete `.migrated` so an older backup re-migrates | 3 |
| `Cache::lock` serializing backup vs restore | 5 |
| Destination-unavailable banner | 5, 6 |
| Desktop-only (404 on web) | 4 |
| Superadmin-only, no RBAC changes | 5 |
| Config stored outside the DB | 1 |
| History = the folder, no index | 2 |
| i18n en/fr/ar | 6 |

No gaps.

**Type consistency:** `BackupSettings::all()` returns the same five keys everywhere it is consumed. `BackupService::list()` entries are `name`/`path`/`bytes`/`created_at` in Task 2, and the Vue page reads exactly those. `isDue($trigger)` takes `'launch'`/`'heartbeat'`, which is what `tick()` passes and what the layout sends. `PREFIX` and `SNAPSHOT_DIR` are referenced by their constants, never by literal strings.

**Placeholders:** none — every step contains the code it asks for.
