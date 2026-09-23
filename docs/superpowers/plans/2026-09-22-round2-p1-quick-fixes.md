# Round 2 · P1 Quick Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the six P1 items of `docs/superpowers/specs/2026-09-22-post-testing-round-2-design.md`:
- #9 board term edit
- #7 translated status labels on screens
- #8 meetings cancel-only
- #3 transaction title
- #4 unambiguous player identification in transactions
- #1 a visible, enforced rental-vs-assignment distinction

**Architecture:** Small, independent changes on the existing Laravel 13 + Inertia/Vue 3 stack.
- **Title:** `transactions.title` is stored when the user types one. Otherwise `App\Support\TransactionTitle` builds a label at render time, in the viewer's language. It reads the UI's own catalogs through a new `App\Support\UiLang`.
- **Status labels:** go through one pure map (`resources/js/lib/statusLabels.js`), which `npm run i18n:check` also verifies.
- **Meetings:** cancelling is a state change with who / when / why stored in new nullable columns. The `status` enum is left alone.
- **Equipment out:** a new list page, backed by a single-action controller. The return form becomes a shared component.

**Tech Stack:** PHP 8.x, Laravel 13, Inertia v2 + Vue 3 `<script setup>`, vue-i18n (flat keys), Tailwind, PHPUnit 12 (class style, `#[Test]`), SQLite (tests: `:memory:`).

## Global Constraints

**Prerequisite (git)**
- Start only after the owner has committed the cash-register / transfer work-in-progress on `feat/player-previous-debts`. At that point `git status --short` shows nothing but `?? .claude/`.
- Then create the branch: `git switch -c feat/round2-p1-quick-fixes`.

**Committing**
- Stage **explicit file paths only**. Never stage a directory.
- After each commit, run `git show --stat HEAD` and check that each file's line count matches what the task changed.

**Replacing whole files**
- When a step says "replace the whole file", first run `git diff HEAD -- <file>`. It must print nothing.
- If the file has changed since this plan was written, apply the change as targeted edits instead.

**Files with a byte-order mark**
- `resources/js/Pages/Equipment/Catalog/Show.vue` and `resources/js/Pages/Players/Show.vue` start with a UTF-8 BOM. Edit them with targeted edits only; never rewrite the first line.

**Tests**
- Run tests with `php artisan config:clear` then `php artisan test --filter=<Name>`. For the full suite use `composer test`.
- A cached config makes CSRF run in tests, which fails every POST with 419.
- Tests use `User::factory()->admin()->create(['email_verified_at' => now()])` as the actor.
- There is no Player factory: build players with `Player::create([...])` including a unique `membership_id`.
- Read Inertia props with `->viewData('page')['props']`.

**UI text**
- UI text lives in `resources/js/i18n/{ar,en,fr}.json`. Keys are **flat**, including dotted ones (`flash.x`, `equipment.x`).
- Call `t('key')` directly; never guard it with `te()`.
- Add keys only with `node scripts/i18n-add.mjs <file>` (created in Task 3). Every key needs `ar`, `fr` and `en`, and the three catalogs must stay the same size.
- vue-i18n treats `{`, `}`, `@`, `$` and `|` as syntax. Use `{name}` only for placeholders and none of the others in values.
- Server-side sentences use `__('English sentence')`, with entries in `lang/ar.json` and `lang/fr.json` kept in alphabetical key order.

**Flash messages**
- Controllers flash keys (`'flash.meeting_cancelled'`), never sentences. `tests/Feature/FlashTranslationTest.php` enforces this.

**Database**
- No enum changes: SQLite would rebuild the table.
- Name new migrations `database/migrations/2026_09_22_1000NN_*.php`.
- Every schema change reaches desktop installs through migrate-on-boot. Reference data goes in migrations, never in seeders.

**Formatting and tooling**
- PHP formatting: `vendor/bin/pint <changed php files>` before each commit.
- There is **no JS test runner** in this project. Frontend tasks are verified with `npm run build` (must succeed) plus a manual check in the browser, listed in Task 12.

---

## File structure

**New**

| File | Responsibility |
|---|---|
| `scripts/i18n-add.mjs` | Merge new keys into the three catalogs, sorted, same format |
| `resources/js/lib/statusLabels.js` | Pure map: (domain, stored code) → i18n key |
| `resources/js/Composables/useStatusLabel.js` | `statusLabel(domain, code)` bound to `t` |
| `app/Support/UiLang.php` | PHP reader of the UI catalogs |
| `app/Support/TransactionTitle.php` | Display label + player summary for a transaction |
| `app/Http/Controllers/EquipmentOutController.php` | "Equipment out" list: open rentals and assignments |
| `resources/js/Components/RentalTypeBadge.vue` | Rented vs Assigned (work) badge |
| `resources/js/Components/ReturnRentalModal.vue` | Return form for one open rental, shared by two pages |
| `resources/js/Pages/Equipment/Out.vue` | The Equipment-out page |
| migrations `100001`–`100003` | player-status `code`; meeting cancellation columns; transaction `title` |
| tests | `BoardTermTest`, `PlayerStatusCodeTest`, `BoardMeetingCancellationTest`, `UiLangTest`, `TransactionTitleTest`, `TransactionTitleFlowTest`, `TransactionPlayerLookupTest`, `EquipmentAssignmentTest`, `Dashboard/ActivityFeedLabelTest` |

**Modified**

| Area | Files |
|---|---|
| Board | `BoardTerm`, `BoardTask`, `BoardMeeting`, `BoardMeetingController`, `BoardController`, `Members.vue`, `Tasks.vue`, `Meeting.vue`, `Meetings.vue`, `ConfirmModal.vue` |
| Transactions | `Transaction`, `Player`, `StoreTransactionRequest`, `TransactionController`, `TransactionImportController`, `PlayerController`, `ReportController`, `receipt.blade.php`, `OverviewStats`, `SearchableSelect.vue`, `TransactionForm.vue`, `Transactions/Index.vue`, `Transactions/Show.vue`, `Players/Show.vue` |
| Equipment | `RentEquipmentRequest`, `EquipmentItem`, `EquipmentCatalogController`, `EquipmentItemController`, `Catalog/Show.vue`, `History.vue`, `OverviewTab.vue`, `AuthenticatedLayout.vue` |
| Statuses | `RegisterPlayerService`, `Subscriptions/Show.vue`, `OperationsTab.vue`, `scripts/i18n-check.mjs` |
| Config, routes and other files | `routes/web.php`, `config/permissions.php`, `lang/ar.json`, `lang/fr.json`, `tests/Feature/CashRegisterManagementTest.php` |

---

### Task 1: Board term and task dates open filled, and edits save visibly

**Why:** `BoardTerm` and `BoardTask` cast dates as `date`, so Inertia serialises them as `2024-01-01T00:00:00.000000Z`.
- `<input type="date">` accepts only `yyyy-MM-dd`, so the edit fields open **empty**, and the hidden old value is re-sent on save.
- The term modal renders no validation errors, so a failed save looks like a save.

**Files:**
- Modify: `app/Models/BoardTerm.php` (casts)
- Modify: `app/Models/BoardTask.php` (casts)
- Modify: `resources/js/Pages/Board/Members.vue` (`openTermEdit`, term modal fields)
- Modify: `resources/js/Pages/Board/Tasks.vue` (`openEdit`, `onDrop`)
- Test: `tests/Feature/BoardTermTest.php`

