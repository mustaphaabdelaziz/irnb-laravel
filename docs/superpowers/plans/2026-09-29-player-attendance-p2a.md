# Player Attendance — P2a (codes, title, multi-category pre-season, views) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the six status codes, names and colours configurable, turn the session "theme" into a "Title / goal", let a pre-season session be shared by several categories, and add Week, Agenda and Timeline views next to the month calendar.

**Architecture:** One rename migration (`theme` → `title`, 150 chars) and one pivot `training_session_category` filled for every session (the P1 `category_id` stays the primary category and keeps the unique slot key). Rosters, slot checks, calendars and pre-season counting read the pivot. The codes live in `WebsiteConfig.settings['attendance']['codes']`; `AttendanceCode` becomes an instance built from them, and every attendance page gets them as the `attendanceCodes` Inertia prop, read in Vue only through `useAttendanceCodes()`. The calendar moves to `AttendanceCalendarController` behind the existing `attendance.index` route with a `view` query parameter; each view is a partial in `resources/js/Pages/Attendance/Partials/`, fed by a `CalendarFeed` service and a `PreseasonProgress` service.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3 (`<script setup>`, JS), Tailwind 4, vue-i18n (flat dotted keys), PHPUnit feature tests on sqlite.

Spec: `docs/superpowers/specs/2026-09-29-player-attendance-p2-design.md`, step **2a** only (sections A, B, C, D). Step 2b (statistics, profile, dashboard, exports) is out of scope.

## Global Constraints

- Work in the existing worktree `D:/irnb-attendance` on branch `feat/attendance-p2`. It already exists with `vendor/`, `node_modules/` and `.env`; do not create it. Use Git Bash and start every shell with `cd /d/irnb-attendance`.
- Stage explicit file paths only (`git add <file>...`). Never `git add -A` or `git add .`. Never commit `.superpowers/`.
- Commit with: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"`.
- Dates are stored as `'Y-m-d'` strings and times as `'H:i'` strings. **No `date`/`datetime` casts** on those columns (sqlite would store `Y-m-d H:i:s` and break the unique key and string comparisons).
- Offline only: no CDN, remote fonts or remote scripts (the desktop app runs offline).
- Both databases: sqlite (desktop, tests) and MySQL (web). Use the schema and query builders only, no raw dialect SQL.
- Migrations run on every desktop boot, so they must be idempotent-safe: guard schema changes with `Schema::hasTable()` / `Schema::hasColumn()`, and backfills insert only rows that are still missing.
- **Never rebuild a sqlite table that other tables reference with cascading foreign keys** (`->change()`, dropping a column, and so on). Migrations on sqlite run inside a transaction, where the `PRAGMA foreign_keys=OFF` that Laravel's rebuild emits does nothing, so the rebuild's `DROP TABLE` cascade-deletes the child rows. A scratch test against this repo's Laravel 13 lost every child row that way. `renameColumn` alone is a native `ALTER TABLE ... RENAME COLUMN` and is safe. Width changes run on MySQL only; sqlite does not enforce VARCHAR lengths anyway.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. In Vue use `t()` only, never `te()`. Error values that are `att.*` keys are shown through the guard `const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);`. Every `flash.*` key a controller emits must exist in all three files.
- New i18n keys are appended with the temporary `att-i18n.mjs` script given in each task (run it, then delete it). Do **not** use `scripts/i18n-add.mjs`: it re-sorts the files. The files must round-trip exactly through `JSON.stringify(o, null, 4) + '\n'`, and the diff must be additions only (the previous last line of each file gains a comma; nothing else changes). Keys added in this plan are new keys; `att.theme` and `att.grid_help` stay in the files unused.
- Never name a test helper `session()`: it collides with Laravel's TestCase.
- Rebuild with `npm run build` before running tests that make Inertia page assertions after a `.vue` file changed.
- Tests: PHPUnit classes with `#[Test]` and `use RefreshDatabase;` (plus `Tests\Support\AttendanceFixtures`), in `tests/Feature` or `tests/Unit`. Run with `php artisan test --filter=<Class>`.
- Activity: record with `ActivityRecorder::record()` at the call site, inside the same `DB::transaction`. No free text or names in properties.
- Permissions come from route names via `config/permissions.php`. Last segment `index`/`show` maps to view, `store` to add, `destroy` to delete, anything else to edit. All calendar views are served by the existing `attendance.index` route with a `view` query parameter, so they stay `view`. The one new route, `attendance.sessions.categories`, derives `edit`, which is what it needs.
- The status config reaches Vue as the Inertia prop `attendanceCodes` (value of `AttendanceSettings::codes()`), passed by the calendar, session and grid controllers, and is read only through `resources/js/Composables/useAttendanceCodes.js`.
- Ambiguities in the spec resolved by this plan (report them to the owner when 2a is done):
  1. The add-session modal always shows a primary-category select (pre-selected to the calendar's category, or the first one in views without a category). For a pre-season session, the other categories are checkboxes.
  2. Editing a pre-season session's categories may drop the primary category; the first remaining selected category then becomes primary.
  3. The session generator leaves free a regular slot that a joint pre-season session already holds for that category (same date and start time), so a category is never double-booked.
  4. Pre-season milestones: "started" is the first **held** pre-season session of the season for that category; "completed" is the held session whose count reaches the target (no target, no "completed").
  5. Timeline paging: "earlier month" / "later month" grow the shown window by one month (up to 12 months, then the window slides); "today" resets to the current month. The window is kept in the URL as `from` / `to`.
  6. The month calendar shows statuses as a small bar under each held session chip (configured colours) with the counts in the tooltip; the week view colours blocks by kind as the spec says.
  7. Grid codes accept Latin and Arabic-Indic digits for minutes (`R15`, `ت15`, `ت١٥`); Arabic-Indic digits are normalised to Latin before parsing.
  8. `title` is widened to 150 characters on MySQL only. On sqlite (desktop) the column is renamed without a table rebuild, because a rebuild would cascade-delete the marks (see above). Validation caps the title at 150 characters on both.

---

## File map

```
database/migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php
database/migrations/2026_09_29_200002_create_training_session_category_table.php   pivot + backfill
app/Models/TrainingSession.php            title, categories(), created hook, includingCategory(), categoryIds()
app/Services/Attendance/Roster.php        expected(int|array $categoryIds, ...)
app/Services/Attendance/SessionGenerator.php   fills the pivot, skips joint slots
app/Services/Attendance/MarkRecorder.php  log field title
app/Services/Attendance/AttendanceCode.php     instance built from the configured codes
app/Services/Attendance/PreseasonProgress.php  (new) done/target per category, milestones
app/Services/Attendance/CalendarFeed.php       (new) sessions for the views, status summaries, timeline events
app/Support/AttendanceSettings.php        codes defaults, LOCALES, codes()
app/Http/Controllers/AttendanceCalendarController.php  (new) attendance.index with view=month|week|agenda|timeline
app/Http/Controllers/AttendanceController.php          show + saveMarks only
app/Http/Controllers/TrainingSessionController.php     multi-category store, slot check, updateCategories
app/Http/Controllers/AttendanceGridController.php      pivot roster, configured codes
app/Http/Controllers/AttendanceSettingsController.php  codes validation
app/Http/Requests/SaveAttendanceMarksRequest.php       title
routes/web.php                            index -> AttendanceCalendarController, + attendance.sessions.categories
resources/js/Composables/useAttendanceCodes.js   (new)
resources/js/lib/attendanceCalendar.js           (new) date helpers + kind colours
resources/js/Pages/Attendance/Index.vue          shell: header, view switcher, add buttons, active view
resources/js/Pages/Attendance/Partials/AddSessionModal.vue (new)
resources/js/Pages/Attendance/Partials/ViewSwitcher.vue    (new)
resources/js/Pages/Attendance/Partials/MonthView.vue       (new)
resources/js/Pages/Attendance/Partials/WeekView.vue        (new)
resources/js/Pages/Attendance/Partials/AgendaView.vue      (new)
resources/js/Pages/Attendance/Partials/TimelineView.vue    (new)
resources/js/Pages/Attendance/Session.vue Grid.vue Settings.vue
resources/js/i18n/{ar,fr,en}.json
tests: TrainingSessionTitleTest, AttendanceCategoryPivotTest, PreseasonMultiCategoryTest,
       AttendanceJointCalendarTest, AttendanceCodeSettingsTest, AttendanceCodesOnPagesTest,
       AttendanceViewsTest (week, agenda, timeline), Unit/AttendanceCodeTest (rewritten);
       updated: AttendanceSessionTest, MarkRecorderTest, AttendancePermissionTest
```

---

### Task 1: Title / goal replaces theme

**Files:**
- Create: `database/migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php`
- Modify: `app/Models/TrainingSession.php`, `app/Services/Attendance/MarkRecorder.php`, `app/Http/Requests/SaveAttendanceMarksRequest.php`, `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/TrainingSessionController.php`
- Modify: `resources/js/Pages/Attendance/Index.vue`, `resources/js/Pages/Attendance/Session.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/TrainingSessionTitleTest.php` (new); update `tests/Feature/AttendanceSessionTest.php`, `tests/Feature/MarkRecorderTest.php`

**Interfaces:**
- Produces: column `training_sessions.title` (`string(150)`, nullable); `TrainingSession` fillable `title` (no `theme`); `MarkRecorder::save(..., ?array $log)` reads `coach`, `title`, `notes`; `attendance.sessions.marks` and `attendance.sessions.store` accept `title` (nullable, max 150); month calendar session payload key `title`; session payload key `session.title`.
- Produces: i18n keys `att.title_goal`, `att.title_placeholder`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/TrainingSessionTitleTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionTitleTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function theme_is_renamed_to_title_keeping_sessions_and_marks(): void
    {
        $this->assertTrue(Schema::hasColumn('training_sessions', 'title'));
        $this->assertFalse(Schema::hasColumn('training_sessions', 'theme'));

        $u15 = $this->category();
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'title' => 'Endurance',
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $migration = require database_path('migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php');

        // Back and forth, then a second run as on every desktop boot. A table
        // rebuild here would cascade-delete the marks (see the migration).
        $migration->down();
        $this->assertTrue(Schema::hasColumn('training_sessions', 'theme'));
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('training_sessions', 'title'));
        $this->assertFalse(Schema::hasColumn('training_sessions', 'theme'));
        $this->assertSame('Endurance', $session->fresh()->title);
        $this->assertSame(1, Attendance::count());
    }

    #[Test]
    public function the_title_is_saved_with_the_marks_up_to_150_characters(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $admin = $this->admin();
        $marks = [['player_id' => $a->id, 'status' => 'present']];

        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), ['title' => str_repeat('x', 151), 'marks' => $marks])
            ->assertSessionHasErrors('title');

        $title = str_repeat('Running 7.2 km in 40 min ', 6); // 150 characters
        $this->actingAs($admin)->put(route('attendance.sessions.marks', $session), ['title' => $title, 'marks' => $marks])
            ->assertSessionHasNoErrors();

        $this->assertSame(trim($title), $session->fresh()->title);
    }

    #[Test]
    public function a_new_session_takes_a_title_and_the_calendar_shows_it(): void
    {
        $u15 = $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.sessions.store'), [
            'category_id' => $u15->id, 'kind' => 'preseason', 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'title' => 'Running 7.2 km in 40 min',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Running 7.2 km in 40 min', TrainingSession::sole()->title);

        $this->actingAs($admin)->get(route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('sessions.0.title', 'Running 7.2 km in 40 min'));

        $this->actingAs($admin)->get(route('attendance.sessions.show', TrainingSession::sole()))
            ->assertInertia(fn (Assert $page) => $page->where('session.title', 'Running 7.2 km in 40 min'));
    }
}
```

Note: `TrimStrings` trims the trailing space of the 150-character string, hence `trim($title)` (149 characters stored).

Update the two existing tests that still say `theme`:

`tests/Feature/AttendanceSessionTest.php`, in `marks_are_saved_and_validated()`, replace
```php
            'coach' => 'Karim', 'theme' => 'Endurance',
```
with
```php
            'coach' => 'Karim', 'title' => 'Endurance',
```
and replace
```php
        $this->assertSame('Endurance', $session->fresh()->theme);
```
with
```php
        $this->assertSame('Endurance', $session->fresh()->title);
```

`tests/Feature/MarkRecorderTest.php`, in `saving_stores_marks_normalises_fields_and_holds_the_session()`, replace
```php
        ], $admin, ['coach' => 'Karim', 'theme' => 'Endurance', 'notes' => null]);
```
with
```php
        ], $admin, ['coach' => 'Karim', 'title' => 'Endurance', 'notes' => null]);
```
and add, right after `$this->assertSame('Karim', $session->coach);`:
```php
        $this->assertSame('Endurance', $session->title);
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="TrainingSessionTitleTest|AttendanceSessionTest|MarkRecorderTest"`
Expected: FAIL. `title` is not a column yet (`no such column: title` / `hasColumn` false).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Theme" becomes "Title / goal" (e.g. "Running 7.2 km in 40 min"), widened
     * from 100 to 150 characters. Each step is guarded so a half-applied run,
     * or a second run on a desktop boot, finishes cleanly.
     *
     * The widening runs only where a VARCHAR length is enforced (MySQL). On
     * sqlite the length is not enforced, and ->change() rebuilds the table:
     * inside the migration transaction `PRAGMA foreign_keys=OFF` is a no-op,
     * so the rebuild's DROP TABLE would cascade-delete every attendance mark.
     * The rename alone is a native ALTER TABLE RENAME COLUMN and is safe.
     */
    public function up(): void
    {
        if (Schema::hasColumn('training_sessions', 'theme') && ! Schema::hasColumn('training_sessions', 'title')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->renameColumn('theme', 'title');
            });
        }

        if (Schema::hasColumn('training_sessions', 'title') && Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->string('title', 150)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // The column keeps its 150 width: shrinking it could cut saved titles.
        if (Schema::hasColumn('training_sessions', 'title') && ! Schema::hasColumn('training_sessions', 'theme')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->renameColumn('title', 'theme');
            });
        }
    }
};
```

- [ ] **Step 4: Rename the field in the model, recorder, request and controllers**

`app/Models/TrainingSession.php`, replace
```php
        'cancel_reason', 'moved_from', 'coach', 'theme', 'notes',
```
with
```php
        'cancel_reason', 'moved_from', 'coach', 'title', 'notes',
```

`app/Services/Attendance/MarkRecorder.php`, replace
```php
    private const LOG_FIELDS = ['coach', 'theme', 'notes'];
```
with
```php
    private const LOG_FIELDS = ['coach', 'title', 'notes'];
```

`app/Http/Requests/SaveAttendanceMarksRequest.php`, replace
```php
            'theme' => ['nullable', 'string', 'max:100'],
```
with
```php
            'title' => ['nullable', 'string', 'max:150'],
```

`app/Http/Controllers/AttendanceController.php`, three replacements:
```php
                    'kind' => $s->kind->value, 'state' => $s->state->value, 'theme' => $s->theme,
```
becomes
```php
                    'kind' => $s->kind->value, 'state' => $s->state->value, 'title' => $s->title,
```
then
```php
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'theme' => $session->theme, 'notes' => $session->notes,
```
becomes
```php
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'title' => $session->title, 'notes' => $session->notes,
```
then
```php
            'coach' => $data['coach'] ?? null, 'theme' => $data['theme'] ?? null, 'notes' => $data['notes'] ?? null,
```
becomes
```php
            'coach' => $data['coach'] ?? null, 'title' => $data['title'] ?? null, 'notes' => $data['notes'] ?? null,
```

`app/Http/Controllers/TrainingSessionController.php`, in `store()`, replace
```php
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            ...$this->slotRules(),
```
with
```php
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            'title' => ['nullable', 'string', 'max:150'],
            ...$this->slotRules(),
```

- [ ] **Step 5: Show the title on the calendar chip and in the add-session modal**

`resources/js/Pages/Attendance/Index.vue`, replace
```js
const form = useForm({ category_id: null, kind: 'extra', date: '', start_time: '18:00', end_time: '19:30' });
```
with
```js
const form = useForm({ category_id: null, kind: 'extra', date: '', start_time: '18:00', end_time: '19:30', title: '' });
```

Replace the chip
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="`${t(`att.kind.${s.kind}`)} · ${t(`att.state.${s.state}`)}`">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
```
with
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="[t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.title].filter(Boolean).join(' · ')">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
```

In the modal, replace
```html
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="form.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in form.errors" :key="k" :message="e.startsWith('att.') ? t(e) : e" />
```
with
```html
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="form.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="form.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :class="[input, 'mt-1 block w-full']" /></label>
                <InputError v-for="(e, k) in form.errors" :key="k" :message="e.startsWith('att.') ? t(e) : e" />
```

`resources/js/Pages/Attendance/Session.vue`, replace
```js
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', theme: props.session.theme ?? '', notes: props.session.notes ?? '' });
```
with
```js
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', title: props.session.title ?? '', notes: props.session.notes ?? '' });
```
and replace
```html
                <label class="block text-sm">{{ t('att.theme') }}<input v-model="log.theme" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
```
with
```html
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="log.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
```

