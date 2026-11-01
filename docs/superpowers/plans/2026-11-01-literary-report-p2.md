# Literary Report (P2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Saved literary report editions (title, free period, section toggles, president's word / introduction / conclusion) exported live as one A4 PDF with nine fixed sections built from the club's data.

**Architecture:** A `literary_reports` table holds editions only; nothing is frozen. On export, `LiteraryReportBuilder` walks the enabled sections in fixed order, drops those the exporter has no right for, and asks one small `Section` class per data section for a plain array, which a Blade partial prints. PDF output reuses the existing `PdfService` (mPDF, RTL for Arabic), `ClubHeader` and `pdf.partials.header` — NOT the Reports Center engine, which is table-shaped (one Column/Filter/rows report) and cannot hold a multi-section document.

**Tech Stack:** Laravel 13, Inertia + Vue 3 `<script setup>`, Tailwind, vue-i18n flat dotted catalogs (ar/fr/en), mPDF via `App\Services\Pdf\PdfService`, PHPUnit (`#[Test]`, `RefreshDatabase`, sqlite :memory:).

Spec: `docs/superpowers/specs/2026-11-01-social-activities-literary-report-design.md` (P2 section).

## Global Constraints

- Table `literary_reports`: `title`, `period_from` (date), `period_to` (date, ≥ from), `sections` (json array of enabled section keys), `president_word`, `introduction`, `conclusion` (nullable long text — conclusion includes outlook), `created_by_user_id`, timestamps.
- Section keys and fixed print order: `cover, intro, members, board, attendance, sports, activities, finance, conclusion`.
- Right needed per section: members → `players.view`, board → `board.view`, attendance → `attendance.view`, sports → `games.view`, activities → `activities.view`, finance → `finance.view`; cover/intro/conclusion need none.
- A section the exporter has no right for is silently omitted; the edit page shows its toggle disabled with a hint.
- Empty computed sections print a one-line "no data for this period" rather than disappearing. Intro and conclusion are skipped when their texts are empty.
- Module `literary_reports` (route prefix `literary-reports`), empty for every role except system roles (granted in the migration, same pattern as P1). Nav item in the `social` section.
- Generated live at export with `PdfService`, header from `ClubHeader::data()`, in the UI language (`app()->getLocale() === 'ar'` → RTL).
- Offline-only: no remote fonts or images; photos read from the public disk path (`App\Support\Media::localFile($photo->url)`).
- Owner decisions 2026-11-01 (P2 round):
  - Active member at period end = `archived = false` AND (`join_year` null OR ≤ year of `period_to`) AND (no `left_at` and not in a `left` status, OR `left_at` > `period_to`).
  - Members table counts a player in every branch they belong to (`branch_player`); the club total counts each player once; a note under the table says so.
  - Sidebar entry only (no Reports hub card).
- Finance: cash basis, `transactions.archived = false`, `whereDate('transaction_date', …)`; transfers live in their own table so they are naturally excluded; subscriptions are debt, not income (they only become transactions when paid).
- i18n: flat dotted keys added with `node scripts/i18n-add.mjs <file.json>` (keeps order, writes ar/fr/en together). Never create a dotted key whose prefix is itself a message and whose next segment is a String method (`npm run i18n:check` rejects it). PDF labels are read with `App\Support\UiLang::get('key')` from the same catalogs.
- Stage explicit files only (`git add <paths>`), never `git add -A`/`.`. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Tests: `php artisan test --compact <paths>`; Inertia page tests need `npm run build` first (public/build is gitignored).

---

## File Structure

Create:
- `database/migrations/2026_11_01_200001_create_literary_reports_table.php` — table + grants `literary_reports` to system roles.
- `app/Models/LiteraryReport.php` — model, `SECTIONS`, `enabledSections()`.
- `app/Http/Requests/LiteraryReportRequest.php` — validation.
- `app/Http/Controllers/LiteraryReportController.php` — index/store/edit/update/destroy/pdf.
- `app/Services/LiteraryReport/LiteraryReportBuilder.php` — section order, rights, assembly.
- `app/Services/LiteraryReport/Sections/Section.php` — interface.
- `app/Services/LiteraryReport/Sections/{Members,Board,Attendance,Sports,Activities,Finance}Section.php`.
- `resources/views/pdf/literary-report.blade.php` + `resources/views/pdf/literary/{cover,intro,members,board,attendance,sports,activities,finance,conclusion}.blade.php`.
- `resources/js/Pages/LiteraryReports/{Index,Edit}.vue`.
- `tests/Feature/LiteraryReport/{LiteraryReportCrudTest,LiteraryReportPdfTest,MembersSectionTest,BoardSectionTest,AttendanceSectionTest,SportsSectionTest,ActivitiesSectionTest,FinanceSectionTest}.php`.

Modify:
- `app/Models/Role.php` (social group + MODULES), `config/permissions.php`, `config/activity.php`, `routes/web.php`, `app/Services/Activity/ActivityAction.php`, `app/Services/Activity/ActivitySubjectLink.php`, `resources/js/Composables/useNavigation.js`, `resources/js/i18n/{ar,fr,en}.json` (via i18n-add), `tests/Unit/RoleModelTest.php`, `tests/Unit/ActivityRecorderTest.php`.

---

### Task 0: Workspace

Worktree `D:\irnb-literary`, branch `feat/literary-report`, created from `origin/main` `da96fd6` (P1 + Reports merged).

- [ ] **Step 1: Make the worktree runnable**

`vendor/` and `.env` are copied from `D:\irnb-activities`. Also needed (all gitignored):

```bash
cd /d/irnb-literary
cp -r /d/irnb-activities/node_modules .        # same lockfile; no junctions (memory rule)
npm run build                                  # Inertia tests need public/build/manifest.json
php artisan config:clear                       # never test against a cached config
```

- [ ] **Step 2: Baseline**

Run: `php artisan test --compact tests/Feature/Activities tests/Feature/Reports tests/Unit/RoleModelTest.php tests/Unit/ActivityRecorderTest.php tests/Unit/ActivityCoverageTest.php`
Expected: all pass.

- [ ] **Step 3: Commit the plan**

```bash
git add docs/superpowers/plans/2026-11-01-literary-report-p2.md
git commit -m "docs(literary-report): P2 implementation plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1: Schema, model, permissions, navigation, activity log

**Files:**
- Create: `database/migrations/2026_11_01_200001_create_literary_reports_table.php`, `app/Models/LiteraryReport.php`
- Modify: `app/Models/Role.php`, `config/permissions.php`, `app/Services/Activity/ActivityAction.php`, `app/Services/Activity/ActivitySubjectLink.php`, `config/activity.php`, `resources/js/Composables/useNavigation.js`, i18n catalogs, `tests/Unit/RoleModelTest.php`, `tests/Unit/ActivityRecorderTest.php`
- Test: `tests/Feature/LiteraryReport/LiteraryReportCrudTest.php` (schema part)

**Interfaces:**
- Produces: `App\Models\LiteraryReport` with `public const SECTIONS = ['cover','intro','members','board','attendance','sports','activities','finance','conclusion']`, `enabledSections(): list<string>` (known keys, print order), `createdBy(): BelongsTo`. `ActivityAction::LITERARY_REPORT_CREATED|UPDATED|DELETED` (`'literary_report_created'` …). Module key `literary_reports`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiteraryReportCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function report(array $attributes = []): LiteraryReport
    {
        return LiteraryReport::create($attributes + [
            'title' => 'التقرير الأدبي 2025/26',
            'period_from' => '2025-09-01',
            'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS,
        ]);
    }

    #[Test]
    public function enabled_sections_keep_the_fixed_order_and_drop_unknown_keys(): void
    {
        $report = $this->report(['sections' => ['finance', 'bogus', 'cover', 'members']]);

        $this->assertSame(['cover', 'members', 'finance'], $report->fresh()->enabledSections());
        $this->assertSame('2025-09-01', $report->fresh()->toArray()['period_from']);
    }

    #[Test]
    public function system_roles_get_the_literary_reports_module_and_others_do_not(): void
    {
        $system = DB::table('roles')->where('is_system', true)->get(['permissions']);
        $this->assertNotEmpty($system);
        foreach ($system as $role) {
            $this->assertSame(['view', 'add', 'edit', 'delete'], json_decode($role->permissions, true)['literary_reports']);
        }

        $this->assertContains('literary_reports', Role::MODULES);
        $this->assertContains('literary_reports', Role::MODULE_GROUPS['social']);
        $custom = Role::factory()->create(['permissions' => []]);
        $this->assertFalse(User::factory()->create(['role_id' => $custom->id])->hasPermission('literary_reports', 'view'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`
Expected: FAIL — `Class "App\Models\LiteraryReport" not found`.

- [ ] **Step 3: Migration**

`database/migrations/2026_11_01_200001_create_literary_reports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE = 'literary_reports';

    public function up(): void
    {
        Schema::create('literary_reports', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('period_from');
            $table->date('period_to');
            $table->json('sections');
            $table->longText('president_word')->nullable();
            $table->longText('introduction')->nullable();
            $table->longText('conclusion')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // New rights start empty for every custom role; system roles get them.
        $all = ['view', 'add', 'edit', 'delete'];
        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions[self::MODULE] = $all;
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                unset($permissions[self::MODULE]);
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });

        Schema::dropIfExists('literary_reports');
    }
};
```

- [ ] **Step 4: Model**

`app/Models/LiteraryReport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved edition of the club's year-end literary report: its period, the
 * sections to print and the editorial texts. Figures are never stored; the
 * PDF is computed live from the club's data at export.
 */
class LiteraryReport extends Model
{
    /** Every section, in the fixed print order. */
    public const SECTIONS = ['cover', 'intro', 'members', 'board', 'attendance', 'sports', 'activities', 'finance', 'conclusion'];

    protected $fillable = [
        'title', 'period_from', 'period_to', 'sections',
        'president_word', 'introduction', 'conclusion', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'sections' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return list<string> the enabled sections in print order (unknown keys dropped) */
    public function enabledSections(): array
    {
        return array_values(array_intersect(self::SECTIONS, $this->sections ?? []));
    }
}
```

- [ ] **Step 5: Role module**

In `app/Models/Role.php`: `MODULE_GROUPS['social']` becomes `['activities', 'literary_reports']`; in `MODULES` the line `'activities',` becomes `'activities', 'literary_reports',`. In `tests/Unit/RoleModelTest.php` change `$this->assertCount(30, $all);` to `$this->assertCount(31, $all);`.

- [ ] **Step 6: Run the test**

Run: `php artisan test --compact tests/Feature/LiteraryReport/LiteraryReportCrudTest.php tests/Unit/RoleModelTest.php`
Expected: PASS.

- [ ] **Step 7: Permissions config**

In `config/permissions.php` `modules`, next to `'activities' => 'activities',` add:

```php
        'literary-reports' => 'literary_reports',
```

In `overrides`, after the `activities.files.destroy` line add:

```php
        // Exporting the literary report is reading it.
        'literary-reports.pdf' => ['literary_reports', 'view'],
```

- [ ] **Step 8: Activity log codes**

`app/Services/Activity/ActivityAction.php`, after `const ACTIVITY_DELETED = 'activity_deleted';`:

```php

    const LITERARY_REPORT_CREATED = 'literary_report_created';

    const LITERARY_REPORT_UPDATED = 'literary_report_updated';

    const LITERARY_REPORT_DELETED = 'literary_report_deleted';
```

Add the three constants to `ALL` right after `self::ACTIVITY_DELETED,` and to `AREAS['social']` right after `self::ACTIVITY_DELETED,` (not to `QUALITY`, same as the activity codes). In `tests/Unit/ActivityRecorderTest.php` change `assertCount(113, ActivityAction::ALL)` to `assertCount(116, ActivityAction::ALL)`.

`app/Services/Activity/ActivitySubjectLink.php`: `use App\Models\LiteraryReport;`; in `MODULES` after `Activity::class => ['activities'],` add `LiteraryReport::class => ['literary_reports'],`; in the `match` after the `Activity` arm add:

```php
            $subject instanceof LiteraryReport => [(string) $subject->title, route('literary-reports.edit', $subject)],
```

`config/activity.php`, after `'activities.destroy' => ActivityAction::ACTIVITY_DELETED,`:

```php
    // literary reports (creation is recorded in LiteraryReportController::store)
    'literary-reports.update' => ActivityAction::LITERARY_REPORT_UPDATED,
    'literary-reports.destroy' => ActivityAction::LITERARY_REPORT_DELETED,
```

NOTE: these two route names do not exist until Task 2. If `tests/Feature/UsersBatchTest.php` or `tests/Unit/ActivityCoverageTest.php` fails because the routes are missing, move the two `config/activity.php` lines and the `ActivitySubjectLink` `route(...)` arm into Task 2 (P1 hit exactly this) and say so in the report.

