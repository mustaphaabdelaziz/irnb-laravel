# Player Status History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every status change of a branch registration is kept as a dated line (old → new, note, author), shown per branch on the player page, editable by hand, and read by the Joined/Left report so departures survive a return.

**Architecture:** New table `registration_status_changes` owned by `BranchRegistration`. `StatusHistory` service writes and reads lines. The player form path (`RegistrationService::change`) writes `form` lines; a model hook writes a `derived` departure line whenever a dated leave is about to be wiped; bulk paths write nothing. `MovementEvents` turns history + undated-by-line leave dates into departures/returns for `PlayerMovement`.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, vue-i18n flat dotted keys, `node --test` for JS libs, SQLite (dev/desktop) and MySQL.

**Spec:** `docs/superpowers/specs/2026-11-20-player-status-history-design.md`

## Global Constraints

- Work in a git worktree on branch `feat/status-history`. Copy `vendor` and `node_modules` into it; never junction them. Fresh worktree needs `npm run build` before Inertia page tests.
- Main folder holds the owner's untracked files (`.claude/`, `HANDOFF.md`, `_bmad/`, `brag-output/`, `ASIRAM.png`, a PDF, `ASIREM-summary-for-NotebookLM.md`, training-groups spec/plan). Never stage them. Stage explicit file paths only.
- Do not push.
- Tests are PHPUnit classes. One class: `php artisan test --filter=<ClassName>`. Whole suite: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Never run test processes in parallel.
- Dates compared with `whereDate` (SQLite may store `Y-m-d 00:00:00`). No SQLite-only SQL.
- Bulk paths (`setField`, `leave`, `register`) write no `form` line.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`, inserted without reformatting, used with `t()` (never `te()`). No key segment named after a String method (`link`, `search`, `replace`, `at`, `sub`, `big`, `small`, `fixed`, `anchor`, `length`). Run `npm run i18n:check`.
- Help Center: Arabic article needs fr and en twins in the same commit; `php artisan help:check` and `php artisan test --filter=Help` pass.
- Commit messages conventional, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_20_300000_create_registration_status_changes_table.php` | Table |
| `database/migrations/2026_11_20_300001_seed_registration_departures.php` | One `derived` line per dated left registration |
| `app/Models/RegistrationStatusChange.php` | One line |
| `app/Models/BranchRegistration.php` | `statusChanges()`, safety-net + date-follow hooks |
| `app/Services/Player/StatusHistory.php` | record, keepDeparture, moveDeparture, correct, timeline, wasMemberOn, attachTo |
| `app/Services/Player/RegistrationService.php` | `change()` writes `form` lines |
| `app/Http/Requests/Player/ValidatesRegistrations.php` | `status_changed_on`, `status_note` rules |
| `app/Http/Requests/Player/StatusChangeRequest.php` | Manual line validation |
| `app/Http/Controllers/RegistrationStatusChangeController.php` | Manual add / edit / delete |
| `routes/web.php`, `config/permissions.php` | Routes, Players → edit |
| `app/Http/Controllers/PlayerController.php` | `show` sends `status_history` + `playerStatuses` |
| `app/Reports/Players/MovementEvents.php` | Departures / returns |
| `app/Reports/Players/PlayerMovement.php` | Returned movement, dates from history |
| `resources/js/lib/registrationCards.js` | Card carries saved status, change date, note |
| `resources/js/Pages/Players/Partials/RegistrationCard.vue` | Date-of-change + note fields |
| `resources/js/Pages/Players/Partials/RegistrationsSection.vue` | Timeline + actions |
| `resources/js/Pages/Players/Partials/StatusChangeModal.vue` | Add / edit line |
| `resources/js/Pages/Players/Show.vue` | Pass player id + statuses |
| `lang/{ar,fr,en}/validation.php`, `app/Support/ValidationAttributes.php` | Messages, field names |
| `resources/help/{ar,fr,en}/players/status-history.md`, `resources/help/topics.php` | Help article |

---

### Task 1: Schema, model, recording, seeding

**Files:**
- Create: both migrations, `app/Models/RegistrationStatusChange.php`, `app/Services/Player/StatusHistory.php`
- Modify: `app/Models/BranchRegistration.php` (relation)
- Test: `tests/Feature/Registrations/StatusHistoryRecordTest.php`