- [ ] **Step 6: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.title_goal': ['Title / goal', 'Titre / objectif', 'العنوان / الهدف'],
    'att.title_placeholder': ['e.g. Running 7.2 km in 40 min', 'ex. Course de 7,2 km en 40 min', 'مثال: جري 7.2 كم في 40 دقيقة'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file, each being the previous last key now re-added with a trailing comma. `git diff --stat resources/js/i18n` shows 3 insertions and 1 deletion per file.

- [ ] **Step 7: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="TrainingSessionTitleTest|AttendanceSessionTest|MarkRecorderTest|AttendanceCalendarTest" && npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_29_200001_rename_training_sessions_theme_to_title.php app/Models/TrainingSession.php app/Services/Attendance/MarkRecorder.php app/Http/Requests/SaveAttendanceMarksRequest.php app/Http/Controllers/AttendanceController.php app/Http/Controllers/TrainingSessionController.php resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Session.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/TrainingSessionTitleTest.php tests/Feature/AttendanceSessionTest.php tests/Feature/MarkRecorderTest.php
git commit -m "feat(attendance): session theme becomes title / goal (150 chars)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Session–category pivot, multi-category roster

**Files:**
- Create: `database/migrations/2026_09_29_200002_create_training_session_category_table.php`
- Modify: `app/Models/TrainingSession.php`, `app/Services/Attendance/Roster.php`, `app/Services/Attendance/SessionGenerator.php`, `app/Http/Controllers/AttendanceGridController.php`
- Test: `tests/Feature/AttendanceCategoryPivotTest.php` (new)

**Interfaces:**
- Consumes: `TrainingSession` (Task 1).
- Produces: table `training_session_category(training_session_id, category_id)` with primary key on the pair, cascading on both sides.
- Produces: `TrainingSession::categories(): BelongsToMany`; model `created` hook linking the primary category; scope `includingCategory(int $categoryId)` (sessions whose pivot contains the category); `categoryIds(): array<int,int>` (sorted ids, falls back to `[category_id]`).
- Produces: `Roster::expected(int|array $categoryIds, string $date): Collection<Player>`; `Roster::forSession()` uses every category of the session.
- Produces: `SessionGenerator::forMonth()` links every row it inserts in the pivot and skips a slot a joint session already holds for that category.
- Produces: the grid (`attendance.grid`, `attendance.grid.save`) lists sessions through the pivot and uses the multi-category roster.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceCategoryPivotTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCategoryPivotTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** A pre-season session of $primary on Monday 2026-10-05 18:00, shared with $others. */
    private function joint(Category $primary, array $others, array $extra = []): TrainingSession
    {
        $training = TrainingSession::create($extra + [
            'category_id' => $primary->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]);
        $training->categories()->syncWithoutDetaching(array_map(fn (Category $c) => $c->id, $others));

        return $training;
    }

    /** @return array<int, array{0: int, 1: int}> [session id, category id] pairs */
    private function links(): array
    {
        return DB::table('training_session_category')->orderBy('training_session_id')->orderBy('category_id')->get()
            ->map(fn ($row) => [(int) $row->training_session_id, (int) $row->category_id])->all();
    }

    #[Test]
    public function every_new_session_is_linked_to_its_primary_category(): void
    {
        $u15 = $this->category();
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $manual = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-06', 'start_time' => '09:00', 'end_time' => '10:00',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);
        app(SessionGenerator::class)->forMonth($u15->id, 2026, 10);

        // Four October Mondays plus the extra session, each linked once.
        $this->assertSame(5, TrainingSession::count());
        $this->assertSame(5, DB::table('training_session_category')->where('category_id', $u15->id)->count());
        $this->assertContains([$manual->id, $u15->id], $this->links());
    }

    #[Test]
    public function the_backfill_links_existing_sessions_once(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = TrainingSession::create(['category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);
        $b = TrainingSession::create(['category_id' => $u17->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned]);
        DB::table('training_session_category')->delete();

        $migration = require database_path('migrations/2026_09_29_200002_create_training_session_category_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame([[$a->id, $u15->id], [$b->id, $u17->id]], $this->links());
    }

    #[Test]
    public function the_generator_leaves_a_slot_taken_by_a_joint_session_free(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        $this->joint($u15, [$u17]);

        $this->assertSame(3, app(SessionGenerator::class)->forMonth($u17->id, 2026, 10));
        $this->assertSame(
            ['2026-10-12', '2026-10-19', '2026-10-26'],
            TrainingSession::where('category_id', $u17->id)->orderBy('date')->pluck('date')->all(),
        );
    }

    #[Test]
    public function the_expected_roster_of_a_joint_session_covers_all_its_categories(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $this->player($u17, ['archived' => true]);
        $this->player($this->category('U19'));
        $training = $this->joint($u15, [$u17]);

        $this->assertSame([$a->id, $b->id], app(Roster::class)->forSession($training)->pluck('id')->all());
        $this->assertSame([$a->id, $b->id], app(Roster::class)->expected([$u15->id, $u17->id], '2026-10-05')->pluck('id')->all());
        $this->assertSame([$u15->id, $u17->id], $training->categoryIds());
    }

    #[Test]
    public function each_category_grid_shows_a_joint_session_with_its_full_roster(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $training = $this->joint($u15, [$u17]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $u17->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->has('sessions', 1)
                ->where('sessions.0.id', $training->id)
                ->has('rows', 2)
                ->where("cells.{$a->id}.{$training->id}", '')
                ->where("cells.{$b->id}.{$training->id}", ''));

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'P', $b->id => 'AN']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $training->attendances()->count());
    }

    #[Test]
    public function the_session_screen_lists_the_players_of_every_category(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $this->player($u15);
        $this->player($u17);
        $training = $this->joint($u15, [$u17]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->has('candidates', 0));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceCategoryPivotTest`
Expected: FAIL: `no such table: training_session_category` / `Call to undefined method categories()`.

- [ ] **Step 3: Write the pivot migration**

`database/migrations/2026_09_29_200002_create_training_session_category_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every session is linked to each category taking part: one row for an
     * ordinary session, several for a joint pre-season session.
     * `training_sessions.category_id` stays the primary category, so the P1
     * unique slot key and the generator keep working unchanged.
     */
    public function up(): void
    {
        if (! Schema::hasTable('training_session_category')) {
            Schema::create('training_session_category', function (Blueprint $table) {
                $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->primary(['training_session_id', 'category_id']);
                $table->index(['category_id', 'training_session_id']);
            });
        }

        // Backfill: each session gets its primary category. Only missing rows
        // are inserted, so a second run (every desktop boot) adds nothing.
        DB::table('training_session_category')->insertUsing(
            ['training_session_id', 'category_id'],
            DB::table('training_sessions as s')
                ->select('s.id', 's.category_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('training_session_category as p')
                    ->whereColumn('p.training_session_id', 's.id')
                    ->whereColumn('p.category_id', 's.category_id')),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_category');
    }
};
```

- [ ] **Step 4: Add the relation, hook, scope and helper to the model**

Replace the whole of `app/Models/TrainingSession.php` with:
```php
<?php

namespace App\Models;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One training on one date. `date`/`moved_from` are 'Y-m-d' strings and times
 * 'H:i' strings (see the create_attendance_tables migration). `category_id` is
 * the primary category; `categories()` holds every category taking part (only
 * pre-season sessions have more than one).
 */
class TrainingSession extends Model
{
    protected $fillable = [
        'category_id', 'schedule_id', 'date', 'start_time', 'end_time', 'kind', 'state',
        'cancel_reason', 'moved_from', 'coach', 'title', 'notes',
    ];

    protected function casts(): array
    {
        return ['kind' => SessionKind::class, 'state' => SessionState::class];
    }

    protected static function booted(): void
    {
        // Every session is in the pivot with its primary category, so lists,
        // rosters and slot checks can read the pivot alone. (The generator
        // inserts rows without models and fills the pivot itself.)
        static::created(function (TrainingSession $session) {
            DB::table('training_session_category')->insertOrIgnore([
                'training_session_id' => $session->id,
                'category_id' => $session->category_id,
            ]);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'training_session_category');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Planned sessions nobody has marked yet: safe to delete and regenerate. */
    public function scopeUnmarkedPlanned(Builder $query): void
    {
        $query->where('state', SessionState::Planned->value)->whereDoesntHave('attendances');
    }

    /** Sessions the category takes part in: its own and joint pre-season ones. */
    public function scopeIncludingCategory(Builder $query, int $categoryId): void
    {
        $query->whereIn('training_sessions.id', DB::table('training_session_category')
            ->select('training_session_id')
            ->where('category_id', $categoryId));
    }

    /** @return array<int, int> sorted ids of every category in the session */
    public function categoryIds(): array
    {
        $ids = $this->relationLoaded('categories')
            ? $this->categories->modelKeys()
            : $this->categories()->pluck('categories.id')->all();
        $ids = array_map('intval', $ids !== [] ? $ids : [$this->category_id]);
        sort($ids);

        return $ids;
    }
}
```

- [ ] **Step 5: Roster over several categories**

Replace the whole of `app/Services/Attendance/Roster.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Models\Player;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session. Before the first save it is the members of
 * every category in the session on that date; after, it is exactly the saved
 * marks. Freezing it keeps history fair: later category changes, departures
 * and newcomers never rewrite a past session.
 */
final class Roster
{
    private const COLUMNS = ['id', 'firstname', 'lastname', 'category_id'];

    /**
     * A player has one category, so several categories never list anyone twice.
     *
     * @param  int|array<int, int>  $categoryIds
     * @return Collection<int, Player>
     */
    public function expected(int|array $categoryIds, string $date): Collection
    {
        return Player::whereIn('category_id', (array) $categoryIds)
            ->where('archived', false)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $date))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }

    /** @return Collection<int, Player> */
    public function forSession(TrainingSession $session): Collection
    {
        if (! $session->attendances()->exists()) {
            return $this->expected($session->categoryIds(), $session->date);
        }

        return Player::whereIn('id', $session->attendances()->select('player_id'))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::COLUMNS);
    }
}
```

- [ ] **Step 6: Generator fills the pivot and skips joint slots**

Replace the whole of `app/Services/Attendance/SessionGenerator.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ClubClosure;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a category's weekly schedule into planned sessions, one month at a
 * time. Called whenever a month is opened (the desktop app has no reliable
 * scheduler), so it must be idempotent: the unique (category, date, start)
 * key ignores slots that already exist, including cancelled ones, and dates
 * whose sessions were moved away from are never regenerated. A slot the
 * category already attends through another category's joint pre-season
 * session is left free too.
 */
final class SessionGenerator
{
    public function forMonth(int $categoryId, int $year, int $month): int
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

        $schedules = TrainingSchedule::where('category_id', $categoryId)
            ->where('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get(['start_date', 'end_date']);

        $moved = TrainingSession::where('category_id', $categoryId)
            ->whereNotNull('moved_from')
            ->whereBetween('moved_from', [$from, $to])
            ->pluck('moved_from')
            ->flip();

        $joint = DB::table('training_sessions')
            ->join('training_session_category', 'training_session_category.training_session_id', '=', 'training_sessions.id')
            ->where('training_session_category.category_id', $categoryId)
            ->where('training_sessions.category_id', '!=', $categoryId)
            ->whereBetween('training_sessions.date', [$from, $to])
            ->get(['training_sessions.date', 'training_sessions.start_time'])
            ->mapWithKeys(fn ($s) => ["{$s->date} {$s->start_time}" => true]);

        $now = now();
        $rows = [];

        for ($day = $first; $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($closures->contains(fn (ClubClosure $c) => $c->start_date <= $date && $c->end_date >= $date)) {
                continue;
            }

            foreach ($schedules as $schedule) {
                if ($schedule->weekday !== $day->dayOfWeekIso
                    || $schedule->valid_from > $date
                    || ($schedule->valid_to !== null && $schedule->valid_to < $date)
                    || isset($moved[$date])
                    || isset($joint["{$date} {$schedule->start_time}"])) {
                    continue;
                }

                $rows[] = [
                    'category_id' => $categoryId,
                    'schedule_id' => $schedule->id,
                    'date' => $date,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'kind' => SessionKind::Regular->value,
                    'state' => SessionState::Planned->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $inserted = $rows === [] ? 0 : DB::table('training_sessions')->insertOrIgnore($rows);

        if ($inserted > 0) {
            // insertOrIgnore returns no ids: link this month's sessions of the
            // category that have no pivot row yet, in one insert-select.
            DB::table('training_session_category')->insertUsing(
                ['training_session_id', 'category_id'],
                DB::table('training_sessions as s')
                    ->select('s.id', 's.category_id')
                    ->where('s.category_id', $categoryId)
                    ->whereBetween('s.date', [$from, $to])
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('training_session_category as p')
                        ->whereColumn('p.training_session_id', 's.id')
                        ->whereColumn('p.category_id', 's.category_id')),
            );
        }

        return $inserted;
    }
}
```

- [ ] **Step 7: The grid lists sessions through the pivot and uses the full roster**

Replace the whole of `app/Http/Controllers/AttendanceGridController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month grid: players × sessions, filled with the paper-sheet codes. A cell
 * exists only where the player is on that session's roster (frozen marks,
 * or the expected roster before the first save). A joint pre-season session
 * shows in the grid of each of its categories with its whole roster, since
 * saving a column replaces the session's full set of marks.
 */
class AttendanceGridController extends Controller
{
    public function show(Request $request, SessionGenerator $generator, Roster $roster): Response
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $generator->forMonth($category->id, $anchor->year, $anchor->month);

        $sessions = TrainingSession::includingCategory($category->id)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
            ->with(['attendances', 'categories'])
            ->orderBy('date')->orderBy('start_time')
            ->get();

        $cells = [];
        $expected = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = AttendanceCode::format($mark->status, $mark->minutes);
                }

                continue;
            }
            $categoryIds = $session->categoryIds();
            $key = $session->date.'|'.implode(',', $categoryIds);
            $expected[$key] ??= $roster->expected($categoryIds, $session->date)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $rows = Player::whereIn('id', array_keys($cells))->orderBy('lastname')->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname'])
            ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]);

        return Inertia::render('Attendance/Grid', [
            'category' => ['id' => $category->id, 'name' => $category->localized_name],
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'kind' => $s->kind->value, 'state' => $s->state->value,
            ])->values(),
            'rows' => $rows,
            'cells' => (object) $cells,
        ]);
    }

    public function save(Request $request, MarkRecorder $recorder, Roster $roster): RedirectResponse
    {
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.session_id' => ['required', 'integer', 'distinct', 'exists:training_sessions,id'],
            'columns.*.codes' => ['required', 'array', 'min:1'],
            'columns.*.codes.*' => ['nullable', 'string', 'max:6'],
        ]);

        $sessionIds = array_column($data['columns'], 'session_id');
        $sessions = TrainingSession::with(['attendances', 'categories'])->whereIn('id', $sessionIds)->get()->keyBy('id');

        $plan = [];
        $errors = [];
        foreach ($data['columns'] as $i => $column) {
            $session = $sessions->get($column['session_id']);

            if ($session->state === SessionState::Cancelled) {
                $errors["columns.$i"] = 'att.error.cancelled';

                continue;
            }

            $existing = $session->attendances->keyBy('player_id');
            $allowedIds = $existing->isNotEmpty()
                ? $existing->keys()->all()
                : $roster->expected($session->categoryIds(), $session->date)->modelKeys();
            $allowed = array_flip($allowedIds);
            $marks = [];

            foreach ($column['codes'] as $playerId => $code) {
                if (! ctype_digit((string) $playerId) || ! isset($allowed[(int) $playerId])) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.not_in_roster';

                    continue;
                }

                $parsed = AttendanceCode::parse($code);
                if ($parsed === null) {
                    $errors["columns.$i.codes.$playerId"] = 'att.error.invalid_code';

                    continue;
                }
                $previous = $existing->get((int) $playerId);
                $keepDetails = $previous?->status === $parsed['status'];
                $reason = $keepDetails ? $previous->reason?->value : null;
                if ($parsed['status'] === AttendanceStatus::AbsentExcused && $reason === null) {
                    $reason = AbsenceReason::Other->value;
                }

                $marks[] = [
                    'player_id' => (int) $playerId,
                    'status' => $parsed['status']->value,
                    'minutes' => $parsed['minutes'],
                    'reason' => $reason,
                    'note' => $keepDetails ? $previous->note : null,
                ];
            }
            $plan[] = [$session, $marks];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($plan, $recorder, $request) {
            foreach ($plan as [$session, $marks]) {
                $recorder->save($session, $marks, $request->user());
            }
        });

        return back()->with('success', 'flash.attendance_saved');
    }
}
```

- [ ] **Step 8: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceCategoryPivotTest|SessionGeneratorTest|MarkRecorderTest|AttendanceGridTest|AttendanceSessionTest|AttendanceCalendarTest|AttendanceSettingsPageTest|AttendanceModelTest"`
Expected: PASS. The P1 tests still pass: every `TrainingSession::create()` gets its pivot row from the hook, and generated rows get theirs from the insert-select.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_29_200002_create_training_session_category_table.php app/Models/TrainingSession.php app/Services/Attendance/Roster.php app/Services/Attendance/SessionGenerator.php app/Http/Controllers/AttendanceGridController.php tests/Feature/AttendanceCategoryPivotTest.php
git commit -m "feat(attendance): session-category pivot with multi-category roster" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Joint pre-season sessions — create, slot check, edit categories

**Files:**
- Create: `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`
- Modify: `app/Http/Controllers/TrainingSessionController.php`, `app/Http/Controllers/AttendanceController.php`, `routes/web.php`
- Modify: `resources/js/Pages/Attendance/Index.vue`, `resources/js/Pages/Attendance/Session.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/PreseasonMultiCategoryTest.php` (new); update `tests/Feature/AttendancePermissionTest.php`

**Interfaces:**
- Consumes: `TrainingSession::categories()`, `categoryIds()` (Task 2).
- Produces: `POST attendance.sessions.store` accepts `category_ids` (array of category ids). For `kind=preseason` the session's categories are `category_id` plus `category_ids`; for `kind=extra`, `category_ids` is ignored.
- Produces: route `PUT /attendance/sessions/{session}/categories` named `attendance.sessions.categories` (`TrainingSessionController::updateCategories`), body `category_ids: int[]`. Errors on `category_ids`: `att.error.not_preseason`, `att.error.cancelled`, `att.error.already_marked`, `att.error.duplicate`. Flash `flash.training_session_categories_saved`.
- Produces: slot check over every category of the session (store, move, update categories), error key `start_time` (store, move) with `att.error.duplicate`.
- Produces: `Attendance/Session` props: `session.categories: {id, name}[]` (primary first), `session.category: string` (names joined with ` · `), `rows[].category: ?string` (set only for joint sessions), `allCategories: {id, name}[]` (only for pre-season sessions, else `[]`).
- Produces: Vue component `Partials/AddSessionModal.vue` with props `show: Boolean`, `kind: 'extra'|'preseason'`, `categories: {id,name}[]`, `categoryId: ?Number`, `date: String ('Y-m-d')`, emits `close`.
- Produces: i18n keys `att.categories`, `att.other_categories`, `att.edit_categories`, `att.categories_help`, `att.error.not_preseason`, `att.error.already_marked`, `flash.training_session_categories_saved`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PreseasonMultiCategoryTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class PreseasonMultiCategoryTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function slot(array $extra = []): array
    {
        return $extra + ['date' => '2026-09-05', 'start_time' => '09:00', 'end_time' => '10:30'];
    }

    private function makeTraining(Category $category, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + $this->slot([
            'category_id' => $category->id, 'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]));
    }

    #[Test]
    public function a_preseason_session_is_created_for_several_categories(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u15->id, $u17->id], 'kind' => 'preseason',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_created');

        $training = TrainingSession::sole();
        $this->assertSame($u15->id, $training->category_id);
        $this->assertSame([$u15->id, $u17->id], $training->categoryIds());
    }

    #[Test]
    public function an_extra_session_stays_single_category(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];

        $this->actingAs($this->admin())->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u17->id], 'kind' => 'extra',
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$u15->id], TrainingSession::sole()->categoryIds());
    }

    #[Test]
    public function the_slot_check_covers_every_category_of_the_session(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $admin = $this->admin();
        $this->makeTraining($u17, ['kind' => SessionKind::Regular]);

        // U17 already trains at that time: a joint U15 + U17 session is refused, U15 alone is fine.
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'category_ids' => [$u17->id], 'kind' => 'preseason',
        ]))->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);

        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'kind' => 'preseason',
        ]))->assertSessionHasNoErrors();

        // U15 now takes part in that slot, so an extra U15 session there is refused too.
        $this->actingAs($admin)->post(route('attendance.sessions.store'), $this->slot([
            'category_id' => $u15->id, 'kind' => 'extra',
        ]))->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);
    }

    #[Test]
    public function moving_checks_the_slot_for_every_category(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $joint = $this->makeTraining($u15);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $this->makeTraining($u17, ['date' => '2026-09-06', 'kind' => SessionKind::Regular]);

        $this->actingAs($this->admin())->post(route('attendance.sessions.move', $joint), $this->slot(['date' => '2026-09-06']))
            ->assertSessionHasErrors(['start_time' => 'att.error.duplicate']);

        $this->assertSame('2026-09-05', $joint->fresh()->date);
    }

    #[Test]
    public function the_categories_of_an_unmarked_preseason_session_can_be_changed(): void
    {
        [$u15, $u17, $u19] = [$this->category(), $this->category('U17'), $this->category('U19')];
        $training = $this->makeTraining($u15);

        $this->actingAs($this->admin())->put(route('attendance.sessions.categories', $training), ['category_ids' => [$u17->id, $u19->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.training_session_categories_saved');

        $training->refresh();
        // The primary category was removed, so the first chosen one takes over.
        $this->assertSame($u17->id, $training->category_id);
        $this->assertSame([$u17->id, $u19->id], $training->categoryIds());
    }

    #[Test]
    public function categories_cannot_change_once_marked_for_other_kinds_or_into_a_taken_slot(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $admin = $this->admin();

        $regular = $this->makeTraining($u15, ['kind' => SessionKind::Regular, 'date' => '2026-09-01']);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $regular), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.not_preseason']);

        $marked = $this->makeTraining($u15, ['date' => '2026-09-02', 'state' => SessionState::Held]);
        Attendance::create(['training_session_id' => $marked->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $marked), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.already_marked']);

        $this->makeTraining($u17, ['date' => '2026-09-03', 'kind' => SessionKind::Regular]);
        $clash = $this->makeTraining($u15, ['date' => '2026-09-03']);
        $this->actingAs($admin)->put(route('attendance.sessions.categories', $clash), ['category_ids' => [$u15->id, $u17->id]])
            ->assertSessionHasErrors(['category_ids' => 'att.error.duplicate']);

        $this->assertSame([$u15->id], $clash->fresh()->categoryIds());
    }

    #[Test]
    public function changing_categories_needs_attendance_edit(): void
    {
        $u15 = $this->category();
        $training = $this->makeTraining($u15);
        $role = Role::factory()->create(['permissions' => ['attendance' => ['view', 'add']]]);
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => $role->id]);

        $this->actingAs($user)->put(route('attendance.sessions.categories', $training), ['category_ids' => [$u15->id]])
            ->assertForbidden();
    }

    #[Test]
    public function the_session_screen_lists_its_categories_and_offers_all_for_preseason(): void
    {
        [$u15, $u17] = [$this->category(), $this->category('U17')];
        $this->category('U19');
        $this->player($u15);
        $this->player($u17);
        $training = $this->makeTraining($u17);
        $training->categories()->syncWithoutDetaching([$u15->id]);

        $this->actingAs($this->admin())->get(route('attendance.sessions.show', $training))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Session')
                ->has('session.categories', 2)
                ->where('session.categories.0.id', $u17->id)
                ->where('session.category', 'U17 · U15')
                ->has('allCategories', 3)
                ->where('rows.0.category', 'U15'));
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, in `attendance_is_a_module_and_its_routes_map_to_it()`, add after the `attendance.sessions.store` line:
```php
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.sessions.categories'));
```

Note on `rows.0.category`: the roster is ordered by last name and the fixture numbers last names in creation order, so the U15 player (`Test001`) comes first. `session.category` uses `Category::localized_name`, which falls back to `name` for these fixtures.

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter="PreseasonMultiCategoryTest|AttendancePermissionTest"`
Expected: FAIL: route `attendance.sessions.categories` is not defined, and the joint store creates one pivot row only.

- [ ] **Step 3: Controller for store, move and categories**

Replace the whole of `app/Http/Controllers/TrainingSessionController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainingSessionController extends Controller
{
    /**
     * An extra or pre-season session added by hand (regular ones come from the
     * schedule). Only a pre-season session can be shared by several
     * categories; `category_id` is its primary one.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'kind' => ['required', Rule::in([SessionKind::Extra->value, SessionKind::Preseason->value])],
            'title' => ['nullable', 'string', 'max:150'],
            ...$this->slotRules(),
        ]);
        $categoryIds = $data['kind'] === SessionKind::Preseason->value
            ? $this->uniqueIds([(int) $data['category_id'], ...($data['category_ids'] ?? [])])
            : [(int) $data['category_id']];
        $this->assertSlotFree($categoryIds, $data['date'], $data['start_time']);

        try {
            $session = DB::transaction(function () use ($data, $categoryIds, $request) {
                $session = TrainingSession::create(Arr::except($data, 'category_ids') + ['state' => SessionState::Planned]);
                $session->categories()->syncWithoutDetaching($categoryIds);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CREATED, $session, ['kind' => $data['kind']]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return redirect()->route('attendance.sessions.show', $session)->with('success', 'flash.training_session_created');
    }

    /**
     * The categories of a pre-season session, editable until its marks are
     * first saved (the roster is frozen from then on). If the primary
     * category is dropped, the first chosen one becomes primary.
     */
    public function updateCategories(Request $request, TrainingSession $session): RedirectResponse
    {
        $data = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ]);

        $error = match (true) {
            $session->kind !== SessionKind::Preseason => 'att.error.not_preseason',
            $session->state === SessionState::Cancelled => 'att.error.cancelled',
            $session->state === SessionState::Held, $session->attendances()->exists() => 'att.error.already_marked',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['category_ids' => $error]);
        }

        $ids = $this->uniqueIds($data['category_ids']);
        $this->assertSlotFree($ids, $session->date, $session->start_time, $session->id, 'category_ids');
        $primary = in_array($session->category_id, $ids, true) ? $session->category_id : $ids[0];

        try {
            DB::transaction(function () use ($session, $ids, $primary) {
                $session->update(['category_id' => $primary]);
                $session->categories()->sync($ids);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['category_ids' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_categories_saved');
    }

    public function cancel(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['reason' => 'att.error.cancelled']);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($session, $data, $request) {
            $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => $data['reason']]);
            ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_CANCELLED, $session);
        });

        return back()->with('success', 'flash.training_session_cancelled');
    }

    /** `moved_from` keeps the first original date so the generator never recreates that slot. */
    public function move(Request $request, TrainingSession $session): RedirectResponse
    {
        if ($session->state === SessionState::Cancelled) {
            throw ValidationException::withMessages(['date' => 'att.error.cancelled']);
        }
        $data = $request->validate($this->slotRules());
        $this->assertSlotFree($session->categoryIds(), $data['date'], $data['start_time'], $session->id);

        try {
            DB::transaction(function () use ($session, $data, $request) {
                $session->update($data + ['moved_from' => $session->moved_from ?? $session->date]);
                ActivityRecorder::record($request->user(), ActivityAction::TRAINING_SESSION_MOVED, $session);
            });
        } catch (UniqueConstraintViolationException) {
            // Two submits raced past assertSlotFree(); the unique (category, date, start) key caught it.
            throw ValidationException::withMessages(['start_time' => 'att.error.duplicate']);
        }

        return back()->with('success', 'flash.training_session_moved');
    }

    private function slotRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }

    /** @return array<int, int> */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * No other session that any of these categories takes part in may start
     * at the same date and time. The pivot holds every category, primary or
     * not, so this also covers the P1 unique (category, date, start) key.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertSlotFree(array $categoryIds, string $date, string $startTime, ?int $ignoreId = null, string $errorKey = 'start_time'): void
    {
        $taken = TrainingSession::where('date', $date)->where('start_time', $startTime)
            ->whereIn('id', DB::table('training_session_category')->select('training_session_id')->whereIn('category_id', $categoryIds))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$errorKey => 'att.error.duplicate']);
        }
    }
}
```

- [ ] **Step 4: Route**

In `routes/web.php`, after
```php
        Route::post('/attendance/sessions/{session}/move', [TrainingSessionController::class, 'move'])->name('attendance.sessions.move');
```
add
```php
        Route::put('/attendance/sessions/{session}/categories', [TrainingSessionController::class, 'updateCategories'])->name('attendance.sessions.categories');