**Interfaces:**
- Produces: `terms[*].start_date` / `end_date` and `tasks[*].due_date` serialised as `Y-m-d` strings (or null).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardTermTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function term(): BoardTerm
    {
        return BoardTerm::create([
            'name' => '2024–2028',
            'start_date' => '2024-01-01',
            'end_date' => '2028-12-31',
            'is_current' => true,
        ]);
    }

    #[Test]
    public function the_members_page_sends_term_dates_as_plain_dates(): void
    {
        $this->term();

        $props = $this->actingAs($this->admin())->get(route('board.members'))
            ->assertOk()->viewData('page')['props'];

        // <input type="date"> only accepts yyyy-MM-dd; a timestamp opens it empty.
        $this->assertSame('2024-01-01', $props['terms'][0]['start_date']);
        $this->assertSame('2028-12-31', $props['terms'][0]['end_date']);
    }

    #[Test]
    public function editing_a_term_stores_the_new_dates(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin())
            ->put(route('board.terms.update', $term), [
                'name' => '2025–2029',
                'start_date' => '2025-03-01',
                'end_date' => '2029-02-28',
                'is_current' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $term->refresh();
        $this->assertSame('2025–2029', $term->name);
        $this->assertSame('2025-03-01', $term->start_date->toDateString());
        $this->assertSame('2029-02-28', $term->end_date->toDateString());
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected_and_nothing_changes(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin())
            ->put(route('board.terms.update', $term), [
                'name' => '2024–2028',
                'start_date' => '2026-01-01',
                'end_date' => '2025-01-01',
                'is_current' => true,
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame('2024-01-01', $term->fresh()->start_date->toDateString());
    }

    #[Test]
    public function the_tasks_page_sends_due_dates_as_plain_dates(): void
    {
        BoardTask::create([
            'title' => 'Book the hall',
            'due_date' => '2026-10-05',
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
        ]);

        $props = $this->actingAs($this->admin())->get(route('board.tasks'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('2026-10-05', $props['columns']['not_started'][0]['due_date']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=BoardTermTest`

Expected: FAIL. Two tests fail: `the_members_page_sends_term_dates_as_plain_dates` and `the_tasks_page_sends_due_dates_as_plain_dates`, each asserting that `'2024-01-01T00:00:00.000000Z'` (or the `2026-10-05` equivalent) is identical to the plain date. The update and validation tests may already pass. They lock the save behaviour in.

- [ ] **Step 3: Serialise the dates as `Y-m-d`**

In `app/Models/BoardTerm.php`, replace the `casts()` body:

```php
        return [
            // Y-m-d so the value drops straight into <input type="date">;
            // a full timestamp leaves the field blank and re-sends the old date.
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
```

In `app/Models/BoardTask.php`, replace `'due_date' => 'date',` with:

```php
            // Y-m-d so the edit modal's <input type="date"> opens filled.
            'due_date' => 'date:Y-m-d',
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `php artisan test --filter=BoardTermTest`

Expected: PASS (4 tests).

- [ ] **Step 5: Make the term modal show errors and never keep stale ones**

In `resources/js/Pages/Board/Members.vue`, replace the whole `openTermEdit` function:

```js
function openTermEdit(tm) {
    editingTermId.value = tm.id;
    termForm.clearErrors();
    termForm.name = tm.name;
    // Slice anyway: a stray timestamp would leave <input type="date"> blank
    // while the old value is silently re-sent on save.
    termForm.start_date = tm.start_date ? String(tm.start_date).slice(0, 10) : '';
    termForm.end_date = tm.end_date ? String(tm.end_date).slice(0, 10) : '';
    termForm.is_current = !!tm.is_current;
    showTermForm.value = true;
}
```

In the same file, replace these term-modal fields:

```html
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                        <input v-model="termForm.name" required :placeholder="'2024–2028'" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_start') }}</span>
                            <input v-model="termForm.start_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_end') }}</span>
                            <input v-model="termForm.end_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    </div>
```

with:

```html
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                        <input v-model="termForm.name" required :placeholder="'2024–2028'" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <span v-if="termForm.errors.name" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.name }}</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_start') }}</span>
                            <input v-model="termForm.start_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-if="termForm.errors.start_date" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.start_date }}</span></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_end') }}</span>
                            <input v-model="termForm.end_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-if="termForm.errors.end_date" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.end_date }}</span></label>
                    </div>
```

- [ ] **Step 6: Same guard for task due dates**

In `resources/js/Pages/Board/Tasks.vue`, inside `openEdit(tk)`, replace:

```js
    form.board_meeting_id = tk.board_meeting_id; form.due_date = tk.due_date || ''; form.status = tk.status;
```

with:

```js
    form.board_meeting_id = tk.board_meeting_id; form.due_date = tk.due_date ? String(tk.due_date).slice(0, 10) : ''; form.status = tk.status;
```

In `onDrop(col)`, replace `        due_date: tk.due_date,` with:

```js
        due_date: tk.due_date ? String(tk.due_date).slice(0, 10) : null,
```

- [ ] **Step 7: Build and run the board tests**

Run: `npm run build`. Expected: build completes with no errors.

Run: `php artisan test --filter="BoardTermTest|BoardMemberTest|BoardCalendarTest"`. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint app/Models/BoardTerm.php app/Models/BoardTask.php tests/Feature/BoardTermTest.php
git add app/Models/BoardTerm.php app/Models/BoardTask.php resources/js/Pages/Board/Members.vue resources/js/Pages/Board/Tasks.vue tests/Feature/BoardTermTest.php
git commit -m "fix(board): term and task dates open filled and edit errors show"
```

---

### Task 2: Stable codes for player statuses

**Why:** `RegisterPlayerService` finds the default status with `where('name', 'منخرط')`. The name is editable in Settings → Player statuses, so renaming it silently leaves new players with no status.

**Files:**
- Create: `database/migrations/2026_09_22_100001_add_code_to_player_statuses.php`
- Modify: `app/Services/Player/RegisterPlayerService.php:24-28`
- Test: `tests/Feature/PlayerStatusCodeTest.php`

**Interfaces:**
- Produces: `player_statuses.code` (nullable, unique). The built-in values are `registered`, `retired`, `paused`, `left`, `unclear` and `sanctioned`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerStatusCodeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerStatus;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerStatusCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_built_in_statuses_carry_stable_codes(): void
    {
        $this->assertSame(
            ['registered', 'retired', 'paused', 'left', 'unclear', 'sanctioned'],
            PlayerStatus::orderBy('sort_order')->pluck('code')->all(),
        );
    }

    #[Test]
    public function a_new_player_defaults_to_registered_even_after_the_status_is_renamed(): void
    {
        $registered = PlayerStatus::where('code', 'registered')->firstOrFail();
        $registered->update(['name' => 'مسجل', 'name_ar' => 'مسجل']);

        $player = app(RegisterPlayerService::class)->handle([
            'firstname' => 'Amine',
            'join_year' => 2026,
        ]);

        $this->assertSame($registered->id, $player->status_id);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=PlayerStatusCodeTest`

Expected: FAIL with `no such column: code`.

- [ ] **Step 3: Add the column and fill the six built-in codes**

Create `database/migrations/2026_09_22_100001_add_code_to_player_statuses.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A status is found by a stable code, never by its display name: the name
     * is editable in Settings, so matching 'منخرط' breaks the moment an admin
     * renames it. Only the six built-in statuses get a code; ones an admin
     * adds later have none and need none.
     */
    public function up(): void
    {
        Schema::table('player_statuses', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique()->after('id');
        });

        $codes = [
            'registered' => ['منخرط', 'Registered'],
            'retired' => ['معتزل', 'Retired'],
            'paused' => ['متوقف', 'Paused'],
            'left' => ['غادر الفريق', 'Left the club'],
            'unclear' => ['غير واضح', 'Unclear'],
            'sanctioned' => ['معاقب', 'Sanctioned'],
        ];

        foreach ($codes as $code => [$name, $english]) {
            // Match on either name: an install may already have renamed one.
            $id = DB::table('player_statuses')
                ->whereNull('code')
                ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $english))
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                DB::table('player_statuses')->where('id', $id)->update(['code' => $code]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('player_statuses', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
```

- [ ] **Step 4: Look up the default status by code**

In `app/Services/Player/RegisterPlayerService.php`, replace:

```php
            // New members default to "enrolled" (منخرط) when no status is given
            // (e.g. spreadsheet import, which carries no membership-status column).
            if (empty($attributes['status_id'])) {
                $attributes['status_id'] = PlayerStatus::where('name', 'منخرط')->value('id');
            }
```

with:

```php
            // New members default to "registered" when no status is given (e.g.
            // spreadsheet import, which carries no membership-status column).
            // Matched by code: the display name is editable in Settings.
            if (empty($attributes['status_id'])) {
                $attributes['status_id'] = PlayerStatus::where('code', 'registered')->value('id');
            }
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerStatusCodeTest|PlayerStatusLookupTest|PlayerImportTest|PlayerStatusSettingsTest"`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint database/migrations/2026_09_22_100001_add_code_to_player_statuses.php app/Services/Player/RegisterPlayerService.php tests/Feature/PlayerStatusCodeTest.php
git add database/migrations/2026_09_22_100001_add_code_to_player_statuses.php app/Services/Player/RegisterPlayerService.php tests/Feature/PlayerStatusCodeTest.php
git commit -m "fix(players): find the default status by a stable code, not its name"
```

---

### Task 3: Status labels follow the selected language

**Why:** Stored codes such as `Paid`, `paid`, `Available` and `cash` render raw in several places. The raw spots are:
- `Transactions/Index.vue` and `Transactions/Show.vue`: payment status, and the payment method on Show
- `Subscriptions/Show.vue:259`
- `Dashboard/Partials/OperationsTab.vue:226`
- `Players/Show.vue:447` (payment method)

This task also adds the key-adding script every later task uses.

**Files:**
- Create: `scripts/i18n-add.mjs`
- Create: `resources/js/lib/statusLabels.js`
- Create: `resources/js/Composables/useStatusLabel.js`
- Modify: `scripts/i18n-check.mjs`
- Modify: `resources/js/Pages/Transactions/Index.vue`, `resources/js/Pages/Transactions/Show.vue`, `resources/js/Pages/Subscriptions/Show.vue`, `resources/js/Pages/Dashboard/Partials/OperationsTab.vue`, `resources/js/Pages/Players/Show.vue`

**Interfaces:**
- Produces: `STATUS_KEYS`, `statusKey(domain, code)` and `statusLabel(t, domain, code)` from `@/lib/statusLabels`.
- Produces: `useStatusLabel()` → `{ statusLabel(domain, code) }`, returning `'—'` for an empty code and the raw code when it is unmapped.
- Domains: `payment`, `payment_method`, `equipment`, `meeting`, `rental_type`.
- Produces: `node scripts/i18n-add.mjs <keys.json>`, where the file looks like `{ "key": { "ar": "…", "fr": "…", "en": "…" } }`.

- [ ] **Step 1: Create the key-adding script**

Create `scripts/i18n-add.mjs`:

```js
// Adds or updates flat keys in all three UI catalogs at once, so ar/fr/en can
// never drift apart. Keys stay in the files' existing order (default
// code-point sort) and format (4-space JSON, trailing newline).
//
// Usage: node scripts/i18n-add.mjs keys.json
//   keys.json: { "some_key": { "ar": "…", "fr": "…", "en": "…" }, … }
import { readFileSync, writeFileSync } from 'node:fs';

const [, , file] = process.argv;
if (!file) {
  console.error('usage: node scripts/i18n-add.mjs keys.json');
  process.exit(1);
}

const locales = ['ar', 'en', 'fr'];
const additions = JSON.parse(readFileSync(file, 'utf8'));

for (const [key, values] of Object.entries(additions)) {
  for (const locale of locales) {
    if (typeof values?.[locale] !== 'string' || values[locale] === '') {
      console.error(`✗ "${key}" has no "${locale}" value — nothing written`);
      process.exit(1);
    }
  }
}

for (const locale of locales) {
  const path = new URL(`../resources/js/i18n/${locale}.json`, import.meta.url);
  const catalog = JSON.parse(readFileSync(path, 'utf8'));
  for (const [key, values] of Object.entries(additions)) catalog[key] = values[locale];
  const sorted = Object.fromEntries(Object.keys(catalog).sort().map((k) => [k, catalog[k]]));
  writeFileSync(path, JSON.stringify(sorted, null, 4) + '\n');
}

console.log(`✓ ${Object.keys(additions).length} key(s) written to ar/en/fr`);
```

Check that it is a no-op on an empty file. Save `{}` as `i18n-keys.tmp.json` in the project root, run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then run `git diff --stat resources/js/i18n`.

Expected: prints `✓ 0 key(s) written`, and the diff is empty. Delete `i18n-keys.tmp.json`. **Never stage `i18n-keys.tmp.json` in any task.**

- [ ] **Step 2: Create the status map**

Create `resources/js/lib/statusLabels.js`:

```js
/**
 * Every stored status code the screens render, per domain, mapped to its i18n
 * key. The database keeps stable codes; only the label is translated.
 *
 * Codes match case-insensitively: transactions store "Paid", subscription
 * lines expose "paid" — both are the same code. Pure data + functions (no Vue)
 * so scripts/i18n-check.mjs can verify every key resolves in ar/fr/en.
 */
export const STATUS_KEYS = {
    payment: { paid: 'paid', partial: 'partial', unpaid: 'unpaid', exempt: 'exempt' },
    payment_method: { cash: 'cash', bank: 'bank_transfer', ccp: 'ccp', baridimob: 'baridimob', other: 'other' },
    equipment: {
        available: 'available',
        rented: 'rented',
        'under repair': 'under_repair',
        'out of service': 'out_of_service',
        lost: 'lost',
        retired: 'retired',
    },
    meeting: { scheduled: 'scheduled', held: 'held', cancelled: 'cancelled' },
    rental_type: { rental: 'equipment.rental', assignment: 'equipment.assignment' },
};

const isEmpty = (code) => code === null || code === undefined || code === '';

/** The i18n key for a stored code, or null when the code is unknown. */
export function statusKey(domain, code) {
    if (isEmpty(code)) return null;
    return STATUS_KEYS[domain]?.[String(code).toLowerCase()] ?? null;
}

/** Translated label; '—' when empty, the raw code when unmapped (never blank). */
export function statusLabel(t, domain, code) {
    if (isEmpty(code)) return '—';
    const key = statusKey(domain, code);
    return key ? t(key) : String(code);
}
```

Create `resources/js/Composables/useStatusLabel.js`:

```js
import { useI18n } from 'vue-i18n';
import { statusLabel } from '@/lib/statusLabels';

/** `statusLabel('payment', tx.status)` → the label in the current language. */
export function useStatusLabel() {
    const { t } = useI18n();

    return { statusLabel: (domain, code) => statusLabel(t, domain, code) };
}
```

- [ ] **Step 3: Have `i18n:check` verify every status key**

In `scripts/i18n-check.mjs`, add under the existing imports:

```js
import { STATUS_KEYS } from '../resources/js/lib/statusLabels.js';
```

Then replace everything from `let failures = 0;` to the end of the file with:

```js
// Every status label the screens render through statusLabel().
const statusKeys = new Set(Object.values(STATUS_KEYS).flatMap((map) => Object.values(map)));

let failures = 0;
for (const locale of ['ar', 'fr', 'en']) {
  i18n.global.locale.value = locale;
  for (const key of [...keys, ...statusKeys]) {
    const out = i18n.global.t(key);
    if (out === key) {
      console.error(`  [${locale}] does not resolve: ${key}`);
      failures++;
    }
  }
}

if (failures) {
  console.error(`\n✗ ${failures} key(s) render raw. Add them to resources/js/i18n/*.json.`);
  process.exit(1);
}
console.log(`✓ all ${keys.size} flash keys and ${statusKeys.size} status keys resolve in ar/fr/en`);
```

Run: `npm run i18n:check`

Expected: `✓ all N flash keys and 20 status keys resolve in ar/fr/en`. Every mapped key already exists.

- [ ] **Step 4: Route every raw status render through `statusLabel`**

In **each** of these five files, add `import { useStatusLabel } from '@/Composables/useStatusLabel';` to the imports and `const { statusLabel } = useStatusLabel();` on the line after `const { t } = useI18n();`:
- `Transactions/Index.vue`
- `Transactions/Show.vue`
- `Subscriptions/Show.vue`
- `Dashboard/Partials/OperationsTab.vue`
- `Players/Show.vue`

Then make these replacements:

| File | Replace | With |
|---|---|---|
| `Transactions/Index.vue` | `<Badge :label="tx.status \|\| '-'"` | `<Badge :label="statusLabel('payment', tx.status)"` |
| `Transactions/Show.vue` | `<Badge :label="transaction.status"` | `<Badge :label="statusLabel('payment', transaction.status)"` |
| `Transactions/Show.vue` | `{{ transaction.payment_method \|\| '-' }}` | `{{ statusLabel('payment_method', transaction.payment_method) }}` |
| `Subscriptions/Show.vue` | `<Badge :label="ps.payment_status"` | `<Badge :label="statusLabel('payment', ps.payment_status)"` |
| `OperationsTab.vue` | `:class="statusTone[row.status] \|\| ''">{{ row.status }}</Badge>` | `:class="statusTone[row.status] \|\| ''">{{ statusLabel('equipment', row.status) }}</Badge>` |
| `Players/Show.vue` | `{{ tx.payment_method \|\| '-' }}` | `{{ statusLabel('payment_method', tx.payment_method) }}` |

In `Players/Show.vue`, the payment-method match must be the transactions-table cell. Check with `grep -n "tx.payment_method || '-'"`, which returns exactly one line.

- [ ] **Step 5: Build and check**

Run: `npm run build`. Expected: success.

Run: `npm run i18n:check`. Expected: `✓ …`.

- [ ] **Step 6: Commit**

```bash
git add scripts/i18n-add.mjs scripts/i18n-check.mjs resources/js/lib/statusLabels.js resources/js/Composables/useStatusLabel.js resources/js/Pages/Transactions/Index.vue resources/js/Pages/Transactions/Show.vue resources/js/Pages/Subscriptions/Show.vue resources/js/Pages/Dashboard/Partials/OperationsTab.vue resources/js/Pages/Players/Show.vue
git commit -m "fix(i18n): status labels follow the selected language"
```

---

### Task 4: Meetings are cancelled, never deleted (backend)

**Why:** The owner's decision is that meetings are never deleted.
- `board.meetings.destroy` hard-deletes and orphans the attachment file. No page calls it.
- "Cancelled" can only be set through the status dropdown, with no who / when / why.

**Files:**
- Create: `database/migrations/2026_09_22_100002_add_cancellation_to_board_meetings.php`
- Modify: `app/Models/BoardMeeting.php`
- Modify: `app/Http/Controllers/BoardMeetingController.php`
- Modify: `app/Http/Controllers/BoardController.php` (`index`, `meetings`, `meeting`)
- Modify: `routes/web.php` (board meetings block)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/BoardMeetingCancellationTest.php`

**Interfaces:**
- Consumes: `scripts/i18n-add.mjs` (Task 3).
- Produces: route `board.meetings.cancel`: `POST /board/meetings/{meeting}/cancel`, body `{reason}`.
- Produces: `BoardMeeting::isCancelled(): bool` and the relation `cancelledBy()`.
- Produces: new `board_meetings` columns `cancelled_at`, `cancelled_by_user_id` and `cancel_reason`.
- Produces: the meetings page receives `filters.status`. The meeting page receives `meeting.cancelled_by` as `{id, name}`.
- Produces: flash keys `flash.meeting_cancelled`, `flash.meeting_not_cancellable` and `flash.meeting_is_cancelled`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardMeetingCancellationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMeetingCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function meeting(array $attributes = []): BoardMeeting
    {
        return BoardMeeting::create(array_merge([
            'title' => 'Monthly board',
            'type' => 'ordinary',
            'meeting_date' => now()->addWeek(),
            'status' => 'scheduled',
        ], $attributes));
    }

    #[Test]
    public function cancelling_keeps_the_meeting_with_who_when_and_why(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting();

        $this->actingAs($admin)
            ->post(route('board.meetings.cancel', $meeting), ['reason' => 'Hall unavailable'])
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.meeting_cancelled');

        $meeting->refresh();
        $this->assertSame('cancelled', $meeting->status);
        $this->assertSame('Hall unavailable', $meeting->cancel_reason);
        $this->assertSame($admin->id, $meeting->cancelled_by_user_id);
        $this->assertNotNull($meeting->cancelled_at);

        $props = $this->actingAs($admin)->get(route('board.meetings.show', $meeting))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame($admin->name, $props['meeting']['cancelled_by']['name']);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())
            ->post(route('board.meetings.cancel', $meeting), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('scheduled', $meeting->fresh()->status);
    }

    #[Test]
    public function a_held_meeting_cannot_be_cancelled(): void
    {
        $meeting = $this->meeting(['status' => 'held']);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.cancel', $meeting), ['reason' => 'Too late'])
            ->assertSessionHas('error', 'flash.meeting_not_cancellable');

        $this->assertSame('held', $meeting->fresh()->status);
    }

    #[Test]
    public function a_cancelled_meeting_is_read_only(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting(['status' => 'cancelled', 'cancelled_at' => now()]);
        $member = BoardMember::create(['name' => 'Karim', 'role' => 'president', 'status' => 'active']);

        $this->actingAs($admin)
            ->put(route('board.meetings.update', $meeting), [
                'title' => 'Renamed', 'type' => 'ordinary',
                'meeting_date' => now()->addWeek()->toDateTimeString(), 'status' => 'scheduled',
            ])
            ->assertSessionHas('error', 'flash.meeting_is_cancelled');

        $this->actingAs($admin)
            ->put(route('board.meetings.attendance', $meeting), [
                'attendances' => [['board_member_id' => $member->id, 'status' => 'present']],
            ])
            ->assertSessionHas('error', 'flash.meeting_is_cancelled');

        $this->assertSame('Monthly board', $meeting->fresh()->title);
        $this->assertSame(0, MeetingAttendance::count());
    }

    #[Test]
    public function cancelled_is_no_longer_a_status_the_form_can_set(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())
            ->put(route('board.meetings.update', $meeting), [
                'title' => 'Monthly board', 'type' => 'ordinary',
                'meeting_date' => now()->addWeek()->toDateTimeString(), 'status' => 'cancelled',
            ])
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function meetings_can_no_longer_be_deleted(): void
    {
        $meeting = $this->meeting();

        $this->assertFalse(Route::has('board.meetings.destroy'));
        $this->actingAs($this->admin())
            ->delete('/board/meetings/'.$meeting->id)
            ->assertStatus(405);

        $this->assertNotNull($meeting->fresh());
    }

    #[Test]
    public function the_board_overview_leaves_cancelled_meetings_out_of_the_term_total(): void
    {
        BoardTerm::create(['name' => 'T', 'start_date' => '2026-01-01', 'end_date' => '2029-12-31', 'is_current' => true]);
        $this->meeting(['meeting_date' => '2026-06-01 18:00:00', 'status' => 'held']);
        $this->meeting(['meeting_date' => '2026-07-01 18:00:00', 'status' => 'cancelled']);

        $props = $this->actingAs($this->admin())->get(route('board.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['stats']['meetings_total']);
    }

    #[Test]
    public function the_meetings_list_filters_by_status(): void
    {
        $this->meeting();
        $this->meeting(['status' => 'cancelled']);

        $props = $this->actingAs($this->admin())->get(route('board.meetings', ['status' => 'cancelled']))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['meetings']);
        $this->assertSame('cancelled', $props['filters']['status']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=BoardMeetingCancellationTest`

Expected: FAIL. The first failure is `Route [board.meetings.cancel] not defined`.

- [ ] **Step 3: Migration**

Create `database/migrations/2026_09_22_100002_add_cancellation_to_board_meetings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meetings are never deleted, only cancelled — and a cancellation records
     * who, when and why. The status enum already has 'cancelled' and is not
     * altered (an enum change rebuilds the table on SQLite).
     */
    public function up(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by_user_id');
        });

        // Meetings set to cancelled through the old status dropdown have no stamp.
        DB::table('board_meetings')
            ->where('status', 'cancelled')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
```

- [ ] **Step 4: Model**

In `app/Models/BoardMeeting.php`:

Replace the `$fillable` array with:

```php
    protected $fillable = [
        'title', 'type', 'meeting_date', 'location', 'agenda', 'status',
        'quorum_required', 'minutes', 'decisions', 'attachment_url',
        'attachment_filename', 'created_by_user_id',
        'cancelled_at', 'cancelled_by_user_id', 'cancel_reason',
    ];
```

In `casts()`, add `'cancelled_at' => 'datetime',` after `'meeting_date' => 'datetime',`.

Add after `createdBy()`:

```php
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** A cancelled meeting is kept as history and can no longer be changed. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
```

- [ ] **Step 5: Controller**

In `app/Http/Controllers/BoardMeetingController.php`:

Make these three edits:
- At the very top of `update(...)`, `attendance(...)` and `attachment(...)`, before any other line, insert:

```php
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }
```

- Insert the same guard at the top of `deleteAttachment(...)`.
- Delete the whole `destroy(...)` method.

Add this method after `deleteAttachment(...)`:

```php
    /**
     * Cancel = keep the record, mark it cancelled, say why. Only a meeting that
     * has not happened yet can be cancelled; a held meeting is history.
     */
    public function cancel(Request $request, BoardMeeting $meeting): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($meeting->status !== 'scheduled') {
            return back()->with('error', 'flash.meeting_not_cancellable');
        }

        $meeting->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $request->user()?->id,
            'cancel_reason' => $data['reason'],
        ]);

        return back()->with('success', 'flash.meeting_cancelled');
    }
```

In `validateCore`, replace the status rule with:

```php
            // 'cancelled' is reached only through cancel(), which records who and why.
            'status' => ['required', Rule::in(['scheduled', 'held'])],
```

- [ ] **Step 6: Routes**

In `routes/web.php`, replace:

```php
        Route::delete('/board/meetings/{meeting}', [BoardMeetingController::class, 'destroy'])->name('board.meetings.destroy');
```

with:

```php
        // Meetings are never deleted: cancelling keeps the record (who/when/why).
        Route::post('/board/meetings/{meeting}/cancel', [BoardMeetingController::class, 'cancel'])->name('board.meetings.cancel');
```

- [ ] **Step 7: Board overview, list filter, meeting page**

In `app/Http/Controllers/BoardController.php`:

In `index()`, replace:

```php
                'meetings_total' => (clone $termMeetings)->count(),
```

with:

```php
                // A cancelled meeting never took place, so it is not part of the term's total.
                'meetings_total' => (clone $termMeetings)->where('status', '!=', 'cancelled')->count(),
```

Replace the whole `meetings()` method with:

```php
    public function meetings(Request $request): Response
    {
        $status = in_array($request->query('status'), ['scheduled', 'held', 'cancelled'], true)
            ? $request->query('status')
            : null;

        return Inertia::render('Board/Meetings', [
            'meetings' => BoardMeeting::withCount(['attendances as present_count' => fn ($q) => $q->where('status', 'present')])
                ->withCount('tasks')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('meeting_date')->get(),
            'filters' => ['status' => $status],
        ]);
    }
```

In `meeting()`, replace:

```php
        $meeting->load(['attendances.member', 'tasks.member', 'createdBy:id,name']);
```

with:

```php
        $meeting->load(['attendances.member', 'tasks.member', 'createdBy:id,name', 'cancelledBy:id,name']);
```

- [ ] **Step 8: Flash keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "flash.meeting_cancelled": { "en": "Meeting cancelled.", "fr": "Réunion annulée.", "ar": "تم إلغاء الاجتماع." },
    "flash.meeting_not_cancellable": { "en": "Only a scheduled meeting can be cancelled.", "fr": "Seule une réunion planifiée peut être annulée.", "ar": "لا يمكن إلغاء إلا اجتماع مجدول." },
    "flash.meeting_is_cancelled": { "en": "This meeting is cancelled and can no longer be changed.", "fr": "Cette réunion est annulée et ne peut plus être modifiée.", "ar": "هذا الاجتماع ملغى ولا يمكن تعديله." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 9: Run the tests and confirm they pass**

Run: `php artisan test --filter="BoardMeetingCancellationTest|BoardCalendarTest|FlashTranslationTest|PermissionMapTest"`

Expected: PASS. `PermissionMapTest` resolves the string `board.meetings.destroy` without needing the route to exist, so it is unaffected.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint database/migrations/2026_09_22_100002_add_cancellation_to_board_meetings.php app/Models/BoardMeeting.php app/Http/Controllers/BoardMeetingController.php app/Http/Controllers/BoardController.php routes/web.php tests/Feature/BoardMeetingCancellationTest.php
git add database/migrations/2026_09_22_100002_add_cancellation_to_board_meetings.php app/Models/BoardMeeting.php app/Http/Controllers/BoardMeetingController.php app/Http/Controllers/BoardController.php routes/web.php tests/Feature/BoardMeetingCancellationTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(board): cancel meetings with a reason instead of deleting them"
```

---

### Task 5: Meeting cancellation on screen

**Files:**
- Modify (replace whole file): `resources/js/Components/ConfirmModal.vue`
- Modify (replace whole file): `resources/js/Pages/Board/Meeting.vue`
- Modify (replace whole file): `resources/js/Pages/Board/Meetings.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes: `board.meetings.cancel`, `meeting.cancelled_at`, `meeting.cancelled_by.name`, `meeting.cancel_reason` and `filters.status` (Task 4). Also consumes `useCan()`.
- Produces: `ConfirmModal` gains a default slot (between message and buttons) and the props `cancelLabel` and `busy`. Existing callers are unchanged.

- [ ] **Step 1: ConfirmModal gets a slot, a cancel label and a busy state**

Replace the whole file `resources/js/Components/ConfirmModal.vue`:

```vue
<script setup>
import { useI18n } from 'vue-i18n';
import Modal from './Modal.vue';
import DangerButton from './DangerButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const { t } = useI18n();

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    // e.g. "Keep meeting" — a plain "Cancel" next to "Cancel meeting" misleads.
    cancelLabel: { type: String, default: '' },
    // Disables the confirm button while the request runs.
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="show" @close="emit('cancel')" max-width="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ title || t('confirm_action') }}
            </h3>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                {{ message || t('are_you_sure') }}
            </p>
            <!-- Optional extra fields, e.g. a required reason. -->
            <slot />
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('cancel')">{{ cancelLabel || t('cancel') }}</SecondaryButton>
                <DangerButton :disabled="busy" @click="emit('confirm')">{{ confirmLabel || t('delete') }}</DangerButton>
            </div>
        </div>
    </Modal>
</template>
```

- [ ] **Step 2: UI keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "cancel_meeting": { "en": "Cancel meeting", "fr": "Annuler la réunion", "ar": "إلغاء الاجتماع" },
    "cancel_meeting_confirm": { "en": "The meeting stays in the history, marked as cancelled. This cannot be undone.", "fr": "La réunion reste dans l'historique, marquée comme annulée. Cette action est irréversible.", "ar": "يبقى الاجتماع في السجل مع وسمه كملغى. لا يمكن التراجع عن ذلك." },
    "cancel_reason": { "en": "Reason for cancellation", "fr": "Motif de l'annulation", "ar": "سبب الإلغاء" },
    "keep_meeting": { "en": "Keep meeting", "fr": "Garder la réunion", "ar": "الإبقاء على الاجتماع" },
    "meeting_cancelled_banner": { "en": "Cancelled on {date} by {name}", "fr": "Annulée le {date} par {name}", "ar": "أُلغي بتاريخ {date} من طرف {name}" },
    "meeting_cancelled_readonly": { "en": "A cancelled meeting can no longer be edited.", "fr": "Une réunion annulée ne peut plus être modifiée.", "ar": "لا يمكن تعديل اجتماع ملغى." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 3: Meeting page — cancel action, banner, read-only, modal confirms**

Replace the whole file `resources/js/Pages/Board/Meeting.vue`:

```vue
<script setup>
import { reactive, ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    meeting: { type: Object, required: true },
    members: { type: Array, default: () => [] },
    allMembers: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();

// A cancelled meeting is kept as history and can no longer be changed.
const isCancelled = computed(() => props.meeting.status === 'cancelled');
const canCancel = computed(() => props.meeting.status === 'scheduled' && can('board', 'edit'));

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function fmtDateTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar' : locale.value, {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

const form = useForm({
    title: props.meeting.title,
    type: props.meeting.type,
    meeting_date: toLocalInput(props.meeting.meeting_date),
    location: props.meeting.location || '',
    // 'cancelled' is not offered: cancelling goes through the Cancel action.
    status: props.meeting.status === 'cancelled' ? 'scheduled' : props.meeting.status,
    quorum_required: props.meeting.quorum_required,
    agenda: props.meeting.agenda?.length ? [...props.meeting.agenda] : [''],
    minutes: props.meeting.minutes || '',
    decisions: props.meeting.decisions?.length ? [...props.meeting.decisions] : [''],
});
function saveMeeting() {
    form.transform((d) => ({ ...d, agenda: d.agenda.filter((x) => x && x.trim()), decisions: d.decisions.filter((x) => x && x.trim()) }))
        .put(route('board.meetings.update', props.meeting.id), { preserveScroll: true });
}

// Cancel = keep the record, mark it cancelled, say why.
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
function openCancel() {
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancel.value = true;
}
function confirmCancel() {
    cancelForm.post(route('board.meetings.cancel', props.meeting.id), {
        preserveScroll: true,
        onSuccess: () => { showCancel.value = false; },
    });
}

// Minutes file attachment (PDF / Word / photo). Multipart, so POST not PUT.
const fileForm = useForm({ attachment: null });
function uploadAttachment() {
    if (!fileForm.attachment) return;
    fileForm.post(route('board.meetings.attachment', props.meeting.id), {
        preserveScroll: true, forceFormData: true, onSuccess: () => fileForm.reset(),
    });
}
const confirmRemoveAttachment = ref(false);
function removeAttachment() {
    confirmRemoveAttachment.value = false;
    router.delete(route('board.meetings.attachment.delete', props.meeting.id), { preserveScroll: true });
}
const attachmentName = computed(() => (props.meeting.attachment_filename || '').split('/').pop());

// Attendance
const attendance = reactive({});
props.members.forEach((m) => { attendance[m.id] = m.attendance || 'present'; });
const presentCount = computed(() => Object.values(attendance).filter((s) => s === 'present').length);
function saveAttendance() {
    const rows = Object.entries(attendance).map(([id, status]) => ({ board_member_id: Number(id), status }));
    router.put(route('board.meetings.attendance', props.meeting.id), { attendances: rows }, { preserveScroll: true });
}

// Tasks
const taskForm = useForm({ title: '', board_member_id: null, due_date: '', priority: 'medium', status: 'not_started', progress: 0, board_meeting_id: props.meeting.id });
function addTask() {
    taskForm.post(route('board.tasks.store'), { preserveScroll: true, onSuccess: () => taskForm.reset('title', 'board_member_id', 'due_date') });
}
const deleteTaskId = ref(null);
function deleteTask() {
    const id = deleteTaskId.value;
    deleteTaskId.value = null;
    router.delete(route('board.tasks.destroy', id), { preserveScroll: true });
}

const attStyle = {
    present: 'bg-emerald-500 text-white',
    absent: 'bg-rose-500 text-white',
    excused: 'bg-amber-500 text-white',
};
</script>

<template>
    <Head :title="meeting.title" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.meetings')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="truncate text-lg font-bold text-slate-900 dark:text-slate-100" :class="isCancelled ? 'line-through decoration-slate-400' : ''">{{ meeting.title }}</h1>
                <div class="ms-auto flex items-center gap-2">
                    <button v-if="canCancel" type="button" @click="openCancel" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-500/30"><Icon name="xcircle" /> {{ t('cancel_meeting') }}</button>
                    <a :href="route('board.meetings.minutes', meeting.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="print" /> {{ t('minutes') }}</a>
                </div>
            </div>
        </template>

        <!-- Cancelled: the record stays, with who/when/why, and is read-only. -->
        <div v-if="isCancelled" class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-700 dark:bg-slate-800/60">
            <p class="flex items-center gap-2 font-bold text-slate-700 dark:text-slate-200"><Icon name="xcircle" /> {{ t('cancelled') }}</p>
            <p v-if="meeting.cancelled_at" class="mt-1 text-slate-500 dark:text-slate-400">
                {{ t('meeting_cancelled_banner', { date: fmtDateTime(meeting.cancelled_at), name: meeting.cancelled_by?.name || '—' }) }}
            </p>
            <p class="mt-1 text-slate-600 dark:text-slate-300">{{ t('cancel_reason') }}: {{ meeting.cancel_reason || '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ t('meeting_cancelled_readonly') }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Left: details + minutes. A disabled fieldset locks every control at once. -->
            <fieldset :disabled="isCancelled" class="min-w-0 space-y-6 lg:col-span-2">
                <!-- Core fields -->
                <section class="card space-y-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('title') }}</span>
                            <input v-model="form.title" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting_type') }}</span>
                            <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="ordinary">{{ t('ordinary') }}</option><option value="extraordinary">{{ t('extraordinary') }}</option><option value="general_assembly">{{ t('general_assembly') }}</option>
                            </select></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('status') }}</span>
                            <select v-model="form.status" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="scheduled">{{ t('scheduled') }}</option><option value="held">{{ t('held') }}</option>
                            </select>
                            <span v-if="form.errors.status" class="mt-1 block text-xs text-rose-500">{{ form.errors.status }}</span></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                            <input v-model="form.meeting_date" type="datetime-local" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('location') }}</span>
                            <input v-model="form.location" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    </div>
                    <div>
                        <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('agenda') }}</span>
                        <div class="space-y-2">
                            <div v-for="(item, i) in form.agenda" :key="i" class="flex items-center gap-2">
                                <span class="text-xs font-bold text-slate-400">{{ i + 1 }}</span>
                                <input v-model="form.agenda[i]" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <button type="button" @click="form.agenda.splice(i, 1)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                            </div>
                        </div>
                        <button type="button" @click="form.agenda.push('')" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600"><Icon name="plus" /> {{ t('add_item') }}</button>
                    </div>
                </section>

                <!-- Minutes + decisions -->
                <section class="card space-y-4 p-5">
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('minutes') }}</span>
                        <textarea v-model="form.minutes" rows="6" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                    </div>

                    <!-- Minutes file (PDF / Word / photo) -->
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('minutes_file') }}</span>
                        <div v-if="meeting.attachment_url" class="mb-2 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <Icon name="document" class="text-primary-500" />
                            <a :href="meeting.attachment_url" target="_blank" class="min-w-0 flex-1 truncate text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ attachmentName || t('view_file') }}</a>
                            <button type="button" @click="confirmRemoveAttachment = true" class="text-slate-300 hover:text-rose-500" :title="t('remove')"><Icon name="xcircle" /></button>
                        </div>
                        <div v-if="!isCancelled" class="flex flex-wrap items-center gap-2">
                            <input type="file" accept=".pdf,.doc,.docx,image/*" @change="fileForm.attachment = $event.target.files[0]"
                                class="block w-full max-w-xs text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-slate-400 dark:file:bg-primary-500/10 dark:file:text-primary-300" />
                            <button type="button" @click="uploadAttachment" :disabled="!fileForm.attachment || fileForm.processing"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"><Icon name="upload" /> {{ t('upload') }}</button>
                        </div>
                        <p v-if="fileForm.errors.attachment" class="mt-1 text-xs text-rose-500">{{ fileForm.errors.attachment }}</p>
                    </div>
                    <div>
                        <span class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('decisions') }}</span>
                        <div class="space-y-2">
                            <div v-for="(d, i) in form.decisions" :key="i" class="flex items-center gap-2">
                                <Icon name="check" class="text-emerald-500" />
                                <input v-model="form.decisions[i]" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <button type="button" @click="form.decisions.splice(i, 1)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                            </div>
                        </div>
                        <button type="button" @click="form.decisions.push('')" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600"><Icon name="plus" /> {{ t('add_item') }}</button>
                    </div>
                    <div v-if="!isCancelled" class="flex justify-end">
                        <button @click="saveMeeting" :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save_minutes') }}</button>
                    </div>
                </section>

                <!-- Tasks from this meeting -->
                <section class="card p-5">
                    <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('action_items') }}</p>
                    <ul class="space-y-2">
                        <li v-for="tk in meeting.tasks" :key="tk.id" class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ tk.title }}</span>
                                <span class="text-xs text-slate-400">{{ tk.member?.name || t('unassigned') }} · {{ t(tk.status) }}</span>
                            </span>
                            <span class="text-xs font-bold text-slate-500">{{ tk.progress }}%</span>
                            <button v-if="!isCancelled" @click="deleteTaskId = tk.id" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                        </li>
                    </ul>
                    <form v-if="!isCancelled" @submit.prevent="addTask" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <input v-model="taskForm.title" :placeholder="t('task_title')" required class="flex-1 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <select v-model="taskForm.board_member_id" class="rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option :value="null">{{ t('unassigned') }}</option>
                            <option v-for="m in allMembers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <input v-model="taskForm.due_date" type="date" class="rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <button class="rounded-xl bg-primary-600 px-3 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /></button>
                    </form>
                </section>
            </fieldset>

            <!-- Right: attendance -->
            <fieldset :disabled="isCancelled" class="min-w-0 space-y-6">
                <section class="card p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('attendance') }}</p>
                        <span class="text-xs font-semibold text-slate-400">{{ presentCount }}<span v-if="meeting.quorum_required">/{{ meeting.quorum_required }}</span></span>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="m in members" :key="m.id" class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-sm text-slate-700 dark:text-slate-200">{{ m.name }} <span class="text-xs text-slate-400">{{ t(m.role) }}</span></span>
                            <div class="flex shrink-0 gap-1">
                                <button v-for="s in ['present', 'absent', 'excused']" :key="s" @click="attendance[m.id] = s"
                                    class="rounded-md px-2 py-0.5 text-xs font-bold transition-colors"
                                    :class="attendance[m.id] === s ? attStyle[s] : 'bg-slate-100 text-slate-400 dark:bg-slate-800'">{{ t(s).charAt(0) }}</button>
                            </div>
                        </li>
                    </ul>
                    <p v-if="!members.length" class="py-4 text-center text-xs text-slate-400">{{ t('no_data') }}</p>
                    <button v-if="members.length && !isCancelled" @click="saveAttendance" class="mt-3 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save_attendance') }}</button>
                </section>
            </fieldset>
        </div>

        <ConfirmModal :show="showCancel" :title="t('cancel_meeting')" :message="t('cancel_meeting_confirm')"
            :confirm-label="t('cancel_meeting')" :cancel-label="t('keep_meeting')" :busy="cancelForm.processing"
            @confirm="confirmCancel" @cancel="showCancel = false">
            <label class="mt-4 block text-sm">
                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('cancel_reason') }}</span>
                <textarea v-model="cancelForm.reason" rows="3" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
            </label>
            <p v-if="cancelForm.errors.reason" class="mt-1 text-xs text-rose-500">{{ cancelForm.errors.reason }}</p>
        </ConfirmModal>

        <ConfirmModal :show="confirmRemoveAttachment" :message="t('confirm_delete')" @confirm="removeAttachment" @cancel="confirmRemoveAttachment = false" />
        <ConfirmModal :show="!!deleteTaskId" :message="t('confirm_delete')" @confirm="deleteTask" @cancel="deleteTaskId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Meetings list — status filter, cancelled rows muted**