**Interfaces — Produces:**
- `RegistrationStatusChange` consts `ORIGIN_FORM='form'`, `ORIGIN_MANUAL='manual'`, `ORIGIN_DERIVED='derived'`; relations `registration()`, `fromStatus()`, `toStatus()`, `user()`; `changed_on` cast `date:Y-m-d`.
- `BranchRegistration::statusChanges(): HasMany` ordered `changed_on, id`.
- `StatusHistory::record(BranchRegistration $r, ?int $from, ?int $to, string $on, ?string $note, ?User $user, string $origin): RegistrationStatusChange` (blank note → null).

Migration (constraint names explicit):

```php
Schema::create('registration_status_changes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('branch_registration_id');
    $table->unsignedBigInteger('from_status_id')->nullable();
    $table->unsignedBigInteger('to_status_id')->nullable();
    $table->date('changed_on');
    $table->string('note', 255)->nullable();
    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('origin', 10);
    $table->timestamps();

    $table->foreign('branch_registration_id', 'reg_status_reg_fk')->references('id')->on('branch_player')->cascadeOnDelete();
    $table->foreign('from_status_id', 'reg_status_from_fk')->references('id')->on('player_statuses')->nullOnDelete();
    $table->foreign('to_status_id', 'reg_status_to_fk')->references('id')->on('player_statuses')->nullOnDelete();
    $table->foreign('user_id', 'reg_status_user_fk')->references('id')->on('users')->nullOnDelete();
    $table->index(['branch_registration_id', 'changed_on'], 'reg_status_reg_date_index');
    $table->index(['to_status_id', 'changed_on'], 'reg_status_to_date_index');
});
```

Seeding migration `up()` (idempotent; `down()` deletes `origin = derived` rows with null user):

```php
$left = DB::table('player_statuses')->where('code', 'left')->value('id');
if ($left === null) {
    return;
}
$now = now();
DB::table('branch_player')->where('status_id', $left)->whereNotNull('left_at')->orderBy('id')
    ->chunkById(500, function ($rows) use ($left, $now) {
        foreach ($rows as $row) {
            $day = substr((string) $row->left_at, 0, 10);
            $exists = DB::table('registration_status_changes')->where('branch_registration_id', $row->id)
                ->where('to_status_id', $left)->whereDate('changed_on', $day)->exists();
            if (! $exists) {
                DB::table('registration_status_changes')->insert([
                    'branch_registration_id' => $row->id, 'from_status_id' => null, 'to_status_id' => $left,
                    'changed_on' => $day, 'note' => null, 'user_id' => null, 'origin' => 'derived',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    });
```

- [ ] Step 1: Test — record writes a line (blank note → null, user id kept); `removeMistake` on a registration deletes its lines; the seeding migration (`(require database_path('migrations/2026_11_20_300001_seed_registration_departures.php'))->up()`, run twice) gives exactly one `derived` line for a dated left registration and none for an undated one (`DB::table('branch_player')->where(...)->update(['left_at' => null])`). Use `Tests\Support\RegistrationFixtures`.
- [ ] Step 2: Run, expect FAIL (class missing).
- [ ] Step 3: Write migrations, model, relation, `StatusHistory::record`.
- [ ] Step 4: Run, expect PASS. `php artisan migrate` on the worktree DB not needed (tests use RefreshDatabase).
- [ ] Step 5: Commit `feat(players): store registration status changes`.

### Task 2: Safety net and leave-date follow

**Files:** Modify `app/Models/BranchRegistration.php`, `app/Services/Player/StatusHistory.php`. Test: `tests/Feature/Registrations/StatusHistorySafetyNetTest.php`.

**Produces:** `StatusHistory::keepDeparture(BranchRegistration $r): void`, `StatusHistory::moveDeparture(BranchRegistration $r, ?string $oldRaw, ?string $new): void`.

```php
// BranchRegistration saving hook, first line after the isDirty guard:
app(StatusHistory::class)->keepDeparture($registration);

// new hook
static::updated(function (BranchRegistration $registration) {
    if ($registration->wasChanged('left_at') && ! $registration->wasChanged('status_id') && Player::isLeftStatus($registration->status_id)) {
        app(StatusHistory::class)->moveDeparture($registration, $registration->getRawOriginal('left_at'), $registration->left_at?->toDateString());
    }
});
```

