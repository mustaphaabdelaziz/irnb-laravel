# Round 2 / P5 — User activity tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record who did which piece of club work (players, money, equipment, board, documents, …), backfill what existing columns already know, and show it: "My activity" for every user, a comparison table and a per-user drill-down for users/view.

**Architecture:** One append-only `activity_logs` table. Controllers and services call `App\Services\Activity\ActivityRecorder::record()` explicitly at the business action (never model observers). Stable action codes live in `App\Services\Activity\ActivityAction`. A data migration backfills from attribution columns. `App\Services\Activity\ActivityReport` computes per-period counts, amounts and "later cancelled / archived" quality counts; three Inertia pages render them.

**Tech Stack:** Laravel 13, PHP 8.3, SQLite (desktop) / web DB, Inertia + Vue 3 (`<script setup>`, vue-i18n), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-22-post-testing-round-2-design.md`, "# P5 — User activity tracking" — its **Owner decisions (2026-09-26)** block at the top of that section overrides the text below it.

## Global Constraints

- **Offline only.** Nothing may need the internet at runtime (no CDN, remote API, online library).
- **Explicit recording only.** `ActivityRecorder::record()` is called in controllers/services at the business action. Never in model observers, migrations, seeders, console fixes or imports' per-row loops (imports record one summary event).
- **No sensitive values in `properties`**: only small context — amount, quantity, count, found/missing, file count, category code, kind. Never names of people, phone numbers, recipients, free text, file names.
- **History outlives its records.** No hard-delete path removes `activity_logs` rows. Reports must tolerate a missing subject ("record deleted").
- Recording happens only after the business action succeeded; when the action runs inside `DB::transaction`, record inside the same transaction.
- **Desktop:** data lives in migrations (NativePHP runs `migrate` on boot, never seeders). The backfill is a migration. No table rebuilds on SQLite; new tables only.
- **i18n:** flat keys; `t()` / `UiLang::get()` directly, never `te()`; add keys only with `node scripts/i18n-add.mjs <file.json>` (ar+fr+en); `npm run i18n:check` passes.
- **Permissions:** routes are protected by the route-name-derived `permission` middleware (`App\Support\PermissionMap`, `config/permissions.php`). `users.activity.index` / `users.activity.show` must resolve to `['users','view']`; `profile.activity` is self-only and unguarded.
- **Tests:** PHPUnit classes, `#[Test]`, `RefreshDatabase`. Full suite: PowerShell `$env:COMPOSER_PROCESS_TIMEOUT='0'; composer test`.
- **BOM files** (e.g. `resources/js/Pages/Players/Index.vue`, `Players/Show.vue`, `Subscriptions/Show.vue`, `Equipment/Catalog/Show.vue`, `resources/js/app.js`): targeted edits only, keep the UTF-8 BOM.
- **Git:** stage explicit files only, never `git add -A`. Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Work only in the worktree `D:\irnb-p5`** (branch `feat/round2-p5-activity`), using absolute `D:\irnb-p5\…` paths. Never write in `D:\irnb-laravel` — another session works there.

## Action codes (the complete list — `ActivityAction`)

| area | code | subject | properties |
|---|---|---|---|
| players | `player_registered` | Player | — |
| players | `player_imported` | — | `count` |
| players | `player_archived` | Player | — |
| players | `job_created` | MemberJob | — |
| players | `academic_record_added` | PlayerAcademicRecord | — |
| money | `transaction_recorded` | Transaction | `amount`, `type` (income/expense) |
| money | `transaction_imported` | — | `count`, `amount` (sum) |
| money | `payment_recorded` | Transaction (the main one) | `amount` (total incl. split donation) |
| money | `payment_edited` | Transaction (the new one) | `amount` |
| money | `transaction_cancelled` | Transaction | `amount` |
| money | `transfer_recorded` | FinanceTransfer | `amount` |
| money | `subscription_created` | Subscription | `kind` |
| money | `players_assigned` | Subscription | `count` |
| equipment | `stock_received` | EquipmentItem | `quantity` |
| equipment | `equipment_imported` | EquipmentCatalog | `count` |
| equipment | `equipment_rented` | EquipmentRental | `quantity` |
| equipment | `equipment_assigned` | EquipmentRental | `quantity` |
| equipment | `equipment_returned` | EquipmentRental | `quantity` |
| equipment | `stocktake_started` | InventorySession | — |
| equipment | `stocktake_completed` | InventorySession | `found`, `missing` |
| board | `meeting_created` | BoardMeeting | — |
| board | `meeting_cancelled` | BoardMeeting | — |
| board | `task_created` | BoardTask | — |
| board | `task_completed` | BoardTask | — |
| documents | `document_received` | PlayerDocument | — |
| documents | `document_renewed` | PlayerDocument | — |
| documents | `document_exempted` | PlayerDocument | — |
| documents | `document_file_uploaded` | PlayerDocument | `count` |