Replace the whole file `resources/js/Pages/Board/Meetings.vue`:

```vue
<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    meetings: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});
const { t, locale } = useI18n();

const showForm = ref(false);
const form = useForm({ title: '', type: 'ordinary', meeting_date: '', location: '', status: 'scheduled', quorum_required: null, agenda: [''] });

function addAgenda() { form.agenda.push(''); }
function removeAgenda(i) { form.agenda.splice(i, 1); }
function submit() {
    form.transform((d) => ({ ...d, agenda: d.agenda.filter((x) => x && x.trim()) }))
        .post(route('board.meetings.store'), { onSuccess: () => { form.reset(); showForm.value = false; } });
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
}
const statusChip = {
    scheduled: 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400',
};

// Cancelled meetings stay listed (history); the filter narrows the list.
const statusFilter = ref(props.filters?.status || '');
watch(statusFilter, (status) => {
    router.get(route('board.meetings'), status ? { status } : {}, {
        only: ['meetings', 'filters'], preserveState: true, preserveScroll: true, replace: true,
    });
});
const filterOptions = ['', 'scheduled', 'held', 'cancelled'];
</script>

<template>
    <Head :title="t('meetings')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('meetings') }}</h1>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="s in filterOptions" :key="s || 'all'" type="button" @click="statusFilter = s"
                        class="rounded-full px-3 py-1 text-xs font-bold transition-colors"
                        :class="statusFilter === s ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-800'">
                        {{ s ? t(s) : t('all') }}
                    </button>
                </div>
                <button @click="showForm = !showForm" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_meeting') }}</button>
            </div>

            <form v-if="showForm" @submit.prevent="submit" class="card space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('title') }}</span>
                        <input v-model="form.title" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting_type') }}</span>
                        <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="ordinary">{{ t('ordinary') }}</option><option value="extraordinary">{{ t('extraordinary') }}</option><option value="general_assembly">{{ t('general_assembly') }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                        <input v-model="form.meeting_date" type="datetime-local" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('location') }}</span>
                        <input v-model="form.location" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('quorum') }}</span>
                        <input v-model="form.quorum_required" type="number" min="0" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('agenda') }}</span>
                    <div class="space-y-2">
                        <div v-for="(item, i) in form.agenda" :key="i" class="flex items-center gap-2">
                            <input v-model="form.agenda[i]" :placeholder="`${i + 1}.`" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" @click="removeAgenda(i)" class="text-slate-300 hover:text-rose-500"><Icon name="xcircle" /></button>
                        </div>
                    </div>
                    <button type="button" @click="addAgenda" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700"><Icon name="plus" /> {{ t('add_item') }}</button>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <button :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('create') }}</button>
                </div>
            </form>

            <div class="space-y-3">
                <Link v-for="mt in meetings" :key="mt.id" :href="route('board.meetings.show', mt.id)"
                    class="card flex items-center justify-between gap-4 p-4 transition-shadow hover:shadow-md"
                    :class="mt.status === 'cancelled' ? 'opacity-70' : ''">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/20"><Icon name="calendar" /></span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100" :class="mt.status === 'cancelled' ? 'line-through decoration-slate-400' : ''">{{ mt.title }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ fmtDate(mt.meeting_date) }} · {{ t(mt.type) }}<span v-if="mt.location"> · {{ mt.location }}</span></p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span v-if="mt.present_count" class="hidden items-center gap-1 text-slate-400 sm:inline-flex"><Icon name="board" /> {{ mt.present_count }}</span>
                        <span v-if="mt.tasks_count" class="hidden items-center gap-1 text-slate-400 sm:inline-flex"><Icon name="task" /> {{ mt.tasks_count }}</span>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 font-bold" :class="statusChip[mt.status]">{{ t(mt.status) }}</span>
                    </div>
                </Link>
                <p v-if="!meetings.length" class="py-10 text-center text-sm text-slate-400">{{ t('no_meetings') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Build and check**

Run: `npm run build`. Expected: success.

Run: `npm run i18n:check`. Expected: `✓ …`.

Run: `php artisan test --filter=FlashTranslationTest`. Expected: PASS, with the catalogs still the same size.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/ConfirmModal.vue resources/js/Pages/Board/Meeting.vue resources/js/Pages/Board/Meetings.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(board): cancel a meeting from its page, cancelled meetings stay listed"
```