```
No `config/permissions.php` change: the last segment `categories` derives `edit`.

- [ ] **Step 5: Session screen props**

Replace the whole of `app/Http/Controllers/AttendanceController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Category;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /** Month calendar of one category. Opening a month generates its planned sessions. */
    public function index(Request $request, SessionGenerator $generator): Response
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));

        $sessions = collect();
        $preseason = null;
        $hasSchedule = false;

        if ($categoryId !== null) {
            $generator->forMonth($categoryId, $anchor->year, $anchor->month);

            $sessions = TrainingSession::where('category_id', $categoryId)
                ->whereBetween('date', [$anchor->startOfMonth()->toDateString(), $anchor->endOfMonth()->toDateString()])
                ->withCount('attendances')
                ->orderBy('date')->orderBy('start_time')
                ->get()
                ->map(fn (TrainingSession $s) => [
                    'id' => $s->id, 'date' => $s->date, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
                    'kind' => $s->kind->value, 'state' => $s->state->value, 'title' => $s->title,
                    'marked' => $s->attendances_count,
                ]);

            $season = Season::forDate($anchor);
            $preseason = [
                'season' => $season->label(),
                'done' => TrainingSession::where('category_id', $categoryId)
                    ->where('kind', SessionKind::Preseason->value)
                    ->where('state', SessionState::Held->value)
                    ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()])
                    ->count(),
                'target' => PreseasonTarget::where('category_id', $categoryId)
                    ->where('season_start_year', $season->startYear)->value('target_count'),
            ];
            $hasSchedule = TrainingSchedule::where('category_id', $categoryId)->exists();
        }

        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'month' => $anchor->format('Y-m'),
            'sessions' => $sessions,
            'preseason' => $preseason,
            'hasSchedule' => $hasSchedule,
        ]);
    }

    /** One session: its roster with marks (everyone present until first saved), its categories and its log. */
    public function show(TrainingSession $session, Roster $roster): Response
    {
        $session->load('categories');
        $marks = $session->attendances()->get()->keyBy('player_id');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->categories
            ->sortBy(fn (Category $c) => $c->id === $session->category_id ? 0 : $c->id)
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;

        return Inertia::render('Attendance/Session', [
            'session' => [
                'id' => $session->id, 'date' => $session->date, 'start_time' => $session->start_time, 'end_time' => $session->end_time,
                'kind' => $session->kind->value, 'state' => $session->state->value, 'cancel_reason' => $session->cancel_reason,
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'title' => $session->title, 'notes' => $session->notes,
                'category' => $categories->implode(' · '),
                'category_id' => $session->category_id,
                'categories' => $categories->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            ],
            'saved' => $marks->isNotEmpty(),
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => trim("{$p->lastname} {$p->firstname}"),
                    // Where a player comes from only matters when several categories share the session.
                    'category' => $joint ? $categories->get($p->category_id) : null,
                    'status' => $mark?->status->value ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            'candidates' => Player::where('archived', false)->whereNull('left_at')
                ->whereNotIn('id', $players->modelKeys())
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname'])
                ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]),
            'lastCoach' => TrainingSession::where('category_id', $session->category_id)
                ->whereKeyNot($session->id)->whereNotNull('coach')
                ->orderByDesc('date')->value('coach'),
            'statuses' => AttendanceStatus::values(),
            'reasons' => AbsenceReason::values(),
            'allCategories' => $session->kind === SessionKind::Preseason
                ? Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values()
                : [],
        ]);
    }

    public function saveMarks(SaveAttendanceMarksRequest $request, TrainingSession $session, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $recorder->save($session, $data['marks'], $request->user(), [
            'coach' => $data['coach'] ?? null, 'title' => $data['title'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'flash.attendance_saved');
    }
}
```

- [ ] **Step 6: The add-session modal**

Create `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`:
```vue
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    kind: { type: String, default: 'extra' }, // extra | preseason
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // pre-selected primary category
    date: { type: String, required: true }, // 'Y-m-d'
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const form = useForm({ category_id: null, category_ids: [], kind: 'extra', date: '', start_time: '18:00', end_time: '19:30', title: '' });
watch(() => props.show, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
    Object.assign(form, {
        category_id: props.categoryId ?? props.categories[0]?.id ?? null,
        category_ids: [],
        kind: props.kind,
        date: props.date,
    });
});

// Only a pre-season session can be shared; the primary category is always part of it.
const others = computed(() => props.categories.filter((c) => c.id !== form.category_id));
function submit() {
    form
        .transform((d) => ({
            ...d,
            category_ids: d.kind === 'preseason' ? [d.category_id, ...d.category_ids.filter((id) => id !== d.category_id)] : [],
            title: d.title || null,
        }))
        .post(route('attendance.sessions.store'), { onSuccess: () => emit('close') });
}

const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form class="space-y-3 p-5" @submit.prevent="submit">
            <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ form.kind === 'preseason' ? t('att.add_preseason') : t('att.add_extra') }}</h2>
            <label class="block text-sm">{{ t('att.category') }}
                <select v-model="form.category_id" :class="[input, 'mt-1 block w-full']">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <fieldset v-if="form.kind === 'preseason' && others.length" class="text-sm">
                <legend class="mb-1">{{ t('att.other_categories') }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="c in others" :key="c.id" class="inline-flex items-center gap-1.5">
                        <input v-model="form.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ c.name }}
                    </label>
                </div>
            </fieldset>
            <label class="block text-sm">{{ t('att.date') }}<input v-model="form.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
            <div class="flex gap-2">
                <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="form.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="form.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
            </div>
            <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="form.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :class="[input, 'mt-1 block w-full']" /></label>
            <InputError v-for="(e, k) in form.errors" :key="k" :message="tr(e)" />
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="emit('close')">{{ t('att.close') }}</button>
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
            </div>
        </form>
    </Modal>
</template>
```

- [ ] **Step 7: Calendar page uses the modal**

Replace the whole of `resources/js/Pages/Attendance/Index.vue` with:
```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import AddSessionModal from './Partials/AddSessionModal.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
});
const { t, locale } = useI18n();
const { can } = useCan();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayKey = key(new Date());
const anchor = computed(() => new Date(`${props.month}-01T00:00:00`));
const monthLabel = computed(() => anchor.value.toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(2024, 0, 1 + i)).toLocaleDateString(lang.value, { weekday: 'short', timeZone: 'UTC' })));

const byDate = computed(() => props.sessions.reduce((acc, s) => ((acc[s.date] ??= []).push(s), acc), {}));

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = anchor.value;
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const k = key(d);
        return { key: k, day: d.getDate(), inMonth: d.getMonth() === first.getMonth(), sessions: byDate.value[k] ?? [] };
    });
});

function visit(params) {
    router.get(route('attendance.index'), { category_id: props.categoryId, month: props.month, ...params }, { preserveScroll: true });
}
function shift(delta) {
    const d = new Date(anchor.value);
    d.setMonth(d.getMonth() + delta);
    visit({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

const chip = {
    planned: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};
const kindDot = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };

const preseasonLabel = computed(() => {
    if (!props.preseason) return '';
    const { season, done, target } = props.preseason;
    return target === null ? t('att.preseason_no_target', { season, done }) : t('att.preseason_progress', { season, done, target });
});

// ---- Add an extra / pre-season session ----
const showCreate = ref(false);
const createKind = ref('extra');
function openCreate(kind) {
    createKind.value = kind;
    showCreate.value = true;
}
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h1>
                <div class="flex gap-2">
                    <Link v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.grid') }}</Link>
                    <Link v-if="can('attendance', 'edit')" :href="route('attendance.settings')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="settings" />{{ t('att.settings') }}</Link>
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <select :value="categoryId" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" @click="shift(-1)"><Icon name="back" /></button>
                    <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" @click="shift(1)"><Icon name="back" /></button>
                    <span v-if="preseason" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ preseasonLabel }}</span>
                </div>
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <button class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700" @click="openCreate('extra')">+ {{ t('att.add_extra') }}</button>
                    <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600" @click="openCreate('preseason')">+ {{ t('att.add_preseason') }}</button>
                </div>
            </div>

            <p v-if="!hasSchedule" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.no_schedule') }}</p>

            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-800">
                <div v-for="w in weekdays" :key="w" class="bg-slate-50 p-2 text-center text-xs font-semibold text-slate-500 dark:bg-slate-900">{{ w }}</div>
                <div v-for="cell in cells" :key="cell.key" class="min-h-[5.5rem] bg-white p-1.5 dark:bg-slate-900" :class="{ 'opacity-40': !cell.inMonth }">
                    <div class="mb-1 text-xs font-semibold" :class="cell.key === todayKey ? 'text-primary-600' : 'text-slate-400'">{{ cell.day }}</div>
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="[t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.title].filter(Boolean).join(' · ')">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                <span v-for="(cls, kind) in kindDot" :key="kind" class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="cls"></span>{{ t(`att.kind.${kind}`) }}</span>
            </div>
        </div>

        <AddSessionModal :show="showCreate" :kind="createKind" :categories="categories" :category-id="categoryId" :date="todayKey" @close="showCreate = false" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Session screen shows and edits the categories**

Replace the whole of `resources/js/Pages/Attendance/Session.vue` with:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    session: { type: Object, required: true },
    saved: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    lastCoach: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    allCategories: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const cancelled = computed(() => props.session.state === 'cancelled');
const editable = computed(() => can('attendance', 'edit') && !cancelled.value);
const dateLabel = computed(() => new Date(`${props.session.date}T00:00:00`).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

const rows = ref(props.rows.map((r) => ({ ...r, note: r.note ?? '' })));
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', title: props.session.title ?? '', notes: props.session.notes ?? '' });

const takesMinutes = (s) => s === 'late' || s === 'left_early';
const takesReason = (s) => s === 'absent_excused' || s === 'not_training';
function setStatus(row, status) {
    row.status = status;
    if (!takesMinutes(status)) row.minutes = null;
    if (!takesReason(status)) row.reason = null;
    if (status === 'absent_excused' && !row.reason) row.reason = 'other';
}
const allPresent = () => rows.value.forEach((r) => setStatus(r, 'present'));

const statusStyle = {
    present: 'bg-emerald-600 text-white', late: 'bg-amber-500 text-white', left_early: 'bg-orange-500 text-white',
    not_training: 'bg-sky-600 text-white', absent_excused: 'bg-slate-500 text-white', absent_unexcused: 'bg-rose-600 text-white',
};
const counts = computed(() => rows.value.reduce((acc, r) => ((acc[r.status] = (acc[r.status] ?? 0) + 1), acc), {}));

const pick = ref('');
function addPlayer() {
    const p = props.candidates.find((c) => c.id === Number(pick.value));
    if (p && !rows.value.some((r) => r.player_id === p.id)) rows.value.push({ player_id: p.id, name: p.name, category: null, status: 'present', minutes: null, reason: null, note: '' });
    pick.value = '';
}
const removeRow = (row) => (rows.value = rows.value.filter((r) => r !== row));

const saving = ref(false);
function save() {
    router.put(route('attendance.sessions.marks', props.session.id), {
        ...log,
        marks: rows.value.map(({ name, category, ...mark }) => mark),
    }, { preserveScroll: true, preserveState: 'errors', onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}
const rowError = (i) => ['minutes', 'reason', 'note', 'status'].map((f) => errors.value[`marks.${i}.${f}`]).find(Boolean);

// ---- Cancel / move ----
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const submitCancel = () => cancelForm.post(route('attendance.sessions.cancel', props.session.id), { onSuccess: () => (showCancel.value = false) });
const showMove = ref(false);
const moveForm = useForm({ date: props.session.date, start_time: props.session.start_time, end_time: props.session.end_time });
const submitMove = () => moveForm.post(route('attendance.sessions.move', props.session.id), { onSuccess: () => (showMove.value = false) });

// ---- Categories of a pre-season session: editable until its marks are first saved ----
const canEditCategories = computed(() => editable.value && props.session.kind === 'preseason' && !props.saved);
const showCategories = ref(false);
const categoriesForm = useForm({ category_ids: [] });
function openCategories() {
    categoriesForm.category_ids = props.session.categories.map((c) => c.id);
    categoriesForm.clearErrors();
    showCategories.value = true;
}
// 'errors' keeps the modal open on a validation error; a success remounts the page with the new roster.
const submitCategories = () => categoriesForm.put(route('attendance.sessions.categories', props.session.id), {
    preserveScroll: true,
    preserveState: 'errors',
    onSuccess: () => (showCategories.value = false),
});

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7) })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <div>
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ session.category }} · {{ t(`att.kind.${session.kind}`) }}</h1>
                        <p class="text-sm capitalize text-slate-500">{{ dateLabel }} · <span dir="ltr">{{ session.start_time }}–{{ session.end_time }}</span> · {{ t(`att.state.${session.state}`) }}</p>
                        <p v-if="session.moved_from" class="text-xs text-slate-400">{{ t('att.moved_from', { date: session.moved_from }) }}</p>
                    </div>
                </div>
                <div v-if="editable" class="flex gap-2">
                    <button v-if="canEditCategories" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-900" @click="openCategories">{{ t('att.edit_categories') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                </div>
            </div>
        </template>

        <p v-if="cancelled" class="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.cancelled_because', { reason: session.cancel_reason }) }}</p>
        <p v-else-if="!saved" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.not_saved') }}</p>
        <InputError :message="tr(errors.session)" class="mb-2" />

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span v-for="s in statuses" :key="s" v-show="counts[s]" class="rounded-full px-2 py-0.5" :class="statusStyle[s]">{{ t(`att.status.${s}`) }}: {{ counts[s] }}</span>
                    </div>
                    <button v-if="editable" class="rounded-lg px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-900" @click="allPresent">{{ t('att.all_present') }}</button>
                </div>
                <ul>
                    <li v-for="(row, i) in rows" :key="row.player_id" class="border-b border-slate-100 p-3 last:border-0 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="min-w-[10rem] flex-1 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.category" class="ms-1 text-xs font-normal text-slate-400">{{ row.category }}</span>
                            </span>
                            <button v-for="s in statuses" :key="s" type="button" :disabled="!editable" class="rounded-lg px-2 py-1 text-xs font-semibold" :class="row.status === s ? statusStyle[s] : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'" @click="setStatus(row, s)">{{ t(`att.status.${s}`) }}</button>
                            <button v-if="editable" type="button" class="p-1 text-slate-300 hover:text-rose-600" :title="t('att.remove')" @click="removeRow(row)"><Icon name="trash" /></button>
                        </div>
                        <div v-if="takesMinutes(row.status) || takesReason(row.status) || row.note" class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-if="takesMinutes(row.status)" class="text-xs text-slate-500">{{ t('att.minutes') }}
                                <input v-model.number="row.minutes" type="number" min="1" max="600" :disabled="!editable" :class="[input, 'ms-1 w-20']" />
                            </label>
                            <label v-if="takesReason(row.status)" class="text-xs text-slate-500">{{ t('att.reason') }}
                                <select v-model="row.reason" :disabled="!editable" :class="[input, 'ms-1']">
                                    <option :value="null">—</option>
                                    <option v-for="r in reasons" :key="r" :value="r">{{ t(`att.reason.${r}`) }}</option>
                                </select>
                            </label>
                            <input v-model="row.note" type="text" maxlength="255" :placeholder="t('att.note')" :disabled="!editable" :class="[input, 'min-w-[12rem] flex-1']" />
                        </div>
                        <InputError :message="rowError(i)" />
                    </li>
                </ul>
                <div v-if="editable && candidates.length" class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                    <select v-model="pick" :class="[input, 'flex-1']" :aria-label="t('att.add_player')">
                        <option value="">{{ t('att.add_player') }}…</option>
                        <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button type="button" class="rounded-lg px-3 text-sm font-semibold text-primary-600 ring-1 ring-primary-200 dark:ring-primary-900" :disabled="!pick" @click="addPlayer">{{ t('att.add') }}</button>
                </div>
            </section>

            <section class="h-fit space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="log.coach" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="log.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="log.notes" rows="4" maxlength="2000" :disabled="!editable" :class="[input, 'mt-1 block w-full']"></textarea></label>
                <InputError :message="tr(errors.marks)" />
                <button v-if="editable" type="button" :disabled="saving || !rows.length" class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </section>
        </div>

        <Modal :show="showCancel" max-width="md" @close="showCancel = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCancel">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.cancel') }}</h2>
                <label class="block text-sm">{{ t('att.cancel_reason') }}<input v-model="cancelForm.reason" type="text" maxlength="255" :class="[input, 'mt-1 block w-full']" /></label>
                <InputError :message="tr(cancelForm.errors.reason)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCancel = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="cancelForm.processing" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('att.cancel') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showMove" max-width="md" @close="showMove = false">
            <form class="space-y-3 p-5" @submit.prevent="submitMove">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.move') }}</h2>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="moveForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="moveForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="moveForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in moveForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showMove = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="moveForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showCategories" max-width="md" @close="showCategories = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCategories">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.categories') }}</h2>
                <p class="text-xs text-slate-500">{{ t('att.categories_help') }}</p>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label v-for="c in allCategories" :key="c.id" class="inline-flex items-center gap-1.5">
                        <input v-model="categoriesForm.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ c.name }}
                    </label>
                </div>
                <InputError v-for="(e, k) in categoriesForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCategories = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="categoriesForm.processing || !categoriesForm.category_ids.length" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 9: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.categories': ['Categories', 'Catégories', 'الفئات'],
    'att.other_categories': ['Other categories taking part', 'Autres catégories participantes', 'فئات أخرى مشاركة'],
    'att.edit_categories': ['Edit categories', 'Modifier les catégories', 'تعديل الفئات'],
    'att.categories_help': ['Categories can be changed until attendance is first saved.', 'Les catégories peuvent être modifiées tant que les présences ne sont pas enregistrées.', 'يمكن تعديل الفئات ما دام الحضور لم يُسجَّل بعد.'],
    'att.error.not_preseason': ['Only pre-season sessions can have several categories.', 'Seules les séances de préparation peuvent regrouper plusieurs catégories.', 'حصص التحضير البدني وحدها يمكن أن تضم عدة فئات.'],
    'att.error.already_marked': ['Attendance has already been saved for this session.', 'Les présences de cette séance sont déjà enregistrées.', 'تم تسجيل الحضور لهذه الحصة من قبل.'],
    'flash.training_session_categories_saved': ['Categories saved.', 'Catégories enregistrées.', 'تم حفظ الفئات.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 10: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="PreseasonMultiCategoryTest|AttendancePermissionTest|AttendanceCalendarTest|AttendanceSessionTest|TrainingSessionTitleTest|FlashTranslationTest" && npm run i18n:check`
Expected: PASS. The P1 race tests in `AttendanceCalendarTest` still pass: the unique key still catches a raced duplicate.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/TrainingSessionController.php app/Http/Controllers/AttendanceController.php routes/web.php resources/js/Pages/Attendance/Partials/AddSessionModal.vue resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Session.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/PreseasonMultiCategoryTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): joint pre-season sessions across categories" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---
### Task 4: Calendars and pre-season counting through the pivot

**Files:**
- Create: `app/Services/Attendance/PreseasonProgress.php`, `app/Services/Attendance/CalendarFeed.php`, `app/Http/Controllers/AttendanceCalendarController.php`
- Modify: `app/Http/Controllers/AttendanceController.php` (the calendar leaves it), `routes/web.php`, `resources/js/Pages/Attendance/Index.vue`
- Test: `tests/Feature/AttendanceJointCalendarTest.php` (new)

**Interfaces:**
- Consumes: `TrainingSession::includingCategory()`, `categories()` (Task 2); `title` (Task 1).
- Produces: `PreseasonProgress::forCategory(int $categoryId, DateTimeInterface|string $date): array{season: string, done: int, target: ?int}`, counting held pre-season sessions whose pivot includes the category, in the season of `$date`.
- Produces: `CalendarFeed::sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null): Collection` of arrays `{id, date, start_time, end_time, kind, state, title, cancel_reason, categories: {id, name}[] (primary first), marked: int, summary: array<status,int>|null}` ordered by date, start time, id. `summary` is set only for held sessions.
- Produces: `CalendarFeed::summaries(array $sessionIds): array<int, array<string, int>>`.
- Produces: `AttendanceCalendarController::index` behind the unchanged route name `attendance.index`, with the same props as before (`categories`, `categoryId`, `month`, `sessions`, `preseason`, `hasSchedule`); `sessions` now has the `CalendarFeed` shape.
- Produces: `AttendanceController` keeps `show()` and `saveMarks()` only.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceJointCalendarTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Services\Attendance\PreseasonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceJointCalendarTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function a_category_calendar_lists_the_joint_sessions_it_takes_part_in(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Held, 'title' => 'Running 7.2 km in 40 min',
        ]);
        $training->categories()->syncWithoutDetaching([$u17->id]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $this->player($u17)->id, 'status' => AttendanceStatus::Late, 'minutes' => 5]);

        $this->actingAs($this->admin())->get(route('attendance.index', ['category_id' => $u17->id, 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->has('sessions', 1)
                ->where('sessions.0.id', $training->id)
                ->where('sessions.0.title', 'Running 7.2 km in 40 min')
                ->has('sessions.0.categories', 2)
                ->where('sessions.0.categories.0.id', $u15->id)
                ->where('sessions.0.marked', 1)
                ->where('sessions.0.summary.late', 1)
                ->where('preseason.done', 1));
    }

    #[Test]
    public function a_joint_preseason_session_counts_for_every_category_in_it(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        PreseasonTarget::create(['category_id' => $u17->id, 'season_start_year' => 2026, 'target_count' => 10]);
        $base = ['category_id' => $u15->id, 'start_time' => '09:00', 'end_time' => '10:30', 'kind' => SessionKind::Preseason];

        $states = ['2026-09-02' => SessionState::Held, '2026-09-03' => SessionState::Held, '2026-09-04' => SessionState::Planned, '2026-09-05' => SessionState::Cancelled];
        foreach ($states as $date => $state) {
            TrainingSession::create($base + ['date' => $date, 'state' => $state])->categories()->syncWithoutDetaching([$u17->id]);
        }
        TrainingSession::create($base + ['date' => '2026-09-06', 'state' => SessionState::Held]); // U15 only
        TrainingSession::create($base + ['date' => '2025-09-06', 'state' => SessionState::Held]); // last season

        $progress = app(PreseasonProgress::class);

        $this->assertSame(['season' => '2026/27', 'done' => 2, 'target' => 10], $progress->forCategory($u17->id, '2026-10-01'));
        $this->assertSame(3, $progress->forCategory($u15->id, '2026-10-01')['done']);
        $this->assertNull($progress->forCategory($u15->id, '2026-10-01')['target']);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceJointCalendarTest`
Expected: FAIL: `Class "App\Services\Attendance\PreseasonProgress" not found`, and the U17 calendar has no session.

- [ ] **Step 3: Pre-season progress service**

Create `app/Services/Attendance/PreseasonProgress.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Support\Season;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pre-season progress per category and season. A joint pre-season session
 * counts once for every category in it (owner decision), so this reads the
 * session-category pivot, never `training_sessions.category_id` alone.
 */
final class PreseasonProgress
{
    /** @return array{season: string, done: int, target: ?int} */
    public function forCategory(int $categoryId, DateTimeInterface|string $date): array
    {
        $season = Season::forDate($date);

        return [
            'season' => $season->label(),
            'done' => $this->held($categoryId, $season)->count(),
            'target' => PreseasonTarget::where('category_id', $categoryId)
                ->where('season_start_year', $season->startYear)->value('target_count'),
        ];
    }

    /** Held pre-season sessions of the season that include the category. */
    private function held(int $categoryId, Season $season): Builder
    {
        return TrainingSession::query()
            ->includingCategory($categoryId)
            ->where('kind', SessionKind::Preseason->value)
            ->where('state', SessionState::Held->value)
            ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()]);
    }
}
```

- [ ] **Step 4: Calendar feed service**

Create `app/Services/Attendance/CalendarFeed.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Sessions shaped for the calendar views: every category taking part
 * (primary first), title, marked count and, once held, how many players had
 * each status.
 */
