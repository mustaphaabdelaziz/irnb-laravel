# Attendance branches P2 (session Edit dialog) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One "Edit" dialog on the attendance session page edits title, date, times, coach and notes at any time, and branch, roster statuses, categories and kind until marks are first saved; it replaces the Move and pre-season Categories dialogs.

**Architecture:** New `PUT attendance.sessions.update` (`TrainingSessionController::update`) applies only the fields sent, enforces the locks, runs the branch-aware slot check and logs one `TRAINING_SESSION_UPDATED` activity. `move()` / `updateCategories()` and their routes are removed; their tests move to the new route. `Session.vue` gets one Edit modal.

**Tech Stack:** Laravel 13, PHPUnit feature tests (sqlite :memory:), Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat keys.

**Spec:** `docs/superpowers/specs/2026-10-23-attendance-branches-design.md`, section "Session edit dialog".

## Global Constraints

- Work only in worktree `D:/irnb-edit`, branch `feat/attendance-session-edit`. Stage explicit file paths only.
- Null `branch_id` = whole club. A branch is required whenever `Branch::exists()`.
- Always editable (not on a cancelled session): title (≤150), date, start/end (`H:i`, end after start), coach (≤100), notes (≤2000). A new date or start time keeps the first original date in `moved_from` (`moved_from ?? old date`).
- Editable only until marks are first saved (`$session->attendances()->exists()`): branch, roster statuses, categories (pre-season only), kind. Branch and kind change only on hand-added sessions (kind extra or preseason); a regular session keeps both. Pre-season → extra keeps only the primary category. Dropped primary → first chosen becomes primary.
- Error keys (existing): `att.error.cancelled`, `att.error.already_marked`, `att.error.not_preseason`, `att.error.duplicate`, `att.error.roster_statuses_required`. New: `att.error.not_hand_added`.
- Never run `php artisan migrate` on a real DB, never `--env=testing`. `php artisan config:clear` before tests. Tests are slow (~90s per file): focused filters while iterating.
- Test helper methods must not be named `session()` or `seed()` (they collide with TestCase).
- New strings via `node scripts/i18n-add.mjs <json>` (ar/fr/en), JSON in the scratchpad `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/f5180bdf-51af-4cd4-a47b-5d8543b2a1bb/scratchpad/`; never overwrite existing keys; then `npm run i18n:check`.
- Offline only.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Update endpoint (replaces move and categories)

**Files:**
- Modify: `app/Http/Controllers/TrainingSessionController.php` (add `update()`, remove `move()` and `updateCategories()`)
- Modify: `routes/web.php:391-392` (replace the move/categories routes)
- Modify: `app/Models/TrainingSession.php` (`updated` hook also watches `branch_id`)
- Modify: `app/Services/Activity/ActivityAction.php` (new `TRAINING_SESSION_UPDATED`, in both lists next to `TRAINING_SESSION_CATEGORIES_CHANGED`; keep the old constants for history)
- Modify (route switch only): `tests/Feature/AttendanceCalendarTest.php`, `tests/Feature/AttendanceSessionDeleteTest.php`, `tests/Feature/AttendanceSettingsPageTest.php`, `tests/Feature/PreseasonMultiCategoryTest.php`, `tests/Feature/SessionGenerationMarksTest.php`, `tests/Feature/AttendancePermissionTest.php`
- Create: `tests/Feature/TrainingSessionUpdateTest.php`