---

### Task 6: `UiLang`, the title column and the `TransactionTitle` presenter

**Files:**
- Create: `app/Support/UiLang.php`
- Create: `app/Support/TransactionTitle.php`
- Create: `database/migrations/2026_09_22_100003_add_title_to_transactions.php`
- Modify: `app/Models/Transaction.php` (fillable `title`, relation `relatedPlayer()`)
- Modify: `app/Models/Player.php` (accessor `short_name`)
- Test: `tests/Feature/UiLangTest.php`, `tests/Feature/TransactionTitleTest.php`

**Interfaces:**
- Produces `UiLang::get(string $key, ?string $default = null, ?string $locale = null): string`. It tries the current app locale, then English, then `$default`, then the key itself.
- Produces `TransactionTitle::RELATIONS`, the eager loads its methods read:

```php
[
    'financeCategory',
    'relatedPlayer:id,firstname,lastname,membership_id',
    'playerSubscription:id,subscription_id,label,year',
    'playerSubscription.subscription:id,name,year',
]
```

- Produces `TransactionTitle::for(Transaction): string`.
- Produces `TransactionTitle::decorate(Transaction): Transaction`. It sets `display_title` and `player_summary` (`{id, name, membership_id}` or null), then unsets the `relatedPlayer` and `playerSubscription` relations.
- Produces `Transaction::relatedPlayer(): BelongsTo`. It is only meaningful when `related_entity_type === 'Player'`.
- Produces `Player::$short_name`, meaning "firstname lastname" and never "Amine null".

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/UiLangTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Support\UiLang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UiLangTest extends TestCase
{
    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        return json_decode(file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
    }

    #[Test]
    public function it_reads_the_label_in_the_current_locale(): void
    {
        app()->setLocale('fr');

        $this->assertSame($this->catalog('fr')['donation'], UiLang::get('donation'));
    }

    #[Test]
    public function an_explicit_locale_wins(): void
    {
        app()->setLocale('en');

        $this->assertSame($this->catalog('ar')['donation'], UiLang::get('donation', null, 'ar'));
    }

    #[Test]
    public function a_missing_key_returns_the_default_then_the_key(): void
    {
        $this->assertSame('Fallback', UiLang::get('no_such_key_zz', 'Fallback'));
        $this->assertSame('no_such_key_zz', UiLang::get('no_such_key_zz'));
    }

    #[Test]
    public function an_unsupported_locale_reads_english(): void
    {
        $this->assertSame($this->catalog('en')['donation'], UiLang::get('donation', null, 'de'));
    }
}
```

Create `tests/Feature/TransactionTitleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Support\TransactionTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTitleTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026]);
    }

    private function subscriptionPayment(): Transaction
    {
        FinanceCategory::updateOrCreate(
            ['type' => 'income', 'name' => 'Subscription'],
            ['name_fr' => 'Cotisation', 'name_ar' => 'اشتراك', 'is_active' => true],
        );
        $player = $this->player();
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'label' => 'Saison', 'year' => 2026,
            'amount_owed' => 1000, 'amount_paid' => 0,
        ]);

        return Transaction::create([
            'amount' => 500, 'transaction_type' => 'income', 'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id,
            'player_subscription_id' => $line->id,
        ])->load(TransactionTitle::RELATIONS);
    }

    #[Test]
    public function a_typed_title_wins(): void
    {
        $tx = Transaction::create([
            'title' => 'Hall rent — March', 'amount' => 8000, 'transaction_type' => 'expense',
            'category' => 'rent', 'status' => 'Paid',
        ])->load(TransactionTitle::RELATIONS);

        $this->assertSame('Hall rent — March', TransactionTitle::for($tx));
    }

    #[Test]
    public function an_untitled_payment_is_labelled_in_the_viewers_language(): void
    {
        $tx = $this->subscriptionPayment();

        app()->setLocale('fr');
        $this->assertSame('Cotisation · Saison 2026 · Amine Benali', TransactionTitle::for($tx));

        // Not stored: the same row reads in Arabic after a language switch.
        app()->setLocale('ar');
        $this->assertSame('اشتراك · Saison 2026 · Amine Benali', TransactionTitle::for($tx));
    }

    #[Test]
    public function decorate_exposes_title_and_player_without_leaking_helper_relations(): void
    {
        app()->setLocale('fr');
        $array = TransactionTitle::decorate($this->subscriptionPayment())->toArray();

        $this->assertSame('Cotisation · Saison 2026 · Amine Benali', $array['display_title']);
        $this->assertSame('Amine Benali', $array['player_summary']['name']);
        $this->assertSame('202600017', $array['player_summary']['membership_id']);
        $this->assertArrayNotHasKey('related_player', $array);
        $this->assertArrayNotHasKey('player_subscription', $array);
    }

    #[Test]
    public function a_transaction_without_a_player_has_no_player_summary(): void
    {
        app()->setLocale('en');
        $tx = Transaction::create([
            'amount' => 50, 'transaction_type' => 'expense', 'category' => 'supplies', 'status' => 'Paid',
        ])->load(TransactionTitle::RELATIONS);

        $array = TransactionTitle::decorate($tx)->toArray();

        $this->assertNull($array['player_summary']);
        $this->assertSame($tx->financeCategory->localized_name, $array['display_title']);
    }

    #[Test]
    public function a_player_without_a_last_name_is_never_called_null(): void
    {
        $player = Player::create(['membership_id' => '202600019', 'firstname' => 'Yanis']);

        $this->assertSame('Yanis', $player->short_name);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter="UiLangTest|TransactionTitleTest"`

Expected: FAIL. The errors are `Class "App\Support\UiLang" not found` and `Class "App\Support\TransactionTitle" not found`.

- [ ] **Step 3: Migration**

Create `database/migrations/2026_09_22_100003_add_title_to_transactions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short, human title for a transaction. Nullable: existing rows and
     * everything the app records by itself (subscription payments, donations,
     * imports) get a label generated at render time instead — see
     * App\Support\TransactionTitle.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('title', 150)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
```

- [ ] **Step 4: Model changes**

In `app/Models/Transaction.php`, add `'title',` as the first entry of `$fillable`. Then add after `receivedBy()`:

```php
    /**
     * The player a transaction is about. Only meaningful when
     * related_entity_type is 'Player' — callers check the type first
     * (see TransactionTitle::player()).
     */
    public function relatedPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'related_entity_id');
    }
```

In `app/Models/Player.php`, add this method right after `getAgeAttribute()`:

```php
    /** "Firstname Lastname" for lists and labels; never "Amine null". */
    public function getShortNameAttribute(): string
    {
        return trim($this->firstname.' '.($this->lastname ?? ''));
    }
```

- [ ] **Step 5: `UiLang`**

Create `app/Support/UiLang.php`:

```php
<?php

namespace App\Support;

/**
 * Server-side access to the UI's own label catalogs (resources/js/i18n/*.json).
 *
 * Text the server renders for a screen — generated transaction titles now,
 * export headers and values later — must read exactly like the screen around
 * it, so it comes from the same catalogs instead of a second copy in
 * lang/*.json. The desktop build ships resources/js/i18n, so this works there.
 */
final class UiLang
{
    private const LOCALES = ['ar', 'fr', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** The label for $key in $locale (default: the app locale), else English, else $default, else the key. */
    public static function get(string $key, ?string $default = null, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return self::catalog($locale)[$key]
            ?? self::catalog('en')[$key]
            ?? $default
            ?? $key;
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'en';
        }

        return self::$catalogs[$locale] ??= json_decode(
            (string) file_get_contents(resource_path("js/i18n/{$locale}.json")),
            true,
        ) ?: [];
    }
}
```

- [ ] **Step 6: `TransactionTitle`**

Create `app/Support/TransactionTitle.php`:

```php
<?php

namespace App\Support;

use App\Models\Player;
use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * The label a transaction is known by on screens, receipts and exports.
 *
 * A title typed by the user wins. Transactions the app records by itself
 * (subscription payments, donations, debt payments, equipment purchases,
 * imports) carry none, so their label is built here at render time — in the
 * viewer's language — rather than stored, which would freeze it in whatever
 * language the recorder happened to use.
 *
 * Reads only relations the caller eager-loaded (RELATIONS); it never
 * lazy-loads, so labelling a page of rows costs no extra queries.
 */
final class TransactionTitle
{
    /** Eager loads that for() and decorate() read. */
    public const RELATIONS = [
        'financeCategory',
        'relatedPlayer:id,firstname,lastname,membership_id',
        'playerSubscription:id,subscription_id,label,year',
        'playerSubscription.subscription:id,name,year',
    ];

    public static function for(Transaction $transaction): string
    {
        if (filled($transaction->title)) {
            return (string) $transaction->title;
        }

        $parts = [
            self::categoryLabel($transaction),
            self::subscriptionLabel($transaction),
            self::player($transaction)?->short_name,
        ];

        return implode(' · ', array_filter($parts, fn (?string $part) => filled($part)));
    }

    /**
     * Adds display_title and player_summary for the UI, then drops the helper
     * relations so they are not serialized: PlayerSubscription appends
     * accessors that would lazy-load once per row.
     */
    public static function decorate(Transaction $transaction): Transaction
    {
        $player = self::player($transaction);

        $transaction->setAttribute('display_title', self::for($transaction));
        $transaction->setAttribute('player_summary', $player ? [
            'id' => $player->id,
            'name' => $player->short_name,
            'membership_id' => $player->membership_id,
        ] : null);

        return $transaction->unsetRelation('relatedPlayer')->unsetRelation('playerSubscription');
    }

    /** The linked player, only when the transaction really points at one. */
    private static function player(Transaction $transaction): ?Player
    {
        if ($transaction->related_entity_type !== 'Player' || ! $transaction->relationLoaded('relatedPlayer')) {
            return null;
        }

        return $transaction->relatedPlayer;
    }

    private static function categoryLabel(Transaction $transaction): string
    {
        if ($transaction->relationLoaded('financeCategory') && $transaction->financeCategory) {
            return $transaction->financeCategory->localized_name;
        }

        $slug = (string) ($transaction->category ?: 'transaction');

        return UiLang::get($slug, Str::headline($slug));
    }

    private static function subscriptionLabel(Transaction $transaction): ?string
    {
        if (! $transaction->relationLoaded('playerSubscription') || ! $transaction->playerSubscription) {
            return null;
        }

        $line = $transaction->playerSubscription;
        $name = trim((string) ($line->label ?: $line->subscription?->name));
        $year = $line->year ? (string) $line->year : '';

        // "Dette 2024" already names its year; don't print it twice.
        if ($year === '' || str_contains($name, $year)) {
            return $name !== '' ? $name : null;
        }

        return trim("{$name} {$year}");
    }
}
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter="UiLangTest|TransactionTitleTest|TransactionFinanceCategoryTest"`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint app/Support/UiLang.php app/Support/TransactionTitle.php database/migrations/2026_09_22_100003_add_title_to_transactions.php app/Models/Transaction.php app/Models/Player.php tests/Feature/UiLangTest.php tests/Feature/TransactionTitleTest.php
git add app/Support/UiLang.php app/Support/TransactionTitle.php database/migrations/2026_09_22_100003_add_title_to_transactions.php app/Models/Transaction.php app/Models/Player.php tests/Feature/UiLangTest.php tests/Feature/TransactionTitleTest.php
git commit -m "feat(transactions): title column and a translated generated label"
```

