# Database Backup & Restore — Design

Date: 2026-07-13
Status: Approved, ready for implementation planning

## Problem

The SPORT_CLUB NativePHP desktop app keeps all club data on the customer's PC, in the
Electron userData directory:

- Database: `%APPDATA%\sport-club\database\database.sqlite`
- Uploads (player photos, club logo, documents): `%APPDATA%\sport-club\storage\app\public`

Nothing copies this anywhere. A dead disk, a stolen laptop, or a bad restore means the club
loses every player, subscription and transaction it has ever recorded. There is currently no
way for the user to take a backup without knowing where `%APPDATA%` is, and no way to put one
back.

This feature adds an in-app Backup page: back up on demand to a folder the user picks
(typically an external drive), back up automatically on a schedule, and restore from any
backup file.

## Scope

In scope:

- Manual backup to a user-chosen destination folder.
- Automatic backup on app launch and while the app stays open, on a configurable frequency.
- Restore from a backup, with an undo path.
- Retention: keep the last N backups, prune the rest.
- Desktop builds only.

Out of scope (deliberate):

- Cloud / network destinations. The destination is a local or removable path.
- Backups on the web build. Server installs back up the way servers do (cron, snapshots).
- Encryption of the backup file. The destination is the user's own drive; adding a passphrase
  adds a way to permanently lose the data by forgetting it.
- Partial / selective restore (e.g. "just the players table").

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Backup contents | Database **and** media, in one `.zip` | Player photos and the club logo live outside the DB. A DB-only backup restores rows but leaves every image broken, and the user would not discover this until the day they needed it. |
| Auto-backup trigger | Heartbeat: on app launch, then every 30 min while open | A desktop app only runs when it is open, and no scheduler is wired (`routes/console.php` is empty, `bootstrap/app.php` has no `withSchedule()`, `nativephp/desktop` v2 ships no scheduler bridge). "Nightly at 2am" is not achievable. Launch + heartbeat covers both the PC that is restarted daily and the PC that is left running. |
| Restore safety | Automatic pre-restore snapshot, typed confirmation, then relaunch | Restore is the one irreversible operation in the app. |
| Platform | Desktop only | No native folder picker on web; a half-working web path is worse than none. |
| Retention | Keep last N, configurable, default 10 | Each zip is DB + all photos (tens of MB). Unbounded growth silently fills an external drive until backups start failing. |
| Access | Superadmin only, whole page | Restore can destroy the club's data. Matches how `/roles` is already gated. No new RBAC module needed. |
| Config storage | NativePHP `Settings` facade (electron-store, in userData, outside the DB) | **Load-bearing.** Config that describes how to recover the database must not live inside the database. If it lived in a DB table, restoring a three-month-old backup would roll back the destination, the frequency, and `last_run_at` — and the stale `last_run_at` would immediately trigger another backup. |
| Backup history | Not stored; the destination folder is the source of truth | The page lists whatever `*.zip` files are in the folder. A backup copied in from a USB stick appears; one deleted in Explorer disappears. No index to drift out of sync. |

## Verified environment facts

Checked against the bundled runtime (`nativephp/electron/dist/win-unpacked/resources/build/php/php.exe`):

- SQLite **3.45.2** — `VACUUM INTO` (needs 3.27+) is available.
- `ext-zip` / `ZipArchive` present.
- `QUEUE_CONNECTION=database`; `config/nativephp.php` already auto-starts a queue worker that
  currently has no jobs to process.
- `Native\Desktop\Dialog::new()->folders()->open()` returns the chosen path synchronously.
- `Native\Desktop\Facades\App::relaunch()` and `Shell::showInFolder()` exist.

## Architecture

Four units with narrow boundaries.

### `App\Services\Backup\BackupSettings`

The only class that touches the NativePHP `Settings` facade. Everything else depends on this,
so tests fake one seam.

Keys, with defaults:

| Key | Type | Default |
|---|---|---|
| `enabled` | bool | `false` |
| `destination` | absolute path or null | `null` |
| `frequency` | `every_launch` \| `daily` \| `weekly` | `daily` |
| `retention` | int, `0` = keep everything | `10` |
| `last_run_at` | ISO-8601 string or null | `null` |

Behaviour: `isDue(): bool` compares `last_run_at` against `frequency`; returns `false` when
`enabled` is false or `destination` is null. `destinationIsWritable(): bool` — a removable
drive can vanish.

### `App\Services\Backup\BackupService`

Filesystem and zip only. No HTTP, no facades except through `BackupSettings`.

- `create(): string` — writes `sport-club-backup-<ts>.zip` to the destination, prunes, returns
  the path. Used by both the manual button and the queued job.
- `snapshot(): string` — writes `pre-restore/pre-restore-<ts>.zip`. Same zip builder, different
  target, never pruned. Called only by `restore()`.
- `restore(string $zipPath): void`
- `list(): array` — scans the destination for `sport-club-backup-*.zip`, ignoring `pre-restore/`.
- `prune(): int` — enforces retention, returns the count deleted.

### `App\Jobs\CreateBackup`

Queued wrapper around `create()`, used by the automatic path only, so an auto-backup never
blocks a request. Manual backup runs synchronously — the user is watching, and wants the error
message if it fails.

### `App\Http\Controllers\BackupController`

`index`, `store` (manual), `tick` (heartbeat), `restore`, `destroy`, `chooseFolder`, `reveal`.

## Backup format

Filename: `sport-club-backup-YYYY-MM-DD_HHmmss.zip`

Contents:

- `database.sqlite` — produced by `VACUUM INTO` to a temp path, then added to the zip. This is
  an atomic, WAL-safe snapshot taken through SQLite itself: no torn copy, no `-wal`/`-shm`
  files to reconcile, and no need to stop writes.