**Interfaces:**
- Produces: route `attendance.sessions.update` (`PUT /attendance/sessions/{session}`), accepting any subset of `title, coach, notes, date, start_time, end_time, branch_id, roster_status_ids, kind, category_ids`; success redirects back with `flash.training_session_updated`. `ActivityAction::TRAINING_SESSION_UPDATED = 'training_session_updated'` with properties `['fields' => list<string>]`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/TrainingSessionUpdateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Attendance\Roster;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionUpdateTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function makeTraining(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    private function mark(TrainingSession $training, Player $player): void
    {
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id,
            'status' => AttendanceStatus::Present->value,
        ]);
        $training->update(['state' => SessionState::Held]);
    }

    private function edit(TrainingSession $training, array $data)
    {
        return $this->actingAs($this->admin())->put(route('attendance.sessions.update', $training), $data);
    }

    #[Test]
    public function details_can_always_be_edited_even_after_marks(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $this->mark($training, $this->player($u15));

        $this->edit($training, [
            'title' => 'Finishing', 'coach' => 'Karim', 'notes' => 'Wet pitch',
            'date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '18:30',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_updated');

        $fresh = $training->fresh();
        $this->assertSame(['Finishing', 'Karim', 'Wet pitch', '2026-10-06', '17:00', '18:30', '2026-10-05'],
            [$fresh->title, $fresh->coach, $fresh->notes, $fresh->date, $fresh->start_time, $fresh->end_time, $fresh->moved_from]);
        $this->assertSame(['coach', 'date', 'end_time', 'moved_from', 'notes', 'start_time', 'title'],
            collect(ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields'])->sort()->values()->all());
    }

    #[Test]
    public function moving_twice_keeps_the_first_original_date_and_a_title_edit_moves_nothing(): void
    {
        $training = $this->makeTraining($this->category());
        $this->edit($training, ['date' => '2026-10-06', 'start_time' => '18:00', 'end_time' => '19:30'])->assertSessionHasNoErrors();
        $this->edit($training, ['date' => '2026-10-07', 'start_time' => '18:00', 'end_time' => '19:30'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-05', $training->fresh()->moved_from);

        $other = $this->makeTraining($this->category('U17'));
        $this->edit($other, ['title' => 'Only a title'])->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->moved_from);
    }

    #[Test]
    public function a_cancelled_session_cannot_be_edited(): void
    {
        $training = $this->makeTraining($this->category(), ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);

        $this->edit($training, ['title' => 'x'])->assertSessionHasErrors(['date' => 'att.error.cancelled']);
    }

    #[Test]
    public function end_must_follow_start(): void
    {
        $training = $this->makeTraining($this->category());

        $this->edit($training, ['date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '17:00'])->assertSessionHasErrors('end_time');
    }

    #[Test]
    public function roster_fields_lock_once_marks_are_saved_but_unchanged_values_pass(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id, 'roster_status_ids' => [$registered]]);
        $this->mark($training, $this->player($u15));

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasErrors(['branch_id' => 'att.error.already_marked']);
        $this->edit($training, ['kind' => 'preseason'])->assertSessionHasErrors(['kind' => 'att.error.already_marked']);
        $paused = (int) PlayerStatus::where('code', 'paused')->value('id');
        $this->edit($training, ['roster_status_ids' => [$paused]])->assertSessionHasErrors(['roster_status_ids' => 'att.error.already_marked']);

        $this->edit($training, ['branch_id' => $foot->id, 'kind' => 'extra', 'roster_status_ids' => [$registered], 'title' => 'Same'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Same', $training->fresh()->title);
    }

    #[Test]
    public function a_regular_session_keeps_its_branch_and_kind(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id, 'kind' => SessionKind::Regular]);

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasErrors(['branch_id' => 'att.error.not_hand_added']);
        $this->edit($training, ['kind' => 'extra'])->assertSessionHasErrors(['kind' => 'att.error.not_hand_added']);
        $this->edit($training, ['category_ids' => [$u15->id, $this->category('U17')->id]])->assertSessionHasErrors(['category_ids' => 'att.error.not_preseason']);
    }

    #[Test]
    public function an_extra_session_becomes_pre_season_with_categories_and_back(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $training = $this->makeTraining($u15);

        $this->edit($training, ['kind' => 'preseason', 'category_ids' => [$u15->id, $u17->id]])->assertSessionHasNoErrors();
        $this->assertSame(SessionKind::Preseason, $training->fresh()->kind);
        $this->assertSame([$u15->id, $u17->id], $training->fresh()->categoryIds());

        $this->edit($training, ['kind' => 'extra'])->assertSessionHasNoErrors();
        $this->assertSame(SessionKind::Extra, $training->fresh()->kind);
        $this->assertSame([$u15->id], $training->fresh()->categoryIds());
    }

    #[Test]
    public function dropping_the_primary_category_makes_the_first_chosen_one_primary(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $u19 = $this->category('U19');
        $training = $this->makeTraining($u15, ['kind' => SessionKind::Preseason]);

        $this->edit($training, ['category_ids' => [$u17->id, $u19->id]])->assertSessionHasNoErrors();

        $this->assertSame($u17->id, $training->fresh()->category_id);
        $this->assertSame([$u17->id, $u19->id], $training->fresh()->categoryIds());
    }

    #[Test]
    public function the_slot_check_uses_the_resulting_branch_and_time(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $this->makeTraining($u15, ['branch_id' => $foot->id]);
        $swimming = $this->makeTraining($u15, ['branch_id' => $swim->id]);

        $this->edit($swimming, ['branch_id' => $foot->id])->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
        $this->edit($swimming, ['branch_id' => $foot->id, 'date' => '2026-10-05', 'start_time' => '20:00', 'end_time' => '21:00'])->assertSessionHasNoErrors();
        $this->assertSame($foot->id, $swimming->fresh()->branch_id);
    }

    #[Test]
    public function changing_the_branch_changes_the_expected_roster(): void
    {
        $u15 = $this->category();
        $foot = Branch::create(['name' => 'Foot']);
        $swim = Branch::create(['name' => 'Swim']);
        $footballer = $this->player($u15);
        $swimmer = $this->player($u15);
        $foot->players()->attach($footballer->id);
        $swim->players()->attach($swimmer->id);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id]);

        $this->edit($training, ['branch_id' => $swim->id])->assertSessionHasNoErrors();

        $this->assertSame([$swimmer->id], app(Roster::class)->forSession($training->fresh())->pluck('id')->all());
    }

    #[Test]
    public function editing_needs_attendance_edit(): void
    {
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.update'));

        $training = $this->makeTraining($this->category());
        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view']]])->id]);
        $this->actingAs($viewer)->put(route('attendance.sessions.update', $training), ['title' => 'x'])->assertForbidden();
    }
}
```

If `PlayerStatus` codes `registered`/`paused` are not seeded by migrations in tests, check `tests/Feature/AttendanceRosterStatusTest.php` (it reads them the same way) and mirror its setup.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test --filter=TrainingSessionUpdateTest`
Expected: FAIL (route `attendance.sessions.update` not defined).

- [ ] **Step 3: Implement `update()`** in `TrainingSessionController` (replace `move()` and `updateCategories()`; keep `cancel`, `destroy`, `reset`, `slotRules()` only if still used by `store()` — it is, keep it):

```php
    /**
     * The session page's Edit dialog. Title, coach, notes, date and times can
     * always change (a new date or start keeps the first original date in
     * `moved_from`, so the generator never recreates the old slot). Branch,
     * roster statuses, categories and kind shape the roster, which is frozen
     * once marks are saved, so they change only before that; branch and kind
     * only on a session added by hand (a regular one follows its weekly
     * schedule). Fields left out of the request stay as they are.
     */
    public function update(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['date' => 'att.error.cancelled']);
        }
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'coach' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            // Start and end travel together (end is checked against start).
            'start_time' => ['required_with:end_time', 'date_format:H:i'],
            'end_time' => ['required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'branch_id' => ['sometimes', Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'roster_status_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'roster_status_ids.*' => ['integer', 'distinct', 'exists:player_statuses,id'],
            'kind' => ['sometimes', 'string'],
            'category_ids' => ['sometimes', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ], ['roster_status_ids.min' => 'att.error.roster_statuses_required']);

        $handAdded = $session->kind !== SessionKind::Regular;
        $kind = isset($data['kind']) ? SessionKind::tryFrom($data['kind']) : $session->kind;
        $branchId = array_key_exists('branch_id', $data) ? ($data['branch_id'] === null ? null : (int) $data['branch_id']) : $session->branch_id;
        $statusIds = array_key_exists('roster_status_ids', $data) ? $this->sortedIds($data['roster_status_ids']) : $this->sortedIds($session->roster_status_ids);
        $currentIds = $session->categoryIds();
        $chosenIds = isset($data['category_ids']) ? $this->uniqueIds($data['category_ids']) : null;
        $categoryIds = match (true) {
            $kind !== SessionKind::Preseason => [$session->category_id],
            $chosenIds !== null => $chosenIds,
            default => $currentIds,
        };
        $sortedCategoryIds = $categoryIds;
        sort($sortedCategoryIds);

        $errors = [];
        if ($kind === null || ($kind === SessionKind::Regular && $session->kind !== SessionKind::Regular)) {
            $errors['kind'] = 'att.error.not_hand_added';
        } elseif (! $handAdded && $kind !== $session->kind) {
            $errors['kind'] = 'att.error.not_hand_added';
        }
        if (! $handAdded && $branchId !== $session->branch_id) {
            $errors['branch_id'] = 'att.error.not_hand_added';
        }
        if ($chosenIds !== null && $kind !== SessionKind::Preseason && $chosenIds !== [$session->category_id]) {
            $errors['category_ids'] = 'att.error.not_preseason';
        }
        if ($session->attendances()->exists()) {
            $changed = [
                'branch_id' => $branchId !== $session->branch_id,
                'roster_status_ids' => $statusIds !== $this->sortedIds($session->roster_status_ids),
                'kind' => $kind !== $session->kind,
                'category_ids' => $sortedCategoryIds !== $currentIds,
            ];
            foreach (array_keys(array_filter($changed)) as $field) {
                $errors[$field] ??= 'att.error.already_marked';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $date = $data['date'] ?? $session->date;
        $start = $data['start_time'] ?? $session->start_time;
        $this->assertSlotFree($branchId, $categoryIds, $date, $start, $session->id);

        $attributes = Arr::only($data, ['title', 'coach', 'notes', 'date', 'start_time', 'end_time']) + [
            'branch_id' => $branchId,
            'kind' => $kind,
            // If the primary category is dropped, the first chosen one becomes primary.
            'category_id' => in_array($session->category_id, $categoryIds, true) ? $session->category_id : $categoryIds[0],
            'roster_status_ids' => $statusIds,
        ];
        if ($date !== $session->date || $start !== $session->start_time) {
            $attributes['moved_from'] = $session->moved_from ?? $session->date;
        }

        try {
            DB::transaction(function () use ($session, $attributes, $categoryIds, $sortedCategoryIds, $currentIds, $request) {
                // Before the sync: a dropped category gets its slot back (pivot syncs fire no model events).
                $session->forgetGenerationMarks();
                $session->fill($attributes);
                $fields = array_keys($session->getDirty());
                if ($sortedCategoryIds !== $currentIds) {
                    $fields[] = 'category_ids';
                }
                $session->save();
                $session->categories()->sync($categoryIds);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_UPDATED, $session, ['fields' => array_values(array_unique($fields))]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (branch, category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_updated');
    }

    /** @return list<int>|null sorted ids; null or empty = none (the roster then takes the settings' default) */
    private function sortedIds(?array $ids): ?array
    {
        if ($ids === null || $ids === []) {
            return null;
        }
        $ids = $this->uniqueIds($ids);
        sort($ids);

        return $ids;
    }
```

Notes for the implementer:
- `kind` accepts only `extra`/`preseason` on a hand-added session; `SessionKind::tryFrom('bogus')` → null → `not_hand_added` error (fine: it is "not a kind a hand-added session can take"). Sending `kind = 'regular'` for a regular session is a no-op; sending it for a hand-added one is an error.
- `sortedIds()` stores the roster set sorted; `store()` keeps storing in the order given — that is fine, comparisons always go through `sortedIds()`.
- `assertSlotFree()` and `uniqueIds()` already exist; keep their signatures.
- Update the class-level docblock of `store()` if it mentions `move()`.

- [ ] **Step 4: Routes, model hook, activity**

`routes/web.php`: replace the two lines

```php
        Route::post('/attendance/sessions/{session}/move', [TrainingSessionController::class, 'move'])->name('attendance.sessions.move');
        Route::put('/attendance/sessions/{session}/categories', [TrainingSessionController::class, 'updateCategories'])->name('attendance.sessions.categories');
```

with

```php
        Route::put('/attendance/sessions/{session}', [TrainingSessionController::class, 'update'])->name('attendance.sessions.update');
```

`TrainingSession::booted()` `updated` hook: `if ($session->wasChanged(['branch_id', 'date', 'start_time', 'category_id', 'moved_from'])) {` and add "branch" to its comment ("A session that leaves a slot (moved, re-assigned to another branch or category, deleted) …").

`ActivityAction`: after `TRAINING_SESSION_CATEGORIES_CHANGED`:

```php
    const TRAINING_SESSION_UPDATED = 'training_session_updated';
```

and add `self::TRAINING_SESSION_UPDATED,` after `self::TRAINING_SESSION_CATEGORIES_CHANGED,` in both lists (the full list and the `'attendance'` group). Keep `TRAINING_SESSION_MOVED` and `TRAINING_SESSION_CATEGORIES_CHANGED`: old log rows still show them.

i18n (`keys-p2-t1.json` in the scratchpad), then `node scripts/i18n-add.mjs <path> && npm run i18n:check`:

```json
{
    "flash.training_session_updated": { "ar": "تم حفظ الحصة.", "fr": "Séance enregistrée.", "en": "Session saved." },
    "att.error.not_hand_added": { "ar": "لا يمكن تغيير الفرع أو النوع إلا لحصة أضيفت يدويًا.", "fr": "Seule une séance ajoutée à la main peut changer de section ou de type.", "en": "Only a session added by hand can change branch or type." },
    "activity.action.training_session_updated": { "ar": "تعديل الحصص", "fr": "Séances modifiées", "en": "Sessions edited" }
}
```

Check first that none of these keys exists (`grep` the three json files); if one exists, stop and report.

- [ ] **Step 5: Move existing tests to the new route**

In the test files listed under **Files**, replace:
- `->post(route('attendance.sessions.move', $x), $payload)` → `->put(route('attendance.sessions.update', $x), $payload)`
- `->put(route('attendance.sessions.categories', $x), $payload)` → `->put(route('attendance.sessions.update', $x), $payload)`
- `PermissionMap::resolve('attendance.sessions.categories')` → `PermissionMap::resolve('attendance.sessions.update')`

Error keys that legitimately change: a slot clash from a categories change is now reported on `start_time` (was `category_ids`); a cancelled session is reported on `date` (was `category_ids` for the categories route). Change only those expectations; any other failure is a bug in `update()` — fix the code, not the test. Flash: `flash.training_session_moved` / `flash.training_session_categories_saved` assertions become `flash.training_session_updated`. Activity assertions on `TRAINING_SESSION_MOVED` / `TRAINING_SESSION_CATEGORIES_CHANGED` become `TRAINING_SESSION_UPDATED`. Run `grep -rn "sessions.move\|sessions.categories\|TRAINING_SESSION_MOVED\|TRAINING_SESSION_CATEGORIES_CHANGED\|training_session_moved\|categories_saved" tests app resources/js/Pages` afterwards: only `ActivityAction.php` (constants) and i18n files may still match.

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="TrainingSessionUpdate|AttendanceCalendarTest|AttendanceSessionDelete|AttendanceSettingsPage|PreseasonMultiCategory|SessionGenerationMarks|AttendancePermission|AttendanceSessionBranch"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TrainingSessionController.php routes/web.php app/Models/TrainingSession.php app/Services/Activity/ActivityAction.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/TrainingSessionUpdateTest.php tests/Feature/AttendanceCalendarTest.php tests/Feature/AttendanceSessionDeleteTest.php tests/Feature/AttendanceSettingsPageTest.php tests/Feature/PreseasonMultiCategoryTest.php tests/Feature/SessionGenerationMarksTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): one update endpoint edits a session; move and categories folded in"
```

(Session.vue still calls the removed routes until Task 2; do not build or ship between the two tasks.)

---

### Task 2: Edit dialog on the session page

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (`show()` props)
- Modify: `resources/js/Pages/Attendance/Session.vue`
- Test: `tests/Feature/TrainingSessionUpdateTest.php` (one page-props test)

**Interfaces:**
- Consumes: Task 1 route `attendance.sessions.update`.
- Produces: page props `session.roster_status_ids` (list<int>, the effective set), `allCategories` (always, every category), `branches` (`[{id, name}]`), `playerStatuses` (`PlayerStatus::options()`).

- [ ] **Step 1: Write the failing test** (append to `TrainingSessionUpdateTest`; add `use Inertia\Testing\AssertableInertia as Assert;`):

```php
    #[Test]
    public function the_session_page_carries_what_the_edit_dialog_needs(): void
    {
        $u15 = $this->category();
        $this->category('U17');
        $foot = Branch::create(['name' => 'Foot']);
        $training = $this->makeTraining($u15, ['branch_id' => $foot->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allCategories', 2)
                ->where('branches.0.id', $foot->id)
                ->has('playerStatuses')
                ->has('session.roster_status_ids'));
    }
```

- [ ] **Step 2: Run it** — `php artisan test --filter=the_session_page_carries` → FAIL.

- [ ] **Step 3: Controller** — in `AttendanceController::show()`:
- `'session' => [` gains `'roster_status_ids' => $roster->statusIdsFor($session),`
- `'allCategories'` becomes unconditional: `Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values(),` with the comment "Every category: the Edit dialog can turn an extra session into a pre-season one."
- add `'branches' => Branch::orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized_name])->values(),`
- add `'playerStatuses' => PlayerStatus::options(),`
- imports: `App\Models\Branch`, `App\Models\PlayerStatus`; drop `SessionKind` if now unused.

- [ ] **Step 4: Session.vue**

Props: add `branches: { type: Array, default: () => [] }, playerStatuses: { type: Array, default: () => [] },`.

Script: delete the `showMove` / `moveForm` / `submitMove` block and the whole "Categories of a pre-season session" block (`canEditCategories`, `showCategories`, `categoriesForm`, `openCategories`, `submitCategories`). Update the `// ---- Cancel / move ----` comment to `// ---- Cancel ----`. Add:

```js
// ---- Edit: details any time; branch, roster, categories and kind until marks are first saved ----
const handAdded = computed(() => props.session.kind !== 'regular');
const showEdit = ref(false);
const editForm = useForm({ title: '', date: '', start_time: '', end_time: '', coach: '', notes: '', branch_id: null, roster_status_ids: [], kind: 'extra', category_ids: [] });
function openEdit() {
    const s = props.session;
    Object.assign(editForm, {
        title: s.title ?? '', date: s.date, start_time: s.start_time, end_time: s.end_time, coach: s.coach ?? '', notes: s.notes ?? '',
        branch_id: s.branch_id, roster_status_ids: [...(s.roster_status_ids ?? [])], kind: s.kind, category_ids: s.categories.map((c) => c.id),
    });
    editForm.clearErrors();
    showEdit.value = true;
}
// Locked fields are left out, so the server keeps them as they are.
const submitEdit = () => editForm
    .transform((d) => {
        const out = { title: d.title || null, date: d.date, start_time: d.start_time, end_time: d.end_time, coach: d.coach || null, notes: d.notes || null };
        if (!props.saved) {
            if (props.playerStatuses.length) out.roster_status_ids = d.roster_status_ids;
            if (handAdded.value) {
                out.kind = d.kind;
                if (props.branches.length) out.branch_id = d.branch_id;
            }
            if (d.kind === 'preseason') out.category_ids = d.category_ids;
        }
        return out;
    })
    // 'errors' keeps the modal open on a validation error; a success remounts the page (new roster, side panel values).
    .put(route('attendance.sessions.update', props.session.id), { preserveScroll: true, preserveState: 'errors', onSuccess: () => (showEdit.value = false) });
```

