# Literary Report Model Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the literary report PDF look like the club's real 2025 report: one numbered table per activity type (chapter), a photo appendix with one photo per activity, a free note, and two signature blocks. Sports participations get organizer and result fields, and dates print in words.

**Architecture:** The spec is `docs/superpowers/specs/2026-11-08-literary-report-model-layout-design.md`. One migration adds the columns, the 5 chapter types, the single-report-photo data fix and the new section keys. A shared `ActivityChapters` query groups the period's activities by type. `ActivitiesSection` (rewritten) and the new `PhotosSection` both read from it. A new `SignaturesSection` reads the report's two signer ids. `LiteraryReportBuilder` wires the new sections into the existing print flow. `HumanDate` formats dates.

**Tech Stack:** Laravel 13, PHPUnit-style feature tests (`#[Test]` classes extending `Tests\TestCase`), mPDF via `PdfService`, Inertia + Vue 3 `<script setup>`, flat i18n catalogs `resources/js/i18n/{ar,fr,en}.json` read server-side by `App\Support\UiLang`.

## Global Constraints

- Production runs on PostgreSQL. Migrations use only the schema builder and plain query-builder calls, never SQLite-only SQL.
- Offline only. No remote fonts or images. PDF images are read from the public disk through `App\Support\Media::localFile`.
- i18n is insert-only. New keys go into ar/fr/en, each inserted at its alphabetical position. A key segment that follows an existing message may not be a String method name (`link`, `search`, …). Never add a key under an existing message key (e.g. no `activities.report.x`, because `activities.report` is a message). `npm run i18n:check` must pass.
- Stage explicit files only (`git add <paths>`), never `git add -A` or `git add .`. The main checkout holds unrelated untracked files.
- Board member names come from `BoardMember::withPlayer()` (live from the linked player). Player names come from `Player::fullname`.
- Action buttons use `IconButton`. Dark mode: use `primary-300` on dark, not `primary-400`.
- Arabic month names (Algeria): جانفي، فيفري، مارس، أفريل، ماي، جوان، جويلية، أوت، سبتمبر، أكتوبر، نوفمبر، ديسمبر.
- Chapter type names, in order: تنظيمات النادي، المشاركات الرياضية، الحملات التطوعية، التكوينات، إعلانات النادي.

---

## File Structure

| File | Responsibility |
|---|---|
| Create `database/migrations/2026_11_08_100001_literary_report_model_layout.php` | Columns, chapter types, one report photo, new section keys |
| Modify `app/Models/Activity.php` | fillable: organizer, result, date_label |
| Modify `app/Models/ActivityPhoto.php` | drop `REPORT_LIMIT` |
| Modify `app/Models/LiteraryReport.php` | SECTIONS, fillable, casts |
| Modify `app/Http/Requests/ActivityRequest.php` | new activity fields |
| Modify `app/Http/Requests/LiteraryReportRequest.php` | note, include_details, signers |
| Create `app/Support/HumanDate.php` | dates in words |
| Modify `app/Http/Controllers/ActivityPhotoController.php` | single report photo |
| Modify `app/Http/Controllers/ActivityController.php` | drop `reportLimit` prop |
| Create `app/Services/LiteraryReport/ActivityChapters.php` | period activities grouped by type |
| Rewrite `app/Services/LiteraryReport/Sections/ActivitiesSection.php` | chapter tables data |
| Create `app/Services/LiteraryReport/Sections/PhotosSection.php` | photo appendix data |
| Create `app/Services/LiteraryReport/Sections/SignaturesSection.php` | signature blocks data |
| Modify `app/Services/LiteraryReport/LiteraryReportBuilder.php` | wire new sections |
| Rewrite `resources/views/pdf/literary/activities.blade.php` | chapter tables |
| Create `resources/views/pdf/literary/photos.blade.php`, `signatures.blade.php` | new partials |
| Modify `resources/views/pdf/literary-report.blade.php` | no page break before signatures |
| Modify `app/Http/Controllers/LiteraryReportController.php` | edit props: new fields + signer options |
| Modify `resources/js/Pages/LiteraryReports/Edit.vue` | note, details, signers |
| Modify `resources/js/Pages/Activities/{Form,Show,Index}.vue` | new fields, single report photo, date label |
| Modify `resources/js/i18n/{ar,fr,en}.json` | new keys |

---

### Task 0: Worktree

- [ ] **Step 1: Create the worktree and branch**

```bash
cd /d/irnb-laravel
git worktree add -b feat/literary-model-layout /d/irnb-literary-layout main
```

- [ ] **Step 2: Copy dependencies (no junctions) and prepare**

```bash
cp -r /d/irnb-laravel/vendor /d/irnb-literary-layout/vendor
cp -r /d/irnb-laravel/node_modules /d/irnb-literary-layout/node_modules
cp /d/irnb-laravel/.env /d/irnb-literary-layout/.env
cd /d/irnb-literary-layout && php artisan config:clear && npm run build
```

- [ ] **Step 3: Baseline**

Run: `cd /d/irnb-literary-layout && php artisan test --filter='LiteraryReport|Activit'`
Expected: all pass. Write down any failure as a pre-existing failure.

All later commands run in `/d/irnb-literary-layout`.

---

### Task 1: Migration, models, requests

**Files:**
- Create: `database/migrations/2026_11_08_100001_literary_report_model_layout.php`
- Modify: `app/Models/Activity.php`, `app/Models/LiteraryReport.php`, `app/Http/Requests/ActivityRequest.php`, `app/Http/Requests/LiteraryReportRequest.php`
- Test: create `tests/Feature/LiteraryReport/ModelLayoutMigrationTest.php`; modify `tests/Feature/Activities/ActivitySchemaTest.php`, `tests/Feature/Activities/ActivityTypesTest.php`

**Interfaces:**
- Produces: columns `activities.organizer|result|date_label`, `literary_reports.note|include_details|signer_1_board_member_id|signer_2_board_member_id`; `LiteraryReport::SECTIONS = ['cover','intro','members','board','attendance','sports','activities','finance','conclusion','signatures','photos']`. Chapter types get sort_order 1–5; the old seeded types become 6–11.

- [ ] **Step 1: Write the failing migration test**

`tests/Feature/LiteraryReport/ModelLayoutMigrationTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\LiteraryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelLayoutMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_08_100001_literary_report_model_layout.php';

    private const CHAPTERS = ['تنظيمات النادي', 'المشاركات الرياضية', 'الحملات التطوعية', 'التكوينات', 'إعلانات النادي'];

    private const OLD = ['حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'];

    #[Test]
    public function the_new_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('activities', ['organizer', 'result', 'date_label']));
        $this->assertTrue(Schema::hasColumns('literary_reports', ['note', 'include_details', 'signer_1_board_member_id', 'signer_2_board_member_id']));
    }

    #[Test]
    public function chapter_types_come_first_and_the_old_types_follow(): void
    {
        $types = ActivityType::orderBy('sort_order')->get(['name', 'sort_order']);

        $this->assertSame([...self::CHAPTERS, ...self::OLD], $types->pluck('name')->all());
        $this->assertSame(range(1, 11), $types->pluck('sort_order')->all());
    }

    #[Test]
    public function on_existing_data_it_keeps_one_report_photo_appends_sections_and_reuses_a_chapter_name(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $training = ActivityType::create(['name' => 'التكوينات', 'sort_order' => 50]);
        $activity = Activity::create(['title' => 'Seminar', 'activity_type_id' => $training->id, 'starts_on' => '2026-07-05']);
        $activity->photos()->create(['path' => 'a.jpg', 'thumb_path' => 'a.jpg', 'in_report' => true, 'sort_order' => 2]);
        $early = $activity->photos()->create(['path' => 'b.jpg', 'thumb_path' => 'b.jpg', 'in_report' => true, 'sort_order' => 1]);
        $report = LiteraryReport::create(['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['cover', 'activities']]);

        $migration->up();

        $this->assertSame([$early->id], $activity->photos()->where('in_report', true)->pluck('id')->all());
        $this->assertSame(['cover', 'activities', 'signatures', 'photos'], $report->fresh()->sections);
        $this->assertSame(1, ActivityType::where('name', 'التكوينات')->count());
        $this->assertSame(4, $training->fresh()->sort_order);
        $this->assertSame([...self::CHAPTERS, ...self::OLD], ActivityType::orderBy('sort_order')->pluck('name')->all());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ModelLayoutMigrationTest`
Expected: FAIL (columns missing / migration file not found).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_11_08_100001_literary_report_model_layout.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Literary report in the layout of the club's own 2025 report (2026-11-08).
 * Activity types act as the report's chapters. Sports participations carry an
 * organizer and a result, and a free date label can replace the computed date.
 * One photo per activity goes to the photo appendix. Each report gets a note,
 * a details option and two signers.
 */