final class CalendarFeed
{
    /** @return Collection<int, array<string, mixed>> */
    public function sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null): Collection
    {
        $sessions = TrainingSession::query()
            ->when($categoryId !== null, fn ($q) => $q->includingCategory($categoryId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->whereBetween('date', [$from, $to])
            ->with('categories')
            ->withCount('attendances')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $summaries = $this->summaries(
            $sessions->filter(fn (TrainingSession $s) => $s->state === SessionState::Held)->modelKeys(),
        );

        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'kind' => $s->kind->value,
            'state' => $s->state->value,
            'title' => $s->title,
            'cancel_reason' => $s->cancel_reason,
            'categories' => $s->categories
                ->sortBy(fn (Category $c) => $c->id === $s->category_id ? 0 : $c->id)
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])
                ->values()->all(),
            'marked' => $s->attendances_count,
            'summary' => $summaries[$s->id] ?? null,
        ])->values();
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, array<string, int>> per session: status => number of players
     */
    public function summaries(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $rows = Attendance::query()
            ->whereIn('training_session_id', $sessionIds)
            ->selectRaw('training_session_id, status, count(*) as total')
            ->groupBy('training_session_id', 'status')
            ->toBase()
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row->training_session_id][$row->status] = (int) $row->total;
        }

        return $summaries;
    }
}
```

- [ ] **Step 5: Calendar controller**

Create `app/Http/Controllers/AttendanceCalendarController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Attendance\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** The attendance calendar (route `attendance.index`). */
class AttendanceCalendarController extends Controller
{
    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly CalendarFeed $feed,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();

        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            ...$this->month($data, $categories),
        ]);
    }

    /** One category's month. Opening it generates its planned sessions. */
    private function month(array $data, Collection $categories): array
    {
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
        $props = ['categoryId' => $categoryId, 'month' => $anchor->format('Y-m'), 'sessions' => [], 'preseason' => null, 'hasSchedule' => false];

        if ($categoryId === null) {
            return $props;
        }

        $this->generator->forMonth($categoryId, $anchor->year, $anchor->month);

        return [
            ...$props,
            'sessions' => $this->feed->sessions($anchor->toDateString(), $anchor->endOfMonth()->toDateString(), $categoryId),
            'preseason' => $this->preseason->forCategory($categoryId, $anchor),
            'hasSchedule' => TrainingSchedule::where('category_id', $categoryId)->exists(),
        ];
    }
}
```

- [ ] **Step 6: Route the calendar to the new controller; drop index from AttendanceController**

In `routes/web.php`, add this import on the line **before** `use App\Http\Controllers\AttendanceController;` (imports are alphabetical, and `AttendanceCalendarController` sorts first):
```php
use App\Http\Controllers\AttendanceCalendarController;
```
Then replace
```php
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
```
with
```php
        Route::get('/attendance', [AttendanceCalendarController::class, 'index'])->name('attendance.index');
```

Replace the whole of `app/Http/Controllers/AttendanceController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Http\Requests\SaveAttendanceMarksRequest;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\Roster;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The session screen. The calendar lives in AttendanceCalendarController. */
class AttendanceController extends Controller
{
    /** One session: its roster with marks (everyone present until first saved), its categories and its log. */
    public function show(TrainingSession $session, Roster $roster): Response
    {
        $session->load('categories');
        $marks = $session->attendances()->get()->keyBy('player_id');
        $players = $roster->forSession($session);
        // Primary category first, then the others of a joint pre-season session.
        $categories = $session->categories
            ->sortBy(fn (Category $c) => $c->id === $session->category_id ? 0 : $c->id)
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->localized_name]);
        $joint = $categories->count() > 1;

        return Inertia::render('Attendance/Session', [
            'session' => [
                'id' => $session->id, 'date' => $session->date, 'start_time' => $session->start_time, 'end_time' => $session->end_time,
                'kind' => $session->kind->value, 'state' => $session->state->value, 'cancel_reason' => $session->cancel_reason,
                'moved_from' => $session->moved_from, 'coach' => $session->coach, 'title' => $session->title, 'notes' => $session->notes,
                'category' => $categories->implode(' · '),
                'category_id' => $session->category_id,
                'categories' => $categories->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            ],
            'saved' => $marks->isNotEmpty(),
            'rows' => $players->map(function (Player $p) use ($marks, $categories, $joint) {
                $mark = $marks->get($p->id);

                return [
                    'player_id' => $p->id,
                    'name' => trim("{$p->lastname} {$p->firstname}"),
                    // Where a player comes from only matters when several categories share the session.
                    'category' => $joint ? $categories->get($p->category_id) : null,
                    'status' => $mark?->status->value ?? AttendanceStatus::Present->value,
                    'minutes' => $mark?->minutes,
                    'reason' => $mark?->reason?->value,
                    'note' => $mark?->note,
                ];
            })->values(),
            'candidates' => Player::where('archived', false)->whereNull('left_at')
                ->whereNotIn('id', $players->modelKeys())
                ->orderBy('lastname')->orderBy('firstname')
                ->get(['id', 'firstname', 'lastname'])
                ->map(fn (Player $p) => ['id' => $p->id, 'name' => trim("{$p->lastname} {$p->firstname}")]),
            'lastCoach' => TrainingSession::where('category_id', $session->category_id)
                ->whereKeyNot($session->id)->whereNotNull('coach')
                ->orderByDesc('date')->value('coach'),
            'statuses' => AttendanceStatus::values(),
            'reasons' => AbsenceReason::values(),
            'allCategories' => $session->kind === SessionKind::Preseason
                ? Category::orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values()
                : [],
        ]);
    }

    public function saveMarks(SaveAttendanceMarksRequest $request, TrainingSession $session, MarkRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $recorder->save($session, $data['marks'], $request->user(), [
            'coach' => $data['coach'] ?? null, 'title' => $data['title'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'flash.attendance_saved');
    }
}
```

- [ ] **Step 7: Month chips show joint categories**

In `resources/js/Pages/Attendance/Index.vue`, replace
```js
const kindDot = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };
```
with
```js
const kindDot = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title]
    .filter(Boolean).join(' · ');
```
and replace the chip
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="[t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.title].filter(Boolean).join(' · ')">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
```
with
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
```

- [ ] **Step 8: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceJointCalendarTest|AttendanceCalendarTest|AttendanceSettingsPageTest|AttendanceGridTest|AttendancePermissionTest|PreseasonMultiCategoryTest|TrainingSessionTitleTest"`
Expected: PASS. The P1 calendar test (`opening_a_month_generates_and_lists_its_sessions_with_preseason_progress`) still sees 4 sessions and `preseason.done` 1.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/PreseasonProgress.php app/Services/Attendance/CalendarFeed.php app/Http/Controllers/AttendanceCalendarController.php app/Http/Controllers/AttendanceController.php routes/web.php resources/js/Pages/Attendance/Index.vue tests/Feature/AttendanceJointCalendarTest.php
git commit -m "feat(attendance): calendars and pre-season progress count joint sessions" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Configurable codes, colours and names in settings

**Files:**
- Modify: `app/Support/AttendanceSettings.php`, `app/Services/Attendance/AttendanceCode.php`, `app/Http/Controllers/AttendanceSettingsController.php`, `app/Http/Controllers/AttendanceGridController.php`
- Modify: `resources/js/Pages/Attendance/Settings.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Unit/AttendanceCodeTest.php` (rewritten), `tests/Feature/AttendanceCodeSettingsTest.php` (new)