```php
public function keepDeparture(BranchRegistration $registration): void
{
    $oldStatus = $registration->getRawOriginal('status_id');
    $oldLeftAt = $registration->getRawOriginal('left_at');
    if (! $registration->exists || ! Player::isLeftStatus($oldStatus) || $oldLeftAt === null || Player::isLeftStatus($registration->status_id)) {
        return;
    }
    $day = substr((string) $oldLeftAt, 0, 10);
    $recorded = RegistrationStatusChange::where('branch_registration_id', $registration->id)
        ->where('to_status_id', (int) $oldStatus)->whereDate('changed_on', $day)->exists();
    if (! $recorded) {
        $this->record($registration, null, (int) $oldStatus, $day, null, null, RegistrationStatusChange::ORIGIN_DERIVED);
    }
}

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
```

- [ ] Step 1: Tests — (a) `leftFootball()` then `register($player, ['branch_id' => foot])` (bulk reopen) → one `derived` line to `left` on `2026-03-15`, no user; (b) same when a line to left on that day already exists → still one line; (c) undated left registration returns → no line; (d) `setField($player, 'status_id', registeredId, foot)` on a left registration → derived line; (e) registration left with a line, `left_at` corrected via `$r->left_at = '2026-03-10'; $r->save();` → the line's `changed_on` is `2026-03-10`.
- [ ] Step 2: FAIL. Step 3: implement. Step 4: PASS, plus `--filter=Registration` stays green.
- [ ] Step 5: Commit `feat(players): never lose a leave date when a player returns`.

### Task 3: Form path writes lines

**Files:** Modify `RegistrationService.php` (`change()`), `ValidatesRegistrations.php`, `lang/{ar,fr,en}/validation.php`, `ValidationAttributes.php`. Test: `tests/Feature/Registrations/StatusHistoryFormTest.php`.

Rules added:

```php
'registrations.*.status_changed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
'registrations.*.status_note' => ['nullable', 'string', 'max:255'],
```

After-check inside the card loop:

```php
$changedOn = $card['status_changed_on'] ?? null;
$statusChanges = $saved !== null && array_key_exists('status_id', $card)
    && (int) ($card['status_id'] ?? 0) !== (int) ($saved->status_id ?? 0);
if ($statusChanges && ! empty($changedOn)) {
    if ($birthdate && $changedOn < $birthdate) {
        $validator->errors()->add("registrations.$i.status_changed_on", __('validation.status_change_before_birth'));
    }
    $joined = $saved->join_date?->toDateString();
    if ($joined !== null && $changedOn < $joined) {
        $validator->errors()->add("registrations.$i.status_changed_on", __('validation.status_change_before_join'));
    }
}
```

`change()`:

```php
$from = $registration->status_id === null ? null : (int) $registration->status_id;
foreach (self::DETAILS as $field) { /* unchanged */ }
$to = $registration->status_id === null || $registration->status_id === '' ? null : (int) $registration->status_id;
$statusChanged = array_key_exists('status_id', $card) && $from !== $to;
$changedOn = $statusChanged ? (empty($card['status_changed_on']) ? now()->toDateString() : (string) $card['status_changed_on']) : null;
// Leaving: the leave date and the date of the change are one fact.
if ($statusChanged && Player::isLeftStatus($to)) {
    $registration->left_at = $changedOn;
}
/* join date unchanged */
$registration->save();
if ($statusChanged) {
    app(StatusHistory::class)->record($registration, $from, $to, $changedOn, $card['status_note'] ?? null, $user, RegistrationStatusChange::ORIGIN_FORM);
}
```

Messages: en `status_change_before_birth` = "The date of change is before the birth date.", `status_change_before_join` = "The date of change is before the join date."; fr « La date du changement précède la date de naissance. » / « …précède la date d'adhésion. »; ar «تاريخ التغيير يسبق تاريخ الميلاد.» / «تاريخ التغيير يسبق تاريخ الانضمام.». Attribute map: `'registrations.*.status_changed_on' => 'status_change.date'`, `'registrations.*.status_note' => 'status_change.note'` (keys added in Task 4; add them to the JSON files in this task so messages resolve).

- [ ] Step 1: Tests via `PUT players.update` as admin with `firstname`, `lastname`, one card — leaving writes a `form` line (from registered, to left, date, note, user) and `left_at` = date; same status → no line; return (left → registered on `2026-09-01`) → lines `[left, registered]`, membership ID and join date unchanged, departure kept once; no date → today; future date / before birth / before join date → error on `registrations.0.status_changed_on`; bulk `players.bulkUpdate` status change → no `form` line.
- [ ] Step 2: FAIL. Step 3: implement. Step 4: PASS + `--filter=Registration`.
- [ ] Step 5: Commit `feat(players): record each status change made in the player form`.

### Task 4: Form fields