---

### Task 7: The title flows everywhere a transaction appears

**Files:**
- Modify: `app/Http/Requests/Transaction/StoreTransactionRequest.php`
- Modify: `app/Http/Controllers/TransactionController.php` (`index`, `export`, `show`, `edit`)
- Modify: `app/Http/Controllers/PlayerController.php` (`show`)
- Modify: `app/Http/Controllers/ReportController.php` (`transactionReceipt`)
- Modify: `resources/views/pdf/receipt.blade.php`
- Modify: `app/Services/Dashboard/OverviewStats.php` (`activity`)
- Modify: `app/Http/Controllers/TransactionImportController.php`
- Modify: `tests/Feature/CashRegisterManagementTest.php` (two posts gain a `title`)
- Test: `tests/Feature/TransactionTitleFlowTest.php`, `tests/Feature/Dashboard/ActivityFeedLabelTest.php`

**Interfaces:**
- Consumes: `TransactionTitle::RELATIONS`, `for()` and `decorate()`, and `UiLang::get()` (Task 6).
- Produces the following page props, consumed in Task 9:
  - `transactions.data[*]` gains `display_title` and `player_summary` (index);
  - `transaction.display_title` and `transaction.player_summary` (show and edit);
  - on the player page, `transactions[*].display_title`.
- Produces request rules:
  - `title` is `required|string|max:150`;
  - `related_entity_type` is `nullable|required_with:related_entity_id|in:Player`;
  - `related_entity_id` must exist in `players`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TransactionTitleFlowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTitleFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'en']);
    }

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift — Café du Port',
            'transaction_type' => 'income',
            'finance_category_id' => $category->id,
            'amount' => 3000,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ], $overrides);
    }

    #[Test]
    public function a_transaction_cannot_be_created_without_a_title(): void
    {
        $this->actingAs($this->admin())
            ->post(route('transactions.store'), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function the_title_is_stored_and_listed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('transactions.store'), $this->payload())->assertRedirect();

        $this->assertSame('Sponsor gift — Café du Port', Transaction::firstOrFail()->title);

        $props = $this->actingAs($admin)->get(route('transactions.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('Sponsor gift — Café du Port', $props['transactions']['data'][0]['display_title']);
        $this->assertNull($props['transactions']['data'][0]['player_summary']);
    }

    #[Test]
    public function an_unknown_player_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('transactions.store'), $this->payload([
                'related_entity_type' => 'Player',
                'related_entity_id' => 999999,
            ]))
            ->assertSessionHasErrors('related_entity_id');
    }

    #[Test]
    public function a_linked_player_is_listed_with_the_transaction(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->actingAs($admin)->post(route('transactions.store'), $this->payload([
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
        ]))->assertRedirect();

        $props = $this->actingAs($admin)->get(route('transactions.index'))->viewData('page')['props'];
        $this->assertSame('202600017', $props['transactions']['data'][0]['player_summary']['membership_id']);
    }

    #[Test]
    public function editing_an_untitled_transaction_prefills_the_generated_label(): void
    {
        $tx = Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);

        $props = $this->actingAs($this->admin())->get(route('transactions.edit', $tx))
            ->assertOk()->viewData('page')['props'];

        app()->setLocale('en');
        $this->assertSame(
            TransactionTitle::for($tx->fresh()->load(TransactionTitle::RELATIONS)),
            $props['transaction']['display_title'],
        );
        $this->assertNotSame('', $props['transaction']['display_title']);
    }

    #[Test]
    public function updating_requires_the_title_too(): void
    {
        $tx = Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);

        $this->actingAs($this->admin())
            ->put(route('transactions.update', $tx), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    #[Test]
    public function the_player_page_labels_payments_by_title(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'category' => 'donation',
            'amount' => 500,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $props = $this->actingAs($admin)->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('Amine Benali', $props['transactions'][0]['display_title']);
    }

    #[Test]
    public function the_import_reads_an_optional_title_column(): void
    {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'Date,Type,Category,Amount,Status,Payment Method,Description,Title',
            '2026-01-15,income,donation,1000,Paid,cash,,Hall rent refund',
            '2026-01-16,income,donation,400,Paid,cash,,',
        ])."\n";

        $this->actingAs($this->admin())->post(route('transactions.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $csv),
        ])->assertRedirect();

        $this->assertSame(['Hall rent refund', null], Transaction::orderBy('id')->pluck('title')->all());
    }
}
```

Create `tests/Feature/Dashboard/ActivityFeedLabelTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Models\Transaction;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\OverviewStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityFeedLabelTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return app(OverviewStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard', 'GET')),
        )['activity'];
    }

    #[Test]
    public function a_titled_transaction_shows_its_title_in_the_feed(): void
    {
        Transaction::create([
            'title' => 'Hall rent — March', 'description' => 'Paid in cash at the town hall',
            'amount' => 8000, 'transaction_type' => 'expense', 'category' => 'rent', 'status' => 'Paid',
        ]);

        $this->assertSame('Hall rent — March', $this->activity()[0]['label']);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter="TransactionTitleFlowTest|ActivityFeedLabelTest"`

Expected: FAIL. `a_transaction_cannot_be_created_without_a_title` fails with "Session is missing expected key [errors]", and there are other failures on `display_title` and the feed label.

- [ ] **Step 3: Request rules**

In `app/Http/Requests/Transaction/StoreTransactionRequest.php`:

Add as the first rule:

```php
            'title' => ['required', 'string', 'max:150'],
```

Replace the two `related_entity_*` rules with:

```php
            // A transaction points at a player by id, never by name — and the id must exist.
            'related_entity_type' => ['nullable', 'required_with:related_entity_id', 'in:Player'],
            'related_entity_id' => ['nullable', 'integer', Rule::exists('players', 'id')],
```

- [ ] **Step 4: TransactionController**

In `app/Http/Controllers/TransactionController.php`, add `use App\Support\TransactionTitle;` to the imports. Then make the following edits.

**`index()`:** replace:

```php
        $transactions = $query->with(['recordedBy', 'receivedBy', 'financeCategory', ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();
```

with:

```php
        $transactions = $query->with(['recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL])
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));
```

**`export()`:** make three replacements.

Replace the query line with:

```php
        $query = Transaction::query()->with(['recordedBy', 'financeAccount', ...TransactionTitle::RELATIONS])->where('archived', false);
```

In the row array, insert `TransactionTitle::for($t),` right after `$t->transaction_date?->format('Y-m-d'),`.

Replace the headers with:

```php
        $headers = ['Date', 'Title', 'Type', 'Category', 'Amount', 'Status', 'Payment', 'Cash Register', 'Description', 'Recorded By'];
```

**`show()`:** add `...TransactionTitle::RELATIONS,` inside the `load([...])` array, after `'financeCategory',`. Then, immediately before `return Inertia::render('Transactions/Show', [`, insert:

```php
        TransactionTitle::decorate($transaction);
```

**`edit()`:** replace its body with:

```php
        // The edit form pre-fills the title with the generated label when none is stored.
        TransactionTitle::decorate($transaction->load(TransactionTitle::RELATIONS));

        return Inertia::render('Transactions/Edit', [
            'transaction' => $transaction,
            ...$this->formOptions(),
        ]);
```

- [ ] **Step 5: Player page, receipt, dashboard feed**

**`PlayerController::show()`** (`app/Http/Controllers/PlayerController.php`).

Add `use App\Support\TransactionTitle;` to the imports. Then replace:

```php
        $transactions = Transaction::query()
            ->with(Transaction::FINANCE_ACCOUNT_LABEL)
            ->where('related_entity_type', 'Player')
            ->where('related_entity_id', $player->id)
            ->where('archived', false)
            ->orderByDesc('transaction_date')
            ->get();
```

with:

```php
        $transactions = Transaction::query()
            ->with([...Transaction::FINANCE_ACCOUNT_LABEL, ...TransactionTitle::RELATIONS])
            ->where('related_entity_type', 'Player')
            ->where('related_entity_id', $player->id)
            ->where('archived', false)
            ->orderByDesc('transaction_date')
            ->get()
            ->each(fn (Transaction $transaction) => TransactionTitle::decorate($transaction));
```

**`ReportController::transactionReceipt()`** (`app/Http/Controllers/ReportController.php`).

Add these imports: `use App\Support\TransactionTitle;` and `use App\Support\UiLang;`.

Replace `$transaction->load(['recordedBy', 'receivedBy']);` with:

```php
        $transaction->load(['recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS]);
```

In the `view('pdf.receipt', [...])` array, add:

```php
            'title' => TransactionTitle::for($transaction),
            'categoryLabel' => $transaction->financeCategory?->localized_name ?? $transaction->category,
            'statusLabel' => UiLang::get(strtolower((string) $transaction->status), (string) $transaction->status),
```

**`resources/views/pdf/receipt.blade.php`**:
- After the `Receipt No.` row, add: `<tr><td class="label">{{ __('Title') }}</td><td>{{ $title }}</td></tr>`.
- Replace `<td>{{ $transaction->category }}</td>` with `<td>{{ $categoryLabel }}</td>`.
- Replace `<span class="badge">{{ $transaction->status }}</span>` with `<span class="badge">{{ $statusLabel }}</span>`.

Add `"Title"` to both PHP catalogs, in alphabetical position. It goes between `"This subscription does not apply to this player's category."` and `"Total Expense"`:
- `lang/fr.json`: `"Title": "Intitulé",`
- `lang/ar.json`: `"Title": "العنوان",`

**`OverviewStats::activity()`** (`app/Services/Dashboard/OverviewStats.php`), in the transactions query:

Replace `->get(['id', 'transaction_date', 'amount', 'transaction_type', 'category', 'description'])` with:

```php
            ->get(['id', 'transaction_date', 'amount', 'transaction_type', 'category', 'title', 'description'])
```

Replace `'label' => $t->description ?: $t->category,` with:

```php
                'label' => $t->title ?: ($t->description ?: $t->category),
```

- [ ] **Step 6: Import reads an optional title column**

In `app/Http/Controllers/TransactionImportController.php`, add a last entry to `COLUMNS`, after the description row:

```php
        // Last so files made from the old 7-column template still import.
        ['title', 'Title', ''],
```

In `Transaction::create([...])` inside `store()`, add:

```php
                    'title' => $data['title'] ? mb_substr($data['title'], 0, 150) : null,
```

- [ ] **Step 7: Existing tests post a title**

In `tests/Feature/CashRegisterManagementTest.php`, the two `post(route('transactions.store'), [...])` payloads each gain a title:
- the income one: `'title' => 'Sponsor gift',`
- the expense one: `'title' => 'Printer ink',`

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `php artisan test --filter="TransactionTitleFlowTest|ActivityFeedLabelTest|CashRegisterManagementTest|ReportPdfTest|OverviewStatsTest|PlayerPaymentFlowTest|TransactionFinanceCategoryTest"`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint app/Http/Requests/Transaction/StoreTransactionRequest.php app/Http/Controllers/TransactionController.php app/Http/Controllers/PlayerController.php app/Http/Controllers/ReportController.php app/Services/Dashboard/OverviewStats.php app/Http/Controllers/TransactionImportController.php tests/Feature/TransactionTitleFlowTest.php tests/Feature/Dashboard/ActivityFeedLabelTest.php tests/Feature/CashRegisterManagementTest.php
git add app/Http/Requests/Transaction/StoreTransactionRequest.php app/Http/Controllers/TransactionController.php app/Http/Controllers/PlayerController.php app/Http/Controllers/ReportController.php resources/views/pdf/receipt.blade.php app/Services/Dashboard/OverviewStats.php app/Http/Controllers/TransactionImportController.php lang/ar.json lang/fr.json tests/Feature/TransactionTitleFlowTest.php tests/Feature/Dashboard/ActivityFeedLabelTest.php tests/Feature/CashRegisterManagementTest.php
git commit -m "feat(transactions): require a title and show it on lists, receipts and the feed"
```

---

### Task 8: Find and pick the right player (backend)

**Why:** The form sends only `firstname` / `lastname`. Two players with the same name look identical, and a player with no last name renders as "Amine null". Transaction search does not look at players at all.

**Files:**
- Modify: `app/Models/Player.php` (add `scopeSearch`)
- Modify: `app/Http/Controllers/PlayerController.php` (use the scope; delete `applySearch`)
- Modify: `app/Http/Controllers/TransactionController.php` (`applyFilters`, `formOptions`, `edit`)
- Test: `tests/Feature/TransactionPlayerLookupTest.php`

**Interfaces:**
- Produces: `Player::query()->search(string $search)`. Each whitespace token must match some name column or `membership_id`. `بن` is ignored.
- Produces: `players[*]` in the transaction form props has these fields:

```
{ id, name, fullname, membership_id, category, birth_year, picture_url,
  branches: string[], outstanding_debt: float, default_finance_account_id }
```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TransactionPlayerLookupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionPlayerLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function paymentFor(?Player $player, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
            'related_entity_type' => $player ? 'Player' : null,
            'related_entity_id' => $player?->id,
        ], $attributes));
    }

    /** @return list<int> */
    private function searchIds(string $search): array
    {
        $props = $this->actingAs($this->admin())->get(route('transactions.index', ['search' => $search]))
            ->assertOk()->viewData('page')['props'];

        return collect($props['transactions']['data'])->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function search_matches_the_title(): void
    {
        $hall = $this->paymentFor(null, ['title' => 'Hall rent March']);
        $this->paymentFor(null, ['title' => 'Printer ink']);

        $this->assertSame([$hall->id], $this->searchIds('hall rent'));
    }

    #[Test]
    public function search_finds_a_players_payments_by_name_in_any_order_or_membership_id(): void
    {
        $amine = Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali']);
        $other = Player::create(['membership_id' => '202600018', 'firstname' => 'Yanis', 'lastname' => 'Kaci']);
        $mine = $this->paymentFor($amine);
        $this->paymentFor($other);

        $this->assertSame([$mine->id], $this->searchIds('benali amine'));
        $this->assertSame([$mine->id], $this->searchIds('202600017'));
    }

    #[Test]
    public function the_form_tells_two_players_with_the_same_name_apart(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);
        Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'category_id' => $cadets->id, 'birthdate' => '2010-03-01']);
        Player::create(['membership_id' => '202600018', 'firstname' => 'Amine', 'lastname' => 'Benali', 'category_id' => $juniors->id, 'birthdate' => '2008-07-15']);

        $players = collect($this->actingAs($this->admin())->get(route('transactions.create'))
            ->assertOk()->viewData('page')['props']['players'])->keyBy('membership_id');

        $this->assertSame('Amine Benali', $players['202600017']['name']);
        $this->assertSame('Cadets', $players['202600017']['category']);
        $this->assertSame(2010, $players['202600017']['birth_year']);
        $this->assertSame('Juniors', $players['202600018']['category']);
        $this->assertSame(2008, $players['202600018']['birth_year']);
    }

    #[Test]
    public function a_player_without_a_last_name_is_labelled_by_first_name_only(): void
    {
        Player::create(['membership_id' => '202600019', 'firstname' => 'Yanis']);

        $players = $this->actingAs($this->admin())->get(route('transactions.create'))->viewData('page')['props']['players'];

        $this->assertSame('Yanis', $players[0]['name']);
    }

    #[Test]
    public function editing_keeps_an_archived_player_selectable(): void
    {
        $archived = Player::create(['membership_id' => '202600020', 'firstname' => 'Sami', 'lastname' => 'Z', 'archived' => true]);
        $tx = $this->paymentFor($archived);

        $players = $this->actingAs($this->admin())->get(route('transactions.edit', $tx))->viewData('page')['props']['players'];

        $this->assertContains($archived->id, collect($players)->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=TransactionPlayerLookupTest`

Expected: FAIL. The name/membership search returns nothing, `players[*].name` is undefined, and the archived player is missing.

- [ ] **Step 3: Move the name search onto the model**

In `app/Models/Player.php`, add this method after `scopeEligibleFor(...)`. The `Builder` import already exists.

```php
    /**
     * Name search across every part of a player's name, plus the membership ID.
     *
     * The full name is spread over several columns (lastname firstname (nickname)
     * بن father grandfather), so matching the raw term against single columns fails
     * for anything but one word. Instead each whitespace-separated token must match
     * SOME column (AND across tokens, OR across columns). That makes the search
     * order-independent and works with a full name, a partial one, and with or
     * without the بن connector — while still requiring all tokens to land on the
     * same player.
     */
    public function scopeSearch(Builder $query, string $search): void
    {
        $columns = ['firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id'];

        // بن is a connector in the rendered full name, not part of any column.
        $tokens = collect(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->reject(fn ($token) => $token === 'بن')
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $inner) use ($token, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', '%'.$token.'%');
                    }
                });
            }
        });
    }