- [ ] **Step 9: Navigation**

`resources/js/Composables/useNavigation.js`, in the `social` section items, after the activities item:

```js
                { label: t('literary_reports'), href: '/literary-reports', icon: 'document', prefix: '/literary-reports', module: 'literary_reports' },
```

Check `resources/js/Components/Icon.vue` has a `document` icon (grep `document`); if not, use one that exists for documents (e.g. the one the documents nav item uses) and keep it.

- [ ] **Step 10: i18n**

Write `/tmp`-free scratch file `storage/app/i18n-literary-1.json` (delete after):

```json
{
  "literary_reports": { "ar": "التقرير الأدبي", "fr": "Rapport moral", "en": "Literary report" },
  "activity.action.literary_report_created": { "ar": "إنشاء تقرير أدبي", "fr": "Rapport moral créé", "en": "Literary report created" },
  "activity.action.literary_report_updated": { "ar": "تعديل تقرير أدبي", "fr": "Rapport moral modifié", "en": "Literary report updated" },
  "activity.action.literary_report_deleted": { "ar": "حذف تقرير أدبي", "fr": "Rapport moral supprimé", "en": "Literary report deleted" }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-1.json && rm storage/app/i18n-literary-1.json && npm run i18n:check`
Expected: check green. If the permission grid shows module labels from another key family (grep `PermissionGrid.vue` for how `activities` gets its label), add the same for `literary_reports`.

- [ ] **Step 11: Verify and commit**

Run: `php artisan test --compact tests/Feature/LiteraryReport tests/Unit/RoleModelTest.php tests/Unit/ActivityRecorderTest.php tests/Unit/ActivityCoverageTest.php tests/Feature/UsersBatchTest.php tests/Feature/ActivityReportTest.php`
Expected: PASS. Then `vendor/bin/pint --dirty`.

```bash
git add database/migrations/2026_11_01_200001_create_literary_reports_table.php app/Models/LiteraryReport.php app/Models/Role.php config/permissions.php config/activity.php app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php resources/js/Composables/useNavigation.js resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/LiteraryReportCrudTest.php tests/Unit/RoleModelTest.php tests/Unit/ActivityRecorderTest.php
git commit -m "feat(literary-report): schema, model, module and activity codes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: CRUD — controller, request, routes, Vue pages

**Files:**
- Create: `app/Http/Requests/LiteraryReportRequest.php`, `app/Http/Controllers/LiteraryReportController.php`, `resources/js/Pages/LiteraryReports/Index.vue`, `resources/js/Pages/LiteraryReports/Edit.vue`
- Modify: `routes/web.php`, i18n catalogs
- Test: `tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`

**Interfaces:**
- Consumes: `LiteraryReport` (Task 1).
- Produces: routes `literary-reports.index|store|edit|update|destroy|pdf`. `pdf` is wired here to a method that Task 3 fills; in this task it may `abort(501)` — Task 3 replaces it. `LiteraryReportBuilder::allows(string $key, User $user): bool` is needed by `edit`; create the class in this task with only that method and the `MODULES` constant (Task 3 adds `build()`):

```php
<?php

namespace App\Services\LiteraryReport;

use App\Models\User;

final class LiteraryReportBuilder
{
    /** The module whose view right each data section needs; other sections need none. */
    public const MODULES = [
        'members' => 'players',
        'board' => 'board',
        'attendance' => 'attendance',
        'sports' => 'games',
        'activities' => 'activities',
        'finance' => 'finance',
    ];

    public static function allows(string $key, User $user): bool
    {
        return ! isset(self::MODULES[$key]) || $user->hasPermission(self::MODULES[$key], 'view');
    }
}
```

- [ ] **Step 1: Write the failing tests** (append to `LiteraryReportCrudTest`; add imports `App\Models\ActivityLog`, `App\Services\Activity\ActivityAction`, `Inertia\Testing\AssertableInertia as Assert`)

```php
    #[Test]
    public function the_index_lists_reports_and_needs_the_view_right(): void
    {
        $this->report();
        $this->actingAs($this->admin())->get('/literary-reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LiteraryReports/Index')->has('reports', 1)->has('defaults.period_from'));

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get('/literary-reports')->assertForbidden();
    }

    #[Test]
    public function creating_a_report_enables_every_section_and_opens_the_editor(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->post('/literary-reports', [
            'title' => 'Rapport 2025/26', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
        ]);

        $report = LiteraryReport::sole();
        $response->assertRedirect(route('literary-reports.edit', $report));
        $this->assertSame(LiteraryReport::SECTIONS, $report->sections);
        $this->assertSame($admin->id, $report->created_by_user_id);
        $this->assertTrue(ActivityLog::where('action', ActivityAction::LITERARY_REPORT_CREATED)->where('subject_id', $report->id)->exists());
    }

    #[Test]
    public function the_period_end_cannot_precede_its_start_and_sections_must_be_known(): void
    {
        $this->actingAs($this->admin())->post('/literary-reports', [
            'title' => 'X', 'period_from' => '2026-08-31', 'period_to' => '2026-01-01',
        ])->assertSessionHasErrors('period_to');

        $report = $this->report();
        $this->actingAs($this->admin())->put("/literary-reports/{$report->id}", [
            'title' => 'X', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['cover', 'gossip'],
        ])->assertSessionHasErrors('sections.1');
    }

    #[Test]
    public function updating_saves_texts_and_section_choice(): void
    {
        $report = $this->report();
        $this->actingAs($this->admin())->put("/literary-reports/{$report->id}", [
            'title' => 'Final', 'period_from' => '2025-09-01', 'period_to' => '2026-06-30',
            'sections' => ['cover', 'activities'],
            'president_word' => "سطر أول\nسطر ثان", 'introduction' => null, 'conclusion' => 'Outlook',
        ])->assertRedirect()->assertSessionHas('success', 'flash.literary_report_updated');

        $fresh = $report->fresh();
        $this->assertSame(['cover', 'activities'], $fresh->sections);
        $this->assertSame("سطر أول\nسطر ثان", $fresh->president_word);
        $this->assertSame('2026-06-30', $fresh->toArray()['period_to']);
    }

    #[Test]
    public function the_editor_marks_sections_the_user_cannot_see(): void
    {
        $report = $this->report();
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => [
            'literary_reports' => ['view', 'edit'], 'players' => ['view'],
        ]])->id]);

        $this->actingAs($user)->get("/literary-reports/{$report->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LiteraryReports/Edit')
                ->where('report.period_from', '2025-09-01')
                ->where('sections', fn ($sections) => collect($sections)->pluck('allowed', 'key')->all() === [
                    'cover' => true, 'intro' => true, 'members' => true, 'board' => false, 'attendance' => false,
                    'sports' => false, 'activities' => false, 'finance' => false, 'conclusion' => true,
                ]));
    }

    #[Test]
    public function deleting_removes_the_report(): void
    {
        $report = $this->report();
        $this->actingAs($this->admin())->delete("/literary-reports/{$report->id}")
            ->assertRedirect(route('literary-reports.index'));
        $this->assertDatabaseCount('literary_reports', 0);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`
Expected: FAIL — 404 on `/literary-reports`.

- [ ] **Step 3: Request**

`app/Http/Requests/LiteraryReportRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\LiteraryReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LiteraryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's permission middleware already decided
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['string', Rule::in(LiteraryReport::SECTIONS)],
            'president_word' => ['nullable', 'string', 'max:20000'],
            'introduction' => ['nullable', 'string', 'max:20000'],
            'conclusion' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
```

- [ ] **Step 4: Controller**

`app/Http/Controllers/LiteraryReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiteraryReportRequest;
use App\Models\LiteraryReport;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class LiteraryReportController extends Controller
{
    public function index(): Response
    {
        $season = Season::current();

        return Inertia::render('LiteraryReports/Index', [
            'reports' => LiteraryReport::query()->latest('period_to')->latest('id')
                ->get(['id', 'title', 'period_from', 'period_to', 'updated_at']),
            // A new report proposes the current season as its period.
            'defaults' => [
                'period_from' => $season->start()->toDateString(),
                'period_to' => $season->end()->toDateString(),
            ],
        ]);
    }

    public function store(LiteraryReportRequest $request): RedirectResponse
    {
        $report = LiteraryReport::create($request->validated() + [
            'sections' => LiteraryReport::SECTIONS,
            'created_by_user_id' => $request->user()->id,
        ]);

        ActivityRecorder::record($request->user(), ActivityAction::LITERARY_REPORT_CREATED, $report);

        return redirect()->route('literary-reports.edit', $report)->with('success', 'flash.literary_report_created');
    }

    public function edit(Request $request, LiteraryReport $literaryReport): Response
    {
        return Inertia::render('LiteraryReports/Edit', [
            'report' => Arr::only($literaryReport->toArray(), [
                'id', 'title', 'period_from', 'period_to', 'sections', 'president_word', 'introduction', 'conclusion',
            ]),
            'sections' => array_map(fn (string $key) => [
                'key' => $key,
                'allowed' => LiteraryReportBuilder::allows($key, $request->user()),
            ], LiteraryReport::SECTIONS),
        ]);
    }

    public function update(LiteraryReportRequest $request, LiteraryReport $literaryReport): RedirectResponse
    {
        $literaryReport->update($request->validated());

        return back()->with('success', 'flash.literary_report_updated');
    }

    public function destroy(LiteraryReport $literaryReport): RedirectResponse
    {
        $literaryReport->delete();

        return redirect()->route('literary-reports.index')->with('success', 'flash.literary_report_deleted');
    }

    public function pdf(LiteraryReport $literaryReport): never
    {
        abort(501); // Task 3
    }
}
```

- [ ] **Step 5: Routes**

`routes/web.php`, right after the activities block (after the `activities.files.destroy` route's closing group), inside the same middleware group:

```php
        // Literary report (P2): saved editions, PDF computed live.
        Route::resource('literary-reports', LiteraryReportController::class)->except(['show', 'create']);
        Route::get('/literary-reports/{literaryReport}/pdf', [LiteraryReportController::class, 'pdf'])->name('literary-reports.pdf');
```

Add `use App\Http\Controllers\LiteraryReportController;` with the other controller imports (edit with the Edit tool, not sed — P1 mangled backslashes with sed).

- [ ] **Step 6: i18n for the pages**

`storage/app/i18n-literary-2.json`:

```json
{
  "flash.literary_report_created": { "ar": "تم إنشاء التقرير الأدبي", "fr": "Rapport moral créé", "en": "Literary report created" },
  "flash.literary_report_updated": { "ar": "تم حفظ التقرير الأدبي", "fr": "Rapport moral enregistré", "en": "Literary report saved" },
  "flash.literary_report_deleted": { "ar": "تم حذف التقرير الأدبي", "fr": "Rapport moral supprimé", "en": "Literary report deleted" },
  "literary.new": { "ar": "تقرير جديد", "fr": "Nouveau rapport", "en": "New report" },
  "literary.report_title": { "ar": "عنوان التقرير", "fr": "Titre du rapport", "en": "Report title" },
  "literary.period": { "ar": "الفترة", "fr": "Période", "en": "Period" },
  "literary.period_from": { "ar": "من", "fr": "Du", "en": "From" },
  "literary.period_to": { "ar": "إلى", "fr": "Au", "en": "To" },
  "literary.updated": { "ar": "آخر تعديل", "fr": "Modifié le", "en": "Last updated" },
  "literary.empty": { "ar": "لا توجد تقارير أدبية بعد", "fr": "Aucun rapport moral pour l'instant", "en": "No literary reports yet" },
  "literary.sections": { "ar": "أقسام التقرير", "fr": "Sections du rapport", "en": "Report sections" },
  "literary.section.cover": { "ar": "الغلاف", "fr": "Couverture", "en": "Cover" },
  "literary.section.intro": { "ar": "كلمة الرئيس والمقدمة", "fr": "Mot du président et introduction", "en": "President's word and introduction" },
  "literary.section.members": { "ar": "المنخرطون", "fr": "Adhérents", "en": "Members" },
  "literary.section.board": { "ar": "المكتب المسيّر", "fr": "Bureau directeur", "en": "Board" },
  "literary.section.attendance": { "ar": "التدريبات والحضور", "fr": "Entraînements et présences", "en": "Training and attendance" },
  "literary.section.sports": { "ar": "النتائج الرياضية", "fr": "Résultats sportifs", "en": "Sports results" },
  "literary.section.activities": { "ar": "الأنشطة الاجتماعية", "fr": "Activités sociales", "en": "Social activities" },
  "literary.section.finance": { "ar": "الملخص المالي", "fr": "Résumé financier", "en": "Financial summary" },
  "literary.section.conclusion": { "ar": "الخاتمة والآفاق", "fr": "Conclusion et perspectives", "en": "Conclusion and outlook" },
  "literary.no_right": { "ar": "لا تملك صلاحية عرض هذا القسم، فلن يظهر في ملفك", "fr": "Vous n'avez pas accès à cette section : elle sera omise de votre export", "en": "You cannot view this section, so your export will leave it out" },
  "literary.texts": { "ar": "النصوص التحريرية", "fr": "Textes rédactionnels", "en": "Editorial texts" },
  "literary.president_word": { "ar": "كلمة الرئيس", "fr": "Mot du président", "en": "President's word" },
  "literary.introduction": { "ar": "المقدمة", "fr": "Introduction", "en": "Introduction" },
  "literary.conclusion": { "ar": "الخاتمة والآفاق", "fr": "Conclusion et perspectives", "en": "Conclusion and outlook" },
  "literary.export_pdf": { "ar": "تصدير PDF", "fr": "Exporter en PDF", "en": "Export PDF" },
  "literary.save_first": { "ar": "احفظ التغييرات قبل التصدير", "fr": "Enregistrez avant d'exporter", "en": "Save your changes before exporting" }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-2.json && rm storage/app/i18n-literary-2.json`

- [ ] **Step 7: Index page**

`resources/js/Pages/LiteraryReports/Index.vue` (mirror the look of `Pages/Settings/ActivityTypes.vue`):

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ reports: Array, defaults: Object });

const deleteId = ref(null);
const form = useForm({ title: '', period_from: props.defaults.period_from, period_to: props.defaults.period_to });

function add() {
    form.post(route('literary-reports.store'));
}

function destroy() {
    router.delete(route('literary-reports.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('literary_reports')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('literary_reports') }}</h1>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <form v-if="can('literary_reports', 'add')" @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="min-w-56 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.report_title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_from') }}</label>
                    <TextInput v-model="form.period_from" type="date" class="mt-1" required />
                    <InputError :message="form.errors.period_from" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_to') }}</label>
                    <TextInput v-model="form.period_to" type="date" class="mt-1" required />
                    <InputError :message="form.errors.period_to" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('literary.new') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('literary.report_title') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('literary.period') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="report in reports" :key="report.id">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                                <Link v-if="can('literary_reports', 'edit')" :href="route('literary-reports.edit', report.id)" class="hover:text-primary-700">{{ report.title }}</Link>
                                <span v-else>{{ report.title }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ report.period_from }} → {{ report.period_to }}</bdi></td>
                            <td class="px-4 py-3 text-end">
                                <div class="flex justify-end gap-2">
                                    <a :href="route('literary-reports.pdf', report.id)" target="_blank" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-slate-800">
                                        <Icon name="download" class="h-4 w-4" /> {{ t('literary.export_pdf') }}
                                    </a>
                                    <IconButton v-if="can('literary_reports', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="router.visit(route('literary-reports.edit', report.id))" />
                                    <IconButton v-if="can('literary_reports', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = report.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!reports.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('literary.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

Check `Icon.vue` has `download`; if not, use the icon other pages use for PDF/print links (grep `pdf` in `Pages/` for the name) — keep it consistent.

- [ ] **Step 8: Edit page**

`resources/js/Pages/LiteraryReports/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({ report: Object, sections: Array });

const form = useForm({
    title: props.report.title,
    period_from: props.report.period_from,
    period_to: props.report.period_to,
    sections: [...(props.report.sections ?? [])],
    president_word: props.report.president_word ?? '',
    introduction: props.report.introduction ?? '',
    conclusion: props.report.conclusion ?? '',
});

const texts = ['president_word', 'introduction', 'conclusion'];

function save() {
    form.put(route('literary-reports.update', props.report.id), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Head :title="form.title || t('literary_reports')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                    <Link :href="route('literary-reports.index')" class="text-slate-500 hover:text-slate-700 dark:text-slate-400">{{ t('literary_reports') }}</Link>
                    <span class="mx-2 text-slate-400">/</span>{{ report.title }}
                </h1>
                <a v-if="!form.isDirty" :href="route('literary-reports.pdf', report.id)" target="_blank" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <Icon name="download" class="h-4 w-4" /> {{ t('literary.export_pdf') }}
                </a>
                <span v-else class="text-sm text-amber-700 dark:text-amber-400">{{ t('literary.save_first') }}</span>
            </div>
        </template>

        <form @submit.prevent="save" class="mx-auto max-w-4xl space-y-6">
            <section class="grid gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.report_title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_from') }}</label>
                    <TextInput v-model="form.period_from" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.period_from" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.period_to') }}</label>
                    <TextInput v-model="form.period_to" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.period_to" class="mt-1" />
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('literary.sections') }}</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="section in sections" :key="section.key">
                        <label class="flex items-start gap-2 text-sm" :class="section.allowed ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400 dark:text-slate-500'">
                            <input v-model="form.sections" type="checkbox" :value="section.key" :disabled="!section.allowed" class="mt-0.5 rounded border-slate-300 text-primary-600" />
                            <span>
                                {{ t(`literary.section.${section.key}`) }}
                                <span v-if="!section.allowed" class="block text-xs">{{ t('literary.no_right') }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <InputError :message="form.errors.sections" class="mt-2" />
            </section>

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('literary.texts') }}</h2>
                <div v-for="field in texts" :key="field">
                    <label :for="field" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t(`literary.${field}`) }}</label>
                    <textarea :id="field" v-model="form[field]" rows="8" class="mt-1 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                    <InputError :message="form.errors[field]" class="mt-1" />
                </div>
            </section>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing || !form.isDirty">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
