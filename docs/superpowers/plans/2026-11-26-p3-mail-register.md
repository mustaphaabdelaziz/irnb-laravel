# P3 — Mail Register Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the club's mail register («سجل البريد» / registre du courrier) under a new **Secretariat** (الأمانة) menu: outgoing and incoming entries, each with its own yearly number (`2026/01`, incoming with a localized mark), cancel-never-delete, one private scan per entry, an outgoing letter printed on the letterhead with President + Secretary General signatures, a "Register in the mail register" tick box on the absence-letter / match-convocation / purchase-order prints (reprints reuse the reference, which every print then shows), and the import of the 141 old Access letters.

**Architecture:** New `mail_entries` table and `MailEntry` model; numbers from the existing `YearlySequence`, which gains an optional `$where` filter so the mail sequence counts per direction. `App\Services\Secretariat\MailRegister` owns every write (create / update / cancel / register-from-a-print / find), each in one DB transaction with an atomic conditional claim like `PurchaseOrderService`; `MailEntryException` renders refusals as flash errors. One active entry per printed document is enforced portably by a nullable unique `source_lock` column (set while active, cleared on cancel). `App\Services\Secretariat\MailSources` turns a document (player + period + branch, game, purchase order) into its source key and default correspondent / subject, checks the rights to read it, and describes an entry's source for the pages. Thin controllers: `MailEntryController` (register pages, scan), `MailEntryPrintController` (letter PDF), `MailSourceController` (JSON lookup + register for the print dialog). Vue: `Pages/Mail/*` and one `MailPrintButton` component wired into the three existing print buttons. New role module `mail` in a new `secretariat` group.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), mPDF (`App\Services\Pdf\PdfService`), PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), SQLite (dev/desktop) + MySQL compatible SQL.

Spec: `docs/superpowers/specs/2026-11-25-access-legacy-documents-design.md` — sections "Owner decisions" (every mail row), "Shared rules", "P3 — Mail register" (owner-approved 2026-11-26), "Import of old Access data", "Testing". The spec is the source of truth. P1 / P2 code on `main` (`PaymentOrder*`, `PurchaseOrder*`, `LegacyAccessImporter`) is the pattern to mirror.

## Global Constraints

- **Worktree:** all work happens in `D:\irnb-mail-register` on branch `feat/mail-register`, created from `main`: `git worktree add -b feat/mail-register D:/irnb-mail-register main`. Copy `vendor` (a real copy, no junctions): `cp -r D:/irnb-laravel/vendor D:/irnb-mail-register/vendor`; copy `node_modules` the same way: `cp -r D:/irnb-laravel/node_modules D:/irnb-mail-register/node_modules` (no `npm ci`: the app works offline); copy `.env`: `cp D:/irnb-laravel/.env D:/irnb-mail-register/.env`; then once in the worktree: `npm run build`. Never run tests in `D:\irnb-laravel` (its cached config makes tests refuse to run). If `bootstrap/cache/config.php` exists in the worktree, run `php artisan config:clear` first.
- **Tests:** single file `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/X.php` (from the worktree root); full suite `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Never run two test processes at once. Every new test class calls `$this->withoutVite()` in `setUp()` (new page components are not in the Vite manifest until a rebuild).
- **Staging:** stage explicit paths only (`git add <paths>`), never `git add -A` / `git add .` — the repo root holds untracked private files (an `.accdb`, PDFs, notes).
- **Commits:** conventional message, body ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **SQL:** portable SQLite + MySQL — no `strftime`, no `||` concatenation, no partial / filtered indexes, no SQLite-only functions; years computed in PHP (`Carbon::parse($date)->year`, `substr($date, 0, 4)`).
- **Migrations:** timestamps `2026_11_26_100010` (grant) and `2026_11_26_100011` (table). `2026_11_26_100001_create_player_status_subscription_table.php` already exists on `main`: never reuse `100001`.
- **State changes:** every write is an atomic conditional claim — `MailEntry::whereKey($id)->where(<expected state>)->update([...])`, refuse when it returns 0 — inside `DB::transaction`. A claim that may leave every value unchanged bumps `lock_version` (MySQL counts changed rows only).
- **Mail entries are never deleted** (no destroy route, no delete method); cancel keeps the number. Outgoing reference `YYYY/NN` (calendar year of `entry_date`, 2-digit pad, wider when > 99); incoming the same with the localized mark from `mreg.in_mark` (ar `و 2026/01`, fr `A-2026/01`, en `IN-2026/01`). Unique `(direction, year, number)`. Legacy rows: `number` null, `is_legacy` true, `legacy_reference` text; only notes + scan editable.
- **One active entry per printed document:** `source_lock` = `"{source_type}|{source_id}|{source_key}"` while active, `null` once cancelled, unique index. (A partial unique index `WHERE status = 'active'` does not exist on MySQL; a unique `(source_type, source_id, source_key, status)` would refuse a second cancellation of the same document. Many `NULL`s are allowed in a unique index on both SQLite and MySQL.)
- **PDF routes never write:** the letter / convocation / purchase-order GET routes only call `MailRegister::find()`; only the POST `mail.sources.store` creates an entry.
- **i18n:** ar / fr / en, flat dotted keys, added only through `node scripts/i18n-add.mjs <keys.json>` (insert-only: never re-run it on an existing key, never re-sort catalogs). Keys file: any scratch path, e.g. `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-tN-keys.json`. The module label is the message `mail` (the role editor prints `t(module)`), so **no key may start with `mail.`**: mail-register keys use the prefix `mreg.`. A key that is a message is never also a prefix (no `mreg.source` next to `mreg.source.game_convocation` — the label is `mreg.source_label`). No key segment after a message may be a String method name (`link`, `search`, `at`, `replace`, `trim`, `concat`, `split`…). Vue uses `t()` directly, never `te()`. `npm run i18n:check` must pass after every task that adds keys; every `flash.*` key emitted by PHP must exist in all three catalogs.
- **Activity log properties:** ids, references, directions, source types / ids only — never names, addresses, subjects or other free text. The four `MAIL_ENTRY_*` codes live in the existing `board` area (the secretary general is a board role; a new area would change every area chart and `ActivityReportTest`).
- **Permissions:** route-name derived (`config/permissions.php`). Module `mail`, new group `secretariat`, canonical actions (`view`, `add`, `edit`, `delete`). `mail.cancel` → `delete`; `mail.print`, `mail.scan` → `view`; `mail.sources.show`, `mail.sources.store` → `add` (the controller also checks the view right of the document's own module). Grant migration: system roles full, others none, idempotent.
- **Files:** one scan per entry on the private `local` disk through `App\Services\Storage\PrivateFileStorage` (directory `mail`), served only by `mail.scan` (`serve()`), replaced file deleted after a successful save, new upload deleted when the save fails — the transaction-receipt pattern.
- **PDFs:** mPDF via `PdfService::stream($html, $filename, $rtl)`; `<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">`, RTL when `app()->getLocale() === 'ar'`; `<bdo dir="ltr">` around references, dates, numbers; layout with tables only; free text through `App\Support\PdfText::ltrIfLatin()` (Latin lines left-to-right in Arabic pages, line breaks kept as `<br />` — mPDF ignores `pre-wrap`); labels via `UiLang::get()`.
- **UI:** action buttons are `IconButton`; dark mode on every colored class (`dark:text-primary-300`, never `-400`); layouts work at 320 px (`grid-cols-1` base, tables in `overflow-x-auto`); no CDN / remote resources; never name an Inertia `useForm` field `progress` (or another reserved name). The print dialog opens the PDF tab synchronously inside the click (`window.open('', '_blank')`) and only points it at the PDF after the POST — a tab opened after an `await` is blocked as a pop-up.
- **Test data:** fake names only ("Test Ligue", "Rivals FC", "Président Test"); the import fixture holds **fake** rows only — never a value read from the `.accdb`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/Role.php` (modify) | `mail` in `MODULES` + new `MODULE_GROUPS['secretariat']` |
| `config/permissions.php` (modify) | `mail` prefix + overrides (cancel → delete; print, scan → view; sources → add) |
| `resources/js/Components/PermissionGrid.vue` (modify) | `secretariat` section label |
| `database/migrations/2026_11_26_100010_grant_mail_permissions.php` | system roles get the module |
| `database/migrations/2026_11_26_100011_create_mail_entries_table.php` | `mail_entries` |
| `app/Services/Documents/YearlySequence.php` (modify) | optional `$where` scope (mail counts per direction) |
| `app/Models/MailEntry.php` | constants, reference, editable fields, printable, source lock |
| `app/Exceptions/MailEntryException.php` | refusals rendered as flash errors |
| `app/Services/Secretariat/MailRegister.php` | create / update (+ scan) / cancel / register / find |
| `app/Services/Activity/ActivityAction.php`, `ActivitySubjectLink.php` (modify) | four `MAIL_ENTRY_*` codes, subject link |
| `app/Services/Secretariat/MailSources.php` | source keys, defaults, rights, page description of a source |
| `app/Services/Attendance/PlayerBranchTabs.php` | the player card's branch-tab rule, shared by the letter and the mail source |
| `app/Http/Requests/Mail/SaveMailEntryRequest.php` | create form, or only the fields an entry may still change |
| `app/Http/Controllers/MailEntryController.php` | register pages, cancel, scan |
| `app/Http/Controllers/MailEntryPrintController.php`, `resources/views/pdf/mail-letter.blade.php` | the outgoing letter |
| `app/Services/Pdf/BoardSignatories.php` (modify) | adds `secretary` |
| `app/Http/Controllers/MailSourceController.php` | JSON lookup + register for the print dialog |
| `app/Http/Controllers/AttendancePlayerController.php`, `GameCallUpController.php`, `PurchaseOrderPrintController.php` + their views (modify) | «Ref: …» on the three prints |
| `resources/js/Pages/Mail/{Index,Create,Edit,Show}.vue`, `Partials/MailEntryForm.vue` | register UI |
| `resources/js/Components/MailPrintButton.vue` | print button with the tick-box dialog |
| `resources/js/Composables/useNavigation.js` (modify) | Secretariat section |
| `resources/js/Pages/PurchaseOrders/Show.vue`, `Games/Show.vue`, `Attendance/Alerts.vue`, `Attendance/Partials/StatsPlayerTable.vue`, `Players/Partials/AttendanceSection.vue` (modify) | print buttons use `MailPrintButton` |
| `app/Services/Legacy/LegacyAccessImporter.php`, `scripts/legacy/export-access.ps1`, `tests/Fixtures/legacy-access.json` (modify) | optional `mail` section |

---

### Task 1: `mail` right, `secretariat` group, grant migration

**Files:**
- Modify: `app/Models/Role.php:23-50`
- Modify: `config/permissions.php` (modules list, overrides)
- Modify: `resources/js/Components/PermissionGrid.vue:26-35`
- Create: `database/migrations/2026_11_26_100010_grant_mail_permissions.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (via script)
- Modify (test): `tests/js/navI18n.test.mjs`
- Test: `tests/Feature/MailRightsTest.php`