```

In `app/Http/Controllers/PlayerController.php`:
- Replace every `$this->applySearch($query, (string) $request->input('search'));` with `$query->search((string) $request->input('search'));`. Confirm with `grep -n "applySearch" app/Http/Controllers/PlayerController.php` that only the method definition remains.
- Delete the `applySearch` method and its docblock.

- [ ] **Step 4: Transaction search and form payload**

In `app/Http/Controllers/TransactionController.php`:

In `applyFilters`, replace the `if ($request->filled('search')) { ... }` block with:

```php
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $like = "%{$search}%";
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like)
                // A player's payments, found by any part of the name or the membership ID.
                ->orWhere(fn (Builder $p) => $p
                    ->where('related_entity_type', 'Player')
                    ->whereIn('related_entity_id', Player::query()->search($search)->select('id'))));
        }
```

Replace the whole `formOptions()` method, including its docblock, with:

```php
    /**
     * Shared Create/Edit form props. Players carry enough to tell two people
     * with the same name apart (membership ID, category, birth year, photo);
     * the transaction's own player stays selectable on edit even once archived.
     */
    private function formOptions(?Transaction $transaction = null): array
    {
        $financeAccounts = FinanceAccount::selectable()->get();
        $registers = new DefaultRegisterResolver($financeAccounts);
        $ownPlayerId = $transaction?->related_entity_type === 'Player' ? $transaction->related_entity_id : null;

        return [
            'financeCategories' => FinanceCategory::where('is_active', true)
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en', 'color']),
            'clubCcp' => WebsiteConfig::query()->first()?->banking_info['ccp'] ?? null,
            'players' => Player::query()
                ->where(fn (Builder $q) => $q->where('archived', false)
                    ->when($ownPlayerId, fn (Builder $q) => $q->orWhere('id', $ownPlayerId)))
                ->with(['branches:id,name,name_ar,name_fr,name_en', 'category:id,name,name_ar,name_fr,name_en'])
                ->orderBy('lastname')->orderBy('firstname')
                ->get()
                ->map(fn (Player $player) => [
                    'id' => $player->id,
                    'name' => $player->short_name,
                    'fullname' => $player->fullname,
                    'membership_id' => $player->membership_id,
                    'category' => $player->category?->localized_name,
                    'birth_year' => $player->birthdate?->year,
                    'picture_url' => $player->picture_url,
                    'branches' => $player->branches->map(fn ($branch) => $branch->localized_name)->values(),
                    'outstanding_debt' => (float) $player->outstanding_debt,
                    'default_finance_account_id' => $registers->forPlayer($player)?->id,
                ]),
            'financeAccounts' => $financeAccounts,
        ];
    }
```

In `edit()`, change `...$this->formOptions(),` to `...$this->formOptions($transaction),`.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `php artisan test --filter="TransactionPlayerLookupTest|PlayerSearchTest|PlayerStatsFilterTest|TransactionTitleFlowTest"`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Models/Player.php app/Http/Controllers/PlayerController.php app/Http/Controllers/TransactionController.php tests/Feature/TransactionPlayerLookupTest.php
git add app/Models/Player.php app/Http/Controllers/PlayerController.php app/Http/Controllers/TransactionController.php tests/Feature/TransactionPlayerLookupTest.php
git commit -m "feat(transactions): search by player and identify players beyond their name"
```

---

### Task 9: Transactions on screen — title field, player picker, list and details

**Files:**
- Modify (replace whole file): `resources/js/Components/SearchableSelect.vue`
- Modify: `resources/js/Pages/Transactions/Partials/TransactionForm.vue`
- Modify: `resources/js/Pages/Transactions/Index.vue`
- Modify: `resources/js/Pages/Transactions/Show.vue`
- Modify: `resources/js/Pages/Players/Show.vue` (transaction table)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes the props from Tasks 7 and 8.
- Produces: `SearchableSelect` options may carry `description` (a second muted line) and `keywords` (hidden search text). Callers that pass neither behave as before, except that search is now word-based.