**Interfaces:**
- Produces: `AttendanceSettings::LOCALES = ['ar', 'fr', 'en']`; `AttendanceSettings::DEFAULTS['codes']` = `status => ['code' => string, 'color' => '#rrggbb', 'label' => ['ar' => ?string, 'fr' => ?string, 'en' => ?string]]` for the six statuses (P, R, D, B, AE, AN and the spec's colours); `AttendanceSettings::codes(): array` (that block, merged over the defaults).
- Produces: `AttendanceCode` instances: `AttendanceCode::fromSettings(): self`, `AttendanceCode::fromConfig(array $codesBlock): self`, `AttendanceCode::normalise(?string $code): string` (all whitespace removed, `mb_strtoupper`), `->parse(?string $code): ?array{status: AttendanceStatus, minutes: ?int}`, `->format(AttendanceStatus $status, ?int $minutes): string`. The static `parse()` / `format()` are gone.
- Produces: `PUT attendance.settings.update` validates `codes.<status>.code` (1–3 letters `\p{L}`, unique case-insensitively), `codes.<status>.color` (`#rrggbb`), `codes.<status>.label.<ar|fr|en>` (nullable, max 40). Errors: `att.error.code_format`, `att.error.code_taken`, `att.error.color_format`. Codes are stored upper-cased, colours lower-cased, empty names as `null`.
- Produces: i18n keys `att.codes`, `att.codes_help`, `att.col.status`, `att.col.code`, `att.col.color`, `att.col.label_ar`, `att.col.label_fr`, `att.col.label_en`, `att.col.points`, `att.error.code_format`, `att.error.code_taken`, `att.error.color_format`.

- [ ] **Step 1: Write the failing tests**

Replace the whole of `tests/Unit/AttendanceCodeTest.php` with:
```php
<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceCode;
use App\Support\AttendanceSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceCodeTest extends TestCase
{
    private static function defaults(): AttendanceCode
    {
        return AttendanceCode::fromConfig(AttendanceSettings::DEFAULTS['codes']);
    }

    private static function configured(array $codes): AttendanceCode
    {
        $config = AttendanceSettings::DEFAULTS['codes'];
        foreach ($codes as $status => $code) {
            $config[$status]['code'] = $code;
        }

        return AttendanceCode::fromConfig($config);
    }

    public static function valid(): array
    {
        return [
            'empty is present' => ['', AttendanceStatus::Present, null],
            'null is present' => [null, AttendanceStatus::Present, null],
            'P' => ['p', AttendanceStatus::Present, null],
            'late' => ['R15', AttendanceStatus::Late, 15],
            'late spaced' => [' r 5 ', AttendanceStatus::Late, 5],
            'left early' => ['D10', AttendanceStatus::LeftEarly, 10],
            'late at the cap' => ['R600', AttendanceStatus::Late, 600],
            'not training' => ['B', AttendanceStatus::NotTraining, null],
            'excused' => ['ae', AttendanceStatus::AbsentExcused, null],
            'unexcused' => ['AN', AttendanceStatus::AbsentUnexcused, null],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_parses_the_default_codes(?string $code, AttendanceStatus $status, ?int $minutes): void
    {
        $this->assertSame(['status' => $status, 'minutes' => $minutes], self::defaults()->parse($code));
    }

    #[Test]
    public function it_rejects_unknown_codes_late_without_minutes_and_non_latin_digits(): void
    {
        foreach (['X', 'R', 'R0', 'D', 'R1000', 'R601', 'A', 'P5', 'R١٥'] as $code) {
            $this->assertNull(self::defaults()->parse($code), $code);
        }
    }

    #[Test]
    public function format_round_trips(): void
    {
        foreach (['P', 'R15', 'D10', 'B', 'AE', 'AN'] as $code) {
            $parsed = self::defaults()->parse($code);
            $this->assertSame($code, self::defaults()->format($parsed['status'], $parsed['minutes']));
        }
    }

    #[Test]
    public function configured_codes_replace_the_defaults_in_any_script(): void
    {
        $codes = self::configured([
            'present' => 'ح', 'late' => 'ت', 'left_early' => 'غم',
            'not_training' => 'م', 'absent_excused' => 'غع', 'absent_unexcused' => 'غ',
        ]);

        $this->assertSame(['status' => AttendanceStatus::Late, 'minutes' => 15], $codes->parse('ت15'));
        $this->assertSame(['status' => AttendanceStatus::LeftEarly, 'minutes' => 10], $codes->parse(' غم 10 '));
        $this->assertSame(['status' => AttendanceStatus::AbsentUnexcused, 'minutes' => null], $codes->parse('غ'));
        $this->assertSame(['status' => AttendanceStatus::AbsentExcused, 'minutes' => null], $codes->parse('غع'));
        $this->assertNull($codes->parse('P'));
        $this->assertNull($codes->parse('R15'));
        $this->assertSame('ت15', $codes->format(AttendanceStatus::Late, 15));
        $this->assertSame('غ', $codes->format(AttendanceStatus::AbsentUnexcused, null));
    }

    #[Test]
    public function codes_compare_ignoring_case(): void
    {
        $codes = self::configured(['present' => 'pr', 'late' => 'rt']);

        $this->assertSame(AttendanceStatus::Present, $codes->parse('Pr')['status']);
        $this->assertSame(['status' => AttendanceStatus::Late, 'minutes' => 5], $codes->parse('rt5'));
        $this->assertSame('PR', $codes->format(AttendanceStatus::Present, null));
    }
}
```

Create `tests/Feature/AttendanceCodeSettingsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCodeSettingsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    /** The whole settings form with some code rows changed. */
    private function payload(array $codes = []): array
    {
        $payload = AttendanceSettings::DEFAULTS;
        $payload['codes'] = array_replace_recursive($payload['codes'], $codes);

        return $payload;
    }

    #[Test]
    public function codes_colours_and_names_are_saved_normalised(): void
    {
        $this->actingAs($this->admin())->put(route('attendance.settings.update'), $this->payload([
            'present' => ['code' => 'ح', 'color' => '#10B981', 'label' => ['ar' => 'حاضر في الوقت', 'fr' => '', 'en' => null]],
            'late' => ['code' => 'rt'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_settings_saved');

        $codes = AttendanceSettings::codes();
        $this->assertSame('ح', $codes['present']['code']);
        $this->assertSame('#10b981', $codes['present']['color']);
        $this->assertSame(['ar' => 'حاضر في الوقت', 'fr' => null, 'en' => null], $codes['present']['label']);
        $this->assertSame('RT', $codes['late']['code']);
        $this->assertSame('#f97316', $codes['left_early']['color']);
    }

    #[Test]
    public function bad_codes_and_colours_are_rejected(): void
    {
        $admin = $this->admin();

        foreach (['', 'ABCD', 'P1', 'P P'] as $bad) {
            $this->actingAs($admin)->put(route('attendance.settings.update'), $this->payload(['present' => ['code' => $bad]]))
                ->assertSessionHasErrors(['codes.present.code' => 'att.error.code_format']);
        }
        $this->actingAs($admin)->put(route('attendance.settings.update'), $this->payload(['late' => ['color' => 'red']]))
            ->assertSessionHasErrors(['codes.late.color' => 'att.error.color_format']);

        $this->assertSame('P', AttendanceSettings::codes()['present']['code']);
    }

    #[Test]
    public function codes_must_be_unique_ignoring_case(): void
    {
        $this->actingAs($this->admin())->put(route('attendance.settings.update'), $this->payload([
            'present' => ['code' => 'ab'], 'late' => ['code' => 'AB'],
        ]))->assertSessionHasErrors(['codes.late.code' => 'att.error.code_taken']);

        $this->assertSame('P', AttendanceSettings::codes()['present']['code']);
    }

    #[Test]
    public function the_grid_reads_and_writes_the_configured_codes_without_rewriting_marks(): void
    {
        $u15 = $this->category();
        [$a, $b] = [$this->player($u15), $this->player($u15)];
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Late, 'minutes' => 15]);
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $b->id, 'status' => AttendanceStatus::Present]);
        AttendanceSettings::save(['codes' => ['present' => ['code' => 'ح'], 'late' => ['code' => 'ت']]]);
        $admin = $this->admin();

        $this->assertSame(AttendanceStatus::Late, Attendance::where('player_id', $a->id)->value('status'));

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("cells.{$a->id}.{$training->id}", 'ت15')
                ->where("cells.{$b->id}.{$training->id}", 'ح'));

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'R10', $b->id => 'ح']]],
        ])->assertSessionHasErrors("columns.0.codes.{$a->id}");

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'columns' => [['session_id' => $training->id, 'codes' => [$a->id => 'ت10', $b->id => '']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, Attendance::where('player_id', $a->id)->value('minutes'));
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceCodeTest|AttendanceCodeSettingsTest"`
Expected: FAIL: `Undefined array key "codes"` and `Call to undefined method AttendanceCode::fromConfig()`.

- [ ] **Step 3: Settings defaults**

Replace the whole of `app/Support/AttendanceSettings.php` with:
```php
<?php

namespace App\Support;

use App\Models\WebsiteConfig;

/**
 * Attendance settings, kept in WebsiteConfig.settings['attendance'].
 * Points and rules only change the score used for ranking and alerts; the
 * status counts shown in reports are always the raw marks. Codes are what is
 * written on paper and typed in the month grid; marks store the status, so
 * changing a code never rewrites history.
 */
final class AttendanceSettings
{
    /** Languages a status name can be given in. */
    public const LOCALES = ['ar', 'fr', 'en'];

    public const DEFAULTS = [
        'points' => [
            'present' => 1, 'late' => 0.75, 'left_early' => 0.75, 'not_training' => 0.5,
            'absent_excused' => 0, 'absent_unexcused' => -1,
        ],
        'rules' => ['lates_per_unexcused' => 3, 'late_minutes_as_absent' => 30],
        'alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 3],
        // A null name falls back to the built-in translation att.status.<status>.
        'codes' => [
            'present' => ['code' => 'P', 'color' => '#059669', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'late' => ['code' => 'R', 'color' => '#f59e0b', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'left_early' => ['code' => 'D', 'color' => '#f97316', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'not_training' => ['code' => 'B', 'color' => '#0284c7', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'absent_excused' => ['code' => 'AE', 'color' => '#64748b', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'absent_unexcused' => ['code' => 'AN', 'color' => '#e11d48', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
        ],
    ];

    public static function get(): array
    {
        $stored = (WebsiteConfig::singleton()->settings ?? [])['attendance'] ?? [];

        return array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /** @return array<string, array{code: string, color: string, label: array<string, ?string>}> */
    public static function codes(): array
    {
        return self::get()['codes'];
    }

    public static function save(array $values): void
    {
        $config = WebsiteConfig::singleton();
        $settings = $config->settings ?? [];
        $settings['attendance'] = array_replace_recursive(self::get(), $values);
        $config->settings = $settings;
        $config->save();
    }
}
```

- [ ] **Step 4: Code parser reads the configured codes**

Replace the whole of `app/Services/Attendance/AttendanceCode.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Support\AttendanceSettings;

/**
 * The short codes written on the paper sheet and typed into the month grid,
 * as configured in the attendance settings (default P, R<min>, D<min>, B, AE,
 * AN). Codes are letters in any script, compared upper-cased with all
 * whitespace removed; late and left-early codes take 1 to 600 minutes in
 * Latin digits. An empty cell means present.
 */
final class AttendanceCode
{
    /** @param array<string, string> $codes status value => normalised code */
    private function __construct(private readonly array $codes) {}

    public static function fromSettings(): self
    {
        return self::fromConfig(AttendanceSettings::codes());
    }

    /** @param array<string, array{code: string}> $config the settings' `codes` block */
    public static function fromConfig(array $config): self
    {
        $codes = [];
        foreach (AttendanceStatus::cases() as $status) {
            $codes[$status->value] = self::normalise($config[$status->value]['code'] ?? '');
        }

        return new self($codes);
    }

    /** Upper-cased, with all whitespace removed. Arabic letters have no case and pass through. */
    public static function normalise(?string $code): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $code));
    }

    /** @return array{status: AttendanceStatus, minutes: ?int}|null null when the code is not valid */
    public function parse(?string $code): ?array
    {
        $code = self::normalise($code);

        if ($code === '') {
            return ['status' => AttendanceStatus::Present, 'minutes' => null];
        }

        foreach (AttendanceStatus::cases() as $status) {
            if (! $status->takesMinutes() && $this->codes[$status->value] === $code) {
                return ['status' => $status, 'minutes' => null];
            }
        }

        if (preg_match('/^(\p{L}+)([0-9]{1,3})$/u', $code, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 600) {
            foreach ([AttendanceStatus::Late, AttendanceStatus::LeftEarly] as $status) {
                if ($this->codes[$status->value] === $m[1]) {
                    return ['status' => $status, 'minutes' => (int) $m[2]];
                }
            }
        }

        return null;
    }

    public function format(AttendanceStatus $status, ?int $minutes): string
    {
        return $this->codes[$status->value].($status->takesMinutes() ? (string) $minutes : '');
    }
}
```

- [ ] **Step 5: Validate and save the codes**

Replace the whole of `app/Http/Controllers/AttendanceSettingsController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceCode;
use App\Support\AttendanceSettings;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSettingsController extends Controller
{
    public function index(): Response
    {
        $current = Season::current();

        return Inertia::render('Attendance/Settings', [
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values(),
            'schedules' => TrainingSchedule::orderBy('category_id')->orderBy('weekday')->orderBy('start_time')
                ->get(['id', 'category_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']),
            'closures' => ClubClosure::orderByDesc('start_date')->get(['id', 'start_date', 'end_date', 'reason']),
            'targets' => PreseasonTarget::get(['category_id', 'season_start_year', 'target_count']),
            'seasons' => collect([$current, Season::forStartYear($current->startYear + 1)])
                ->map(fn (Season $s) => ['start_year' => $s->startYear, 'label' => $s->label()])->values(),
            'settings' => AttendanceSettings::get(),
            'statuses' => AttendanceStatus::values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'rules.lates_per_unexcused' => ['required', 'integer', 'between:0,20'],
            'rules.late_minutes_as_absent' => ['required', 'integer', 'between:0,240'],
            'alerts.min_score_pct' => ['required', 'integer', 'between:0,100'],
            'alerts.unexcused_streak' => ['required', 'integer', 'between:0,20'],
        ];
        foreach (AttendanceStatus::values() as $status) {
            $rules["points.$status"] = ['required', 'numeric', 'between:-5,5'];
            $rules["codes.$status.code"] = ['required', 'string', 'regex:/^\p{L}{1,3}$/u'];
            $rules["codes.$status.color"] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
            foreach (AttendanceSettings::LOCALES as $locale) {
                $rules["codes.$status.label.$locale"] = ['nullable', 'string', 'max:40'];
            }
        }
        $data = $request->validate($rules, [
            'codes.*.code.required' => 'att.error.code_format',
            'codes.*.code.regex' => 'att.error.code_format',
            'codes.*.color.required' => 'att.error.color_format',
            'codes.*.color.regex' => 'att.error.color_format',
        ]);

        AttendanceSettings::save([
            'points' => array_map('floatval', $data['points']),
            'rules' => array_map('intval', $data['rules']),
            'alerts' => array_map('intval', $data['alerts']),
            'codes' => $this->normaliseCodes($data['codes']),
        ]);

        return back()->with('success', 'flash.attendance_settings_saved');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        TrainingSchedule::create($this->validateSchedule($request));

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function updateSchedule(Request $request, TrainingSchedule $schedule): RedirectResponse
    {
        $schedule->update($this->validateSchedule($request));
        $this->purgeFuturePlanned($schedule);

        return back()->with('success', 'flash.training_schedule_saved');
    }

    public function destroySchedule(TrainingSchedule $schedule): RedirectResponse
    {
        $this->purgeFuturePlanned($schedule);
        $schedule->delete();

        return back()->with('success', 'flash.training_schedule_deleted');
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:100'],
        ]);

        ClubClosure::create($data);
        TrainingSession::unmarkedPlanned()
            ->where('kind', SessionKind::Regular->value)
            ->whereNull('moved_from')
            ->whereBetween('date', [$data['start_date'], $data['end_date']])
            ->delete();

        return back()->with('success', 'flash.club_closure_saved');
    }

    public function destroyClosure(ClubClosure $closure): RedirectResponse
    {
        $closure->delete(); // the next month view regenerates the freed dates

        return back()->with('success', 'flash.club_closure_deleted');
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'season_start_year' => ['required', 'integer', 'between:2000,2100'],
            'target_count' => ['required', 'integer', 'between:0,200'],
        ]);

        PreseasonTarget::updateOrCreate(
            ['category_id' => $data['category_id'], 'season_start_year' => $data['season_start_year']],
            ['target_count' => $data['target_count']],
        );

        return back()->with('success', 'flash.preseason_target_saved');
    }

    /**
     * Codes are stored upper-cased and must differ from each other ignoring
     * case (the grid compares them that way); the later status in the list
     * gets the error. Colours are stored lower-cased, empty names as null.
     */
    private function normaliseCodes(array $input): array
    {
        $codes = [];
        $seen = [];
        $errors = [];

        foreach (AttendanceStatus::values() as $status) {
            $row = $input[$status];
            $code = AttendanceCode::normalise($row['code']);
            if (isset($seen[$code])) {
                $errors["codes.$status.code"] = 'att.error.code_taken';
            }
            $seen[$code] = true;

            $labels = [];
            foreach (AttendanceSettings::LOCALES as $locale) {
                $label = $row['label'][$locale] ?? null;
                $labels[$locale] = $label === null || $label === '' ? null : $label;
            }

            $codes[$status] = ['code' => $code, 'color' => strtolower($row['color']), 'label' => $labels];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $codes;
    }

    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
    }

    /**
     * Upcoming generated sessions nobody marked are rebuilt from the new
     * schedule on the next month view. Moved ones are left alone: move()
     * keeps the schedule's `schedule_id`, and purging one here would drop
     * its `moved_from` guard, so the generator would recreate the original
     * date right back.
     */
    private function purgeFuturePlanned(TrainingSchedule $schedule): void
    {
        TrainingSession::unmarkedPlanned()
            ->where('schedule_id', $schedule->id)
            ->whereNull('moved_from')
            ->where('date', '>=', now()->toDateString())
            ->delete();
    }
}
```

- [ ] **Step 6: The grid uses the configured codes**

In `app/Http/Controllers/AttendanceGridController.php` (as rewritten in Task 2), three edits.

In `show()`, replace
```php
        $cells = [];
        $expected = [];
```
with
```php
        $codes = AttendanceCode::fromSettings();
        $cells = [];
        $expected = [];
```
and replace
```php
                    $cells[$mark->player_id][$session->id] = AttendanceCode::format($mark->status, $mark->minutes);
```
with
```php
                    $cells[$mark->player_id][$session->id] = $codes->format($mark->status, $mark->minutes);
```

In `save()`, replace
```php
        $plan = [];
        $errors = [];
```
with
```php
        $codes = AttendanceCode::fromSettings();
        $plan = [];
        $errors = [];
```
and replace
```php
                $parsed = AttendanceCode::parse($code);
```
with
```php
                $parsed = $codes->parse($code);
```

- [ ] **Step 7: Settings screen with a codes card**

Replace the whole of `resources/js/Pages/Attendance/Settings.vue` with:
```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    closures: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));
// Local 'Y-m-d', not toISOString()'s UTC date (see Index.vue's key()).
const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = key(new Date());

// ISO weekday 1..7 -> localized name (2024-01-01 is a Monday).
const weekdayName = (n) => new Date(Date.UTC(2024, 0, n)).toLocaleDateString(lang.value, { weekday: 'long', timeZone: 'UTC' });
const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '';

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);

// ---- Weekly schedule ----
const editingId = ref(null);
const scheduleForm = useForm({ category_id: props.categories[0]?.id ?? null, weekday: 1, start_time: '18:00', end_time: '19:30', valid_from: today, valid_to: '' });
function editSchedule(s) {
    editingId.value = s.id;
    Object.assign(scheduleForm, { category_id: s.category_id, weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, valid_from: s.valid_from, valid_to: s.valid_to ?? '' });
}
function resetSchedule() {
    editingId.value = null;
    scheduleForm.reset();
    scheduleForm.clearErrors();
}
function submitSchedule() {
    const opts = { preserveScroll: true, onSuccess: resetSchedule };
    scheduleForm.transform((d) => ({ ...d, valid_to: d.valid_to || null }));
    editingId.value ? scheduleForm.put(route('attendance.schedules.update', editingId.value), opts) : scheduleForm.post(route('attendance.schedules.store'), opts);
}
function destroy(name, id) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route(name, id), { preserveScroll: true });
}

// ---- Closures ----
const closureForm = useForm({ start_date: today, end_date: today, reason: '' });
const submitClosure = () => closureForm.post(route('attendance.closures.store'), { preserveScroll: true, onSuccess: () => closureForm.reset() });

// ---- Pre-season targets ----
const targetForm = useForm({ category_id: props.categories[0]?.id ?? null, season_start_year: props.seasons[0]?.start_year, target_count: 0 });
watch(() => [targetForm.category_id, targetForm.season_start_year], ([c, y]) => {
    targetForm.target_count = props.targets.find((x) => x.category_id === c && x.season_start_year === y)?.target_count ?? 0;
}, { immediate: true });
const submitTarget = () => targetForm.post(route('attendance.preseason-targets.store'), { preserveScroll: true });

// ---- Codes, points, rules, alerts: one form, one save ----
const LOCALES = ['ar', 'fr', 'en'];
const settingsForm = useForm(JSON.parse(JSON.stringify(props.settings)));
const submitSettings = () => settingsForm.put(route('attendance.settings.update'), { preserveScroll: true });
// The built-in name in one language, shown as the placeholder of that language's field.
const builtin = (status, loc) => t(`att.status.${status}`, {}, { locale: loc });
const rowError = (status) => tr(settingsForm.errors[`codes.${status}.code`] ?? settingsForm.errors[`codes.${status}.color`]);
const otherErrors = computed(() => Object.entries(settingsForm.errors)
    .filter(([k]) => !/^codes\.[a-z_]+\.(code|color)$/.test(k))
    .map(([, e]) => tr(e)));
</script>

<template>
    <Head :title="t('att.settings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('attendance.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.settings') }}</h1>
            </div>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Weekly schedule -->
            <section :class="[card, 'lg:col-span-2']">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.schedules') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitSchedule">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="scheduleForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.weekday') }}
                        <select v-model="scheduleForm.weekday" :class="[input, 'block']"><option v-for="n in 7" :key="n" :value="n">{{ weekdayName(n) }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.start') }}<input v-model="scheduleForm.start_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end') }}<input v-model="scheduleForm.end_time" type="time" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_from') }}<input v-model="scheduleForm.valid_from" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.valid_to') }}<input v-model="scheduleForm.valid_to" type="date" :class="[input, 'block']" /></label>
                    <button type="submit" :disabled="scheduleForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingId ? t('att.save') : t('att.add') }}</button>
                    <button v-if="editingId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="resetSchedule">{{ t('att.close') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in scheduleForm.errors" :key="k" :message="e" /></div>
                </form>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 font-medium">{{ categoryName(s.category_id) }}</td>
                            <td>{{ weekdayName(s.weekday) }}</td>
                            <td dir="ltr" class="text-start">{{ s.start_time }}–{{ s.end_time }}</td>
                            <td class="text-slate-500">{{ s.valid_from }} → {{ s.valid_to ?? '…' }}</td>
                            <td class="text-end">
                                <button class="p-1 text-slate-400 hover:text-primary-600" :title="t('att.edit')" @click="editSchedule(s)"><Icon name="pencil" /></button>
                                <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.schedules.destroy', s.id)"><Icon name="trash" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Closures -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.closures') }}</h2>
                <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submitClosure">
                    <label class="text-xs text-slate-500">{{ t('att.start_date') }}<input v-model="closureForm.start_date" type="date" :class="[input, 'block']" /></label>
                    <label class="text-xs text-slate-500">{{ t('att.end_date') }}<input v-model="closureForm.end_date" type="date" :class="[input, 'block']" /></label>
                    <label class="flex-1 text-xs text-slate-500">{{ t('att.reason') }}<input v-model="closureForm.reason" type="text" maxlength="100" :class="[input, 'block w-full']" /></label>
                    <button type="submit" :disabled="closureForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.add') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in closureForm.errors" :key="k" :message="e" /></div>
                </form>
                <ul class="space-y-1 text-sm">
                    <li v-for="c in closures" :key="c.id" class="flex items-center justify-between border-t border-slate-100 pt-1 dark:border-slate-800">
                        <span><b>{{ c.reason }}</b> · {{ c.start_date }} → {{ c.end_date }}</span>
                        <button class="p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="destroy('attendance.closures.destroy', c.id)"><Icon name="trash" /></button>
                    </li>
                </ul>
            </section>

            <!-- Pre-season targets -->
            <section :class="card">
                <h2 class="mb-3 font-bold text-slate-900 dark:text-slate-100">{{ t('att.targets') }}</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="submitTarget">
                    <label class="text-xs text-slate-500">{{ t('att.category') }}
                        <select v-model="targetForm.category_id" :class="[input, 'block']"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.season') }}
                        <select v-model="targetForm.season_start_year" :class="[input, 'block']"><option v-for="s in seasons" :key="s.start_year" :value="s.start_year">{{ s.label }}</option></select>
                    </label>
                    <label class="text-xs text-slate-500">{{ t('att.target_count') }}<input v-model.number="targetForm.target_count" type="number" min="0" max="200" :class="[input, 'block w-24']" /></label>
                    <button type="submit" :disabled="targetForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                    <div class="w-full"><InputError v-for="(e, k) in targetForm.errors" :key="k" :message="e" /></div>
                </form>
            </section>

            <!-- Codes and points, rules, alerts -->
            <form class="space-y-4 lg:col-span-2" @submit.prevent="submitSettings">
                <section :class="card">
                    <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.codes') }}</h2>
                    <p class="mb-3 text-xs text-slate-500">{{ t('att.codes_help') }}</p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] text-sm">
                            <thead>
                                <tr class="text-xs text-slate-500">
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.status') }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.code') }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.color') }}</th>
                                    <th v-for="l in LOCALES" :key="l" class="py-1 text-start font-semibold">{{ t(`att.col.label_${l}`) }}</th>
                                    <th class="py-1 text-start font-semibold">{{ t('att.col.points') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in statuses" :key="s" class="border-t border-slate-100 align-top dark:border-slate-800">
                                    <td class="py-2 pe-2">
                                        <span class="inline-flex items-center gap-2 font-medium">
                                            <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: settingsForm.codes[s].color }"></span>
                                            {{ t(`att.status.${s}`) }}
                                        </span>
                                        <InputError :message="rowError(s)" />
                                    </td>
                                    <td class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].code" type="text" maxlength="3" dir="auto" :aria-label="`${t('att.col.code')} · ${t(`att.status.${s}`)}`" :class="[input, 'w-16 text-center font-mono uppercase']" />
                                    </td>
                                    <td class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].color" type="color" :aria-label="`${t('att.col.color')} · ${t(`att.status.${s}`)}`" class="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-700" />
                                    </td>
                                    <td v-for="l in LOCALES" :key="l" class="py-2 pe-2">
                                        <input v-model="settingsForm.codes[s].label[l]" type="text" maxlength="40" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtin(s, l)" :aria-label="`${t(`att.col.label_${l}`)} · ${t(`att.status.${s}`)}`" :class="[input, 'w-full min-w-[8rem]']" />
                                    </td>
                                    <td class="py-2">
                                        <input v-model.number="settingsForm.points[s]" type="number" step="0.25" min="-5" max="5" :aria-label="`${t('att.col.points')} · ${t(`att.status.${s}`)}`" :class="[input, 'w-20']" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section :class="[card, 'grid gap-4 md:grid-cols-2']">
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.rules') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.lates_per_unexcused') }}<input v-model.number="settingsForm.rules.lates_per_unexcused" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.late_minutes_as_absent') }}<input v-model.number="settingsForm.rules.late_minutes_as_absent" type="number" min="0" max="240" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                    <div>
                        <h2 class="mb-2 font-bold text-slate-900 dark:text-slate-100">{{ t('att.alerts') }}</h2>
                        <label class="mb-2 block text-sm">{{ t('att.min_score_pct') }}<input v-model.number="settingsForm.alerts.min_score_pct" type="number" min="0" max="100" :class="[input, 'mt-1 block w-24']" /></label>
                        <label class="block text-sm">{{ t('att.unexcused_streak') }}<input v-model.number="settingsForm.alerts.unexcused_streak" type="number" min="0" max="20" :class="[input, 'mt-1 block w-24']" /></label>
                    </div>
                </section>

                <div>
                    <InputError v-for="(e, i) in otherErrors" :key="i" :message="e" />
                    <button type="submit" :disabled="settingsForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.codes': ['Codes and colours', 'Codes et couleurs', 'الرموز والألوان'],
    'att.codes_help': [
        'Each status has a code of 1 to 3 letters (Arabic letters allowed), written on paper and typed in the month grid. Late and left-early codes are followed by the minutes, e.g. R15. A name left empty uses the built-in one. Changing a code never changes saved marks.',
        'Chaque statut a un code de 1 à 3 lettres (lettres arabes acceptées), écrit sur papier et saisi dans la grille du mois. Les codes de retard et de départ anticipé sont suivis des minutes, ex. R15. Un nom laissé vide reprend le nom par défaut. Changer un code ne modifie jamais les présences enregistrées.',
        'لكل حالة رمز من حرف إلى 3 أحرف (الحروف العربية مقبولة)، يُكتب على الورق ويُدخل في جدول الشهر. رمزا التأخر والمغادرة المبكرة يتبعهما عدد الدقائق، مثال R15. الاسم الفارغ يأخذ الاسم الافتراضي. تغيير الرمز لا يغيّر الحضور المسجل.',
    ],
    'att.col.status': ['Status', 'Statut', 'الحالة'],
    'att.col.code': ['Code', 'Code', 'الرمز'],
    'att.col.color': ['Colour', 'Couleur', 'اللون'],
    'att.col.label_ar': ['Name (Arabic)', 'Nom (arabe)', 'الاسم (عربية)'],
    'att.col.label_fr': ['Name (French)', 'Nom (français)', 'الاسم (فرنسية)'],
    'att.col.label_en': ['Name (English)', 'Nom (anglais)', 'الاسم (إنجليزية)'],
    'att.col.points': ['Points', 'Points', 'النقاط'],
    'att.error.code_format': ['1 to 3 letters, no digits or spaces.', '1 à 3 lettres, sans chiffres ni espaces.', 'من حرف إلى 3 أحرف، دون أرقام أو مسافات.'],
    'att.error.code_taken': ['Another status already uses this code.', 'Un autre statut utilise déjà ce code.', 'هذا الرمز مستعمل لحالة أخرى.'],
    'att.error.color_format': ['Pick a colour.', 'Choisissez une couleur.', 'اختر لوناً.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 9: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceCodeTest|AttendanceCodeSettingsTest|AttendanceSettingsTest|AttendanceSettingsPageTest|AttendanceGridTest|AttendanceCategoryPivotTest" && npm run i18n:check`
Expected: PASS. `AttendanceSettingsTest::defaults_apply_until_saved_and_other_settings_survive` still holds (`get()` equals `DEFAULTS` until saved), and `preseason_target_and_scoring_settings_are_saved` posts `DEFAULTS`, codes included, which validate.

- [ ] **Step 10: Commit**

```bash
git add app/Support/AttendanceSettings.php app/Services/Attendance/AttendanceCode.php app/Http/Controllers/AttendanceSettingsController.php app/Http/Controllers/AttendanceGridController.php resources/js/Pages/Attendance/Settings.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Unit/AttendanceCodeTest.php tests/Feature/AttendanceCodeSettingsTest.php
git commit -m "feat(attendance): configurable status codes, colours and names" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Configured names and colours everywhere

**Files:**
- Create: `resources/js/Composables/useAttendanceCodes.js`
- Modify: `app/Http/Controllers/AttendanceCalendarController.php`, `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/AttendanceGridController.php`
- Modify: `resources/js/Pages/Attendance/Session.vue`, `resources/js/Pages/Attendance/Grid.vue`, `resources/js/Pages/Attendance/Index.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendanceCodesOnPagesTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceSettings::codes()` (Task 5); `CalendarFeed` `summary` (Task 4).
- Produces: Inertia prop `attendanceCodes` (the `codes()` block) on `Attendance/Index`, `Attendance/Session` and `Attendance/Grid`.
- Produces: `useAttendanceCodes()` returning `{ statuses: string[], codes: ComputedRef, code(status): string, label(status): string, color(status): string, chipStyle(status): object, tint(status, alpha = '26'): object, statusOf(value): string|null, summaryText(summary): string }`. `label()` uses the configured name in the current locale, else `t('att.status.<status>')`. `statusOf()` mirrors `AttendanceCode::parse()` for colouring (null for empty or unknown).
- Produces: i18n key `att.grid_help_codes` (parameter `{late}`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceCodesOnPagesTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSession;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCodesOnPagesTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function every_attendance_page_receives_the_configured_codes(): void
    {
        $u15 = $this->category();
        $training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        AttendanceSettings::save(['codes' => ['late' => ['code' => 'ت', 'color' => '#123456', 'label' => ['ar' => 'تأخير', 'fr' => null, 'en' => null]]]]);
        $admin = $this->admin();

        $urls = [
            route('attendance.index', ['category_id' => $u15->id, 'month' => '2026-10']),
            route('attendance.sessions.show', $training),
            route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-10']),
        ];
        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('attendanceCodes.late.code', 'ت')
                ->where('attendanceCodes.late.color', '#123456')
                ->where('attendanceCodes.late.label.ar', 'تأخير')
                ->where('attendanceCodes.present.code', 'P')
                ->where('attendanceCodes.present.color', '#059669'));
        }
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceCodesOnPagesTest`
Expected: FAIL: property `attendanceCodes` does not exist.

- [ ] **Step 3: Pass the codes from the three controllers**

`app/Http/Controllers/AttendanceCalendarController.php`: add `use App\Support\AttendanceSettings;` to the imports (after `use App\Services\Attendance\SessionGenerator;`) and replace
```php
        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            ...$this->month($data, $categories),
        ]);
```
with
```php
        return Inertia::render('Attendance/Index', [
            'categories' => $categories,
            'attendanceCodes' => AttendanceSettings::codes(),
            ...$this->month($data, $categories),
        ]);
```

`app/Http/Controllers/AttendanceController.php`: add `use App\Support\AttendanceSettings;` to the imports (after `use App\Services\Attendance\Roster;`) and replace
```php
            'reasons' => AbsenceReason::values(),
```
with
```php
            'reasons' => AbsenceReason::values(),
            'attendanceCodes' => AttendanceSettings::codes(),
```

`app/Http/Controllers/AttendanceGridController.php`: add `use App\Support\AttendanceSettings;` to the imports (after `use App\Services\Attendance\SessionGenerator;`) and replace
```php
            'rows' => $rows,
            'cells' => (object) $cells,
```
with
```php
            'rows' => $rows,
            'cells' => (object) $cells,
            'attendanceCodes' => AttendanceSettings::codes(),
```

- [ ] **Step 4: The composable**

Create `resources/js/Composables/useAttendanceCodes.js`:
```js
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/** The six statuses in their fixed order (App\Enums\AttendanceStatus). */
export const STATUSES = ['present', 'late', 'left_early', 'not_training', 'absent_excused', 'absent_unexcused'];
const WITH_MINUTES = ['late', 'left_early'];
const FALLBACK_COLOR = '#64748b';

/**
 * The configured status codes, names and colours (attendance settings), read
 * from the `attendanceCodes` prop every attendance page receives. A name left
 * empty falls back to the built-in translation.
 */
export function useAttendanceCodes() {
    const page = usePage();
    const { t, locale } = useI18n();
    const codes = computed(() => page.props.attendanceCodes ?? {});

    const code = (status) => codes.value[status]?.code ?? '';
    const label = (status) => codes.value[status]?.label?.[locale.value] || t(`att.status.${status}`);
    const color = (status) => codes.value[status]?.color || FALLBACK_COLOR;
    /** Solid chip: the configured colour behind white text. */
    const chipStyle = (status) => ({ backgroundColor: color(status), color: '#fff' });
    /** A light wash of the colour (hex alpha), for grid cells. */
    const tint = (status, alpha = '26') => ({ backgroundColor: `${color(status)}${alpha}` });

    /** Mirrors AttendanceCode::parse() to colour a typed code; the server still validates. */
    function statusOf(value) {
        const v = String(value ?? '').replace(/\s+/gu, '').toUpperCase();
        if (v === '') return null;
        const simple = STATUSES.find((s) => !WITH_MINUTES.includes(s) && code(s) === v);
        if (simple) return simple;
        const m = v.match(/^(\p{L}+)([0-9]{1,3})$/u);
        return m ? WITH_MINUTES.find((s) => code(s) === m[1]) ?? null : null;
    }

    /** "18 Present · 2 Late" for a held session's { status: count } summary. */
    const summaryText = (summary) => STATUSES.filter((s) => summary?.[s]).map((s) => `${summary[s]} ${label(s)}`).join(' · ');

    return { statuses: STATUSES, codes, code, label, color, chipStyle, tint, statusOf, summaryText };
}
```

- [ ] **Step 5: Session screen chips use the configured names and colours**

Replace the whole of `resources/js/Pages/Attendance/Session.vue` with:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

const props = defineProps({
    session: { type: Object, required: true },
    saved: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    lastCoach: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    allCategories: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const { label, chipStyle } = useAttendanceCodes();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const cancelled = computed(() => props.session.state === 'cancelled');
const editable = computed(() => can('attendance', 'edit') && !cancelled.value);
const dateLabel = computed(() => new Date(`${props.session.date}T00:00:00`).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

const rows = ref(props.rows.map((r) => ({ ...r, note: r.note ?? '' })));
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', title: props.session.title ?? '', notes: props.session.notes ?? '' });

const takesMinutes = (s) => s === 'late' || s === 'left_early';
const takesReason = (s) => s === 'absent_excused' || s === 'not_training';
function setStatus(row, status) {
    row.status = status;
    if (!takesMinutes(status)) row.minutes = null;
    if (!takesReason(status)) row.reason = null;
    if (status === 'absent_excused' && !row.reason) row.reason = 'other';
}
const allPresent = () => rows.value.forEach((r) => setStatus(r, 'present'));

const idleChip = 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400';
const counts = computed(() => rows.value.reduce((acc, r) => ((acc[r.status] = (acc[r.status] ?? 0) + 1), acc), {}));

const pick = ref('');
function addPlayer() {
    const p = props.candidates.find((c) => c.id === Number(pick.value));
    if (p && !rows.value.some((r) => r.player_id === p.id)) rows.value.push({ player_id: p.id, name: p.name, category: null, status: 'present', minutes: null, reason: null, note: '' });
    pick.value = '';
}
const removeRow = (row) => (rows.value = rows.value.filter((r) => r !== row));

const saving = ref(false);
function save() {
    router.put(route('attendance.sessions.marks', props.session.id), {
        ...log,
        marks: rows.value.map(({ name, category, ...mark }) => mark),
    }, { preserveScroll: true, preserveState: 'errors', onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}
const rowError = (i) => ['minutes', 'reason', 'note', 'status'].map((f) => errors.value[`marks.${i}.${f}`]).find(Boolean);

// ---- Cancel / move ----
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const submitCancel = () => cancelForm.post(route('attendance.sessions.cancel', props.session.id), { onSuccess: () => (showCancel.value = false) });
const showMove = ref(false);
const moveForm = useForm({ date: props.session.date, start_time: props.session.start_time, end_time: props.session.end_time });
const submitMove = () => moveForm.post(route('attendance.sessions.move', props.session.id), { onSuccess: () => (showMove.value = false) });

// ---- Categories of a pre-season session: editable until its marks are first saved ----
const canEditCategories = computed(() => editable.value && props.session.kind === 'preseason' && !props.saved);
const showCategories = ref(false);
const categoriesForm = useForm({ category_ids: [] });
function openCategories() {
    categoriesForm.category_ids = props.session.categories.map((c) => c.id);
    categoriesForm.clearErrors();
    showCategories.value = true;
}
// 'errors' keeps the modal open on a validation error; a success remounts the page with the new roster.
const submitCategories = () => categoriesForm.put(route('attendance.sessions.categories', props.session.id), {
    preserveScroll: true,
    preserveState: 'errors',
    onSuccess: () => (showCategories.value = false),
});

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7) })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <div>
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ session.category }} · {{ t(`att.kind.${session.kind}`) }}</h1>
                        <p class="text-sm capitalize text-slate-500">{{ dateLabel }} · <span dir="ltr">{{ session.start_time }}–{{ session.end_time }}</span> · {{ t(`att.state.${session.state}`) }}</p>
                        <p v-if="session.moved_from" class="text-xs text-slate-400">{{ t('att.moved_from', { date: session.moved_from }) }}</p>
                    </div>
                </div>
                <div v-if="editable" class="flex gap-2">
                    <button v-if="canEditCategories" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-900" @click="openCategories">{{ t('att.edit_categories') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                    <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                </div>
            </div>
        </template>

        <p v-if="cancelled" class="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.cancelled_because', { reason: session.cancel_reason }) }}</p>
        <p v-else-if="!saved" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.not_saved') }}</p>
        <InputError :message="tr(errors.session)" class="mb-2" />

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span v-for="s in statuses" :key="s" v-show="counts[s]" class="rounded-full px-2 py-0.5" :style="chipStyle(s)">{{ label(s) }}: {{ counts[s] }}</span>
                    </div>
                    <button v-if="editable" class="rounded-lg px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-900" @click="allPresent">{{ t('att.all_present') }}</button>
                </div>
                <ul>
                    <li v-for="(row, i) in rows" :key="row.player_id" class="border-b border-slate-100 p-3 last:border-0 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="min-w-[10rem] flex-1 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.category" class="ms-1 text-xs font-normal text-slate-400">{{ row.category }}</span>
                            </span>
                            <button v-for="s in statuses" :key="s" type="button" :disabled="!editable" class="rounded-lg px-2 py-1 text-xs font-semibold" :class="row.status === s ? '' : idleChip" :style="row.status === s ? chipStyle(s) : null" @click="setStatus(row, s)">{{ label(s) }}</button>
                            <button v-if="editable" type="button" class="p-1 text-slate-300 hover:text-rose-600" :title="t('att.remove')" @click="removeRow(row)"><Icon name="trash" /></button>
                        </div>
                        <div v-if="takesMinutes(row.status) || takesReason(row.status) || row.note" class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-if="takesMinutes(row.status)" class="text-xs text-slate-500">{{ t('att.minutes') }}
                                <input v-model.number="row.minutes" type="number" min="1" max="600" :disabled="!editable" :class="[input, 'ms-1 w-20']" />
                            </label>
                            <label v-if="takesReason(row.status)" class="text-xs text-slate-500">{{ t('att.reason') }}
                                <select v-model="row.reason" :disabled="!editable" :class="[input, 'ms-1']">
                                    <option :value="null">—</option>
                                    <option v-for="r in reasons" :key="r" :value="r">{{ t(`att.reason.${r}`) }}</option>
                                </select>
                            </label>
                            <input v-model="row.note" type="text" maxlength="255" :placeholder="t('att.note')" :disabled="!editable" :class="[input, 'min-w-[12rem] flex-1']" />
                        </div>
                        <InputError :message="rowError(i)" />
                    </li>
                </ul>
                <div v-if="editable && candidates.length" class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                    <select v-model="pick" :class="[input, 'flex-1']" :aria-label="t('att.add_player')">
                        <option value="">{{ t('att.add_player') }}…</option>
                        <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <button type="button" class="rounded-lg px-3 text-sm font-semibold text-primary-600 ring-1 ring-primary-200 dark:ring-primary-900" :disabled="!pick" @click="addPlayer">{{ t('att.add') }}</button>
                </div>
            </section>

            <section class="h-fit space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="log.coach" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="log.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="log.notes" rows="4" maxlength="2000" :disabled="!editable" :class="[input, 'mt-1 block w-full']"></textarea></label>
                <InputError :message="tr(errors.marks)" />
                <button v-if="editable" type="button" :disabled="saving || !rows.length" class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </section>
        </div>

        <Modal :show="showCancel" max-width="md" @close="showCancel = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCancel">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.cancel') }}</h2>
                <label class="block text-sm">{{ t('att.cancel_reason') }}<input v-model="cancelForm.reason" type="text" maxlength="255" :class="[input, 'mt-1 block w-full']" /></label>
                <InputError :message="tr(cancelForm.errors.reason)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCancel = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="cancelForm.processing" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('att.cancel') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showMove" max-width="md" @close="showMove = false">
            <form class="space-y-3 p-5" @submit.prevent="submitMove">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.move') }}</h2>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="moveForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="moveForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="moveForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in moveForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showMove = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="moveForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showCategories" max-width="md" @close="showCategories = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCategories">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.categories') }}</h2>
                <p class="text-xs text-slate-500">{{ t('att.categories_help') }}</p>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label v-for="c in allCategories" :key="c.id" class="inline-flex items-center gap-1.5">
                        <input v-model="categoriesForm.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ c.name }}
                    </label>
                </div>
                <InputError v-for="(e, k) in categoriesForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCategories = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="categoriesForm.processing || !categoriesForm.category_ids.length" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Grid legend and cell colours from the settings**