**Files:** `resources/js/lib/registrationCards.js`, `tests/js/registrationCards.test.mjs`, `RegistrationCard.vue`, i18n JSON.

`cardFromRegistration` adds `saved_status_id: registration.status_id || ''`, `status_changed_on: ''`, `status_note: ''`. `newCard` adds `saved_status_id: ''`, `status_changed_on: ''`, `status_note: ''`. New export:

```js
/** A saved card whose status differs from the one it was opened with. */
export function statusChanged(card) {
    return !card.is_new && String(card.status_id || '') !== String(card.saved_status_id || '');
}
```

`cardPayload`: when `statusChanged(card)` add `status_changed_on: card.status_changed_on || null`, `status_note: card.status_note || null`, and a left card sends `left_at: card.status_changed_on || null`. Otherwise neither key is sent.

`RegistrationCard.vue`: `const changing = computed(() => statusChanged(props.card))`; `watch(changing, (on) => { if (on && !props.card.status_changed_on) props.card.status_changed_on = localToday(); })`; under the status select, when `changing`, a date field (`status_change.date`, `max=localToday()`) and a note field (`status_change.note`, placeholder `status_change.note_hint`, maxlength 255) with their errors. The leave-date field shows only when `isLeft && !changing`.

Keys (ar / fr / en): `status_change.date` «تاريخ التغيير» / « Date du changement » / "Date of change"; `status_change.note` «ملاحظة» / « Note » / "Note"; `status_change.note_hint` «مثال: انتقل إلى مدينة أخرى» / « ex. : a déménagé » / "e.g. moved to another city".

- [ ] Step 1: JS tests — changed status sends date + note; left change sends `left_at` = change date; unchanged status sends neither; new card never counts as changed.
- [ ] Step 2: `npm run test:js` FAIL. Step 3: implement. Step 4: PASS; `npm run i18n:check`.
- [ ] Step 5: Commit `feat(players): ask the date and a note when a status changes`.

### Task 5: Manual lines (backend)

**Files:** Create `RegistrationStatusChangeController.php`, `StatusChangeRequest.php`. Modify `routes/web.php` (after `players.registrations.destroy`), `config/permissions.php` overrides, `StatusHistory.php` (`correct`, `present`, `attachTo`), `PlayerController::show`. Test: `tests/Feature/Registrations/StatusHistoryManualTest.php`.

Routes:

```php
Route::post('/players/{player}/registrations/{registration}/status-changes', [RegistrationStatusChangeController::class, 'store'])->name('players.status-changes.store');
Route::put('/players/{player}/status-changes/{statusChange}', [RegistrationStatusChangeController::class, 'update'])->name('players.status-changes.update');
Route::delete('/players/{player}/status-changes/{statusChange}', [RegistrationStatusChangeController::class, 'destroy'])->name('players.status-changes.destroy');
```

Overrides: `'players.status-changes.store' => ['players', 'edit']`, `'players.status-changes.destroy' => ['players', 'edit']` (update derives edit). Activity recorded explicitly in the controller (`ActivityRecorder::record($request->user(), ActivityAction::PLAYER_UPDATED, $player)`), so the routes stay out of `config/activity.php`.

Request rules: `from_status_id` nullable exists; `to_status_id` required on POST, `sometimes|required` on PUT, exists; `changed_on` required `date_format:Y-m-d`, `before_or_equal:today`, `after:1900-01-01`; `note` nullable max 255. After: not before player birthdate, not before the registration's join date (registration = route `registration` or `statusChange->registration`).

Controller checks the registration / line belongs to the player (`abort_unless(..., 404)`). Store → `record(..., ORIGIN_MANUAL)`, flash `flash.status_change_saved`. Update → `correct($line, $validated)`, same flash. Destroy → delete, flash `flash.status_change_deleted`.

```php
public function correct(RegistrationStatusChange $line, array $data): void
{
    DB::transaction(function () use ($line, $data) {
        $oldDay = $line->changed_on->toDateString();
        $line->changed_on = $data['changed_on'];
        $line->note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;
        if ($line->origin === RegistrationStatusChange::ORIGIN_MANUAL) {
            if (array_key_exists('to_status_id', $data)) $line->to_status_id = $data['to_status_id'];
            if (array_key_exists('from_status_id', $data)) $line->from_status_id = $data['from_status_id'];
        }
        $line->save();

        // The leave date and its line are one fact.
        $registration = $line->registration;
        if (Player::isLeftStatus($registration->status_id)
            && (int) $line->to_status_id === (int) $registration->status_id
            && $registration->left_at?->toDateString() === $oldDay) {
            $registration->forceFill(['left_at' => $line->changed_on->toDateString()])->saveQuietly();
            app(RegistrationService::class)->refreshMirror($registration->player);
        }
    });
}
```