return new class extends Migration
{
    /** The model report's chapters, in print order. */
    private const CHAPTERS = ['تنظيمات النادي', 'المشاركات الرياضية', 'الحملات التطوعية', 'التكوينات', 'إعلانات النادي'];

    private const NEW_SECTIONS = ['signatures', 'photos'];

    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('organizer', 150)->nullable();
            $table->string('result', 150)->nullable();
            $table->string('date_label', 100)->nullable();
        });

        Schema::table('literary_reports', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->boolean('include_details')->default(false);
            $table->foreignId('signer_1_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->foreignId('signer_2_board_member_id')->nullable()->constrained('board_members')->nullOnDelete();
        });

        $this->addChapterTypes();
        $this->keepOneReportPhoto();
        $this->appendSections();
    }

    public function down(): void
    {
        DB::table('literary_reports')->orderBy('id')->get(['id', 'sections'])->each(function ($report) {
            $sections = array_values(array_diff(json_decode((string) $report->sections, true) ?: [], self::NEW_SECTIONS));
            DB::table('literary_reports')->where('id', $report->id)->update(['sections' => json_encode($sections)]);
        });

        // Chapter types still in use stay; the others go, and the old types move back up.
        $used = DB::table('activities')->distinct()->pluck('activity_type_id')->all();
        DB::table('activity_types')->whereIn('name', self::CHAPTERS)->whereNotIn('id', $used)->delete();
        DB::table('activity_types')->where('sort_order', '>', count(self::CHAPTERS))->decrement('sort_order', count(self::CHAPTERS));

        Schema::table('literary_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signer_2_board_member_id');
            $table->dropConstrainedForeignId('signer_1_board_member_id');
            $table->dropColumn(['note', 'include_details']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['organizer', 'result', 'date_label']);
        });
    }

    /** Chapters take sort orders 1–5 (an existing type of the same name is reused); every other type moves down by 5. */
    private function addChapterTypes(): void
    {
        $now = now();
        DB::table('activity_types')->increment('sort_order', count(self::CHAPTERS));

        foreach (self::CHAPTERS as $i => $name) {
            $id = DB::table('activity_types')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('activity_types')->where('id', $id)->update(['sort_order' => $i + 1, 'updated_at' => $now]);
            } else {
                DB::table('activity_types')->insert(['name' => $name, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /** One report photo per activity: the first flagged one by (sort_order, id) keeps the mark. */
    private function keepOneReportPhoto(): void
    {
        $extra = DB::table('activity_photos')->where('in_report', true)
            ->orderBy('activity_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'activity_id'])
            ->groupBy('activity_id')
            ->flatMap(fn ($photos) => $photos->slice(1)->pluck('id'))
            ->all();

        foreach (array_chunk($extra, 500) as $ids) {
            DB::table('activity_photos')->whereIn('id', $ids)->update(['in_report' => false]);
        }
    }

    /** Existing reports print the new signatures and photo appendix without a manual edit. */
    private function appendSections(): void
    {
        DB::table('literary_reports')->orderBy('id')->get(['id', 'sections'])->each(function ($report) {
            $sections = json_decode((string) $report->sections, true) ?: [];
            $sections = array_values(array_unique(array_merge($sections, self::NEW_SECTIONS)));
            DB::table('literary_reports')->where('id', $report->id)->update(['sections' => json_encode($sections)]);
        });
    }
};
```

- [ ] **Step 4: Update the models and requests**

`app/Models/Activity.php` — in `$fillable`, after `'partners',` add `'organizer', 'result', 'date_label',`:

```php
    protected $fillable = [
        'title', 'activity_type_id', 'starts_on', 'ends_on', 'date_label', 'location', 'partners', 'organizer', 'result',
        'branch_id', 'responsible_board_member_id', 'cost', 'beneficiaries_count',
        'external_volunteers_count', 'description', 'report', 'created_by_user_id',
    ];
```

`app/Models/LiteraryReport.php`:

```php
    /** Every section, in the fixed print order. */
    public const SECTIONS = ['cover', 'intro', 'members', 'board', 'attendance', 'sports', 'activities', 'finance', 'conclusion', 'signatures', 'photos'];

    protected $fillable = [
        'title', 'period_from', 'period_to', 'sections',
        'president_word', 'introduction', 'conclusion', 'note', 'include_details',
        'signer_1_board_member_id', 'signer_2_board_member_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'sections' => 'array',
            'include_details' => 'boolean',
            'signer_1_board_member_id' => 'integer',
            'signer_2_board_member_id' => 'integer',
        ];
    }
```

`app/Http/Requests/ActivityRequest.php` — in `rules()`, after `'partners' => …,` add:

```php
            'organizer' => ['nullable', 'string', 'max:150'],
            'result' => ['nullable', 'string', 'max:150'],
            'date_label' => ['nullable', 'string', 'max:100'],
```

`app/Http/Requests/LiteraryReportRequest.php` — in `rules()`, after `'conclusion' => …,` add:

```php
            'note' => ['nullable', 'string', 'max:5000'],
            'include_details' => ['sometimes', 'boolean'],
            'signer_1_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
            'signer_2_board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
```

- [ ] **Step 5: Update the type-order tests to the new seed**

`tests/Feature/Activities/ActivitySchemaTest.php`, `default_activity_types_are_seeded`:

```php
        $this->assertSame(
            ['تنظيمات النادي', 'المشاركات الرياضية', 'الحملات التطوعية', 'التكوينات', 'إعلانات النادي',
                'حملة تطوعية', 'جلسة توعوية', 'مخيم صيفي', 'نشاط خيري', 'زيارة', 'احتفال / مناسبة'],
            ActivityType::orderBy('sort_order')->pluck('name')->all(),
        );
```

`tests/Feature/Activities/ActivityTypesTest.php`:
- the index assertion: `->has('types', 11)` and `->where('types.0.name', 'تنظيمات النادي')`
- `a_type_can_be_added_renamed_and_deleted`: `$this->assertSame(12, $type->sort_order);`
- `clearing_the_order_keeps_the_stored_one`: `ActivityType::first()` is the old «حملة تطوعية» (id 1), now at 6, so assert `$this->assertSame(6, $type->fresh()->sort_order);`

`tests/Feature/LiteraryReport/LiteraryReportRenderTest.php`: `SECTIONS` now lists `signatures` and `photos`, which have no partial until Tasks 5–6. In the heading loop replace `if ($key === 'cover') {` with `if (in_array($key, ['cover', 'signatures', 'photos'], true)) {` (Task 9 narrows it again).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter='ModelLayoutMigrationTest|ActivitySchemaTest|ActivityTypesTest|LiteraryReport'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_08_100001_literary_report_model_layout.php app/Models/Activity.php app/Models/LiteraryReport.php app/Http/Requests/ActivityRequest.php app/Http/Requests/LiteraryReportRequest.php tests/Feature/LiteraryReport/ModelLayoutMigrationTest.php tests/Feature/LiteraryReport/LiteraryReportRenderTest.php tests/Feature/Activities/ActivitySchemaTest.php tests/Feature/Activities/ActivityTypesTest.php
git commit -m "feat(literary): schema for model layout (chapters, organizer/result, date label, signers, note)"
```

---

### Task 2: HumanDate

**Files:**
- Create: `app/Support/HumanDate.php`
- Test: `tests/Feature/LiteraryReport/HumanDateTest.php`

**Interfaces:**
- Produces: `App\Support\HumanDate::range(CarbonInterface $start, ?CarbonInterface $end, string $locale): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Support\HumanDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HumanDateTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'ar one day' => ['2025-03-28', null, 'ar', '28 مارس'],
            'ar same day end' => ['2025-03-28', '2025-03-28', 'ar', '28 مارس'],
            'ar same month' => ['2025-10-09', '2025-10-10', 'ar', 'من 09 إلى 10 أكتوبر'],
            'ar cross month' => ['2025-03-28', '2025-04-04', 'ar', 'من 28 مارس إلى 04 أفريل'],
            'ar cross year' => ['2025-12-28', '2026-01-04', 'ar', 'من 28 ديسمبر 2025 إلى 04 جانفي 2026'],
            'ar july' => ['2025-07-04', null, 'ar', '04 جويلية'],
            'fr one day' => ['2025-03-28', null, 'fr', '28 mars'],
            'fr same month' => ['2025-10-09', '2025-10-10', 'fr', 'du 09 au 10 octobre'],
            'fr cross month' => ['2025-03-28', '2025-04-04', 'fr', 'du 28 mars au 04 avril'],
            'fr cross year' => ['2025-12-28', '2026-01-04', 'fr', 'du 28 décembre 2025 au 04 janvier 2026'],
            'en same month' => ['2025-10-09', '2025-10-10', 'en', '9–10 October'],
            'en cross month' => ['2025-03-28', '2025-04-04', 'en', '28 March – 4 April'],
            'unknown locale falls back to en' => ['2025-03-28', null, 'de', '28 March'],
            'end before start prints the start' => ['2025-03-28', '2025-03-01', 'ar', '28 مارس'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_writes_dates_in_words(string $start, ?string $end, string $locale, string $expected): void
    {
        $this->assertSame($expected, HumanDate::range(CarbonImmutable::parse($start), $end ? CarbonImmutable::parse($end) : null, $locale));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=HumanDateTest`
Expected: FAIL with "Class App\Support\HumanDate not found".

- [ ] **Step 3: Implement**

`app/Support/HumanDate.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Dates in words for printed documents, written the way the club writes them:
 * «28 مارس», «من 09 إلى 10 أكتوبر». Arabic uses the month names of Algeria.
 * The year is left out, because the document's title carries it, unless the
 * range crosses a year.
 */
final class HumanDate
{
    private const MONTHS = [
        'ar' => ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    public static function range(CarbonInterface $start, ?CarbonInterface $end, string $locale): string
    {
        $locale = isset(self::MONTHS[$locale]) ? $locale : 'en';
        if ($end === null || ! $end->greaterThan($start->copy()->endOfDay())) {
            return self::day($start, $locale, false);
        }

        $withYear = $start->year !== $end->year;
        $sameMonth = ! $withYear && $start->month === $end->month;
        $first = $sameMonth ? self::number($start, $locale) : self::day($start, $locale, $withYear);
        $last = self::day($end, $locale, $withYear);

        return match ($locale) {
            'ar' => "من {$first} إلى {$last}",
            'fr' => "du {$first} au {$last}",
            default => $sameMonth ? "{$first}–{$last}" : "{$first} – {$last}",
        };
    }

    private static function day(CarbonInterface $date, string $locale, bool $withYear): string
    {
        $text = self::number($date, $locale).' '.self::MONTHS[$locale][$date->month - 1];

        return $withYear ? $text.' '.$date->year : $text;
    }

    /** Two-digit day in Arabic and French (the club's style), plain in English. */
    private static function number(CarbonInterface $date, string $locale): string
    {
        return $locale === 'en' ? (string) $date->day : $date->format('d');
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=HumanDateTest`
Expected: PASS (14 cases).

- [ ] **Step 5: Commit**

```bash
git add app/Support/HumanDate.php tests/Feature/LiteraryReport/HumanDateTest.php
git commit -m "feat(literary): HumanDate writes dates in words (Algerian month names)"
```

---

### Task 3: Single report photo

**Files:**
- Modify: `app/Http/Controllers/ActivityPhotoController.php`, `app/Http/Controllers/ActivityController.php` (show), `app/Models/ActivityPhoto.php`, `resources/js/Pages/Activities/Show.vue`, `resources/js/i18n/{ar,fr,en}.json`
- Test: modify `tests/Feature/Activities/ActivityMediaTest.php`, `tests/Feature/Activities/ActivityCrudTest.php`

**Interfaces:**
- Produces: at most one `activity_photos.in_report = true` per activity. `ActivityPhoto::REPORT_LIMIT` is removed. The `reportLimit` Inertia prop is removed.

- [ ] **Step 1: Replace the cap tests with the move-the-mark test**

In `tests/Feature/Activities/ActivityMediaTest.php`:
- delete `at_most_four_photos_go_in_the_report` and `a_photo_refused_by_the_report_cap_is_not_logged`
- add:

```php
    #[Test]
    public function marking_a_report_photo_moves_the_mark(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos(3);
        [$first, $second] = $this->activity->photos()->get()->all();

        $this->put(route('activities.photos.update', [$this->activity, $first]), ['in_report' => true, 'caption' => 'Opening'])
            ->assertSessionHas('success', 'flash.activity_photo_updated');
        $this->put(route('activities.photos.update', [$this->activity, $second]), ['in_report' => true])
            ->assertSessionHas('success', 'flash.activity_photo_updated');

        $this->assertSame([$second->id], $this->activity->photos()->where('in_report', true)->pluck('id')->all());
        $this->assertSame('Opening', $first->fresh()->caption);

        $this->put(route('activities.photos.update', [$this->activity, $second]), ['in_report' => false]);
        $this->assertSame(0, $this->activity->photos()->where('in_report', true)->count());
    }
```

In `tests/Feature/Activities/ActivityCrudTest.php` (show test, around line 179) replace `->where('reportLimit', 4));` with `->missing('reportLimit'));`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter='ActivityMediaTest|ActivityCrudTest'`
Expected: FAIL. `marking_a_report_photo_moves_the_mark` finds two flagged photos, and `missing('reportLimit')` fails.

- [ ] **Step 3: Implement**

`app/Http/Controllers/ActivityPhotoController.php`: add `use App\Models\ActivityPhoto;` (already there) and `use Illuminate\Support\Facades\DB;`, then replace `update()`:

```php
    public function update(Request $request, Activity $activity, ActivityPhoto $photo): RedirectResponse
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:255'],
            'in_report' => ['sometimes', 'boolean'],
        ]);

        $inReport = $request->has('in_report') ? $request->boolean('in_report') : $photo->in_report;

        DB::transaction(function () use ($activity, $photo, $data, $inReport) {
            // One report photo per activity: marking this one moves the mark.
            if ($inReport) {
                ActivityPhoto::query()->where('activity_id', $activity->id)->whereKeyNot($photo->id)
                    ->where('in_report', true)->update(['in_report' => false]);
            }

            $photo->update([
                'caption' => array_key_exists('caption', $data) ? $data['caption'] : $photo->caption,
                'in_report' => $inReport,
            ]);
        });

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_photo_updated');
    }
```

`app/Models/ActivityPhoto.php`: delete the `REPORT_LIMIT` constant and its doc comment.

`app/Http/Controllers/ActivityController.php` `show()`:

```php
        return Inertia::render('Activities/Show', [
            'activity' => $data,
        ]);
```

If `ActivityPhoto` is now unused in `ActivityController`, remove its `use` line (check with `grep -n ActivityPhoto app/Http/Controllers/ActivityController.php`). `ActivitiesSection` still references `ActivityPhoto::REPORT_LIMIT` until Task 4 rewrites it, so in this task change its line `->take(ActivityPhoto::REPORT_LIMIT)` to `->take(1)`.

`resources/js/Pages/Activities/Show.vue`:
- `const props = defineProps({ activity: Object });`
- delete the `inReportCount` computed
- photos heading: `<h2 class="font-semibold">{{ t('activities.photos') }}</h2>`
- the checkbox label:

```vue
                                    <label class="flex items-center gap-1" :title="t('activities.report_photo_hint')">
                                        <input type="checkbox" :checked="p.in_report" class="rounded border-slate-300" @change="toggleReport(p, $event)" />
                                        {{ t('activities.report_photo') }}
                                    </label>
```

- in `toggleReport`, replace the onFinish comment with `// Put the box back to what the server says (another photo may have taken the mark).`

i18n — add in alphabetical position:

| key | ar | fr | en |
|---|---|---|---|
| `activities.report_photo` | صورة التقرير | Photo du rapport | Report photo |
| `activities.report_photo_hint` | صورة واحدة لكل نشاط تُطبع في ملحق صور التقرير الأدبي | Une photo par activité, imprimée dans l'annexe photos du rapport moral | One photo per activity, printed in the literary report's photo appendix |

Leave the old keys (`activities.in_report*`, `flash.activity_report_photos_limit`) in place (insert-only rule).

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter='ActivityMediaTest|ActivityCrudTest|ActivitiesSectionTest' && npm run i18n:check`
Expected: PASS. i18n check clean.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ActivityPhotoController.php app/Http/Controllers/ActivityController.php app/Models/ActivityPhoto.php app/Services/LiteraryReport/Sections/ActivitiesSection.php resources/js/Pages/Activities/Show.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Activities/ActivityMediaTest.php tests/Feature/Activities/ActivityCrudTest.php
git commit -m "feat(activities): one report photo per activity; marking one moves the mark"
```

---

### Task 4: Chapter tables (ActivityChapters + ActivitiesSection)

**Files:**
- Create: `app/Services/LiteraryReport/ActivityChapters.php`
- Rewrite: `app/Services/LiteraryReport/Sections/ActivitiesSection.php`, `resources/views/pdf/literary/activities.blade.php`
- Modify: `app/Services/LiteraryReport/LiteraryReportBuilder.php`, `resources/js/i18n/{ar,fr,en}.json`
- Test: rewrite `tests/Feature/LiteraryReport/ActivitiesSectionTest.php`

**Interfaces:**
- Consumes: `HumanDate::range` (Task 2), the columns from Task 1.
- Produces:
  - `App\Services\LiteraryReport\ActivityChapters::inPeriod(CarbonImmutable $from, CarbonImmutable $to, array $with = []): Collection`. It returns a list of `['type' => string, 'activities' => Collection<Activity>]`, ordered by type `sort_order` then name; activities by `starts_on` then id, with `players_count` and `boardMembers` counts loaded.
  - `ActivitiesSection::build()` returns `['count' => int, 'chapters' => list<['type' => string, 'rows' => list<['number' => '01', 'title' => string, 'details' => string, 'place' => ?string, 'date' => string, 'report' => ?string]>]>, 'summary' => list<['type','count','beneficiaries','participants']>, 'totals' => ['count','beneficiaries','participants']]`.
  - The builder adds `note` (trimmed string) and `include_details` (bool) to the activities data.

- [ ] **Step 1: Write the failing test (replace the file)**

`tests/Feature/LiteraryReport/ActivitiesSectionTest.php`:

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Category;
use App\Models\LiteraryReport;
use App\Models\Player;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\ActivitiesSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivitiesSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
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
    public function only_activities_starting_in_the_period_are_listed(): void
    {
        $this->activity('مخيم صيفي', '2026-08-31', ['title' => 'Last day']);
        $this->activity('مخيم صيفي', '2025-09-01', ['title' => 'First day']);
        $this->activity('مخيم صيفي', '2026-09-01', ['title' => 'After']);

        $this->assertSame(['First day', 'Last day'], array_column($this->build()['chapters'][0]['rows'], 'title'));
    }

    #[Test]
    public function each_type_is_a_chapter_in_the_type_list_order_with_numbered_rows_by_date(): void
    {
        $this->activity('الحملات التطوعية', '2025-10-01', ['title' => 'Clean-up']);
        $this->activity('تنظيمات النادي', '2026-03-28', ['title' => 'Iftar']);
        $this->activity('تنظيمات النادي', '2025-11-01', ['title' => 'Gathering']);

        $chapters = $this->build()['chapters'];

        $this->assertSame(['تنظيمات النادي', 'الحملات التطوعية'], array_column($chapters, 'type'));
        $this->assertSame(['Gathering', 'Iftar'], array_column($chapters[0]['rows'], 'title'));
        $this->assertSame(['01', '02'], array_column($chapters[0]['rows'], 'number'));
        $this->assertSame('01', $chapters[1]['rows'][0]['number']);
    }

    #[Test]
    public function the_details_line_holds_organizer_result_categories_and_partners_when_filled(): void
    {
        $category = Category::create(['name' => 'U13']);
        $a = $this->activity('المشاركات الرياضية', '2026-04-11', [
            'title' => 'دورة التعارف', 'organizer' => 'نادي السلام', 'result' => 'وصيف', 'partners' => 'شباب الوحدة', 'location' => 'غرداية',
        ]);
        $a->categories()->attach($category->id);
        $this->activity('المشاركات الرياضية', '2026-04-18', ['title' => 'Bare']);

        $rows = $this->build()['chapters'][0]['rows'];

        $label = $category->fresh()->localized_name ?: 'U13';
        $this->assertSame("من تنظيم نادي السلام · وصيف · {$label} · بالتنسيق مع شباب الوحدة", $rows[0]['details']);
        $this->assertSame('غرداية', $rows[0]['place']);
        $this->assertSame('', $rows[1]['details']);
        $this->assertNull($rows[1]['place']);
    }

    #[Test]
    public function dates_print_in_words_unless_a_date_label_is_set(): void
    {
        $this->activity('تنظيمات النادي', '2025-10-09', ['title' => 'Trip', 'ends_on' => '2025-10-10']);
        $this->activity('تنظيمات النادي', '2025-10-17', ['title' => 'Watch', 'date_label' => '17 من كل شهر']);

        $rows = $this->build()['chapters'][0]['rows'];

        $this->assertSame('من 09 إلى 10 أكتوبر', $rows[0]['date']);
        $this->assertSame('17 من كل شهر', $rows[1]['date']);
    }

    #[Test]
    public function it_counts_each_chapter_and_keeps_report_texts(): void
    {
        $camp = $this->activity('مخيم صيفي', '2026-07-05', ['beneficiaries_count' => 40, 'external_volunteers_count' => 3, 'report' => 'Went well']);
        $camp->players()->attach(Player::create(['membership_id' => 'A1', 'firstname' => 'Sami', 'lastname' => 'Z'])->id);
        $this->activity('مخيم صيفي', '2026-08-10', ['beneficiaries_count' => 10, 'report' => '   ']);
        $this->activity('جلسة توعوية', '2025-10-01', ['beneficiaries_count' => 25, 'external_volunteers_count' => 1]);

        $data = $this->build();

        $this->assertSame(3, $data['count']);
        $this->assertEquals([
            ['type' => 'جلسة توعوية', 'count' => 1, 'beneficiaries' => 25, 'participants' => 1],
            ['type' => 'مخيم صيفي', 'count' => 2, 'beneficiaries' => 50, 'participants' => 4],
        ], $data['summary']);
        $this->assertSame(['count' => 3, 'beneficiaries' => 75, 'participants' => 5], $data['totals']);
        $this->assertSame(['Went well', null], array_column($data['chapters'][1]['rows'], 'report'));
    }

    #[Test]
    public function an_empty_period_gives_no_chapters(): void
    {
        $data = $this->build();

        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['chapters']);
    }

    #[Test]
    public function the_builder_adds_the_note_and_the_details_option(): void
    {
        $report = LiteraryReport::create([
            'title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['activities'],
            'note' => "  يمكن التأكد عبر الصفحة الرسمية  ", 'include_details' => true,
        ]);

        $sections = app(LiteraryReportBuilder::class)->build($report, User::factory()->admin()->create());

        $this->assertSame('activities', $sections[0]['key']);
        $this->assertSame('يمكن التأكد عبر الصفحة الرسمية', $sections[0]['data']['note']);
        $this->assertTrue($sections[0]['data']['include_details']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=ActivitiesSectionTest`
Expected: FAIL (no `chapters` key).

- [ ] **Step 3: Add i18n keys** (alphabetical position, all three catalogs)

| key | ar | fr | en |
|---|---|---|---|
| `literary.pdf.activity_nature` | نوع النشاط | Nature de l'activité | Activity |
| `literary.pdf.in_partnership` | بالتنسيق مع {name} | en partenariat avec {name} | with {name} |
| `literary.pdf.number` | الرقم | N° | No. |
| `literary.pdf.organized_by` | من تنظيم {name} | organisé par {name} | organised by {name} |

Change the value (not the key) of `literary.pdf.by_type`: ar «ملخص حسب المحور», fr «Résumé par chapitre», en «Summary by chapter».

- [ ] **Step 4: Implement ActivityChapters**

`app/Services/LiteraryReport/ActivityChapters.php`:

```php
<?php

namespace App\Services\LiteraryReport;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The period's activities grouped by type, as the chapters of the literary
 * report. The type list's sort order is the chapter order.
 */
final class ActivityChapters
{
    /**
     * Activities that started in the period. Chapters are ordered by type sort
     * order, then name; rows by start date, then id.
     *
     * @param  array<int|string, mixed>  $with  extra relations to eager-load
     * @return Collection<int, array{type: string, activities: Collection<int, Activity>}>
     */
    public static function inPeriod(CarbonImmutable $from, CarbonImmutable $to, array $with = []): Collection
    {
        return Activity::query()
            ->with(['type:id,name,sort_order', ...$with])
            ->withCount(['players', 'boardMembers'])
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_on')->orderBy('id')
            ->get()
            ->groupBy('activity_type_id')
            ->map(fn (Collection $group) => [
                'type' => (string) ($group->first()->type?->name ?? '—'),
                'order' => [$group->first()->type?->sort_order ?? PHP_INT_MAX, (string) $group->first()->type?->name],
                'activities' => $group->values(),
            ])
            ->sortBy('order')
            ->map(fn (array $chapter) => ['type' => $chapter['type'], 'activities' => $chapter['activities']])
            ->values();
    }
}
```

- [ ] **Step 5: Rewrite ActivitiesSection**

`app/Services/LiteraryReport/Sections/ActivitiesSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Models\Category;
use App\Services\LiteraryReport\ActivityChapters;
use App\Support\HumanDate;
use App\Support\UiLang;
use Carbon\CarbonImmutable;

/**
 * Activities that started in the period, laid out as in the club's own report:
 * one numbered table per activity type (No / activity / place / date). Also
 * returns the counts per chapter and the report texts, for the optional details.
 */
final class ActivitiesSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $locale = app()->getLocale();
        $chapters = ActivityChapters::inPeriod($from, $to, ['categories']);
        $all = $chapters->flatMap(fn (array $chapter) => $chapter['activities']);

        return [
            'count' => $all->count(),
            'chapters' => $chapters->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'rows' => $chapter['activities']->values()->map(fn (Activity $a, int $i) => [
                    'number' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'title' => $a->title,
                    'details' => self::details($a, $locale),
                    'place' => $a->location,
                    'date' => filled($a->date_label) ? trim($a->date_label) : HumanDate::range($a->starts_on, $a->ends_on, $locale),
                    'report' => filled($a->report) ? $a->report : null,
                ])->all(),
            ])->all(),
            'summary' => $chapters->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'count' => $chapter['activities']->count(),
                'beneficiaries' => (int) $chapter['activities']->sum('beneficiaries_count'),
                'participants' => (int) $chapter['activities']->sum('participants_count'),
            ])->all(),
            'totals' => [
                'count' => $all->count(),
                'beneficiaries' => (int) $all->sum('beneficiaries_count'),
                'participants' => (int) $all->sum('participants_count'),
            ],
        ];
    }

    /** «من تنظيم …» · result · categories · «بالتنسيق مع …», whichever are filled. */
    private static function details(Activity $a, string $locale): string
    {
        $fill = fn (string $key, string $value) => str_replace('{name}', trim($value), UiLang::get($key));
        $categories = $a->categories
            ->map(fn (Category $c) => $c->localized_name ?: $c->name)
            ->filter()
            ->implode($locale === 'ar' ? '، ' : ', ');

        return collect([
            filled($a->organizer) ? $fill('literary.pdf.organized_by', $a->organizer) : null,
            filled($a->result) ? trim($a->result) : null,
            $categories !== '' ? $categories : null,
            filled($a->partners) ? $fill('literary.pdf.in_partnership', $a->partners) : null,
        ])->filter()->implode(' · ');
    }
}
```

- [ ] **Step 6: Builder adds note and details option**

In `app/Services/LiteraryReport/LiteraryReportBuilder.php` `build()`, add a match arm before `default`:

```php
                'activities' => app(ActivitiesSection::class)->build($from, $to) + [
                    'note' => trim((string) $report->note),
                    'include_details' => (bool) $report->include_details,
                ],