**Interfaces:**
- Produces: module key `mail` (group `secretariat`, actions `['view','add','edit','delete']`); route prefix `mail` → `mail`; exact overrides `mail.cancel` → `delete`, `mail.print` / `mail.scan` → `view`, `mail.sources.show` / `mail.sources.store` → `add`; i18n keys `mail` (module label), `nav.secretariat` (section label, reused by the sidebar in Task 7).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailRightsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function mail_is_the_secretariat_module_with_the_canonical_actions_only(): void
    {
        $this->assertContains('mail', Role::MODULES);
        $this->assertSame(['mail'], Role::MODULE_GROUPS['secretariat']);
        $this->assertSame(['view', 'add', 'edit', 'delete'], Role::allPermissions()['mail']);
        $this->assertArrayNotHasKey('mail', Role::EXTRA_ACTIONS);
    }

    #[Test]
    public function routes_map_to_the_rights_the_spec_names(): void
    {
        foreach (['index', 'show', 'print', 'scan'] as $route) {
            $this->assertSame(['mail', 'view'], PermissionMap::resolve('mail.'.$route), $route);
        }
        foreach (['create', 'store', 'sources.show', 'sources.store'] as $route) {
            $this->assertSame(['mail', 'add'], PermissionMap::resolve('mail.'.$route), $route);
        }
        foreach (['edit', 'update'] as $route) {
            $this->assertSame(['mail', 'edit'], PermissionMap::resolve('mail.'.$route), $route);
        }
        // Entries are never deleted: the delete right is cancelling.
        $this->assertSame(['mail', 'delete'], PermissionMap::resolve('mail.cancel'));
    }

    #[Test]
    public function the_grant_migration_gives_system_roles_the_module_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view']]]);
        $trimmed = Role::factory()->create(['is_system' => true, 'permissions' => ['mail' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_26_100010_grant_mail_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['mail']);
        $this->assertSame(['view'], $system->fresh()->permissions['players']);
        // A system role someone already trimmed keeps its choice.
        $this->assertSame(['view'], $trimmed->fresh()->permissions['mail']);
        $this->assertArrayNotHasKey('mail', $other->fresh()->permissions);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRightsTest.php`
Expected: FAIL (`mail` not in `Role::MODULES`, `PermissionMap::resolve('mail.index')` returns null, migration file missing).

- [ ] **Step 3: Add the module to `Role`**

In `app/Models/Role.php`, in `MODULE_GROUPS` replace

```php
        'board' => ['board'],
        'social' => ['activities', 'literary_reports'],
```

with

```php
        'board' => ['board'],
        // The board's secretariat (الأمانة): the mail register now, mission orders in P4.
        'secretariat' => ['mail'],
        'social' => ['activities', 'literary_reports'],
```

and in `MODULES` replace

```php
        'board',
        'activities', 'literary_reports',
```

with

```php
        'board',
        'mail',
        'activities', 'literary_reports',
```

- [ ] **Step 4: Map the routes in `config/permissions.php`**

In `config/permissions.php`, in `modules`, replace

```php
        'literary-reports' => 'literary_reports',
```

with

```php
        'literary-reports' => 'literary_reports',
        // Secretariat: the mail register (outgoing and incoming letters).
        'mail' => 'mail',
```

In `config/permissions.php`, in `overrides`, replace

```php
        'literary-reports.pdf' => ['literary_reports', 'view'],
```

with

```php
        'literary-reports.pdf' => ['literary_reports', 'view'],
        // Mail entries are never deleted, so the delete right is cancelling one.
        // Printing a letter and opening a scan only read. The print tick box
        // (lookup + register) adds an entry; the controller also checks the
        // view right of the printed document's own module.
        'mail.cancel' => ['mail', 'delete'],
        'mail.print' => ['mail', 'view'],
        'mail.scan' => ['mail', 'view'],
        'mail.sources.show' => ['mail', 'add'],
        'mail.sources.store' => ['mail', 'add'],
```

- [ ] **Step 5: Grant migration**

Create `database/migrations/2026_11_26_100010_grant_mail_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The mail register. Owner's rule for new rights: they start empty except the
 * system roles (full access). A migration because the desktop build never
 * seeds. Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const GRANT = [
        'mail' => ['view', 'add', 'edit', 'delete'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::GRANT as $module => $actions) {
                $permissions[$module] ??= $actions;
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (array_keys(self::GRANT) as $module) {
                unset($permissions[$module]);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
```

- [ ] **Step 6: Section label in the role editor**

In `resources/js/Components/PermissionGrid.vue`, in `SECTION_LABELS` replace

```js
    social: 'nav.social',
```

with

```js
    social: 'nav.social',
    secretariat: 'nav.secretariat',
```

- [ ] **Step 7: Module and section labels**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t1-keys.json`:

```json
{
    "mail": { "ar": "سجل البريد", "fr": "Registre du courrier", "en": "Mail register" },
    "nav.secretariat": { "ar": "الأمانة", "fr": "Secrétariat", "en": "Secretariat" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t1-keys.json`
Expected: `✓ 2 key(s) written to ar/en/fr`

- [ ] **Step 8: Pin the owner's section name in the JS test**

In `tests/js/navI18n.test.mjs` replace

```js
    'teams', 'games', 'competitions',
];
```

with

```js
    'teams', 'games', 'competitions',
    'nav.secretariat', 'mail',
];
```

and replace

```js
    assert.equal(messages.fr['nav.board_of_directors'], 'Bureau du club');
});
```

with

```js
    assert.equal(messages.fr['nav.board_of_directors'], 'Bureau du club');
    assert.equal(messages.ar['nav.secretariat'], 'الأمانة');
});
```

- [ ] **Step 9: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRightsTest.php`
Expected: PASS (3 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PerListPermissionsTest.php`
Expected: PASS (MODULES and MODULE_GROUPS still list the same modules).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/RoleExtraActionsTest.php`
Expected: PASS.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Models/Role.php config/permissions.php resources/js/Components/PermissionGrid.vue database/migrations/2026_11_26_100010_grant_mail_permissions.php tests/Feature/MailRightsTest.php tests/js/navI18n.test.mjs resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(roles): mail register module in a new secretariat group

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: `mail_entries` table, `MailEntry` model, sequence per direction

**Files:**
- Create: `database/migrations/2026_11_26_100011_create_mail_entries_table.php`
- Create: `app/Models/MailEntry.php`
- Modify: `app/Services/Documents/YearlySequence.php` (whole file, 37 lines)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MailEntryModelTest.php`

**Interfaces:**
- Consumes: `YearlySequence::retrying(callable): mixed` (P1, unchanged).
- Produces:
  - `YearlySequence::next(string $table, int $year, array $where = []): int` — `$where` is a `[column => value]` filter (mail passes `['direction' => 'out']`); existing two-argument callers are unchanged.
  - `MailEntry` constants `OUT='out'`, `IN='in'`, `DIRECTIONS`, `ACTIVE='active'`, `CANCELLED='cancelled'`, `STATUSES`, `SOURCE_ABSENCE_LETTER='absence_letter'`, `SOURCE_GAME='game_convocation'`, `SOURCE_PURCHASE_ORDER='purchase_order'`, `FORM_FIELDS`, `SOURCE_FIELDS`.
  - Appended attributes `reference` (string) and `has_scan` (bool); hidden `scan_path`, `source_lock`, `lock_version`.
  - `static MailEntry::fieldsFor(string $direction): list<string>`, `static MailEntry::lockFor(string $type, int $id, string $key): string` (`"{type}|{id}|{key}"`), `isFromSource(): bool`, `isPrintable(): bool`, `editableFields(): list<string>`, scope `active()`, relations `createdBy()`, `cancelledBy()`.
  - i18n key `mreg.in_mark`.
  - Columns: see the migration below.

- [ ] **Step 1: Write the failing model test**

Create `tests/Feature/MailEntryModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailEntryModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function entry(array $attributes = []): MailEntry
    {
        return MailEntry::create(array_merge([
            'direction' => MailEntry::OUT, 'year' => 2026, 'number' => 1, 'status' => MailEntry::ACTIVE,
            'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue', 'subject' => 'Engagement',
        ], $attributes));
    }

    #[Test]
    public function the_reference_is_year_slash_number_with_the_incoming_mark_or_the_old_text(): void
    {
        $this->assertSame('2026/03', $this->entry(['number' => 3])->reference);
        $this->assertSame('2026/114', $this->entry(['number' => 114])->reference);

        $incoming = $this->entry(['direction' => MailEntry::IN, 'number' => 1]);
        app()->setLocale('fr');
        $this->assertSame('A-2026/01', $incoming->reference);
        app()->setLocale('en');
        $this->assertSame('IN-2026/01', $incoming->reference);
        app()->setLocale('ar');
        $this->assertSame('و 2026/01', $incoming->reference);

        $this->assertSame('2019/07', $this->entry([
            'number' => null, 'year' => 2019, 'is_legacy' => true, 'legacy_reference' => '2019/07', 'entry_date' => null,
        ])->reference);
    }

    #[Test]
    public function each_direction_counts_from_one_every_year_and_old_rows_never_count(): void
    {
        $this->entry(['number' => 1]);
        $this->entry(['number' => 2]);
        $this->entry(['direction' => MailEntry::IN, 'number' => 1]);
        $this->entry(['number' => null, 'is_legacy' => true, 'legacy_reference' => '2026/40']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026, ['direction' => MailEntry::OUT])));
        $this->assertSame(2, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026, ['direction' => MailEntry::IN])));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('mail_entries', 2027, ['direction' => MailEntry::OUT])));
        // Without a filter the sequence reads the whole year, as payment and purchase orders do.
        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026)));
    }

    #[Test]
    public function a_number_is_used_once_per_direction_and_year(): void
    {
        $this->entry(['number' => 1]);
        // The same number in the other direction belongs to another sequence.
        $this->entry(['direction' => MailEntry::IN, 'number' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry(['number' => 1]);
    }

    #[Test]
    public function only_one_active_entry_holds_a_document_lock(): void
    {
        $lock = MailEntry::lockFor(MailEntry::SOURCE_GAME, 5, 'convocation');
        $this->assertSame('game_convocation|5|convocation', $lock);

        // Cancelled entries of the same document carry no lock: any number of them.
        $this->entry(['number' => 1, 'status' => MailEntry::CANCELLED, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation']);
        $this->entry(['number' => 2, 'status' => MailEntry::CANCELLED, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation']);
        $this->entry(['number' => 3, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation', 'source_lock' => $lock]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry(['number' => 4, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation', 'source_lock' => $lock]);
    }

    #[Test]
    public function the_editable_fields_follow_the_kind_of_entry(): void
    {
        $this->assertSame(['entry_date', 'correspondent', 'address', 'subject', 'body', 'notes'], $this->entry(['number' => 1])->editableFields());
        $this->assertSame(
            ['entry_date', 'correspondent', 'address', 'subject', 'sender_reference', 'received_date', 'notes'],
            $this->entry(['direction' => MailEntry::IN, 'number' => 1])->editableFields(),
        );
        // Made from a print: the document fixes direction, number, date and text.
        $this->assertSame(['correspondent', 'address', 'subject', 'notes'], $this->entry([
            'number' => 2, 'source_type' => MailEntry::SOURCE_PURCHASE_ORDER, 'source_id' => 1, 'source_key' => 'order',
        ])->editableFields());
        $this->assertSame(['notes'], $this->entry(['number' => null, 'is_legacy' => true, 'legacy_reference' => '2019/01'])->editableFields());
        $this->assertSame([], $this->entry(['number' => 3, 'status' => MailEntry::CANCELLED])->editableFields());
    }

    #[Test]
    public function only_an_active_outgoing_entry_with_a_text_prints_as_a_letter(): void
    {
        $this->assertTrue($this->entry(['number' => 1, 'body' => "Monsieur,\nBonjour."])->isPrintable());
        $this->assertFalse($this->entry(['number' => 2, 'body' => '   '])->isPrintable());
        $this->assertFalse($this->entry(['number' => 3, 'body' => 'Texte', 'status' => MailEntry::CANCELLED])->isPrintable());
        $this->assertFalse($this->entry(['direction' => MailEntry::IN, 'number' => 1, 'body' => 'Texte'])->isPrintable());
    }

    #[Test]
    public function the_page_props_carry_the_reference_and_the_scan_flag_but_no_internal_columns(): void
    {
        $array = $this->entry(['number' => 1, 'scan_path' => 'mail/abc.pdf', 'scan_name' => 'lettre.pdf', 'source_lock' => 'x|1|y'])->toArray();

        $this->assertSame('2026/01', $array['reference']);
        $this->assertTrue($array['has_scan']);
        $this->assertSame('lettre.pdf', $array['scan_name']);
        $this->assertArrayNotHasKey('scan_path', $array);
        $this->assertArrayNotHasKey('source_lock', $array);
        $this->assertArrayNotHasKey('lock_version', $array);
        $this->assertFalse($this->entry(['number' => 2])->has_scan);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryModelTest.php`
Expected: FAIL (`Class "App\Models\MailEntry" not found`).

- [ ] **Step 3: Table migration**

Create `database/migrations/2026_11_26_100011_create_mail_entries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mail register (سجل البريد): outgoing and incoming letters, numbered
 * per direction and calendar year (2026/01, incoming shown with a mark).
 * Entries are never deleted — cancelled ones keep their number. The old
 * Access letters are imported with their text reference and no number.
 *
 * An entry made from a print points at its document through source_type /
 * source_id / source_key. source_lock repeats those three while the entry is
 * active and is cleared on cancel; its unique index allows one active entry
 * per document on SQLite and MySQL alike (a partial index "where active" is
 * not portable, and NULLs never collide in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_entries', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);
            // Calendar year of entry_date; null only on an imported row with neither a date nor a year in its reference.
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('status', 20)->default('active');
            // Null only on imported rows (half the old register has no date).
            $table->date('entry_date')->nullable();
            // Recipient (outgoing) or sender (incoming); required by the form, null only on imported rows.
            $table->string('correspondent', 200)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('subject', 255)->nullable();
            // Outgoing letter text printed on the letterhead (optional).
            $table->text('body')->nullable();
            // Incoming only.
            $table->string('sender_reference', 100)->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            // One scan per entry, on the private disk.
            $table->string('scan_path', 255)->nullable();
            $table->string('scan_name', 255)->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120)->nullable();
            $table->string('source_lock', 191)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('legacy_reference', 50)->nullable();
            $table->string('legacy_source', 20)->nullable();
            // Bumped by every edit claim so the claim always changes a row (MySQL counts changed rows only).
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['direction', 'year', 'number']);
            $table->unique('source_lock');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_entries');
    }
};
```

- [ ] **Step 4: Scope the sequence**

Replace the whole content of `app/Services/Documents/YearlySequence.php` with:

```php
<?php

namespace App\Services\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Per-year document numbers (payment orders, purchase orders, the mail
 * register): the year's highest number plus one. Gaps stay — cancelled
 * documents keep their number. Each numbered table carries a unique index on
 * its sequence columns (year, number — on mail: direction, year, number), so
 * two people saving at the same instant cannot share a number: the second
 * insert fails and retrying() runs it again.
 */
final class YearlySequence
{
    /**
     * The next number for $year among the rows matching $where (e.g. one
     * mail direction). Call inside the DB transaction that inserts it.
     *
     * @param  array<string, mixed>  $where  column => value
     */
    public static function next(string $table, int $year, array $where = []): int
    {
        return (int) DB::table($table)
            ->where('year', $year)
            ->when($where !== [], fn ($query) => $query->where($where))
            ->max('number') + 1;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    public static function retrying(callable $attempt): mixed
    {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();
        }
    }
}
```

- [ ] **Step 5: The model**

Create `app/Models/MailEntry.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the mail register (سجل البريد): an outgoing letter (with an
 * optional text printed on the letterhead) or an incoming one. Numbered per
 * direction and calendar year; cancelled entries keep their number and are
 * never deleted. An entry made from a print (absence letter, convocation,
 * purchase order) points at its document through source_type / source_id /
 * source_key, and holds source_lock while active.
 */
class MailEntry extends Model
{
    use SerializesClubDates;

    public const OUT = 'out';

    public const IN = 'in';

    public const DIRECTIONS = [self::OUT, self::IN];

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE, self::CANCELLED];

    public const SOURCE_ABSENCE_LETTER = 'absence_letter';

    public const SOURCE_GAME = 'game_convocation';

    public const SOURCE_PURCHASE_ORDER = 'purchase_order';

    /** Every field the form may send; fieldsFor() and editableFields() narrow it. */
    public const FORM_FIELDS = ['entry_date', 'correspondent', 'address', 'subject', 'body', 'sender_reference', 'received_date', 'notes'];

    /** What an entry made from a print may still change: its document fixes direction, number, date and text. */
    public const SOURCE_FIELDS = ['correspondent', 'address', 'subject', 'notes'];

    protected $fillable = [
        'direction', 'year', 'number', 'status', 'entry_date', 'correspondent', 'address', 'subject', 'body',
        'sender_reference', 'received_date', 'notes', 'scan_path', 'scan_name',
        'source_type', 'source_id', 'source_key', 'source_lock',
        'is_legacy', 'legacy_reference', 'legacy_source', 'created_by', 'cancelled_by', 'cancelled_at',
    ];

    protected $hidden = ['scan_path', 'source_lock', 'lock_version'];

    protected $appends = ['reference', 'has_scan'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'source_id' => 'integer',
            'is_legacy' => 'boolean',
            'entry_date' => 'date:Y-m-d',
            'received_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Outgoing 2026/01; incoming with the app language's mark (و 2026/01, A-2026/01, IN-2026/01); imported rows keep their old text. */
    protected function reference(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->is_legacy || $this->number === null) {
                return (string) $this->legacy_reference;
            }
            $reference = sprintf('%d/%02d', $this->year, $this->number);

            return $this->direction === self::IN ? UiLang::get('mreg.in_mark').$reference : $reference;
        });
    }

    protected function hasScan(): Attribute
    {
        return Attribute::make(get: fn () => (string) $this->scan_path !== '');
    }

    /**
     * The form fields a direction uses: the letter text is outgoing only, the
     * sender's reference and received date incoming only.
     *
     * @return list<string>
     */
    public static function fieldsFor(string $direction): array
    {
        $unused = $direction === self::OUT ? ['sender_reference', 'received_date'] : ['body'];

        return array_values(array_diff(self::FORM_FIELDS, $unused));
    }

    /** The marker an active entry made from a print holds (unique); cancelling clears it. */
    public static function lockFor(string $type, int $id, string $key): string
    {
        return $type.'|'.$id.'|'.$key;
    }

    public function isFromSource(): bool
    {
        return $this->source_type !== null;
    }

    /** An active outgoing entry with a letter text prints on the letterhead. */
    public function isPrintable(): bool
    {
        return $this->direction === self::OUT && $this->status === self::ACTIVE && trim((string) $this->body) !== '';
    }

    /**
     * What the edit form may still change. The scan follows the same rule
     * (none once cancelled) and is handled apart.
     *
     * @return list<string>
     */
    public function editableFields(): array
    {
        if ($this->status !== self::ACTIVE) {
            return [];
        }
        if ($this->is_legacy) {
            // Lets the secretary annotate and scan the old letters, nothing else.
            return ['notes'];
        }
        if ($this->isFromSource()) {
            return self::SOURCE_FIELDS;
        }

        return self::fieldsFor($this->direction);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('mail_entries.status', self::ACTIVE);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
```

- [ ] **Step 6: The incoming mark**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t2-keys.json` (the Arabic mark ends with a space, so the reference reads `و 2026/01`):

```json
{
    "mreg.in_mark": { "ar": "و ", "fr": "A-", "en": "IN-" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t2-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryModelTest.php`
Expected: PASS (7 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderModelTest.php`
Expected: PASS (two-argument `YearlySequence::next` unchanged).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderModelTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_26_100011_create_mail_entries_table.php app/Models/MailEntry.php app/Services/Documents/YearlySequence.php tests/Feature/MailEntryModelTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(mail): mail_entries table, MailEntry model, sequence per direction

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: `MailRegister` service (create, update + scan, cancel, register, find)

**Files:**
- Create: `app/Exceptions/MailEntryException.php`
- Create: `app/Services/Secretariat/MailRegister.php`
- Modify: `app/Services/Activity/ActivityAction.php` (constants after `PURCHASE_ORDER_PRINTED`, `ALL`, `AREAS['board']`)
- Modify: `app/Services/Activity/ActivitySubjectLink.php` (import, `MODULES`, `describe()`)
- Modify (test): `tests/Unit/ActivityRecorderTest.php:61`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MailRegisterTest.php`

**Interfaces:**
- Consumes: `MailEntry` (Task 2: `fieldsFor()`, `editableFields()`, `lockFor()`, constants), `YearlySequence::next(string, int, array)` and `::retrying()`, `PrivateFileStorage::store(UploadedFile, string): array{path, original_name, mime, size}`, `::delete(?string)`, `ActivityRecorder::record(?User, string, ?Model, array)`.
- Produces:
  - `MailEntryException(string $flashKey)` with public `$flashKey`; renders `back()->with('error', key)`.
  - `MailRegister` (container-resolved): `create(array $data, User $by, ?UploadedFile $scan = null): MailEntry`; `update(MailEntry $entry, array $data, User $by, ?UploadedFile $scan = null): MailEntry`; `cancel(MailEntry $entry, User $by): MailEntry`; `register(string $type, int $id, string $key, array $defaults, User $by): MailEntry` (`$defaults` keys `correspondent`, `address`, `subject`; returns the existing active entry with `wasRecentlyCreated === false`, or the new one with `true`); `find(string $type, int $id, string $key): ?MailEntry`.
  - Activity codes `ActivityAction::MAIL_ENTRY_CREATED` (`mail_entry_created`), `MAIL_ENTRY_UPDATED`, `MAIL_ENTRY_CANCELLED`, `MAIL_ENTRY_REGISTERED`; properties `reference`, `direction` (+ `scan: true` on a replaced scan, + `source_type`, `source_id` on register).
  - Flash keys `flash.mail_locked`, `flash.mail_year_locked`, `flash.mail_cannot_cancel`; label key `mreg.title`.
  - `ActivitySubjectLink` labels a `MailEntry` subject `«mreg.title» {reference}` linked to `route('mail.show', $entry)` (the route arrives in Task 4), visible to `mail` → `view`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailRegisterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\MailEntryException;
use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function register(): MailRegister
    {
        return app(MailRegister::class);
    }

    private function outgoing(array $extra = [], ?UploadedFile $scan = null): MailEntry
    {
        return $this->register()->create(array_merge([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue',
            'address' => '1 rue Test', 'subject' => 'Engagement saison', 'body' => "Monsieur,\nBonjour.", 'notes' => null,
        ], $extra), $this->admin, $scan);
    }

    private function legacy(): MailEntry
    {
        return MailEntry::create([
            'direction' => MailEntry::OUT, 'year' => 2019, 'status' => MailEntry::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '2019/04', 'legacy_source' => 'corresponds', 'correspondent' => 'Ancien destinataire',
        ]);
    }

    #[Test]
    public function created_entries_are_numbered_per_direction_and_year_of_their_date(): void
    {
        $this->assertSame('2026/01', $this->outgoing()->reference);
        $this->assertSame('2026/02', $this->outgoing()->reference);
        $this->assertSame('2025/01', $this->outgoing(['entry_date' => '2025-12-30'])->reference);

        app()->setLocale('fr');
        $in = $this->register()->create([
            'direction' => MailEntry::IN, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction',
            'subject' => 'Invitation', 'sender_reference' => 'DJS/45', 'received_date' => '2026-11-25',
            // Not an incoming field: dropped.
            'body' => 'Ignored',
        ], $this->admin);

        $this->assertSame('A-2026/01', $in->reference);
        $this->assertNull($in->body);
        $this->assertSame('DJS/45', $in->sender_reference);
        $this->assertSame('2026-11-25', $in->received_date->toDateString());
        $this->assertSame(MailEntry::ACTIVE, $in->status);
        $this->assertSame($this->admin->id, $in->created_by);
    }

    #[Test]
    public function an_outgoing_entry_drops_the_incoming_fields(): void
    {
        $entry = $this->outgoing(['sender_reference' => 'X/1', 'received_date' => '2026-11-20']);

        $this->assertNull($entry->sender_reference);
        $this->assertNull($entry->received_date);
        $this->assertSame("Monsieur,\nBonjour.", $entry->body);
    }

    #[Test]
    public function a_scan_goes_to_the_private_disk_and_a_new_one_replaces_it(): void
    {
        $entry = $this->outgoing([], UploadedFile::fake()->create('lettre.pdf', 50, 'application/pdf'));
        $first = $entry->scan_path;

        $this->assertTrue($entry->has_scan);
        $this->assertSame('lettre.pdf', $entry->scan_name);
        $this->assertStringStartsWith('mail/', $first);
        Storage::disk('local')->assertExists($first);

        $entry = $this->register()->update($entry, ['notes' => 'Scannée'], $this->admin, UploadedFile::fake()->create('signee.pdf', 50, 'application/pdf'));

        $this->assertSame('signee.pdf', $entry->scan_name);
        $this->assertSame('Scannée', $entry->notes);
        Storage::disk('local')->assertExists($entry->scan_path);
        Storage::disk('local')->assertMissing($first);
    }

    #[Test]
    public function a_refused_save_leaves_no_uploaded_file_behind(): void
    {
        $entry = $this->outgoing();
        // Someone else cancels it while this page still shows it active: the claim refuses.
        $this->register()->cancel($entry->fresh(), $this->admin);

        try {
            $this->register()->update($entry, ['notes' => 'x'], $this->admin, UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'));
            $this->fail('A cancelled entry took a scan.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_locked', $e->flashKey);
        }

        $this->assertSame([], Storage::disk('local')->allFiles('mail'));
        $this->assertNull($entry->fresh()->notes);
    }

    #[Test]
    public function an_update_changes_only_what_the_entry_may_still_change(): void
    {
        $entry = $this->register()->update($this->outgoing(), [
            'correspondent' => 'Test Ligue Régionale', 'subject' => 'Engagement', 'body' => 'Nouveau texte',
            'entry_date' => '2026-11-20', 'direction' => MailEntry::IN,
        ], $this->admin);

        $this->assertSame('Test Ligue Régionale', $entry->correspondent);
        $this->assertSame('Nouveau texte', $entry->body);
        $this->assertSame('2026-11-20', $entry->entry_date->toDateString());
        $this->assertSame(MailEntry::OUT, $entry->direction);
        $this->assertSame('2026/01', $entry->reference);

        // Made from a print: correspondent, address, subject and notes only.
        $printed = $this->register()->register(MailEntry::SOURCE_GAME, 7, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $printed = $this->register()->update($printed, [
            'correspondent' => 'Ligue Test', 'body' => 'Texte', 'entry_date' => '2026-11-01', 'notes' => 'Envoyée par mail',
        ], $this->admin);

        $this->assertSame('Ligue Test', $printed->correspondent);
        $this->assertNull($printed->body);
        $this->assertSame('2026-11-26', $printed->entry_date->toDateString());
        $this->assertSame('Envoyée par mail', $printed->notes);

        // Imported: notes only.
        $old = $this->register()->update($this->legacy(), ['correspondent' => 'Changé', 'notes' => 'Original classé'], $this->admin);

        $this->assertSame('Ancien destinataire', $old->correspondent);
        $this->assertSame('Original classé', $old->notes);
    }

    #[Test]
    public function the_date_stays_in_the_year_of_the_number(): void
    {
        $entry = $this->outgoing();

        try {
            $this->register()->update($entry, ['entry_date' => '2027-01-02'], $this->admin);
            $this->fail('The date left its number\'s year.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_year_locked', $e->flashKey);
        }
        $this->assertSame('2026-11-26', $entry->fresh()->entry_date->toDateString());
    }

    #[Test]
    public function a_cancelled_entry_keeps_its_number_and_is_read_only(): void
    {
        $entry = $this->register()->cancel($this->outgoing(), $this->admin);

        $this->assertSame(MailEntry::CANCELLED, $entry->status);
        $this->assertSame($this->admin->id, $entry->cancelled_by);
        $this->assertNotNull($entry->cancelled_at);
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame('2026/02', $this->outgoing()->reference);

        try {
            $this->register()->update($entry, ['notes' => 'x'], $this->admin);
            $this->fail('A cancelled entry was edited.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_locked', $e->flashKey);
        }
        try {
            $this->register()->cancel($entry, $this->admin);
            $this->fail('An entry was cancelled twice.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_cannot_cancel', $e->flashKey);
        }
    }

    #[Test]
    public function an_imported_entry_cannot_be_cancelled(): void
    {
        $old = $this->legacy();

        try {
            $this->register()->cancel($old, $this->admin);
            $this->fail('An imported entry was cancelled.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_cannot_cancel', $e->flashKey);
        }
        $this->assertSame(MailEntry::ACTIVE, $old->fresh()->status);
    }

    #[Test]
    public function a_print_takes_one_outgoing_number_and_reprints_reuse_it(): void
    {
        $first = $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order', [
            'correspondent' => 'Test Sport Shop', 'address' => '12 rue des Sports', 'subject' => 'Bon de commande 03/2026',
        ], $this->admin);
        $again = $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order', ['correspondent' => 'Other'], $this->admin);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('2026/01', $first->reference);
        $this->assertSame(MailEntry::OUT, $first->direction);
        $this->assertSame('2026-11-26', $first->entry_date->toDateString());
        $this->assertSame('Test Sport Shop', $first->correspondent);
        $this->assertSame('12 rue des Sports', $first->address);
        $this->assertSame('Bon de commande 03/2026', $first->subject);
        $this->assertSame([MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order'], [$first->source_type, $first->source_id, $first->source_key]);
        $this->assertSame(1, MailEntry::count());
        // Another document is another entry; the outgoing sequence is shared with typed letters.
        $this->assertSame('2026/02', $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 4, 'order', ['correspondent' => 'X', 'subject' => 'Y'], $this->admin)->reference);
        $this->assertSame('2026/03', $this->outgoing()->reference);
    }

    #[Test]
    public function find_only_looks_up_and_a_cancelled_entry_frees_its_document(): void
    {
        $this->assertNull($this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation'));
        $this->assertSame(0, MailEntry::count());

        $first = $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $this->assertSame($first->id, $this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation')?->id);

        $this->register()->cancel($first, $this->admin);
        $this->assertNull($this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation'));

        $second = $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('2026/02', $second->reference);
        $this->assertSame(2, MailEntry::where('source_type', MailEntry::SOURCE_GAME)->where('source_id', 9)->count());
    }

    #[Test]
    public function blank_defaults_still_give_a_readable_entry(): void
    {
        $entry = $this->register()->register(MailEntry::SOURCE_GAME, 1, 'convocation', ['correspondent' => '  ', 'subject' => null], $this->admin);

        $this->assertSame('—', $entry->correspondent);
        $this->assertSame('—', $entry->subject);
        $this->assertNull($entry->address);
    }

    #[Test]
    public function the_activity_log_keeps_references_never_names_or_text(): void
    {
        $entry = $this->outgoing();
        $this->register()->update($entry, ['notes' => 'Texte libre'], $this->admin);
        $this->register()->cancel($entry, $this->admin);
        $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);

        $created = ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CREATED)->sole();
        $this->assertSame($entry->id, (int) $created->subject_id);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], $created->properties);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], ActivityLog::where('action', ActivityAction::MAIL_ENTRY_UPDATED)->sole()->properties);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CANCELLED)->sole()->properties);
        $this->assertSame(
            ['reference' => '2026/02', 'direction' => 'out', 'source_type' => 'game_convocation', 'source_id' => 9],
            ActivityLog::where('action', ActivityAction::MAIL_ENTRY_REGISTERED)->sole()->properties,
        );
        foreach (ActivityLog::all() as $log) {
            foreach (['Test Ligue', '1 rue Test', 'Engagement', 'Texte libre', 'Rivals FC', 'Convocation'] as $text) {
                $this->assertStringNotContainsString($text, (string) json_encode($log->properties, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRegisterTest.php`
Expected: FAIL (`Class "App\Services\Secretariat\MailRegister" not found`).

- [ ] **Step 3: Exception**

Create `app/Exceptions/MailEntryException.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A mail-register step the entry's state refuses (editing a cancelled entry,
 * moving a date out of its number's year, cancelling an imported letter…).
 * An expected refusal: rendered as the flash error it names, never logged.
 */
class MailEntryException extends RuntimeException
{
    public function __construct(public readonly string $flashKey)
    {
        parent::__construct($flashKey);
    }

    public function report(): void {}

    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', $this->flashKey);
    }
}
```

- [ ] **Step 4: Activity codes**

In `app/Services/Activity/ActivityAction.php` replace

```php
    const PURCHASE_ORDER_PRINTED = 'purchase_order_printed';
```

with

```php
    const PURCHASE_ORDER_PRINTED = 'purchase_order_printed';

    const MAIL_ENTRY_CREATED = 'mail_entry_created';

    const MAIL_ENTRY_UPDATED = 'mail_entry_updated';

    const MAIL_ENTRY_CANCELLED = 'mail_entry_cancelled';

    const MAIL_ENTRY_REGISTERED = 'mail_entry_registered';
```

In `ALL` (8-space indent) replace

```php
        self::BOARD_MEMBER_REMOVED,
        self::DOCUMENT_UPDATED,
```

with

```php
        self::BOARD_MEMBER_REMOVED,
        self::MAIL_ENTRY_CREATED,
        self::MAIL_ENTRY_UPDATED,
        self::MAIL_ENTRY_CANCELLED,
        self::MAIL_ENTRY_REGISTERED,
        self::DOCUMENT_UPDATED,
```

In `AREAS['board']` (12-space indent) replace

```php
            self::BOARD_MEMBER_REMOVED,
        ],
        'social' => [
```

with

```php
            self::BOARD_MEMBER_REMOVED,
            // The secretariat's mail register (the secretary general is a board role).
            self::MAIL_ENTRY_CREATED,
            self::MAIL_ENTRY_UPDATED,
            self::MAIL_ENTRY_CANCELLED,
            self::MAIL_ENTRY_REGISTERED,
        ],
        'social' => [
```

In `tests/Unit/ActivityRecorderTest.php` replace

```php
        $this->assertCount(131, ActivityAction::ALL);
```

with

```php
        $this->assertCount(135, ActivityAction::ALL);
```

- [ ] **Step 5: Subject link (activity pages)**

In `app/Services/Activity/ActivitySubjectLink.php` replace

```php
use App\Models\LiteraryReport;
use App\Models\MemberJob;
```

with

```php
use App\Models\LiteraryReport;
use App\Models\MailEntry;
use App\Models\MemberJob;
```

replace

```php
        PurchaseOrder::class => ['purchase_orders'],
```

with

```php
        PurchaseOrder::class => ['purchase_orders'],
        MailEntry::class => ['mail'],
```

and replace

```php
            $subject instanceof PurchaseOrder => [
                UiLang::get('pur.title').' '.$subject->reference,
                route('purchase-orders.show', $subject),
            ],
```

with

```php
            $subject instanceof PurchaseOrder => [
                UiLang::get('pur.title').' '.$subject->reference,
                route('purchase-orders.show', $subject),
            ],
            // The route comes with the controller (Task 4); a link is only built when a log is displayed.
            $subject instanceof MailEntry => [
                UiLang::get('mreg.title').' '.$subject->reference,
                route('mail.show', $subject),
            ],
```

- [ ] **Step 6: The service**

Create `app/Services/Secretariat/MailRegister.php`:

```php
<?php

namespace App\Services\Secretariat;

use App\Exceptions\MailEntryException;
use App\Models\MailEntry;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every write to the mail register, one DB transaction each, each guarded by
 * an atomic conditional claim: create (numbered per direction and year of the
 * entry date), update (only the fields the entry may still change, and its
 * one scan), cancel (keeps the number, frees the printed document) and
 * register() for the print tick box. Entries are never deleted.
 */
final class MailRegister
{
    /** Folder of the scans on the private disk. */
    private const SCANS = 'mail';

    public function __construct(private readonly PrivateFileStorage $files) {}

    /** @param array<string, mixed> $data validated form fields, with `direction` */
    public function create(array $data, User $by, ?UploadedFile $scan = null): MailEntry
    {
        $direction = (string) $data['direction'];
        $fields = Arr::only($data, MailEntry::fieldsFor($direction));
        $stored = $scan ? $this->files->store($scan, self::SCANS) : null;

        try {
            return YearlySequence::retrying(fn () => DB::transaction(function () use ($direction, $fields, $stored, $by) {
                $year = Carbon::parse($fields['entry_date'])->year;
                $entry = MailEntry::create([
                    ...$fields,
                    'direction' => $direction,
                    'year' => $year,
                    'number' => YearlySequence::next('mail_entries', $year, ['direction' => $direction]),
                    'status' => MailEntry::ACTIVE,
                    'scan_path' => $stored['path'] ?? null,
                    'scan_name' => $stored['original_name'] ?? null,
                    'created_by' => $by->id,
                ]);
                ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CREATED, $entry, self::properties($entry));

                return $entry->fresh();
            }));
        } catch (Throwable $e) {
            // Refused: do not leave the upload orphaned.
            $this->files->delete($stored['path'] ?? null);

            throw $e;
        }
    }

    /**
     * Only the fields editableFields() allows; the rest of $data is ignored.
     * A new scan replaces the old one, which is deleted once the save holds.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MailEntry $entry, array $data, User $by, ?UploadedFile $scan = null): MailEntry
    {
        $editable = $entry->editableFields();
        if ($editable === []) {
            throw new MailEntryException('flash.mail_locked');
        }
        $changes = Arr::only($data, $editable);
        // The number belongs to the year it was given in.
        if (isset($changes['entry_date']) && Carbon::parse($changes['entry_date'])->year !== $entry->year) {
            throw new MailEntryException('flash.mail_year_locked');
        }
        $previous = $entry->scan_path;
        $stored = $scan ? $this->files->store($scan, self::SCANS) : null;

        try {
            DB::transaction(function () use ($entry, $changes, $stored, $by) {
                $claimed = MailEntry::whereKey($entry->id)->where('status', MailEntry::ACTIVE)
                    ->update([
                        ...$changes,
                        ...($stored !== null ? ['scan_path' => $stored['path'], 'scan_name' => $stored['original_name']] : []),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now(),
                    ]);
                if ($claimed === 0) {
                    throw new MailEntryException('flash.mail_locked');
                }
                ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_UPDATED, $entry, [
                    ...self::properties($entry),
                    // The fact only: file names never reach the log.
                    'scan' => $stored !== null ? true : null,
                ]);
            });
        } catch (Throwable $e) {
            $this->files->delete($stored['path'] ?? null);

            throw $e;
        }

        if ($stored !== null && $previous) {
            $this->files->delete($previous);
        }

        return $entry->fresh();
    }

    /** Active, not imported; keeps its number and frees its printed document for a new entry. */
    public function cancel(MailEntry $entry, User $by): MailEntry
    {
        return DB::transaction(function () use ($entry, $by) {
            $claimed = MailEntry::whereKey($entry->id)
                ->where('status', MailEntry::ACTIVE)->where('is_legacy', false)
                ->update([
                    'status' => MailEntry::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'source_lock' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MailEntryException('flash.mail_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CANCELLED, $entry, self::properties($entry));

            return $entry->fresh();
        });
    }

    /**
     * The print tick box: the document's active entry, or a new outgoing one
     * (today, next number) with the document's correspondent, address and
     * subject. A cancelled entry does not count — the document gets a new
     * number. Two clicks at the same instant: the second insert fails on the
     * unique source_lock and the retry returns the first one's entry.
     *
     * @param  array{correspondent?: ?string, address?: ?string, subject?: ?string}  $defaults
     */
    public function register(string $type, int $id, string $key, array $defaults, User $by): MailEntry
    {
        $lock = MailEntry::lockFor($type, $id, $key);

        return YearlySequence::retrying(fn () => DB::transaction(function () use ($type, $id, $key, $lock, $defaults, $by) {
            $existing = MailEntry::where('source_lock', $lock)->first();
            if ($existing !== null) {
                return $existing;
            }

            $today = Carbon::now();
            $entry = MailEntry::create([
                'direction' => MailEntry::OUT,
                'year' => $today->year,
                'number' => YearlySequence::next('mail_entries', $today->year, ['direction' => MailEntry::OUT]),
                'status' => MailEntry::ACTIVE,
                'entry_date' => $today->toDateString(),
                'correspondent' => self::text($defaults['correspondent'] ?? null, 200) ?? '—',
                'address' => self::text($defaults['address'] ?? null, 255),
                'subject' => self::text($defaults['subject'] ?? null, 255) ?? '—',
                'source_type' => $type,
                'source_id' => $id,
                'source_key' => $key,
                'source_lock' => $lock,
                'created_by' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_REGISTERED, $entry, [
                ...self::properties($entry),
                'source_type' => $type,
                'source_id' => $id,
            ]);

            return $entry;
        }));
    }

    /** The document's active entry, if any. Never writes: the PDF routes call only this. */
    public function find(string $type, int $id, string $key): ?MailEntry
    {
        return MailEntry::where('source_lock', MailEntry::lockFor($type, $id, $key))->first();
    }

    /** @return array{reference: string, direction: string} references only, never names or free text */
    private static function properties(MailEntry $entry): array
    {
        return ['reference' => $entry->reference, 'direction' => $entry->direction];
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
```

- [ ] **Step 7: Translations (refusals, activity labels, title)**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t3-keys.json`:

```json
{
    "mreg.title": { "ar": "مراسلة", "fr": "Courrier", "en": "Mail" },
    "flash.mail_locked": { "ar": "لا يمكن تعديل هذا التسجيل.", "fr": "Cette entrée ne peut plus être modifiée.", "en": "This entry can no longer be changed." },
    "flash.mail_year_locked": { "ar": "يجب أن يبقى التاريخ في سنة الرقم.", "fr": "La date doit rester dans l'année du numéro.", "en": "The date must stay in the year of its number." },
    "flash.mail_cannot_cancel": { "ar": "لا يمكن إلغاء هذا التسجيل.", "fr": "Cette entrée ne peut pas être annulée.", "en": "This entry cannot be cancelled." },
    "activity.action.mail_entry_created": { "ar": "تسجيل مراسلة", "fr": "Courrier enregistré", "en": "Mail entry created" },
    "activity.action.mail_entry_updated": { "ar": "تعديل مراسلة", "fr": "Courrier modifié", "en": "Mail entry edited" },
    "activity.action.mail_entry_cancelled": { "ar": "إلغاء مراسلة", "fr": "Courrier annulé", "en": "Mail entry cancelled" },
    "activity.action.mail_entry_registered": { "ar": "تسجيل وثيقة مطبوعة في سجل البريد", "fr": "Document imprimé inscrit au registre", "en": "Printed document registered" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t3-keys.json`
Expected: `✓ 8 key(s) written to ar/en/fr`

- [ ] **Step 8: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRegisterTest.php`
Expected: PASS (12 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/ActivityRecorderTest.php`
Expected: PASS (135 codes, each in exactly one area).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/ActivityReportTest.php`
Expected: PASS (no new area).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Exceptions/MailEntryException.php app/Services/Secretariat/MailRegister.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php tests/Unit/ActivityRecorderTest.php tests/Feature/MailRegisterTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(mail): MailRegister service — create, edit, scan, cancel, register from a print

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Controller, form request, routes, scan, source description

**Files:**
- Create: `app/Http/Requests/Mail/SaveMailEntryRequest.php`
- Create: `app/Services/Secretariat/MailSources.php` (keys, links and labels; Task 6 adds the print-side resolution)
- Create: `app/Http/Controllers/MailEntryController.php`
- Modify: `routes/web.php` (imports near line 60; routes after `purchase-orders.print`, line 304)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MailEntryHttpTest.php`

**Interfaces:**
- Consumes: `MailRegister` (Task 3), `MailEntry` (Task 2), `PrivateFileStorage::exists(?string): bool`, `::serve(string $path, string $name)`.
- Produces routes: `mail.index` GET `/mail` (query `direction` = `out`|`in`, default `out`; `year`; `q`), `mail.create` GET `/mail/create` (query `direction`), `mail.store` POST `/mail` (multipart, `scan` optional), `mail.show` GET `/mail/{mailEntry}`, `mail.edit` GET `/mail/{mailEntry}/edit`, `mail.update` PUT `/mail/{mailEntry}` (the form posts with `_method=put` for the file), `mail.cancel` POST `/mail/{mailEntry}/cancel`, `mail.scan` GET `/mail/{mailEntry}/scan`. No destroy.
- `MailSources` (container-resolved, `final`): constants `GAME_KEY = 'convocation'`, `PURCHASE_ORDER_KEY = 'order'`, `LINK_MODULES`; `static letterKey(int $playerId, ?int $branchId, string $from, string $to): string` (`player:{id}|branch:{id or 0}|{from}|{to}`); `static url(string $type, int $id, User $viewer): ?string`; `describe(MailEntry $entry, User $viewer): ?array{type: string, label: string, url: ?string}`.
- Inertia props (Task 7 builds the pages on them):
  - `Mail/Index`: `entries` (paginator of `{id, reference, entry_date, correspondent, subject, status, is_legacy, has_scan, source_type, source_url}`), `filters` (`{direction, year?, q?}`, `direction` always set), `years` (list of int, newest first, for the shown direction).
  - `Mail/Create`: `direction` (`out`|`in`), `correspondents` (`{out: list<string>, in: list<string>}` — same direction first).
  - `Mail/Edit`: `entry` (model array: fields + `reference`, `has_scan`, `scan_name`), `editable` (list of field names), `correspondents`.
  - `Mail/Show`: `entry` (model array), `source` (`{type, label, url}` or null), `can` `{edit, cancel, print}`.
- Flash keys `flash.mail_created`, `_updated`, `_cancelled`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailEntryHttpTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivitySubjectLink;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MailEntryHttpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'direction' => 'out', 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue', 'address' => '1 rue Test',
            'subject' => 'Engagement saison', 'body' => "Monsieur,\nBonjour.", 'notes' => '',
        ], $extra);
    }

    private function entry(array $extra = [], ?UploadedFile $scan = null): MailEntry
    {
        return app(MailRegister::class)->create($this->payload($extra), User::factory()->admin()->create(), $scan);
    }

    #[Test]
    public function an_editor_registers_a_letter_with_its_scan_and_lands_on_it(): void
    {
        $user = $this->userWith(['mail' => ['view', 'add']]);

        $response = $this->actingAs($user)->post(route('mail.store'), $this->payload([
            'scan' => UploadedFile::fake()->create('lettre.pdf', 80, 'application/pdf'),
        ]));

        $entry = MailEntry::sole();
        $response->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_created');
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame("Monsieur,\nBonjour.", $entry->body);
        $this->assertNull($entry->notes);
        $this->assertSame('lettre.pdf', $entry->scan_name);
        Storage::disk('local')->assertExists($entry->scan_path);
    }

    #[Test]
    public function the_form_is_validated(): void
    {
        $user = $this->userWith(['mail' => ['view', 'add']]);

        $this->actingAs($user)->post(route('mail.store'), $this->payload([
            'direction' => 'sideways', 'correspondent' => '', 'subject' => '', 'entry_date' => '2026-12-01',
            'scan' => UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'),
        ]))->assertSessionHasErrors(['direction', 'correspondent', 'subject', 'entry_date', 'scan']);

        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->userWith(['mail' => ['view']]));
        $this->get(route('mail.index'))->assertOk();
        $this->get(route('mail.show', $entry))->assertOk();
        $this->get(route('mail.create'))->assertForbidden();
        $this->post(route('mail.store'), $this->payload())->assertForbidden();
        $this->get(route('mail.edit', $entry))->assertForbidden();
        $this->put(route('mail.update', $entry), $this->payload())->assertForbidden();
        $this->post(route('mail.cancel', $entry))->assertForbidden();

        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('mail.index'))->assertForbidden();
        // Entries are never deleted: there is no destroy route.
        $this->assertFalse(Route::has('mail.destroy'));
    }

    #[Test]
    public function cancelling_needs_the_delete_right_and_keeps_the_number(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.cancel', $entry))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view', 'delete']]))->from(route('mail.show', $entry))
            ->post(route('mail.cancel', $entry))
            ->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_cancelled');

        $this->assertSame(MailEntry::CANCELLED, $entry->fresh()->status);
        $this->assertSame('2026/01', $entry->fresh()->reference);
        // A second cancel is a refusal shown as a flash error.
        $this->post(route('mail.cancel', $entry))->assertSessionHas('error', 'flash.mail_cannot_cancel');
    }

    #[Test]
    public function an_update_keeps_direction_and_number_and_replaces_the_scan(): void
    {
        $entry = $this->entry([], UploadedFile::fake()->create('brouillon.pdf', 80, 'application/pdf'));
        $first = $entry->scan_path;

        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.update', $entry), $this->payload([
            '_method' => 'put', 'direction' => 'in', 'subject' => 'Engagement corrigé',
            'scan' => UploadedFile::fake()->create('signee.pdf', 80, 'application/pdf'),
        ]))->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_updated');

        $entry->refresh();
        $this->assertSame('Engagement corrigé', $entry->subject);
        $this->assertSame(MailEntry::OUT, $entry->direction);
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame('signee.pdf', $entry->scan_name);
        Storage::disk('local')->assertMissing($first);
    }

    #[Test]
    public function an_imported_entry_only_takes_notes_and_a_scan(): void
    {
        $old = MailEntry::create([
            'direction' => 'out', 'year' => 2019, 'status' => 'active',
            'is_legacy' => true, 'legacy_reference' => '2019/04', 'legacy_source' => 'corresponds', 'correspondent' => 'Ancien',
        ]);

        // The required fields of a new entry are not asked of it.
        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.update', $old), [
            '_method' => 'put', 'correspondent' => '', 'notes' => 'Original aux archives',
            'scan' => UploadedFile::fake()->create('scan.jpg', 30, 'image/jpeg'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('mail.show', $old));

        $old->refresh();
        $this->assertSame('Ancien', $old->correspondent);
        $this->assertSame('Original aux archives', $old->notes);
        $this->assertTrue($old->has_scan);
    }

    #[Test]
    public function a_cancelled_entry_has_no_edit_page(): void
    {
        $entry = $this->entry();
        app(MailRegister::class)->cancel($entry, User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.edit', $entry))
            ->assertRedirect(route('mail.show', $entry))->assertSessionHas('error', 'flash.mail_locked');
    }

    #[Test]
    public function the_edit_page_lists_what_the_entry_may_still_change(): void
    {
        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_PURCHASE_ORDER, 1, 'order', [
            'correspondent' => 'Test Sport Shop', 'subject' => 'Bon de commande',
        ], User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.id', $entry->id)
                ->where('editable', ['correspondent', 'address', 'subject', 'notes'])
                ->has('correspondents.out', 1));
    }

    #[Test]
    public function the_scan_is_served_behind_the_view_right_only(): void
    {
        $entry = $this->entry([], UploadedFile::fake()->create('lettre.pdf', 80, 'application/pdf'));

        $this->get(route('mail.scan', $entry))->assertRedirect(route('login'));
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('mail.scan', $entry))->assertForbidden();

        $response = $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.scan', $entry))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $this->get(route('mail.scan', $this->entry()))->assertNotFound();
    }

    #[Test]
    public function the_register_lists_one_direction_filters_by_year_and_finds_a_reference(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $register = app(MailRegister::class);
        $register->create($this->payload(['correspondent' => 'Test Ligue']), $admin);                              // 2026/01
        $register->create($this->payload(['correspondent' => 'Test Mairie', 'subject' => 'Salle']), $admin);       // 2026/02
        $register->create($this->payload(['entry_date' => '2025-06-01', 'correspondent' => 'Test Wilaya']), $admin); // 2025/01
        $register->create(['direction' => 'in', 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction', 'subject' => 'Invitation'], $admin);

        $this->actingAs($admin)->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.direction', 'out')
                ->has('entries.data', 3)
                ->where('entries.data.0.reference', '2026/02')
                ->where('years', [2026, 2025]));
        $this->get(route('mail.index', ['direction' => 'in']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.reference', 'A-2026/01'));
        $this->get(route('mail.index', ['year' => 2025]))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.correspondent', 'Test Wilaya'));
        $this->get(route('mail.index', ['q' => 'Mairie']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.reference', '2026/02'));
        // "2026/1" is entry 1 of 2026; the incoming mark may be typed too.
        $this->get(route('mail.index', ['q' => '2026/1']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.correspondent', 'Test Ligue'));
        $this->get(route('mail.index', ['direction' => 'in', 'q' => 'A-2026/01']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    #[Test]
    public function the_form_suggests_earlier_correspondents_same_direction_first(): void
    {
        $admin = User::factory()->admin()->create();
        $register = app(MailRegister::class);
        $register->create($this->payload(['correspondent' => 'Zeta Ligue']), $admin);
        $register->create($this->payload(['correspondent' => 'Zeta Ligue']), $admin);
        $register->create(['direction' => 'in', 'entry_date' => '2026-11-26', 'correspondent' => 'Alpha Mairie', 'subject' => 'Invitation'], $admin);

        $this->actingAs($admin)->get(route('mail.create', ['direction' => 'in']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('direction', 'in')
                ->where('correspondents.out', ['Zeta Ligue', 'Alpha Mairie'])
                ->where('correspondents.in', ['Alpha Mairie', 'Zeta Ligue']));
    }

    #[Test]
    public function the_entry_page_names_and_links_its_document_only_for_who_may_view_it(): void
    {
        $game = $this->game($this->team());
        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_GAME, $game->id, 'convocation', [
            'correspondent' => 'Rivals FC', 'subject' => 'Convocation',
        ], User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.reference', '2026/01')
                ->where('source.type', 'game_convocation')
                ->where('source.label', 'Rivals FC · 17/10/2026')
                ->where('source.url', route('games.show', $game))
                ->where('can.edit', true)->where('can.cancel', true)->where('can.print', false));

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.label', '#'.$game->id)->where('source.url', null)
                ->where('can.edit', false)->where('can.cancel', false));
        $this->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.source_type', 'game_convocation')
                ->where('entries.data.0.source_url', null));
    }

    #[Test]
    public function the_activity_pages_link_an_entry_for_mail_viewers_only(): void
    {
        $entry = $this->entry();
        $log = ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CREATED)->sole();

        $this->assertSame(route('mail.show', $entry), ActivitySubjectLink::for($log, User::factory()->admin()->create())['url']);

        $neutral = ActivitySubjectLink::for($log, $this->userWith(['players' => ['view']]));
        $this->assertNull($neutral['url']);
        $this->assertTrue($neutral['neutral']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryHttpTest.php`
Expected: FAIL (`Route [mail.store] not defined`).

- [ ] **Step 3: Form request**

Create `app/Http/Requests/Mail/SaveMailEntryRequest.php`:

```php
<?php

namespace App\Http\Requests\Mail;

use App\Models\MailEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A new entry (every field; MailRegister keeps those of its direction), or an
 * existing one: only the fields it may still change (editableFields()) and
 * its scan — so an imported letter is never asked for the correspondent it
 * lacks, and a cancelled one validates nothing but is refused by the service.
 */
class SaveMailEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'direction' => ['required', Rule::in(MailEntry::DIRECTIONS)],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'correspondent' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'sender_reference' => ['nullable', 'string', 'max:100'],
            'received_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // One scan per entry: a PDF or a picture, replaced by the next upload.
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
        $entry = $this->route('mailEntry');

        return $entry instanceof MailEntry
            ? Arr::only($rules, [...$entry->editableFields(), 'scan'])
            : $rules;
    }
}
```

- [ ] **Step 4: Source keys, links and labels**

Create `app/Services/Secretariat/MailSources.php`:

```php
<?php

namespace App\Services\Secretariat;

use App\Models\Game;
use App\Models\MailEntry;
use App\Models\Player;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * The printed documents a mail entry can come from — parent (absence)
 * letter, match convocation, purchase order: their source keys, and how the
 * register names and links them. A label or link is shown only to who may
 * view the document's module (the player page, the match, the order).
 */
final class MailSources
{
    /** One convocation per game. */
    public const GAME_KEY = 'convocation';

    /** One registration per purchase order. */
    public const PURCHASE_ORDER_KEY = 'order';

    /** The module guarding the page an entry links to. */
    public const LINK_MODULES = [
        MailEntry::SOURCE_ABSENCE_LETTER => 'players',
        MailEntry::SOURCE_GAME => 'games',
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
    ];

    /** A parent letter is about one player, for one period, in one branch tab (0 = none). */
    public static function letterKey(int $playerId, ?int $branchId, string $from, string $to): string
    {
        return sprintf('player:%d|branch:%d|%s|%s', $playerId, $branchId ?? 0, $from, $to);
    }

    /** The page of an entry's document, when the viewer may open it. */
    public static function url(string $type, int $id, User $viewer): ?string
    {
        $module = self::LINK_MODULES[$type] ?? null;
        if ($module === null || ! $viewer->hasPermission($module, 'view')) {
            return null;
        }

        return match ($type) {
            MailEntry::SOURCE_ABSENCE_LETTER => route('players.show', $id),
            MailEntry::SOURCE_GAME => route('games.show', $id),
            MailEntry::SOURCE_PURCHASE_ORDER => route('purchase-orders.show', $id),
        };
    }

    /**
     * How the entry page shows its document: its type, a label (the player and
     * the letter's period, the opponent and date, the order's number) and a
     * link — or "#id" without a link when the viewer may not view that module
     * or the document is gone.
     *
     * @return array{type: string, label: string, url: ?string}|null
     */
    public function describe(MailEntry $entry, User $viewer): ?array
    {
        if (! $entry->isFromSource()) {
            return null;
        }
        $id = (int) $entry->source_id;
        $url = self::url($entry->source_type, $id, $viewer);
        $label = $url === null ? null : match ($entry->source_type) {
            MailEntry::SOURCE_ABSENCE_LETTER => self::letterLabel($id, (string) $entry->source_key),
            MailEntry::SOURCE_GAME => self::gameLabel($id),
            MailEntry::SOURCE_PURCHASE_ORDER => PurchaseOrder::find($id)?->reference,
            default => null,
        };

        return ['type' => $entry->source_type, 'label' => $label ?? '#'.$id, 'url' => $label === null ? null : $url];
    }

    private static function letterLabel(int $playerId, string $key): ?string
    {
        $player = Player::find($playerId);
        if ($player === null) {
            return null;
        }
        // player:{id}|branch:{id}|{from}|{to}
        $parts = explode('|', $key);

        return count($parts) === 4
            ? $player->fullname.' · '.self::day($parts[2]).' – '.self::day($parts[3])
            : $player->fullname;
    }

    private static function gameLabel(int $gameId): ?string
    {
        $game = Game::with('opponent:id,name')->find($gameId);

        return $game === null ? null : ($game->opponent?->name ?? '—').' · '.self::day((string) $game->date);
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as the prints show dates. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }
}
```

- [ ] **Step 5: Controller**

Create `app/Http/Controllers/MailEntryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mail\SaveMailEntryRequest;
use App\Models\MailEntry;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Secretariat → Mail register (سجل البريد): outgoing / incoming lists, form, cancel, scan. */
class MailEntryController extends Controller
{
    /** How many earlier correspondents the form suggests. */
    private const SUGGESTIONS = 300;

    public function __construct(
        private readonly MailRegister $register,
        private readonly MailSources $sources,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(MailEntry::DIRECTIONS)],
            'year' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $direction = $filters['direction'] ?? MailEntry::OUT;
        $user = $request->user();

        $entries = MailEntry::query()
            ->where('direction', $direction)
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text) {
                $like = '%'.$text.'%';
                $q->where('correspondent', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhere('sender_reference', 'like', $like);
                // "2026/3", "و 2026/03", "IN-2026/03" find entry 3 of 2026; a bare "3" finds number 3 of any year.
                if (preg_match('#(\d{4})\s*/\s*0*(\d+)\s*$#u', $text, $m)) {
                    $q->orWhere(fn ($q) => $q->where('year', (int) $m[1])->where('number', (int) $m[2]));
                } elseif (preg_match('/^\s*0*(\d+)\s*$/', $text, $m)) {
                    $q->orWhere('number', (int) $m[1]);
                }
            }))
            ->orderByDesc('year')->orderByDesc('number')->orderByDesc('legacy_reference')
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (MailEntry $entry) => [
                'id' => $entry->id,
                'reference' => $entry->reference,
                'entry_date' => $entry->entry_date?->format('Y-m-d'),
                'correspondent' => $entry->correspondent,
                'subject' => $entry->subject,
                'status' => $entry->status,
                'is_legacy' => $entry->is_legacy,
                'has_scan' => $entry->has_scan,
                'source_type' => $entry->source_type,
                // A link from the id alone: no query per row.
                'source_url' => $entry->source_type ? MailSources::url($entry->source_type, (int) $entry->source_id, $user) : null,
            ]);

        return Inertia::render('Mail/Index', [
            'entries' => $entries,
            'filters' => [...$filters, 'direction' => $direction],
            'years' => MailEntry::query()->where('direction', $direction)->whereNotNull('year')
                ->distinct()->orderByDesc('year')->pluck('year'),
        ]);
    }

    public function create(Request $request): Response
    {
        $asked = $request->query('direction');

        return Inertia::render('Mail/Create', [
            'direction' => in_array($asked, MailEntry::DIRECTIONS, true) ? $asked : MailEntry::OUT,
            'correspondents' => $this->correspondents(),
        ]);
    }

    public function store(SaveMailEntryRequest $request): RedirectResponse
    {
        $entry = $this->register->create(Arr::except($request->validated(), ['scan']), $request->user(), $request->file('scan'));

        return redirect()->route('mail.show', $entry)->with('success', 'flash.mail_created');
    }

    public function show(Request $request, MailEntry $mailEntry): Response
    {
        $user = $request->user();

        return Inertia::render('Mail/Show', [
            'entry' => $mailEntry,
            'source' => $this->sources->describe($mailEntry, $user),
            'can' => [
                'edit' => $user->hasPermission('mail', 'edit') && $mailEntry->editableFields() !== [],
                // Imported letters are history: never cancelled.
                'cancel' => $user->hasPermission('mail', 'delete')
                    && $mailEntry->status === MailEntry::ACTIVE && ! $mailEntry->is_legacy,
                'print' => $mailEntry->isPrintable(),
            ],
        ]);
    }

    public function edit(MailEntry $mailEntry): Response|RedirectResponse
    {
        if ($mailEntry->editableFields() === []) {
            return redirect()->route('mail.show', $mailEntry)->with('error', 'flash.mail_locked');
        }

        return Inertia::render('Mail/Edit', [
            'entry' => $mailEntry,
            'editable' => $mailEntry->editableFields(),
            'correspondents' => $this->correspondents(),
        ]);
    }

    public function update(SaveMailEntryRequest $request, MailEntry $mailEntry): RedirectResponse
    {
        $this->register->update($mailEntry, Arr::except($request->validated(), ['scan']), $request->user(), $request->file('scan'));

        return redirect()->route('mail.show', $mailEntry)->with('success', 'flash.mail_updated');
    }

    public function cancel(Request $request, MailEntry $mailEntry): RedirectResponse
    {
        $this->register->cancel($mailEntry, $request->user());

        return back()->with('success', 'flash.mail_cancelled');
    }

    /** The scanned letter — private: this route (auth + mail/view) is the only way to read it. */
    public function scan(MailEntry $mailEntry, PrivateFileStorage $files): StreamedResponse
    {
        $path = $mailEntry->scan_path;
        abort_unless($path && $files->exists($path), 404);

        return $files->serve($path, $mailEntry->scan_name ?: basename($path));
    }

    /**
     * Earlier correspondents for the form's suggestions, per direction: the
     * same direction's names first, then the other's, each name once.
     *
     * @return array{out: list<string>, in: list<string>}
     */
    private function correspondents(): array
    {
        $rows = MailEntry::query()
            ->whereNotNull('correspondent')->where('correspondent', '!=', '')
            ->select(['correspondent', 'direction'])->distinct()
            ->toBase()->get();
        $list = fn (string $direction) => $rows
            ->sortBy([
                fn ($a, $b) => ($a->direction === $direction ? 0 : 1) <=> ($b->direction === $direction ? 0 : 1),
                fn ($a, $b) => strcmp($a->correspondent, $b->correspondent),
            ])
            ->pluck('correspondent')->unique()->take(self::SUGGESTIONS)->values()->all();

        return ['out' => $list(MailEntry::OUT), 'in' => $list(MailEntry::IN)];
    }
}
```

- [ ] **Step 6: Routes**

In `routes/web.php` replace

```php
use App\Http\Controllers\LiteraryReportController;
use App\Http\Controllers\MemberJobController;
```

with

```php
use App\Http\Controllers\LiteraryReportController;
use App\Http\Controllers\MailEntryController;
use App\Http\Controllers\MemberJobController;
```

and replace

```php
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderPrintController::class, 'show'])->name('purchase-orders.print');
```

with

```php
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderPrintController::class, 'show'])->name('purchase-orders.print');

    // Secretariat → mail register (سجل البريد): never deleted — cancelled instead, so the routes have no destroy.
    Route::resource('mail', MailEntryController::class)->except(['destroy'])->parameters(['mail' => 'mailEntry']);
    Route::post('/mail/{mailEntry}/cancel', [MailEntryController::class, 'cancel'])->name('mail.cancel');
    // The scanned letter — private: this (auth + mail/view) is the only way to read it.
    Route::get('/mail/{mailEntry}/scan', [MailEntryController::class, 'scan'])->name('mail.scan');
```

- [ ] **Step 7: Flash translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t4-keys.json`:

```json
{
    "flash.mail_created": { "ar": "تم تسجيل المراسلة.", "fr": "Entrée enregistrée.", "en": "Entry saved." },
    "flash.mail_updated": { "ar": "تم تعديل المراسلة.", "fr": "Entrée modifiée.", "en": "Entry updated." },
    "flash.mail_cancelled": { "ar": "أُلغيت المراسلة، ويبقى رقمها محجوزًا.", "fr": "Entrée annulée ; son numéro reste pris.", "en": "Entry cancelled; its number stays taken." }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t4-keys.json`
Expected: `✓ 3 key(s) written to ar/en/fr`

- [ ] **Step 8: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailEntryHttpTest.php`
Expected: PASS (13 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailRightsTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Mail/SaveMailEntryRequest.php app/Services/Secretariat/MailSources.php app/Http/Controllers/MailEntryController.php routes/web.php tests/Feature/MailEntryHttpTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(mail): mail register routes, form request, scan and source links

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: The printed outgoing letter (President + Secretary General)

**Files:**
- Modify: `app/Services/Pdf/BoardSignatories.php` (whole file, 27 lines)
- Create: `app/Http/Controllers/MailEntryPrintController.php`
- Create: `resources/views/pdf/mail-letter.blade.php`
- Modify: `routes/web.php` (import after `MailEntryController`; route after `mail.scan`)
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MailLetterPrintTest.php`

**Interfaces:**
- Consumes: `MailEntry::isPrintable()`, `reference` (Task 2), `MailRegister::create()` (Task 3), `ClubHeader::data()`, `PdfService::stream()`, `PdfText::ltrIfLatin()`, partial `pdf.partials.header`, `pdf.partials.sign-line`.
- Produces:
  - `BoardSignatories::names(): array{president: ?string, treasurer: ?string, secretary: ?string}` (board role name `secretary`; existing callers read `president` / `treasurer` only).
  - Route `mail.print` GET `/mail/{mailEntry}/print` (404 unless an active outgoing entry with a text); file name `courrier-YYYY-NN.pdf`.
  - Label keys `mreg.pdf.ref` (also printed by Task 6 on the three prints), `mreg.pdf.date`, `mreg.pdf.to`, `mreg.pdf.address`, `mreg.pdf.subject`, `mreg.pdf.secretary`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailLetterPrintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MailEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailLetterPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function letter(array $extra = []): MailEntry
    {
        return app(MailRegister::class)->create(array_merge([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue de Wilaya',
            'address' => '12 rue des Sports, Testville', 'subject' => 'Engagement de nos équipes',
            'body' => "Monsieur le Président,\nNous vous adressons la liste de nos équipes.",
        ], $extra), User::factory()->admin()->create());
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function member(string $name, string $role, string $status = 'active'): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => $name, 'role' => $role, 'status' => $status]);
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_letter_is_a_real_pdf_in_arabic(): void
    {
        $response = $this->actingAs($this->user('ar'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_french_letter_has_reference_date_recipient_subject_text_and_both_signatures(): void
    {
        $this->member('Président Test', 'president');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Trésorier Test', 'treasurer');
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('courrier-2026-01.pdf', $seen['filename']);
        foreach ([
            UiLang::get('mreg.pdf.ref', null, 'fr'), '<bdo dir="ltr">2026/01</bdo>', '<bdo dir="ltr">26/11/2026</bdo>',
            UiLang::get('mreg.pdf.to', null, 'fr'), 'Test Ligue de Wilaya', '12 rue des Sports, Testville',
            UiLang::get('mreg.pdf.subject', null, 'fr'), 'Engagement de nos équipes',
            'Monsieur le Président,</bdo><br /><bdo dir="ltr">Nous vous adressons la liste de nos équipes.',
            UiLang::get('mreg.pdf.secretary', null, 'fr'), UiLang::get('po.pdf.president', null, 'fr'),
            'Secrétaire Test', 'Président Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        // The treasurer does not sign letters.
        $this->assertStringNotContainsString('Trésorier Test', $seen['html']);
    }

    #[Test]
    public function the_arabic_letter_keeps_latin_lines_left_to_right(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('ar'))->get(route('mail.print', $this->letter([
            'correspondent' => 'الرابطة الولائية', 'body' => "السيد الرئيس،\nRef. FAF 2026",
        ])))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('<body dir="rtl">', $seen['html']);
        $this->assertStringContainsString('السيد الرئيس،<br /><bdo dir="ltr">Ref. FAF 2026</bdo>', $seen['html']);
        $this->assertStringContainsString('الرابطة الولائية', $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
    }

    #[Test]
    public function a_role_nobody_holds_prints_a_blank_line_to_sign(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertStringContainsString(UiLang::get('mreg.pdf.secretary', null, 'fr'), $seen['html']);
        $this->assertSame(['president' => null, 'treasurer' => null, 'secretary' => null], BoardSignatories::names());
    }

    #[Test]
    public function the_signatories_include_the_active_secretary_general_of_the_current_term(): void
    {
        $this->member('Ancien Secrétaire', 'secretary', 'former');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Président Test', 'president');

        $names = BoardSignatories::names();

        $this->assertSame('Secrétaire Test', $names['secretary']);
        $this->assertSame('Président Test', $names['president']);
        $this->assertNull($names['treasurer']);
    }

    #[Test]
    public function only_an_active_outgoing_letter_with_a_text_prints(): void
    {
        $user = $this->user('fr');
        $register = app(MailRegister::class);
        $incoming = $register->create([
            'direction' => MailEntry::IN, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction', 'subject' => 'Invitation',
        ], $user);
        $noText = $this->letter(['body' => null]);
        $cancelled = $register->cancel($this->letter(), $user);

        $this->actingAs($user)->get(route('mail.print', $incoming))->assertNotFound();
        $this->get(route('mail.print', $noText))->assertNotFound();
        $this->get(route('mail.print', $cancelled))->assertNotFound();
    }

    #[Test]
    public function a_view_only_user_prints(): void
    {
        $letter = $this->letter();
        $this->spyPdf();
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => ['mail' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('mail.print', $letter))->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailLetterPrintTest.php`
Expected: FAIL (`Route [mail.print] not defined`).

- [ ] **Step 3: The secretary general signs letters**

Replace the whole content of `app/Services/Pdf/BoardSignatories.php` with:

```php
<?php

namespace App\Services\Pdf;

use App\Models\BoardMember;

/**
 * The names for the signature blocks: president and treasurer (payment and
 * purchase orders), president and secretary general (letters). Current term,
 * active members; null when nobody holds the role (a blank line to sign).
 */
final class BoardSignatories
{
    /** @return array{president: ?string, treasurer: ?string, secretary: ?string} */
    public static function names(): array
    {
        $members = BoardMember::query()->withPlayer()->inCurrentTerm()
            ->where('status', 'active')
            ->whereIn('role', ['president', 'treasurer', 'secretary'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('role');

        return [
            'president' => $members->get('president')?->first()?->name,
            'treasurer' => $members->get('treasurer')?->first()?->name,
            'secretary' => $members->get('secretary')?->first()?->name,
        ];
    }
}
```

- [ ] **Step 4: Print controller**

Create `app/Http/Controllers/MailEntryPrintController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MailEntry;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use Symfony\Component\HttpFoundation\Response;

/** An outgoing letter of the mail register on the club letterhead (A4, app language). */
class MailEntryPrintController extends Controller
{
    public function __construct(private readonly PdfService $pdf) {}

    public function __invoke(MailEntry $mailEntry): Response
    {
        // Only an active outgoing entry with a text is a letter to print.
        abort_unless($mailEntry->isPrintable(), 404);

        $locale = app()->getLocale();
        $html = view('pdf.mail-letter', [
            'club' => ClubHeader::data(),
            'rtl' => $locale === 'ar',
            'entry' => $mailEntry,
            'signatories' => BoardSignatories::names(),
        ])->render();

        return $this->pdf->stream($html, 'courrier-'.str_replace('/', '-', $mailEntry->reference).'.pdf', $locale === 'ar');
    }
}
```

- [ ] **Step 5: The template**

Create `resources/views/pdf/mail-letter.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    // Free text: Latin lines keep their own left-to-right order inside the RTL page; line breaks become <br />.
    $T = fn (?string $v) => \App\Support\PdfText::ltrIfLatin($v);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.to { border-collapse: collapse; margin: 4px 0 14px; }
        table.to td { padding: 2px 8px 2px 0; vertical-align: top; }
        .label { color: #64748b; }
        .subject { font-weight: bold; margin: 8px 0 14px; }
        .body { margin: 0 0 18px; }
    </style>
</head>
<body dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @include('pdf.partials.header')

    <table class="meta">
        <tr>
            <td><span class="label">{{ $L('mreg.pdf.ref') }}</span> <bdo dir="ltr">{{ $entry->reference }}</bdo></td>
            <td style="text-align: {{ $rtl ? 'left' : 'right' }};"><span class="label">{{ $L('mreg.pdf.date') }}</span> <bdo dir="ltr">{{ $entry->entry_date->format('d/m/Y') }}</bdo></td>
        </tr>
    </table>

    <table class="to">
        <tr><td class="label">{{ $L('mreg.pdf.to') }}</td><td>{{ $T($entry->correspondent) }}</td></tr>
        @if ($entry->address)
            <tr><td class="label">{{ $L('mreg.pdf.address') }}</td><td>{{ $T($entry->address) }}</td></tr>
        @endif
    </table>

    <div class="subject">{{ $L('mreg.pdf.subject') }} {{ $T($entry->subject) }}</div>

    <div class="body">{{ $T($entry->body) }}</div>

    {{-- President + Secretary General (blank line when nobody holds the role). --}}
    <table style="width:100%; margin-top:30px; text-align:center; font-size:12px;">
        <tr>
            <td style="width:50%; vertical-align:top;">
                <div style="font-weight:bold;">{{ $L('mreg.pdf.secretary') }}</div>
                <div style="margin-top:4px;">{{ $signatories['secretary'] ?? '' }}</div>
                @include('pdf.partials.sign-line')
            </td>
            <td style="width:50%; vertical-align:top;">
                <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
                <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
                @include('pdf.partials.sign-line')
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 6: Route**

In `routes/web.php` replace

```php
use App\Http\Controllers\MailEntryController;
```

with

```php
use App\Http\Controllers\MailEntryController;
use App\Http\Controllers\MailEntryPrintController;
```

and replace

```php
    Route::get('/mail/{mailEntry}/scan', [MailEntryController::class, 'scan'])->name('mail.scan');
```

with

```php
    Route::get('/mail/{mailEntry}/scan', [MailEntryController::class, 'scan'])->name('mail.scan');
    // An outgoing entry with a text, printed as a letter on the letterhead.
    Route::get('/mail/{mailEntry}/print', MailEntryPrintController::class)->name('mail.print');
```

- [ ] **Step 7: PDF labels**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t5-keys.json`:

```json
{
    "mreg.pdf.ref": { "ar": "المرجع:", "fr": "Réf. :", "en": "Ref:" },
    "mreg.pdf.date": { "ar": "التاريخ:", "fr": "Date :", "en": "Date:" },
    "mreg.pdf.to": { "ar": "إلى السيد(ة):", "fr": "À :", "en": "To:" },
    "mreg.pdf.address": { "ar": "العنوان:", "fr": "Adresse :", "en": "Address:" },
    "mreg.pdf.subject": { "ar": "الموضوع:", "fr": "Objet :", "en": "Subject:" },
    "mreg.pdf.secretary": { "ar": "الكاتب العام", "fr": "Le secrétaire général", "en": "The Secretary General" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t5-keys.json`
Expected: `✓ 6 key(s) written to ar/en/fr`

- [ ] **Step 8: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailLetterPrintTest.php`
Expected: PASS (7 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPrintTest.php`
Expected: PASS (the signatures partial still reads president / treasurer).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PaymentOrderPrintTest.php`
Expected: PASS.
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Pdf/BoardSignatories.php app/Http/Controllers/MailEntryPrintController.php resources/views/pdf/mail-letter.blade.php routes/web.php tests/Feature/MailLetterPrintTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(mail): outgoing letter PDF signed by the president and the secretary general

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: Register from a print (lookup + register routes) and «Ref: …» on the three prints

**Files:**
- Create: `app/Services/Attendance/PlayerBranchTabs.php` (the card's branch-tab rule, moved out of the controller unchanged)
- Modify: `app/Http/Controllers/AttendancePlayerController.php` (imports; `letter()` lines 98-146; `branchTabs()` lines 206-241)
- Modify: `app/Services/Secretariat/MailSources.php` (adds `URL_TYPES`, `PRINT_MODULES`, `resolve()`)
- Create: `app/Http/Controllers/MailSourceController.php`
- Modify: `app/Http/Controllers/GameCallUpController.php` (imports; `convocation()` lines 146-187)
- Modify: `app/Http/Controllers/PurchaseOrderPrintController.php` (imports, constructor, view data)
- Modify: `resources/views/pdf/attendance-letter.blade.php:30-37`, `resources/views/pdf/game-convocation.blade.php:31-32`, `resources/views/pdf/purchase-order.blade.php:37`
- Modify: `routes/web.php` (import; two routes before `Route::resource('mail', …)`)
- Test: `tests/Feature/MailSourceTest.php`

**Interfaces:**
- Consumes: `MailRegister::register()` / `::find()` (Task 3), `MailSources::letterKey()`, `GAME_KEY`, `PURCHASE_ORDER_KEY` (Task 4), `mreg.pdf.ref` (Task 5), `ActivityPeriod::fromRequestOrSeason(Request)->toArray()` (`from`, `to`), `RegistrationDisplay::make()->values(Player, ?int)['category_id']`, `AttendanceSettings::letter(array): array{subject, body}`.
- Produces:
  - `PlayerBranchTabs::resolve(Request $request, Player $player): array{0: list<array{id: int, name: string}>, 1: ?int}` (static).
  - `MailSources::URL_TYPES` (`'absence-letter'`, `'game'`, `'purchase-order'` → stored types), `MailSources::PRINT_MODULES` (`attendance`, `games`, `purchase_orders`), `resolve(Request $request, string $urlType, int $id): array{type: string, id: int, key: string, defaults: array{correspondent: ?string, address: ?string, subject: ?string}}` — 403 without the document's print right, 422 for a purchase order that is not sent / received or is imported.
  - Routes `mail.sources.show` GET `/mail/sources/{type}/{id}` → JSON `{entry: {id, reference} | null}`; `mail.sources.store` POST same path → JSON `{entry: {id, reference}, created: bool}`. Both take the PDF's own query string (`branch_id`, `period`, `from`, `to` for a letter). Task 7's `MailPrintButton` calls them.
  - The three PDFs receive `mailReference` (`?string`) and print `«mreg.pdf.ref» <bdo dir="ltr">2026/01</bdo>` when it is set.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailSourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\PlayerEmergencyContact;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MailSourceTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    private const SEPTEMBER = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function french(): User
    {
        return User::factory()->admin()->create(['preferred_lng' => 'fr']);
    }

    private function order(string $status = PurchaseOrder::SENT, int $number = 3): PurchaseOrder
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Test Sport Shop'], ['address' => '12 rue des Sports', 'rib' => '00100000000000001234']);

        return PurchaseOrder::create([
            'year' => 2026, 'number' => $number, 'status' => $status, 'order_date' => '2026-10-15', 'supplier_id' => $supplier->id,
        ]);
    }

    /** @return array<string, mixed> route parameters of a source, plus the PDF's query */
    private function source(string $type, int $id, array $query = []): array
    {
        return ['type' => $type, 'id' => $id, ...$query];
    }

    /**
     * Swaps mPDF for a spy, once per test: every stream() call's HTML lands in
     * the returned list, in order. (A controller that takes PdfService in its
     * constructor is kept by its route between two requests of one test, so a
     * second mock would never be seen.)
     */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->andReturnUsing(function (string $html) use ($seen) {
                $seen[] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function a_convocation_is_looked_up_then_registered_once(): void
    {
        $game = $this->game($this->team());
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'games' => ['view']]));

        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertOk()->assertExactJson(['entry' => null]);
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01')->assertJsonPath('created', true);
        // A reprint reuses the same reference.
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01')->assertJsonPath('created', false);
        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertJsonPath('entry.reference', '2026/01');

        $entry = MailEntry::sole();
        $this->assertSame([MailEntry::OUT, MailEntry::SOURCE_GAME, $game->id, 'convocation'], [$entry->direction, $entry->source_type, $entry->source_id, $entry->source_key]);
        $this->assertSame('2026-10-20', $entry->entry_date->toDateString());
        $this->assertSame('Rivals FC', $entry->correspondent);
        $this->assertStringContainsString(UiLang::get('comp.convocation.title', null, 'fr'), $entry->subject);
        $this->assertStringContainsString('Rivals FC', $entry->subject);
        $this->assertStringContainsString('17/10/2026', $entry->subject);
    }

    #[Test]
    public function a_parent_letter_is_one_entry_per_player_branch_and_period(): void
    {
        $player = $this->player($this->category('U15'));
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'attendance' => ['view']]));

        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01');
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))
            ->assertJsonPath('created', false);
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::SEPTEMBER)))
            ->assertJsonPath('entry.reference', '2026/02');

        $october = MailEntry::where('source_key', "player:{$player->id}|branch:0|2026-10-01|2026-10-31")->sole();
        $this->assertSame(MailEntry::SOURCE_ABSENCE_LETTER, $october->source_type);
        $this->assertSame($player->id, $october->source_id);
        // The letter's own recipient and subject (no emergency contact: "parent / guardian of").
        $this->assertSame('Parent / tuteur de '.$player->fullname, $october->correspondent);
        $this->assertSame('Assiduité de '.$player->fullname.' aux entraînements', $october->subject);
    }

    #[Test]
    public function the_first_emergency_contact_is_the_letter_s_correspondent(): void
    {
        $player = $this->player($this->category('U15'));
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim Test', 'relationship' => 'father']);
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Salma Test', 'relationship' => 'mother']);

        $this->actingAs($this->french())->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))->assertOk();

        $this->assertSame('Karim Test', MailEntry::sole()->correspondent);
    }

    #[Test]
    public function a_sent_purchase_order_registers_to_its_supplier_and_a_draft_cannot(): void
    {
        $order = $this->order();
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'purchase_orders' => ['view']]));

        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $order->id)))->assertOk()->assertJsonPath('entry.reference', '2026/01');

        $entry = MailEntry::sole();
        $this->assertSame('Test Sport Shop', $entry->correspondent);
        $this->assertSame('12 rue des Sports', $entry->address);
        $this->assertSame(UiLang::get('pur.title', null, 'fr').' 03/2026', $entry->subject);
        $this->assertSame('order', $entry->source_key);

        $draft = $this->order(PurchaseOrder::DRAFT, 4);
        $this->getJson(route('mail.sources.show', $this->source('purchase-order', $draft->id)))->assertStatus(422);
        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $draft->id)))->assertStatus(422);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function registering_needs_the_mail_add_right_and_the_right_to_print_the_document(): void
    {
        $game = $this->game($this->team());
        // team() already made U15 (firstOrCreate); category() always creates.
        $player = $this->player($this->category('U13'));

        $this->actingAs($this->userWith(['mail' => ['view'], 'games' => ['view']]));
        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view', 'add']]));
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $this->order()->id)))->assertForbidden();

        $this->actingAs($this->french())->postJson('/mail/sources/invoice/1')->assertNotFound();
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_convocation_prints_its_reference_once_registered_and_printing_never_registers(): void
    {
        $game = $this->game($this->team());
        $admin = $this->french();

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('games.convocation', $game))->assertOk();
        $this->assertStringNotContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertSame(0, MailEntry::count());

        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_GAME, $game->id, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $admin);
        $this->get(route('games.convocation', $game))->assertOk();
        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[1]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[1]);

        // A cancelled entry no longer prints.
        app(MailRegister::class)->cancel($entry, $admin);
        $this->get(route('games.convocation', $game))->assertOk();
        $this->assertStringNotContainsString('2026/01', $seen[2]);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_parent_letter_prints_the_reference_of_its_own_period_only(): void
    {
        $player = $this->player($this->category('U15'));
        $admin = $this->french();
        app(MailRegister::class)->register(MailEntry::SOURCE_ABSENCE_LETTER, $player->id, "player:{$player->id}|branch:0|2026-10-01|2026-10-31", [
            'correspondent' => 'Parent', 'subject' => 'Assiduité',
        ], $admin);

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();
        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[0]);

        $this->get(route('attendance.players.letter', ['player' => $player] + self::SEPTEMBER))->assertOk();
        $this->assertStringNotContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[1]);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_purchase_order_prints_its_reference(): void
    {
        $order = $this->order();
        $admin = $this->french();
        app(MailRegister::class)->register(MailEntry::SOURCE_PURCHASE_ORDER, $order->id, 'order', ['correspondent' => 'Test Sport Shop', 'subject' => 'Bon'], $admin);

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">03/2026</bdo>', $seen[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailSourceTest.php`
Expected: FAIL (`Route [mail.sources.show] not defined`).

- [ ] **Step 3: Share the card's branch-tab rule**

Create `app/Services/Attendance/PlayerBranchTabs.php` (the body is `AttendancePlayerController::branchTabs()` moved unchanged):

```php
<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A player attendance card's branch tabs: every branch the player is a
 * member of now or has a mark in (any date, so the tabs do not change with
 * the period), by id; and the tab shown: the asked `branch_id` when it is one
 * of them, else the remembered attendance branch (BranchChoice) when it is,
 * else the first. No tab (a club without branches, or a player in none):
 * null, and the numbers cover every session. Never changes the remembered
 * choice. One query. Shared by the card, its PDFs and the mail register's
 * parent-letter source, so a letter and its register entry name the same tab.
 */
final class PlayerBranchTabs
{
    /** @return array{0: list<array{id: int, name: string}>, 1: ?int} */
    public static function resolve(Request $request, Player $player): array
    {
        $asked = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        $tabs = Branch::query()
            ->whereIn('id', DB::table('branch_player')->select('branch_id')->where('player_id', $player->id))
            ->orWhereIn('id', DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $player->id)
                ->whereNotNull('training_sessions.branch_id')
                ->select('training_sessions.branch_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])
            ->values()
            ->all();
        $ids = array_column($tabs, 'id');

        foreach ([$asked === null ? null : (int) $asked, BranchChoice::remembered($request)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $ids, true)) {
                return [$tabs, $candidate];
            }
        }

        return [$tabs, $ids[0] ?? null];
    }
}
```

In `app/Http/Controllers/AttendancePlayerController.php` replace

```php
    /**
     * The card's branch tabs: every branch the player is a member of now or
     * has a mark in (any date, so the tabs do not change with the period),
     * by id; and the tab shown: the asked `branch_id` when it is one of
     * them, else the remembered attendance branch (BranchChoice) when it is,
     * else the first. No tab (a club without branches, or a player in none):
     * null, and the numbers cover every session. The card never changes the
     * remembered choice. One query.
     *
     * @return array{0: list<array{id: int, name: string}>, 1: ?int}
     */
    private function branchTabs(Request $request, Player $player): array
    {
        $asked = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        $tabs = Branch::query()
            ->whereIn('id', DB::table('branch_player')->select('branch_id')->where('player_id', $player->id))
            ->orWhereIn('id', DB::table('attendances')
                ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
                ->where('attendances.player_id', $player->id)
                ->whereNotNull('training_sessions.branch_id')
                ->select('training_sessions.branch_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])
            ->values()
            ->all();
        $ids = array_column($tabs, 'id');

        foreach ([$asked === null ? null : (int) $asked, BranchChoice::remembered($request)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $ids, true)) {
                return [$tabs, $candidate];
            }
        }

        return [$tabs, $ids[0] ?? null];
    }
```

with

```php
    /**
     * The card's branch tabs and the tab shown (see PlayerBranchTabs, shared
     * with the mail register so a letter and its entry name the same tab).
     *
     * @return array{0: list<array{id: int, name: string}>, 1: ?int}
     */
    private function branchTabs(Request $request, Player $player): array
    {
        return PlayerBranchTabs::resolve($request, $player);
    }
```

In the same file's imports replace

```php
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\BranchChoice;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PreseasonProgress;
```

with

```php
use App\Models\MailEntry;
use App\Models\Player;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\AttendanceStatusCatalog;
use App\Services\Attendance\InjurySpells;
use App\Services\Attendance\PlayerBranchTabs;
use App\Services\Attendance\PreseasonProgress;
```

and replace

```php
use App\Services\Player\RegistrationDisplay;
use App\Support\AttendanceSettings;
use App\Support\Media;
use App\Support\UiLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
```

with

```php
use App\Services\Player\RegistrationDisplay;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use App\Support\AttendanceSettings;
use App\Support\Media;
use App\Support\UiLang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
```

(`DB` and `BranchChoice` were used only by the moved method; `Branch` stays — `tabCategoryId()` uses it.)

- [ ] **Step 4: The parent letter prints its reference**

In `app/Http/Controllers/AttendancePlayerController.php` replace

```php
    public function letter(Request $request, Player $player): Response
    {
```

with

```php
    public function letter(Request $request, Player $player, MailRegister $mail): Response
    {
```

and replace

```php
        $html = view('pdf.attendance-letter', [
            'club' => $club,
            'date' => now()->format('d/m/Y'),
```

with

```php
        $html = view('pdf.attendance-letter', [
            'club' => $club,
            // The mail-register reference of this letter (same player, tab and period), when it has one. Lookup only.
            'mailReference' => $mail->find(MailEntry::SOURCE_ABSENCE_LETTER, $player->id, MailSources::letterKey($player->id, $branchId, $from, $to))?->reference,
            'date' => now()->format('d/m/Y'),
```

In `resources/views/pdf/attendance-letter.blade.php` replace

```blade
    <table class="meta">
        <tr><td class="label">{{ $L('att.letter.date_label') }}</td><td><bdi dir="ltr">{{ $date }}</bdi></td></tr>
```

with

```blade
    <table class="meta">
        @if (!empty($mailReference))
            <tr><td class="label">{{ $L('mreg.pdf.ref') }}</td><td><bdo dir="ltr">{{ $mailReference }}</bdo></td></tr>
        @endif
        <tr><td class="label">{{ $L('att.letter.date_label') }}</td><td><bdi dir="ltr">{{ $date }}</bdi></td></tr>
```

- [ ] **Step 5: The convocation prints its reference**

In `app/Http/Controllers/GameCallUpController.php` replace

```php
use App\Models\GamePlayer;
use App\Models\User;
```

with

```php
use App\Models\GamePlayer;
use App\Models\MailEntry;
use App\Models\User;
```

replace

```php
use App\Services\Pdf\PdfService;
use App\Support\CategoryLabels;
```

with

```php
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use App\Support\CategoryLabels;
```

replace

```php
    public function convocation(Game $game, PdfService $pdf): Response
    {
```

with

```php
    public function convocation(Game $game, PdfService $pdf, MailRegister $mail): Response
    {
```

and replace

```php
        $html = view('pdf.game-convocation', [
            'club' => ClubHeader::data(),
```

with

```php
        $html = view('pdf.game-convocation', [
            'club' => ClubHeader::data(),
            // The game's mail-register reference, when it has one. Lookup only: printing never registers.
            'mailReference' => $mail->find(MailEntry::SOURCE_GAME, $game->id, MailSources::GAME_KEY)?->reference,
```

In `resources/views/pdf/game-convocation.blade.php` replace

```blade
    <table class="info">
        <tr><td class="label">{{ $L('date') }}</td><td>{{ $game['dateLabel'] }}</td></tr>
```

with

```blade
    <table class="info">
        @if (!empty($mailReference))
            <tr><td class="label">{{ $L('mreg.pdf.ref') }}</td><td><bdo dir="ltr">{{ $mailReference }}</bdo></td></tr>
        @endif
        <tr><td class="label">{{ $L('date') }}</td><td>{{ $game['dateLabel'] }}</td></tr>
```

- [ ] **Step 6: The purchase order prints its reference**

In `app/Http/Controllers/PurchaseOrderPrintController.php` replace

```php
use App\Models\PurchaseOrder;
use App\Services\Activity\ActivityAction;
```

with

```php
use App\Models\MailEntry;
use App\Models\PurchaseOrder;
use App\Services\Activity\ActivityAction;
```

replace

```php
use App\Services\Pdf\PdfService;
use App\Support\AmountInWords;
```

with

```php
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use App\Support\AmountInWords;
```

replace

```php
    public function __construct(private readonly PdfService $pdf) {}
```

with

```php
    public function __construct(
        private readonly PdfService $pdf,
        private readonly MailRegister $mail,
    ) {}
```

and replace

```php
            'signatories' => BoardSignatories::names(),
        ])->render();
```

with

```php
            'signatories' => BoardSignatories::names(),
            // The order's mail-register reference, when it has one. Lookup only: printing never registers.
            'mailReference' => $this->mail->find(MailEntry::SOURCE_PURCHASE_ORDER, $purchaseOrder->id, MailSources::PURCHASE_ORDER_KEY)?->reference,
        ])->render();
```

In `resources/views/pdf/purchase-order.blade.php` replace

```blade
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>
```

with

```blade
    <div class="ref">{{ $L('po.number') }} <bdo dir="ltr">{{ $order->reference }}</bdo> — <bdo dir="ltr">{{ $order->order_date->format('d/m/Y') }}</bdo></div>
    @if (!empty($mailReference))
        <div class="ref">{{ $L('mreg.pdf.ref') }} <bdo dir="ltr">{{ $mailReference }}</bdo></div>
    @endif
```

- [ ] **Step 7: Resolve a print button's document**

In `app/Services/Secretariat/MailSources.php` replace

```php
use App\Models\Game;
use App\Models\MailEntry;
use App\Models\Player;
use App\Models\PurchaseOrder;
use App\Models\User;
```

with

```php
use App\Models\Category;
use App\Models\Game;
use App\Models\MailEntry;
use App\Models\Player;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\PlayerBranchTabs;
use App\Services\Pdf\ClubHeader;
use App\Services\Player\RegistrationDisplay;
use App\Support\AttendanceSettings;
use App\Support\CategoryLabels;
use App\Support\UiLang;
use Illuminate\Http\Request;
```

replace

```php
    /** A parent letter is about one player, for one period, in one branch tab (0 = none). */
```

with

```php
    /** The print dialog's URL segment → stored source type. */
    public const URL_TYPES = [
        'absence-letter' => MailEntry::SOURCE_ABSENCE_LETTER,
        'game' => MailEntry::SOURCE_GAME,
        'purchase-order' => MailEntry::SOURCE_PURCHASE_ORDER,
    ];

    /** The module whose view right printing the document needs (its PDF route's right). */
    public const PRINT_MODULES = [
        MailEntry::SOURCE_ABSENCE_LETTER => 'attendance',
        MailEntry::SOURCE_GAME => 'games',
        MailEntry::SOURCE_PURCHASE_ORDER => 'purchase_orders',
    ];

    /**
     * The document behind a print button, for the tick box: its stored type,
     * id and key, and the correspondent / address / subject a new entry
     * starts with (the document's own). 403 without the right to print it,
     * 404 when it does not exist, 422 for a purchase order that is not sent or
     * received, or is imported (the dialog is hidden for those).
     *
     * @return array{type: string, id: int, key: string, defaults: array{correspondent: ?string, address: ?string, subject: ?string}}
     */
    public function resolve(Request $request, string $urlType, int $id): array
    {
        $type = self::URL_TYPES[$urlType] ?? abort(404);
        abort_unless($request->user()->hasPermission(self::PRINT_MODULES[$type], 'view'), 403);

        return match ($type) {
            MailEntry::SOURCE_ABSENCE_LETTER => $this->absenceLetter($request, Player::findOrFail($id)),
            MailEntry::SOURCE_GAME => $this->game(Game::with(['team.category', 'opponent'])->findOrFail($id)),
            MailEntry::SOURCE_PURCHASE_ORDER => $this->purchaseOrder(PurchaseOrder::with('supplier:id,name,address')->findOrFail($id)),
        };
    }

    /**
     * Same tab and period as the letter's PDF (AttendancePlayerController::letter):
     * the dialog sends the PDF's own query string. Recipient and subject as the
     * letter prints them.
     */
    private function absenceLetter(Request $request, Player $player): array
    {
        [, $branchId] = PlayerBranchTabs::resolve($request, $player);
        ['from' => $from, 'to' => $to] = ActivityPeriod::fromRequestOrSeason($request)->toArray();
        $categoryId = RegistrationDisplay::make()->values($player, $branchId)['category_id'];
        $contact = $player->emergencyContacts()->orderBy('id')->value('name');
        $letter = AttendanceSettings::letter([
            'player' => $player->fullname,
            'category' => $categoryId === null ? '—' : (Category::find($categoryId)?->localized_name ?? '—'),
            'period' => self::day($from).' – '.self::day($to),
            'club' => (string) (ClubHeader::data()['name'] ?? ''),
        ]);

        return [
            'type' => MailEntry::SOURCE_ABSENCE_LETTER,
            'id' => $player->id,
            'key' => self::letterKey($player->id, $branchId, $from, $to),
            'defaults' => [
                'correspondent' => $contact ?: strtr(UiLang::get('att.letter.guardian_of'), ['{player}' => $player->fullname]),
                'address' => null,
                'subject' => $letter['subject'],
            ],
        ];
    }

    /** The convocation goes to the opponent's name by default (editable on the entry). */
    private function game(Game $game): array
    {
        $opponent = $game->opponent?->name ?? '—';

        return [
            'type' => MailEntry::SOURCE_GAME,
            'id' => $game->id,
            'key' => self::GAME_KEY,
            'defaults' => [
                'correspondent' => $opponent,
                'address' => null,
                'subject' => UiLang::get('comp.convocation.title').' — '.$game->team->labelWith(CategoryLabels::make())
                    .' / '.$opponent.' — '.self::day((string) $game->date),
            ],
        ];
    }

    private function purchaseOrder(PurchaseOrder $order): array
    {
        abort_unless(in_array($order->status, [PurchaseOrder::SENT, PurchaseOrder::RECEIVED], true) && ! $order->is_legacy, 422);

        return [
            'type' => MailEntry::SOURCE_PURCHASE_ORDER,
            'id' => $order->id,
            'key' => self::PURCHASE_ORDER_KEY,
            'defaults' => [
                'correspondent' => $order->supplier?->name,
                'address' => $order->supplier?->address,
                'subject' => UiLang::get('pur.title').' '.$order->reference,
            ],
        ];
    }

    /** A parent letter is about one player, for one period, in one branch tab (0 = none). */
```

- [ ] **Step 8: Lookup + register controller**

Create `app/Http/Controllers/MailSourceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MailEntry;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The print dialog's two calls (absence letter, convocation, purchase order):
 * show looks the document's active entry up; store registers it (or returns
 * the existing one — a reprint keeps its reference). The PDF routes never
 * write; only store does. Both need mail → add (config/permissions.php) and
 * the right to print the document (MailSources::resolve()).
 */
class MailSourceController extends Controller
{
    public function __construct(
        private readonly MailRegister $register,
        private readonly MailSources $sources,
    ) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $source = $this->sources->resolve($request, $type, $id);
        $entry = $this->register->find($source['type'], $source['id'], $source['key']);

        return response()->json(['entry' => $entry ? self::summary($entry) : null]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $source = $this->sources->resolve($request, $type, $id);
        $entry = $this->register->register($source['type'], $source['id'], $source['key'], $source['defaults'], $request->user());

        return response()->json(['entry' => self::summary($entry), 'created' => $entry->wasRecentlyCreated]);
    }

    /** @return array{id: int, reference: string} */
    private static function summary(MailEntry $entry): array
    {
        return ['id' => $entry->id, 'reference' => $entry->reference];
    }
}
```

- [ ] **Step 9: Routes**

In `routes/web.php` replace

```php
use App\Http\Controllers\MailEntryPrintController;
```

with

```php
use App\Http\Controllers\MailEntryPrintController;
use App\Http\Controllers\MailSourceController;
```

and replace

```php
    // Secretariat → mail register (سجل البريد): never deleted — cancelled instead, so the routes have no destroy.
    Route::resource('mail', MailEntryController::class)->except(['destroy'])->parameters(['mail' => 'mailEntry']);
```

with

```php
    // Secretariat → mail register (سجل البريد): never deleted — cancelled instead, so the routes have no destroy.
    // The print tick box (absence letter, convocation, purchase order): look the document's entry up, or register it.
    Route::get('/mail/sources/{type}/{id}', [MailSourceController::class, 'show'])
        ->whereIn('type', ['absence-letter', 'game', 'purchase-order'])->whereNumber('id')->name('mail.sources.show');
    Route::post('/mail/sources/{type}/{id}', [MailSourceController::class, 'store'])
        ->whereIn('type', ['absence-letter', 'game', 'purchase-order'])->whereNumber('id')->name('mail.sources.store');
    Route::resource('mail', MailEntryController::class)->except(['destroy'])->parameters(['mail' => 'mailEntry']);
```

- [ ] **Step 10: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailSourceTest.php`
Expected: PASS (8 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/AttendanceLetterPdfTest.php`
Expected: PASS (the letter is unchanged without an entry).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/AttendanceBranchPlayerCardTest.php`
Expected: PASS (the moved branch-tab rule behaves the same).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/Registrations/PlayerAttendanceCardRegistrationTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/Competitions/CallUpTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPrintTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Attendance/PlayerBranchTabs.php app/Http/Controllers/AttendancePlayerController.php app/Services/Secretariat/MailSources.php app/Http/Controllers/MailSourceController.php app/Http/Controllers/GameCallUpController.php app/Http/Controllers/PurchaseOrderPrintController.php resources/views/pdf/attendance-letter.blade.php resources/views/pdf/game-convocation.blade.php resources/views/pdf/purchase-order.blade.php routes/web.php tests/Feature/MailSourceTest.php
git commit -F - <<'EOF'
feat(mail): register a print in the mail register; prints show their reference

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 7: Pages, Secretariat menu, and the print dialog on the three prints

**Files:**
- Create: `resources/js/Pages/Mail/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`, `Partials/MailEntryForm.vue`
- Create: `resources/js/Components/MailPrintButton.vue`
- Modify: `resources/js/Composables/useNavigation.js:50-56` (new section after the board)
- Modify: `resources/js/Pages/PurchaseOrders/Show.vue:12, 27-29, 73`
- Modify: `resources/js/Pages/Games/Show.vue:9, 187`
- Modify: `resources/js/Pages/Attendance/Alerts.vue:10, 40, 137`
- Modify: `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue:5, 54, 107`
- Modify: `resources/js/Pages/Players/Partials/AttendanceSection.vue:13, 115, 136`
- Modify: `resources/js/i18n/*.json` (via script)
- Test: `tests/Feature/MailPagesTest.php`

**Interfaces:**
- Consumes: the Inertia props of Task 4 (`Mail/Index`, `Mail/Create`, `Mail/Edit`, `Mail/Show`), routes `mail.*` (Tasks 4-6), JSON of `mail.sources.show` / `mail.sources.store` (Task 6), `useCan().can(module, action)`, `window.axios` (sends the XSRF cookie), `IconButton`, `Modal`, `Checkbox` (`v-model:checked`), `Badge`, `TextInput` (`v-model`, extra attrs such as `list` fall through to the `<input>`), `localToday()`, `useFormatDate().formatDate`.
- Produces: `MailPrintButton` props `href` (PDF URL, required), `source` (`{type: 'absence-letter'|'game'|'purchase-order', id: number, query?: object}` or `null` = no dialog), `ticked` (Boolean, default state of the box), `icon`, `label`, `variant`, `size`, `plain`; other attributes (`data-help`, `class`) go to the button. Users without `mail` → `add`, and a `null` source, print straight away exactly as before.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MailPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\User;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function letter(): MailEntry
    {
        return app(MailRegister::class)->create([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue',
            'subject' => 'Engagement', 'body' => "Monsieur,\nBonjour.",
        ], $this->admin);
    }

    #[Test]
    public function the_register_and_the_create_page_render(): void
    {
        $this->letter();

        $this->actingAs($this->admin)->get(route('mail.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Index')->has('entries.data', 1));
        $this->get(route('mail.create', ['direction' => 'in']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Create')->where('direction', 'in')->has('correspondents.in', 1));
    }

    #[Test]
    public function the_edit_and_show_pages_render(): void
    {
        $entry = $this->letter();

        $this->actingAs($this->admin)->get(route('mail.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Edit')
                ->where('editable', ['entry_date', 'correspondent', 'address', 'subject', 'body', 'notes']));
        $this->get(route('mail.show', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Show')
                ->where('entry.reference', '2026/01')->where('can.print', true)->where('source', null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailPagesTest.php`
Expected: FAIL (Inertia page component file `Mail/Index` does not exist).

- [ ] **Step 3: Secretariat menu**

In `resources/js/Composables/useNavigation.js` replace

```js
                { label: t('tasks'), href: '/board/tasks', icon: 'task', prefix: '/board/tasks', module: 'board' },
            ] },
```

with

```js
                { label: t('tasks'), href: '/board/tasks', icon: 'task', prefix: '/board/tasks', module: 'board' },
            ] },
            // The board's secretariat (الأمانة): mail register now, mission orders in P4.
            { key: 'secretariat', label: t('nav.secretariat'), icon: 'mail', items: [
                { label: t('mail'), href: '/mail', icon: 'mail', prefix: '/mail', module: 'mail' },
            ] },
```

- [ ] **Step 4: Register page**

Create `resources/js/Pages/Mail/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { can } = useCan();
const { formatDate } = useFormatDate();

const props = defineProps({ entries: Object, filters: Object, years: Array });

const DIRECTIONS = ['out', 'in'];
const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };
const direction = props.filters.direction ?? 'out';

// The tab is a link (it resets year and search); year and search reload in place.
const filters = reactive({ year: props.filters.year ?? '', q: props.filters.q ?? '' });
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('mail.index'), {
        direction, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
    }, { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <Head :title="t('mail')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mail') }}</h1>
                <IconButton v-if="can('mail', 'add')" :href="route('mail.create', { direction })" icon="plus" :label="t('mreg.new')" variant="primary" />
            </div>
        </template>

        <div class="space-y-4">
            <nav class="flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800/60">
                <Link v-for="d in DIRECTIONS" :key="d" :href="route('mail.index', { direction: d })"
                    :class="['flex-1 rounded-lg px-3 py-2 text-center text-sm font-semibold', d === direction
                        ? 'bg-white text-primary-700 shadow-sm dark:bg-slate-900 dark:text-primary-300'
                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white']">
                    {{ t(d === 'out' ? 'mreg.outgoing' : 'mreg.incoming') }}
                </Link>
            </nav>

            <div class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 dark:bg-slate-900 dark:ring-slate-800">
                <TextInput v-model="filters.q" class="sm:col-span-2" :placeholder="t('mreg.find_placeholder')" />
                <select v-model="filters.year" class="rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('po.all_years') }}</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('reference') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-start">{{ t(direction === 'out' ? 'mreg.recipient' : 'mreg.sender') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mreg.subject') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mreg.source_label') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="e in entries.data" :key="e.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono">
                                <Link :href="route('mail.show', e.id)" class="text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ e.reference || '—' }}</bdi></Link>
                                <span v-if="e.is_legacy" class="ms-1 rounded bg-slate-100 px-1 font-sans text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('po.legacy') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><bdi v-if="e.entry_date" dir="ltr">{{ formatDate(e.entry_date) }}</bdi><span v-else class="text-slate-400 dark:text-slate-500">—</span></td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ e.correspondent || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ e.subject }}</td>
                            <td class="px-4 py-3 text-xs">
                                <Link v-if="e.source_url" :href="e.source_url" class="text-primary-600 hover:underline dark:text-primary-300">{{ t('mreg.source.' + e.source_type) }}</Link>
                                <span v-else-if="e.source_type" class="text-slate-500 dark:text-slate-400">{{ t('mreg.source.' + e.source_type) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center"><Badge :label="t('mreg.status.' + e.status)" :color="STATUS_COLOR[e.status]" /></td>
                        </tr>
                        <tr v-if="!entries.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="entries.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="link in entries.links" :key="link.label" :href="link.url || ''" v-html="link.label" preserve-state
                    :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-600 dark:text-slate-300', !link.url && 'pointer-events-none opacity-40']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Form partial**

Create `resources/js/Pages/Mail/Partials/MailEntryForm.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const props = defineProps({
    entry: { type: Object, default: null },
    // A new entry's direction (the tab the form was opened from).
    direction: { type: String, default: 'out' },
    // Edit: the fields the entry may still change (MailEntry::editableFields()).
    editable: { type: Array, default: () => [] },
    // Earlier correspondents per direction, the same direction first.
    correspondents: { type: Object, default: () => ({ out: [], in: [] }) },
});
const isEdit = !!props.entry;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    direction: props.entry?.direction ?? props.direction,
    entry_date: day(props.entry?.entry_date) || localToday(),
    correspondent: props.entry?.correspondent ?? '',
    address: props.entry?.address ?? '',
    subject: props.entry?.subject ?? '',
    body: props.entry?.body ?? '',
    sender_reference: props.entry?.sender_reference ?? '',
    received_date: day(props.entry?.received_date),
    notes: props.entry?.notes ?? '',
    scan: null,
});

// A new entry sets everything; an existing one only what the server allows.
const may = (field) => !isEdit || props.editable.includes(field);
const outgoing = computed(() => form.direction === 'out');
const suggestions = computed(() => props.correspondents?.[form.direction] ?? []);
const hint = computed(() => {
    if (!isEdit) return null;
    if (props.entry.is_legacy) return t('mreg.legacy_hint');
    if (props.entry.source_type) return t('mreg.from_print_hint');
    return null;
});
const field = 'mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900';

function submit() {
    form.transform((data) => {
        const sent = { ...data };
        if (isEdit) {
            // Direction and number never change; the file needs a POST spoofed as PUT.
            delete sent.direction;
            sent._method = 'put';
        }
        if (!sent.scan) delete sent.scan;
        return sent;
    }).post(isEdit ? route('mail.update', props.entry.id) : route('mail.store'), { forceFormData: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <p v-if="hint" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ hint }}</p>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <fieldset v-if="!isEdit">
                <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('mreg.direction') }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label v-for="d in ['out', 'in']" :key="d" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.direction" type="radio" :value="d" class="border-slate-300 text-primary-600 focus:ring-primary-500" />
                        {{ t(d === 'out' ? 'mreg.outgoing' : 'mreg.incoming') }}
                    </label>
                </div>
                <InputError :message="form.errors.direction" class="mt-1" />
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('mreg.entry_date')" required />
                    <TextInput v-model="form.entry_date" type="date" required :disabled="!may('entry_date')" class="mt-1 w-full" />
                    <InputError :message="form.errors.entry_date" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t(outgoing ? 'mreg.recipient' : 'mreg.sender')" required />
                    <TextInput v-model="form.correspondent" list="mail-correspondents" maxlength="200" :required="may('correspondent')" :disabled="!may('correspondent')" class="mt-1 w-full" />
                    <datalist id="mail-correspondents">
                        <option v-for="name in suggestions" :key="name" :value="name" />
                    </datalist>
                    <InputError :message="form.errors.correspondent" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('address')" />
                    <TextInput v-model="form.address" maxlength="255" :disabled="!may('address')" class="mt-1 w-full" />
                    <InputError :message="form.errors.address" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mreg.subject')" required />
                    <TextInput v-model="form.subject" maxlength="255" :required="may('subject')" :disabled="!may('subject')" class="mt-1 w-full" />
                    <InputError :message="form.errors.subject" class="mt-1" />
                </div>
                <template v-if="!outgoing">
                    <div>
                        <InputLabel :value="t('mreg.sender_reference')" />
                        <TextInput v-model="form.sender_reference" maxlength="100" :disabled="!may('sender_reference')" class="mt-1 w-full" />
                        <InputError :message="form.errors.sender_reference" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('mreg.received_date')" />
                        <TextInput v-model="form.received_date" type="date" :disabled="!may('received_date')" class="mt-1 w-full" />
                        <InputError :message="form.errors.received_date" class="mt-1" />
                    </div>
                </template>
                <div v-if="outgoing" class="sm:col-span-3">
                    <InputLabel :value="t('mreg.body')" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.body_hint') }}</p>
                    <textarea v-model="form.body" rows="10" maxlength="20000" :disabled="!may('body')" :class="field"></textarea>
                    <InputError :message="form.errors.body" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('notes')" />
                    <textarea v-model="form.notes" rows="2" maxlength="5000" :disabled="!may('notes')" :class="field"></textarea>
                    <InputError :message="form.errors.notes" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mreg.scan')" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.scan_hint') }}</p>
                    <a v-if="entry?.has_scan" :href="route('mail.scan', entry.id)" target="_blank" class="mt-1 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ entry.scan_name || t('mreg.scan_open') }} ↗</a>
                    <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" @change="form.scan = $event.target.files[0] ?? null"
                        class="mt-1 w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-500/10 dark:file:text-primary-300 dark:hover:file:bg-primary-500/15" />
                    <InputError :message="form.errors.scan" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('mail.show', entry.id) : route('mail.index', { direction: form.direction })"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
```

- [ ] **Step 6: Create and Edit pages**

Create `resources/js/Pages/Mail/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MailEntryForm from './Partials/MailEntryForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ direction: String, correspondents: Object });
</script>

<template>
    <Head :title="t('mreg.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.new') }}</h1></template>
        <MailEntryForm :direction="direction" :correspondents="correspondents" />
    </AuthenticatedLayout>
</template>
```

Create `resources/js/Pages/Mail/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MailEntryForm from './Partials/MailEntryForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ entry: Object, editable: Array, correspondents: Object });
</script>

<template>
    <Head :title="t('mreg.title') + ' ' + entry.reference" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.title') }} <bdi>{{ entry.reference }}</bdi></h1></template>
        <MailEntryForm :entry="entry" :editable="editable" :correspondents="correspondents" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Show page**

Create `resources/js/Pages/Mail/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();
const props = defineProps({ entry: Object, source: Object, can: Object });

const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };
const outgoing = computed(() => props.entry.direction === 'out');
const cancelOpen = ref(false);

const rows = computed(() => [
    { label: t('mreg.direction'), value: t(outgoing.value ? 'mreg.outgoing' : 'mreg.incoming') },
    { label: t('mreg.entry_date'), value: props.entry.entry_date ? formatDate(props.entry.entry_date) : null, ltr: true },
    { label: t(outgoing.value ? 'mreg.recipient' : 'mreg.sender'), value: props.entry.correspondent },
    { label: t('address'), value: props.entry.address },
    { label: t('mreg.subject'), value: props.entry.subject, wide: true },
    { label: t('mreg.sender_reference'), value: props.entry.sender_reference, ltr: true },
    { label: t('mreg.received_date'), value: props.entry.received_date ? formatDate(props.entry.received_date) : null, ltr: true },
    { label: t('notes'), value: props.entry.notes, wide: true, multiline: true },
].filter((row) => row.value));

const hint = computed(() => {
    if (props.entry.status === 'cancelled') return { text: t('mreg.cancelled_hint'), tone: 'rose' };
    if (props.entry.is_legacy) return { text: t('mreg.legacy_hint'), tone: 'amber' };
    if (props.entry.source_type) return { text: t('mreg.from_print_hint'), tone: 'amber' };
    return null;
});
const HINT_CLASS = {
    rose: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
};

const cancel = () => router.post(route('mail.cancel', props.entry.id), {}, {
    preserveScroll: true, onFinish: () => { cancelOpen.value = false; },
});
</script>

<template>
    <Head :title="t('mreg.title') + ' ' + entry.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.title') }} <bdi>{{ entry.reference }}</bdi></h1>
                    <Badge :label="t('mreg.status.' + entry.status)" :color="STATUS_COLOR[entry.status]" />
                    <Badge v-if="entry.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-if="can.print" :href="route('mail.print', entry.id)" external target="_blank" icon="print" :label="t('mreg.print_letter')" />
                    <IconButton v-if="entry.has_scan" :href="route('mail.scan', entry.id)" external target="_blank" icon="document" :label="t('mreg.scan_open')" />
                    <IconButton v-if="can.edit" :href="route('mail.edit', entry.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('mreg.cancel_entry')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" :class="row.wide && 'sm:col-span-2'">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100" :class="row.multiline && 'whitespace-pre-line'"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="hint" class="mt-5 rounded-lg p-3 text-xs" :class="HINT_CLASS[hint.tone]">{{ hint.text }}</p>
            </div>

            <div v-if="source" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mreg.source_label') }}</h2>
                <p class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ t('mreg.source.' + source.type) }}</span>
                    <Link v-if="source.url" :href="source.url" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ source.label }}</bdi></Link>
                    <span v-else class="text-slate-600 dark:text-slate-300">{{ source.label }}</span>
                </p>
            </div>

            <div v-if="entry.body" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mreg.body') }}</h2>
                <p class="mt-3 whitespace-pre-line text-sm text-slate-800 dark:text-slate-200">{{ entry.body }}</p>
            </div>
        </div>

        <Modal :show="cancelOpen" @close="cancelOpen = false" max-width="md">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.cancel_entry') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('mreg.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" @click="cancel">{{ t('mreg.cancel_entry') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: The print button with the tick box**

Create `resources/js/Components/MailPrintButton.vue`:

```vue
<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useCan } from '@/Composables/useCan';

/**
 * A print button that can register the printed document in the mail register.
 * With a `source` and the mail add right it opens a small dialog: the
 * document's reference when it already has one, else the box «Register in
 * the mail register» (default `ticked`). Ticking posts to mail.sources.store,
 * then the PDF opens — the PDF route only looks the reference up, so opening
 * or reprinting never takes a number. Without a source or the right, it is
 * the plain print link it replaced.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    href: { type: String, required: true },
    // { type: 'absence-letter' | 'game' | 'purchase-order', id, query? } — query: the PDF's own (period, branch).
    source: { type: Object, default: null },
    ticked: { type: Boolean, default: false },
    icon: { type: String, default: 'print' },
    label: { type: String, required: true },
    variant: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    plain: { type: Boolean, default: false },
});

const { t } = useI18n();
const { can } = useCan();

const asks = computed(() => props.source !== null && can('mail', 'add'));
const open = ref(false);
const loading = ref(false);
const busy = ref(false);
const entry = ref(null);
const register = ref(props.ticked);
const lookupFailed = ref(false);
const failed = ref(false);

const params = () => ({ type: props.source.type, id: props.source.id, ...(props.source.query ?? {}) });

async function ask() {
    if (loading.value) return;
    loading.value = true;
    failed.value = false;
    lookupFailed.value = false;
    try {
        const { data } = await window.axios.get(route('mail.sources.show', params()));
        entry.value = data.entry;
    } catch {
        // The register cannot be reached: the dialog still prints.
        entry.value = null;
        lookupFailed.value = true;
    } finally {
        register.value = props.ticked;
        loading.value = false;
        open.value = true;
    }
}

async function print() {
    if (entry.value || lookupFailed.value || !register.value) {
        window.open(props.href, '_blank');
        open.value = false;
        return;
    }
    // Opened now, while the click still counts as the user's: a tab opened after the request is blocked as a pop-up.
    const tab = window.open('', '_blank');
    busy.value = true;
    failed.value = false;
    try {
        const { data } = await window.axios.post(route('mail.sources.store', params()));
        entry.value = data.entry;
        if (tab) tab.location.href = props.href;
        else window.open(props.href, '_blank');
        open.value = false;
    } catch {
        tab?.close();
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <IconButton v-if="!asks" v-bind="$attrs" :href="href" external target="_blank" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" />
    <template v-else>
        <IconButton v-bind="$attrs" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" :disabled="loading" @click="ask" />
        <Modal :show="open" max-width="sm" @close="open = false">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ label }}</h3>
                <p v-if="entry" class="text-sm text-slate-700 dark:text-slate-200">
                    {{ t('mreg.already_registered') }} <bdi class="font-semibold text-primary-700 dark:text-primary-300">{{ entry.reference }}</bdi>
                </p>
                <label v-else-if="!lookupFailed" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="register" class="mt-0.5" />
                    <span>{{ t('mreg.register_tick') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.register_hint') }}</span></span>
                </label>
                <p v-if="lookupFailed || failed" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mreg.register_failed') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="open = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton type="button" :disabled="busy" @click="print">{{ t('print') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </template>
</template>
```

- [ ] **Step 9: Wire the three prints**

Purchase order (ticked; no dialog for a draft, a cancelled or an imported order). In `resources/js/Pages/PurchaseOrders/Show.vue` replace

```js
import DangerButton from '@/Components/DangerButton.vue';
```

with

```js
import DangerButton from '@/Components/DangerButton.vue';
import MailPrintButton from '@/Components/MailPrintButton.vue';
```

replace

```js
const STATUS_COLOR = { draft: 'slate', sent: 'blue', received: 'emerald', cancelled: 'rose' };
const PAYMENT_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
```

with

```js
const STATUS_COLOR = { draft: 'slate', sent: 'blue', received: 'emerald', cancelled: 'rose' };
const PAYMENT_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
// A sent or received order can go to the mail register when printed.
const mailSource = computed(() => (['sent', 'received'].includes(props.order.status) && !props.order.is_legacy
    ? { type: 'purchase-order', id: props.order.id } : null));
```

and replace

```vue
                    <IconButton v-if="can.print" :href="route('purchase-orders.print', order.id)" external target="_blank" icon="print" :label="t('pur.print')" />
```

with

```vue
                    <MailPrintButton v-if="can.print" :href="route('purchase-orders.print', order.id)" :source="mailSource" ticked icon="print" :label="t('pur.print')" />
```

Convocation (ticked). In `resources/js/Pages/Games/Show.vue` replace

```js
import ConfirmModal from '@/Components/ConfirmModal.vue';
```

with

```js
import ConfirmModal from '@/Components/ConfirmModal.vue';
import MailPrintButton from '@/Components/MailPrintButton.vue';
```

and replace

```vue
                    <a :href="route('games.convocation', game.id)" data-help="game-callup-pdf" target="_blank" class="text-sm text-primary-600 hover:underline dark:text-primary-300">{{ t('comp.convocation_pdf') }}</a>
```

with

```vue
                    <MailPrintButton :href="route('games.convocation', game.id)" :source="{ type: 'game', id: game.id }" ticked data-help="game-callup-pdf" icon="print" :label="t('comp.convocation_pdf')" size="sm" />
```

Parent letter on the follow-up page (unticked). In `resources/js/Pages/Attendance/Alerts.vue` replace

```js
import IconButton from '@/Components/IconButton.vue';
```

with

```js
import IconButton from '@/Components/IconButton.vue';
import MailPrintButton from '@/Components/MailPrintButton.vue';
```

replace

```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...branchQuery.value });
```

with

```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...branchQuery.value });
// The same letter as a mail-register document: player, period and branch.
const letterSource = (row) => ({ type: 'absence-letter', id: row.player_id, query: { ...periodQuery(props.period), ...branchQuery.value } });
```

and replace

```vue
                                    <IconButton :data-help="i === 0 ? 'alerts-letter-first' : undefined" :href="letterHref(row)" external target="_blank" icon="mail" :label="t('att.letter.print')" plain size="sm" />
```

with

```vue
                                    <MailPrintButton :data-help="i === 0 ? 'alerts-letter-first' : undefined" :href="letterHref(row)" :source="letterSource(row)" icon="mail" :label="t('att.letter.print')" plain size="sm" />
```

Parent letter in the statistics table (unticked). In `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue` replace

```js
import IconButton from '@/Components/IconButton.vue';
```

with

```js
import MailPrintButton from '@/Components/MailPrintButton.vue';
```

replace

```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...(props.branchId ? { branch_id: props.branchId } : {}) });
```

with

```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period), ...(props.branchId ? { branch_id: props.branchId } : {}) });
// The same letter as a mail-register document: player, period and branch.
const letterSource = (row) => ({ type: 'absence-letter', id: row.player_id, query: { ...periodQuery(props.period), ...(props.branchId ? { branch_id: props.branchId } : {}) } });
```

and replace

```vue
                        <IconButton :href="letterHref(row)" external target="_blank" icon="mail" :label="t('att.letter.print')" plain size="sm" />
```

with

```vue
                        <MailPrintButton :href="letterHref(row)" :source="letterSource(row)" icon="mail" :label="t('att.letter.print')" plain size="sm" />
```

Parent letter on the player's attendance card (unticked). In `resources/js/Pages/Players/Partials/AttendanceSection.vue` replace

```js
import IconButton from '@/Components/IconButton.vue';
```

with

```js
import IconButton from '@/Components/IconButton.vue';
import MailPrintButton from '@/Components/MailPrintButton.vue';
```

replace

```js
const letterHref = computed(() => (printQuery.value ? route('attendance.players.letter', printQuery.value) : null));
```

with

```js
const letterHref = computed(() => (printQuery.value ? route('attendance.players.letter', printQuery.value) : null));
// The same letter as a mail-register document: player, period and branch tab of the card.
const letterSource = computed(() => (data.value
    ? { type: 'absence-letter', id: props.player.id, query: { ...periodQuery(data.value.period), ...(data.value.branchId ? { branch_id: data.value.branchId } : {}) } }
    : null));
```

and replace

```vue
                <IconButton :href="letterHref" external target="_blank" icon="mail" :label="t('att.letter.print')" size="sm" />
```

with

```vue
                <MailPrintButton :href="letterHref" :source="letterSource" icon="mail" :label="t('att.letter.print')" size="sm" />
```

- [ ] **Step 10: Page translations**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t7-keys.json`:

```json
{
    "mreg.outgoing": { "ar": "الصادر", "fr": "Courrier départ", "en": "Outgoing" },
    "mreg.incoming": { "ar": "الوارد", "fr": "Courrier arrivée", "en": "Incoming" },
    "mreg.new": { "ar": "تسجيل مراسلة", "fr": "Nouvelle entrée", "en": "New entry" },
    "mreg.find_placeholder": { "ar": "بحث: المرجع، المراسل، الموضوع…", "fr": "Rechercher : référence, correspondant, objet…", "en": "Search: reference, correspondent, subject…" },
    "mreg.direction": { "ar": "الاتجاه", "fr": "Sens", "en": "Direction" },
    "mreg.entry_date": { "ar": "تاريخ التسجيل", "fr": "Date d'enregistrement", "en": "Entry date" },
    "mreg.recipient": { "ar": "المرسل إليه", "fr": "Destinataire", "en": "Recipient" },
    "mreg.sender": { "ar": "المرسل", "fr": "Expéditeur", "en": "Sender" },
    "mreg.subject": { "ar": "الموضوع", "fr": "Objet", "en": "Subject" },
    "mreg.body": { "ar": "نص الرسالة", "fr": "Texte de la lettre", "en": "Letter text" },
    "mreg.body_hint": { "ar": "اختياري: إذا كُتب، تُطبع الرسالة على ورق النادي.", "fr": "Facultatif : s'il est rempli, la lettre s'imprime sur l'en-tête du club.", "en": "Optional: when filled in, the letter prints on the club letterhead." },
    "mreg.sender_reference": { "ar": "مرجع المرسل", "fr": "Référence de l'expéditeur", "en": "Sender's reference" },
    "mreg.received_date": { "ar": "تاريخ الاستلام", "fr": "Date de réception", "en": "Date received" },
    "mreg.scan": { "ar": "النسخة الممسوحة", "fr": "Scan", "en": "Scan" },
    "mreg.scan_open": { "ar": "عرض النسخة الممسوحة", "fr": "Voir le scan", "en": "Open the scan" },
    "mreg.scan_hint": { "ar": "ملف واحد (PDF أو صورة)؛ الملف الجديد يعوّض القديم.", "fr": "Un fichier (PDF ou image) ; un nouveau fichier remplace l'ancien.", "en": "One file (PDF or picture); a new file replaces the old one." },
    "mreg.status.active": { "ar": "ساري", "fr": "Active", "en": "Active" },
    "mreg.status.cancelled": { "ar": "ملغى", "fr": "Annulée", "en": "Cancelled" },
    "mreg.cancel_entry": { "ar": "إلغاء التسجيل", "fr": "Annuler l'entrée", "en": "Cancel the entry" },
    "mreg.cancel_confirm": { "ar": "يبقى الرقم محجوزًا ولا يُحذف التسجيل. هل تريد الإلغاء؟", "fr": "Le numéro reste pris et l'entrée n'est pas supprimée. L'annuler ?", "en": "The number stays taken and the entry is not deleted. Cancel it?" },
    "mreg.print_letter": { "ar": "طباعة الرسالة", "fr": "Imprimer la lettre", "en": "Print the letter" },
    "mreg.source_label": { "ar": "الوثيقة الأصلية", "fr": "Document d'origine", "en": "Source document" },
    "mreg.source.absence_letter": { "ar": "رسالة إلى الأولياء", "fr": "Lettre aux parents", "en": "Parent letter" },
    "mreg.source.game_convocation": { "ar": "استدعاء مباراة", "fr": "Convocation de match", "en": "Match call-up" },
    "mreg.source.purchase_order": { "ar": "سند طلب", "fr": "Bon de commande", "en": "Purchase order" },
    "mreg.from_print_hint": { "ar": "سُجّلت عند الطباعة: الاتجاه والرقم والتاريخ والوثيقة ثابتة.", "fr": "Enregistrée à l'impression : sens, numéro, date et document sont fixes.", "en": "Registered at print: direction, number, date and document are fixed." },
    "mreg.legacy_hint": { "ar": "مستوردة من البرنامج القديم: الملاحظات والنسخة الممسوحة فقط قابلة للتعديل.", "fr": "Importée de l'ancien logiciel : seules les notes et le scan se modifient.", "en": "Imported from the old software: only the notes and the scan can change." },
    "mreg.cancelled_hint": { "ar": "تسجيل ملغى: للاطلاع فقط.", "fr": "Entrée annulée : lecture seule.", "en": "Cancelled entry: read-only." },
    "mreg.register_tick": { "ar": "تسجيل في سجل البريد", "fr": "Inscrire au registre du courrier", "en": "Register in the mail register" },
    "mreg.register_hint": { "ar": "تأخذ رقمًا صادرًا؛ إعادة الطباعة تحتفظ بنفس الرقم.", "fr": "Prend un numéro de départ ; une réimpression garde le même.", "en": "Takes an outgoing number; a reprint keeps the same one." },
    "mreg.already_registered": { "ar": "مسجّلة في سجل البريد تحت المرجع", "fr": "Déjà inscrite au registre sous la référence", "en": "Already in the mail register as" },
    "mreg.register_failed": { "ar": "تعذّر الوصول إلى سجل البريد.", "fr": "Le registre du courrier n'a pas pu être joint.", "en": "The mail register could not be reached." }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t7-keys.json`
Expected: `✓ 32 key(s) written to ar/en/fr`

- [ ] **Step 11: Build, check and run the tests**

Run: `npm run build`
Expected: build succeeds (the new pages are in the manifest).
Run: `npm run i18n:check`
Expected: PASS.
Run: `npm run test:js`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/MailPagesTest.php`
Expected: PASS (2 tests).
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PurchaseOrderPagesTest.php`
Expected: PASS.
Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/Competitions/CallUpTest.php`
Expected: PASS (`Games/Show` still renders).

- [ ] **Step 12: Commit**

```bash
git add resources/js/Pages/Mail/Index.vue resources/js/Pages/Mail/Create.vue resources/js/Pages/Mail/Edit.vue resources/js/Pages/Mail/Show.vue resources/js/Pages/Mail/Partials/MailEntryForm.vue resources/js/Components/MailPrintButton.vue resources/js/Composables/useNavigation.js resources/js/Pages/PurchaseOrders/Show.vue resources/js/Pages/Games/Show.vue resources/js/Pages/Attendance/Alerts.vue resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue resources/js/Pages/Players/Partials/AttendanceSection.vue tests/Feature/MailPagesTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(mail): Secretariat menu, mail register pages, register-at-print dialog

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 8: Import of the old Access letters

**Files:**
- Modify: `tests/Fixtures/legacy-access.json` (end of file: new `mail` section — **fake rows only**)
- Modify: `app/Services/Legacy/LegacyAccessImporter.php` (import, constant, `import()`, new `mail()` + `yearOf()`)
- Modify: `scripts/legacy/export-access.ps1` (optional `mail` section; keep the UTF-8 BOM)
- Modify (test): `tests/Feature/LegacyAccessImportTest.php` (import line, four tests at the end)
- Modify: `resources/js/i18n/*.json` (via script)

**Interfaces:**
- Consumes: `MailEntry` (Task 2), `YearlySequence::next(string, int, array)` (Task 2), the importer's private helpers `plain()`, `text()`, `date()` (P1).
- Produces: export section `mail` = list of `{reference, correspondent, address, date}` (Access table `Corresponds`: columns `المرجع`, `الى السيد:`, `العنوان`, `التاريخ`; `N°` is the Access row id and is not exported); import result key `mail` = `{created, skipped}`; legacy entries `direction = out`, `status = active`, `number = null`, `is_legacy = true`, `legacy_source = 'corresponds'`, `legacy_reference` = the old text, `year` from the date, else from a `YYYY/NN` reference, else null; activity property `mail` (created count); label key `legacy_import.section.mail`.

- [ ] **Step 1: Fixture section**

In `tests/Fixtures/legacy-access.json` replace

```json
            { "designation": "Caméra de surveillance", "reference": "", "quantity": 1, "unit_price": null }
        ] }
    ]
}
```

with

```json
            { "designation": "Caméra de surveillance", "reference": "", "quantity": 1, "unit_price": null }
        ] }
    ],
    "mail": [
        { "reference": "2019/01", "correspondent": "Test Ligue de Wilaya", "address": "طبعت", "date": "2019-01-15" },
        { "reference": "2019/02", "correspondent": "Test Direction de la Jeunesse", "address": "1 rue Test, Testville", "date": null },
        { "reference": "2020/05", "correspondent": "Test Club Voisin", "address": "", "date": "2020-03-02" },
        { "reference": "", "correspondent": "", "address": "", "date": null }
    ]
}
```

(All four rows are invented. Never copy a row of the real `.accdb` into the fixture.)

- [ ] **Step 2: Write the failing tests**

In `tests/Feature/LegacyAccessImportTest.php` replace

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
```

with

```php
use App\Models\MailEntry;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
```

and replace the end of the file

```php
        $this->assertSame(['created' => 1, 'skipped' => 3, 'merged' => 0], $result['payment_orders']);
    }
}
```

with

```php
        $this->assertSame(['created' => 1, 'skipped' => 3, 'merged' => 0], $result['payment_orders']);
    }

    #[Test]
    public function the_old_letters_are_imported_as_outgoing_history(): void
    {
        $result = app(LegacyAccessImporter::class)->import($this->data(), User::factory()->admin()->create());

        $this->assertSame(['created' => 3, 'skipped' => 1], $result['mail']);

        $printed = MailEntry::where('legacy_reference', '2019/01')->sole();
        $this->assertTrue($printed->is_legacy);
        $this->assertSame('corresponds', $printed->legacy_source);
        $this->assertSame(MailEntry::OUT, $printed->direction);
        $this->assertSame(MailEntry::ACTIVE, $printed->status);
        $this->assertNull($printed->number);
        $this->assertSame(2019, $printed->year);
        $this->assertSame('2019/01', $printed->reference);
        $this->assertSame('2019-01-15', $printed->entry_date->toDateString());
        $this->assertSame('Test Ligue de Wilaya', $printed->correspondent);
        // «طبعت» (printed) was a status typed in the address column: it moves to the notes.
        $this->assertNull($printed->address);
        $this->assertSame('طبعت', $printed->notes);
        // Read-only history: only notes (and a scan) can be added.
        $this->assertSame(['notes'], $printed->editableFields());

        $undated = MailEntry::where('legacy_reference', '2019/02')->sole();
        $this->assertNull($undated->entry_date);
        $this->assertSame(2019, $undated->year);
        $this->assertSame('1 rue Test, Testville', $undated->address);
        $this->assertNull($undated->notes);

        // Old letters never take a sequence number.
        $this->assertSame(0, MailEntry::whereNotNull('number')->count());
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('mail_entries', 2019, ['direction' => MailEntry::OUT])));
        $this->assertDatabaseHas('activity_logs', ['action' => 'legacy_data_imported']);
    }

    #[Test]
    public function importing_the_letters_twice_changes_nothing(): void
    {
        $importer = app(LegacyAccessImporter::class);
        $admin = User::factory()->admin()->create();
        $importer->import($this->data(), $admin);

        $second = $importer->import($this->data(), $admin);

        $this->assertSame(['created' => 0, 'skipped' => 4], $second['mail']);
        $this->assertSame(3, MailEntry::count());
    }

    #[Test]
    public function a_file_without_letters_still_imports(): void
    {
        $data = $this->data();
        unset($data['mail']);

        $result = app(LegacyAccessImporter::class)->import($data, User::factory()->admin()->create());

        $this->assertSame(['created' => 0, 'skipped' => 0], $result['mail']);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'merged' => 1], $result['payment_orders']);
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function bad_letter_rows_are_skipped_and_a_reference_alone_still_imports(): void
    {
        $result = app(LegacyAccessImporter::class)->import([
            'format' => 'access-legacy-v1',
            'mail' => [
                'oops',
                ['reference' => '  2018/7 ', 'correspondent' => '', 'address' => '', 'date' => 'not a date'],
                // An address alone (here the "printed" status) is no letter.
                ['reference' => '', 'correspondent' => '', 'address' => 'طبعت', 'date' => null],
            ],
        ], User::factory()->admin()->create());

        $this->assertSame(['created' => 1, 'skipped' => 2], $result['mail']);
        $row = MailEntry::sole();
        $this->assertSame('2018/7', $row->legacy_reference);
        $this->assertSame(2018, $row->year);
        $this->assertNull($row->entry_date);
        $this->assertNull($row->correspondent);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: FAIL (`Undefined array key "mail"`); the 11 earlier tests still pass.

- [ ] **Step 4: Importer section**

In `app/Services/Legacy/LegacyAccessImporter.php` replace

```php
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
```

with

```php
use App\Models\MailEntry;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
```

replace

```php
    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];
```

with

```php
    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];

    /** The status ("printed") the old mail register typed in its address column. */
    private const PRINTED = 'طبعت';
```

replace

```php
                // Optional section (P2): an older export without it still imports.
                'purchase_orders' => $this->purchaseOrders(is_array($data['purchase_orders'] ?? null) ? $data['purchase_orders'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
            ]);
```

with

```php
                // Optional section (P2): an older export without it still imports.
                'purchase_orders' => $this->purchaseOrders(is_array($data['purchase_orders'] ?? null) ? $data['purchase_orders'] : []),
                // Optional section (P3): the old outgoing letters.
                'mail' => $this->mail(is_array($data['mail'] ?? null) ? $data['mail'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
                'mail' => $result['mail']['created'],
            ]);
```

and replace

```php
    /**
     * One old order line: a designation (or its article reference), a quantity
     * of at least 1 and a price when one was typed.
```

with

```php
    /**
     * Old `Corresponds` rows → outgoing legacy mail entries: no number, the
     * old reference text kept, an empty date left empty (the year then comes
     * from a "YYYY/NN" reference), the recipient as correspondent. The old
     * register typed the status «طبعت» (printed) in its address column: it
     * moves to the notes. A row is matched on (source, reference, date,
     * correspondent), so importing again creates nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mail(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = self::text(preg_replace('/\s+/u', ' ', self::plain($row['reference'] ?? null)), 50);
            $date = self::date($row['date'] ?? null);
            $correspondent = self::text($row['correspondent'] ?? null, 200);
            $address = self::text($row['address'] ?? null, 255);
            if ($reference === null && $correspondent === null && $date === null) {
                $skipped++;

                continue;
            }
            $notes = null;
            if ($address === self::PRINTED) {
                [$notes, $address] = [$address, null];
            }

            $exists = MailEntry::where('is_legacy', true)->where('legacy_source', 'corresponds')
                ->when($reference !== null, fn ($q) => $q->where('legacy_reference', $reference), fn ($q) => $q->whereNull('legacy_reference'))
                ->when($date !== null, fn ($q) => $q->whereDate('entry_date', $date), fn ($q) => $q->whereNull('entry_date'))
                ->when($correspondent !== null, fn ($q) => $q->where('correspondent', $correspondent), fn ($q) => $q->whereNull('correspondent'))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            MailEntry::create([
                'direction' => MailEntry::OUT,
                'year' => $date !== null ? (int) substr($date, 0, 4) : self::yearOf($reference),
                'number' => null,
                'status' => MailEntry::ACTIVE,
                'entry_date' => $date,
                'correspondent' => $correspondent,
                'address' => $address,
                'notes' => $notes,
                'is_legacy' => true,
                'legacy_reference' => $reference,
                'legacy_source' => 'corresponds',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** "2019/07" → 2019; anything else → null. */
    private static function yearOf(?string $reference): ?int
    {
        return $reference !== null && preg_match('/^(\d{4})\s*\//', $reference, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * One old order line: a designation (or its article reference), a quantity
     * of at least 1 and a price when one was typed.
```

- [ ] **Step 5: Export script section**

`scripts/legacy/export-access.ps1` starts with a UTF-8 BOM (PowerShell 5.1 needs it to read the Arabic column names) — edit it in place and keep it. In `scripts/legacy/export-access.ps1` replace

```powershell
$connection.Close()
```

with

```powershell
# Optional: the old outgoing mail register (Corresponds): reference text
# ("YYYY/NN"), recipient, "address" (often the status «طبعت» = printed, moved
# to the notes by the importer) and date (often empty). The N° column is the
# Access row id and is not exported. A database without the table still exports.
$mail = New-Object System.Collections.ArrayList
try {
    $mailRows = (Read-Table 'SELECT * FROM [Corresponds]').Rows
} catch {
    $mailRows = @()
    Write-Warning 'No [Corresponds] table: mail skipped'
}
foreach ($row in $mailRows) {
    [void]$mail.Add([ordered]@{
        reference = (Get-Text $row['المرجع']); correspondent = (Get-Text $row['الى السيد:'])
        address = (Get-Text $row['العنوان']); date = (Get-Day $row['التاريخ'])
    })
}

$connection.Close()
```

and replace

```powershell
    purchase_orders = @($purchaseOrders.Values)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders to {3}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $Out)
```

with

```powershell
    purchase_orders = @($purchaseOrders.Values)
    mail = @($mail)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders, {3} letters to {4}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $mail.Count, $Out)
```

Check the BOM is still there and the script still parses (no database needed):

Run: `head -c 3 scripts/legacy/export-access.ps1 | od -An -tx1`
Expected: ` ef bb bf`

Run (from Git Bash — the single quotes keep `$` away from bash):

```bash
powershell -NoProfile -Command '$errors = $null; [void][System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path "scripts/legacy/export-access.ps1"), [ref]$null, [ref]$errors); if ($errors) { $errors; exit 1 } else { "parse ok" }'
```

Expected: `parse ok`

- [ ] **Step 6: Result label**

Create `C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t8-keys.json`:

```json
{
    "legacy_import.section.mail": { "ar": "سجل البريد", "fr": "Registre du courrier", "en": "Mail register" }
}
```

Run: `node scripts/i18n-add.mjs C:\Users\MUSTAPHA\AppData\Local\Temp\p3-t8-keys.json`
Expected: `✓ 1 key(s) written to ar/en/fr`

(`resources/js/Pages/LegacyImport/Index.vue` already loops over every result section with `t('legacy_import.section.' + section)` — no page change.)

- [ ] **Step 7: Run the tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/LegacyAccessImportTest.php`
Expected: PASS (15 tests: 11 from P1/P2 + 4 new).
Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add scripts/legacy/export-access.ps1 app/Services/Legacy/LegacyAccessImporter.php tests/Fixtures/legacy-access.json tests/Feature/LegacyAccessImportTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -F - <<'EOF'
feat(legacy): import the old Access mail register (optional section)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 9: Full verification

**Files:** none changed unless a check fails (fix in the task the failure belongs to, with its own test and commit).

- [ ] **Step 1: Front-end checks**

Run: `npm run i18n:check`
Expected: PASS.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 2: Full suite**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: all tests pass (no other test process running at the same time).

- [ ] **Step 3: Migrations on a copy of the dev database**

The table migration only creates `mail_entries` (no rebuild of an existing table). Work on a copy, never on `D:\irnb-laravel\database\database.sqlite` itself (stop `php artisan serve` first if it holds the file):

```bash
COPY="C:/Users/MUSTAPHA/AppData/Local/Temp/p3-dev-copy.sqlite"
cp D:/irnb-laravel/database/database.sqlite "$COPY"
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
COPY="$COPY" php -r '$p = new PDO("sqlite:".getenv("COPY")); echo $p->query("select count(*) from mail_entries")->fetchColumn(), PHP_EOL; foreach ($p->query("select permissions from roles where is_system = 1") as $r) { echo (str_contains($r[0], "\"mail\"") ? "mail granted" : "MISSING"), PHP_EOL; }'
DB_CONNECTION=sqlite DB_DATABASE="$COPY" php artisan migrate --force
```

Expected: the first `migrate` runs `2026_11_26_100010_grant_mail_permissions` and `2026_11_26_100011_create_mail_entries_table` without error; the count prints `0`; every system role prints `mail granted`; the second `migrate` prints `Nothing to migrate.`

- [ ] **Step 4: Look at the prints (mPDF RTL)**

Render the Arabic and French letter, and the three prints with a reference, against the migrated copy:

```bash
DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p3-dev-copy.sqlite" php artisan tinker --execute '
foreach (["ar", "fr"] as $locale) {
    app()->setLocale($locale);
    $e = new App\Models\MailEntry(["direction" => "out", "year" => 2026, "number" => 7, "status" => "active", "entry_date" => "2026-11-26",
        "correspondent" => $locale === "ar" ? "الرابطة الولائية لكرة القدم" : "Ligue de Wilaya de football",
        "address" => "12 rue des Sports, Testville", "subject" => $locale === "ar" ? "تسجيل الفرق" : "Engagement des équipes",
        "body" => $locale === "ar" ? "السيد الرئيس،\nيشرفنا أن نرسل إليكم قائمة فرقنا.\nRef. FAF 2026" : "Monsieur le Président,\nNous avons l honneur de vous adresser la liste de nos équipes."]);
    $html = view("pdf.mail-letter", ["club" => App\Services\Pdf\ClubHeader::data(), "rtl" => $locale === "ar", "entry" => $e, "signatories" => App\Services\Pdf\BoardSignatories::names()])->render();
    $file = sys_get_temp_dir()."/p3-letter-".$locale.".pdf";
    file_put_contents($file, app(App\Services\Pdf\PdfService::class)->render($html, $locale === "ar"));
    echo $file, PHP_EOL;
}'
```

Expected: two file paths printed. Open both: the Arabic one reads right-to-left with the reference `2026/07` and the date left-to-right, the Latin line `Ref. FAF 2026` left-to-right on its own line, the body keeps its line breaks; the French one reads left-to-right; both show the letterhead, «Secrétaire général / الكاتب العام» and «Président» columns with a line to sign. Then, in the browser (Step 5), print a convocation, a parent letter and a sent purchase order after ticking the box, in Arabic and in French, and check «المرجع: 2026/NN» / «Réf. : 2026/NN» sits near the date and reads left-to-right. Fix any defect in the matching Blade view (commit `fix(mail): …`) and re-run Step 2.

- [ ] **Step 5: Browser QA (owner or Claude with the dev server)**

Serve the worktree against the copy (`DB_CONNECTION=sqlite DB_DATABASE="C:/Users/MUSTAPHA/AppData/Local/Temp/p3-dev-copy.sqlite" php artisan serve --no-reload --port=2027`) and check, in Arabic and French, at full width and at 320 px, light and dark:
- Sidebar shows **الأمانة / Secrétariat → سجل البريد / Registre du courrier**; Roles shows the Secretariat group with the mail row.
- Outgoing / Incoming tabs, year filter, search by `2026/1` and by name; new outgoing (with text + scan) and incoming entries number `2026/01` and `A-2026/01` (`و 2026/01` in Arabic) separately; the correspondent field suggests earlier names.
- Show page: scan opens, letter prints, cancel keeps the number and makes the entry read-only; an entry made from a print offers only correspondent / address / subject / notes / scan.
- Purchase order (sent): the print button opens the dialog with the box ticked; ticking prints with the reference and the next print shows «already registered as 2026/NN». Draft order: prints directly. Convocation: ticked by default. Parent letter (player card, follow-up page, statistics table): unticked by default; a different period is a different entry. A user without the mail add right prints directly (no dialog).

- [ ] **Step 6: Review the branch before handing over**

Run: `git log --oneline main..feat/mail-register`
Expected: the 8 task commits (plus any fix commits), nothing else.
Run: `git status --short`
Expected: clean (no unstaged or untracked project file left behind).

Hand-over notes for the owner (no server deploy — the app runs locally and on the desktop): run `php artisan migrate` on the web PC's database; the desktop build picks up the migrations on its next version; Claude re-exports the old data with the updated script (`powershell -NoProfile -ExecutionPolicy Bypass -File scripts/legacy/export-access.ps1 -Database "D:\irnb-laravel\Sauvegarde de Sauvegarde de IRNB 2021 FOOT BALL.accdb" -Out "D:\BACKUPS\irnb-access-legacy.json"` — the output holds names: never commit it), then the owner imports it again from Settings → Old software import (existing rows are skipped, the 141 letters are added). Help Center articles for the mail register are not part of this plan.

---

## Spec coverage (P3)

| Spec rule | Task |
|---|---|
| Outgoing **and** incoming register (`direction` out / in) | 2, 3, 4, 7 |
| `mail_entries` columns: year, number (null legacy), status active / cancelled + cancelled_by / at, entry_date (nullable legacy), correspondent, address, subject, body, sender_reference + received_date, one attachment, notes, source_type / id / key, legacy fields, created_by | 2 |
| Unique `(direction, year, number)`; `YearlySequence` scoped by direction; each direction counts from 01 every calendar year | 2, 3 |
| Display `YYYY/NN`; incoming with the mark (ar `و `, fr `A-`, en `IN-`); legacy shows `legacy_reference` | 2 |
| Create / edit; cancel keeps the number, never deleted (no destroy route); cancelled read-only | 3, 4, 7 |
| Legacy entries: only notes + scan editable; legacy never cancelled (read-only history) | 2, 3, 4 |
| Entries made from a print: correspondent, address, subject, notes, scan editable; direction, number, date, source fixed; can be cancelled | 2, 3, 4, 7 |
| Page links to the source document when the user may view it | 4, 7 |
| Correspondent suggests earlier correspondents, same direction first | 4, 7 |
| `MailRegister::register(type, id, key, defaults, User)` returns the active entry or creates one (outgoing, today, next number) in a transaction; `find()` only looks up; a cancelled entry does not block a new one | 3 |
| One active entry per document, portable SQLite + MySQL (`source_lock` nullable unique, cleared on cancel) | 2, 3 |
| Tick box at print on absence letter, convocation, purchase order; ticking POSTs (mail add right) then opens the PDF; PDF routes only look up | 6, 7 |
| Existing active entry: dialog shows its reference; every print shows «Ref: …» | 6, 7 |
| Defaults: ticked for convocations and sent / received orders; unticked for absence letters; hidden for draft / cancelled orders and users without the add right | 7 |
| Source keys: letter `player:{id}|branch:{id or 0}|{from}|{to}`; convocation = the game; purchase order = the order | 4, 6 |
| Default correspondent / subject from the document (guardian / contact, opponent, supplier) | 6 |
| One scan per entry, private disk, replaceable, served behind login + mail view | 3, 4 |
| Letter PDF: letterhead, reference, date, recipient, address, subject, body (line breaks + `PdfText` in ar), President + Secretary General, blank line when none; `BoardSignatories` gains the secretary | 5 |
| «Ref: …» near the date on the absence letter, convocation and purchase order PDFs when the source has an active entry | 6 |
| New **Secretariat** (الأمانة) nav section with Mail register | 1, 7 |
| Module `mail` (view / add / edit / delete, delete = cancel); print, scan → view; register → add; new `secretariat` group labelled in the role editor; grant migration system roles only, idempotent | 1 |
| Activity codes for create / update / cancel / register, ids and references only | 3 |
| Import: `Corresponds` → outgoing legacy entries, legacy reference kept, empty dates stay empty, recipient → correspondent, «طبعت» → notes; optional export section (format id unchanged); a file without it still imports; idempotent by (legacy_source, legacy_reference, date, correspondent) | 8 |
| Tests: numbering per year / direction, cancelled keeps number, unique index, mail reference reused on reprint, permission grants, PDF smoke tests ar + fr, import twice → same counts; i18n check, build, full suite | 1-9 |