Header buttons inside `<template v-if="editable">`: replace the categories and move `IconButton`s with

```vue
                        <IconButton icon="pencil" :label="t('att.edit')" @click="openEdit" />
```

Template: delete the Move and Categories `<Modal>`s; add before `</AuthenticatedLayout>`:

```vue
        <Modal :show="showEdit" max-width="lg" @close="showEdit = false">
            <form class="space-y-3 p-5" @submit.prevent="submitEdit">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.edit_session') }}</h2>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="editForm.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="editForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="editForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="editForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="editForm.coach" type="text" maxlength="100" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="editForm.notes" rows="3" maxlength="2000" :class="[input, 'mt-1 block w-full']"></textarea></label>

                <div v-if="!saved && (handAdded || playerStatuses.length)" class="space-y-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div v-if="handAdded" class="inline-flex rounded-lg p-0.5 ring-1 ring-slate-200 dark:ring-slate-700" role="radiogroup">
                        <button v-for="k in ['extra', 'preseason']" :key="k" type="button" role="radio" :aria-checked="editForm.kind === k" class="rounded-md px-3 py-1 text-sm font-semibold" :class="editForm.kind === k ? (k === 'preseason' ? 'bg-amber-500 text-white' : 'bg-primary-600 text-white') : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'" @click="editForm.kind = k">{{ t(`att.kind.${k}`) }}</button>
                    </div>
                    <label v-if="handAdded && branches.length" class="block text-sm">{{ t('branch') }}
                        <select v-model="editForm.branch_id" :class="[input, 'mt-1 block w-full']">
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </label>
                    <fieldset v-if="editForm.kind === 'preseason'" class="text-sm">
                        <legend class="mb-1">{{ t('att.categories') }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label v-for="c in allCategories" :key="c.id" class="inline-flex items-center gap-1.5">
                                <input v-model="editForm.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                                {{ c.name }}
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('att.categories_help') }}</p>
                    </fieldset>
                    <fieldset v-if="playerStatuses.length" class="text-sm">
                        <legend class="mb-1">{{ t('att.roster_statuses') }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label v-for="s in playerStatuses" :key="s.id" class="inline-flex items-center gap-1.5">
                                <input v-model="editForm.roster_status_ids" type="checkbox" :value="s.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                                {{ s.name }}
                            </label>
                        </div>
                    </fieldset>
                </div>
                <p v-else-if="saved" class="rounded-lg bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800">{{ t('att.edit_locked_hint') }}</p>

                <InputError v-for="(e, k) in editForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showEdit = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="editForm.processing || (editForm.kind === 'preseason' && !saved && !editForm.category_ids.length) || (!saved && playerStatuses.length > 0 && !editForm.roster_status_ids.length)" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
```