```

Note `literary.section.${key}` and `literary.${field}` are dynamic keys: every value they can take is in the catalogs (Step 6). If `npm run i18n:check` reports dynamic template keys it cannot resolve, list the concrete keys in the script's allow-list the same way existing dynamic keys are handled (grep the script for `activity.action.`).

- [ ] **Step 9: Build, test, commit**

Run: `npm run i18n:check && npm run build && php artisan test --compact tests/Feature/LiteraryReport tests/Feature/UsersBatchTest.php tests/Unit/ActivityCoverageTest.php`
Expected: PASS. `vendor/bin/pint --dirty`.

```bash
git add app/Http/Requests/LiteraryReportRequest.php app/Http/Controllers/LiteraryReportController.php app/Services/LiteraryReport/LiteraryReportBuilder.php routes/web.php resources/js/Pages/LiteraryReports/Index.vue resources/js/Pages/LiteraryReports/Edit.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/LiteraryReportCrudTest.php
git commit -m "feat(literary-report): list, create, edit texts and sections

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Add `config/activity.php` / `ActivitySubjectLink.php` to the `git add` if Task 1 deferred them here.)

---

### Task 3: Builder, PDF route, document shell, activities section

**Files:**
- Create: `app/Services/LiteraryReport/Sections/Section.php`, `app/Services/LiteraryReport/Sections/ActivitiesSection.php`, `resources/views/pdf/literary-report.blade.php`, `resources/views/pdf/literary/{cover,intro,conclusion,activities}.blade.php`
- Modify: `app/Services/LiteraryReport/LiteraryReportBuilder.php`, `app/Http/Controllers/LiteraryReportController.php` (`pdf`), i18n catalogs
- Test: `tests/Feature/LiteraryReport/ActivitiesSectionTest.php`, `tests/Feature/LiteraryReport/LiteraryReportPdfTest.php`