Replace the whole of `resources/js/Pages/Attendance/Grid.vue` with:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

const props = defineProps({
    category: { type: Object, required: true },
    month: { type: String, required: true },
    sessions: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    cells: { type: Object, default: () => ({}) }, // { playerId: { sessionId: code } }
});
const { t, locale } = useI18n();
const { can } = useCan();
const { statuses, code, label, chipStyle, tint, statusOf } = useAttendanceCodes();
const page = usePage();
const editable = computed(() => can('attendance', 'edit'));
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const values = reactive(JSON.parse(JSON.stringify(props.cells)));
const dirty = reactive(new Set());
const sent = ref([]); // session ids in the order last posted, to map `columns.{i}` errors back

const inRoster = (pid, sid) => values[pid] !== undefined && sid in values[pid];
const dayLabel = (s) => new Date(`${s.date}T00:00:00`).toLocaleDateString(lang.value, { weekday: 'short', day: 'numeric' });
const monthLabel = computed(() => new Date(`${props.month}-01T00:00:00`).toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));

// The legend comes from the configured codes: late / left early show a sample with minutes.
const legendCode = (s) => code(s) + (s === 'late' || s === 'left_early' ? '15' : '');

function onInput(pid, sid, event) {
    values[pid][sid] = event.target.value.toUpperCase();
    dirty.add(sid);
}
// Only att.* codes are translation keys; anything else (e.g. a plain message) shows as is.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
function cellError(pid, sid) {
    const i = sent.value.indexOf(sid);
    const e = i === -1 ? null : page.props.errors?.[`columns.${i}.codes.${pid}`];
    return tr(e);
}
function columnError(sid) {
    const i = sent.value.indexOf(sid);
    const e = i === -1 ? null : page.props.errors?.[`columns.${i}`];
    return tr(e);
}
const columnMessages = computed(() => {
    const errors = page.props.errors ?? {};

    return Object.keys(errors)
        .filter((key) => /^columns\.\d+$/.test(key) || key === 'session' || key === 'marks')
        .map((key) => tr(errors[key]));
});
// A wash of the configured colour of the status the code stands for; present stays plain.
function cellStyle(value) {
    const status = statusOf(value);
    return status && status !== 'present' ? tint(status) : null;
}

// Spreadsheet-style navigation: arrows / Enter move between cells (mirrored in RTL).
function onKey(event, r, c) {
    const rtl = document.documentElement.dir === 'rtl';
    const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], Enter: [1, 0], ArrowLeft: [0, rtl ? 1 : -1], ArrowRight: [0, rtl ? -1 : 1] };
    const move = moves[event.key];
    if (!move) return;
    event.preventDefault();
    let [nr, nc] = [r + move[0], c + move[1]];
    while (nr >= 0 && nr < props.rows.length && nc >= 0 && nc < props.sessions.length) {
        const el = document.querySelector(`[data-cell="${nr}-${nc}"]`);
        if (el) return el.focus();
        [nr, nc] = [nr + move[0], nc + move[1]];
    }
}

function save() {
    const columns = props.sessions
        .filter((s) => dirty.has(s.id))
        .map((s) => ({ session_id: s.id, codes: Object.fromEntries(props.rows.filter((r) => inRoster(r.id, s.id)).map((r) => [r.id, values[r.id][s.id] ?? ''])) }))
        .filter((c) => Object.keys(c.codes).length);
    if (!columns.length) return;
    sent.value = columns.map((c) => c.session_id);
    router.post(route('attendance.grid.save'), { columns }, { preserveScroll: true, preserveState: 'errors', onSuccess: () => dirty.clear() });
}
</script>

<template>
    <Head :title="t('att.grid')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: category.id, month })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <h1 class="text-lg font-bold capitalize text-slate-900 dark:text-slate-100">{{ t('att.grid') }} · {{ category.name }} · {{ monthLabel }}</h1>
                </div>
                <button v-if="editable" :disabled="!dirty.size" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </div>
        </template>

        <div class="mb-2 flex flex-wrap gap-2 text-xs">
            <span v-for="s in statuses" :key="s" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5" :style="chipStyle(s)">
                <b class="font-mono" dir="ltr">{{ legendCode(s) }}</b>{{ label(s) }}
            </span>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ t('att.grid_help_codes', { late: code('late') }) }}</p>
        <InputError :message="tr(page.props.errors?.columns)" />
        <InputError v-for="(msg, idx) in columnMessages" :key="idx" :message="msg" />

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="sticky start-0 z-10 bg-slate-50 p-2 text-start dark:bg-slate-800">{{ t('att.player') }}</th>
                        <th v-for="s in sessions" :key="s.id" class="min-w-[3.5rem] p-1 text-center text-xs font-semibold" :class="columnError(s.id) ? 'text-rose-600' : dirty.has(s.id) ? 'text-primary-600' : 'text-slate-500'" :title="columnError(s.id) ?? ''">
                            <Link :href="route('attendance.sessions.show', s.id)" class="hover:underline">{{ dayLabel(s) }}</Link>
                            <div class="font-normal" :class="s.state === 'held' ? 'text-emerald-600' : 'text-slate-400'">{{ s.state === 'held' ? '✓' : '·' }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in rows" :key="row.id" class="border-t border-slate-100 dark:border-slate-800">
                        <td class="sticky start-0 z-10 whitespace-nowrap bg-white p-2 font-medium dark:bg-slate-900">{{ row.name }}</td>
                        <td v-for="(s, c) in sessions" :key="s.id" class="p-0.5 text-center">
                            <template v-if="inRoster(row.id, s.id)">
                                <input
                                    :data-cell="`${r}-${c}`"
                                    :value="values[row.id][s.id]"
                                    :disabled="!editable"
                                    maxlength="6"
                                    dir="ltr"
                                    class="w-14 rounded border-slate-200 p-1 text-center font-mono text-xs uppercase dark:border-slate-700 dark:bg-slate-900"
                                    :class="cellError(row.id, s.id) ? 'border-rose-500 ring-1 ring-rose-500' : ''"
                                    :style="cellStyle(values[row.id][s.id])"
                                    :title="cellError(row.id, s.id) ?? ''"
                                    @input="onInput(row.id, s.id, $event)"
                                    @keydown="onKey($event, r, c)"
                                />
                            </template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Month calendar shows the status mix of held sessions**

In `resources/js/Pages/Attendance/Index.vue` (as left by Task 4), three edits.

Replace
```js
import { useCan } from '@/Composables/useCan';
```
with
```js
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
```

Replace
```js
const { can } = useCan();
```
with
```js
const { can } = useCan();
const { statuses, color, summaryText } = useAttendanceCodes();
```

Replace
```js
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title]
    .filter(Boolean).join(' · ');
```
with
```js
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title, summaryText(s.summary)]
    .filter(Boolean).join(' · ');
```

Replace the chip
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </Link>
```
with
```html
                    <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 block rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                        <span class="flex items-center gap-1">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="kindDot[s.kind]"></span>
                            <span dir="ltr">{{ s.start_time }}</span>
                            <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                            <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                            <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                        </span>
                        <span v-if="s.summary" class="mt-0.5 flex h-1 overflow-hidden rounded-full">
                            <span v-for="st in statuses" v-show="s.summary[st]" :key="st" :style="{ backgroundColor: color(st), flexGrow: s.summary[st] ?? 0 }"></span>
                        </span>
                    </Link>
```

- [ ] **Step 8: Append the translation**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.grid_help_codes': [
        'Type one code per cell; add the minutes after the late and left-early codes, e.g. {late}15. An empty cell counts as present when the column is saved.',
        'Saisissez un code par case ; ajoutez les minutes après les codes de retard et de départ anticipé, ex. {late}15. Une case vide compte comme présent à l’enregistrement de la colonne.',
        'اكتب رمزاً في كل خانة، وأضف الدقائق بعد رمزي التأخر والمغادرة المبكرة، مثال {late}15. الخانة الفارغة تُحسب حضوراً عند حفظ العمود.',
    ],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 9: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceCodesOnPagesTest|AttendanceSessionTest|AttendanceGridTest|AttendanceCalendarTest|AttendanceJointCalendarTest|PreseasonMultiCategoryTest" && npm run i18n:check`
Expected: PASS, and the build has no missing-import errors.

- [ ] **Step 10: Commit**

```bash
git add resources/js/Composables/useAttendanceCodes.js app/Http/Controllers/AttendanceCalendarController.php app/Http/Controllers/AttendanceController.php app/Http/Controllers/AttendanceGridController.php resources/js/Pages/Attendance/Session.vue resources/js/Pages/Attendance/Grid.vue resources/js/Pages/Attendance/Index.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceCodesOnPagesTest.php
git commit -m "feat(attendance): configured status names and colours on every screen" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---
### Task 7: View switcher and Week view

**Files:**
- Create: `resources/js/lib/attendanceCalendar.js`, `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`, `resources/js/Pages/Attendance/Partials/MonthView.vue`, `resources/js/Pages/Attendance/Partials/WeekView.vue`
- Modify: `app/Http/Controllers/AttendanceCalendarController.php`, `app/Services/Attendance/CalendarFeed.php`, `resources/js/Pages/Attendance/Index.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendanceWeekViewTest.php` (new)

**Interfaces:**
- Consumes: `CalendarFeed::sessions()` (Task 4), `useAttendanceCodes()` (Task 6), `AddSessionModal` (Task 3).
- Produces: `CalendarFeed::__construct(SessionGenerator $generator)` and `CalendarFeed::generateAll(string $from, string $to): void` (every category, every month the range touches).
- Produces: `GET attendance.index` query `view=month|week` (default `month`; unknown values fail validation on `view`), `date=Y-m-d` for the week. `AttendanceCalendarController::VIEWS` lists the allowed views. Props for every view: `view`, `categories`, `categoryId` (month: the shown category; other views: the filter or `null`), `month` (`Y-m`, the anchor kept when switching views), `attendanceCodes`. Week adds `week: {start, end}` (Monday to Sunday) and `sessions` of every category.
- Produces: `resources/js/lib/attendanceCalendar.js` exports `dateKey(Date)`, `monthKey(Date)`, `parseDay('Y-m-d')`, `addDays('Y-m-d', n)`, `addMonths('Y-m', n)`, `monthsBetween('Y-m', 'Y-m')`, `toMinutes('H:i')`, `KIND_DOT`, `KIND_BLOCK`, `KIND_BORDER`.
- Produces: partials emit `navigate(params, options?)`; `Index.vue` turns it into `router.get(route('attendance.index'), { view, category_id, ...params })` with empty values dropped. `ViewSwitcher` props `view`, emits `switch(view)`.
- Produces: i18n keys `att.views`, `att.view.month`, `att.view.week`, `att.today`, `att.prev_week`, `att.next_week`, `att.prev_month`, `att.next_month`, `att.no_sessions_week`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceWeekViewTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceWeekViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_calendar_defaults_to_the_month_view_and_rejects_unknown_views(): void
    {
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')->where('view', 'month'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'year']))
            ->assertSessionHasErrors('view');
    }

    #[Test]
    public function the_week_view_generates_and_lists_every_category_for_the_week(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-01-01']);
        $joint = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-31', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned,
        ]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);

        // Wednesday 2026-10-28: the week runs Monday 26 October to Sunday 1 November.
        $this->actingAs($this->admin())->get(route('attendance.index', ['view' => 'week', 'date' => '2026-10-28']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'week')
                ->where('categoryId', null)
                ->where('week.start', '2026-10-26')
                ->where('week.end', '2026-11-01')
                ->where('month', '2026-10')
                ->has('sessions', 3)
                ->where('sessions.0.date', '2026-10-26')
                ->where('sessions.1.date', '2026-10-28')
                ->where('sessions.1.categories.0.id', $u17->id)
                ->where('sessions.2.id', $joint->id)
                ->has('sessions.2.categories', 2));

        // The week touches November, so November was generated too.
        $this->assertTrue(TrainingSession::where('category_id', $u15->id)->where('date', '2026-11-02')->exists());
    }

    #[Test]
    public function the_week_view_defaults_to_today_or_to_the_first_of_the_month_it_was_switched_from(): void
    {
        Carbon::setTestNow('2026-10-08 10:00'); // a Thursday
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'week']))
            ->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-10-05'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'week', 'month' => '2026-12']))
            ->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-11-30')->where('month', '2026-11'));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceWeekViewTest`
Expected: FAIL: property `view` does not exist, and `view=year` is not rejected.

- [ ] **Step 3: The feed generates every category's range**

Replace the whole of `app/Services/Attendance/CalendarFeed.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sessions shaped for the calendar views: every category taking part
 * (primary first), title, marked count and, once held, how many players had
 * each status.
 */
final class CalendarFeed
{
    public function __construct(private readonly SessionGenerator $generator) {}

    /** Generates every category's planned sessions for each month the range touches (idempotent). */
    public function generateAll(string $from, string $to): void
    {
        $categoryIds = Category::orderBy('id')->pluck('id');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();

        for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            foreach ($categoryIds as $categoryId) {
                $this->generator->forMonth((int) $categoryId, $month->year, $month->month);
            }
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null): Collection
    {
        $sessions = TrainingSession::query()
            ->when($categoryId !== null, fn ($q) => $q->includingCategory($categoryId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->whereBetween('date', [$from, $to])
            ->with('categories')
            ->withCount('attendances')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $summaries = $this->summaries(
            $sessions->filter(fn (TrainingSession $s) => $s->state === SessionState::Held)->modelKeys(),
        );

        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'kind' => $s->kind->value,
            'state' => $s->state->value,
            'title' => $s->title,
            'cancel_reason' => $s->cancel_reason,
            'categories' => $s->categories
                ->sortBy(fn (Category $c) => $c->id === $s->category_id ? 0 : $c->id)
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])
                ->values()->all(),
            'marked' => $s->attendances_count,
            'summary' => $summaries[$s->id] ?? null,
        ])->values();
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, array<string, int>> per session: status => number of players
     */
    public function summaries(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $rows = Attendance::query()
            ->whereIn('training_session_id', $sessionIds)
            ->selectRaw('training_session_id, status, count(*) as total')
            ->groupBy('training_session_id', 'status')
            ->toBase()
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row->training_session_id][$row->status] = (int) $row->total;
        }

        return $summaries;
    }
}
```

- [ ] **Step 4: The controller dispatches on `view`**

Replace the whole of `app/Http/Controllers/AttendanceCalendarController.php` with:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\TrainingSchedule;
use App\Services\Attendance\CalendarFeed;
use App\Services\Attendance\PreseasonProgress;
use App\Services\Attendance\SessionGenerator;
use App\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attendance calendar: one route (`attendance.index`, gated as view by
 * its name) with a `view` query parameter. Month shows one category; the
 * other views show every category and first generate the shown range for
 * all of them (the generator is idempotent).
 */
class AttendanceCalendarController extends Controller
{
    public const VIEWS = ['month', 'week'];

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly CalendarFeed $feed,
        private readonly PreseasonProgress $preseason,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $view = $data['view'] ?? 'month';
        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])->values();

        $props = match ($view) {
            'week' => $this->week($data),
            default => $this->month($data, $categories),
        };

        return Inertia::render('Attendance/Index', [
            'view' => $view,
            'categories' => $categories,
            'attendanceCodes' => AttendanceSettings::codes(),
            ...$props,
        ]);
    }

    /** One category's month. Opening it generates its planned sessions. */
    private function month(array $data, Collection $categories): array
    {
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : data_get($categories->first(), 'id');
        $anchor = $this->monthAnchor($data);
        $props = ['categoryId' => $categoryId, 'month' => $anchor->format('Y-m'), 'sessions' => [], 'preseason' => null, 'hasSchedule' => false];

        if ($categoryId === null) {
            return $props;
        }

        $this->generator->forMonth($categoryId, $anchor->year, $anchor->month);

        return [
            ...$props,
            'sessions' => $this->feed->sessions($anchor->toDateString(), $anchor->endOfMonth()->toDateString(), $categoryId),
            'preseason' => $this->preseason->forCategory($categoryId, $anchor),
            'hasSchedule' => TrainingSchedule::where('category_id', $categoryId)->exists(),
        ];
    }

    /** Monday to Sunday around `date`, every category. */
    private function week(array $data): array
    {
        $day = isset($data['date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['date']) : $this->defaultDay($data);
        $start = $day->subDays($day->dayOfWeekIso - 1);
        $end = $start->addDays(6);
        $this->feed->generateAll($start->toDateString(), $end->toDateString());

        return [
            'categoryId' => $this->categoryFilter($data),
            'month' => $start->format('Y-m'),
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'sessions' => $this->feed->sessions($start->toDateString(), $end->toDateString()),
        ];
    }

    private function monthAnchor(array $data): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
    }

    /** Today when no month is given or it is the current one, else that month's first day. */
    private function defaultDay(array $data): CarbonImmutable
    {
        $month = $data['month'] ?? null;

        return $month === null || $month === now()->format('Y-m')
            ? CarbonImmutable::createFromFormat('!Y-m-d', now()->toDateString())
            : CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
    }

    /** Views other than month show every category unless one is picked. */
    private function categoryFilter(array $data): ?int
    {
        return isset($data['category_id']) ? (int) $data['category_id'] : null;
    }
}
```

- [ ] **Step 5: Shared calendar helpers**

Create `resources/js/lib/attendanceCalendar.js`:
```js
/**
 * Date helpers and kind colours shared by the attendance calendar views.
 * Dates travel as local 'Y-m-d' strings and months as 'Y-m' strings, never
 * through toISOString() (that is the UTC date and can be a day off).
 */
const pad = (n) => String(n).padStart(2, '0');

export const dateKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const monthKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
export const parseDay = (key) => new Date(`${key}T00:00:00`);

export function addDays(key, n) {
    const d = parseDay(key);
    d.setDate(d.getDate() + n);
    return dateKey(d);
}

export function addMonths(month, n) {
    const d = parseDay(`${month}-01`);
    d.setMonth(d.getMonth() + n);
    return monthKey(d);
}

/** Whole months from `from` to `to` ('Y-m'); 0 when equal. */
export function monthsBetween(from, to) {
    const [fy, fm] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);
    return (ty - fy) * 12 + (tm - fm);
}

export function toMinutes(hhmm) {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
}

export const KIND_DOT = { regular: 'bg-primary-500', preseason: 'bg-amber-500', extra: 'bg-violet-500' };
export const KIND_BORDER = { regular: 'border-primary-500', preseason: 'border-amber-500', extra: 'border-violet-500' };
export const KIND_BLOCK = {
    regular: 'border-primary-500 bg-primary-50 text-primary-900 dark:bg-primary-500/15 dark:text-primary-100',
    preseason: 'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-500/15 dark:text-amber-100',
    extra: 'border-violet-500 bg-violet-50 text-violet-900 dark:bg-violet-500/15 dark:text-violet-100',
};
```

- [ ] **Step 6: View switcher**

Create `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`:
```vue
<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ view: { type: String, required: true } });
const emit = defineEmits(['switch']);
const { t } = useI18n();