```

- [ ] **Step 7: Rewrite the partial**

`resources/views/pdf/literary/activities.blade.php`:

```blade
<h2>{{ $l('literary.section.activities') }}</h2>
@if ($data['count'] === 0)
    <p class="none">{{ $l('literary.pdf.no_data') }}</p>
@else
    @if ($data['include_details'])
        <h3>{{ $l('literary.pdf.by_type') }}</h3>
        <table class="data">
            <tr><th>{{ $l('literary.pdf.type') }}</th><th>{{ $l('literary.pdf.count') }}</th><th>{{ $l('literary.pdf.beneficiaries') }}</th><th>{{ $l('literary.pdf.participants') }}</th></tr>
            @foreach ($data['summary'] as $row)
                <tr><td class="start">{{ $row['type'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['beneficiaries'] }}</td><td>{{ $row['participants'] }}</td></tr>
            @endforeach
            <tr class="total"><td class="start">{{ $l('literary.pdf.total') }}</td><td>{{ $data['totals']['count'] }}</td><td>{{ $data['totals']['beneficiaries'] }}</td><td>{{ $data['totals']['participants'] }}</td></tr>
        </table>
    @endif

    @foreach ($data['chapters'] as $chapter)
        <h3>{{ $chapter['type'] }}</h3>
        <table class="data">
            <tr>
                <th style="width: 8%;">{{ $l('literary.pdf.number') }}</th>
                <th>{{ $l('literary.pdf.activity_nature') }}</th>
                <th style="width: 18%;">{{ $l('literary.pdf.location') }}</th>
                <th style="width: 18%;">{{ $l('literary.pdf.date') }}</th>
            </tr>
            @foreach ($chapter['rows'] as $row)
                <tr>
                    <td><bdi dir="ltr">{{ $row['number'] }}</bdi></td>
                    <td class="start">{{ $row['title'] }}@if ($row['details'] !== '')<div class="muted">{{ $row['details'] }}</div>@endif</td>
                    <td>{{ $row['place'] }}</td>
                    <td>{{ $row['date'] }}</td>
                </tr>
            @endforeach
        </table>
        @if ($data['include_details'])
            @foreach ($chapter['rows'] as $row)
                @if ($row['report'])
                    <div style="page-break-inside: avoid; margin: 6px 0;">
                        <strong>{{ $row['title'] }}</strong>
                        <div class="text">{!! $text($row['report']) !!}</div>
                    </div>
                @endif
            @endforeach
        @endif
    @endforeach
@endif

@if ($data['note'] !== '')
    <div class="text" style="margin-top: 10px;">{!! $text($data['note']) !!}</div>
@endif
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter='ActivitiesSectionTest|LiteraryReport' && npm run i18n:check`
Expected: PASS. If `LiteraryReportRenderTest` fails only because the new `signatures`/`photos` keys have no partial yet, that is expected and gets fixed in Tasks 5–6. Note the failure and continue.

- [ ] **Step 9: Commit**

```bash
git add app/Services/LiteraryReport/ActivityChapters.php app/Services/LiteraryReport/Sections/ActivitiesSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/activities.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/ActivitiesSectionTest.php
git commit -m "feat(literary): activities print as one numbered table per type, with note and optional details"
```

---

### Task 5: Photo appendix

**Files:**
- Create: `app/Services/LiteraryReport/Sections/PhotosSection.php`, `resources/views/pdf/literary/photos.blade.php`
- Modify: `app/Services/LiteraryReport/LiteraryReportBuilder.php`, `resources/js/i18n/{ar,fr,en}.json`
- Test: create `tests/Feature/LiteraryReport/PhotosSectionTest.php`

**Interfaces:**
- Consumes: `ActivityChapters::inPeriod` (Task 4), `Media::localFile(?string $url): ?string`.
- Produces: `PhotosSection::build()` returns `[]` when no photo prints; otherwise `['chapters' => list<['number' => '01', 'type' => string, 'photos' => list<['file' => string, 'caption' => string]>]>]`. Builder: `photos` needs `activities.view` and is skipped when empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\PhotosSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotosSectionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'literary-photos-test-'.uniqid();
        File::ensureDirectoryExists(storage_path('app/public/'.$this->dir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/'.$this->dir));
        parent::tearDown();
    }

    private function build(): array
    {
        return app(PhotosSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    private function activity(string $type, string $title, string $startsOn = '2026-03-01'): Activity
    {
        return Activity::create(['title' => $title, 'activity_type_id' => ActivityType::where('name', $type)->value('id'), 'starts_on' => $startsOn]);
    }

    private function photo(Activity $activity, string $name, array $extra = [], bool $onDisk = true): ActivityPhoto
    {
        if ($onDisk) {
            File::put(storage_path("app/public/{$this->dir}/{$name}"), 'jpeg-bytes');
        }

        return ActivityPhoto::create($extra + ['activity_id' => $activity->id, 'path' => "{$this->dir}/{$name}", 'thumb_path' => "{$this->dir}/{$name}"]);
    }

    #[Test]
    public function the_report_photo_wins_else_the_first_photo_else_the_activity_is_left_out(): void
    {
        $flagged = $this->activity('تنظيمات النادي', 'Iftar', '2026-03-28');
        $this->photo($flagged, 'first.jpg', ['sort_order' => 1]);
        $this->photo($flagged, 'chosen.jpg', ['sort_order' => 2, 'in_report' => true, 'caption' => 'Iftar table']);

        $plain = $this->activity('تنظيمات النادي', 'Trip', '2026-04-10');
        $this->photo($plain, 'b.jpg', ['sort_order' => 2]);
        $this->photo($plain, 'a.jpg', ['sort_order' => 1]);

        $this->activity('تنظيمات النادي', 'No photo', '2026-05-01');

        $photos = $this->build()['chapters'][0]['photos'];

        $this->assertCount(2, $photos);
        $this->assertStringEndsWith('chosen.jpg', $photos[0]['file']);
        $this->assertSame('Iftar table', $photos[0]['caption']);
        $this->assertStringEndsWith('a.jpg', $photos[1]['file']);
        $this->assertSame('Trip', $photos[1]['caption']); // no caption: the activity title
        $this->assertFileExists($photos[1]['file']);
    }

    #[Test]
    public function chapters_follow_the_type_order_and_are_numbered_and_photo_less_chapters_are_dropped(): void
    {
        $this->photo($this->activity('الحملات التطوعية', 'Clean-up'), 'c.jpg');
        $this->activity('المشاركات الرياضية', 'Cup'); // no photo: its chapter is dropped
        $this->photo($this->activity('تنظيمات النادي', 'Iftar'), 'i.jpg');

        $chapters = $this->build()['chapters'];

        $this->assertSame(['تنظيمات النادي', 'الحملات التطوعية'], array_column($chapters, 'type'));
        $this->assertSame(['01', '02'], array_column($chapters, 'number'));
    }

    #[Test]
    public function a_missing_file_or_no_photos_gives_an_empty_section(): void
    {
        $this->photo($this->activity('تنظيمات النادي', 'Gone'), 'missing.jpg', ['in_report' => true], onDisk: false);

        $this->assertSame([], $this->build());
    }

    #[Test]
    public function the_builder_skips_the_appendix_when_empty_or_without_the_activities_right(): void
    {
        $this->photo($this->activity('تنظيمات النادي', 'Iftar'), 'i.jpg');
        $report = LiteraryReport::create(['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['photos']]);

        $admin = User::factory()->admin()->create();
        $this->assertSame(['photos'], array_column(app(LiteraryReportBuilder::class)->build($report, $admin), 'key'));

        $noRight = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['literary_reports' => ['view']]])->id]);
        $this->assertSame([], app(LiteraryReportBuilder::class)->build($report, $noRight));

        $empty = LiteraryReport::create(['title' => 'E', 'period_from' => '2020-01-01', 'period_to' => '2020-12-31', 'sections' => ['photos']]);
        $this->assertSame([], app(LiteraryReportBuilder::class)->build($empty, $admin));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=PhotosSectionTest`
Expected: FAIL with "Class …PhotosSection not found".

- [ ] **Step 3: Implement PhotosSection**

`app/Services/LiteraryReport/Sections/PhotosSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\Activity;
use App\Services\LiteraryReport\ActivityChapters;
use App\Support\Media;
use Carbon\CarbonImmutable;

/**
 * The photo appendix: per chapter, one photo per activity, as in the club's
 * own report. Empty when no photo prints, so the builder leaves it out.
 */
final class PhotosSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $chapters = ActivityChapters::inPeriod($from, $to, ['photos'])
            ->map(fn (array $chapter) => [
                'type' => $chapter['type'],
                'photos' => $chapter['activities']->map(fn (Activity $a) => self::photo($a))->filter()->values()->all(),
            ])
            ->filter(fn (array $chapter) => $chapter['photos'] !== [])
            ->values();

        if ($chapters->isEmpty()) {
            return [];
        }

        return [
            'chapters' => $chapters->map(fn (array $chapter, int $i) => [
                'number' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            ] + $chapter)->all(),
        ];
    }

    /** The activity's report photo, else its first photo. Null when it has none on disk. */
    private static function photo(Activity $a): ?array
    {
        $photo = $a->photos->firstWhere('in_report', true) ?? $a->photos->first();
        $file = $photo ? Media::localFile($photo->url) : null;

        return $file === null ? null : ['file' => $file, 'caption' => filled($photo->caption) ? $photo->caption : $a->title];
    }
}
```

- [ ] **Step 4: Wire it into the builder**

In `LiteraryReportBuilder`:

```php
use App\Services\LiteraryReport\Sections\PhotosSection;

    public const MODULES = [
        'members' => 'players',
        'board' => 'board',
        'attendance' => 'attendance',
        'sports' => 'games',
        'activities' => 'activities',
        'finance' => 'finance',
        'photos' => 'activities',
    ];

    public const DATA_SECTIONS = [
        'members' => MembersSection::class,
        'board' => BoardSection::class,
        'attendance' => AttendanceSection::class,
        'sports' => SportsSection::class,
        'activities' => ActivitiesSection::class,
        'finance' => FinanceSection::class,
        'photos' => PhotosSection::class,
    ];

    /** Sections left out when they have nothing to print; the other computed ones always print. */
    private const SKIPPED_WHEN_EMPTY = ['intro', 'conclusion', 'photos', 'signatures'];
```

Replace the skip condition in `build()`:

```php
            // Editorial chapters, the appendix and the signatures are left out when empty; computed chapters always print.
            if ($data === null || (in_array($key, self::SKIPPED_WHEN_EMPTY, true) && $data === [])) {
                continue;
            }
```

- [ ] **Step 5: Partial + i18n**

`resources/views/pdf/literary/photos.blade.php`:

```blade
<h2>{{ $l('literary.section.photos') }}</h2>
@foreach ($data['chapters'] as $chapter)
    @if (! $loop->first)
        <pagebreak />
    @endif
    <div style="text-align: center; margin: 20px 0 14px;">
        <div style="font-size: 30px; font-weight: bold; color: #02a85c;"><bdi dir="ltr">{{ $chapter['number'] }}</bdi></div>
        <div style="font-size: 18px; font-weight: bold; color: #0f172a;">{{ $chapter['type'] }}</div>
    </div>
    @foreach ($chapter['photos'] as $photo)
        <div style="page-break-inside: avoid; text-align: center; margin: 0 0 12px;">
            <img src="{{ $photo['file'] }}" style="max-width: 100%; max-height: 110mm;">
            <div style="font-size: 11px; margin-top: 3px;">{{ $photo['caption'] }}</div>
        </div>
    @endforeach
@endforeach
```

i18n (alphabetical):

| key | ar | fr | en |
|---|---|---|---|
| `literary.section.photos` | ملحق الصور | Annexe photos | Photo appendix |

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter='PhotosSectionTest|ActivitiesSectionTest' && npm run i18n:check`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/LiteraryReport/Sections/PhotosSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/photos.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/PhotosSectionTest.php
git commit -m "feat(literary): photo appendix, one photo per activity grouped by chapter"
```

---

### Task 6: Signatures

**Files:**
- Create: `app/Services/LiteraryReport/Sections/SignaturesSection.php`, `resources/views/pdf/literary/signatures.blade.php`
- Modify: `app/Services/LiteraryReport/LiteraryReportBuilder.php`, `resources/views/pdf/literary-report.blade.php`, `resources/js/i18n/{ar,fr,en}.json`
- Test: create `tests/Feature/LiteraryReport/SignaturesSectionTest.php`

**Interfaces:**
- Consumes: `literary_reports.signer_1_board_member_id|signer_2_board_member_id` (Task 1); `SKIPPED_WHEN_EMPTY` already lists `signatures` (Task 5).
- Produces: `SignaturesSection::build(LiteraryReport $report): array` returns `[]` or `['signers' => list<['label' => string, 'name' => string]>]` in slot order. It needs no right.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\SignaturesSection;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignaturesSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
    }

    private function member(string $name, string $role): BoardMember
    {
        $term = BoardTerm::firstOrCreate(['name' => 'Term Sig'], ['is_current' => true]);

        return BoardMember::create(['name' => $name, 'role' => $role, 'board_term_id' => $term->id, 'status' => 'active']);
    }

    private function report(array $extra = []): LiteraryReport
    {
        return LiteraryReport::create($extra + ['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['signatures']]);
    }

    #[Test]
    public function it_prints_the_chosen_members_with_their_role_in_slot_order(): void
    {
        BoardRole::create(['name' => 'sg_custom', 'label' => 'الأمين العام للجمعية', 'level' => 'bureau', 'sort_order' => 90]);
        $secretary = $this->member('عبد العزيز مصطفى', 'sg_custom');
        $president = $this->member('Président Test', 'president');

        $data = app(SignaturesSection::class)->build($this->report([
            'signer_1_board_member_id' => $president->id, 'signer_2_board_member_id' => $secretary->id,
        ]));

        $this->assertSame([
            ['label' => 'إمضاء وختم واسم '.UiLang::get('president'), 'name' => 'Président Test'],
            ['label' => 'إمضاء وختم واسم الأمين العام للجمعية', 'name' => 'عبد العزيز مصطفى'],
        ], $data['signers']);
    }

    #[Test]
    public function an_empty_slot_is_skipped_and_no_slot_gives_an_empty_section(): void
    {
        $president = $this->member('P', 'president');

        $this->assertCount(1, app(SignaturesSection::class)->build($this->report(['signer_2_board_member_id' => $president->id]))['signers']);
        $this->assertSame([], app(SignaturesSection::class)->build($this->report()));
    }

    #[Test]
    public function a_deleted_member_drops_out(): void
    {
        $president = $this->member('P', 'president');
        $report = $this->report(['signer_1_board_member_id' => $president->id]);
        $president->delete();

        $this->assertSame([], app(SignaturesSection::class)->build($report->fresh()));
    }

    #[Test]
    public function signatures_need_no_extra_right(): void
    {
        $report = $this->report(['signer_1_board_member_id' => $this->member('P', 'president')->id]);
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['literary_reports' => ['view']]])->id]);

        $this->assertSame(['signatures'], array_column(app(LiteraryReportBuilder::class)->build($report, $user), 'key'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SignaturesSectionTest`
Expected: FAIL with "Class …SignaturesSection not found".

- [ ] **Step 3: Implement**

`app/Services/LiteraryReport/Sections/SignaturesSection.php`:

```php
<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\LiteraryReport;
use App\Support\UiLang;

/** The report's signature blocks: the chosen board members with their role. Empty when none is chosen. */
final class SignaturesSection
{
    /** @return array{signers?: list<array{label: string, name: string}>} */
    public function build(LiteraryReport $report): array
    {
        $ids = array_values(array_map('intval', array_filter([$report->signer_1_board_member_id, $report->signer_2_board_member_id])));
        if ($ids === []) {
            return [];
        }

        $members = BoardMember::withPlayer()->whereIn('id', $ids)->get()->keyBy('id');
        $roles = BoardRole::query()->whereIn('name', $members->pluck('role')->all())->pluck('label', 'name');
        $template = UiLang::get('literary.pdf.signature');

        $signers = collect($ids)
            ->map(fn (int $id) => $members->get($id))
            ->filter()
            ->map(fn (BoardMember $m) => [
                // Built-in roles have no label: their name is a UI key, as on the board pages.
                'label' => str_replace('{role}', $roles->get($m->role) ?: UiLang::get((string) $m->role), $template),
                'name' => (string) $m->name,
            ])
            ->values()->all();

        return $signers === [] ? [] : ['signers' => $signers];
    }
}
```

In `LiteraryReportBuilder::build()`, add a match arm:

```php
                'signatures' => app(SignaturesSection::class)->build($report),
```

and `use App\Services\LiteraryReport\Sections\SignaturesSection;`.

- [ ] **Step 4: Partial, no page break, i18n**

`resources/views/pdf/literary/signatures.blade.php`:

```blade
<table style="width: 100%; margin-top: 24px;">
    <tr>
        @foreach ($data['signers'] as $signer)
            <td style="width: 50%; text-align: center; vertical-align: top; padding: 0 8px;">
                <div style="font-weight: bold;">{{ $signer['label'] }}</div>
                <div style="margin-top: 4px;">{{ $signer['name'] }}</div>
                <div style="height: 30mm;"></div>
            </td>
        @endforeach
    </tr>
</table>
```

In `resources/views/pdf/literary-report.blade.php`, the signatures follow on the same page as the content before them:

```blade
@foreach ($sections as $section)
    @if (! $loop->first && $section['key'] !== 'signatures')
        <pagebreak />
    @endif
    @include('pdf.literary.'.$section['key'], ['data' => $section['data']])
@endforeach
```

i18n (alphabetical):

| key | ar | fr | en |
|---|---|---|---|
| `literary.pdf.signature` | إمضاء وختم واسم {role} | Signature, cachet et nom : {role} | Signature, stamp and name: {role} |
| `literary.section.signatures` | التوقيعات | Signatures | Signatures |

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter='SignaturesSectionTest|PhotosSectionTest|ActivitiesSectionTest' && npm run i18n:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/LiteraryReport/Sections/SignaturesSection.php app/Services/LiteraryReport/LiteraryReportBuilder.php resources/views/pdf/literary/signatures.blade.php resources/views/pdf/literary-report.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/SignaturesSectionTest.php
git commit -m "feat(literary): two signature blocks from chosen board members"
```

---

### Task 7: Report edit page (note, details, signers)

**Files:**
- Modify: `app/Http/Controllers/LiteraryReportController.php`, `resources/js/Pages/LiteraryReports/Edit.vue`, `resources/js/i18n/{ar,fr,en}.json`
- Test: modify `tests/Feature/LiteraryReport/LiteraryReportCrudTest.php`

**Interfaces:**
- Consumes: request rules + fillable from Task 1.
- Produces: Inertia props `report.note|include_details|signer_1_board_member_id|signer_2_board_member_id` and `boardMembers: list<{value:int,label:string}>` (current term first).

- [ ] **Step 1: Write the failing tests** (append to `LiteraryReportCrudTest`)

Add `use App\Models\BoardMember; use App\Models\BoardTerm;` at the top, then:

```php
    #[Test]
    public function the_note_details_option_and_signers_are_saved_and_offered(): void
    {
        $old = BoardTerm::create(['name' => 'Old term', 'is_current' => false]);
        BoardTerm::query()->whereKeyNot($old->id)->update(['is_current' => false]);
        $current = BoardTerm::create(['name' => 'Current term', 'is_current' => true]);
        $former = BoardMember::create(['name' => 'Former', 'role' => 'member', 'board_term_id' => $old->id, 'status' => 'former']);
        $president = BoardMember::create(['name' => 'Président', 'role' => 'president', 'board_term_id' => $current->id, 'status' => 'active']);
        $report = $this->report();

        $this->actingAs($this->admin())->put(route('literary-reports.update', $report), [
            'title' => $report->title, 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'note' => 'Page officielle', 'include_details' => true,
            'signer_1_board_member_id' => $president->id, 'signer_2_board_member_id' => null,
        ])->assertSessionHas('success', 'flash.literary_report_updated');

        $report->refresh();
        $this->assertSame('Page officielle', $report->note);
        $this->assertTrue($report->include_details);
        $this->assertSame($president->id, $report->signer_1_board_member_id);
        $this->assertNull($report->signer_2_board_member_id);

        $this->get(route('literary-reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->where('report.note', 'Page officielle')
            ->where('report.include_details', true)
            ->where('report.signer_1_board_member_id', $president->id)
            ->where('boardMembers.0.value', $president->id)
            ->where('boardMembers.1.value', $former->id));
    }

    #[Test]
    public function a_signer_must_be_a_board_member(): void
    {
        $report = $this->report();

        $this->actingAs($this->admin())->put(route('literary-reports.update', $report), [
            'title' => $report->title, 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'signer_1_board_member_id' => 999999,
        ])->assertSessionHasErrors('signer_1_board_member_id');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=LiteraryReportCrudTest`
Expected: FAIL (`report.note` missing / `boardMembers` missing).

- [ ] **Step 3: Controller**

`app/Http/Controllers/LiteraryReportController.php`: add `use App\Models\BoardMember;`. In `edit()`:

```php
        return Inertia::render('LiteraryReports/Edit', [
            'report' => Arr::only($literaryReport->toArray(), [
                'id', 'title', 'period_from', 'period_to', 'sections', 'president_word', 'introduction', 'conclusion',
                'note', 'include_details', 'signer_1_board_member_id', 'signer_2_board_member_id',
            ]),
            'sections' => array_map(fn (string $key) => [
                'key' => $key,
                'allowed' => LiteraryReportBuilder::allows($key, $request->user()),
            ], LiteraryReport::SECTIONS),
            'boardMembers' => self::signerOptions(),
        ]);
```

Add:

```php
    /**
     * Board members for the signer pickers: active members of the current term
     * first, then everyone else.
     *
     * @return list<array{value: int, label: string}>
     */
    private static function signerOptions(): array
    {
        return BoardMember::withPlayer()->with('term:id,name,is_current')->get()
            ->sortBy(fn (BoardMember $m) => [$m->term?->is_current ? 0 : 1, $m->status === 'active' ? 0 : 1, $m->sort_order, $m->id])
            ->map(fn (BoardMember $m) => ['value' => $m->id, 'label' => $m->term ? $m->name.' — '.$m->term->name : (string) $m->name])
            ->values()->all();
    }
```

- [ ] **Step 4: Edit.vue**

`defineProps({ report: Object, sections: Array, boardMembers: Array })`. Add to `useForm`:

```js
    note: props.report.note ?? '',
    include_details: Boolean(props.report.include_details),
    signer_1_board_member_id: props.report.signer_1_board_member_id ?? '',
    signer_2_board_member_id: props.report.signer_2_board_member_id ?? '',
```

`save()`:

```js
function save() {
    form.transform((d) => ({ ...d, signer_1_board_member_id: d.signer_1_board_member_id || null, signer_2_board_member_id: d.signer_2_board_member_id || null }))
        .put(route('literary-reports.update', props.report.id), {
            preserveScroll: true,
            onSuccess: () => form.defaults(),
        });
}
```

Insert a new section between the sections card and the texts card:

```vue
            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input v-model="form.include_details" type="checkbox" class="mt-0.5 rounded border-slate-300 text-primary-600" />
                    <span>{{ t('literary.include_details') }}</span>
                </label>
                <div>
                    <label for="note" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('literary.note') }}</label>
                    <textarea id="note" v-model="form.note" rows="3" class="mt-1 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('literary.note_hint') }}</p>
                    <InputError :message="form.errors.note" class="mt-1" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('literary.signers') }}</h2>
                    <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div v-for="slot in ['signer_1_board_member_id', 'signer_2_board_member_id']" :key="slot">
                            <label :for="slot" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t(slot === 'signer_1_board_member_id' ? 'literary.signer_1' : 'literary.signer_2') }}</label>
                            <select :id="slot" v-model="form[slot]" class="mt-1 w-full rounded-xl border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="">{{ t('literary.no_signer') }}</option>
                                <option v-for="m in boardMembers" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                            <InputError :message="form.errors[slot]" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>
```

- [ ] **Step 5: i18n** (alphabetical)

| key | ar | fr | en |
|---|---|---|---|
| `literary.include_details` | إضافة التفاصيل (ملخص الأعداد ونصوص تقارير الأنشطة) | Ajouter les détails (totaux et textes des comptes rendus) | Include details (totals and activity report texts) |
| `literary.no_signer` | — بدون — | — Aucun — | — None — |
| `literary.note` | ملاحظة | Note | Note |
| `literary.note_hint` | تُطبع بعد جداول الأنشطة، مثال: يمكن التأكد من صحة المعلومات عبر الصفحة الرسمية للنادي | Imprimée après les tableaux d'activités, ex. : informations vérifiables sur la page officielle du club | Printed after the activity tables, e.g. details can be checked on the club's official page |
| `literary.signer_1` | الموقّع الأول | Premier signataire | First signatory |
| `literary.signer_2` | الموقّع الثاني | Second signataire | Second signatory |
| `literary.signers` | التوقيعات | Signataires | Signatories |

Check first that none of these keys exists as a prefix of another key: `grep -n '"literary.note\.' resources/js/i18n/ar.json` must return nothing.

- [ ] **Step 6: Run the tests and the build**

Run: `php artisan test --filter=LiteraryReportCrudTest && npm run i18n:check && npm run build`
Expected: PASS, clean, build OK.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/LiteraryReportController.php resources/js/Pages/LiteraryReports/Edit.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/LiteraryReport/LiteraryReportCrudTest.php
git commit -m "feat(literary): report edit page sets note, details option and signers"
```

---

### Task 8: Activity form, show, list

**Files:**
- Modify: `resources/js/Pages/Activities/Form.vue`, `Show.vue`, `Index.vue`, `resources/js/i18n/{ar,fr,en}.json`
- Test: modify `tests/Feature/Activities/ActivityCrudTest.php`

**Interfaces:**
- Consumes: `organizer`, `result`, `date_label` fillable + rules (Task 1).

- [ ] **Step 1: Write the failing test** (append to `ActivityCrudTest`)

```php
    #[Test]
    public function organizer_result_and_date_label_are_saved(): void
    {
        $this->actingAs($this->admin())->post(route('activities.store'), [
            'title' => 'Wilaya championship', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2025-09-15',
            'organizer' => 'رابطة غرداية', 'result' => 'وصيف', 'date_label' => 'موسم 2025-2026',
        ])->assertSessionHas('success', 'flash.activity_created');

        $activity = Activity::where('title', 'Wilaya championship')->firstOrFail();
        $this->assertSame(['رابطة غرداية', 'وصيف', 'موسم 2025-2026'], [$activity->organizer, $activity->result, $activity->date_label]);

        $this->post(route('activities.store'), [
            'title' => 'Too long', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2025-09-15', 'date_label' => str_repeat('x', 101),
        ])->assertSessionHasErrors('date_label');
    }
```

(Use the file's existing `admin()` helper and `use` lines. Add `use App\Models\ActivityType;` / `use App\Models\Activity;` if missing.)

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=organizer_result_and_date_label_are_saved`
Expected: PASS already (Task 1 added the rules and fillable). This test pins the back end; the rest of the task is UI.

- [ ] **Step 3: Form.vue**

Add to `useForm`, after `partners`:

```js
    organizer: a.organizer ?? '',
    result: a.result ?? '',
    date_label: a.date_label ?? '',
```

After the `ends_on` block (the `<div>` that ends with `form.errors.ends_on`), still inside the same grid, add:

```vue
                    <div>
                        <label :class="label">{{ t('activities.date_label') }}</label>
                        <TextInput v-model="form.date_label" class="mt-1 w-full" maxlength="100" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('activities.date_label_hint') }}</p>
                        <InputError :message="form.errors.date_label" class="mt-1" />
                    </div>
```

After the `partners` block in the location/partners grid, add:

```vue
                    <div>
                        <label :class="label">{{ t('activities.organizer') }}</label>
                        <TextInput v-model="form.organizer" class="mt-1 w-full" maxlength="150" />
                        <InputError :message="form.errors.organizer" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.result') }}</label>
                        <TextInput v-model="form.result" class="mt-1 w-full" maxlength="150" :placeholder="t('activities.result_hint')" />
                        <InputError :message="form.errors.result" class="mt-1" />
                    </div>
```

- [ ] **Step 4: Show.vue and Index.vue**

Show.vue: replace the `dates` computed:

```js
const dates = computed(() => {
    const base = a.value.ends_on && a.value.ends_on !== a.value.starts_on ? `${a.value.starts_on} → ${a.value.ends_on}` : a.value.starts_on;
    return a.value.date_label ? `${a.value.date_label} (${base})` : base;
});
```

After the dates `<div>` in the details `<dl>`, add:

```vue
                        <div v-if="a.organizer"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.organizer') }}</dt><dd class="font-medium">{{ a.organizer }}</dd></div>
                        <div v-if="a.result"><dt class="text-slate-500 dark:text-slate-400">{{ t('activities.result') }}</dt><dd class="font-medium">{{ a.result }}</dd></div>
```

Index.vue: replace the `dates` function:

```js
const dates = (a) => {
    const base = a.ends_on && a.ends_on !== a.starts_on ? `${a.starts_on} → ${a.ends_on}` : a.starts_on;
    return a.date_label ? `${a.date_label} (${base})` : base;
};
```

- [ ] **Step 5: i18n** (alphabetical)

| key | ar | fr | en |
|---|---|---|---|
| `activities.date_label` | تاريخ نصّي | Date en texte | Date as text |
| `activities.date_label_hint` | يُطبع بدل التاريخ في التقرير، مثال: 17 من كل شهر | Imprimée à la place de la date, ex. : le 17 de chaque mois | Printed instead of the date, e.g. every 17th of the month |
| `activities.organizer` | الجهة المنظِّمة | Organisateur | Organizer |
| `activities.result` | النتيجة | Résultat | Result |
| `activities.result_hint` | مثال: بطل الدورة، وصيف | Ex. : champion, finaliste | E.g. champion, runner-up |

- [ ] **Step 6: Run the tests and the build**

Run: `php artisan test --filter='ActivityCrudTest' && npm run i18n:check && npm run build`
Expected: PASS, clean, build OK.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Activities/Form.vue resources/js/Pages/Activities/Show.vue resources/js/Pages/Activities/Index.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Activities/ActivityCrudTest.php
git commit -m "feat(activities): organizer, result and date label on the activity form and pages"
```

---

### Task 9: Render test, PDF smoke, full suite

**Files:**
- Modify: `tests/Feature/LiteraryReport/LiteraryReportRenderTest.php`

- [ ] **Step 1: Extend the render test**

In `every_section_partial_renders_its_title_and_data`:
- give the activity organizer/result/date label, a photo on disk, and a signer:

```php
        $activity = Activity::create([
            'title' => 'Render Activity', 'activity_type_id' => ActivityType::where('name', 'تنظيمات النادي')->value('id'), 'starts_on' => '2026-05-05',
            'organizer' => 'Render Organizer', 'result' => 'Render Result', 'date_label' => 'Render Date Label',
        ]);
        $dir = 'literary-render-'.uniqid();
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/public/'.$dir));
        \Illuminate\Support\Facades\File::put(storage_path("app/public/{$dir}/p.jpg"), base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z'));
        $activity->photos()->create(['path' => "{$dir}/p.jpg", 'thumb_path' => "{$dir}/p.jpg", 'caption' => 'Render Caption']);
```

  (replace the existing `Activity::create([... 'Render Activity' ...])` call with this block)
- capture the board member: `$signer = BoardMember::create([... 'Board Person' ...]);`
- report: add `'note' => 'Render Note', 'signer_1_board_member_id' => $signer->id`
- after rendering, clean up: `\Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/public/'.$dir));`
- the heading loop (Task 1 made it skip `cover`, `signatures`, `photos`) now skips only `cover` and `signatures` (signatures have no heading): `if (in_array($key, ['cover', 'signatures'], true)) { continue; }`
- add to the values list: `'Render Organizer', 'Render Result', 'Render Date Label', 'Render Note', 'Render Caption', 'تنظيمات النادي'`
- assert the signature label prefix: `$this->assertStringContainsString(e(trim(str_replace('{role}', '', UiLang::get('literary.pdf.signature')))), $html);`

- [ ] **Step 2: Run the literary report suite**

Run: `php artisan test --filter=LiteraryReport`
Expected: PASS (render, PDF smoke, sections, CRUD, migration, HumanDate).

- [ ] **Step 3: Full suite + checks**

Run: `php artisan test` (takes a while). Then `npm run i18n:check`, then `vendor/bin/pint --dirty`.
Expected: everything passes except the known failures noted in Task 0. Fix any Pint changes and include them in the commit.

- [ ] **Step 4: Visual check of the PDF (owner-facing)**

Seed a few activities in the worktree DB (one per chapter, one with a date label, one with a photo), set a note and two signers, then open `/literary-reports/{id}/pdf` in Arabic. Check:
- the tables run right to left with No on the right
- the details line is muted under the title
- the dates read «28 مارس»
- the note appears after the tables
- the signatures sit side by side on the same page
- the appendix has a divider per chapter with one photo each

See the memory notes on mPDF RTL: check visually.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/LiteraryReport/LiteraryReportRenderTest.php
git commit -m "test(literary): render test covers chapters, note, signatures and photo appendix"
```

- [ ] **Step 6: Finish**

Use superpowers:finishing-a-development-branch. Before any push: check `git log origin/main..main` and the main tip (other sessions move main). Deploy notes: `php artisan migrate`, then desktop build.