- [ ] **Step 1: UI keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "transaction_title_placeholder": { "en": "e.g. Hall rent for March", "fr": "ex. Location de la salle – mars", "ar": "مثال: كراء القاعة – مارس" },
    "born_in": { "en": "Born", "fr": "Né(e) en", "ar": "مواليد" },
    "view_player": { "en": "View player", "fr": "Voir le joueur", "ar": "عرض اللاعب" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 2: SearchableSelect — second line and word search**

Replace the whole file `resources/js/Components/SearchableSelect.vue`:

```vue
<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    // [{ value, label, description?, keywords? }]. description is a muted second
    // line (e.g. membership ID · category · birth year); keywords is extra search
    // text that is not shown (e.g. the full name with the father's name).
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const queryText = ref('');
const active = ref(0);
const root = ref(null);

const selected = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)) || null);

// Every typed word must appear somewhere in the label, description or keywords:
// "benali amine" and "amine benali" find the same person, and a membership ID
// typed on its own finds them too.
const filtered = computed(() => {
    const tokens = queryText.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!tokens.length) return props.options;
    return props.options.filter((o) => {
        const haystack = [o.label, o.description, o.keywords].filter(Boolean).join(' ').toLowerCase();
        return tokens.every((token) => haystack.includes(token));
    });
});

watch([queryText, open], () => { active.value = 0; });

function choose(option) {
    emit('update:modelValue', option ? option.value : '');
    open.value = false;
    queryText.value = '';
}

function onKey(e) {
    if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) { open.value = true; return; }
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, filtered.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { if (filtered.value[active.value]) choose(filtered.value[active.value]); e.preventDefault(); }
    else if (e.key === 'Escape') { open.value = false; }
}

function onBlur(e) {
    if (root.value && !root.value.contains(e.relatedTarget)) open.value = false;
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onBlur">
        <button type="button" :disabled="disabled" @click="open = !open" @keydown="onKey"
            class="mt-1 flex w-full items-center justify-between rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-start text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50">
            <span :class="selected ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400'">{{ selected ? selected.label : (placeholder || '-') }}</span>
            <span class="flex items-center gap-1">
                <span v-if="selected" class="text-slate-400 hover:text-rose-500" @click.stop="choose(null)">&times;</span>
                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>

        <div v-if="open" class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg">
            <input v-model="queryText" @keydown="onKey" autofocus
                class="w-full rounded-t-lg border-0 border-b border-slate-200 dark:border-slate-700 bg-transparent px-3 py-2 text-sm focus:ring-0" :placeholder="placeholder" />
            <ul class="max-h-56 overflow-y-auto py-1">
                <li v-for="(o, i) in filtered" :key="o.value" @mousedown.prevent="choose(o)"
                    class="cursor-pointer px-3 py-2 text-sm"
                    :class="i === active ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800'">
                    <span class="block">{{ o.label }}</span>
                    <span v-if="o.description" class="block text-xs text-slate-400 dark:text-slate-500">{{ o.description }}</span>
                </li>
                <li v-if="!filtered.length" class="px-3 py-2 text-sm text-slate-400">-</li>
            </ul>
        </div>
    </div>
</template>
```

- [ ] **Step 3: TransactionForm — title, clearer picker, player card**

In `resources/js/Pages/Transactions/Partials/TransactionForm.vue`:

**(a)** Under the `useFinanceAccountLabel` import, add:

```js
import { useFormatMoney } from '@/Composables/useFormatMoney';
```

**(b)** In `useForm({`, insert as the first property:

```js
    // An untitled (legacy/automatic) transaction pre-fills its generated label.
    title: props.transaction?.title || props.transaction?.display_title || '',
```

**(c)** Replace the `playerOptions` line with:

```js
// Name first; membership ID, category and birth year tell two people with the
// same name apart. keywords lets the full name (father's name) match too.
const playerOptions = computed(() => props.players.map((p) => ({
    value: p.id,
    label: p.name,
    description: [p.membership_id, p.category, p.birth_year].filter(Boolean).join(' · '),
    keywords: p.fullname,
})));
const selectedPlayer = computed(() => props.players.find((p) => String(p.id) === String(form.related_entity_id)) || null);
const { formatMoney } = useFormatMoney();
```

**(d)** In the template, directly after the opening card tag, replace:

```html
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('type')" />
```

with:

```html
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 space-y-4">
            <div>
                <InputLabel :value="t('title')" />
                <TextInput v-model="form.title" class="mt-1 w-full" maxlength="150" required :placeholder="t('transaction_title_placeholder')" />
                <InputError :message="form.errors.title" class="mt-1" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('type')" />
```

**(e)** Replace the player block:

```html
            <div v-if="players.length">
                <InputLabel :value="t('player')" />
                <SearchableSelect v-model="form.related_entity_id" :options="playerOptions" :placeholder="t('search_player')" />
            </div>
```

with:

```html
            <div v-if="players.length">
                <InputLabel :value="t('player')" />
                <SearchableSelect v-model="form.related_entity_id" :options="playerOptions" :placeholder="t('search_player')" />
                <!-- Confirms the right person before saving: photo, IDs, category, debt. -->
                <div v-if="selectedPlayer" class="mt-2 flex items-center gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-950 dark:ring-slate-800">
                    <img v-if="selectedPlayer.picture_url" :src="selectedPlayer.picture_url" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover" />
                    <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-bold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ selectedPlayer.name.charAt(0) }}</span>
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="truncate font-semibold text-slate-900 dark:text-slate-100">{{ selectedPlayer.fullname || selectedPlayer.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <span class="font-mono">{{ selectedPlayer.membership_id }}</span>
                            <span v-if="selectedPlayer.category"> · {{ selectedPlayer.category }}</span>
                            <span v-if="selectedPlayer.birth_year"> · {{ t('born_in') }} {{ selectedPlayer.birth_year }}</span>
                        </p>
                        <p v-if="selectedPlayer.branches?.length" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ selectedPlayer.branches.join(', ') }}</p>
                    </div>
                    <div class="shrink-0 text-end text-xs">
                        <p class="text-slate-500 dark:text-slate-400">{{ t('outstanding_debt') }}</p>
                        <p class="font-semibold" :class="selectedPlayer.outstanding_debt > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ formatMoney(selectedPlayer.outstanding_debt) }}</p>
                        <a :href="route('players.show', selectedPlayer.id)" target="_blank" class="text-primary-600 hover:underline">{{ t('view_player') }} ↗</a>
                    </div>
                </div>
                <InputError :message="form.errors.related_entity_id" class="mt-1" />
            </div>
```

- [ ] **Step 4: Transactions list — title and player columns**

In `resources/js/Pages/Transactions/Index.vue`:

Replace the description header:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
```

with:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('title') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('player') }}</th>
```

Replace the description cell:

```html
                                <td class="max-w-xs truncate px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ tx.description || '-' }}</td>
```

with:

```html
                                <td class="max-w-xs px-4 py-3 text-sm">
                                    <Link :href="route('transactions.show', tx.id)" class="block truncate font-medium text-slate-900 hover:text-primary-600 dark:text-slate-100">{{ tx.display_title }}</Link>
                                    <span v-if="tx.description" class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tx.description }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <template v-if="tx.player_summary">
                                        <Link :href="route('players.show', tx.player_summary.id)" class="text-primary-600 hover:underline">{{ tx.player_summary.name }}</Link>
                                        <span class="block font-mono text-xs text-slate-400">{{ tx.player_summary.membership_id }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
```

Change the empty-row `colspan="8"` to `colspan="9"`.

- [ ] **Step 5: Transaction details — title and player**

In `resources/js/Pages/Transactions/Show.vue`, replace:

```html
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
```

with:

```html
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-5 border-b border-slate-100 pb-4 dark:border-slate-800">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ transaction.display_title }}</h2>
                    <Link v-if="transaction.player_summary" :href="route('players.show', transaction.player_summary.id)" class="mt-1 inline-flex items-center gap-2 text-sm text-primary-600 hover:underline">
                        {{ transaction.player_summary.name }}
                        <span class="font-mono text-xs text-slate-400">{{ transaction.player_summary.membership_id }}</span>
                    </Link>
                </div>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
```

- [ ] **Step 6: Player page transaction table**

In `resources/js/Pages/Players/Show.vue`, inside the Transaction history table:
- Replace the header `<th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>` with the same element showing `{{ t('title') }}`. It is the first `t('category')` header after `<!-- Transaction history -->`.
- Replace `<td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ tx.category }}</td>` with `<td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ tx.display_title }}</td>`.

- [ ] **Step 7: Build and check**

Run: `npm run build`. Expected: success.

Run: `npm run i18n:check`. Expected: `✓ …`.

Run: `php artisan test --filter=FlashTranslationTest`. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Components/SearchableSelect.vue resources/js/Pages/Transactions/Partials/TransactionForm.vue resources/js/Pages/Transactions/Index.vue resources/js/Pages/Transactions/Show.vue resources/js/Pages/Players/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(transactions): title field, identifiable player picker, title and player columns"
```

---

### Task 10: Assignments go to players only, every holder is visible, "Equipment out" page (backend)

**Files:**
- Modify: `app/Http/Requests/Equipment/RentEquipmentRequest.php`
- Modify: `app/Models/EquipmentItem.php` (add `openRentals()`)
- Modify: `app/Http/Controllers/EquipmentCatalogController.php` (`show` eager-load)
- Modify: `app/Http/Controllers/EquipmentItemController.php` (`history`: `rented_to.type`)
- Modify: `app/Services/Dashboard/OverviewStats.php` (feed rentals carry their type)
- Create: `app/Http/Controllers/EquipmentOutController.php`
- Modify: `routes/web.php`, `config/permissions.php`, `lang/ar.json`, `lang/fr.json`
- Test: `tests/Feature/EquipmentAssignmentTest.php`
- Test (append a method): `tests/Feature/Dashboard/ActivityFeedLabelTest.php`

**Interfaces:**
- Consumes: `Player::scopeSearch` (Task 8).
- Produces: `catalog.items[*].open_rentals[*]`. Each entry carries `id, type, quantity, returned_quantity, recipient_name` plus the rest of the rental model.
- Produces: `item.rented_to.type` on the history page.
- Produces: route `equipment.out` (`GET /equipment/out`), requiring equipment view. Its props:

```
rentals: paginator of { id, type, recipient_name, player_id, membership_id,
    external_phone, catalog {id, name}|null, item_label, quantity,
    returned_quantity, outstanding_quantity, checkout_date, due_date, is_overdue }
counts: { rental, assignment }
filters: { type, search }
```

- Produces: dashboard feed entries of type `'assignment'`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/EquipmentAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function lot(int $quantity = 10): EquipmentItem
    {
        $catalog = EquipmentCatalog::create(['name' => 'Training bibs', 'category' => 'Apparel']);

        return EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => $quantity]);
    }

    private function player(string $membership = '202600001'): Player
    {
        return Player::create(['firstname' => 'Ali', 'lastname' => 'B', 'membership_id' => $membership, 'join_year' => 2026]);
    }

    private function open(EquipmentItem $lot, array $attributes): EquipmentRental
    {
        return EquipmentRental::create(array_merge([
            'equipment_item_id' => $lot->id,
            'checkout_date' => now(),
            'type' => 'rental',
            'quantity' => 1,
        ], $attributes));
    }

    #[Test]
    public function equipment_cannot_be_assigned_to_an_external_person(): void
    {
        $this->actingAs($this->admin())->post(route('equipment.items.rent'), [
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => 'External',
            'external_name' => 'Karim Visitor',
            'type' => 'assignment',
            'quantity' => 1,
        ])->assertSessionHasErrors('rentable_type');

        $this->assertSame(0, EquipmentRental::count());
    }

    #[Test]
    public function a_player_can_be_assigned_work_equipment_without_a_due_date(): void
    {
        $player = $this->player();

        $this->actingAs($this->admin())->post(route('equipment.items.rent'), [
            'equipment_item_id' => $this->lot()->id,
            'rentable_type' => 'Player',
            'rentable_id' => $player->id,
            'type' => 'assignment',
            'quantity' => 2,
            'expected_days' => 10,
        ])->assertSessionHasNoErrors();

        $rental = EquipmentRental::firstOrFail();
        $this->assertSame('assignment', $rental->type);
        $this->assertNull($rental->due_date);
    }

    #[Test]
    public function the_catalog_page_lists_every_holder_of_a_lot(): void
    {
        $lot = $this->lot();
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player()->id, 'quantity' => 3, 'type' => 'assignment']);
        $this->open($lot, ['external_name' => 'Karim Visitor', 'quantity' => 2]);

        $props = $this->actingAs($this->admin())->get(route('equipment.catalogs.show', $lot->catalog_id))
            ->assertOk()->viewData('page')['props'];

        $holders = collect($props['catalog']['items'][0]['open_rentals']);
        $this->assertSame(['Ali B', 'Karim Visitor'], $holders->pluck('recipient_name')->all());
        $this->assertSame(['assignment', 'rental'], $holders->pluck('type')->all());
    }

    #[Test]
    public function the_history_page_says_whether_the_holder_was_assigned_or_lent(): void
    {
        $lot = $this->lot(1);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player()->id, 'type' => 'assignment']);

        $props = $this->actingAs($this->admin())->get(route('equipment.items.history', $lot))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('assignment', $props['item']['rented_to']['type']);
    }

    #[Test]
    public function equipment_out_splits_rentals_from_assignments_and_hides_returned_items(): void
    {
        $lot = $this->lot();
        $player = $this->player();
        $this->open($lot, ['external_name' => 'Karim Visitor']);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $player->id, 'type' => 'assignment']);
        $this->open($lot, ['external_name' => 'Returned Person', 'return_date' => now(), 'returned_quantity' => 1]);

        $admin = $this->admin();
        $rentals = $this->actingAs($admin)->get(route('equipment.out'))->assertOk()->viewData('page')['props'];
        $this->assertSame(['rental' => 1, 'assignment' => 1], $rentals['counts']);
        $this->assertSame(['Karim Visitor'], collect($rentals['rentals']['data'])->pluck('recipient_name')->all());

        $assignments = $this->actingAs($admin)->get(route('equipment.out', ['type' => 'assignment']))->viewData('page')['props'];
        $this->assertSame([$player->id], collect($assignments['rentals']['data'])->pluck('player_id')->all());
    }

    #[Test]
    public function equipment_out_searches_holders_by_name_or_membership_id(): void
    {
        $lot = $this->lot();
        $this->open($lot, ['external_name' => 'Karim Visitor']);
        $this->open($lot, ['rentable_type' => Player::class, 'rentable_id' => $this->player('202600042')->id]);

        $admin = $this->admin();
        $byName = $this->actingAs($admin)->get(route('equipment.out', ['search' => 'karim']))->viewData('page')['props'];
        $this->assertSame(['Karim Visitor'], collect($byName['rentals']['data'])->pluck('recipient_name')->all());

        $byId = $this->actingAs($admin)->get(route('equipment.out', ['search' => '202600042']))->viewData('page')['props'];
        $this->assertSame(['202600042'], collect($byId['rentals']['data'])->pluck('membership_id')->all());
    }

    #[Test]
    public function viewing_equipment_out_needs_only_equipment_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['equipment' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->get(route('equipment.out'))->assertOk();
    }
}
```

Append this method to `tests/Feature/Dashboard/ActivityFeedLabelTest.php`. The class imports `EquipmentCatalog`, `EquipmentItem` and `EquipmentRental` from `App\Models`, so add those three `use` lines too.

```php
    #[Test]
    public function an_assignment_is_labelled_as_an_assignment_in_the_feed(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Coach radio', 'category' => 'Electronics']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 1]);
        EquipmentRental::create([
            'equipment_item_id' => $item->id, 'external_name' => 'n/a', 'type' => 'assignment',
            'quantity' => 1, 'checkout_date' => now(),
        ]);

        $this->assertSame('assignment', $this->activity()[0]['type']);
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter="EquipmentAssignmentTest|ActivityFeedLabelTest"`

Expected: FAIL. The external assignment is accepted, `open_rentals` is missing, and `Route [equipment.out] not defined`.

- [ ] **Step 3: Assignments require a player**

In `app/Http/Requests/Equipment/RentEquipmentRequest.php`, add `use Closure;` under the namespace imports. Then replace the `rentable_type` rule with:

```php
            // A team player, or someone from outside the club (free text).
            // Work equipment is only ever assigned to a player; outsiders can only rent.
            'rentable_type' => ['required', 'string', 'in:Player,External', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('type') === 'assignment' && $value !== 'Player') {
                    $fail(__('Equipment can only be assigned to a player.'));
                }
            }],
```

Add the sentence to both PHP catalogs, in alphabetical position (between `"Donations"` and `"Expense"`):
- `lang/fr.json`: `"Equipment can only be assigned to a player.": "Le matériel ne peut être affecté qu'à un joueur.",`
- `lang/ar.json`: `"Equipment can only be assigned to a player.": "لا يمكن تسليم المعدات للعمل إلا للاعب.",`

- [ ] **Step 4: Every open rental of a lot**

In `app/Models/EquipmentItem.php`, add after `activeRental()`:

```php
    /** Every rental of this lot still out — a lot can be out with several people at once. */
    public function openRentals(): HasMany
    {
        return $this->hasMany(EquipmentRental::class)->whereNull('return_date')->orderBy('checkout_date')->orderBy('id');
    }
```

In `app/Http/Controllers/EquipmentCatalogController.php` `show()`, replace:

```php
        $catalog->load(['items.activeRental.rentable', 'items.rentals', 'items.branches']);
```

with:

```php
        // openRentals (with holders) so every person a lot is out with is listed,
        // not just the latest one.
        $catalog->load(['items.openRentals.rentable', 'items.rentals', 'items.branches']);
```

In `app/Http/Controllers/EquipmentItemController.php` `history()`, inside the `'rented_to' => ... [` array, add after `'phone' => $active->external_phone,`:

```php
                    'type' => $active->type,
```

In `app/Services/Dashboard/OverviewStats.php` `activity()`, in the rentals query `get([...])`, add `'equipment_rentals.type as type',`. In its map, replace `'type' => 'rental',` with:

```php
                'type' => $r->type === 'assignment' ? 'assignment' : 'rental',
```

- [ ] **Step 5: Equipment out page (controller, route, permission)**

Create `app/Http/Controllers/EquipmentOutController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\EquipmentRental;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything currently out with someone, across every catalog, split into
 * temporary rentals and work assignments — the one place to see who holds
 * what and take it back.
 */
class EquipmentOutController extends Controller
{
    private const TYPES = ['rental', 'assignment'];

    public function __invoke(Request $request): Response
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'rental';
        $search = trim((string) $request->query('search', ''));

        $open = EquipmentRental::query()->whereNull('return_date');

        $counts = (clone $open)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $rentals = (clone $open)
            ->where('type', $type)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('external_name', 'like', "%{$search}%")
                ->orWhereHasMorph('rentable', [Player::class], fn (Builder $p) => $p->search($search))))
            ->with(['equipmentItem.catalog:id,name', 'rentable'])
            ->orderBy('checkout_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (EquipmentRental $rental) => [
                'id' => $rental->id,
                'type' => $rental->type,
                'recipient_name' => $rental->recipient_name,
                'player_id' => $rental->rentable instanceof Player ? $rental->rentable->id : null,
                'membership_id' => $rental->rentable instanceof Player ? $rental->rentable->membership_id : null,
                'external_phone' => $rental->external_phone,
                'catalog' => $rental->equipmentItem?->catalog
                    ? ['id' => $rental->equipmentItem->catalog->id, 'name' => $rental->equipmentItem->catalog->name]
                    : null,
                'item_label' => $rental->equipmentItem?->unique_identifier ?: $rental->equipmentItem?->designation,
                'quantity' => $rental->quantity,
                'returned_quantity' => $rental->returned_quantity,
                'outstanding_quantity' => $rental->outstanding_quantity,
                'checkout_date' => $rental->checkout_date?->toDateString(),
                'due_date' => $rental->due_date?->toDateString(),
                'is_overdue' => $rental->is_overdue,
            ]);

        return Inertia::render('Equipment/Out', [
            'rentals' => $rentals,
            'counts' => [
                'rental' => (int) ($counts['rental'] ?? 0),
                'assignment' => (int) ($counts['assignment'] ?? 0),
            ],
            'filters' => ['type' => $type, 'search' => $search],
        ]);
    }
}
```

In `routes/web.php`, add `use App\Http\Controllers\EquipmentOutController;` with the other controller imports. Directly after the `equipment.inventory` route, add:

```php
    Route::get('/equipment/out', EquipmentOutController::class)->name('equipment.out');
```

In `config/permissions.php` `overrides`, add after `'inventory.report' => ['inventory', 'view'],`:

```php
        // "out" is not a view verb, so without this the list would need edit rights.
        'equipment.out' => ['equipment', 'view'],
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="EquipmentAssignmentTest|ActivityFeedLabelTest|EquipmentLotJourneyTest|EquipmentLotRentalTest|EquipmentInventoryReportTest|EquipmentItemManagementTest|EquipmentBranchTaggingTest|AuditFixesTest|OverviewStatsTest"`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Http/Requests/Equipment/RentEquipmentRequest.php app/Models/EquipmentItem.php app/Http/Controllers/EquipmentCatalogController.php app/Http/Controllers/EquipmentItemController.php app/Services/Dashboard/OverviewStats.php app/Http/Controllers/EquipmentOutController.php routes/web.php config/permissions.php tests/Feature/EquipmentAssignmentTest.php tests/Feature/Dashboard/ActivityFeedLabelTest.php
git add app/Http/Requests/Equipment/RentEquipmentRequest.php app/Models/EquipmentItem.php app/Http/Controllers/EquipmentCatalogController.php app/Http/Controllers/EquipmentItemController.php app/Services/Dashboard/OverviewStats.php app/Http/Controllers/EquipmentOutController.php routes/web.php config/permissions.php lang/ar.json lang/fr.json tests/Feature/EquipmentAssignmentTest.php tests/Feature/Dashboard/ActivityFeedLabelTest.php
git commit -m "feat(equipment): assignments for players only, every holder listed, equipment-out page"
```

---

### Task 11: Rental vs assignment on screen

**Files:**
- Create: `resources/js/Components/RentalTypeBadge.vue`
- Create: `resources/js/Components/ReturnRentalModal.vue`
- Create: `resources/js/Pages/Equipment/Out.vue`
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue` (**has a BOM — targeted edits only**)
- Modify: `resources/js/Pages/Equipment/History.vue`
- Modify: `resources/js/Pages/Players/Show.vue` (**has a BOM — targeted edits only**)
- Modify: `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes: the props and route from Task 10, and `player.equipment_rentals` (already eager-loaded by `PlayerController::show`).
- Produces:
  - `<RentalTypeBadge :type="'rental'|'assignment'" />`;
  - `<ReturnRentalModal :rental="{id, quantity, returned_quantity}|null" :title="string" @close />`. It renders nothing while `rental` is null.

- [ ] **Step 1: UI keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "equipment.assigned_badge": { "en": "Assigned (work)", "fr": "Affecté (travail)", "ar": "مُسلَّم للعمل" },
    "equipment.assignment_player_only": { "en": "Assignments are for players only.", "fr": "Les affectations sont réservées aux joueurs.", "ar": "التسليم للعمل خاص باللاعبين فقط." },
    "equipment.holder": { "en": "Held by", "fr": "Détenu par", "ar": "بحوزة" },
    "equipment.rentals_tab": { "en": "Rentals", "fr": "Prêts", "ar": "الإعارات" },
    "equipment.assignments_tab": { "en": "Assignments", "fr": "Affectations", "ar": "التسليمات للعمل" },
    "equipment.out_since": { "en": "Since", "fr": "Depuis", "ar": "منذ" },
    "equipment.outstanding": { "en": "Still out", "fr": "Encore sorti", "ar": "لم يُرجَع بعد" },
    "equipment.none_out": { "en": "Nothing is out right now.", "fr": "Aucun matériel n'est sorti pour le moment.", "ar": "لا توجد معدات مُخرَجة حاليًا." },
    "equipment.past_items": { "en": "Returned", "fr": "Rendu", "ar": "أُرجِع" },
    "equipment.returned_on": { "en": "Returned on", "fr": "Rendu le", "ar": "أُرجِع في" },
    "equipment_out": { "en": "Equipment out", "fr": "Matériel sorti", "ar": "المعدات المُخرَجة" },
    "assigned_to": { "en": "Assigned to", "fr": "Affecté à", "ar": "مُسلَّم إلى" },
    "dashboard.activity_assignment": { "en": "Equipment assigned", "fr": "Matériel affecté", "ar": "تسليم معدات للعمل" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 2: The two shared components**

Create `resources/js/Components/RentalTypeBadge.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';

/**
 * Tells work equipment apart from a loan at a glance, everywhere a rental is
 * listed. An assignment is kit given to a player to work with (open-ended);
 * a rental is a temporary loan that comes back by a date.
 */
const props = defineProps({ type: { type: String, default: 'rental' } });
const { t } = useI18n();
const isAssignment = computed(() => props.type === 'assignment');
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
        :class="isAssignment
            ? 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/25'
            : 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/25'">
        <Icon :name="isAssignment ? 'wrench' : 'refresh'" class="text-sm" />
        {{ isAssignment ? t('equipment.assigned_badge') : t('rented') }}
    </span>
</template>
```

Create `resources/js/Components/ReturnRentalModal.vue`:

```vue
<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

/**
 * Take back one open rental or assignment, in full or in part. Shared by the
 * catalog page and the equipment-out page so both return the same way.
 * Renders nothing while `rental` is null.
 */
const props = defineProps({
    rental: { type: Object, default: null }, // { id, quantity, returned_quantity }
    title: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const CONDITIONS = ['New', 'Good', 'Fair', 'Poor', 'Damaged'];

const form = useForm({
    quantity: 1,
    condition: 'Good',
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
});

const outstanding = (r) => (r?.quantity ?? 1) - (r?.returned_quantity ?? 0);

// Default to bringing back everything still out, which is the common case.
watch(() => props.rental, (rental) => {
    if (!rental) return;
    form.reset();
    form.clearErrors();
    form.quantity = outstanding(rental);
}, { immediate: true });

function submit() {
    form.post(route('equipment.rentals.return', props.rental.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <div v-if="rental" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="emit('close')">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ t('return') }}<span v-if="title"> — {{ title }}</span>
            </h3>
            <form @submit.prevent="submit" class="mt-4 space-y-3">
                <!-- Units can come back in instalments; the rental stays open until all are in. -->
                <div v-if="(rental.quantity ?? 1) > 1">
                    <InputLabel :value="t('equipment.quantity')" />
                    <TextInput v-model="form.quantity" type="number" min="1" :max="outstanding(rental)" class="mt-1 w-full" required />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ rental.returned_quantity ?? 0 }} / {{ rental.quantity }} {{ t('equipment.returned_of') }}
                    </p>
                    <InputError :message="form.errors.quantity" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('return_date')" />
                    <TextInput v-model="form.return_date" type="date" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('condition')" />
                    <div class="mt-2 grid grid-cols-5 gap-2">
                        <button v-for="c in CONDITIONS" :key="c" type="button" @click="form.condition = c"
                            :class="form.condition === c ? 'bg-primary-100 dark:bg-primary-500/25 border-primary-500 text-primary-800 dark:text-primary-100' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300'"
                            class="rounded-lg border px-2 py-2 text-xs font-medium text-center transition-colors">{{ t(c.toLowerCase()) }}</button>
                    </div>
                </div>
                <div>
                    <InputLabel :value="t('notes')" />
                    <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="emit('close')" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <PrimaryButton :disabled="form.processing">{{ t('return') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Catalog page — all holders, players-only assignment, shared return**

In `resources/js/Pages/Equipment/Catalog/Show.vue`, apply these targeted edits in order.

**(a) Imports.** After the line `import SearchableSelect from '@/Components/SearchableSelect.vue';`, add:

```js
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
import ReturnRentalModal from '@/Components/ReturnRentalModal.vue';
```

**(b) `isDeletable`.** Replace:

```js
const isDeletable = (item) => item.status !== 'Rented' && !item.active_rental;
```

with:

```js
const isDeletable = (item) => item.status !== 'Rented' && !item.open_rentals?.length;
```

**(c)** Delete the line `const showReturnModal = ref(false);`.

**(d)** Delete this whole block:

```js
const returnForm = useForm({
    quantity: 1,
    condition: 'Good',
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
});
```

**(e) Watchers.** Replace:

```js
// An assignment is open-ended; the backend discards a due date on one, so
// don't leave a stale value sitting in the form.
watch(() => rentForm.type, (type) => {
    if (type === 'assignment') rentForm.due_date = '';
});

// Switching between a player and a staff member invalidates the chosen id.
```

with:

```js
// Work equipment is only ever assigned to a player; the server enforces the
// same rule, this just keeps the form from offering the invalid combination.
watch(() => rentForm.type, (type) => {
    if (type === 'assignment') rentForm.rentable_type = 'Player';
});

// Switching between a player and an external person invalidates the chosen id.
```

**(f) Return flow.** Replace:

```js
function openReturn(item) {
    selectedItem.value = item;
    // Default to bringing back everything still out, which is the common case.
    returnForm.quantity = item.active_rental?.quantity
        ? item.active_rental.quantity - (item.active_rental.returned_quantity ?? 0)
        : 1;
    returnForm.clearErrors();
    showReturnModal.value = true;
}

function doReturn() {
    const rental = selectedItem.value?.active_rental;
    if (!rental) return;
    returnForm.post(route('equipment.rentals.return', rental.id), {
        onSuccess: () => {
            showReturnModal.value = false;
        },
    });
}
```

with:

```js
// One lot can be out with several people; Return acts on the chosen rental.
const returningRental = ref(null);
function openReturn(item, rental) {
    selectedItem.value = item;
    returningRental.value = rental;
}
const returningLabel = computed(() => selectedItem.value?.unique_identifier || selectedItem.value?.designation || props.catalog.name);
```

**(g) Table header.** Replace:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('rented_to') }}</th>
```

with:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.holder') }}</th>
```

**(h) Holder cell.** Replace:

```html
                                    <span v-if="item.active_rental">
                                        {{ item.active_rental.recipient_name }}
                                        <span v-if="(item.active_rental.quantity ?? 1) > 1" class="text-xs text-slate-400">
                                            ({{ item.active_rental.quantity - (item.active_rental.returned_quantity ?? 0) }})
                                        </span>
                                    </span>
                                    <span v-else>-</span>
```

with:

```html
                                    <!-- Every open rental of the lot, each with its own Return. -->
                                    <ul v-if="item.open_rentals?.length" class="space-y-1">
                                        <li v-for="r in item.open_rentals" :key="r.id" class="flex flex-wrap items-center gap-1.5">
                                            <RentalTypeBadge :type="r.type" />
                                            <span>{{ r.recipient_name || '—' }}</span>
                                            <span v-if="(r.quantity ?? 1) > 1" class="text-xs text-slate-400">({{ r.quantity - (r.returned_quantity ?? 0) }})</span>
                                            <button @click="openReturn(item, r)" class="text-xs font-medium text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
                                        </li>
                                    </ul>
                                    <span v-else>-</span>
```

**(i) Actions.** Delete the line:

```html
                                        <button v-if="item.active_rental" @click="openReturn(item)" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
```

Then replace `<button v-if="!item.active_rental" @click="deleteItemId = item.id"` with `<button v-if="!item.open_rentals?.length" @click="deleteItemId = item.id"`.

**(j) Rent modal recipient toggle.** Replace:

```html
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="rentForm.rentable_type = 'Player'"
```

with:

```html
                        <div class="grid gap-2" :class="rentForm.type === 'rental' ? 'grid-cols-2' : 'grid-cols-1'">
                            <button type="button" @click="rentForm.rentable_type = 'Player'"
```

Replace:

```html
                            <button type="button" @click="rentForm.rentable_type = 'External'"
```

with:

```html
                            <button v-if="rentForm.type === 'rental'" type="button" @click="rentForm.rentable_type = 'External'"
```

After the closing `</div>` of that toggle grid, which sits directly before `<div v-if="rentForm.rentable_type === 'Player'">`, insert:

```html
                        <p v-if="rentForm.type === 'assignment'" class="text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.assignment_player_only') }}</p>
                        <InputError :message="rentForm.errors.rentable_type" class="mt-1" />
```

**(k) Return modal.** Replace the whole block from `            <!-- Return Modal -->` down to its closing `            </div>`, which sits right before `        </Teleport>`, with:

```html
            <!-- Return Modal -->
            <ReturnRentalModal :rental="returningRental" :title="returningLabel" @close="returningRental = null" />
```

Check: `grep -n "returnForm\|showReturnModal\|active_rental" resources/js/Pages/Equipment/Catalog/Show.vue` prints nothing. Also check that the first bytes are still the BOM: `head -c 3 resources/js/Pages/Equipment/Catalog/Show.vue | od -An -tx1` prints `ef bb bf`.

- [ ] **Step 4: History page badge**

In `resources/js/Pages/Equipment/History.vue`, add `import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';` to the imports. Then replace:

```html
                    <span class="font-medium text-amber-900 dark:text-amber-200">{{ t('rented_to') }}:</span>
```

with:

```html
                    <RentalTypeBadge :type="item.rented_to.type" class="me-1" />
                    <span class="font-medium text-amber-900 dark:text-amber-200">{{ item.rented_to.type === 'assignment' ? t('assigned_to') : t('rented_to') }}:</span>
```

- [ ] **Step 5: Equipment out page**

Create `resources/js/Pages/Equipment/Out.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
import ReturnRentalModal from '@/Components/ReturnRentalModal.vue';
import SearchInput from '@/Components/SearchInput.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useListFilters } from '@/Composables/useListFilters';

const props = defineProps({
    rentals: { type: Object, required: true },
    counts: { type: Object, default: () => ({ rental: 0, assignment: 0 }) },
    filters: { type: Object, default: () => ({}) },
});
const { t } = useI18n();
const { can } = useCan();

const type = ref(props.filters?.type || 'rental');
const search = ref(props.filters?.search || '');
const { loading } = useListFilters('equipment.out', () => ({ type: type.value, search: search.value }), {
    only: ['rentals', 'counts', 'filters'],
});

const tabs = [
    { key: 'rental', label: 'equipment.rentals_tab' },
    { key: 'assignment', label: 'equipment.assignments_tab' },
];

const returning = ref(null);
</script>

<template>
    <Head :title="t('equipment_out')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('equipment.catalogs.index')" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300"><Icon name="back" /></Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('equipment_out') }}</h1>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-1.5">
                    <button v-for="tab in tabs" :key="tab.key" type="button" @click="type = tab.key"
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
                        :class="type === tab.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800'">
                        {{ t(tab.label) }}
                        <span class="rounded-full bg-black/10 px-1.5 text-xs dark:bg-white/10">{{ counts[tab.key] ?? 0 }}</span>
                    </button>
                </div>
                <div class="w-full sm:w-72"><SearchInput v-model="search" :loading="loading" :placeholder="t('search')" /></div>
            </div>

            <div :class="{ 'opacity-60': loading }" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.holder') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.outstanding') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.out_since') }}</th>
                                <th v-if="type === 'rental'" class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('due_date') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="r in rentals.data" :key="r.id">
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center gap-2">
                                        <RentalTypeBadge :type="r.type" />
                                        <Link v-if="r.player_id" :href="route('players.show', r.player_id)" class="font-medium text-primary-600 hover:underline">{{ r.recipient_name }}</Link>
                                        <span v-else class="font-medium text-slate-800 dark:text-slate-200">{{ r.recipient_name || '—' }}</span>
                                    </div>
                                    <span v-if="r.membership_id" class="font-mono text-xs text-slate-400">{{ r.membership_id }}</span>
                                    <span v-else-if="r.external_phone" class="text-xs text-slate-400">{{ r.external_phone }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <Link v-if="r.catalog" :href="route('equipment.catalogs.show', r.catalog.id)" class="text-slate-800 hover:text-primary-600 dark:text-slate-200">{{ r.catalog.name }}</Link>
                                    <span v-if="r.item_label" class="block font-mono text-xs text-slate-400">{{ r.item_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ r.outstanding_quantity }}<span v-if="r.quantity > 1" class="font-normal text-slate-400"> / {{ r.quantity }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ r.checkout_date }}</td>
                                <td v-if="type === 'rental'" class="px-4 py-3 text-sm">
                                    <span class="text-slate-600 dark:text-slate-300">{{ r.due_date || '—' }}</span>
                                    <Badge v-if="r.is_overdue" :label="t('overdue')" color="rose" class="ms-2" />
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <button v-if="can('equipment', 'edit')" type="button" @click="returning = r" class="text-sm font-medium text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!rentals.data?.length">
                                <td :colspan="type === 'rental' ? 6 : 5" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('equipment.none_out') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4"><Pagination :links="rentals" /></div>
            </div>
        </div>

        <ReturnRentalModal :rental="returning" :title="returning?.catalog?.name || ''" @close="returning = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Sidebar link and dashboard icon**

In `resources/js/Layouts/AuthenticatedLayout.vue`, in the `nav_equipment` items, add this directly after the `t('equipments')` item:

```js
            { label: t('equipment_out'), href: '/equipment/out', icon: 'box', prefix: '/equipment/out', module: 'equipment' },
```

In `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`, replace:

```js
const activityIcon = { transaction: 'money', registration: 'user', rental: 'box' };
```

with:

```js
const activityIcon = { transaction: 'money', registration: 'user', rental: 'box', assignment: 'wrench' };
```

- [ ] **Step 7: Player page — equipment section**

In `resources/js/Pages/Players/Show.vue`:

**(a)** After `import SecondaryButton from '@/Components/SecondaryButton.vue';`, add:

```js
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
```

**(b)** After the line `const { accountLabel } = useFinanceAccountLabel();`, add:

```js
// Equipment the player holds or held. Assignments (work kit) and rentals are
// listed apart so a loan is never mistaken for kit given to work with.
const equipmentRentals = computed(() => [...(props.player?.equipment_rentals ?? [])]
    .sort((a, b) => String(b.checkout_date).localeCompare(String(a.checkout_date))));
const equipmentGroups = computed(() => [
    { key: 'assigned', label: t('equipment.assignments_tab'), rows: equipmentRentals.value.filter((r) => !r.return_date && r.type === 'assignment') },
    { key: 'rented', label: t('equipment.rentals_tab'), rows: equipmentRentals.value.filter((r) => !r.return_date && r.type !== 'assignment') },
    { key: 'returned', label: t('equipment.past_items'), rows: equipmentRentals.value.filter((r) => r.return_date) },
].filter((group) => group.rows.length));
function isOverdueRental(r) {
    return r.type !== 'assignment' && !r.return_date && r.due_date
        && String(r.due_date).slice(0, 10) < new Date().toISOString().slice(0, 10);
}
```

**(c)** Replace the end of the Transaction history card:

```html
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment modal -->
```

with:

```html
                    </table>
                </div>
            </div>

            <!-- Equipment: work assignments and rentals, current then returned -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('equipment') }}</h3>
                </div>
                <div v-if="!equipmentRentals.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>
                <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                    <section v-for="group in equipmentGroups" :key="group.key" class="px-5 py-4">
                        <p class="mb-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ group.label }} ({{ group.rows.length }})</p>
                        <ul class="space-y-2">
                            <li v-for="r in group.rows" :key="r.id" class="flex flex-wrap items-center gap-2 text-sm">
                                <RentalTypeBadge :type="r.type" />
                                <Link v-if="r.equipment_item?.catalog" :href="route('equipment.catalogs.show', r.equipment_item.catalog.id)" class="font-medium text-slate-800 hover:text-primary-600 dark:text-slate-200">{{ r.equipment_item.catalog.name }}</Link>
                                <span v-if="r.equipment_item?.unique_identifier" class="font-mono text-xs text-slate-400">{{ r.equipment_item.unique_identifier }}</span>
                                <span v-if="(r.quantity ?? 1) > 1" class="text-xs text-slate-500">× {{ r.return_date ? r.quantity : r.quantity - (r.returned_quantity ?? 0) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ t('equipment.out_since') }} {{ formatDate(r.checkout_date) }}</span>
                                <span v-if="r.return_date" class="text-xs text-slate-500 dark:text-slate-400">· {{ t('equipment.returned_on') }} {{ formatDate(r.return_date) }}</span>
                                <Badge v-else-if="isOverdueRental(r)" :label="t('overdue')" color="rose" />
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <!-- Payment modal -->
```

Check that the BOM is intact: `head -c 3 resources/js/Pages/Players/Show.vue | od -An -tx1` prints `ef bb bf`.

- [ ] **Step 8: Build and check**

Run: `npm run build`. Expected: success.

Run: `npm run i18n:check`. Expected: `✓ …`.

Run: `php artisan test --filter="FlashTranslationTest|EquipmentAssignmentTest"`. Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/RentalTypeBadge.vue resources/js/Components/ReturnRentalModal.vue resources/js/Pages/Equipment/Out.vue resources/js/Pages/Equipment/Catalog/Show.vue resources/js/Pages/Equipment/History.vue resources/js/Pages/Players/Show.vue resources/js/Pages/Dashboard/Partials/OverviewTab.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(equipment): rented vs assigned badges, every holder returnable, equipment-out page"
```