// Keep in step with AttendanceCalendarController::VIEWS.
const VIEWS = ['month', 'week'];
</script>

<template>
    <div role="tablist" :aria-label="t('att.views')" class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
        <button
            v-for="v in VIEWS"
            :key="v"
            type="button"
            role="tab"
            :aria-selected="view === v"
            class="rounded-md px-3 py-1 text-sm font-semibold"
            :class="view === v ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
            @click="view !== v && emit('switch', v)"
        >{{ t(`att.view.${v}`) }}</button>
    </div>
</template>
```

- [ ] **Step 7: Month view partial**

Create `resources/js/Pages/Attendance/Partials/MonthView.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_DOT, addMonths, dateKey, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { statuses, color, summaryText } = useAttendanceCodes();

const todayKey = dateKey(new Date());
const anchor = computed(() => parseDay(`${props.month}-01`));
const monthLabel = computed(() => anchor.value.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(2024, 0, 1 + i)).toLocaleDateString(locale.value, { weekday: 'short', timeZone: 'UTC' })));

const byDate = computed(() => props.sessions.reduce((acc, s) => ((acc[s.date] ??= []).push(s), acc), {}));

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = anchor.value;
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const k = dateKey(d);
        return { key: k, day: d.getDate(), inMonth: d.getMonth() === first.getMonth(), sessions: byDate.value[k] ?? [] };
    });
});

const go = (params) => emit('navigate', { category_id: props.categoryId, month: props.month, ...params });

const chip = {
    planned: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title, summaryText(s.summary)]
    .filter(Boolean).join(' · ');

const preseasonLabel = computed(() => {
    if (!props.preseason) return '';
    const { season, done, target } = props.preseason;
    return target === null ? t('att.preseason_no_target', { season, done }) : t('att.preseason_progress', { season, done, target });
});
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId" :class="input" :aria-label="t('att.category')" @change="go({ category_id: Number($event.target.value) })">
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_month')" @click="go({ month: addMonths(month, -1) })"><Icon name="back" /></button>
            <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_month')" @click="go({ month: addMonths(month, 1) })"><Icon name="back" /></button>
            <span v-if="preseason" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ preseasonLabel }}</span>
        </div>

        <p v-if="!hasSchedule" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.no_schedule') }}</p>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-800">
            <div v-for="w in weekdays" :key="w" class="bg-slate-50 p-2 text-center text-xs font-semibold text-slate-500 dark:bg-slate-900">{{ w }}</div>
            <div v-for="cell in cells" :key="cell.key" class="min-h-[5.5rem] min-w-0 bg-white p-1.5 dark:bg-slate-900" :class="{ 'opacity-40': !cell.inMonth }">
                <div class="mb-1 text-xs font-semibold" :class="cell.key === todayKey ? 'text-primary-600' : 'text-slate-400'">{{ cell.day }}</div>
                <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 block rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                    <span class="flex items-center gap-1">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="KIND_DOT[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </span>
                    <span v-if="s.summary" class="mt-0.5 flex h-1 overflow-hidden rounded-full">
                        <span v-for="st in statuses" v-show="s.summary[st]" :key="st" :style="{ backgroundColor: color(st), flexGrow: s.summary[st] ?? 0 }"></span>
                    </span>
                </Link>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 text-xs text-slate-500">
            <span v-for="(cls, kind) in KIND_DOT" :key="kind" class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="cls"></span>{{ t(`att.kind.${kind}`) }}</span>
        </div>
    </div>
</template>
```

- [ ] **Step 8: Week view partial**

Create `resources/js/Pages/Attendance/Partials/WeekView.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { KIND_BLOCK, addDays, dateKey, parseDay, toMinutes } from '@/lib/attendanceCalendar';

const props = defineProps({
    week: { type: Object, required: true }, // { start, end } 'Y-m-d', Monday to Sunday
    sessions: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();

const HOUR_PX = 48;
const today = dateKey(new Date());
const days = computed(() => Array.from({ length: 7 }, (_, i) => addDays(props.week.start, i)));
const dayLabel = (key) => parseDay(key).toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric', month: 'short' });
const rangeLabel = computed(() => `${dayLabel(props.week.start)} – ${dayLabel(props.week.end)}`);

// Hour rows from the earliest start to the latest end of the week, never less than 08:00–20:00.
const firstHour = computed(() => Math.min(8, ...props.sessions.map((s) => Math.floor(toMinutes(s.start_time) / 60))));
const lastHour = computed(() => Math.max(20, ...props.sessions.map((s) => Math.ceil(toMinutes(s.end_time) / 60))));
const hours = computed(() => Array.from({ length: lastHour.value - firstHour.value }, (_, i) => firstHour.value + i));
const height = computed(() => hours.value.length * HOUR_PX);

/**
 * Overlapping sessions sit side by side: sessions are grouped into clusters
 * that overlap in time, and each takes the first free lane of its cluster.
 */
function layoutDay(list) {
    const sorted = [...list].sort((a, b) => a.start_time.localeCompare(b.start_time) || a.end_time.localeCompare(b.end_time));
    const placed = [];
    let cluster = [];
    let clusterEnd = '';
    const flush = () => {
        const laneEnds = [];
        const members = cluster.map((session) => {
            let lane = laneEnds.findIndex((end) => end <= session.start_time);
            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(session.end_time);
            } else {
                laneEnds[lane] = session.end_time;
            }
            return { session, lane };
        });
        members.forEach((m) => placed.push({ ...m, lanes: laneEnds.length }));
        cluster = [];
        clusterEnd = '';
    };
    for (const s of sorted) {
        if (cluster.length && s.start_time >= clusterEnd) flush();
        cluster.push(s);
        if (s.end_time > clusterEnd) clusterEnd = s.end_time;
    }
    if (cluster.length) flush();
    return placed;
}
const blocks = computed(() => Object.fromEntries(days.value.map((d) => [d, layoutDay(props.sessions.filter((s) => s.date === d))])));

function blockStyle({ session, lane, lanes }) {
    const top = ((toMinutes(session.start_time) - firstHour.value * 60) / 60) * HOUR_PX;
    const tall = Math.max(22, ((toMinutes(session.end_time) - toMinutes(session.start_time)) / 60) * HOUR_PX);
    return { top: `${top}px`, height: `${tall}px`, insetInlineStart: `${(lane * 100) / lanes}%`, width: `calc(${100 / lanes}% - 2px)` };
}
const blockTitle = (s) => [`${s.start_time}–${s.end_time}`, t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title]
    .filter(Boolean).join(' · ');
const go = (date) => emit('navigate', { date });
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_week')" @click="go(addDays(week.start, -7))"><Icon name="back" /></button>
            <span class="min-w-[12rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ rangeLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_week')" @click="go(addDays(week.start, 7))"><Icon name="back" /></button>
            <button class="rounded-lg px-3 py-1 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800" @click="go(today)">{{ t('att.today') }}</button>
        </div>

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions_week') }}</p>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid min-w-[48rem]" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
                <div class="border-b border-slate-100 dark:border-slate-800"></div>
                <div v-for="d in days" :key="d" class="border-b border-s border-slate-100 p-2 text-center text-xs font-semibold capitalize dark:border-slate-800" :class="d === today ? 'text-primary-600' : 'text-slate-500'">{{ dayLabel(d) }}</div>

                <div class="relative" :style="{ height: `${height}px` }">
                    <div v-for="(h, i) in hours" :key="h" class="absolute inset-x-0 pe-1 text-end text-[10px] text-slate-400" :style="{ top: `${i * HOUR_PX}px` }"><span dir="ltr">{{ String(h).padStart(2, '0') }}:00</span></div>
                </div>
                <div v-for="d in days" :key="`col-${d}`" class="relative border-s border-slate-100 dark:border-slate-800" :class="{ 'bg-primary-50/40 dark:bg-primary-500/5': d === today }" :style="{ height: `${height}px` }">
                    <div v-for="(h, i) in hours" :key="h" class="absolute inset-x-0 border-t border-slate-100 dark:border-slate-800" :style="{ top: `${i * HOUR_PX}px` }"></div>
                    <Link
                        v-for="b in blocks[d]"
                        :key="b.session.id"
                        :href="route('attendance.sessions.show', b.session.id)"
                        class="absolute overflow-hidden rounded-md border-s-4 p-1 text-[11px] leading-tight shadow-sm hover:z-10 hover:shadow"
                        :class="[KIND_BLOCK[b.session.kind], b.session.state === 'cancelled' ? 'line-through opacity-60' : '']"
                        :style="blockStyle(b)"
                        :title="blockTitle(b.session)"
                    >
                        <div class="font-semibold"><span dir="ltr">{{ b.session.start_time }}–{{ b.session.end_time }}</span><span v-if="b.session.state === 'held'" class="ms-1">✓</span></div>
                        <div class="truncate">{{ b.session.categories.map((c) => c.name).join(' · ') }}</div>
                        <div v-if="b.session.title" class="truncate opacity-80">{{ b.session.title }}</div>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 9: Index becomes the shell**

Replace the whole of `resources/js/Pages/Attendance/Index.vue` with:
```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { dateKey } from '@/lib/attendanceCalendar';
import AddSessionModal from './Partials/AddSessionModal.vue';
import ViewSwitcher from './Partials/ViewSwitcher.vue';
import MonthView from './Partials/MonthView.vue';
import WeekView from './Partials/WeekView.vue';

const props = defineProps({
    view: { type: String, default: 'month' },
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM, the anchor kept when switching views
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
    week: { type: Object, default: null }, // { start, end } in the week view
});
const { t } = useI18n();
const { can } = useCan();
const today = dateKey(new Date());

// Empty values are dropped so the URL only carries what is set. The category
// travels along unless a view sets it (null = every category).
const clean = (params) => Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
function navigate(params, options = {}) {
    router.get(route('attendance.index'), clean({ view: props.view, category_id: props.categoryId, ...params }), { preserveScroll: true, ...options });
}
const switchView = (view) => navigate({ view, month: props.month }, { preserveScroll: false });

// ---- Add an extra / pre-season session ----
const showCreate = ref(false);
const createKind = ref('extra');
function openCreate(kind) {
    createKind.value = kind;
    showCreate.value = true;
}
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h1>
                <div class="flex gap-2">
                    <Link v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.grid') }}</Link>
                    <Link v-if="can('attendance', 'edit')" :href="route('attendance.settings')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="settings" />{{ t('att.settings') }}</Link>
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <ViewSwitcher :view="view" @switch="switchView" />
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <button class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700" @click="openCreate('extra')">+ {{ t('att.add_extra') }}</button>
                    <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600" @click="openCreate('preseason')">+ {{ t('att.add_preseason') }}</button>
                </div>
            </div>

            <MonthView v-if="view === 'month'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" :preseason="preseason" :has-schedule="hasSchedule" @navigate="navigate" />
            <WeekView v-else-if="view === 'week'" :week="week" :sessions="sessions" @navigate="navigate" />
        </div>

        <AddSessionModal :show="showCreate" :kind="createKind" :categories="categories" :category-id="categoryId" :date="today" @close="showCreate = false" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 10: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.views': ['Calendar view', 'Affichage', 'طريقة العرض'],
    'att.view.month': ['Month', 'Mois', 'شهر'],
    'att.view.week': ['Week', 'Semaine', 'أسبوع'],
    'att.today': ['Today', "Aujourd'hui", 'اليوم'],
    'att.prev_week': ['Previous week', 'Semaine précédente', 'الأسبوع السابق'],
    'att.next_week': ['Next week', 'Semaine suivante', 'الأسبوع التالي'],
    'att.prev_month': ['Previous month', 'Mois précédent', 'الشهر السابق'],
    'att.next_month': ['Next month', 'Mois suivant', 'الشهر التالي'],
    'att.no_sessions_week': ['No sessions this week.', 'Aucune séance cette semaine.', 'لا توجد حصص هذا الأسبوع.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 11: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceWeekViewTest|AttendanceCalendarTest|AttendanceJointCalendarTest|AttendanceCodesOnPagesTest|AttendanceSettingsPageTest|AttendancePermissionTest" && npm run i18n:check`
Expected: PASS. `attendance.index` still resolves to `['attendance', 'view']`.

- [ ] **Step 12: Commit**

```bash
git add resources/js/lib/attendanceCalendar.js resources/js/Pages/Attendance/Partials/ViewSwitcher.vue resources/js/Pages/Attendance/Partials/MonthView.vue resources/js/Pages/Attendance/Partials/WeekView.vue resources/js/Pages/Attendance/Index.vue app/Http/Controllers/AttendanceCalendarController.php app/Services/Attendance/CalendarFeed.php resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceWeekViewTest.php
git commit -m "feat(attendance): calendar view switcher with a week view" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Agenda view

**Files:**
- Create: `resources/js/Pages/Attendance/Partials/AgendaView.vue`
- Modify: `app/Http/Controllers/AttendanceCalendarController.php`, `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`, `resources/js/Pages/Attendance/Index.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendanceAgendaViewTest.php` (new)

**Interfaces:**
- Consumes: `CalendarFeed::generateAll()`, `sessions()` (Task 7); `navigate` / `ViewSwitcher` (Task 7); `useAttendanceCodes().summaryText` (Task 6).
- Produces: `view=agenda` with `month=Y-m` and optional `category_id`: props `categoryId` (filter or `null`), `month`, `sessions` (the month, every category or those including the filter, chronological).
- Produces: `Partials/AgendaView.vue` props `categories`, `categoryId`, `month`, `sessions`; emits `navigate`.
- Produces: i18n keys `att.view.agenda`, `att.all_categories`, `att.col.time`, `att.col.kind`, `att.col.state`, `att.col.marked`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceAgendaViewTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAgendaViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_agenda_lists_the_month_for_every_category_or_one(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        // October 2026: four Mondays for U15, four Wednesdays for U17.
        TrainingSchedule::create(['category_id' => $u15->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01']);
        TrainingSchedule::create(['category_id' => $u17->id, 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-01-01']);
        $joint = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-03', 'start_time' => '09:00', 'end_time' => '10:30',
            'kind' => SessionKind::Preseason, 'state' => SessionState::Planned, 'title' => 'Endurance',
        ]);
        $joint->categories()->syncWithoutDetaching([$u17->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'agenda', 'month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'agenda')
                ->where('categoryId', null)
                ->where('month', '2026-10')
                ->has('sessions', 9)
                ->where('sessions.0.id', $joint->id)
                ->where('sessions.0.title', 'Endurance')
                ->where('sessions.1.date', '2026-10-05')
                ->where('sessions.2.date', '2026-10-07'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'agenda', 'month' => '2026-10', 'category_id' => $u17->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $u17->id)
                ->has('sessions', 5)
                ->where('sessions.0.id', $joint->id));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceAgendaViewTest`
Expected: FAIL: `view=agenda` is rejected by validation (the selected view is invalid).

- [ ] **Step 3: Controller**

In `app/Http/Controllers/AttendanceCalendarController.php`, replace
```php
    public const VIEWS = ['month', 'week'];
```
with
```php
    public const VIEWS = ['month', 'week', 'agenda'];
```
replace
```php
            'week' => $this->week($data),
            default => $this->month($data, $categories),
```
with
```php
            'week' => $this->week($data),
            'agenda' => $this->agenda($data),
            default => $this->month($data, $categories),
```
and add this method right after `week()`:
```php
    /** The month as a chronological list, every category or those including the filter. */
    private function agenda(array $data): array
    {
        $anchor = $this->monthAnchor($data);
        $from = $anchor->toDateString();
        $to = $anchor->endOfMonth()->toDateString();
        $this->feed->generateAll($from, $to);
        $categoryId = $this->categoryFilter($data);

        return [
            'categoryId' => $categoryId,
            'month' => $anchor->format('Y-m'),
            'sessions' => $this->feed->sessions($from, $to, $categoryId),
        ];
    }
```

- [ ] **Step 4: Agenda partial**

Create `resources/js/Pages/Attendance/Partials/AgendaView.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_DOT, addMonths, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // null = every category
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { summaryText } = useAttendanceCodes();

const monthLabel = computed(() => parseDay(`${props.month}-01`).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }));
const dayLabel = (key) => parseDay(key).toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric', month: 'short' });
const go = (params) => emit('navigate', { category_id: props.categoryId, month: props.month, ...params });
const pickCategory = (value) => go({ category_id: value ? Number(value) : null });

const stateClass = { planned: 'text-slate-500', held: 'text-emerald-600 dark:text-emerald-400', cancelled: 'text-slate-400' };
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                <option value="">{{ t('att.all_categories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_month')" @click="go({ month: addMonths(month, -1) })"><Icon name="back" /></button>
            <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_month')" @click="go({ month: addMonths(month, 1) })"><Icon name="back" /></button>
        </div>

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                        <th class="p-2 text-start font-semibold">{{ t('att.date') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.time') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.kind') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.categories') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.title_goal') }}</th>
                        <th class="p-2 text-start font-semibold">{{ t('att.col.state') }}</th>
                        <th class="p-2 text-end font-semibold">{{ t('att.col.marked') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions" :key="s.id" class="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40" :class="{ 'text-slate-400 line-through': s.state === 'cancelled' }">
                        <td class="whitespace-nowrap p-2 font-medium capitalize">
                            <Link :href="route('attendance.sessions.show', s.id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ dayLabel(s.date) }}</Link>
                        </td>
                        <td class="whitespace-nowrap p-2"><span dir="ltr">{{ s.start_time }}–{{ s.end_time }}</span></td>
                        <td class="whitespace-nowrap p-2">
                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="KIND_DOT[s.kind]"></span>{{ t(`att.kind.${s.kind}`) }}</span>
                        </td>
                        <td class="p-2">{{ s.categories.map((c) => c.name).join(' · ') }}</td>
                        <td class="max-w-[16rem] truncate p-2" :title="s.title ?? ''">{{ s.title }}</td>
                        <td class="whitespace-nowrap p-2" :class="stateClass[s.state]" :title="s.cancel_reason ?? ''">{{ t(`att.state.${s.state}`) }}</td>
                        <td class="whitespace-nowrap p-2 text-end" :title="summaryText(s.summary)">{{ s.marked ? t('att.marked', { n: s.marked }) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Wire it into the switcher and the shell**

In `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`, replace
```js
const VIEWS = ['month', 'week'];
```
with
```js
const VIEWS = ['month', 'week', 'agenda'];
```

In `resources/js/Pages/Attendance/Index.vue`, replace
```js
import WeekView from './Partials/WeekView.vue';
```
with
```js
import WeekView from './Partials/WeekView.vue';
import AgendaView from './Partials/AgendaView.vue';
```
and replace
```html
            <WeekView v-else-if="view === 'week'" :week="week" :sessions="sessions" @navigate="navigate" />
```
with
```html
            <WeekView v-else-if="view === 'week'" :week="week" :sessions="sessions" @navigate="navigate" />
            <AgendaView v-else-if="view === 'agenda'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" @navigate="navigate" />
```

- [ ] **Step 6: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.view.agenda': ['Agenda', 'Agenda', 'القائمة'],
    'att.all_categories': ['All categories', 'Toutes les catégories', 'كل الفئات'],
    'att.col.time': ['Time', 'Heure', 'الوقت'],
    'att.col.kind': ['Type', 'Type', 'النوع'],
    'att.col.state': ['State', 'État', 'الوضعية'],
    'att.col.marked': ['Marked', 'Saisis', 'المسجلون'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 7: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceAgendaViewTest|AttendanceWeekViewTest|AttendanceCalendarTest" && npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Attendance/Partials/AgendaView.vue app/Http/Controllers/AttendanceCalendarController.php resources/js/Pages/Attendance/Partials/ViewSwitcher.vue resources/js/Pages/Attendance/Index.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceAgendaViewTest.php
git commit -m "feat(attendance): agenda view of the month" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Timeline view (sessions, closures, pre-season milestones)

**Files:**
- Create: `resources/js/Pages/Attendance/Partials/TimelineView.vue`
- Modify: `app/Services/Attendance/PreseasonProgress.php`, `app/Services/Attendance/CalendarFeed.php`, `app/Http/Controllers/AttendanceCalendarController.php`, `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`, `resources/js/Pages/Attendance/Index.vue`, `resources/js/i18n/{en,fr,ar}.json`
- Test: `tests/Feature/AttendanceTimelineViewTest.php` (new)

**Interfaces:**
- Consumes: `CalendarFeed::sessions()`, `generateAll()` (Task 7); `PreseasonProgress::held()` (Task 4); `navigate` (Task 7).
- Produces: `PreseasonProgress::milestones(string $from, string $to, ?int $categoryId = null): array` of `{milestone: 'started'|'completed', date, time, session_id, category_id, category, done: ?int, target: ?int}` dated inside `[from, to]`.
- Produces: `CalendarFeed::__construct(SessionGenerator $generator, PreseasonProgress $preseason)` and `CalendarFeed::timeline(string $from, string $to, ?int $categoryId = null, ?string $kind = null): array` of events sorted by date, then closure / session / milestone, then time. Every event has `type` (`session`|`closure`|`milestone`), `key` (unique), `date`. Sessions carry the `sessions()` fields; closures carry `start_date`, `end_date`, `reason` (`date` is clipped to the range start); milestones are left out when `kind` is set to anything but `preseason`.
- Produces: `view=timeline` with `from=Y-m`, `to=Y-m` (default: `month`, else the current month; swapped if reversed; at most `TIMELINE_MAX_MONTHS = 12`, keeping `to`), optional `category_id` and `kind`: props `categoryId`, `kind`, `from`, `to`, `month` (= `to`), `events`.
- Produces: `Partials/TimelineView.vue` props `categories`, `categoryId`, `kind`, `from`, `to`, `events`; emits `navigate`.
- Produces: i18n keys `att.view.timeline`, `att.all_kinds`, `att.earlier`, `att.later`, `att.closure`, `att.milestone.started`, `att.milestone.completed`, `att.no_events`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceTimelineViewTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\ClubClosure;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceTimelineViewTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_timeline_merges_sessions_closures_and_preseason_milestones_in_order(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        PreseasonTarget::create(['category_id' => $u15->id, 'season_start_year' => 2026, 'target_count' => 2]);
        $base = ['category_id' => $u15->id, 'start_time' => '09:00', 'end_time' => '10:30', 'kind' => SessionKind::Preseason, 'state' => SessionState::Held];

        $first = TrainingSession::create($base + ['date' => '2026-09-02', 'title' => 'Endurance']);
        $first->categories()->syncWithoutDetaching([$u17->id]);
        Attendance::create(['training_session_id' => $first->id, 'player_id' => $this->player($u15)->id, 'status' => AttendanceStatus::Present]);
        $second = TrainingSession::create($base + ['date' => '2026-09-04']);
        TrainingSession::create(['date' => '2026-09-10', 'kind' => SessionKind::Extra, 'state' => SessionState::Cancelled, 'cancel_reason' => 'Rain'] + $base);
        ClubClosure::create(['start_date' => '2026-08-25', 'end_date' => '2026-09-01', 'reason' => 'Summer']);

        $this->actingAs($this->admin())->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Index')
                ->where('view', 'timeline')
                ->where('from', '2026-09')
                ->where('to', '2026-09')
                ->where('month', '2026-09')
                ->has('events', 7)
                ->where('events.0.type', 'closure')
                ->where('events.0.date', '2026-09-01')
                ->where('events.0.reason', 'Summer')
                ->where('events.1.type', 'session')
                ->where('events.1.id', $first->id)
                ->where('events.1.title', 'Endurance')
                ->where('events.1.summary.present', 1)
                ->where('events.2.type', 'milestone')
                ->where('events.2.milestone', 'started')
                ->where('events.2.category_id', $u15->id)
                ->where('events.3.milestone', 'started')
                ->where('events.3.category_id', $u17->id)
                ->where('events.4.id', $second->id)
                ->where('events.5.milestone', 'completed')
                ->where('events.5.done', 2)
                ->where('events.5.target', 2)
                ->where('events.6.state', 'cancelled')
                ->where('events.6.cancel_reason', 'Rain'));
    }

    #[Test]
    public function the_timeline_filters_by_category_and_kind(): void
    {
        $u15 = $this->category();
        $u17 = $this->category('U17');
        $base = ['date' => '2026-09-02', 'start_time' => '09:00', 'end_time' => '10:30'];
        TrainingSession::create($base + ['category_id' => $u15->id, 'kind' => SessionKind::Preseason, 'state' => SessionState::Held]);
        $extra = TrainingSession::create($base + ['category_id' => $u17->id, 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09', 'category_id' => $u17->id]))
            ->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.id', $extra->id));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09', 'kind' => 'extra']))
            ->assertInertia(fn (Assert $page) => $page->where('kind', 'extra')->has('events', 1)->where('events.0.id', $extra->id));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->has('events', 3)); // 2 sessions + U15 "started"
    }

    #[Test]
    public function the_timeline_window_is_ordered_and_capped_at_twelve_months(): void
    {
        $this->category();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'from' => '2025-01', 'to' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('from', '2025-11')->where('to', '2026-10'));

        $this->actingAs($admin)->get(route('attendance.index', ['view' => 'timeline', 'from' => '2026-10', 'to' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->where('from', '2026-09')->where('to', '2026-10'));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceTimelineViewTest`
Expected: FAIL: `view=timeline` is rejected by validation.

- [ ] **Step 3: Pre-season milestones**

Replace the whole of `app/Services/Attendance/PreseasonProgress.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use App\Support\Season;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pre-season progress per category and season. A joint pre-season session
 * counts once for every category in it (owner decision), so this reads the
 * session-category pivot, never `training_sessions.category_id` alone.
 */
final class PreseasonProgress
{
    /** @return array{season: string, done: int, target: ?int} */
    public function forCategory(int $categoryId, DateTimeInterface|string $date): array
    {
        $season = Season::forDate($date);

        return [
            'season' => $season->label(),
            'done' => $this->held($categoryId, $season)->count(),
            'target' => PreseasonTarget::where('category_id', $categoryId)
                ->where('season_start_year', $season->startYear)->value('target_count'),
        ];
    }

    /**
     * Per category and season: "started" at its first held pre-season
     * session, "completed" at the held session whose count reaches the
     * target (none without a target). Only milestones dated in [from, to].
     *
     * @return array<int, array{milestone: string, date: string, time: string, session_id: int, category_id: int, category: string, done: ?int, target: ?int}>
     */
    public function milestones(string $from, string $to, ?int $categoryId = null): array
    {
        $categories = Category::query()->when($categoryId !== null, fn ($q) => $q->whereKey($categoryId))->orderBy('id')->get();
        // The window is at most 12 months, so it spans one or two seasons.
        $seasons = collect([Season::forDate($from), Season::forDate($to)])->unique(fn (Season $s) => $s->startYear);
        $events = [];

        foreach ($seasons as $season) {
            $targets = PreseasonTarget::where('season_start_year', $season->startYear)->pluck('target_count', 'category_id');

            foreach ($categories as $category) {
                $held = $this->held($category->id, $season)
                    ->orderBy('date')->orderBy('start_time')->orderBy('id')
                    ->get(['id', 'date', 'start_time']);
                if ($held->isEmpty()) {
                    continue;
                }

                $target = $targets->get($category->id);
                $reached = [['started', $held->first(), null]];
                if ($target > 0 && $held->count() >= $target) {
                    $reached[] = ['completed', $held[$target - 1], (int) $target];
                }

                foreach ($reached as [$milestone, $session, $count]) {
                    if ($session->date < $from || $session->date > $to) {
                        continue;
                    }
                    $events[] = [
                        'milestone' => $milestone,
                        'date' => $session->date,
                        'time' => $session->start_time,
                        'session_id' => $session->id,
                        'category_id' => $category->id,
                        'category' => $category->localized_name,
                        'done' => $count,
                        'target' => $count,
                    ];
                }
            }
        }

        return $events;
    }

    /** Held pre-season sessions of the season that include the category. */
    private function held(int $categoryId, Season $season): Builder
    {
        return TrainingSession::query()
            ->includingCategory($categoryId)
            ->where('kind', SessionKind::Preseason->value)
            ->where('state', SessionState::Held->value)
            ->whereBetween('date', [$season->start()->toDateString(), $season->end()->toDateString()]);
    }
}
```

- [ ] **Step 4: Timeline events in the feed**

Replace the whole of `app/Services/Attendance/CalendarFeed.php` with:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sessions shaped for the calendar views: every category taking part
 * (primary first), title, marked count and, once held, how many players had
 * each status. The timeline adds club closures and pre-season milestones.
 */
final class CalendarFeed
{
    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly PreseasonProgress $preseason,
    ) {}

    /** Generates every category's planned sessions for each month the range touches (idempotent). */
    public function generateAll(string $from, string $to): void
    {
        $categoryIds = Category::orderBy('id')->pluck('id');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth();

        for ($month = CarbonImmutable::createFromFormat('!Y-m-d', $from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            foreach ($categoryIds as $categoryId) {
                $this->generator->forMonth((int) $categoryId, $month->year, $month->month);
            }
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function sessions(string $from, string $to, ?int $categoryId = null, ?string $kind = null): Collection
    {
        $sessions = TrainingSession::query()
            ->when($categoryId !== null, fn ($q) => $q->includingCategory($categoryId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->whereBetween('date', [$from, $to])
            ->with('categories')
            ->withCount('attendances')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $summaries = $this->summaries(
            $sessions->filter(fn (TrainingSession $s) => $s->state === SessionState::Held)->modelKeys(),
        );

        return $sessions->map(fn (TrainingSession $s) => [
            'id' => $s->id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'kind' => $s->kind->value,
            'state' => $s->state->value,
            'title' => $s->title,
            'cancel_reason' => $s->cancel_reason,
            'categories' => $s->categories
                ->sortBy(fn (Category $c) => $c->id === $s->category_id ? 0 : $c->id)
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->localized_name])
                ->values()->all(),
            'marked' => $s->attendances_count,
            'summary' => $summaries[$s->id] ?? null,
        ])->values();
    }

    /**
     * Sessions, club closures and pre-season milestones in [from, to], in
     * date order; on one date a closure comes first and a milestone right
     * after the session that reached it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(string $from, string $to, ?int $categoryId = null, ?string $kind = null): array
    {
        $events = $this->sessions($from, $to, $categoryId, $kind)
            ->map(fn (array $s) => $s + ['type' => 'session', 'key' => "s{$s['id']}", 'order' => 1, 'time' => $s['start_time']])
            ->all();

        $closures = ClubClosure::where('start_date', '<=', $to)->where('end_date', '>=', $from)->orderBy('start_date')->get();
        foreach ($closures as $closure) {
            $events[] = [
                'type' => 'closure', 'key' => "c{$closure->id}", 'order' => 0, 'time' => '00:00',
                'date' => max($closure->start_date, $from),
                'start_date' => $closure->start_date, 'end_date' => $closure->end_date, 'reason' => $closure->reason,
            ];
        }

        if ($kind === null || $kind === SessionKind::Preseason->value) {
            foreach ($this->preseason->milestones($from, $to, $categoryId) as $m) {
                $events[] = $m + ['type' => 'milestone', 'key' => "m{$m['milestone']}-{$m['category_id']}-{$m['session_id']}", 'order' => 2];
            }
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['order'], $a['time']] <=> [$b['date'], $b['order'], $b['time']]);

        return $events;
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, array<string, int>> per session: status => number of players
     */
    public function summaries(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $rows = Attendance::query()
            ->whereIn('training_session_id', $sessionIds)
            ->selectRaw('training_session_id, status, count(*) as total')
            ->groupBy('training_session_id', 'status')
            ->toBase()
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row->training_session_id][$row->status] = (int) $row->total;
        }

        return $summaries;
    }
}
```

`usort` is stable in PHP 8, so two milestones at the same date and time keep the category order.

- [ ] **Step 5: Controller**

In `app/Http/Controllers/AttendanceCalendarController.php`:

Add `use App\Enums\SessionKind;` as the first `use` line (before `use App\Models\Category;`).

Replace
```php
    public const VIEWS = ['month', 'week', 'agenda'];
```
with
```php
    public const VIEWS = ['month', 'week', 'agenda', 'timeline'];

    /** The timeline grows one month at a time up to this many months. */
    public const TIMELINE_MAX_MONTHS = 12;
```

Replace
```php
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
```
with
```php
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'kind' => ['nullable', Rule::enum(SessionKind::class)],
        ]);