`attachTo(Collection $registrations)` loads lines for those ids with `fromStatus`, `toStatus`, `user` in one query and sets attribute `status_history` (array of `present()`): `id, from_status_id, to_status_id, from_status {id, localized_name}|null, to_status {…}|null, changed_on (Y-m-d), note, origin, user (fullname|null), recorded_at (ISO)`. `show` calls it after `load`, and passes `playerStatuses` (`PlayerStatus::orderBy('sort_order')->get()`).

Flash keys (ar / fr / en): `flash.status_change_saved` «تم حفظ السطر.» / « Ligne enregistrée. » / "Line saved."; `flash.status_change_deleted` «تم حذف السطر.» / « Ligne supprimée. » / "Line deleted.".

- [ ] Step 1: Tests — admin adds a manual line; line on another player's registration → 404; update changes date + note; update on a `form` line ignores status fields; moving the departure line's date moves `left_at` on registration and player; destroy; viewer with only `players.view` gets 403 on all three; show page has `status_history` with note and user name.
- [ ] Step 2: FAIL. Step 3: implement. Step 4: PASS.
- [ ] Step 5: Commit `feat(players): add, correct and delete past status lines`.

### Task 6: Timeline on the player page

**Files:** Create `StatusChangeModal.vue`. Modify `RegistrationsSection.vue`, `Show.vue`, i18n JSON.

`RegistrationsSection` new props `playerId`, `playerStatuses`. Under each registration's `<dl>`: an ordered list — first point join date (or `join_year_only`, or —) labelled `status_history.joined`; then each `status_history` line: `to_status` name (or `status_history.removed_status`), formatted date, note, small «by X · date» (`status_history.by`) unless `user` is null. With `can('players','edit')`: add button (`status_history.add`) per registration; edit/delete icon buttons per line; delete through `ConfirmModal` (`status_history.delete_confirm`). Modal: fields previous status (optional), status (required), date, note; status fields shown only for a new or `manual` line; posts/puts with `preserveScroll`.

Keys: `status_history.title` «سجل الحالة» / « Historique du statut » / "Status history"; `.joined` «انضم» / « A rejoint » / "Joined"; `.by` «بواسطة {name}» / « par {name} » / "by {name}"; `.add` «إضافة سطر سابق» / « Ajouter une ligne passée » / "Add a past line"; `.edit` «تعديل السطر» / « Modifier la ligne » / "Edit line"; `.delete` «حذف السطر» / « Supprimer la ligne » / "Delete line"; `.delete_confirm` «حذف هذا السطر من السجل؟ لا يغيّر الحالة الحالية.» / « Supprimer cette ligne de l'historique ? Le statut actuel ne change pas. » / "Delete this line from the history? The current status does not change."; `.from_status` «الحالة السابقة» / « Statut précédent » / "Previous status"; `.to_status` «الحالة الجديدة» / « Nouveau statut » / "New status"; `.removed_status` «حالة محذوفة» / « Statut supprimé » / "Removed status"; `.empty` «لا تغييرات مسجلة.» / « Aucun changement enregistré. » / "No changes recorded.".

- [ ] Step 1: Implement. Step 2: `npm run build` and `npm run i18n:check` pass; `php artisan test --filter=PlayerPage` and `--filter=Registrations` green.
- [ ] Step 3: Commit `feat(players): show each branch's status history on the player page`.

### Task 7: Movement report

**Files:** Create `app/Reports/Players/MovementEvents.php`. Modify `StatusHistory.php` (`timeline`, `wasMemberOn`), `PlayerMovement.php`, i18n JSON, `tests/Feature/Reports/PlayerReportsTest.php` (summary now `[1, 1, 0]`). Test: `tests/Feature/Reports/PlayerMovementHistoryTest.php`.

