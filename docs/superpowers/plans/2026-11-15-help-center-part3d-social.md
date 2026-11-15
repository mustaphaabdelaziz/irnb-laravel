# Help Center Part 3d — Social activities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two social-activities problems the owner chose, give the screenshot run generated activity photos and a throwaway private disk, then add the `social` help topic: 6 Arabic articles with screenshots on seeded demo activities and one literary report.

**Architecture:** Fixes first (Task 1), with feature tests. Task 2 extends the 3c throwaway storage to the private disk and adds generated neutral photos and a tiny PDF to `HelpDemoImages`. Task 3 adds `HelpDemoSocialSeeder` (called last from the guarded `HelpDemoSeeder`), the topic and nav targets. Then two article tasks on the same Help Center engine as Parts 1–3c. No Help framework change, no migration.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), GD (installed), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-09-help-center-part3-design.md` (phase 3d). References: `resources/help/README.md` (authoring guide), `resources/help/ar/competitions/*.md` and `resources/help/ar/board/*.md` (house style), `docs/superpowers/plans/2026-11-10-help-center-part3c-competitions.md` (previous phase, same task shapes), `.superpowers/archive/help-3c-sdd/` (implementer/reviewer instructions, article-review addendum, ledger format to reuse).

## Owner decisions for 3d

Both spec fixes were chosen (2026-11-09):

1. The «تقرير النشاط» hint (`activities.report_hint`) says the text prints in the literary report only when «إضافة التفاصيل» is ticked.
2. «تقرير جديد» by a user with the literary report add right but not edit: the report is created and the list reloads with the success message (today `store` redirects to `literary-reports.edit`, which needs edit → 403 page).

Decided while planning (AskUserQuestion, 2026-11-15):

- **Demo report period = the current season** (the form's default). Demo activities, the 3b board and the 3c matches all fall inside it, so every chapter has data.
- **7 demo activities over the 5 report chapters**, 4 with generated neutral photos (3 marked «صورة التقرير»), 2 with a PDF file. The 6 older types from the first migration stay in the demo, unused (the types article can point at one as deletable).
- **Rights in the export article: text only.** One sentence quoting `literary.no_right`; no screenshot as another user, so no older shot changes.

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON; `scripts/i18n-add.mjs` adds keys), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…). `flash.*` keys live in `resources/js/i18n/{ar,fr,en}.json`. Use `t()` directly, never `te()` for `flash.*`.
- Rights (`config/permissions.php` + `app/Support/PermissionMap.php`): last route-name segment `store`/`create` → add, `destroy`/`delete` → delete, `index`/`show`/`export` → view, **anything else → edit**. Modules: `activities`, `activity-types` → `activity_types`, `literary-reports` → `literary_reports`. Overrides already there: `activities.photos.store|destroy` and `activities.files.store|destroy` → activities **edit**; `literary-reports.pdf` → literary_reports **view**. So: photos, captions, «صورة التقرير» and files need activities edit; opening a report (`literary-reports.edit`) needs edit; the PDF needs view only.
- **Report chapters follow the exporter's rights** (`LiteraryReportBuilder::MODULES`): members → players view, board → board view, attendance → attendance view, sports → games view, activities and photos → activities view, finance → finance view; cover, intro, conclusion, signatures need none. A section the user can't see is greyed with `literary.no_right` and is kept untouched on save (`LiteraryReportController::update`).
- Sidebar group «النشاط الاجتماعي» (`nav.social`, icon `heart`): «الأنشطة» (/activities), «التقرير الأدبي» (/literary-reports). «تهيئة التطبيق» holds «أنواع الأنشطة» (/activity-types). Check each Arabic label in `resources/js/i18n/ar.json` before quoting it.
- Facts the articles rely on (verify each against the code before writing it):
  - An activity belongs to a literary report when its **start date** falls in the report period (`ActivityChapters::inPeriod`, `whereBetween('starts_on', …)`). The activities list filter is different: an activity shows when any of its days falls in the filter range (`ActivityController::index`).
  - A report's «إلى» must be on or after «من» (`LiteraryReportRequest`, `after_or_equal:period_from`).
  - Chapters = activity types that have activities in the period, ordered by the type's «#» (`sort_order`), then name; rows by start date. Types without activities print nothing.
  - «تاريخ نصّي» replaces the dates in the report table (`ActivitiesSection`, `date_label`).
  - Report texts of activities and the per-type summary print only with «إضافة التفاصيل» (`resources/views/pdf/literary/activities.blade.php`).
  - One «صورة التقرير» per activity (`ActivityPhotoController::update` moves the mark); the photo appendix prints that one photo per activity.
  - «التكلفة» is for information only, never a finance entry (`activities.cost_hint`).
  - Deleting an activity erases its photos and files from disk (`Activity::booted` `deleting`) — irreversible. A type with activities can't be deleted (`flash.activity_type_in_use`; the trash is disabled).
  - The PDF is computed live when exported: no saved copy (`LiteraryReportController::pdf`); the export link is hidden while the edit form has unsaved changes (`literary.save_first`).
  - Photos: jpg/png/webp, up to 20 per upload, 8 MB each. Files: pdf/doc/docx/jpg/png/webp, 20 MB, one per upload. Files are private (served only to signed-in users with activities view).
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (filling adjacent fields of one form card is one step), every clicked element ringed (sidebar clicks too), phone hint where a step relies on the sidebar («في الهاتف، افتح القائمة أولًا بزر ☰»), exact labels from `ar.json` in «…», no emoji standing in for app icons, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- **Icon-only buttons** (`IconButton`, and the file upload label in `Activities/Show.vue`, which is an icon with `title` «إرفاق ملف»): describe the icon («زر القلم», «زر سلة المهملات», «زر الرفع») and quote the hover name the way `resources/help/ar/settings/add-branch.md` does. No colour words for buttons; «الملوّن» only where colour is the point (the ring around the report photo is such a place).
- Lessons from earlier phases: walk each article literally against the component's watchers and defaults; take each screenshot in the state the reader is in at that step; a step holds one action; reviewers repeatedly catch two actions in one step and unringed sidebar clicks; messages users cannot see (native confirm, file pickers) are never screenshot; the Ctrl+K palette shot must wait for help results; a full `help:shots` run flakes once in a while — rerun the failed shot alone before suspecting the branch; older shots change daily (date-relative demo data) → restore them, keep only real changes.
- **Shots must never save anything.** Never press «حفظ», «إضافة», «تقرير جديد», the photo/file upload, the «صورة التقرير» box, the caption save or any delete/confirm button. Forms are filled in `before` and left unsaved. The native file picker is never opened. If a shot must write, it goes last in `shots.js` with an `after` undo (none planned).
- **Real media is never written.** Only the screenshot run writes images and files, into `storage/help-demo-storage/app/{public,private}`. The seeder writes no image or file unless `HelpDemoSeeder::onThrowawayStorage()` is true (unit tests get activities without photos/files, except the one test that points both disks at a temp folder).
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first, writes models directly (no `ActivityRecorder`: the users articles count activity entries), and dates everything relative to the seed day. Production is PostgreSQL; seeders and sweeps never run there. **No migration in this phase.**
- Git: stage explicit files only (the main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `_bmad/`, `brag-output/`, a PDF). Never `git add -A`. Commit after every step that leaves the tree green (interrupted agents lose uncommitted work). Run every command in the foreground; leave no background jobs.
- Worktree: reuse `D:\irnb-help3` (vendor + node_modules copied, playwright installed; currently on merged branch `feat/help-3c`). Create branch `feat/help-3d` from local `main` (`89a2862` or newer, which already holds this plan's commit) inside it. If the worktree is gone, recreate it per `superpowers:using-git-worktrees`, copy `vendor/` and `node_modules/` (no junctions), `npm install`, restore `package-lock.json`'s `name` if npm changed it, `npx playwright install chromium`.
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## File map

| File | Responsibility |
|---|---|
| `resources/js/i18n/{ar,fr,en}.json` (modify) | fix 1 text; `help.topic.social` |
| `app/Http/Controllers/LiteraryReportController.php` (modify) | fix 2 (`store` redirect) |
| `config/filesystems.php`, `scripts/help-shots.mjs`, `resources/help/README.md` (modify) | throwaway private disk + guard |
| `database/seeders/HelpDemoImages.php` (modify) | generated activity photos, tiny PDF |
| `database/seeders/HelpDemoSeeder.php` (modify) | call the new seeder |
| `database/seeders/HelpDemoSocialSeeder.php` (create) | demo activities, photos, files, literary report |
| `resources/js/Components/Sidebar/SidebarSection.vue` (modify) | nav `data-help` |
| `resources/help/topics.php` (modify) | `social` topic |
| `tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`, `tests/Feature/Help/*` (modify/create) | fix tests, image test, seeder test |
| `resources/help/shots.js` (modify) | new shots |
| `resources/js/Pages/{Activities,LiteraryReports,Settings/ActivityTypes}*.vue` (modify) | `data-help` only |
| `resources/help/ar/social/*.md` (create) | 6 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: Report-text hint and «تقرير جديد» without the edit right

**Files:**
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json` (`activities.report_hint`, text edit only)
- Modify: `app/Http/Controllers/LiteraryReportController.php` (`store`)
- Test: `tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`

**Interfaces:**
- Consumes: `User::hasPermission(string $module, string $action): bool`.
- Produces: `store` redirects to `literary-reports.edit` when the user has `literary_reports` edit, else to `literary-reports.index`; both with `success` = `flash.literary_report_created`.

- [ ] **Step 1: Failing tests** — add to `LiteraryReportCrudTest`:

```php
    #[Test]
    public function a_user_who_can_add_but_not_edit_lands_back_on_the_list(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => [
            'literary_reports' => ['view', 'add'],
        ]])->id]);

        $this->actingAs($user)
            ->post(route('literary-reports.store'), ['title' => 'تقرير', 'period_from' => '2026-09-01', 'period_to' => '2027-08-31'])
            ->assertRedirect(route('literary-reports.index'))
            ->assertSessionHas('success', 'flash.literary_report_created');

        $this->assertSame(1, LiteraryReport::count());
        $this->actingAs($user)->get(route('literary-reports.index'))->assertOk();
    }

    #[Test]
    public function a_user_who_can_edit_opens_the_new_report(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => [
            'literary_reports' => ['view', 'add', 'edit'],
        ]])->id]);

        $response = $this->actingAs($user)
            ->post(route('literary-reports.store'), ['title' => 'تقرير', 'period_from' => '2026-09-01', 'period_to' => '2027-08-31']);

        $response->assertRedirect(route('literary-reports.edit', LiteraryReport::firstOrFail()));
    }
```

Check how the existing tests in this file build an approved, verified user (line ~125/142/168) and copy that (the factory may need `email_verified_at`/`approved`).

- [ ] **Step 2: Run** `php artisan test --filter=LiteraryReportCrudTest` — the first new test FAILS (redirects to edit).

- [ ] **Step 3: Implement** — in `store`, replace the return:

```php
        // Opening the report needs the edit right; without it the list shows the new row.
        $target = $request->user()->hasPermission('literary_reports', 'edit')
            ? route('literary-reports.edit', $report)
            : route('literary-reports.index');

        return redirect($target)->with('success', 'flash.literary_report_created');
```

- [ ] **Step 4: Hint text** — edit the value of `activities.report_hint` in place (no re-serialising):
  - ar: «يُطبع هذا النص في التقرير الأدبي عند اختيار «إضافة التفاصيل»» — check that `literary.include_details` in ar.json starts with «إضافة التفاصيل»; quote exactly that part.
  - fr: read `literary.include_details` in fr.json and write «Ce texte est imprimé dans le rapport moral lorsque « <that label's first words> » est coché».
  - en: read `literary.include_details` in en.json and write «Printed in the literary report when “<that label's first words>” is ticked».

- [ ] **Step 5: Run** `php artisan test --filter=LiteraryReport` and `npm run i18n:check` — PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LiteraryReportController.php tests/Feature/LiteraryReport/LiteraryReportCrudTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "fix(literary): report-text hint names the details box; new report without edit right returns to the list"
```

---

### Task 2: Throwaway private disk, generated activity photos and a demo PDF

**Files:**
- Modify: `config/filesystems.php` (`local` disk root)
- Modify: `scripts/help-shots.mjs` (env + real-private guard), `resources/help/README.md` (one paragraph)
- Modify: `database/seeders/HelpDemoImages.php`
- Test: `tests/Feature/Help/HelpDemoImagesTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::onThrowawayStorage()`, `FileStorageService::storeImageVariants(UploadedFile $file, array $targets): array` (returns `[width => ['path' => …, …]]` — read the method to confirm the keys), `PrivateFileStorage::store(UploadedFile $file, string $directory): array` (returns the attributes `ActivityFile::create` takes — read it), `Activity::photoDirectory()`, `Activity::fileDirectory()`.
- Produces:
  - `HelpDemoImages::photo(Activity $activity, string $scene, int $variant = 0): ?array` → `['path' => string, 'thumb_path' => string]` or `null` off throwaway storage. `$scene` ∈ `field`, `beach`, `hall`, `pool`; `$variant` shifts colours/figure positions so two photos of one scene differ.
  - `HelpDemoImages::pdf(Activity $activity, string $originalName): ?array` → the `PrivateFileStorage::store` attributes (incl. `original_name`) or `null` off throwaway storage.
  - `HelpDemoSeeder::onThrowawayStorage()` true only when the public **and** the local (private) disk roots both end in `/help-demo-storage/app/public` and `/help-demo-storage/app/private`.

- [ ] **Step 1: Failing tests** — in `HelpDemoImagesTest` (read the existing logo test first and follow its setup/teardown):
  1. Off throwaway storage `photo()` and `pdf()` return null and write nothing.
  2. With both disk roots pointed at a temp folder `…/help-demo-storage/app/{public,private}` (`config([...])`, then `Storage::forgetDisk(['public', 'local'])` if the services cache disks — check), for a created `Activity` (needs an `ActivityType`; use the migration's first type): `photo($a, 'beach')` returns two paths under `activities/{id}` and `activities/{id}/thumbs` that exist on the public disk; the big image is 1600 px wide (`getimagesize`); `photo($a, 'beach', 1)` differs in bytes from variant 0; `pdf($a, 'اتفاقية الشراكة.pdf')` stores a file on the private disk whose bytes start with `%PDF-` and `original_name` is kept. Remove the temp folder in `tearDown`.
  3. `onThrowawayStorage()` is false when only the public root points at the throwaway folder.

- [ ] **Step 2: Run** `php artisan test --filter=HelpDemoImagesTest` — FAIL.

- [ ] **Step 3: Private disk** — `config/filesystems.php`, `local` disk:

```php
            // HELP_DEMO_PRIVATE_ROOT is set only by scripts/help-shots.mjs (throwaway screenshot storage).
            'root' => env('HELP_DEMO_PRIVATE_ROOT', storage_path('app/private')),
```

`HelpDemoSeeder::onThrowawayStorage()`: also require the `local` disk root to end with `/help-demo-storage/app/private` (same normalisation as the public check).

`scripts/help-shots.mjs`: add `HELP_DEMO_PRIVATE_ROOT: join(demoStorage, 'app', 'private')` to `env`; update the storage comment (the private disk now moves too); add a second guard next to `realPublic`: list `storage/app/private/activities` before and after the seeding and throw `Real storage/app/private changed during the run` if it changed (only that folder: the real private disk also holds backups other tools write). `resources/help/README.md`: in the throwaway-storage paragraph, add that the private disk (activity files) moves too.

- [ ] **Step 4: `photo()`** — 1600 × 1000 truecolor GD image, no text (GD cannot shape Arabic), nothing that reads as a real place or club:
  - `field`: sky gradient, green pitch with white lines, a small goal, 6–8 simple figures (circle head + rounded body) in two shirt colours.
  - `beach`: sky, sea band, sand, figures with bags (small rectangles), a sun.
  - `hall`: wall, floor, a long table (rectangle) with figures seated behind it, a board on the wall.
  - `pool`: water with lane lines, the pool edge, figures on the edge.
  - `$variant` shifts the hue a little (±15 per channel) and the figures' x positions (seeded `mt_srand(crc32($scene.$variant))`, restore with `mt_srand()` after).
  Save to a temp PNG (as `logo()` does), wrap in `UploadedFile($tmp, "{$scene}.jpg"…)` — check whether `storeImageVariants` re-encodes (if it keeps the format, write JPEG with `imagejpeg($img, $tmp, 85)` instead to keep files small), call `storeImageVariants($upload, [1600 => $activity->photoDirectory(), 400 => $activity->photoDirectory().'/thumbs'])` exactly like `ActivityPhotoController::store`, return `['path' => $sizes[1600]['path'], 'thumb_path' => $sizes[400]['path']]`; unlink temps in `finally`.

- [ ] **Step 5: `pdf()`** — write a minimal valid one-page PDF (hand-built: header `%PDF-1.4`, catalog, pages, one empty page with a drawn rectangle, xref, trailer; compute the xref offsets with `strlen`) to a temp file, wrap in `UploadedFile($tmp, $originalName, 'application/pdf', null, true)`, return `app(PrivateFileStorage::class)->store($upload, $activity->fileDirectory())`; unlink in `finally`.

- [ ] **Step 6: Run** `php artisan test --filter=Help` — PASS (older tests unchanged; 3c logo tests still pass with the stricter `onThrowawayStorage()` — fix their setup if they set only the public root, and say so in the report).

- [ ] **Step 7: Check the real run** — `npm run help:shots -- app-dashboard` (any one shot): the run succeeds, `storage/help-demo-storage/app/private` exists, the guards stay silent.

- [ ] **Step 8: Commit**

```bash
git add config/filesystems.php scripts/help-shots.mjs resources/help/README.md database/seeders/HelpDemoImages.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoImagesTest.php
git commit -m "feat(help): throwaway private disk, generated activity photos and demo PDF"
```

(Restore `public/help-shots` changes from Step 7: `git checkout -- public/help-shots`.)

---

### Task 3: `social` topic, nav targets and demo activities

**Files:**
- Create: `database/seeders/HelpDemoSocialSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoCompetitionsSeeder`)
- Modify: `resources/js/Components/Sidebar/SidebarSection.vue` (the `data-help` map, line ~70)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`help.topic.social`)
- Modify: `resources/help/topics.php` (only if `help:check` accepts listed slugs before the articles exist; else in Task 4, as earlier phases did)
- Test: `tests/Feature/Help/HelpDemoSocialSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`, `HelpDemoSeeder::USERNAME` (demo user, `created_by_user_id`), `HelpDemoImages::photo()`, `HelpDemoImages::pdf()`, `App\Support\Season::current()` (read it: `start()`, `end()`, `startYear`), the board names `HelpDemoBoardSeeder::PRESIDENT|SALIM|FATIMA|NABIL|LEILA|KAMEL` (active members of the current term), demo players by name (`HelpDemoSeeder::PLAYERS`).
- Produces: topic `social` (icon `heart` — check `Components/Icon.vue`; the sidebar group uses it), nav `data-help`: `nav-activities` (/activities), `nav-literary-reports` (/literary-reports), `nav-activity-types` (/activity-types); public constants for every title the articles quote; activity and report ids written into the task report for the shot tasks.

**Demo facts** (`D` = `CarbonImmutable::today()`; `S0` = the current season's first day; `A(n)` = `max(D − n days, S0)`, so every date stays inside the current season even when the seed day is in early September; activities on the same clamped day are fine). Types are the 5 chapter types the migrations create («تنظيمات النادي», «المشاركات الرياضية», «الحملات التطوعية», «التكوينات», «إعلانات النادي», `#` 1–5); the 6 older types stay, unused.

| # | Title (constant) | Type | Dates | Place / partners / organizer / result | Branch, categories | Responsible, board members | Players | Counts, cost | Media |
|---|---|---|---|---|---|---|---|---|---|
| 1 | «لقاء مع أولياء اللاعبين» `PARENTS` | تنظيمات النادي | `A(50)` | «مقر النادي» | — | PRESIDENT; PRESIDENT, FATIMA, NABIL | — | beneficiaries 40, external 0 | file «محضر اللقاء.pdf» |
| 2 | «دورة الصداقة لأقل من 14 سنة» `TOURNAMENT` | المشاركات الرياضية | `A(35)` → `A(34)` | «ملعب البلدية»; organizer «الرابطة الولائية لكرة القدم»; result «وصيف الدورة» | Football, «أقل من 14 سنة» | SALIM; SALIM | أحمد بن علي, ياسين بوزيد, زكرياء عمراني | beneficiaries 0, external 0, cost 12000 | photos `field` ×2 (variant 0 = «صورة التقرير», caption «الفريق قبل النهائي»; variant 1 caption «تسليم الميداليات») |
| 3 | «حملة تنظيف شاطئ المدينة» `BEACH` | الحملات التطوعية | `A(28)` | «شاطئ المدينة»; partners «جمعية البيئة المحلية» | Swimming, «أصاغر» | LEILA; LEILA, KAMEL | سارة بن يوسف, إيناس حداد, يوسف بوشامة, لينا قادري, شيماء مزياني | beneficiaries 150, external 12, cost 8500 | photos `beach` ×3 (variant 1 = «صورة التقرير», captions «جمع النفايات», «فريق المتطوعين», «الشاطئ بعد الحملة»); file «اتفاقية الشراكة.pdf» |
| 4 | «زيارة شهرية لدار المسنين» `VISIT` | الحملات التطوعية | `A(45)` → `A(14)`, date label «17 من كل شهر» | «دار المسنين» | — | FATIMA; FATIMA | أمين قاسمي, نور شعبان | beneficiaries 30, external 3 | photo `hall` ×1 (variant 1, **not** marked, caption «جلسة مع المقيمين») |
| 5 | «تكوين في الإسعافات الأولية للمدربين» `FIRST_AID` | التكوينات | `A(21)` | «مقر النادي»; partners «مركز التكوين المحلي» | — | KAMEL; KAMEL, NABIL | — | beneficiaries 15, external 1, cost 5000 | photo `hall` ×1 (variant 0, «صورة التقرير», caption «تمرين عملي») |
| 6 | «بطولة السباحة الولائية للأصاغر» `SWIM_CHAMP` | المشاركات الرياضية | `A(14)` | «المسبح نصف الأولمبي»; organizer «الرابطة الولائية للسباحة»; result «المرتبة الثالثة» | Swimming, «أصاغر» | SALIM; — | سارة بن يوسف, إيناس حداد, لينا قادري | beneficiaries 0, external 0 | — |
| 7 | «إعلان فتح التسجيلات للموسم» `SIGNUPS` | إعلانات النادي | `A(55)` | «صفحة النادي» | — | FATIMA; — | — | beneficiaries 0, external 0 | — |

Each activity gets a short Arabic `description` (one sentence) and activities 1–3 and 5 a `report` text of 2–3 sentences (the record and export articles quote that it prints only with «إضافة التفاصيل»); 4, 6, 7 have no report («لم يُكتب التقرير بعد» shows). Photos and files are created only when `HelpDemoImages` returns non-null; set `sort_order` 1, 2, 3 per activity and `in_report` as in the table.

Literary report «التقرير الأدبي للموسم {S}/{S+1}» (`REPORT` constant built from `startYear`), `period_from` = `S0`, `period_to` = the season's last day (same defaults as `LiteraryReportController::index`), `sections` = all `LiteraryReport::SECTIONS`, `include_details` **false** (the export article ticks it), `introduction` 2 sentences, `president_word` empty, `note` empty, no signers (the export article picks PRESIDENT and FATIMA), `created_by_user_id` = demo.

So the activities list shows 7 activities; «المستفيدون» 235; «المشاركون» = 16 external + 13 player links + 9 board links = 38 (recompute from the table against `ActivityController::index` and pin the real figure in the test and the report). The report has 5 chapters: «تنظيمات النادي» 1, «المشاركات الرياضية» 2, «الحملات التطوعية» 2, «التكوينات» 1, «إعلانات النادي» 1; the photo appendix 3 photos.

- [ ] **Step 1: Failing test** — `tests/Feature/Help/HelpDemoSocialSeederTest.php`, same shape as `HelpDemoCompetitionsSeederTest` (seed on `2026-11-18 10:00:00`, then `seed(HelpDemoSeeder::class)`). Assert: 7 activities with the titles, types, dates and date label above; every `starts_on` in [`S0`, `D`]; links (categories, players, board members, responsible) as in the table; no photos and no files (unit tests are off throwaway storage); the index page totals (`get(route('activities.index'))` → `stats.count` 7, `stats.beneficiaries` 235, `stats.participants` = the pinned figure); one literary report with the period, all sections, `include_details` false, no signers; `ActivityChapters::inPeriod(S0, season end)` returns the 5 chapters in type order with counts 1, 2, 2, 1, 1; the 6 older types still exist with 0 activities; `ActivityLog` has no entry for activities or literary reports; older counts unchanged (`Player::where('archived', false)->count()` 22, board members, games). A second method seeds on `2027-09-03 09:00:00`: every activity still starts in [`S0`, `D`] and the chapters are the same. A third method points both disk roots at a temp `…/help-demo-storage/app/{public,private}` folder (as in Task 2's test) and asserts 7 photos (3 `in_report`, at most one per activity), 2 files with the names above, every path exists on its disk; clean up in `tearDown`.

- [ ] **Step 2: Run** `php artisan test --filter=HelpDemoSocialSeederTest` — FAIL (class missing).

- [ ] **Step 3: Seeder** — `HelpDemoSocialSeeder::run()`: `HelpDemoSeeder::assertThrowawayDatabase();` then the facts above (models directly; `$activity->categories()->sync()`, `players()->sync()`, `boardMembers()->sync()` as `ActivityController::syncLinks` does; board members of the **current** term, looked up by player name; photos through `HelpDemoImages::photo()`, files through `HelpDemoImages::pdf()` then `$activity->files()->create($attrs)`). Call it from `HelpDemoSeeder::run()` after `HelpDemoCompetitionsSeeder`.

- [ ] **Step 4: Run** the seeder test and `php artisan test --filter=Help` — PASS (older seeder tests too).

- [ ] **Step 5: Topic and nav** — `topics.php` after `competitions`:

```php
        'social' => ['icon' => 'heart', 'module' => 'activities', 'articles' => ['activity-types', 'record-activity', 'photos-files', 'find-edit-activity', 'create-literary-report', 'export-literary-report']],
```

i18n `help.topic.social` after `help.topic.competitions`: ar «النشاط الاجتماعي», fr «Activités sociales», en «Social activities» (check `nav.social` in fr/en and use the same words). `SidebarSection.vue`: add `'/activities': 'nav-activities', '/literary-reports': 'nav-literary-reports', '/activity-types': 'nav-activity-types'` to the map.

- [ ] **Step 6: Older shots** — no older page shows activities (checked while planning: dashboard and player pages don't). Run `npm run build && npm run help:shots -- app-dashboard` and confirm only date churn; restore it.

- [ ] **Step 7: Run** `php artisan help:check`, `php artisan test --filter=Help`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 8: Commit**

```bash
git add database/seeders/HelpDemoSocialSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoSocialSeederTest.php resources/js/Components/Sidebar/SidebarSection.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/help/topics.php
git commit -m "feat(help): social demo activities, topic and nav targets"
```

---

## Article tasks (4–5): common procedure

For each article: (1) read the page component(s) and controller for every step and quote labels from `ar.json`; (2) add `data-help` attributes (attribute only) to what the steps ring — `ParticipantPicker.vue`, `SearchableSelect.vue`, `MultiSelectFilter.vue` pass them through `$attrs` (check; add a prop only if `inheritAttrs` is off); (3) add shots to `resources/help/shots.js` (reader's state at that step: `before` opens forms, types, selects, ticks boxes, opens the caption editor; never saves; never opens a file picker; `navIntoView(page, '<nav name>')` for sidebar targets); (4) `npm run build && npm run help:shots -- <names>`; (5) open every new light and dark image and check rings land on the right elements, the generated photos show, and nothing reads as a real club or place; (6) write the article with the house front matter (`title` «كيف…», `summary`, `module` when not `activities`, `pages`, `keywords`, `related`), an `:::example` that follows the demo data, `:::step` cards with `::figure`; (7) `php artisan help:check` + `php artisan test --filter=Help`; (8) commit the articles, shots, `shots.js` and the touched Vue files explicitly.

Name shots `soc-<slug>-<state>`; targets `atype-…` (types list), `act-…` (list, form, show page), `lit-…` (report list and edit page). Say which right a step needs when it is not view.

### Task 4: Articles — `activity-types`, `record-activity`, `photos-files`

**Files:** Create `resources/help/ar/social/{activity-types,record-activity,photos-files}.md`; modify `shots.js`, `Settings/ActivityTypes.vue`, `Activities/Index.vue`, `Activities/Form.vue`, `Activities/Show.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `activity-types` — module `activity_types`, pages `[activity-types.index]`. Example: add the type «الأيام التحسيسية» (it gets the next number, after the 11 seeded types — check the real numbers in the demo), then use the pencil to give it a «#» just after «إعلانات النادي» (5) so it prints right after the five chapters; ties sort by name, so check the order the shot shows. Steps: open «أنواع الأنشطة» (nav ring, under «تهيئة التطبيق») → «الاسم» + «إضافة» (needs add; the new type gets the next number) → pencil on a row (needs edit) → «#» and name in the row, the row's «حفظ» → the «الأنشطة» column and the disabled trash on a used type; an unused older type (e.g. «احتفال / مناسبة») can be deleted (needs delete). Facts: each type is a chapter of the literary report, printed in «#» order; types without activities in the period print nothing; names are unique. Shots: list with the 11 types, add form filled (not saved), a row in edit mode (not saved).
2. `record-activity` — pages `[activities.index, activities.create, activities.show]`. Example: record «حملة تنظيف شاطئ المدينة» as in the demo (the form is filled in `before` with the demo values; the demo row already exists, so the shot never saves). Steps: open «الأنشطة» (nav ring) → «نشاط جديد» (needs add) → «العنوان», «النوع», dates («تاريخ النهاية» empty for one day; quote `activities.single_day_hint`) → place, partners, organizer/result (quote `activities.result_hint`; result is for sports participations), «الفرع» and «الفئات المعنية» (the categories follow the branch), «المسؤول» → participants card: «المستفيدون», «متطوعون من خارج النادي», «التكلفة» (quote `activities.cost_hint`), players and board members pickers → «الوصف» and «تقرير النشاط» (quote the new hint from Task 1) → «حفظ» (opens the activity page, `flash.activity_created`). Mention «تاريخ نصّي» with the demo «17 من كل شهر» (quote `activities.date_label_hint`). Keep to 7 steps: merge adjacent fields of one card into one step. Shots: form top card filled, participants card filled with the pickers showing chips, the text card with the hint ringed; the saved activity page of the demo row.
3. `photos-files` — pages `[activities.show]`. Example: the beach campaign: its three photos, «صورة التقرير» on «فريق المتطوعين», then the file «اتفاقية الشراكة.pdf». Steps: open the activity → «إضافة صور» (needs edit; several at once, up to 20, jpg/png/webp, 8 MB each; the picker is the system's — no shot of it) → caption: pencil «التعليق» on a photo, type, save icon → «صورة التقرير» box (one per activity: ticking another moves the mark; the marked photo has a coloured ring; it is the one printed in the report's photo appendix; quote `activities.report_photo_hint`) → click a photo to enlarge → «إرفاق ملف» (upload icon, hover name; pdf/doc/docx/images, 20 MB; files are private and never printed) → trash on a photo or file (asks first; the file is erased). Shots: the photos card of the demo (3 photos, one ringed), caption editor open (typed, not saved), the files card with the upload icon ringed, the lightbox open.

### Task 5: Articles — `find-edit-activity`, `create-literary-report`, `export-literary-report`

**Files:** Create `resources/help/ar/social/{find-edit-activity,create-literary-report,export-literary-report}.md`; modify `shots.js`, `Activities/Index.vue`, `Activities/Show.vue`, `LiteraryReports/Index.vue`, `LiteraryReports/Edit.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `find-edit-activity` — pages `[activities.index, activities.show, activities.edit]`. Example: find the swimming championship by filtering «النوع» «المشاركات الرياضية» and «الفرع» Swimming, then correct its result; and delete a duplicate. Steps: open «الأنشطة» (nav ring) → the three totals (they follow the filters) → search (title, place or partners) and filters (type, branch, «من»/«إلى»: an activity shows when any of its days falls in the range) → open a card → pencil «تعديل» (needs edit) → change and «حفظ» → trash «حذف» (needs delete; shot of the confirm, not confirmed). ⚠️ Warning: deleting erases the activity with its photos and files; it can't be undone. Shots: list with filters applied, the show page header with pencil/trash ringed, the confirm.
2. `create-literary-report` — module `literary_reports`, pages `[literary-reports.index]`. Example: the demo report «التقرير الأدبي للموسم {S}/{S+1}» exists; the article creates «التقرير الأدبي — نصف الموسم» for the season's first half (`S0` → the last day of February) — form filled in `before`, not saved. Steps: open «التقرير الأدبي» (nav ring) → «العنوان» → «من»/«إلى» (proposed: the current season; «إلى» on or after «من») → «تقرير جديد» (needs add; with edit the report opens, without it the list shows the new row — Task 1 behaviour) → the list: title link and pencil (need edit), «تصدير PDF» (needs view), trash (needs delete). Fact (the start date rule): an activity counts in the report whose period contains its **start date**; the demo «زيارة شهرية لدار المسنين» runs over several weeks and counts once, by its first day. Several reports may cover the same period (they are saved editions). Shots: list with the demo report, form filled (not saved).
3. `export-literary-report` — module `literary_reports`, pages `[literary-reports.edit]`. Example: open the demo report, keep all sections, tick «إضافة التفاصيل», signers PRESIDENT («الإمضاء 1» — check labels `literary.signer_1`/`signer_2`) and FATIMA, write a short «كلمة الرئيس», save, export. Steps: open the report (title link; needs edit) → «الأقسام» checkboxes (fixed print order; chapters follow the exporter's rights — one sentence quoting `literary.no_right`, no shot) → «إضافة التفاصيل» + «ملاحظة» (quote `literary.note_hint`) → signers (two different board members; current term first) → texts (president's word, introduction, conclusion — quote labels) → «حفظ» → «تصدير PDF» (hidden until saved: quote `literary.save_first`; the PDF is computed when exported, so the latest activities always print; chapters = activity types with activities, in «#» order; the photo appendix = each activity's «صورة التقرير»). No PDF screenshot (the shot tool can't capture the PDF tab): describe what it holds in one short list. Shots: sections card, details/note/signers card filled (not saved), texts card, header with the unsaved notice (dirty form) and, separately, the clean header with «تصدير PDF» ringed.

---

### Task 6: Phase checks and merge

- [ ] **Step 1:** In the worktree: `php artisan help:check` (73 articles), `php artisan test --filter=Help`, full suite `php -d memory_limit=1G vendor/bin/phpunit`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`.
- [ ] **Step 2:** `npm run help:shots` for the `soc-` shots plus a sample of older ones (owner prefers speed over a full rerun: at least every shot whose page Task 1–3 touched, and `app-dashboard`). Guards silent; `storage/help-demo-storage/app/{public,private}` populated, real `storage/app` unchanged. `git diff --stat public/help-shots`: only new shots may change; restore date churn.
- [ ] **Step 3:** Manual Playwright pass on the demo DB: the 6 articles light/dark at 1280 and 320 px; the `?` panel on «الأنشطة», an activity, the activity form, «أنواع الأنشطة», «التقرير الأدبي», a report; Ctrl+K «نشاط» and «التقرير الأدبي»; a user with literary reports view+add only creates a report and lands on the list with the success message; a user with activities view only sees no pencil/trash/upload controls.
- [ ] **Step 4:** Whole-phase review (spec 3d section + this plan), fix findings, collect the owner nits found while documenting into one AskUserQuestion round (fix the chosen ones before merging), then merge `main` into the branch, rerun Step 1, merge into local `main` (`push-only-verified-tip`: nothing is pushed without the owner's word). Archive `.superpowers/sdd` → `.superpowers/archive/help-3d-sdd/`.
- [ ] **Step 5:** Update `HANDOFF.md` (untracked) and the `help-center-part3` memory: what merged, owner nits. No migration; the local dev DB needs nothing; desktop build only when the owner asks.