```

Replace
```php
            'agenda' => $this->agenda($data),
```
with
```php
            'agenda' => $this->agenda($data),
            'timeline' => $this->timeline($data),
```

Add this method right after `agenda()`:
```php
    /**
     * Months `from`..`to` (default: `month`, else the current one) as one
     * chronological list of sessions, closures and pre-season milestones.
     */
    private function timeline(array $data): array
    {
        $to = CarbonImmutable::createFromFormat('!Y-m', $data['to'] ?? $data['month'] ?? now()->format('Y-m'));
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('!Y-m', $data['from']) : $to;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        if (($to->year - $from->year) * 12 + $to->month - $from->month >= self::TIMELINE_MAX_MONTHS) {
            $from = $to->subMonths(self::TIMELINE_MAX_MONTHS - 1);
        }

        $fromDate = $from->toDateString();
        $toDate = $to->endOfMonth()->toDateString();
        $this->feed->generateAll($fromDate, $toDate);
        $categoryId = $this->categoryFilter($data);
        $kind = $data['kind'] ?? null;

        return [
            'categoryId' => $categoryId,
            'kind' => $kind,
            'month' => $to->format('Y-m'),
            'from' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'events' => $this->feed->timeline($fromDate, $toDate, $categoryId, $kind),
        ];
    }
```

- [ ] **Step 6: Timeline partial**

Create `resources/js/Pages/Attendance/Partials/TimelineView.vue`:
```vue
<script setup>
import { computed, nextTick, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_BORDER, KIND_DOT, addMonths, dateKey, monthKey, monthsBetween, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // null = every category
    kind: { type: String, default: null }, // null = every kind
    from: { type: String, required: true }, // YYYY-MM
    to: { type: String, required: true },
    events: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { statuses, label, color } = useAttendanceCodes();

const MAX_MONTHS = 12; // AttendanceCalendarController::TIMELINE_MAX_MONTHS
const KINDS = ['regular', 'preseason', 'extra'];
const today = dateKey(new Date());
const thisMonth = monthKey(new Date());

const months = computed(() => Array.from({ length: monthsBetween(props.from, props.to) + 1 }, (_, i) => addMonths(props.from, i)));
const byMonth = computed(() => props.events.reduce((acc, e) => ((acc[e.date.slice(0, 7)] ??= []).push(e), acc), {}));
const monthLabel = (m) => parseDay(`${m}-01`).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' });
const dayLabel = (key) => parseDay(key).toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric', month: 'short' });
// The "today" line sits just before the first event on or after today.
const todayEventKey = computed(() => (props.from <= thisMonth && thisMonth <= props.to ? props.events.find((e) => e.date >= today)?.key ?? null : null));

// Filters stay; the window grows by one month, then slides once it holds MAX_MONTHS.
const filters = computed(() => ({ category_id: props.categoryId, kind: props.kind }));
const span = computed(() => monthsBetween(props.from, props.to) + 1);
const earlier = () => emit('navigate', { ...filters.value, from: addMonths(props.from, -1), to: span.value >= MAX_MONTHS ? addMonths(props.to, -1) : props.to });
const later = () => emit('navigate', { ...filters.value, from: span.value >= MAX_MONTHS ? addMonths(props.from, 1) : props.from, to: addMonths(props.to, 1) });
const goToday = () => emit('navigate', { ...filters.value }, { preserveScroll: false });
const filter = (patch) => emit('navigate', { ...filters.value, from: props.from, to: props.to, ...patch });

const summaryParts = (e) => statuses.filter((s) => e.summary?.[s]).map((s) => ({ status: s, text: `${e.summary[s]} ${label(s)}` }));
function dotClass(e) {
    if (e.type === 'closure') return 'bg-slate-400';
    if (e.type === 'milestone') return 'bg-amber-500';
    return e.state === 'cancelled' ? 'border-2 border-slate-400 bg-white dark:bg-slate-900' : KIND_DOT[e.kind];
}
// A cancelled session is drawn hollow (dashed outline, no fill).
const cardClass = (e) => (e.state === 'cancelled'
    ? 'border-2 border-dashed border-slate-300 bg-transparent text-slate-500 dark:border-slate-700'
    : ['border-s-4 bg-white ring-1 ring-slate-200 hover:ring-primary-300 dark:bg-slate-900 dark:ring-slate-800', KIND_BORDER[e.kind]]);
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';

onMounted(() => {
    // Opened on the current month (first visit or "today"): bring today into view.
    if (props.from === props.to && props.to === thisMonth) {
        nextTick(() => document.getElementById('att-today')?.scrollIntoView({ block: 'center' }));
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="filter({ category_id: $event.target.value ? Number($event.target.value) : null })">
                <option value="">{{ t('att.all_categories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select :value="kind ?? ''" :class="input" :aria-label="t('att.col.kind')" @change="filter({ kind: $event.target.value || null })">
                <option value="">{{ t('att.all_kinds') }}</option>
                <option v-for="k in KINDS" :key="k" :value="k">{{ t(`att.kind.${k}`) }}</option>
            </select>
            <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800" @click="goToday">{{ t('att.today') }}</button>
        </div>

        <button class="w-full rounded-lg border border-dashed border-slate-300 py-2 text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="earlier">↑ {{ t('att.earlier') }}</button>

        <ol class="space-y-6">
            <li v-for="m in months" :key="m">
                <h3 class="mb-3 text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel(m) }}</h3>
                <ol class="space-y-3 border-s-2 border-slate-200 ps-6 dark:border-slate-800">
                    <li v-if="!byMonth[m]" class="text-sm text-slate-400">{{ t('att.no_events') }}</li>
                    <li v-for="e in byMonth[m] ?? []" :key="e.key">
                        <p v-if="e.key === todayEventKey" id="att-today" class="mb-3 flex items-center gap-2 text-xs font-semibold text-primary-600">
                            <span class="h-px flex-1 bg-primary-300"></span>{{ t('att.today') }}<span class="h-px flex-1 bg-primary-300"></span>
                        </p>
                        <div class="relative">
                            <span class="absolute -start-[1.94rem] top-4 h-3 w-3 rounded-full ring-4 ring-white dark:ring-slate-900" :class="dotClass(e)"></span>

                            <Link v-if="e.type === 'session'" :href="route('attendance.sessions.show', e.id)" class="block rounded-xl p-3" :class="cardClass(e)">
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                    <span class="font-semibold capitalize">{{ dayLabel(e.date) }}</span>
                                    <span dir="ltr">{{ e.start_time }}–{{ e.end_time }}</span>
                                    <span>{{ t(`att.kind.${e.kind}`) }}</span>
                                    <span class="ms-auto">{{ t(`att.state.${e.state}`) }}</span>
                                </div>
                                <div class="mt-1 font-semibold text-slate-900 dark:text-slate-100" :class="{ 'line-through': e.state === 'cancelled' }">{{ e.categories.map((c) => c.name).join(' · ') }}</div>
                                <div v-if="e.title" class="text-sm text-slate-600 dark:text-slate-300">{{ e.title }}</div>
                                <div v-if="e.state === 'held' && e.summary" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                                    <span v-for="p in summaryParts(e)" :key="p.status" class="inline-flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: color(p.status) }"></span>{{ p.text }}
                                    </span>
                                </div>
                                <div v-if="e.state === 'cancelled'" class="mt-1 text-xs">{{ t('att.cancelled_because', { reason: e.cancel_reason }) }}</div>
                            </Link>

                            <div v-else-if="e.type === 'closure'" class="rounded-xl bg-slate-100 p-3 text-sm dark:bg-slate-800">
                                <div class="text-xs font-semibold text-slate-500">{{ t('att.closure') }} · <span dir="ltr">{{ e.start_date }} → {{ e.end_date }}</span></div>
                                <div class="font-medium text-slate-800 dark:text-slate-200">{{ e.reason }}</div>
                            </div>

                            <Link v-else :href="route('attendance.sessions.show', e.session_id)" class="flex items-center gap-2 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300">
                                <Icon name="flag" />
                                <span>{{ e.milestone === 'started' ? t('att.milestone.started', { category: e.category }) : t('att.milestone.completed', { category: e.category, done: e.done, target: e.target }) }}</span>
                                <span class="ms-auto text-xs font-normal capitalize">{{ dayLabel(e.date) }}</span>
                            </Link>
                        </div>
                    </li>
                </ol>
            </li>
        </ol>

        <button class="w-full rounded-lg border border-dashed border-slate-300 py-2 text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="later">↓ {{ t('att.later') }}</button>
    </div>
</template>
```

- [ ] **Step 7: Wire it into the switcher and the shell**

In `resources/js/Pages/Attendance/Partials/ViewSwitcher.vue`, replace
```js
const VIEWS = ['month', 'week', 'agenda'];
```
with
```js
const VIEWS = ['month', 'week', 'agenda', 'timeline'];
```

In `resources/js/Pages/Attendance/Index.vue`, replace
```js
import AgendaView from './Partials/AgendaView.vue';
```
with
```js
import AgendaView from './Partials/AgendaView.vue';
import TimelineView from './Partials/TimelineView.vue';
```
replace
```js
    week: { type: Object, default: null }, // { start, end } in the week view
});
```
with
```js
    week: { type: Object, default: null }, // { start, end } in the week view
    from: { type: String, default: null }, // timeline window, YYYY-MM
    to: { type: String, default: null },
    kind: { type: String, default: null }, // timeline kind filter
    events: { type: Array, default: () => [] }, // timeline events
});
```
and replace
```html
            <AgendaView v-else-if="view === 'agenda'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" @navigate="navigate" />
```
with
```html
            <AgendaView v-else-if="view === 'agenda'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" @navigate="navigate" />
            <TimelineView v-else-if="view === 'timeline'" :categories="categories" :category-id="categoryId" :kind="kind" :from="from" :to="to" :events="events" @navigate="navigate" />
```

- [ ] **Step 8: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

const keys = {
    'att.view.timeline': ['Timeline', 'Chronologie', 'الخط الزمني'],
    'att.all_kinds': ['All types', 'Tous les types', 'كل الأنواع'],
    'att.earlier': ['Show the previous month', 'Afficher le mois précédent', 'عرض الشهر السابق'],
    'att.later': ['Show the next month', 'Afficher le mois suivant', 'عرض الشهر التالي'],
    'att.closure': ['Club closed', 'Club fermé', 'النادي مغلق'],
    'att.milestone.started': ['Pre-season started · {category}', 'Début de la préparation · {category}', 'انطلاق التحضير البدني · {category}'],
    'att.milestone.completed': ['Pre-season completed {done}/{target} · {category}', 'Préparation terminée {done}/{target} · {category}', 'اكتمال التحضير البدني {done}/{target} · {category}'],
    'att.no_events': ['Nothing this month.', 'Rien ce mois-ci.', 'لا شيء هذا الشهر.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines (the previous last key of each file, re-added with a comma).

- [ ] **Step 9: Run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceTimelineViewTest|AttendanceAgendaViewTest|AttendanceWeekViewTest|AttendanceJointCalendarTest|AttendanceCalendarTest" && npm run i18n:check`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add resources/js/Pages/Attendance/Partials/TimelineView.vue app/Services/Attendance/PreseasonProgress.php app/Services/Attendance/CalendarFeed.php app/Http/Controllers/AttendanceCalendarController.php resources/js/Pages/Attendance/Partials/ViewSwitcher.vue resources/js/Pages/Attendance/Index.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceTimelineViewTest.php
git commit -m "feat(attendance): timeline of sessions, closures and pre-season milestones" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Full verification

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all green. Fix any failure in the task that caused it, and commit the fix with the explicit paths it touched.

- [ ] **Step 2: Style**

Run: `vendor/bin/pint --dirty`, then `git status --short`.
If pint changed files, stage exactly those paths and commit:
```bash
git add <the files pint changed>
git commit -m "style(attendance): pint" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 3: Frontend build and translations**

Run: `npm run build && npm run i18n:check`
Expected: the build succeeds with no missing-import warnings, and the check prints no `does not resolve` lines. Also run:
```bash
git diff main --stat -- resources/js/i18n
git diff main -U0 -- resources/js/i18n | grep '^-[^-]' | wc -l
```
Expected: the second command prints `3` (in each file only the pre-2a last line changed, by gaining a comma).

- [ ] **Step 4: Migrations are rerunnable**

Run: `php artisan migrate && php artisan migrate:status | tail -3 && php artisan migrate`
Expected: the two new migrations show as `Ran`; the second `migrate` prints `Nothing to migrate`.

- [ ] **Step 5: Manual check in Arabic and in French**

Serve the worktree (`php artisan serve --port=2027`) and go through this list twice, once with the interface in **ar** and once in **fr**:
1. Settings → "Codes and colours": change *late* to `ت` with another colour and an Arabic name; save. Setting *present* and *late* both to `AB` shows "Another status already uses this code" under *late*; `A1` shows the letters-only error.
2. Month grid: the legend shows the configured codes, names and colours; `ت10` is accepted and the cell takes the late colour; `R10` is rejected; saved marks from before the change now read `ت15` etc.
3. Session screen: status chips use the configured names and colours; the "Title / goal" field keeps up to 150 characters.
4. Add a pre-season session for U15 with U17 ticked: the session opens with both rosters (players tagged with their category). "Edit categories" adds U19 or drops U15; after saving attendance the button is gone.
5. U17's month calendar shows the joint session with a `+1` badge and its title; both badges count it (`n/target`). Held chips show the status bar, with counts in the tooltip.
6. Try to add an extra U17 session at the same date and time as the joint session: "already has a session at that date and time".
7. View switcher: Month / Week / Agenda / Timeline; the URL keeps `view=`; switching keeps the month.
8. Week: two sessions of different categories at the same time sit side by side; hours stretch past 08:00–20:00 when a session starts earlier; previous / next / today work; in Arabic the days run right to left and the arrows point the right way.
9. Agenda: the category filter ("All categories" or one) and month arrows; clicking a date opens the session.
10. Timeline: month headers, the "today" line, "Show the previous month" adds a month above, "Show the next month" below; a cancelled session is hollow with its reason; a closure card; "Pre-season started · U15" and "Pre-season completed n/n · U15" after enough held sessions; the kind and category filters.

- [ ] **Step 6: Hand-off**

Report to the owner: what shipped in 2a, the resolved ambiguities listed under Global Constraints, and the deploy notes. The deploy needs `php artisan migrate` (the desktop app runs it on boot); on the MySQL server check afterwards that `SHOW CREATE TABLE training_sessions` shows `title varchar(150)` and that `training_session_category` has as many rows as `training_sessions` has sessions (more if joint sessions exist). Next step: plan 2b (statistics, profile card, dashboard card, exports).