**Interfaces:**
- Produces:
  - `interface Section { public function build(CarbonImmutable $from, CarbonImmutable $to): array; }` — `$from`/`$to` are start-of-day dates, inclusive.
  - `LiteraryReportBuilder::build(LiteraryReport $report, User $user): array` → `list<array{key: string, data: array}>` (enabled, permitted, intro/conclusion dropped when empty).
  - `LiteraryReportBuilder::DATA_SECTIONS` maps key → Section class. Tasks 4–6 add their classes there; until then the builder skips keys whose class is not registered yet (so the PDF works at every task boundary).
  - Blade partials receive `$data` (the section's array) plus `$report`, `$club` and the label helper `$l` from the parent view.

- [ ] **Step 1: Failing tests**

`tests/Feature/LiteraryReport/ActivitiesSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\Player;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitiesSectionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'literary-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/'.$this->dir));
        parent::tearDown();
    }

    private function build(): array
    {
        return app(ActivitiesSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function activity(string $type, string $startsOn, array $extra = []): Activity
    {
        return Activity::create($extra + [
            'title' => 'Act '.uniqid(), 'activity_type_id' => ActivityType::where('name', $type)->value('id'), 'starts_on' => $startsOn,
        ]);
    }

    #[Test]
    public function it_summarises_activities_of_the_period_by_type(): void
    {
        $camp = $this->activity('مخيم صيفي', '2026-07-05', ['beneficiaries_count' => 40, 'external_volunteers_count' => 3]);
        $camp->players()->attach(Player::create(['membership_id' => 'A1', 'firstname' => 'Sami', 'lastname' => 'Z'])->id);
        $this->activity('مخيم صيفي', '2026-08-10', ['beneficiaries_count' => 10]);
        $this->activity('جلسة توعوية', '2025-10-01', ['beneficiaries_count' => 25, 'external_volunteers_count' => 1]);
        $this->activity('جلسة توعوية', '2025-08-31', ['beneficiaries_count' => 99]); // before the period

        $data = $this->build();

        $this->assertSame(3, $data['count']);
        $this->assertEquals([
            ['type' => 'جلسة توعوية', 'count' => 1, 'beneficiaries' => 25, 'participants' => 1],
            ['type' => 'مخيم صيفي', 'count' => 2, 'beneficiaries' => 50, 'participants' => 4],
        ], $data['by_type']);
        $this->assertSame(['totals' => ['count' => 3, 'beneficiaries' => 75, 'participants' => 5]], ['totals' => $data['totals']]);
        $this->assertSame('2025-10-01', $data['activities'][0]['starts_on']); // chronological
    }

    #[Test]
    public function only_report_photos_whose_file_exists_are_printed(): void
    {
        $activity = $this->activity('مخيم صيفي', '2026-07-05');
        File::ensureDirectoryExists(storage_path('app/public/'.$this->dir));
        File::put(storage_path("app/public/{$this->dir}/a.jpg"), 'jpeg-bytes');
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/a.jpg", 'thumb_path' => "{$this->dir}/a.jpg", 'in_report' => true, 'caption' => 'Opening']);
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/missing.jpg", 'thumb_path' => "{$this->dir}/missing.jpg", 'in_report' => true]);
        ActivityPhoto::create(['activity_id' => $activity->id, 'path' => "{$this->dir}/a.jpg", 'thumb_path' => "{$this->dir}/a.jpg", 'in_report' => false]);

        $photos = $this->build()['activities'][0]['photos'];

        $this->assertCount(1, $photos);
        $this->assertSame('Opening', $photos[0]['caption']);
        $this->assertFileExists($photos[0]['file']);
    }

    #[Test]
    public function an_empty_period_gives_zero_counts(): void
    {
        $this->assertSame(0, $this->build()['count']);
    }
}
```

`tests/Feature/LiteraryReport/LiteraryReportPdfTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiteraryReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $attributes = []): LiteraryReport
    {
        return LiteraryReport::create($attributes + [
            'title' => 'Rapport', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS,
            'president_word' => "Mot\nsur deux lignes", 'conclusion' => 'Perspectives',
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_pdf_streams_with_every_section_enabled(): void
    {
        $admin = User::factory()->admin()->create();
        $report = $this->report();

        $this->actingAs($admin)->get("/literary-reports/{$report->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function the_pdf_needs_the_literary_reports_view_right(): void
    {
        $report = $this->report();
        $this->actingAs($this->userWith([]))->get("/literary-reports/{$report->id}/pdf")->assertForbidden();
        $this->actingAs($this->userWith(['literary_reports' => ['view']]))->get("/literary-reports/{$report->id}/pdf")->assertOk();
    }

    #[Test]
    public function sections_without_the_source_right_are_omitted_and_empty_texts_skipped(): void
    {
        $user = $this->userWith(['literary_reports' => ['view'], 'activities' => ['view']]);
        $keys = array_column(app(LiteraryReportBuilder::class)->build($this->report(['introduction' => null]), $user), 'key');

        $this->assertSame(['cover', 'intro', 'activities', 'conclusion'], $keys);

        $noTexts = $this->report(['president_word' => '  ', 'conclusion' => null]);
        $this->assertSame(['cover', 'activities'], array_column(app(LiteraryReportBuilder::class)->build($noTexts, $user), 'key'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/LiteraryReport/ActivitiesSectionTest.php tests/Feature/LiteraryReport/LiteraryReportPdfTest.php`
Expected: FAIL — `ActivitiesSection` not found / 501.

- [ ] **Step 3: Section interface**

`app/Services/LiteraryReport/Sections/Section.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use Carbon\CarbonImmutable;

/** One computed chapter of the literary report: plain data for its Blade partial. */
interface Section
{
    /**
     * @param  CarbonImmutable  $from  first day of the period (inclusive)
     * @param  CarbonImmutable  $to  last day of the period (inclusive)
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array;
}
```

- [ ] **Step 4: Activities section**

`app/Services/LiteraryReport/Sections/ActivitiesSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Support\Media;
use Carbon\CarbonImmutable;

/** Social activities that started in the period: a summary by type, then one block per activity. */
final class ActivitiesSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $activities = Activity::query()
            ->with([
                'type:id,name',
                'photos' => fn ($q) => $q->where('in_report', true),
            ])
            ->withCount(['players', 'boardMembers'])
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_on')->orderBy('id')
            ->get();

        $byType = $activities->groupBy(fn (Activity $a) => $a->type?->name ?? '—')
            ->map(fn ($group, $type) => [
                'type' => (string) $type,
                'count' => $group->count(),
                'beneficiaries' => (int) $group->sum('beneficiaries_count'),
                'participants' => (int) $group->sum('participants_count'),
            ])
            ->sortBy('type')->values()->all();

        return [
            'count' => $activities->count(),
            'by_type' => $byType,
            'totals' => [
                'count' => $activities->count(),
                'beneficiaries' => (int) $activities->sum('beneficiaries_count'),
                'participants' => (int) $activities->sum('participants_count'),
            ],
            'activities' => $activities->map(fn (Activity $a) => [
                'title' => $a->title,
                'type' => $a->type?->name,
                'starts_on' => $a->starts_on?->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'location' => $a->location,
                'partners' => $a->partners,
                'beneficiaries' => (int) $a->beneficiaries_count,
                'participants' => (int) $a->participants_count,
                'report' => $a->report,
                'photos' => $a->photos
                    ->take(ActivityPhoto::REPORT_LIMIT)
                    ->map(fn (ActivityPhoto $p) => ['file' => Media::localFile($p->url), 'caption' => $p->caption])
                    ->filter(fn (array $p) => $p['file'] !== null)
                    ->values()->all(),
            ])->all(),
        ];
    }
}
```

`sortBy('type')` must give `جلسة توعوية` before `مخيم صيفي` (ج < م in code-point order) — the test pins that.

- [ ] **Step 5: Builder `build()`**

Add to `LiteraryReportBuilder`:

```php
use App\Models\LiteraryReport;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use Carbon\CarbonImmutable;

    /** Computed sections by key; Tasks 4–6 register theirs here. */
    public const DATA_SECTIONS = [
        'activities' => ActivitiesSection::class,
    ];

    /** @return list<array{key: string, data: array<string, mixed>}> enabled, permitted sections in print order */
    public function build(LiteraryReport $report, User $user): array
    {
        $from = CarbonImmutable::instance($report->period_from)->startOfDay();
        $to = CarbonImmutable::instance($report->period_to)->startOfDay();

        $sections = [];
        foreach ($report->enabledSections() as $key) {
            if (! self::allows($key, $user)) {
                continue;
            }

            $data = match ($key) {
                'cover' => [],
                'intro' => self::texts(['president_word' => $report->president_word, 'introduction' => $report->introduction]),
                'conclusion' => self::texts(['conclusion' => $report->conclusion]),
                default => isset(self::DATA_SECTIONS[$key]) ? app(self::DATA_SECTIONS[$key])->build($from, $to) : null,
            };

            // Editorial chapters with no text are left out; computed ones always print.
            if ($data === null || (in_array($key, ['intro', 'conclusion'], true) && $data === [])) {
                continue;
            }

            $sections[] = ['key' => $key, 'data' => $data];
        }

        return $sections;
    }

    /** @param array<string, ?string> $texts @return array<string, string> the non-blank texts */
    private static function texts(array $texts): array
    {
        return array_filter($texts, fn (?string $text) => trim((string) $text) !== '');
    }
```

(`$data === null` only happens for a data section not yet registered — remove nothing else; Task 6 leaves every key registered.)

- [ ] **Step 6: Controller `pdf()`**

Replace the Task 2 stub (imports: `App\Services\Pdf\ClubHeader`, `App\Services\Pdf\PdfService`, `Symfony\Component\HttpFoundation\Response as HttpResponse` — `PdfService::stream()` returns the Symfony Response, see `ReportController`):

```php
    public function pdf(Request $request, LiteraryReport $literaryReport, LiteraryReportBuilder $builder, PdfService $pdf): HttpResponse
    {
        $html = view('pdf.literary-report', [
            'club' => ClubHeader::data(),
            'report' => $literaryReport,
            'sections' => $builder->build($literaryReport, $request->user()),
        ])->render();

        return $pdf->stream($html, 'literary-report-'.$literaryReport->period_to->format('Y-m-d').'.pdf', app()->getLocale() === 'ar');
    }
```

- [ ] **Step 7: Main view**

`resources/views/pdf/literary-report.blade.php`:

```blade
@php
    $l = fn (string $key) => \App\Support\UiLang::get($key);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-size: 11px; color: #1e293b; line-height: 1.5; }
    h2 { font-size: 16px; color: #02a85c; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; margin: 0 0 10px; }
    h3 { font-size: 12.5px; color: #0f172a; margin: 12px 0 4px; }
    table.data { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    table.data th { background: #f1f5f9; border: 1px solid #94a3b8; padding: 3px; font-size: 10px; }
    table.data td { border: 1px solid #cbd5e1; padding: 3px; text-align: center; }
    table.data td.start { text-align: start; }
    table.data tr.total td { font-weight: bold; background: #f8fafc; }
    .kpis { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    .kpis td { border: 1px solid #cbd5e1; padding: 6px; text-align: center; }
    .kpis .value { font-size: 15px; font-weight: bold; color: #0f172a; }
    .kpis .label { font-size: 9.5px; color: #64748b; }
    .text { white-space: pre-wrap; text-align: justify; }
    .muted { color: #64748b; font-size: 10px; }
    .none { color: #64748b; font-style: italic; }
</style>
</head>
<body>
<htmlpagefooter name="footer"><div style="text-align: center; font-size: 8px; color: #94a3b8;">{{ $report->title }} — {{ $l('literary.pdf.page') }} {PAGENO}/{nbpg}</div></htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

@if (($sections[0]['key'] ?? null) !== 'cover')
    @include('pdf.partials.header')
@endif

@foreach ($sections as $section)
    @if (! $loop->first)
        <pagebreak />
    @endif
    @include('pdf.literary.'.$section['key'], ['data' => $section['data']])
@endforeach
</body>
</html>
```

- [ ] **Step 8: Partials (cover, intro, conclusion, activities)**

`resources/views/pdf/literary/cover.blade.php`:

```blade
<div style="text-align: center; padding-top: 70px;">
    @if (!empty($club['logo']))
        <img src="{{ $club['logo'] }}" style="width: 120px; height: 120px;">
    @endif
    <div style="font-size: 20px; font-weight: bold; color: #0f172a; margin-top: 18px;">{{ $club['name'] }}</div>
    <div style="font-size: 28px; font-weight: bold; color: #02a85c; margin-top: 50px;">{{ $report->title }}</div>
    <div style="font-size: 14px; color: #475569; margin-top: 14px;">
        {{ $l('literary.period_from') }} <bdi dir="ltr">{{ $report->period_from->format('Y-m-d') }}</bdi>
        {{ $l('literary.period_to') }} <bdi dir="ltr">{{ $report->period_to->format('Y-m-d') }}</bdi>
    </div>
    @if (!empty($club['address']))
        <div style="font-size: 11px; color: #64748b; margin-top: 90px;">{{ $club['address'] }}</div>
    @endif
</div>
```

`resources/views/pdf/literary/intro.blade.php`:

```blade
<h2>{{ $l('literary.section.intro') }}</h2>
@foreach (['president_word', 'introduction'] as $field)
    @if (isset($data[$field]))
        <h3>{{ $l('literary.'.$field) }}</h3>
        <div class="text">{{ $data[$field] }}</div>
    @endif
@endforeach
```

`resources/views/pdf/literary/conclusion.blade.php`:

```blade
<h2>{{ $l('literary.section.conclusion') }}</h2>
<div class="text">{{ $data['conclusion'] }}</div>
```

`resources/views/pdf/literary/activities.blade.php`:

```blade
<h2>{{ $l('literary.section.activities') }}</h2>
@if ($data['count'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <h3>{{ $l('literary.pdf.by_type') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.type') }}</th><th>{{ $l('literary.pdf.count') }}</th><th>{{ $l('literary.pdf.beneficiaries') }}</th><th>{{ $l('literary.pdf.participants') }}</th></tr>
        @foreach ($data['by_type'] as $row)
            <tr><td class="start">{{ $row['type'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['beneficiaries'] }}</td><td>{{ $row['participants'] }}</td></tr>
        @endforeach
        <tr class="total"><td class="start">{{ $l('literary.pdf.total') }}</td><td>{{ $data['totals']['count'] }}</td><td>{{ $data['totals']['beneficiaries'] }}</td><td>{{ $data['totals']['participants'] }}</td></tr>
    </table>

    @foreach ($data['activities'] as $activity)
        <div style="page-break-inside: avoid; margin-top: 12px;">
            <h3>{{ $activity['title'] }}</h3>
            <div class="muted">
                {{ $activity['type'] }} ·
                <bdi dir="ltr">{{ $activity['starts_on'] }}@if ($activity['ends_on'] && $activity['ends_on'] !== $activity['starts_on']) → {{ $activity['ends_on'] }}@endif</bdi>
                @if ($activity['location']) · {{ $l('literary.pdf.location') }}: {{ $activity['location'] }}@endif
            </div>
            @if ($activity['partners'])
                <div class="muted">{{ $l('literary.pdf.partners') }}: {{ $activity['partners'] }}</div>
            @endif
            <div class="muted">{{ $l('literary.pdf.beneficiaries') }}: {{ $activity['beneficiaries'] }} · {{ $l('literary.pdf.participants') }}: {{ $activity['participants'] }}</div>
            @if ($activity['report'])
                <div class="text" style="margin-top: 4px;">{{ $activity['report'] }}</div>
            @endif
            @if ($activity['photos'])
                <table style="width: 100%; margin-top: 6px;">
                    @foreach (array_chunk($activity['photos'], 2) as $pair)
                        <tr>
                            @foreach ($pair as $photo)
                                <td style="width: 50%; text-align: center; vertical-align: top; padding: 3px;">
                                    <img src="{{ $photo['file'] }}" style="max-width: 100%; max-height: 220px;">
                                    @if ($photo['caption'])<div class="muted">{{ $photo['caption'] }}</div>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach
@endif
```

- [ ] **Step 9: i18n for the PDF shell**

`storage/app/i18n-literary-3.json`:

```json
{
  "literary.pdf.page": { "ar": "صفحة", "fr": "Page", "en": "Page" },
  "literary.pdf.no_data": { "ar": "لا توجد بيانات لهذه الفترة.", "fr": "Aucune donnée pour cette période.", "en": "No data for this period." },
  "literary.pdf.by_type": { "ar": "ملخص حسب النوع", "fr": "Synthèse par type", "en": "Summary by type" },
  "literary.pdf.type": { "ar": "النوع", "fr": "Type", "en": "Type" },
  "literary.pdf.count": { "ar": "العدد", "fr": "Nombre", "en": "Count" },
  "literary.pdf.beneficiaries": { "ar": "المستفيدون", "fr": "Bénéficiaires", "en": "Beneficiaries" },
  "literary.pdf.participants": { "ar": "المشاركون", "fr": "Participants", "en": "Participants" },
  "literary.pdf.location": { "ar": "المكان", "fr": "Lieu", "en": "Location" },
  "literary.pdf.partners": { "ar": "الشركاء", "fr": "Partenaires", "en": "Partners" },
  "literary.pdf.total": { "ar": "المجموع", "fr": "Total", "en": "Total" }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-3.json && rm storage/app/i18n-literary-3.json && npm run i18n:check`

- [ ] **Step 10: Test, look at it, commit**

Run: `php artisan test --compact tests/Feature/LiteraryReport`
Expected: PASS.

Render one real PDF to eyeball RTL layout (sqlite dev copy in the worktree, any admin user):

```bash
php artisan tinker --execute='$r = App\Models\LiteraryReport::create(["title"=>"تجربة","period_from"=>"2025-09-01","period_to"=>"2026-08-31","sections"=>App\Models\LiteraryReport::SECTIONS,"president_word"=>"سطر\nسطر"]); app()->setLocale("ar"); $u = App\Models\User::whereJsonContains("privileges","superadmin")->first(); $html = view("pdf.literary-report", ["club"=>App\Services\Pdf\ClubHeader::data(),"report"=>$r,"sections"=>app(App\Services\LiteraryReport\LiteraryReportBuilder::class)->build($r,$u)])->render(); file_put_contents(storage_path("app/literary-check.pdf"), app(App\Services\Pdf\PdfService::class)->render($html)); $r->delete();'
```

Skip this step if the worktree has no `database/database.sqlite`; say so in the report. Delete `storage/app/literary-check.pdf` afterwards. `vendor/bin/pint --dirty`.

```bash
git add app/Services/LiteraryReport/Sections/Section.php app/Services/LiteraryReport/Sections/ActivitiesSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php app/Http/Controllers/LiteraryReportController.php resources/views/pdf/literary-report.blade.php resources/views/pdf/literary/cover.blade.php resources/views/pdf/literary/intro.blade.php resources/views/pdf/literary/conclusion.blade.php resources/views/pdf/literary/activities.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/ActivitiesSectionTest.php tests/Feature/LiteraryReport/LiteraryReportPdfTest.php
git commit -m "feat(literary-report): live PDF with cover, texts and social activities

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Members and board sections

**Files:**
- Create: `app/Services/LiteraryReport/Sections/MembersSection.php`, `app/Services/LiteraryReport/Sections/BoardSection.php`, `resources/views/pdf/literary/members.blade.php`, `resources/views/pdf/literary/board.blade.php`
- Modify: `LiteraryReportBuilder::DATA_SECTIONS` (add `'members' => MembersSection::class, 'board' => BoardSection::class`), i18n catalogs
- Test: `tests/Feature/LiteraryReport/MembersSectionTest.php`, `tests/Feature/LiteraryReport/BoardSectionTest.php`

**Interfaces:**
- Consumes: `Section` interface, `DATA_SECTIONS` (Task 3). `Player` (`archived`, `join_year`, `left_at`, `gender` 'Male'/'Female', `category_id`, scope `notLeft()`, `branch_player` pivot), `Team::coaches()` via `team_coaches(team_id, season_start_year, user_id, role)`, `App\Support\Season::forDate()->startYear`. Board: `BoardTerm(is_current)`, `BoardMember(status active|former, role → BoardRole.name, sort_order, scope withPlayer(), name accessor)`, `BoardRole(label, level in BoardRole::LEVELS, sort_order)`, `BoardCommittee(name, sort_order)->members()` pivot `position` head|deputy|member, `BoardMeeting(meeting_date datetime, title, type, status scheduled|held|cancelled, decisions json array)`.
- Produces: members data `{total, male, female, rows: list<{branch: ?string, category: ?string, male, female, total}>, multi_branch: bool, joined, left, coaches: list<{name, team, role}>}`; board data `{term: ?string, members: list<{name, role}>, committees: list<{name, head: ?string, deputies: list<string>, members_count}>, meetings: list<{date, title, type, decisions: list<string>}>}`.

- [ ] **Step 1: Failing tests**

`tests/Feature/LiteraryReport/MembersSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\LiteraryReport\Sections\MembersSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MembersSectionTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(MembersSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function active_members_follow_the_owner_rule(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $this->player($u15, ['join_year' => 2024]);                                   // counted
        $this->player($u15, ['join_year' => null, 'gender' => 'Female']);             // counted (no join year)
        $this->player($u15, ['join_year' => 2027]);                                   // joins after the period
        $this->player($u15, ['archived' => true]);                                    // archived
        $leftAfter = $this->player($u15, ['join_year' => 2020]);                      // left after the period end → counted
        $leftAfter->forceFill(['left_at' => '2026-10-01'])->saveQuietly();
        $leftDuring = $this->player($u15, ['join_year' => 2020]);                     // left during the period → not counted
        $leftDuring->forceFill(['left_at' => '2026-03-01'])->saveQuietly();
        $leftStatus = PlayerStatus::where('code', 'left')->first();
        if ($leftStatus) {                                                            // left status without a date → not counted
            $this->player($u15)->forceFill(['status_id' => $leftStatus->id, 'left_at' => null])->saveQuietly();
        }

        $data = $this->build();

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['female']);
        $this->assertSame(2, $data['male']);
        $this->assertSame(1, $data['left']);
    }

    #[Test]
    public function a_player_in_two_branches_is_counted_in_each_but_once_in_the_total(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $football = Branch::create(['name' => 'Football']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $both = $this->player($u15);
        $both->branches()->attach([$football->id, $swimming->id]);
        $this->player($u15)->branches()->attach($football->id);
        $this->player($u15); // no branch

        $data = $this->build();

        $this->assertSame(3, $data['total']);
        $this->assertTrue($data['multi_branch']);
        $byBranch = collect($data['rows'])->mapWithKeys(fn ($r) => [($r['branch'] ?? '-').'/'.$r['category'] => $r['total']])->all();
        $this->assertSame(['Football/U15' => 2, 'Swimming/U15' => 1, '-/U15' => 1], $byBranch);
    }

    #[Test]
    public function joins_coaches_and_an_empty_club(): void
    {
        $this->assertSame(0, $this->build()['total']);

        $u15 = Category::create(['name' => 'U15']);
        $this->player($u15, ['join_year' => 2025]);
        $this->player($u15, ['join_year' => 2026]);
        $this->player($u15, ['join_year' => 2024]);
        $team = $this->team($u15, ['name' => 'U15 A']);
        $coach = \App\Models\User::factory()->create(['name' => 'Coach Karim']);
        $this->assignCoach($team, $coach, 2025);
        $this->assignCoach($team, \App\Models\User::factory()->create(['name' => 'Old Coach']), 2023);

        $data = $this->build();

        $this->assertSame(2, $data['joined']);
        $this->assertSame([['name' => 'Coach Karim', 'team' => 'U15 A', 'role' => 'head']], $data['coaches']);
    }
}
```

If `User::factory()->create(['name' => …])` does not persist `name` (users have computed names — check `User` for a `name` mutator/accessor), create the coach with the factory's name fields instead and adjust the expected `name`; the section must print whatever `users.name` holds.

`tests/Feature/LiteraryReport/BoardSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Services\LiteraryReport\Sections\BoardSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardSectionTest extends TestCase
{
    use RefreshDatabase;

    private function build(): array
    {
        return app(BoardSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function it_lists_the_current_term_committees_and_meetings_of_the_period(): void
    {
        BoardRole::query()->delete();
        BoardRole::create(['name' => 'president', 'label' => 'الرئيس', 'level' => 'top', 'sort_order' => 1]);
        BoardRole::create(['name' => 'member', 'label' => 'عضو', 'level' => 'member', 'sort_order' => 9]);
        BoardTerm::create(['name' => '2017–2021', 'is_current' => false]);
        $term = BoardTerm::create(['name' => '2021–2025', 'is_current' => true]);
        $member = BoardMember::create(['name' => 'Ali', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active', 'sort_order' => 1]);
        $president = BoardMember::create(['name' => 'Omar', 'role' => 'president', 'board_term_id' => $term->id, 'status' => 'active', 'sort_order' => 2]);
        BoardMember::create(['name' => 'Gone', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'former']);

        $committee = BoardCommittee::create(['name' => 'Finance']);
        $committee->members()->attach($president->id, ['position' => 'head']);
        $committee->members()->attach($member->id, ['position' => 'deputy']);

        BoardMeeting::create(['title' => 'AG', 'type' => 'general_assembly', 'meeting_date' => '2026-06-20 18:00:00', 'status' => 'held', 'decisions' => ['Budget adopted']]);
        BoardMeeting::create(['title' => 'Cancelled', 'type' => 'ordinary', 'meeting_date' => '2026-02-01 18:00:00', 'status' => 'cancelled']);
        BoardMeeting::create(['title' => 'Old', 'type' => 'ordinary', 'meeting_date' => '2025-06-01 18:00:00', 'status' => 'held']);
        BoardMeeting::create(['title' => 'Last day', 'type' => 'ordinary', 'meeting_date' => '2026-08-31 20:00:00', 'status' => 'held']);

        $data = $this->build();

        $this->assertSame('2021–2025', $data['term']);
        $this->assertSame([['name' => 'Omar', 'role' => 'الرئيس'], ['name' => 'Ali', 'role' => 'عضو']], $data['members']);
        $this->assertSame([['name' => 'Finance', 'head' => 'Omar', 'deputies' => ['Ali'], 'members_count' => 2]], $data['committees']);
        $this->assertSame(['AG', 'Last day'], array_column($data['meetings'], 'title'));
        $this->assertSame(['Budget adopted'], $data['meetings'][0]['decisions']);
        $this->assertSame('2026-06-20', $data['meetings'][0]['date']);
    }

    #[Test]
    public function no_term_gives_empty_lists(): void
    {
        $data = $this->build();
        $this->assertNull($data['term']);
        $this->assertSame([], $data['members']);
        $this->assertSame([], $data['meetings']);
    }
}
```

Adjust the `BoardTerm::create` attributes if the table requires `start_date`/`end_date` (check its migration); keep the assertions.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/LiteraryReport/MembersSectionTest.php tests/Feature/LiteraryReport/BoardSectionTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Members section**

`app/Services/LiteraryReport/Sections/MembersSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Membership at the end of the period (owner rule 2026-11-01): not archived,
 * joined by that year (or no join year), and not left by that day. A player
 * in several branches counts in each branch row, once in the totals.
 */
final class MembersSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $end = $to->toDateString();
        $activeQuery = Player::query()
            ->where('archived', false)
            ->where(fn (Builder $q) => $q->whereNull('join_year')->orWhere('join_year', '<=', $to->year))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNull('left_at')->notLeft())
                ->orWhereDate('left_at', '>', $end));

        $active = (clone $activeQuery)->get(['id', 'category_id', 'gender']);
        $links = DB::table('branch_player')
            ->whereIn('player_id', (clone $activeQuery)->select('id'))
            ->get(['player_id', 'branch_id'])
            ->groupBy('player_id')
            ->map(fn ($rows) => $rows->pluck('branch_id')->all());

        $categories = Category::query()->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])->keyBy('id');
        $branches = Branch::query()->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])->keyBy('id');

        $rows = [];
        $female = 0;
        foreach ($active as $player) {
            $isFemale = strtolower((string) $player->gender) === 'female';
            $female += $isFemale ? 1 : 0;
            foreach ($links->get($player->id, [null]) as $branchId) {
                $key = ($branchId ?? 0).'|'.($player->category_id ?? 0);
                $rows[$key] ??= [
                    'branch' => $branchId ? $branches->get($branchId)?->localized_name : null,
                    'category' => $player->category_id ? $categories->get($player->category_id)?->localized_name : null,
                    'male' => 0, 'female' => 0, 'total' => 0,
                ];
                $rows[$key][$isFemale ? 'female' : 'male']++;
                $rows[$key]['total']++;
            }
        }

        // Branch rows first (A–Z), the "no branch" bucket last; categories A–Z inside.
        usort($rows, fn (array $a, array $b) => [$a['branch'] === null, (string) $a['branch'], (string) $a['category']]
            <=> [$b['branch'] === null, (string) $b['branch'], (string) $b['category']]);

        return [
            'total' => $active->count(),
            'male' => $active->count() - $female,
            'female' => $female,
            'rows' => $rows,
            'multi_branch' => $links->contains(fn (array $ids) => count($ids) > 1),
            'joined' => Player::query()->whereBetween('join_year', [$from->year, $to->year])->count(),
            'left' => Player::query()->whereDate('left_at', '>=', $from->toDateString())->whereDate('left_at', '<=', $end)->count(),
            'coaches' => DB::table('team_coaches')
                ->join('teams', 'teams.id', '=', 'team_coaches.team_id')
                ->join('users', 'users.id', '=', 'team_coaches.user_id')
                ->whereBetween('team_coaches.season_start_year', [Season::forDate($from)->startYear, Season::forDate($to)->startYear])
                ->distinct()
                ->orderBy('users.name')->orderBy('teams.name')
                ->get(['users.name as name', 'teams.name as team', 'team_coaches.role as role'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }
}
```

The `joined` count uses join years overlapping the period's calendar years (players store a year only). Test: period 2025-09-01..2026-08-31 → years 2025–2026 → 2 joins.

- [ ] **Step 4: Board section**

`app/Services/LiteraryReport/Sections/BoardSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use Carbon\CarbonImmutable;

/** The current board, its committees, and the meetings held in the period with their decisions. */
final class BoardSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $term = BoardTerm::query()->where('is_current', true)->first() ?? BoardTerm::query()->latest('id')->first();
        $roles = BoardRole::query()->get(['name', 'label', 'level', 'sort_order'])->keyBy('name');
        $levels = array_flip(BoardRole::LEVELS);

        $members = $term === null ? collect() : $term->members()->where('status', 'active')->withPlayer()->get()
            ->sortBy(fn (BoardMember $m) => [
                $levels[$roles->get($m->role)?->level] ?? count($levels),
                $roles->get($m->role)?->sort_order ?? PHP_INT_MAX,
                $m->sort_order,
            ])->values();

        $committees = BoardCommittee::query()
            ->with(['members' => fn ($q) => $q->where('status', 'active')->withPlayer()])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (BoardCommittee $c) => [
                'name' => $c->name,
                'head' => $c->members->firstWhere('pivot.position', 'head')?->name,
                'deputies' => $c->members->where('pivot.position', 'deputy')->map(fn (BoardMember $m) => $m->name)->values()->all(),
                'members_count' => $c->members->count(),
            ])->all();

        $meetings = BoardMeeting::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('meeting_date', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('meeting_date')
            ->get(['id', 'title', 'type', 'meeting_date', 'decisions'])
            ->map(fn (BoardMeeting $m) => [
                'date' => $m->meeting_date->toDateString(),
                'title' => $m->title,
                'type' => $m->type,
                'decisions' => array_values(array_filter((array) $m->decisions, fn ($d) => trim((string) $d) !== '')),
            ])->all();

        return [
            'term' => $term?->name,
            'members' => $members->map(fn (BoardMember $m) => [
                'name' => $m->name,
                'role' => $roles->get($m->role)?->label ?: $m->role,
            ])->all(),
            'committees' => $committees,
            'meetings' => $meetings,
        ];
    }
}
```

If `decisions` is not cast to array on `BoardMeeting`, decode it: `(array) (is_string($m->decisions) ? json_decode($m->decisions, true) : $m->decisions)`. If the `status` column is an enum cast, compare with its `->value`. `'scheduled'` meetings dated in the past still count (the club often forgets to flip them to held); only `cancelled` is excluded.

- [ ] **Step 5: Register and run**

`DATA_SECTIONS` becomes `['members' => MembersSection::class, 'board' => BoardSection::class, 'activities' => ActivitiesSection::class]` (order in the const does not matter; print order comes from `LiteraryReport::SECTIONS`).

Run: `php artisan test --compact tests/Feature/LiteraryReport/MembersSectionTest.php tests/Feature/LiteraryReport/BoardSectionTest.php`
Expected: PASS.

- [ ] **Step 6: Partials**

`resources/views/pdf/literary/members.blade.php`:

```blade
<h2>{{ $l('literary.section.members') }}</h2>
<table class="kpis">
    <tr>
        <td><div class="value">{{ $data['total'] }}</div><div class="label">{{ $l('literary.pdf.active_members') }}</div></td>
        <td><div class="value">{{ $data['male'] }}</div><div class="label">{{ $l('literary.pdf.male') }}</div></td>
        <td><div class="value">{{ $data['female'] }}</div><div class="label">{{ $l('literary.pdf.female') }}</div></td>
        <td><div class="value">{{ $data['joined'] }}</div><div class="label">{{ $l('literary.pdf.joined') }}</div></td>
        <td><div class="value">{{ $data['left'] }}</div><div class="label">{{ $l('literary.pdf.left') }}</div></td>
    </tr>