- `media/` — the `storage/app/public` tree.
- `manifest.json`:

```json
{
  "app_id": "app.local",
  "app_version": "1.1.2",
  "created_at": "2026-07-13T14:22:30+00:00",
  "schema_signature": "<md5 of sorted migration basenames>",
  "db_bytes": 4194304,
  "media_files": 218
}
```

`schema_signature` is the same value `NativeAppServiceProvider::firstRunSetup()` already
computes to decide whether to run `migrate`.

## Restore

Synchronous, never queued: the queue tables live inside the database being replaced.

1. **Validate.** The zip must contain `manifest.json` and `database.sqlite`, and
   `manifest.app_id` must match the running app. A zip from another app is rejected.
2. **Verify.** Extract the DB to a temp path, open it, run `PRAGMA integrity_check`, and confirm
   a `migrations` table exists. A corrupt or wrong-shaped file is rejected here — before
   anything on disk has been touched.
3. **Safety snapshot.** Back up current data to `<destination>/pre-restore/pre-restore-<ts>.zip`.
   Never pruned.
4. **Swap the DB.** `DB::disconnect()`, unlink `-wal` and `-shm`, move the temp DB over the
   runtime DB.
5. **Swap the media.** Move the current `storage/app/public` aside, move the restored tree in,
   delete the old one only on success.
6. **Delete the `.migrated` marker.** This makes the existing boot logic re-run `migrate` on the
   next launch, so restoring an older backup into a newer app self-heals its schema instead of
   500ing on a missing table.
7. **`App::relaunch()`.**

Steps 1–3 are non-destructive. From step 4 on, the pre-restore zip is the undo path, and its
path is surfaced in any error message.

The UI requires the user to type `RESTORE` to confirm.

## Automatic backup

`AuthenticatedLayout` (desktop only) POSTs to `/backups/tick` once on mount and then every
30 minutes. The controller dispatches `CreateBackup` when `isDue()` and the destination is
writable.

- Once on mount covers "the user reopened the app".
- Every 30 minutes covers "the PC is never restarted".

A `Cache::lock` serializes backup against restore so they cannot interleave.

If the destination is unavailable (drive unplugged), `tick` is a silent no-op and the Backup
page shows a red "destination unavailable" banner. A backup that fails silently forever is the
real hazard.

After a successful create, `prune()` deletes the oldest backups beyond `retention`, skipping the
`pre-restore/` directory.

## Access control and platform gating

- New `EnsureDesktop` middleware: 404 when `config('nativephp-internal.running')` is false.
- Routes: `Route::middleware(['desktop', 'superadmin'])` — the same `superadmin` gate `/roles`
  already uses.
- No RBAC changes. `PermissionMap` passes through route names it does not map, and `backups.*`
  is unmapped by design.
- `HandleInertiaRequests` shares a new `isDesktop` prop; the sidebar entry renders only when
  `isDesktop && isSuperadmin`.

## Error handling

| Failure | Behaviour |
|---|---|
| Destination not set | Page shows a setup prompt; `tick` is a no-op. |
| Destination unwritable / unplugged | Red banner on the page; `tick` is a no-op; manual backup returns a clear error. |
| Disk full mid-zip | Partial zip is deleted; error surfaced. |
| Restore: bad or foreign zip | Rejected at validation, nothing touched. |
| Restore: corrupt DB inside zip | Rejected at `PRAGMA integrity_check`, nothing touched. |
| Restore fails after the swap | Error names the pre-restore zip path so the user can recover. |
| Two backups at once | `Cache::lock` — the second is skipped. |

## Testing

Unit (`BackupService`, `BackupSettings`, against temp directories):

- `create()` produces a zip containing `manifest.json`, `database.sqlite`, and the media tree.
- Restore round-trip: create → mutate the DB → restore → original rows are back.
- `prune()` keeps exactly N and never touches `pre-restore/`.
- A zip whose `manifest.app_id` differs is rejected.
- A zip with a corrupt `database.sqlite` is rejected before the live DB is modified.
- `isDue()` is correct for each frequency, and false when disabled or when no destination is set.

Feature:

- Backup routes 404 on the web build, 403 for a non-superadmin.
- `tick` dispatches `CreateBackup` when due, and does not when it is not.
- `restore` rejects a request without the typed confirmation.

NativePHP ships a `Fakes/` directory, so `Settings`, `Dialog` and `App` fake cleanly in tests.

## Files

New:

- `app/Services/Backup/BackupSettings.php`
- `app/Services/Backup/BackupService.php`
- `app/Jobs/CreateBackup.php`
- `app/Http/Controllers/BackupController.php`
- `app/Http/Middleware/EnsureDesktop.php`
- `resources/js/Pages/Backups/Index.vue`

Edited:

- `routes/web.php` — the `backups.*` route group
- `bootstrap/app.php` — register the `desktop` middleware alias
- `app/Http/Middleware/HandleInertiaRequests.php` — share `isDesktop`
- `resources/js/Layouts/AuthenticatedLayout.vue` — sidebar entry + heartbeat
- `resources/js/i18n/{en,fr,ar}.json` — page strings

## UI

A single page, `Backups/Index.vue`, four cards:

1. **Destination** — the current path, a "Choose folder" button (native picker), and an "Open
   folder" button (`Shell::showInFolder`).
2. **Automatic backup** — enable toggle, frequency select, retention number. Saves on change.
3. **Back up now** — a button, with a spinner while it runs.
4. **Backups** — the list from the destination folder (name, date, size), each row offering
   Restore, Delete, and Reveal.