Check the icon name `pencil` exists in `resources/js/Components/Icon.vue` (Settings.vue already uses it). If `att.categories_help` does not exist, drop that `<p>`.

i18n (`keys-p2-t2.json`), then `node scripts/i18n-add.mjs <path> && npm run i18n:check`:

```json
{
    "att.edit_session": { "ar": "تعديل الحصة", "fr": "Modifier la séance", "en": "Edit session" },
    "att.edit_locked_hint": { "ar": "الفرع وحالات اللاعبين والفئات والنوع ثابتة بعد حفظ الحضور.", "fr": "La section, les statuts des joueurs, les catégories et le type sont figés une fois les présences enregistrées.", "en": "Branch, player statuses, categories and type are fixed once attendance is saved." }
}
```

Afterwards: `grep -n "att.move\|att.edit_categories" resources/js` — if those keys are no longer used anywhere in `resources/js/Pages` or `resources/js/Components`, leave them in the catalogs (harmless) and say so in the report.

- [ ] **Step 5: Run tests and build**

Run: `php artisan test --filter="TrainingSessionUpdate|PreseasonMultiCategory|AttendanceSession|AttendanceRosterBranch"` → PASS. `npm run build` → succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Session.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/TrainingSessionUpdateTest.php
git commit -m "feat(attendance): one Edit dialog on the session page"
```

---

### Task 3: Verification

- [ ] `npm run i18n:check` → clean. `npm run build` → OK.
- [ ] Full suite once: `php artisan test` → all green (report the summary line).
- [ ] `grep -rn "sessions.move\|sessions.categories" app routes resources/js tests` → no matches.