---

### Task 12: Full verification and manual check

**Files:** none new. Fix whatever this task uncovers, in the file where it lives, and commit the fix separately.

- [ ] **Step 1: Whole suite and checks**

Run each of the following:

| Command | Expected |
|---|---|
| `composer test` | every test passes. Record the count. |
| `npm run i18n:check` | `✓ …` |
| `npm run build` | success |
| `vendor/bin/pint --test app tests database routes config` | no changes needed. If it reports files, run `vendor/bin/pint` on exactly those files and commit them as `style: pint`. |
| `git status --short` | only `?? .claude/`. No `i18n-keys.tmp.json` left over. |

- [ ] **Step 2: Migrate the dev database**

Run: `php artisan migrate`

Expected: migrations `2026_09_22_100001`, `100002` and `100003` run.

- [ ] **Step 3: Manual check in the browser**

Run `php artisan serve` together with `npm run dev`. Log in as admin. Switch the language to **FR**, then **AR**, and walk through the list below in each. Every label must be translated, and the Arabic layout must stay right-to-left.

**1. Board → Members → Edit term**
- Both dates open filled.
- Changing the end date to before the start date shows a red error under "End".
- A valid change saves, and the term bar shows the new dates.

**2. Board → Tasks → edit a task with a due date**
- The date opens filled.

**3. Board → a scheduled meeting → Cancel meeting**
- An empty reason shows an error.
- With a reason, the page shows the grey "Cancelled" banner with date, name and reason. Every field is locked, and there is no upload, save or add-task control.
- Meetings list: the meeting is muted and struck through. The **Cancelled** filter shows only it.
- Board overview: the meeting total no longer counts it.

**4. Transactions → Add**
- Title is required.
- Typing `benali amine` or a membership ID in the player field finds the player, with the second line showing ID · category · year.
- Picking a player shows the card (photo or initial, IDs, debt, link).
- Saving works. The list shows the Title and Player columns, and the Status badge is translated.
- Searching the list by player name finds the payment.

**5. Players → a player with payments**
- The transaction table shows titles (e.g. `Don · Amine Benali`) and a translated payment method.
- The Equipment section groups items as Assignments / Rentals / Returned, with badges.

**6. Transaction details and receipt PDF**
- The details page shows the title block and the player link.
- The receipt has a Title row, and its category and status are translated.

**7. Equipment → a count-tracked catalog**
- Rent 3 to a player as **Assignment**. The External button disappears, and a hint says assignments are for players only.
- Rent 2 to an external person as **Rental**.
- The Holder cell lists both people with blue and amber badges and a Return button each. Returning one leaves the other.

**8. Sidebar → Equipment out**
- The tabs show counts. The Assignments tab has no due-date column.
- Search by name or membership ID works. Return works from here.

**9. Dashboard**
- The recent-activity feed shows the transaction title and marks an assignment with the wrench icon.

- [ ] **Step 4: Deploy notes to carry forward (no code)**

Put these three points in the P1 PR description:
- Existing databases need `php artisan migrate`.
- Desktop installs get it through migrate-on-boot.
- Before the next desktop build, refresh `storage/app/seed/database.sqlite` from the migrated dev database (see the NativePHP build notes).

---

## Spec coverage check

| Spec item (P1) | Task |
|---|---|
| #9 cast `date:Y-m-d`, slice, modal errors, `clearErrors`, `Tasks.vue` due date, tests | 1 |
| #7 `useStatusLabel` + raw spots + i18n-check guard | 3 |
| #7 `player_statuses.code` + `RegisterPlayerService` by code | 2 |
| #8 no delete; cancel columns; scheduled-only; reason 3–500; read-only; dropdown without cancelled; legacy backfill; stats; filter; ConfirmModal | 4, 5 |
| #3 `title` column, required in form, generated label via `TransactionTitle` + `UiLang`, list/details/player page/receipt/feed/export/search/import | 6, 7, 9 |
| #4 `exists` validation, payload, two-line options, player card, list/details player column | 7, 8, 9 |
| #1 players-only assignment, `RentalTypeBadge` everywhere, multi-holder fix, Equipment out page, player page section, stale watcher/comment clean-up | 10, 11 |