</table>
@if ($data['total'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="data">
        <tr><th>{{ $l('literary.pdf.branch') }}</th><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.male') }}</th><th>{{ $l('literary.pdf.female') }}</th><th>{{ $l('literary.pdf.total') }}</th></tr>
        @foreach ($data['rows'] as $row)
            <tr>
                <td class="start">{{ $row['branch'] ?? $l('literary.pdf.no_branch') }}</td>
                <td class="start">{{ $row['category'] ?? $l('literary.pdf.no_category') }}</td>
                <td>{{ $row['male'] }}</td><td>{{ $row['female'] }}</td><td>{{ $row['total'] }}</td>
            </tr>
        @endforeach
        <tr class="total"><td class="start" colspan="2">{{ $l('literary.pdf.club_total') }}</td><td>{{ $data['male'] }}</td><td>{{ $data['female'] }}</td><td>{{ $data['total'] }}</td></tr>
    </table>
    @if ($data['multi_branch'])
        <p class="muted">{{ $l('literary.pdf.multi_branch_note') }}</p>
    @endif
@endif

@if ($data['coaches'])
    <h3>{{ $l('literary.pdf.coaches') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.name') }}</th><th>{{ $l('literary.pdf.team') }}</th><th>{{ $l('literary.pdf.role') }}</th></tr>
        @foreach ($data['coaches'] as $coach)
            <tr><td class="start">{{ $coach['name'] }}</td><td class="start">{{ $coach['team'] }}</td><td>{{ $l('comp.coach_'.$coach['role']) }}</td></tr>
        @endforeach
    </table>
@endif
```

`resources/views/pdf/literary/board.blade.php`:

```blade
<h2>{{ $l('literary.section.board') }}</h2>
@if ($data['term'])
    <p class="muted">{{ $l('literary.pdf.term') }}: {{ $data['term'] }}</p>
@endif
@if ($data['members'])
    <table class="data">
        <tr><th>{{ $l('literary.pdf.name') }}</th><th>{{ $l('literary.pdf.role') }}</th></tr>
        @foreach ($data['members'] as $member)
            <tr><td class="start">{{ $member['name'] }}</td><td class="start">{{ $member['role'] }}</td></tr>
        @endforeach
    </table>
@else
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@endif

@if ($data['committees'])
    <h3>{{ $l('literary.pdf.committees') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.committee') }}</th><th>{{ $l('literary.pdf.head') }}</th><th>{{ $l('literary.pdf.deputies') }}</th><th>{{ $l('literary.pdf.members_count') }}</th></tr>
        @foreach ($data['committees'] as $committee)
            <tr><td class="start">{{ $committee['name'] }}</td><td class="start">{{ $committee['head'] ?? '—' }}</td><td class="start">{{ implode('، ', $committee['deputies']) ?: '—' }}</td><td>{{ $committee['members_count'] }}</td></tr>
        @endforeach
    </table>
@endif

<h3>{{ $l('literary.pdf.meetings') }} ({{ count($data['meetings']) }})</h3>
@forelse ($data['meetings'] as $meeting)
    <div style="page-break-inside: avoid; margin-bottom: 6px;">
        <strong><bdi dir="ltr">{{ $meeting['date'] }}</bdi> — {{ $meeting['title'] }}</strong>
        <span class="muted">({{ $l($meeting['type']) }})</span>
        @if ($meeting['decisions'])
            <ul style="margin: 2px 0;">
                @foreach ($meeting['decisions'] as $decision)
                    <li>{{ $decision }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@empty
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@endforelse
```

Join deputies with `'، '` in Arabic only if it reads oddly in fr/en exports; acceptable as is (owner is Arabic-first).

- [ ] **Step 7: i18n**

`storage/app/i18n-literary-4.json`:

```json
{
  "literary.pdf.active_members": { "ar": "منخرطون نشطون في نهاية الفترة", "fr": "Adhérents actifs en fin de période", "en": "Active members at period end" },
  "literary.pdf.male": { "ar": "ذكور", "fr": "Garçons", "en": "Male" },
  "literary.pdf.female": { "ar": "إناث", "fr": "Filles", "en": "Female" },
  "literary.pdf.joined": { "ar": "منخرطون جدد", "fr": "Nouvelles adhésions", "en": "New members" },
  "literary.pdf.left": { "ar": "مغادرون", "fr": "Départs", "en": "Leavers" },
  "literary.pdf.branch": { "ar": "الفرع", "fr": "Section", "en": "Branch" },
  "literary.pdf.category": { "ar": "الفئة", "fr": "Catégorie", "en": "Category" },
  "literary.pdf.no_branch": { "ar": "بدون فرع", "fr": "Sans section", "en": "No branch" },
  "literary.pdf.no_category": { "ar": "بدون فئة", "fr": "Sans catégorie", "en": "No category" },
  "literary.pdf.club_total": { "ar": "مجموع النادي", "fr": "Total du club", "en": "Club total" },
  "literary.pdf.multi_branch_note": { "ar": "المنخرط المسجّل في أكثر من فرع يُحسب في كل فرع، ويُحسب مرة واحدة في مجموع النادي.", "fr": "Un adhérent inscrit dans plusieurs sections compte dans chacune, mais une seule fois dans le total du club.", "en": "A member in several branches counts in each branch, but once in the club total." },
  "literary.pdf.coaches": { "ar": "المدربون", "fr": "Entraîneurs", "en": "Coaches" },
  "literary.pdf.name": { "ar": "الاسم", "fr": "Nom", "en": "Name" },
  "literary.pdf.team": { "ar": "الفريق", "fr": "Équipe", "en": "Team" },
  "literary.pdf.role": { "ar": "الصفة", "fr": "Fonction", "en": "Role" },
  "literary.pdf.term": { "ar": "العهدة", "fr": "Mandat", "en": "Term" },
  "literary.pdf.committees": { "ar": "اللجان", "fr": "Commissions", "en": "Committees" },
  "literary.pdf.committee": { "ar": "اللجنة", "fr": "Commission", "en": "Committee" },
  "literary.pdf.head": { "ar": "الرئيس", "fr": "Président", "en": "Head" },
  "literary.pdf.deputies": { "ar": "النواب", "fr": "Adjoints", "en": "Deputies" },
  "literary.pdf.members_count": { "ar": "عدد الأعضاء", "fr": "Membres", "en": "Members" },
  "literary.pdf.meetings": { "ar": "الاجتماعات", "fr": "Réunions", "en": "Meetings" }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-4.json && rm storage/app/i18n-literary-4.json && npm run i18n:check`

- [ ] **Step 8: Verify and commit**

Run: `php artisan test --compact tests/Feature/LiteraryReport` → PASS. `vendor/bin/pint --dirty`.

```bash
git add app/Services/LiteraryReport/Sections/MembersSection.php app/Services/LiteraryReport/Sections/BoardSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/members.blade.php resources/views/pdf/literary/board.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/MembersSectionTest.php tests/Feature/LiteraryReport/BoardSectionTest.php
git commit -m "feat(literary-report): members and board sections

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Attendance and sports sections

**Files:**
- Create: `app/Services/LiteraryReport/Sections/AttendanceSection.php`, `app/Services/LiteraryReport/Sections/SportsSection.php`, `resources/views/pdf/literary/attendance.blade.php`, `resources/views/pdf/literary/sports.blade.php`
- Modify: `DATA_SECTIONS` (add `'attendance'`, `'sports'`), i18n catalogs
- Test: `tests/Feature/LiteraryReport/AttendanceSectionTest.php`, `tests/Feature/LiteraryReport/SportsSectionTest.php`

**Interfaces:**
- Consumes: `App\Services\Attendance\AttendanceStats::categories(string $from, string $to, ?int $branchId = null): array` (rows `category_id, name, held, cancelled, expected, counts[status], pct[status]`; held sessions only; `$branchId` null = no branch filter). `training_sessions(date Y-m-d string, state planned|held|cancelled, branch_id nullable)`. Games: `Game(date Y-m-d string, status GameStatus, our_score, their_score, competition_id nullable, team_id)`, `CompetitionRecord::fromGames(iterable): array{played,wins,draws,losses,goals_for,goals_against,form}`, `GameEvent(type 'goal', subtype nullable|'opponent_own_goal', player_id)`, `PlayerAchievement(player_id, title, date)`, `Player::NAME_COLUMNS` + `fullname`.
- Produces: attendance data `{held, branches: list<{name: ?string, held, rate: ?float}>, categories: list<{name, held, expected, rate: ?float}>}`; sports data `{played, record: {played,wins,draws,losses,goals_for,goals_against}, competitions: list<{name, team, played,wins,draws,losses,goals_for,goals_against}>, scorers: list<{name, goals}>, achievements: list<{date, title, player}>}`.

- [ ] **Step 1: Failing tests**

`tests/Feature/LiteraryReport/AttendanceSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\LiteraryReport\Sections\AttendanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSectionTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(AttendanceSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function it_counts_held_sessions_by_branch_and_the_attendance_rate_by_category(): void
    {
        $u15 = $this->category('U15');
        $football = Branch::create(['name' => 'Football']);
        $a = $this->player($u15);
        $b = $this->player($u15);
        $session = fn (array $x) => TrainingSession::create($x + [
            'category_id' => $u15->id, 'date' => '2026-03-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held, 'branch_id' => $football->id,
        ]);
        $held = $session([]);
        $session(['state' => SessionState::Cancelled, 'start_time' => '10:00', 'end_time' => '11:00']);
        $session(['date' => '2025-08-01']); // before the period
        foreach ([[$a, 'present'], [$b, 'absent_unexcused']] as [$player, $status]) {
            Attendance::create(['training_session_id' => $held->id, 'player_id' => $player->id, 'status' => $status, 'category_id' => $u15->id, 'branch_id' => $football->id]);
        }

        $data = $this->build();

        $this->assertSame(1, $data['held']);
        $this->assertSame([['name' => 'Football', 'held' => 1, 'rate' => 50.0]], $data['branches']);
        $this->assertSame([['name' => 'U15', 'held' => 1, 'expected' => 2, 'rate' => 50.0]], $data['categories']);
    }

    #[Test]
    public function an_empty_period_has_no_rows(): void
    {
        $data = $this->build();
        $this->assertSame(0, $data['held']);
        $this->assertSame([], $data['categories']);
    }
}
```

Check `Attendance` model fillable/required columns and the real status keys (`AttendanceSettings` defaults list `present`, …; use whatever unexcused-absence key exists, e.g. `absent_unexcused` as used by `AttendanceStats::ranking`). If the attendance model is named differently (grep `class .* extends Model` in `app/Models/Attendance*.php`), use that.

`tests/Feature/LiteraryReport/SportsSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Enums\GameStatus;
use App\Models\Category;
use App\Models\GameEvent;
use App\Models\PlayerAchievement;
use App\Services\LiteraryReport\Sections\SportsSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SportsSectionTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private function build(): array
    {
        return app(SportsSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function it_builds_records_per_competition_scorers_and_achievements_from_the_period(): void
    {
        $u15 = Category::create(['name' => 'U15']);
        $team = $this->team($u15, ['name' => 'U15 A']);
        $league = $this->competition($team, 2025, ['name' => 'Ligue']);
        $scorer = $this->player($u15, ['firstname' => 'Riyad', 'lastname' => 'Mahrez']);

        $win = $this->game($team, ['competition_id' => $league->id, 'date' => '2025-10-05', 'status' => GameStatus::Played, 'our_score' => 3, 'their_score' => 1]);
        $this->game($team, ['competition_id' => $league->id, 'date' => '2026-02-01', 'status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0]);
        $this->game($team, ['date' => '2026-05-01', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 2]); // friendly
        $this->game($team, ['competition_id' => $league->id, 'date' => '2025-06-01', 'status' => GameStatus::Played, 'our_score' => 9, 'their_score' => 0]); // before
        $this->game($team, ['competition_id' => $league->id, 'date' => '2026-03-01']); // scheduled

        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 10, 'player_id' => $scorer->id]);
        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'penalty', 'minute' => 50, 'player_id' => $scorer->id]);
        GameEvent::create(['game_id' => $win->id, 'type' => 'goal', 'subtype' => 'opponent_own_goal', 'minute' => 70, 'player_id' => null]);
        PlayerAchievement::create(['player_id' => $scorer->id, 'title' => 'Wilaya top scorer', 'date' => '2026-06-15']);
        PlayerAchievement::create(['player_id' => $scorer->id, 'title' => 'Too old', 'date' => '2024-06-15']);

        $data = $this->build();

        $this->assertSame(3, $data['played']);
        $this->assertSame(['played' => 3, 'wins' => 1, 'draws' => 1, 'losses' => 1, 'goals_for' => 4, 'goals_against' => 3], $data['record']);
        $this->assertSame(['Ligue', null], array_column($data['competitions'], 'name')); // friendlies last, name null
        $this->assertSame(2, $data['competitions'][0]['played']);
        $this->assertSame([['name' => $scorer->fresh()->fullname, 'goals' => 2]], $data['scorers']);
        $this->assertSame([['date' => '2026-06-15', 'title' => 'Wilaya top scorer', 'player' => $scorer->fresh()->fullname]], $data['achievements']);
    }

    #[Test]
    public function no_games_gives_an_empty_record(): void
    {
        $data = $this->build();
        $this->assertSame(0, $data['played']);
        $this->assertSame([], $data['competitions']);
    }
}
```

`CompetitionFixtures::player()` comes from `AttendanceFixtures` (`player(Category $category, array $extra)`); it needs `private int $playerSeq = 0;` in the test class, as above.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/LiteraryReport/AttendanceSectionTest.php tests/Feature/LiteraryReport/SportsSectionTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Attendance section**

`app/Services/LiteraryReport/Sections/AttendanceSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use Carbon\CarbonImmutable;

/** Training held in the period: sessions and attendance rate per branch and per category. */
final class AttendanceSection implements Section
{
    public function __construct(private readonly AttendanceStats $stats) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->toDateString(), $to->toDateString()];

        $held = TrainingSession::query()->where('state', SessionState::Held)->whereBetween('date', [$start, $end]);
        $byBranch = (clone $held)->selectRaw('branch_id, COUNT(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $branches = Branch::query()->whereIn('id', $byBranch->keys()->filter())->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])
            ->sortBy('localized_name')
            ->map(fn (Branch $branch) => [
                'name' => $branch->localized_name,
                'held' => (int) $byBranch[$branch->id],
                'rate' => self::rate($this->stats->categories($start, $end, $branch->id)),
            ])->values()->all();
        if (isset($byBranch[''])) {
            // Sessions with no branch are club-wide; their rate is folded into the totals only.
            $branches[] = ['name' => null, 'held' => (int) $byBranch[''], 'rate' => null];
        }

        $categories = collect($this->stats->categories($start, $end))
            ->filter(fn (array $row) => $row['held'] > 0)
            ->map(fn (array $row) => [
                'name' => $row['name'],
                'held' => (int) $row['held'],
                'expected' => (int) $row['expected'],
                'rate' => $row['pct']['present'] ?? null,
            ])->values()->all();

        return ['held' => (clone $held)->count(), 'branches' => $branches, 'categories' => $categories];
    }

    /** Present marks over expected marks across the given category rows, in %. */
    private static function rate(array $rows): ?float
    {
        $expected = array_sum(array_column($rows, 'expected'));
        $present = array_sum(array_map(fn (array $row) => $row['counts']['present'] ?? 0, $rows));

        return $expected > 0 ? round($present / $expected * 100, 1) : null;
    }
}
```

`pluck('n', 'branch_id')` keys a null `branch_id` as `''` — that is what `isset($byBranch[''])` reads; verify in the test with a branchless session if you touch it. Null-branch sessions show as a row named by `literary.pdf.club_wide`.

- [ ] **Step 4: Sports section**

`app/Services/LiteraryReport/Sections/SportsSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Models\PlayerAchievement;
use App\Services\Competitions\CompetitionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Results of the games played in the period. MatchStats works per season; the
 * literary report's free period needs a date range, so this queries games directly.
 */
final class SportsSection implements Section
{
    private const RECORD_KEYS = ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against'];

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->toDateString(), $to->toDateString()];

        $games = Game::query()
            ->with(['competition:id,name', 'team:id,name'])
            ->where('status', GameStatus::Played)
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get();

        $competitions = $games->groupBy(fn (Game $g) => $g->competition_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->competition?->name,
                'team' => $group->first()->team?->name,
            ] + Arr::only(CompetitionRecord::fromGames($group), self::RECORD_KEYS))
            ->sortBy(fn (array $row) => [$row['name'] === null, (string) $row['name']])
            ->values()->all();

        $goals = GameEvent::query()
            ->where('type', 'goal')
            ->whereNotNull('player_id')
            ->where(fn ($q) => $q->whereNull('subtype')->orWhere('subtype', '!=', 'opponent_own_goal'))
            ->whereIn('game_id', $games->pluck('id'))
            ->selectRaw('player_id, COUNT(*) as goals')
            ->groupBy('player_id')
            ->orderByDesc('goals')
            ->limit(10)
            ->pluck('goals', 'player_id');
        $players = Player::query()->whereIn('id', $goals->keys())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        return [
            'played' => $games->count(),
            'record' => Arr::only(CompetitionRecord::fromGames($games), self::RECORD_KEYS),
            'competitions' => $competitions,
            'scorers' => $goals->map(fn ($n, $playerId) => ['name' => $players->get($playerId)?->fullname, 'goals' => (int) $n])->values()->all(),
            'achievements' => PlayerAchievement::query()
                ->with(['player' => fn ($q) => $q->select(['id', ...Player::NAME_COLUMNS])])
                ->whereBetween('date', [$start, $end])
                ->orderBy('date')
                ->get()
                ->map(fn (PlayerAchievement $a) => ['date' => $a->date->toDateString(), 'title' => $a->title, 'player' => $a->player?->fullname])
                ->all(),
        ];
    }
}
```

`whereIn('game_id', $games->pluck('id'))` is bounded by one period's games (hundreds at most). If `PlayerAchievement` has no `player()` relation, add `public function player(): BelongsTo { return $this->belongsTo(Player::class); }` to it. Ties in goals keep DB order; fine.

- [ ] **Step 5: Register and run**

Add `'attendance' => AttendanceSection::class, 'sports' => SportsSection::class` to `DATA_SECTIONS`.

Run: `php artisan test --compact tests/Feature/LiteraryReport/AttendanceSectionTest.php tests/Feature/LiteraryReport/SportsSectionTest.php`
Expected: PASS.

- [ ] **Step 6: Partials**

`resources/views/pdf/literary/attendance.blade.php`:

```blade
<h2>{{ $l('literary.section.attendance') }}</h2>
@if ($data['held'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="kpis"><tr><td><div class="value">{{ $data['held'] }}</div><div class="label">{{ $l('literary.pdf.sessions_held') }}</div></td></tr></table>

    @if ($data['branches'])
        <h3>{{ $l('literary.pdf.by_branch') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.branch') }}</th><th>{{ $l('literary.pdf.sessions_held') }}</th><th>{{ $l('literary.pdf.attendance_rate') }}</th></tr>
            @foreach ($data['branches'] as $row)
                <tr><td class="start">{{ $row['name'] ?? $l('literary.pdf.club_wide') }}</td><td>{{ $row['held'] }}</td><td>{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td></tr>
            @endforeach
        </table>
    @endif

    <h3>{{ $l('literary.pdf.by_category') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.sessions_held') }}</th><th>{{ $l('literary.pdf.expected') }}</th><th>{{ $l('literary.pdf.attendance_rate') }}</th></tr>
        @foreach ($data['categories'] as $row)
            <tr><td class="start">{{ $row['name'] }}</td><td>{{ $row['held'] }}</td><td>{{ $row['expected'] }}</td><td>{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td></tr>
        @endforeach
    </table>
@endif
```

`resources/views/pdf/literary/sports.blade.php`:

```blade
<h2>{{ $l('literary.section.sports') }}</h2>
@if ($data['played'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    @php($cols = ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against'])
    <table class="data">
        <tr>
            <th>{{ $l('literary.pdf.competition') }}</th><th>{{ $l('literary.pdf.team') }}</th>
            @foreach ($cols as $col)<th>{{ $l('literary.pdf.'.$col) }}</th>@endforeach
        </tr>
        @foreach ($data['competitions'] as $row)
            <tr>
                <td class="start">{{ $row['name'] ?? $l('literary.pdf.friendlies') }}</td><td class="start">{{ $row['team'] }}</td>
                @foreach ($cols as $col)<td>{{ $row[$col] }}</td>@endforeach
            </tr>
        @endforeach
        <tr class="total">
            <td class="start" colspan="2">{{ $l('literary.pdf.total') }}</td>
            @foreach ($cols as $col)<td>{{ $data['record'][$col] }}</td>@endforeach
        </tr>
    </table>

    @if ($data['scorers'])
        <h3>{{ $l('literary.pdf.top_scorers') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.player') }}</th><th>{{ $l('literary.pdf.goals') }}</th></tr>
            @foreach ($data['scorers'] as $row)
                <tr><td class="start">{{ $row['name'] }}</td><td>{{ $row['goals'] }}</td></tr>
            @endforeach
        </table>
    @endif
@endif

@if ($data['achievements'])
    <h3>{{ $l('literary.pdf.achievements') }}</h3>
    <table class="data">
        <tr><th>{{ $l('literary.pdf.date') }}</th><th>{{ $l('literary.pdf.player') }}</th><th>{{ $l('literary.pdf.achievement') }}</th></tr>
        @foreach ($data['achievements'] as $row)
            <tr><td><bdi dir="ltr">{{ $row['date'] }}</bdi></td><td class="start">{{ $row['player'] }}</td><td class="start">{{ $row['title'] }}</td></tr>
        @endforeach
    </table>
@endif
```

The `@php($cols = [...])` short form is safe here (no `=>` or nested parentheses); if Blade mis-parses it, use the `@php … @endphp` block.

- [ ] **Step 7: i18n**

`storage/app/i18n-literary-5.json`:

```json
{
  "literary.pdf.sessions_held": { "ar": "حصص منجزة", "fr": "Séances tenues", "en": "Sessions held" },
  "literary.pdf.by_branch": { "ar": "حسب الفرع", "fr": "Par section", "en": "By branch" },
  "literary.pdf.by_category": { "ar": "حسب الفئة", "fr": "Par catégorie", "en": "By category" },
  "literary.pdf.club_wide": { "ar": "حصص مشتركة للنادي", "fr": "Séances communes du club", "en": "Club-wide sessions" },
  "literary.pdf.expected": { "ar": "حضور متوقع", "fr": "Présences attendues", "en": "Expected attendances" },
  "literary.pdf.attendance_rate": { "ar": "نسبة الحضور", "fr": "Taux de présence", "en": "Attendance rate" },
  "literary.pdf.competition": { "ar": "المنافسة", "fr": "Compétition", "en": "Competition" },
  "literary.pdf.friendlies": { "ar": "مباريات ودية", "fr": "Matchs amicaux", "en": "Friendlies" },
  "literary.pdf.played": { "ar": "لعب", "fr": "J", "en": "P" },
  "literary.pdf.wins": { "ar": "فوز", "fr": "G", "en": "W" },
  "literary.pdf.draws": { "ar": "تعادل", "fr": "N", "en": "D" },
  "literary.pdf.losses": { "ar": "خسارة", "fr": "P", "en": "L" },
  "literary.pdf.goals_for": { "ar": "له", "fr": "BP", "en": "GF" },
  "literary.pdf.goals_against": { "ar": "عليه", "fr": "BC", "en": "GA" },
  "literary.pdf.top_scorers": { "ar": "الهدافون", "fr": "Meilleurs buteurs", "en": "Top scorers" },
  "literary.pdf.player": { "ar": "اللاعب", "fr": "Joueur", "en": "Player" },
  "literary.pdf.goals": { "ar": "الأهداف", "fr": "Buts", "en": "Goals" },
  "literary.pdf.achievements": { "ar": "الإنجازات", "fr": "Distinctions", "en": "Achievements" },
  "literary.pdf.achievement": { "ar": "الإنجاز", "fr": "Distinction", "en": "Achievement" },
  "literary.pdf.date": { "ar": "التاريخ", "fr": "Date", "en": "Date" }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-5.json && rm storage/app/i18n-literary-5.json && npm run i18n:check`

- [ ] **Step 8: Verify and commit**

Run: `php artisan test --compact tests/Feature/LiteraryReport` → PASS. `vendor/bin/pint --dirty`.

```bash
git add app/Services/LiteraryReport/Sections/AttendanceSection.php app/Services/LiteraryReport/Sections/SportsSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/attendance.blade.php resources/views/pdf/literary/sports.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/AttendanceSectionTest.php tests/Feature/LiteraryReport/SportsSectionTest.php
git commit -m "feat(literary-report): training/attendance and sports results sections

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Add `app/Models/PlayerAchievement.php` if you added the `player()` relation.)

---

### Task 6: Finance section, final checks

**Files:**
- Create: `app/Services/LiteraryReport/Sections/FinanceSection.php`, `resources/views/pdf/literary/finance.blade.php`
- Modify: `DATA_SECTIONS` (add `'finance'`; remove the "not registered yet" comment), i18n catalogs
- Test: `tests/Feature/LiteraryReport/FinanceSectionTest.php`

**Interfaces:**
- Consumes: `Transaction(amount, transaction_date, transaction_type income|expense, archived, finance_category_id)`, `FinanceCategory(type, name, name_ar/fr/en, localized_name)`, `App\Support\ClubCurrency::label()`.
- Produces: finance data `{income: float, expense: float, balance: float, currency: string, top_income: list<{name: ?string, total: float}>, top_expense: list<…>}`.

- [ ] **Step 1: Failing test**

`tests/Feature/LiteraryReport/FinanceSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Services\LiteraryReport\Sections\FinanceSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceSectionTest extends TestCase
{
    use RefreshDatabase;

    private function build(): array
    {
        return app(FinanceSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function tx(string $type, float $amount, string $date, ?FinanceCategory $category = null, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'title' => 'T', 'amount' => $amount, 'transaction_date' => $date, 'transaction_type' => $type,
            'category' => $type === 'income' ? 'donation' : 'other', 'payment_method' => 'cash', 'status' => 'Paid',
            'finance_category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function totals_are_cash_basis_and_exclude_archived_and_out_of_period_entries(): void
    {
        $donations = FinanceCategory::create(['type' => 'income', 'name' => 'LR Donations']);
        $grants = FinanceCategory::create(['type' => 'income', 'name' => 'LR Grants']);
        $travel = FinanceCategory::create(['type' => 'expense', 'name' => 'LR Travel']);

        $this->tx('income', 1000, '2025-09-01', $grants);
        $this->tx('income', 400, '2026-08-31 23:00:00', $donations);
        $this->tx('income', 300, '2026-01-10', $donations);
        $this->tx('income', 50, '2026-01-10');                                     // no category
        $this->tx('expense', 300, '2026-02-02', $travel);
        $this->tx('income', 999, '2026-03-03', $grants, ['archived' => true]);     // cancelled
        $this->tx('income', 777, '2026-09-01', $grants);                           // after the period

        $data = $this->build();

        $this->assertEquals(1750.0, $data['income']);
        $this->assertEquals(300.0, $data['expense']);
        $this->assertEquals(1450.0, $data['balance']);
        $this->assertEquals([
            ['name' => 'LR Grants', 'total' => 1000.0],
            ['name' => 'LR Donations', 'total' => 700.0],
            ['name' => null, 'total' => 50.0],
        ], $data['top_income']);
        $this->assertEquals([['name' => 'LR Travel', 'total' => 300.0]], $data['top_expense']);
        $this->assertNotSame('', $data['currency']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/LiteraryReport/FinanceSectionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Finance section**

`app/Services/LiteraryReport/Sections/FinanceSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\ClubCurrency;
use Carbon\CarbonImmutable;

/**
 * Cash-basis summary of the period: money actually received and spent.
 * Same population as the transactions screen (archived = cancelled entries
 * excluded); transfers live in their own table; unpaid subscriptions are
 * debts, not income, until paid.
 */
final class FinanceSection implements Section
{
    private const TOP = 5;

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = Transaction::query()
            ->where('archived', false)
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString());

        $byCategory = (clone $base)->toBase()
            ->selectRaw('transaction_type, finance_category_id, SUM(amount) as total')
            ->groupBy('transaction_type', 'finance_category_id')
            ->get();
        $names = FinanceCategory::query()
            ->whereIn('id', $byCategory->pluck('finance_category_id')->filter()->unique())
            ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en'])
            ->keyBy('id');

        $top = fn (string $type) => $byCategory->where('transaction_type', $type)
            ->sortByDesc(fn ($row) => (float) $row->total)
            ->take(self::TOP)
            ->map(fn ($row) => [
                'name' => $row->finance_category_id ? $names->get($row->finance_category_id)?->localized_name : null,
                'total' => round((float) $row->total, 2),
            ])->values()->all();

        $income = round((float) $byCategory->where('transaction_type', 'income')->sum(fn ($row) => (float) $row->total), 2);
        $expense = round((float) $byCategory->where('transaction_type', 'expense')->sum(fn ($row) => (float) $row->total), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => round($income - $expense, 2),
            'currency' => ClubCurrency::label(),
            'top_income' => $top('income'),
            'top_expense' => $top('expense'),
        ];
    }
}
```

If `FinanceCategory` lacks `name_ar/fr/en` columns (check its migration/`HasLocalizedName` use), select `['id', 'name']` and use `name`.

- [ ] **Step 4: Register and run**

Add `'finance' => FinanceSection::class` to `DATA_SECTIONS`. All nine keys now resolve.

Run: `php artisan test --compact tests/Feature/LiteraryReport`
Expected: PASS.

- [ ] **Step 5: Partial**

`resources/views/pdf/literary/finance.blade.php`:

```blade
@php
    $money = fn ($v) => number_format((float) $v, 2).' '.$data['currency'];
@endphp
<h2>{{ $l('literary.section.finance') }}</h2>
@if ($data['income'] == 0 && $data['expense'] == 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $money($data['income']) }}</div><div class="label">{{ $l('literary.pdf.income') }}</div></td>
            <td><div class="value">{{ $money($data['expense']) }}</div><div class="label">{{ $l('literary.pdf.expense') }}</div></td>
            <td><div class="value">{{ $money($data['balance']) }}</div><div class="label">{{ $l('literary.pdf.balance') }}</div></td>
        </tr>
    </table>
    @foreach (['top_income', 'top_expense'] as $list)
        @if ($data[$list])
            <h3>{{ $l('literary.pdf.'.$list) }}</h3>
            <table class="data">
                <tr><th>{{ $l('literary.pdf.category') }}</th><th>{{ $l('literary.pdf.amount') }}</th></tr>
                @foreach ($data[$list] as $row)
                    <tr><td class="start">{{ $row['name'] ?? $l('literary.pdf.uncategorized') }}</td><td><bdi dir="ltr">{{ $money($row['total']) }}</bdi></td></tr>
                @endforeach
            </table>
        @endif
    @endforeach
    <p class="muted">{{ $l('literary.pdf.finance_note') }}</p>
@endif
```

- [ ] **Step 6: i18n**

`storage/app/i18n-literary-6.json`:

```json
{
  "literary.pdf.income": { "ar": "المداخيل", "fr": "Recettes", "en": "Income" },
  "literary.pdf.expense": { "ar": "المصاريف", "fr": "Dépenses", "en": "Expenses" },
  "literary.pdf.balance": { "ar": "الرصيد", "fr": "Solde", "en": "Balance" },
  "literary.pdf.top_income": { "ar": "أهم مصادر المداخيل", "fr": "Principales recettes", "en": "Main income" },
  "literary.pdf.top_expense": { "ar": "أهم أبواب المصاريف", "fr": "Principales dépenses", "en": "Main expenses" },
  "literary.pdf.amount": { "ar": "المبلغ", "fr": "Montant", "en": "Amount" },
  "literary.pdf.uncategorized": { "ar": "غير مصنّف", "fr": "Non classé", "en": "Uncategorised" },
  "literary.pdf.finance_note": { "ar": "على الأساس النقدي: ما قُبض وما صُرف فعلاً خلال الفترة. الاشتراكات غير المسددة ديون وليست مداخيل.", "fr": "Base caisse : sommes réellement encaissées et dépensées sur la période. Les cotisations impayées sont des créances, pas des recettes.", "en": "Cash basis: money actually received and spent in the period. Unpaid subscriptions are debts, not income." }
}
```

Run: `node scripts/i18n-add.mjs storage/app/i18n-literary-6.json && rm storage/app/i18n-literary-6.json`

- [ ] **Step 7: Final verification (all required; report outputs)**

```bash
npm run i18n:check
npm run build
vendor/bin/pint --test app/Models/LiteraryReport.php app/Http/Controllers/LiteraryReportController.php app/Http/Requests/LiteraryReportRequest.php app/Services/LiteraryReport database/migrations/2026_11_01_200001_create_literary_reports_table.php
php artisan test --compact tests/Feature/LiteraryReport tests/Unit/RoleModelTest.php tests/Unit/ActivityRecorderTest.php tests/Unit/ActivityCoverageTest.php tests/Feature/UsersBatchTest.php tests/Feature/ActivityReportTest.php tests/Feature/Activities tests/Feature/Reports
```

Expected: all green. Then the full suite once: `php artisan test --compact` — expected only the known date-dependent `Competitions\ManualSuspensionsTest > a manual ban is added in matches or dates and listed` failure.

- [ ] **Step 8: Commit**

```bash
git add app/Services/LiteraryReport/Sections/FinanceSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/finance.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/FinanceSectionTest.php
git commit -m "feat(literary-report): financial summary section

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## After the tasks (controller, not a subagent)

- Browser check of `/literary-reports` and the edit page in headless Chrome (CDP probe script from P1 QA; Git Bash needs `MSYS_NO_PATHCONV=1`) — PHP/Inertia tests cannot see client render errors.
- Export one real PDF from a copy of the real DB and look at it (RTL tables, photos, page breaks, footer).
- Deploy notes for the owner: `php artisan migrate` (new table + right for system roles), desktop build.