```php
/** Stored lines plus the current leave date when no line records it. Needs statusChanges loaded. */
public function timeline(BranchRegistration $registration, ?int $leftId): Collection
{
    $entries = $registration->statusChanges->map(fn (RegistrationStatusChange $c) => (object) [
        'id' => $c->id, 'from' => $c->from_status_id, 'to' => $c->to_status_id, 'on' => $c->changed_on->toDateString(),
    ]);
    $leftAt = $registration->left_at?->toDateString();
    if ($leftId !== null && (int) $registration->status_id === $leftId && $leftAt !== null
        && ! $entries->contains(fn ($e) => (int) $e->to === $leftId && $e->on === $leftAt)) {
        $entries->push((object) ['id' => null, 'from' => null, 'to' => $leftId, 'on' => $leftAt]);
    }

    return $entries->sortBy(fn ($e) => $e->on.'#'.str_pad((string) ($e->id ?? PHP_INT_MAX), 20, '0', STR_PAD_LEFT))->values();
}

/** A member that day: joined by then and not left. Unknown counts as a member. */
public function wasMemberOn(BranchRegistration $registration, string $day, ?int $leftId): bool
{
    $joined = $registration->join_date?->toDateString();
    if ($joined !== null && $joined > $day) return false;
    if ($joined === null && $registration->legacy_join_year !== null && $registration->legacy_join_year > (int) substr($day, 0, 4)) return false;
    if ($leftId === null) return true;

    $timeline = $this->timeline($registration, $leftId);
    $before = $timeline->filter(fn ($e) => $e->on <= $day)->last();
    if ($before !== null) return (int) $before->to !== $leftId;
    $after = $timeline->first();
    if ($after !== null) return $after->from === null || (int) $after->from !== $leftId;

    return (int) $registration->status_id !== $leftId;
}
```

`MovementEvents::departures(?array $branches, ?array $categories): Collection` and `returns(...)` — each item `(object) ['player_id' => int, 'on' => 'Y-m-d']`, all years. Candidates: registrations with a line to left (or currently left with a date) / a line from left to not-left; filtered by branches (and categories when branches are given); club-wide (`$branches === null`) an event counts only if every other registration of the player was not a member that day (`wasMemberOn`). Club-wide departures also include players with no registration whose `players.left_at` is set.

`PlayerMovement`: movement options gain `returned` (`reports.movement.returned`); years include `changed_on` years; column `returned_at` (`reports.col.returned_at`) after `left_at`; `base()` for left / returned builds `Player::whereIn('id', keys of datesFor())`, then `applyCategoryBranch` when no branch is chosen; `rows()` stores `datesFor()` in `$this->dates` for `row()`; `row()` takes `left_at` / `returned_at` from it (joined rows: current `left_at`, null). `datesFor()` — left: latest departure in the year per player, `returned_at` = first return on/after it; returned: latest return in the year, `left_at` = latest departure on/before it. Summary gets a third figure (returned). Leavers chart groups departures by year, distinct players, category-filtered club-wide via `applyCategoryBranch`. Remove `leftRegistrations()`.

Keys: `reports.movement.returned` «عادوا» / « Retours » / "Returned"; `reports.col.returned_at` «تاريخ العودة» / « Date de retour » / "Returned on".

- [ ] Step 1: Tests — single-branch player left `2025-06-15` and returned `2026-09-01` through `sync()` with change dates: in 2025 Left with both dates; in 2026 Returned; not in 2026 Left. Two-branch player who left football only: not a club-wide leaver, but listed with `branch_id=[foot]`. `leftBoth()` (bulk, no line): club-wide Left 2026 with `2026-03-15`. Summary `[joined, left, returned]`. Update the old summary assertion to `[1, 1, 0]`.
- [ ] Step 2: FAIL. Step 3: implement. Step 4: PASS + `--filter=Report`.
- [ ] Step 5: Commit `feat(reports): count past departures and returns in the movement report`.

### Task 8: Help article

**Files:** Create `resources/help/{ar,fr,en}/players/status-history.md` (front matter `title`, `summary`, `pages: [players.show, players.edit]`, `keywords`, `related: [players/branch-registrations, players/edit-player, players/player-page]`; an example, steps without figures: change status in the edit form with date + note; read the history on the player page; add/correct a past line; a warning that bulk changes from the list leave no line). Modify `resources/help/topics.php` (append `status-history` after `branch-registrations`), and the Arabic/fr/en `branch-registrations.md` warning gains a sentence pointing to the history.

- [ ] Step 1: Write. Step 2: `php artisan help:check`, `php artisan test --filter=Help`.
- [ ] Step 3: Commit `docs(help): explain the player status history`.

### Task 9: Whole check

- [ ] `vendor/bin/pint --dirty`, `npm run build`, `npm run test:js`, `npm run i18n:check`, full suite. Fix failures caused by this work.
- [ ] Merge into local `main` only after checking `main`'s tip (other sessions move it). Do not push.