Quality ("later cancelled / archived"): `transaction_recorded`, `payment_recorded`, `payment_edited` → subject Transaction now `archived = true`; `player_registered` → subject Player now `archived = true`. A missing subject counts as neither.

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_26_100001_create_activity_logs_table.php` (new) | Table + indexes. |
| `app/Models/ActivityLog.php` (new) | Model, `UPDATED_AT = null`, casts, `user()`, `subject()` morphTo. |
| `app/Services/Activity/ActivityAction.php` (new) | Code constants, `ALL`, `AREAS` (area → codes), `QUALITY` (code → kind). |
| `app/Services/Activity/ActivityRecorder.php` (new) | `record()`. |
| `database/migrations/2026_09_26_100002_backfill_activity_logs.php` (new) | Idempotent backfill. |
| `app/Services/Activity/ActivityPeriod.php` (new) | Period from request: month / season / custom. |
| `app/Services/Activity/ActivityReport.php` (new) | Comparison rows, per-user summary, drill-through rows. |
| `app/Services/Activity/ActivitySubjectLink.php` (new) | Subject → URL (or null when deleted / no page). |
| `app/Http/Controllers/UserActivityController.php` (new) | `index`, `show`, `mine`. |
| `resources/js/Pages/Users/Activity/Index.vue`, `Show.vue` (new) | Comparison + per-user detail. `Show.vue` also serves "My activity". |
| `resources/js/Components/Activity/PeriodFilter.vue` (new) | month / season / custom range picker. |

---

### Task 1: Storage, model, action codes, recorder

**Files:**
- Create: the migration `2026_09_26_100001_create_activity_logs_table.php`, `app/Models/ActivityLog.php`, `app/Services/Activity/ActivityAction.php`, `app/Services/Activity/ActivityRecorder.php`
- Test: `tests/Unit/ActivityRecorderTest.php` (uses the DB — extend `Tests\TestCase` with `RefreshDatabase`)

**Interfaces — Produces:**
- `ActivityAction::PLAYER_REGISTERED = 'player_registered'` … one public const per code in the table above; `ActivityAction::ALL: list<string>`; `ActivityAction::AREAS: array<string area, list<string code>>` with areas `players, money, equipment, board, documents`; `ActivityAction::QUALITY: array<string code, 'cancelled'|'archived'>` per the Quality paragraph.
- `ActivityRecorder::record(?\App\Models\User $user, string $action, ?\Illuminate\Database\Eloquent\Model $subject = null, array $properties = []): \App\Models\ActivityLog` — static. Throws `InvalidArgumentException` for a code not in `ALL`. `occurred_at = now()`. Stores `subject_type = $subject->getMorphClass()`, `subject_id = $subject->getKey()`. Drops null property values. Stores `properties` as null when empty.
- `ActivityLog`: `$fillable = user_id, action, subject_type, subject_id, properties, occurred_at`; casts `properties => array`, `occurred_at => datetime`; `const UPDATED_AT = null`; `user(): BelongsTo`; `subject(): MorphTo`.

- [ ] **Step 1: Failing tests** — `tests/Unit/ActivityRecorderTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_actor_action_subject_and_small_properties(): void
    {
        $user = User::factory()->create();
        $meeting = BoardMeeting::create(['title' => 'AG', 'type' => 'general', 'meeting_date' => now(), 'status' => 'scheduled']);

        $log = ActivityRecorder::record($user, ActivityAction::MEETING_CREATED, $meeting, ['count' => 3, 'skip' => null]);

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('meeting_created', $log->action);
        $this->assertSame($meeting->getMorphClass(), $log->subject_type);
        $this->assertSame($meeting->id, $log->subject_id);
        $this->assertSame(['count' => 3], $log->fresh()->properties);
        $this->assertNotNull($log->occurred_at);
        $this->assertTrue($log->fresh()->subject->is($meeting));
    }

    #[Test]
    public function subject_and_properties_are_optional_and_empty_properties_store_null(): void
    {
        $log = ActivityRecorder::record(User::factory()->create(), ActivityAction::PLAYER_IMPORTED, null, []);

        $this->assertNull($log->subject_type);
        $this->assertNull($log->fresh()->properties);
    }

    #[Test]
    public function an_unknown_action_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActivityRecorder::record(User::factory()->create(), 'made_up');
    }

    #[Test]
    public function every_code_belongs_to_exactly_one_area(): void
    {
        $inAreas = array_merge(...array_values(ActivityAction::AREAS));
        sort($inAreas);
        $all = ActivityAction::ALL;
        sort($all);

        $this->assertSame($all, $inAreas);
        $this->assertCount(28, ActivityAction::ALL);
        $this->assertSame(count($inAreas), count(array_unique($inAreas)));
    }

    #[Test]
    public function the_table_has_no_updated_at_and_rows_survive_user_deletion(): void
    {
        $user = User::factory()->create();
        ActivityRecorder::record($user, ActivityAction::JOB_CREATED);
        $user->delete();

        $this->assertNull(ActivityLog::query()->sole()->user_id);
        $this->assertFalse(\Schema::hasColumn('activity_logs', 'updated_at'));
    }
}
```

(Check `BoardMeeting`'s required columns in its migration and adjust the fixture; keep the assertions.)

- [ ] **Step 2: Run — expect FAIL.** `php artisan test --filter=ActivityRecorderTest`
- [ ] **Step 3: Implement.** Migration: `id`; `foreignId('user_id')->nullable()->constrained()->nullOnDelete()`; `string('action', 64)`; `nullableMorphs('subject')`; `json('properties')->nullable()`; `timestamp('occurred_at')`; `timestamp('created_at')->nullable()`; indexes `(user_id, occurred_at)` and `(action, occurred_at)`. `down()` drops the table. Model and the two services per the interfaces.
- [ ] **Step 4: Run — PASS; then the full suite.**
- [ ] **Step 5: Commit** — `feat(activity): activity_logs table, action codes and recorder`

---

### Task 2: Record player, job and academic events

**Files (modify):** `app/Http/Controllers/PlayerController.php` (`store` ~:330, `destroy` ~:439, `bulkArchive` ~:462), `app/Http/Controllers/PlayerImportController.php` (`store`), `app/Services/Player/RegisterPlayerService.php` (`handle` :19), `app/Http/Controllers/MemberJobController.php` (`store` :26, `quickStore` :45), `app/Http/Controllers/PlayerAcademicRecordController.php` (`store` :16)
**Test:** `tests/Feature/ActivityPlayerEventsTest.php`

**Interfaces — Consumes:** `ActivityRecorder::record()`, `ActivityAction::*` (Task 1).

**Behaviour:**
- `PlayerController::store` records `player_registered` (subject: the new player) after registration succeeds. The importer does **not** record registrations; it records one `player_imported` with `count = $imported`, only when `$imported > 0`.
- `RegisterPlayerService::handle`: remove the unused `?int $recordedByUserId` parameter and update both callers (the spec's "fix in passing" — attribution now lives in the activity event recorded by the controller).
- `destroy` (single archive) and `bulkArchive` record `player_archived` per player actually archived. `bulkArchive` updates by query: select the ids that were not archived before the update, update, then record one event per id (load the models with `Player::whereIn(...)->get(['id'])` before the update; record after).
- Restores and permanent deletes record nothing.
- `MemberJobController::store` and `quickStore` record `job_created` (subject: the job) only when a job was actually created (the duplicate guard may return an existing job — check its flow; no event then).
- `PlayerAcademicRecordController::store` records `academic_record_added` (subject: the record). `update` records nothing.

- [ ] **Step 1: Failing tests** covering: register via POST `players.store` → one `player_registered` for the acting user with the player as subject; CSV import of 2 rows (reuse `PlayerImportTest`'s file builder) → one `player_imported` with `count = 2` and no `player_registered`; import of 0 valid rows → no event; single archive → one `player_archived`; bulk archive of 3 ids where 1 was already archived → 2 events; job create (settings page) and quick-create (JSON) → one `job_created` each, and a duplicate quick-create that returns the existing job → none; academic record store → one `academic_record_added`. Build fixtures the way the existing tests for those controllers do (`PlayerImportTest`, `JobDuplicateTest`, the academic tests).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the new test + `--filter="Player|Job|Academic"` — PASS; then the full suite.**
- [ ] **Step 5: Commit** — `feat(activity): record player, job and academic events`

---

### Task 3: Record money events

**Files (modify):** `app/Http/Controllers/TransactionController.php` (`store` ~:123, `destroy` ~:203, `bulkDestroy` ~:219), `app/Http/Controllers/TransactionImportController.php` (`store`), `app/Http/Controllers/PlayerTransactionController.php` (`store` :20, `update` :82, `destroy` :126), `app/Http/Controllers/PlayerController.php` (`permanentlyDelete` ~:760 — its transaction archiving), `app/Http/Controllers/CashRegisterController.php` (`transfer` ~:99), `app/Http/Controllers/SubscriptionController.php` (`store` ~:200, `assign` ~:257, `assignOne` ~:302), `app/Services/Equipment/EquipmentStockService.php` (`receive` — its optional expense, see Task 4 for the actor parameter)
**Test:** `tests/Feature/ActivityMoneyEventsTest.php`

**Behaviour:**
- `TransactionController::store` → `transaction_recorded` (subject: transaction; `amount`, `type`).
- Transactions import → one `transaction_imported` with `count` and `amount` (sum of imported amounts), only when count > 0.
- `PlayerTransactionController::store` → one `payment_recorded` per request that created at least one transaction: subject = the main transaction (the subscription payment, or the player-level one when there is no subscription), `amount` = total of the transactions created in that request (a split overpayment donation is included). An exemption that creates no transaction records nothing.
- `PlayerTransactionController::update` (cancel + recreate) → exactly one `payment_edited` (subject: the new main transaction, `amount`), and **no** `transaction_cancelled` / `payment_recorded` for that request.
- `transaction_cancelled` (subject: the transaction, `amount`) per transaction actually archived by: `TransactionController::destroy`, `TransactionController::bulkDestroy` (only the archivable ones — the controller already computes `$archivable`), `PlayerTransactionController::destroy`. `PlayerController::permanentlyDelete` archives transactions as a side effect of deleting a player: record nothing there (the player deletion is not a cancellation of work).
- `CashRegisterController::transfer` → `transfer_recorded` (subject: FinanceTransfer, `amount`).
- `SubscriptionController::store` → `subscription_created` (subject: subscription, `kind`). `assign` → `players_assigned` (subject: subscription, `count` = number of newly assigned players), only when count > 0. `assignOne` → `players_assigned` with `count = 1` when a player was newly assigned. The implicit assignment inside `PlayerTransactionController::resolvePlayerSubscription` records nothing (it is part of the payment).
- The stock-receipt expense: Task 4 passes the acting user into `EquipmentStockService::receive`; when that call creates an expense transaction, record `transaction_recorded` for it (type expense) there. If Task 4 has not run yet, add the `?User $actor = null` parameter here and let Task 4 reuse it — coordinate through the interface below.

**Interfaces — Produces:** `EquipmentStockService::receive(..., ?\App\Models\User $actor = null)` — a new trailing optional parameter (keep all existing parameters and callers working).

- [ ] **Step 1: Failing tests** for every bullet above, each asserting the exact event count, action, actor, subject and properties, and asserting absence where the spec says "no event" (edit → no cancelled/recorded; exemption → none; permanent delete → no cancelled; implicit subscription assignment → no players_assigned). Reuse fixtures from `PlayerPaymentFlowTest`, `TransactionBulkDeleteAndFiscalYearDeleteTest`, `TransactionImportRoundTripTest`, the cash register / transfer tests and the subscription tests.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the new test + `--filter="Transaction|Payment|Subscription|CashRegister|Transfer|Finance"` — PASS; full suite.**
- [ ] **Step 5: Commit** — `feat(activity): record money events`

---

### Task 4: Record equipment and stocktake events

**Files (modify):** `app/Http/Controllers/EquipmentItemController.php` (`receive` ~:40, `store` ~:66, `import` ~:489, `rent` ~:166, `returnItem` ~:202), `app/Services/Equipment/EquipmentStockService.php` (`receive`), `app/Services/Equipment/EquipmentLifecycleService.php` (`rentOut` :24, `returnItem` :84) — or record in the controllers, whichever keeps the actor explicit; `app/Http/Controllers/InventoryController.php` (`store` ~:37, `complete` ~:163)
**Test:** `tests/Feature/ActivityEquipmentEventsTest.php`

**Behaviour:**
- `stock_received` (subject: the EquipmentItem lot, `quantity`) for `EquipmentItemController::receive` and for `store` (single serialized item, `quantity = 1`).
- `EquipmentItemController::import` → one `equipment_imported` (subject: the catalog, `count`), only when count > 0. No per-row `stock_received`.
- `rent` → `equipment_rented` when type is rental, `equipment_assigned` when type is assignment (subject: EquipmentRental, `quantity`). No recipient data in properties.
- `returnItem` → `equipment_returned` (subject: the rental, `quantity` returned), partial returns included.
- `InventoryController::store` → `stocktake_started` (subject: session). `complete` → `stocktake_completed` (subject: session, `found`, `missing` from the reconciled totals), credited to the user completing it.
- Receipts that create an expense also get `transaction_recorded` (Task 3's rule) — implement via the `?User $actor` parameter on `EquipmentStockService::receive` (create it here if Task 3 has not).

- [ ] **Step 1: Failing tests** for each bullet (exact counts/actions/subjects/properties; import → no per-row events; rental vs assignment; partial return; stocktake started by user A and completed by user B → credited to each). Reuse fixtures from `EquipmentHistoryTest`, the equipment lot/rental tests, `EquipmentImportExportTest`, and the inventory tests.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the new test + `--filter="Equipment|Inventory|Stock|Rental"` — PASS; full suite.**
- [ ] **Step 5: Commit** — `feat(activity): record equipment and stocktake events`

---

### Task 5: Record board and document events

**Files (modify):** `app/Http/Controllers/BoardMeetingController.php` (`store` :16, `cancel` :127), `app/Http/Controllers/BoardTaskController.php` (`store` :12, `update` :22), `app/Http/Controllers/PlayerDocumentController.php` (`store` :31, `update` :48, `exempt` :65, `storeFiles` :90) and/or `app/Services/Player/PlayerDocumentService.php`
**Test:** `tests/Feature/ActivityBoardDocumentEventsTest.php`

**Behaviour:**
- `meeting_created`, `meeting_cancelled` (subject: meeting).
- `task_created` on store. `task_completed` on the transition to `completed`: on `store` when created already completed, on `update` only when the status before the update was not `completed` and after is `completed`. Saving an already-completed task records nothing.
- `document_received` (store / markReceived), `document_renewed` (update → renew), `document_exempted` (exempt) — subject: the PlayerDocument.
- `document_file_uploaded`: one event per request that attached ≥ 1 file (subject: the PlayerDocument, `count` = files attached in that request) — for `storeFiles`, and also when `store`/`update` attach files in the same request (then the request records both `document_received`/`document_renewed` and `document_file_uploaded`).

- [ ] **Step 1: Failing tests** for each bullet, including: re-saving a completed task → no second `task_completed`; completed → in_progress → completed → two events; receive with 2 files → one `document_received` + one `document_file_uploaded` with `count = 2`. Reuse fixtures from `BoardMeetingCancellationTest`, the board task tests and `PlayerDocumentActionsTest` (use `Storage::fake` as they do).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the new test + `--filter="Board|Document"` — PASS; full suite.**
- [ ] **Step 5: Commit** — `feat(activity): record board and document events`

---

### Task 6: Backfill migration

**Files:**
- Create: `database/migrations/2026_09_26_100002_backfill_activity_logs.php`
- Test: `tests/Feature/ActivityBackfillTest.php`

**Behaviour** (query builder only — no models, no recorder, so later code changes cannot break the migration):
- Every backfilled row: `properties` includes `"backfilled": true`; `occurred_at` = the source timestamp below; `created_at = now()`.
- Sources:
  - `transactions` where `recorded_by_user_id` not null → `payment_recorded` when `player_subscription_id` not null or `related_entity_type = 'Player'`, else `transaction_recorded`; subject `App\Models\Transaction`; `amount`; `occurred_at = created_at`.
  - `equipment_histories` where `user_id` not null and `event_type` in `Received` → `stock_received`, `Checkout` → `equipment_rented`, `Assigned` → `equipment_assigned`, `Return` → `equipment_returned`; subject `App\Models\EquipmentItem` (`item_id`); `quantity` from `details` JSON when present (json key used by `EquipmentStockService` / `EquipmentLifecycleService` — check them); `occurred_at = event_timestamp`. Note: these rows have no rental id, so the subject is the item.
  - `board_meetings.created_by_user_id` → `meeting_created` (`occurred_at = created_at`); `cancelled_by_user_id` not null → `meeting_cancelled` (`occurred_at = cancelled_at`).
  - `board_tasks.created_by_user_id` → `task_created` (`created_at`).
  - `inventory_sessions.conducted_by_user_id` → `stocktake_started` (`created_at`); and when `completed_at` not null → `stocktake_completed` (`completed_at`, `found`/`missing` from `total_found`/`total_missing`).
  - `finance_transfers.created_by_user_id` → `transfer_recorded` (`amount`, `created_at`).
  - `player_document_files.uploaded_by_user_id` → `document_file_uploaded` (subject `App\Models\PlayerDocument` via `player_document_id`, `count = 1`, `created_at`).
- **Idempotent:** skip a source row when an `activity_logs` row with the same `action`, `subject_type`, `subject_id` already exists — except `document_file_uploaded` and the equipment events, where one subject legitimately has many events: for those, skip when a row with the same action, subject and `occurred_at` exists.
- Chunked inserts (e.g. 500). `down()` deletes rows whose properties mark them backfilled (`json_extract(properties, '$.backfilled')` on SQLite; write it so it also works on MySQL — `whereJsonContains('properties->backfilled', true)` or the query builder JSON path).

- [ ] **Step 1: Failing tests:** seed one row per source (plus an unattributed row per source that must be ignored), run the migration's `up()` (`(require database_path('migrations/2026_09_26_100002_backfill_activity_logs.php'))->up();`), assert the exact rows; run `up()` twice → no duplicates; a live `transaction_recorded` already recorded for a transaction → not duplicated; `down()` removes only backfilled rows.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run — PASS; full suite. Then on a COPY of the dev DB** (`copy D:\irnb-laravel\database\database.sqlite <scratchpad>\p5.sqlite`, run `php artisan migrate --database=… ` against it via a temporary `DB_DATABASE` env var in the worktree only) and report the row counts per action — never migrate `D:\irnb-laravel\database\database.sqlite` itself.
- [ ] **Step 5: Commit** — `feat(activity): backfill activity from existing attribution columns`

---

### Task 7: ActivityPeriod, ActivityReport, ActivitySubjectLink

**Files:**
- Create: `app/Services/Activity/ActivityPeriod.php`, `app/Services/Activity/ActivityReport.php`, `app/Services/Activity/ActivitySubjectLink.php`
- Test: `tests/Unit/ActivityPeriodTest.php`, `tests/Feature/ActivityReportTest.php`

**Interfaces — Produces:**
- `ActivityPeriod::fromRequest(Request $r): ActivityPeriod` — `period` = `month` (default: current calendar month) | `season` (`App\Support\Season::current()` start..end) | `custom` (`from`, `to` as Y-m-d, inclusive; invalid or from > to → fall back to month). Public readonly `CarbonImmutable $start`, `$end` (end of day), `string $key`; `toArray(): array{period:string, from:string, to:string, label:string}` (label: month → `Y-m`, season → `Season::label()`, custom → `from – to`).
- `ActivityReport::comparison(ActivityPeriod $p): list<array{user: array{id:int,name:string}, areas: array<string area,int>, payments: array{count:int, amount:float}, total:int}>` — one row per user who has ≥ 1 event in the period **plus** active users with none (show zeros), ordered by `payments.count` desc, then `total` desc, then name. `payments` = `payment_recorded` events only (count and summed `amount`); edits are not new payments. Areas from `ActivityAction::AREAS`.
- `ActivityReport::summary(int $userId, ActivityPeriod $p): list<array{action:string, area:string, count:int, amount:?float, quality:?array{kind:'cancelled'|'archived', count:int}}>` — every action with count > 0 for that user; `amount` summed for actions whose properties carry `amount`; `quality` per `ActivityAction::QUALITY` (count of those events whose subject still exists and is now archived).
- `ActivityReport::entries(int $userId, string $action, ActivityPeriod $p, int $perPage = 25): LengthAwarePaginator` of `array{id, occurred_at (ISO), properties, subject: array{label:string, url:?string, deleted:bool}}`, newest first.
- `ActivitySubjectLink::for(ActivityLog $log): array{label:string, url:?string, deleted:bool}` — resolves the subject (eager-load; no N+1 — load subjects per type in one query per type) to a label (player full name, transaction title/amount, meeting title, …) and the page URL via the existing named routes (players.show, transactions.show, board meetings/tasks pages, equipment item/catalog pages, inventory session page, documents → the player page). `deleted = true` and `url = null` when the subject row no longer exists. `label` for events with no subject (imports) comes from the properties (e.g. count).

- [ ] **Step 1: Failing tests:** period parsing (month default, season via a configured `seasonStartMonth`, custom inclusive, invalid custom → month); comparison ranking (payments count desc) and zero rows for idle active users, amounts summed, events outside the period excluded; summary quality counts (2 payments, 1 later archived → quality 1; player registered then archived; subject deleted → not counted); entries pagination, newest first, deleted subject → `deleted: true`, `url: null`; query count stays constant as rows grow (use `DB::enableQueryLog()` with 5 vs 50 events).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** with aggregate queries (`GROUP BY user_id, action`), not per-row PHP loops.
- [ ] **Step 4: Run — PASS; full suite.**
- [ ] **Step 5: Commit** — `feat(activity): period, report and subject links`

---

### Task 8: Pages — comparison, per-user detail, My activity

**Files:**
- Create: `app/Http/Controllers/UserActivityController.php`, `resources/js/Pages/Users/Activity/Index.vue`, `resources/js/Pages/Users/Activity/Show.vue`, `resources/js/Components/Activity/PeriodFilter.vue`
- Modify: `routes/web.php` (inside the authenticated `permission` group, **before** any `users/{user}` wildcard routes: `GET /users/activity` → `users.activity.index`, `GET /users/{user}/activity` → `users.activity.show`; in the profile block: `GET /profile/activity` → `profile.activity`), `config/permissions.php` (add `profile.activity` to `unguarded` like the other `profile.*` routes), `resources/js/Pages/Users/Index.vue` (a link to the comparison), `resources/js/Pages/Profile/Edit.vue` (a "My activity" link), the user menu in `resources/js/Layouts/AuthenticatedLayout.vue` (a "My activity" item for everyone; "Activity" under users for users/view), i18n catalogs
- Test: `tests/Feature/UserActivityPagesTest.php`

**Behaviour:**
- `index`: Inertia `Users/Activity/Index` with `period` (toArray), `rows` (comparison), `areas` (area codes). Table: user, one column per area, payments (count + amount), total; each cell links to `users.activity.show` for that user with the same period (and `?area=` preselected). Ranked as the report returns.
- `show`: Inertia `Users/Activity/Show` with `user {id,name}`, `period`, `summary`, and — when `?action=` is a valid code — `entries` (paginated) for that action. Every count is a link that sets `action`; entries list the date, the subject link (or a greyed "record deleted"), and properties rendered as localized chips (amount formatted like the rest of the app, count, quantity, found/missing).
- `mine`: same `Show` page for `$request->user()`, route `profile.activity`, no users/view needed, and it never accepts another user's id.
- A small note on both pages: "Registrations before this release have no recorded author and are not counted." (spec) and backfilled rows show a subtle "from history" marker.
- Access: `users.activity.*` → users/view (assert via `PermissionMap::resolve`); a user without users/view gets 403 on both; `profile.activity` works for any approved user and shows only their own data.
- i18n: labels for all 28 actions (`activity.action.<code>`), 5 areas (`activity.area.<area>`), page titles, period options, column headers, "record deleted", "from history", the note. Add via `i18n-add` with ar/fr/en. Suggested labels (en / fr / ar):

| key | en | fr | ar |
|---|---|---|---|
| activity.title | Activity | Activité | النشاط |
| activity.my_title | My activity | Mon activité | نشاطي |
| activity.area.players | Players | Joueurs | اللاعبون |
| activity.area.money | Finance | Finances | المالية |
| activity.area.equipment | Equipment | Matériel | العتاد |
| activity.area.board | Board | Bureau | المكتب |
| activity.area.documents | Documents | Documents | الوثائق |
| activity.period.month | This month | Ce mois | هذا الشهر |
| activity.period.season | This season | Cette saison | هذا الموسم |
| activity.period.custom | Custom range | Période personnalisée | فترة مخصصة |
| activity.payments | Payments | Paiements | المدفوعات |
| activity.total | Total | Total | المجموع |
| activity.record_deleted | Record deleted | Enregistrement supprimé | سجل محذوف |
| activity.from_history | From history | Depuis l'historique | من السجل السابق |
| activity.later_cancelled | later cancelled | annulés ensuite | أُلغيت لاحقاً |
| activity.later_archived | later archived | archivés ensuite | أُرشفت لاحقاً |
| activity.no_author_note | Registrations made before this feature have no recorded author and are not counted. | Les inscriptions antérieures à cette fonction n'ont pas d'auteur enregistré et ne sont pas comptées. | التسجيلات التي سبقت هذه الميزة ليس لها منفذ مسجل ولا تُحتسب. |
| activity.action.player_registered | Players registered | Joueurs inscrits | تسجيل لاعبين |
| activity.action.player_imported | Player imports | Imports de joueurs | استيراد لاعبين |
| activity.action.player_archived | Players archived | Joueurs archivés | أرشفة لاعبين |
| activity.action.job_created | Jobs created | Professions créées | إضافة مهن |
| activity.action.academic_record_added | Grades entered | Moyennes saisies | إدخال معدلات |
| activity.action.transaction_recorded | Transactions recorded | Opérations saisies | تسجيل عمليات |
| activity.action.transaction_imported | Transaction imports | Imports d'opérations | استيراد عمليات |
| activity.action.payment_recorded | Payments recorded | Paiements encaissés | تسجيل مدفوعات |
| activity.action.payment_edited | Payments edited | Paiements modifiés | تعديل مدفوعات |
| activity.action.transaction_cancelled | Transactions cancelled | Opérations annulées | إلغاء عمليات |
| activity.action.transfer_recorded | Transfers | Virements entre caisses | تحويلات بين الصناديق |
| activity.action.subscription_created | Subscriptions created | Cotisations créées | إنشاء اشتراكات |
| activity.action.players_assigned | Players assigned to subscriptions | Joueurs affectés aux cotisations | إسناد لاعبين لاشتراكات |
| activity.action.stock_received | Stock received | Réceptions de stock | استلام مخزون |
| activity.action.equipment_imported | Equipment imports | Imports de matériel | استيراد عتاد |
| activity.action.equipment_rented | Rentals | Locations | تأجير |
| activity.action.equipment_assigned | Assignments | Attributions | تسليم للاستعمال |
| activity.action.equipment_returned | Returns | Retours | إرجاع |
| activity.action.stocktake_started | Stocktakes started | Inventaires ouverts | بدء جرد |
| activity.action.stocktake_completed | Stocktakes completed | Inventaires clôturés | إتمام جرد |
| activity.action.meeting_created | Meetings created | Réunions créées | إنشاء اجتماعات |
| activity.action.meeting_cancelled | Meetings cancelled | Réunions annulées | إلغاء اجتماعات |
| activity.action.task_created | Tasks created | Tâches créées | إنشاء مهام |
| activity.action.task_completed | Tasks completed | Tâches terminées | إنجاز مهام |
| activity.action.document_received | Documents received | Documents reçus | استلام وثائق |
| activity.action.document_renewed | Documents renewed | Documents renouvelés | تجديد وثائق |
| activity.action.document_exempted | Documents exempted | Documents dispensés | إعفاء من وثائق |
| activity.action.document_file_uploaded | Document uploads | Fichiers déposés | رفع ملفات |

  (Reuse existing keys for generic words such as the column "User" or "Date" if present — grep first.)
- UI: follow `Users/Index.vue` styling; `PeriodFilter` writes `period`/`from`/`to` to the query string with `router.get(..., { preserveState: true })`; responsive (table scrolls horizontally inside its card at phone width, no page-level horizontal scroll); RTL-aware (`text-start`, `ms-/me-`).

- [ ] **Step 1: Failing tests:** `PermissionMap::resolve('users.activity.index')` and `.show` → `['users','view']`; admin sees index with rows and show with summary/entries props (Inertia assertions `assertInertia`); a user with a role lacking users/view → 403 on both; any approved user → 200 on `profile.activity` with only their own summary; `?action=unknown` → no entries prop (or empty) and no error; period query parameters reach the props.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** backend then pages; `npm run i18n:check`; `npm run build`.
- [ ] **Step 4: Run the new test + `--filter="User|Permission|Profile"` — PASS; full suite.**
- [ ] **Step 5: Commit** — `feat(activity): comparison, per-user detail and My activity pages`

---

### Task 9: Verification and deploy notes

- [ ] Full suite; `npm run i18n:check`; `npm run build`; offline grep of `public/build/assets` (`grep -rhoE "https?://[a-zA-Z0-9.-]+" public/build/assets | sort -u` shows no CDN host).
- [ ] Pint on changed PHP files only.
- [ ] Recording audit: `grep -rn "ActivityRecorder::record" app` — every code in `ActivityAction::ALL` appears at least once (write a quick script or a unit test `every_action_is_recorded_somewhere` that greps `app/` for each constant name — keep it as a test).
- [ ] Observers audit: `grep -rn "ActivityRecorder" app/Observers database` → nothing (except the backfill migration, which must not use the recorder at all).
- [ ] Deploy notes (ledger): run `php artisan migrate` (2 migrations: table + backfill); refresh `storage/app/seed/database.sqlite` before the desktop build (schema change); rebuild desktop; QA in ar + fr: My activity, comparison with each period, drill-through incl. a deleted record after a finance reset.
