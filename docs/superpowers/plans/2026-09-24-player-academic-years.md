# Player Academic Tracking v2 (School Years, /10 Scale, Certificates) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the existing (unreleased) academic feature so each school year is its own record with its own school info and trimester grades (/10 for primary, /20 otherwise), a year average, and a per-trimester certificate with thresholds editable in Settings.

**Architecture:** Two tables — `player_academic_years` (school info per year) and `player_academic_records` (one trimester, belongs to a year). Scale derives from the year's education level (`EducationLevel::scale()`). One SQL helper, `PlayerAcademicRecord::latestOn20Sql()`, defines "latest trimester converted to /20" for filter + dashboard. `App\Support\CertificateThresholds` reads thresholds from club settings and suggests a certificate. The branch has never been deployed, so the original migration is rewritten in place.

**Tech Stack:** Laravel 13, Inertia + Vue 3 `<script setup>`, vue-i18n flat keys in `resources/js/i18n/{ar,en,fr}.json`, chart.js/vue-chartjs, mPDF, PHPUnit `#[Test]`, SQLite (tests in-memory) + MySQL.

**Spec:** `docs/superpowers/specs/2026-09-23-player-academic-gpa-design.md` (v2).

**Starting point:** branch `feat/player-academic-gpa` at `89c177e` in worktree `D:/irnb-academic`. v1 exists: `AcademicPeriod` (T1–T3), `EducationLevel`, `PlayerAcademicRecord` (player_id, academic_year, period, gpa, remark), `SaveAcademicRecordRequest`, `PlayerAcademicRecordController`, `AcademicSection.vue`, PDF, list filter `academic`, dashboard `academic` block, player columns `education_level/institution/field_of_study`.

## Global Constraints

- Periods: `T1`, `T2`, `T3` only; rank 1, 2, 3.
- Education levels: `primary`, `middle`, `secondary`, `vocational`, `licence`, `master`, `doctorate`.
- Scale: `primary` → 10, every other level → 20. Pass mark = scale / 2.
- `academic_year` = start year (2025 means "2025/2026"), integer 1990–2100. Unique per player.
- One grade per trimester per year. Grade 0 – scale of the year.
- Certificates: `excellence`, `congratulations`, `encouragement`, `honor_roll` (highest → lowest), or none. One per trimester.
- Default thresholds: /20 → 16, 15, 14, 12; /10 → 8, 7.5, 7, 6 (excellence, congratulations, encouragement, honor_roll). Stored in `website_configs.settings.academicCertificates` as `{"20": {...}, "10": {...}}`.
- Year average = mean of entered trimesters, 2 decimals; `provisional` while fewer than 3.
- "Latest trimester" = highest academic_year, then highest period rank, then highest id. At risk = latest trimester < half its scale. Club average = mean of students' latest trimester converted to /20.
- Current school year = `App\Support\Season::current()->startYear`.
- School fields are removed from `players` (form, requests, fillable, migration).
- Section hidden when `is_student = false`; data kept.
- Permissions: route-name derived (`players.*` → players module); `players.academic-report` override → view (already present).
- Every raw SQL fragment must work on SQLite and MySQL.
- All UI strings in all three i18n catalogs; `npm run i18n:check` passes. Delete i18n keys that become unused.
- Stage explicit paths only; never `git add -A`/`.`; never commit build output.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- Work only in `D:/irnb-academic`; never touch `D:/irnb-laravel`.

## File Map

| File | Change |
|---|---|
| `database/migrations/2026_09_23_110000_add_academic_tracking.php` | rewrite: years + records tables, no player columns |
| `app/Enums/EducationLevel.php` | + `scale()` |
| `app/Enums/AcademicCertificate.php` | new |
| `app/Models/PlayerAcademicYear.php` | new |
| `app/Models/PlayerAcademicRecord.php` | rework |
| `app/Models/Player.php` | `academicYears()`, `academicRecords()` hasManyThrough; drop school fillable |
| `app/Support/CertificateThresholds.php` | new |
| `app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php` | threshold rules |
| `resources/js/Pages/Settings/General.vue` | thresholds block |
| `app/Http/Requests/Player/SaveAcademicRecordRequest.php` | rework (store/update grade) |
| `app/Http/Requests/Player/UpdateAcademicYearRequest.php` | new |
| `app/Http/Controllers/PlayerAcademicRecordController.php` | rework |
| `app/Http/Controllers/PlayerAcademicYearController.php` | new |
| `routes/web.php` | year routes |
| `app/Http/Controllers/PlayerController.php` | show props, filters |
| `app/Http/Requests/Player/{Store,Update}PlayerRequest.php` | drop school rules |
| `app/Services/Dashboard/MemberStats.php` | academic block v2 |
| `app/Http/Controllers/ReportController.php`, `resources/views/pdf/academic-report.blade.php` | PDF v2 |
| `resources/js/Pages/Players/Partials/AcademicSection.vue` | rewrite |
| `resources/js/Pages/Players/Partials/PlayerForm.vue` | drop school fields |
| `resources/js/Pages/Players/Index.vue` | certificate filter |
| `resources/js/Pages/Dashboard/Partials/MembersTab.vue` | certificate counts |
| tests | `PlayerAcademicRecordTest`, `PlayerAcademicYearTest` (new), `PlayerAcademicFilterTest`, `CertificateThresholdsTest` (new), `Dashboard/MemberStatsTest`, `ReportPdfTest` |

---

### Task 1: Data layer v2

**Files:** migration (rewrite), `app/Enums/EducationLevel.php`, create `app/Enums/AcademicCertificate.php`, create `app/Models/PlayerAcademicYear.php`, `app/Models/PlayerAcademicRecord.php`, `app/Models/Player.php`, test `tests/Feature/PlayerAcademicModelTest.php` (new). Also make every existing test that still compiles against removed columns compile — no: other tests will be rewritten in Tasks 3–5; in this task only run the new model test. The full suite is expected to have failures in academic tests until Task 5; say so in the report.

**Interfaces produced:**
- `EducationLevel::scale(): int` (primary 10, else 20); `EducationLevel::passMark(): float` (scale/2).
- `AcademicCertificate` string enum: `Excellence='excellence'`, `Congratulations='congratulations'`, `Encouragement='encouragement'`, `HonorRoll='honor_roll'`; `static values(): array`; `rank(): int` (excellence 4 … honor_roll 1).
- `PlayerAcademicYear` fillable `player_id, academic_year, education_level, institution, field_of_study`; casts academic_year int; `player()`, `records(): HasMany` ordered by period rank; `level(): EducationLevel`; `scale(): int`; `average(): ?float` (mean of loaded/queried records' gpa, round 2, null if none); `isProvisional(): bool` (records count < 3); appends `scale`, `average`, `is_provisional` for serialization.
- `PlayerAcademicRecord` fillable `player_academic_year_id, period, gpa, certificate, remark`; casts gpa decimal:2; `academicYear(): BelongsTo`; `static latestOn20Sql(string $playerIdColumn = 'players.id'): string`.
- `Player::academicYears(): HasMany` ordered by academic_year; `Player::academicRecords(): HasManyThrough` (through PlayerAcademicYear).

- [ ] **Step 1: failing test** `tests/Feature/PlayerAcademicModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\EducationLevel;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerAcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicModelTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function student(): Player
    {
        return Player::create([
            'membership_id' => '6'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'M'.$this->seq, 'lastname' => 'Test', 'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function year(Player $p, int $year, string $level = 'secondary'): PlayerAcademicYear
    {
        return $p->academicYears()->create(['academic_year' => $year, 'education_level' => $level]);
    }

    #[Test]
    public function primary_is_out_of_10_and_others_out_of_20(): void
    {
        $this->assertSame(10, EducationLevel::Primary->scale());
        $this->assertSame(20, EducationLevel::Secondary->scale());
        $this->assertSame(5.0, EducationLevel::Primary->passMark());
    }

    #[Test]
    public function year_average_is_the_mean_of_entered_trimesters_and_provisional_until_three(): void
    {
        $y = $this->year($this->student(), 2025);
        $y->records()->create(['period' => 'T2', 'gpa' => 11]);
        $y->records()->create(['period' => 'T1', 'gpa' => 12.5]);

        $y->refresh();
        $this->assertSame(['T1', 'T2'], $y->records->pluck('period')->all());
        $this->assertSame(11.75, $y->average());
        $this->assertTrue($y->isProvisional());

        $y->records()->create(['period' => 'T3', 'gpa' => 14]);
        $y->refresh();
        $this->assertSame(12.5, $y->average());
        $this->assertFalse($y->isProvisional());
        $this->assertNull($this->year($this->student(), 2025)->average());
    }

    #[Test]
    public function latest_on_20_uses_the_latest_trimester_and_converts_primary(): void
    {
        $a = $this->student();
        $this->year($a, 2024)->records()->create(['period' => 'T3', 'gpa' => 15]);
        $this->year($a, 2025)->records()->create(['period' => 'T1', 'gpa' => 9]);   // later year wins
        $b = $this->student();
        $yb = $this->year($b, 2025, 'primary');
        $yb->records()->create(['period' => 'T1', 'gpa' => 4]);
        $yb->records()->create(['period' => 'T2', 'gpa' => 7]);                      // 7/10 → 14/20
        $c = $this->student();

        $latest = DB::table('players')
            ->selectRaw('players.id, ('.PlayerAcademicRecord::latestOn20Sql().') as v')
            ->pluck('v', 'id');

        $this->assertEquals(9.0, (float) $latest[$a->id]);
        $this->assertEquals(14.0, (float) $latest[$b->id]);
        $this->assertNull($latest[$c->id]);
    }

    #[Test]
    public function deleting_a_year_deletes_its_trimesters_and_player_reaches_records_through_years(): void
    {
        $p = $this->student();
        $y = $this->year($p, 2025);
        $y->records()->create(['period' => 'T1', 'gpa' => 12]);
        $this->assertSame(1, $p->academicRecords()->count());

        $y->delete();
        $this->assertDatabaseCount('player_academic_records', 0);
    }
}
```

- [ ] **Step 2:** run `php artisan test --filter=PlayerAcademicModelTest` → FAIL.

- [ ] **Step 3: migration** — replace the body of `database/migrations/2026_09_23_110000_add_academic_tracking.php`:

```php
    public function up(): void
    {
        Schema::create('player_academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Start year of the school year: 2025 means "2025/2026".
            $table->unsignedSmallInteger('academic_year');
            $table->string('education_level', 20);
            $table->string('institution')->nullable();
            $table->string('field_of_study')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'academic_year']);
        });

        Schema::create('player_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10);
            $table->decimal('gpa', 4, 2);
            $table->string('certificate', 20)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['player_academic_year_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_academic_records');
        Schema::dropIfExists('player_academic_years');
    }
```

- [ ] **Step 4: enums.** Add to `EducationLevel`:

```php
    /** Primary school grades out of 10; every later level out of 20. */
    public function scale(): int
    {
        return $this === self::Primary ? 10 : 20;
    }

    public function passMark(): float
    {
        return $this->scale() / 2;
    }
```

Create `app/Enums/AcademicCertificate.php`:

```php
<?php

namespace App\Enums;

/** Distinctions a school awards for a trimester, highest first. */
enum AcademicCertificate: string
{
    case Excellence = 'excellence';
    case Congratulations = 'congratulations';
    case Encouragement = 'encouragement';
    case HonorRoll = 'honor_roll';

    public function rank(): int
    {
        return match ($this) {
            self::Excellence => 4,
            self::Congratulations => 3,
            self::Encouragement => 2,
            self::HonorRoll => 1,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 5: models.** `app/Models/PlayerAcademicYear.php`:

```php
<?php

namespace App\Models;

use App\Enums\AcademicPeriod;
use App\Enums\EducationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One school year of a student player: where they studied, and their trimester grades. */
class PlayerAcademicYear extends Model
{
    protected $fillable = ['player_id', 'academic_year', 'education_level', 'institution', 'field_of_study'];

    protected $appends = ['scale', 'average', 'is_provisional'];

    protected function casts(): array
    {
        return ['academic_year' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Trimesters of this year, T1 first. */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerAcademicRecord::class)
            ->orderByRaw(AcademicPeriod::rankSql('period'))
            ->orderBy('id');
    }

    public function level(): EducationLevel
    {
        return EducationLevel::from($this->education_level);
    }

    public function scale(): int
    {
        return $this->level()->scale();
    }

    /** Mean of the trimesters entered so far, or null when none. */
    public function average(): ?float
    {
        $grades = $this->records->map(fn (PlayerAcademicRecord $r) => (float) $r->gpa);

        return $grades->isEmpty() ? null : round($grades->avg(), 2);
    }

    /** The average is final only once all three trimesters are in. */
    public function isProvisional(): bool
    {
        return $this->records->count() < count(AcademicPeriod::cases());
    }

    public function getScaleAttribute(): int
    {
        return $this->scale();
    }

    public function getAverageAttribute(): ?float
    {
        return $this->average();
    }

    public function getIsProvisionalAttribute(): bool
    {
        return $this->isProvisional();
    }
}
```

Note: the `$appends` accessors touch `$this->records`; always eager-load `records` wherever years are serialized (profile, PDF) to avoid N+1.

`app/Models/PlayerAcademicRecord.php` — replace class body:

```php
/** One graded trimester of a school year, on that year's scale (/10 primary, /20 otherwise). */
class PlayerAcademicRecord extends Model
{
    protected $fillable = ['player_academic_year_id', 'period', 'gpa', 'certificate', 'remark'];

    protected function casts(): array
    {
        return ['gpa' => 'decimal:2'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(PlayerAcademicYear::class, 'player_academic_year_id');
    }

    /**
     * Correlated subquery: a player's most recent trimester grade converted to /20
     * (NULL when none). The one definition of "latest" for list filters and dashboard stats.
     */
    public static function latestOn20Sql(string $playerIdColumn = 'players.id'): string
    {
        $rank = AcademicPeriod::rankSql('par.period');

        return "SELECT par.gpa * 20.0 / (CASE pay.education_level WHEN 'primary' THEN 10 ELSE 20 END)"
            .' FROM player_academic_records par'
            .' JOIN player_academic_years pay ON pay.id = par.player_academic_year_id'
            ." WHERE pay.player_id = {$playerIdColumn}"
            ." ORDER BY pay.academic_year DESC, {$rank} DESC, par.id DESC LIMIT 1";
    }
}
```

Remove `PASS_MARK`, `scopeChronological`, `latestGpaSql`, `player()` (update the `use` lines; keep `AcademicPeriod`, `BelongsTo`, `Model`). Callers of the removed members are rewritten in Tasks 3–5.

`app/Models/Player.php`: remove `'education_level', 'institution', 'field_of_study'` from `$fillable`; replace `academicRecords()` with:

```php
    /** School years, oldest first. */
    public function academicYears(): HasMany
    {
        return $this->hasMany(PlayerAcademicYear::class)->orderBy('academic_year');
    }

    public function academicRecords(): HasManyThrough
    {
        return $this->hasManyThrough(PlayerAcademicRecord::class, PlayerAcademicYear::class);
    }
```

(import `Illuminate\Database\Eloquent\Relations\HasManyThrough`).

- [ ] **Step 6:** `php artisan test --filter=PlayerAcademicModelTest` → 4 pass.
- [ ] **Step 7:** delete the v1 test file `tests/Feature/PlayerAcademicRecordTest.php`? **No** — Task 3 rewrites it. Commit only this task's files:

```bash
git add database/migrations/2026_09_23_110000_add_academic_tracking.php app/Enums/EducationLevel.php app/Enums/AcademicCertificate.php app/Models/PlayerAcademicYear.php app/Models/PlayerAcademicRecord.php app/Models/Player.php tests/Feature/PlayerAcademicModelTest.php
git commit -m "feat(academic): school years own their trimesters, /10 for primary, certificates enum"
```

---

### Task 2: Certificate thresholds (settings + suggestion)

**Files:** create `app/Support/CertificateThresholds.php`; modify `app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php`; modify `resources/js/Pages/Settings/General.vue`; i18n; test `tests/Feature/CertificateThresholdsTest.php`.

**Interfaces produced:**
- `CertificateThresholds::all(): array` → `['20' => ['excellence'=>16.0,'congratulations'=>15.0,'encouragement'=>14.0,'honor_roll'=>12.0], '10' => [8.0, 7.5, 7.0, 6.0 keyed likewise]]`, merged over defaults from `WebsiteConfig::singleton()->settings['academicCertificates']`.
- `CertificateThresholds::suggest(float $gpa, int $scale): ?AcademicCertificate` — highest certificate whose threshold ≤ gpa for that scale.

- [ ] **Step 1: failing test** `tests/Feature/CertificateThresholdsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AcademicCertificate;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\CertificateThresholds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateThresholdsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_suggest_by_scale(): void
    {
        $this->assertSame(AcademicCertificate::Excellence, CertificateThresholds::suggest(16, 20));
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(15.5, 20));
        $this->assertSame(AcademicCertificate::Encouragement, CertificateThresholds::suggest(14, 20));
        $this->assertSame(AcademicCertificate::HonorRoll, CertificateThresholds::suggest(12, 20));
        $this->assertNull(CertificateThresholds::suggest(11.99, 20));
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(7.5, 10));
        $this->assertNull(CertificateThresholds::suggest(5.5, 10));
    }

    #[Test]
    public function thresholds_are_saved_from_settings_and_used(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->put(route('settings.update'), [
            'settings' => ['academicCertificates' => [
                '20' => ['excellence' => 17, 'congratulations' => 15, 'encouragement' => 14, 'honor_roll' => 12],
                '10' => ['excellence' => 8, 'congratulations' => 7.5, 'encouragement' => 7, 'honor_roll' => 6],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(17.0, CertificateThresholds::all()['20']['excellence']);
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(16, 20));
        $this->assertSame('DZD', WebsiteConfig::singleton()->settings['currency']); // other settings kept
    }

    #[Test]
    public function thresholds_must_fit_the_scale_and_descend(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->put(route('settings.update'), [
            'settings' => ['academicCertificates' => [
                '20' => ['excellence' => 21, 'congratulations' => 15, 'encouragement' => 16, 'honor_roll' => 12],
                '10' => ['excellence' => 8, 'congratulations' => 7.5, 'encouragement' => 7, 'honor_roll' => 6],
            ]],
        ])->assertSessionHasErrors(['settings.academicCertificates.20.excellence', 'settings.academicCertificates.20.encouragement']);
    }
}
```

If `settings.update` needs other required fields, check `UpdateWebsiteConfigRequest` and add minimal valid ones to the payloads.

- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** `app/Support/CertificateThresholds.php`:

```php
<?php

namespace App\Support;

use App\Enums\AcademicCertificate;
use App\Models\WebsiteConfig;

/** Minimum grade for each certificate, per grading scale, from club settings. */
final class CertificateThresholds
{
    public const DEFAULTS = [
        '20' => ['excellence' => 16.0, 'congratulations' => 15.0, 'encouragement' => 14.0, 'honor_roll' => 12.0],
        '10' => ['excellence' => 8.0, 'congratulations' => 7.5, 'encouragement' => 7.0, 'honor_roll' => 6.0],
    ];

    /** @return array<string, array<string, float>> */
    public static function all(): array
    {
        $saved = (WebsiteConfig::singleton()->settings ?? [])['academicCertificates'] ?? [];
        $out = [];

        foreach (self::DEFAULTS as $scale => $defaults) {
            foreach ($defaults as $certificate => $default) {
                $value = $saved[$scale][$certificate] ?? null;
                $out[$scale][$certificate] = is_numeric($value) ? (float) $value : $default;
            }
        }

        return $out;
    }

    /** The highest certificate the grade reaches on its scale, or null. */
    public static function suggest(float $gpa, int $scale): ?AcademicCertificate
    {
        $thresholds = self::all()[(string) $scale] ?? null;
        if ($thresholds === null) {
            return null;
        }

        foreach (AcademicCertificate::cases() as $certificate) { // highest first
            if ($gpa >= $thresholds[$certificate->value]) {
                return $certificate;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4:** validation in `UpdateWebsiteConfigRequest::rules()` after the `settings.fileDrawerSize` rule:

```php
            'settings.academicCertificates' => ['nullable', 'array'],
            'settings.academicCertificates.20' => ['required_with:settings.academicCertificates', 'array'],
            'settings.academicCertificates.10' => ['required_with:settings.academicCertificates', 'array'],
            'settings.academicCertificates.20.excellence' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'gte:settings.academicCertificates.20.congratulations'],
            'settings.academicCertificates.20.congratulations' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'gte:settings.academicCertificates.20.encouragement'],
            'settings.academicCertificates.20.encouragement' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20', 'gte:settings.academicCertificates.20.honor_roll'],
            'settings.academicCertificates.20.honor_roll' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:20'],
            'settings.academicCertificates.10.excellence' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'gte:settings.academicCertificates.10.congratulations'],
            'settings.academicCertificates.10.congratulations' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'gte:settings.academicCertificates.10.encouragement'],
            'settings.academicCertificates.10.encouragement' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10', 'gte:settings.academicCertificates.10.honor_roll'],
            'settings.academicCertificates.10.honor_roll' => ['required_with:settings.academicCertificates', 'numeric', 'min:0', 'max:10'],
```

(`WebsiteConfigController::update` already array-merges `settings`, so other keys survive. Verify the nested `academicCertificates` is replaced as a whole, which is fine.)

- [ ] **Step 5: Settings UI.** In `resources/js/Pages/Settings/General.vue` add a new card after the existing "settings" card, same markup style as sibling cards (`<h2 class="mb-4 text-base font-semibold ...">`), titled `t('academic_certificates')`, with a short hint `t('academic_certificates_hint')`. A 3-column grid: row label = certificate name (`t('certificate_excellence')` …), column "/ 20" and "/ 10" number inputs (`step="0.25" min="0" max="20|10"`). Own `useForm` (`certificatesForm`) initialised from `props.config?.settings?.academicCertificates` merged over the defaults (hardcode the same default numbers in JS), a save button like siblings, submitting `{ settings: { academicCertificates: { '20': {...}, '10': {...} } } }` via `.put(route('settings.update'))` with `preserveScroll: true`. Show errors with the keys `settings.academicCertificates.20.excellence` etc. under each input.

- [ ] **Step 6: i18n** (ar / en / fr), add with the existing merge helper `.superpowers/sdd/i18n-merge.mjs` (new keys file):
  - `academic_certificates`: "الشهادات المدرسية" / "Academic certificates" / "Distinctions scolaires"
  - `academic_certificates_hint`: "أدنى معدل للحصول على كل شهادة. يُقترح تلقائيًا عند إدخال المعدل ويمكن تغييره." / "Minimum grade for each certificate. Suggested automatically when a grade is entered; can be changed." / "Moyenne minimale pour chaque distinction. Proposée automatiquement à la saisie ; modifiable."
  - `certificate_excellence`: "شهادة الامتياز" / "Certificate of Excellence" / "Tableau d'excellence"
  - `certificate_congratulations`: "شهادة التهنئة" / "Certificate of Congratulations" / "Félicitations"
  - `certificate_encouragement`: "شهادة التشجيع" / "Certificate of Encouragement" / "Encouragements"
  - `certificate_honor_roll`: "لوحة الشرف" / "Honor Roll" / "Tableau d'honneur"
  - `no_certificate`: "بدون شهادة" / "No certificate" / "Aucune distinction"
- [ ] **Step 7:** tests pass (`--filter=CertificateThresholdsTest` and `--filter=WebsiteConfig`), `npm run build`, `npm run i18n:check`. Commit the changed files: "feat(academic): certificate thresholds editable in settings".

---

### Task 3: Grades + school years CRUD v2

**Files:** `SaveAcademicRecordRequest.php` (rework), create `UpdateAcademicYearRequest.php`, `PlayerAcademicRecordController.php` (rework), create `PlayerAcademicYearController.php`, `routes/web.php`, `PlayerController.php` (`show`), `StorePlayerRequest.php` + `UpdatePlayerRequest.php` (remove the three school rules + `EducationLevel` import if unused), i18n, tests `tests/Feature/PlayerAcademicRecordTest.php` (rewrite) + `tests/Feature/PlayerAcademicYearTest.php` (new).

**Behaviour:**
- `POST players.academic-records.store` (`/players/{player}/academic-records`): fields `academic_year` (req int 1990–2100), `period` (req in T1–T3), `gpa` (req numeric min 0, max = scale), `certificate` (nullable, in `AcademicCertificate::values()`), `remark` (nullable ≤1000), and — **required only when the player has no year `academic_year` yet** — `education_level` (in `EducationLevel::values()`), `institution`, `field_of_study` (nullable ≤255). Scale = existing year's level scale, else the submitted level's scale. Period unique within that year. In a DB transaction: `firstOrCreate` the year (only fills school info when creating), then create the record. Flash `flash.academic_record_added`.
- `PUT players.academic-records.update` (`/players/{player}/academic-records/{academicRecord}`, scoped via `Player::academicRecords()` hasManyThrough): fields `period`, `gpa` (max = record's year scale), `certificate`, `remark`; period unique within the record's year ignoring itself. The year cannot be changed. Flash `flash.academic_record_updated`.
- `DELETE players.academic-records.destroy`: delete; flash `flash.academic_record_deleted`. If the year has no trimesters left, keep the year (school info is still history).
- `PUT players.academic-years.update` (`/players/{player}/academic-years/{academicYear}`, scoped via `academicYears()`): `education_level` (req, enum), `institution`, `field_of_study`. Refuse (error on `education_level`, message key `academic_level_scale_conflict`) when any of the year's grades exceeds the new level's scale. Flash `flash.academic_year_updated`.
- `DELETE players.academic-years.destroy`: deletes the year and its trimesters. Flash `flash.academic_year_deleted`.
- All store/update (records and years) refuse non-students with error key `student` (`UiLang::get('academic_not_student')`), as v1.
- Routes inside the existing `Route::scopeBindings()->group(...)` block next to the v1 ones. Permission derivation: store→add, update→edit, destroy→delete (no config change).
- `PlayerController::show()`: when student, `$player->load(['academicYears.records'])` (years oldest first, records T1→T3), and pass a new prop `certificateThresholds` => `CertificateThresholds::all()`. Remove the v1 `academicRecords` load.

**Tests (write first, all must fail before implementing)** — `PlayerAcademicRecordTest` (rewrite, keep helpers `admin()`, `makePlayer(bool $student = true)`):
1. first grade of a new year creates the year with its school info + the record (assert both rows, flash).
2. second grade of the same year needs no school info and reuses the year (1 year row, 2 records); sending different school info does not change the year.
3. new year without `education_level` → error `education_level`.
4. /20: gpa 20.5 → error; /10 (primary year): gpa 10.5 → error, 10 ok.
5. duplicate trimester in same year → error `period` (store and update); same period in another year ok.
6. certificate must be one of the four or null; stored as sent.
7. update changes gpa/certificate/remark; delete removes the record but keeps the year.
8. worker → error `student`; another player's record → 404; viewer with only `players.view` → 403 on store.
9. profile (`players.show`) for a student carries `player.academic_years.0.records` ordered T1,T2 and `player.academic_years.0.average`, and prop `certificateThresholds.20.excellence` = 16; worker: `player.academic_years` missing.

`PlayerAcademicYearTest` (new):
1. update school info; level change primary→secondary allowed; secondary→primary refused when a grade is 12 (error `education_level`), allowed when all grades ≤ 10.
2. delete year cascades its records; flash.
3. worker → `student` error on update; another player's year → 404.

- Also remove the v1 `school_info_is_saved_from_the_player_form` test (the fields no longer exist on players) — replace with: updating a player with `education_level` in the payload does not error and nothing is stored (column gone).

**i18n (merge helper):**
- `flash.academic_year_updated`: "تم تحديث السنة الدراسية." / "School year updated." / "Année scolaire mise à jour."
- `flash.academic_year_deleted`: "تم حذف السنة الدراسية." / "School year deleted." / "Année scolaire supprimée."
- `academic_level_scale_conflict`: "لا يمكن تغيير المستوى: بعض المعدلات تتجاوز سلم التنقيط الجديد." / "Cannot change the level: some grades exceed the new scale." / "Impossible de changer le niveau : certaines moyennes dépassent le nouveau barème."

Commit: "feat(academic): record grades per school year; edit and delete school years".

---

### Task 4: List filters + dashboard v2

**Files:** `PlayerController.php` (`applyPlayerFilters`, `filters` only list), `MemberStats.php`, tests `PlayerAcademicFilterTest.php` (rewrite), `Dashboard/MemberStatsTest.php` (rewrite the two academic tests + add certificate test).

**Behaviour:**
- `academic=at_risk` → students with `(latestOn20Sql) < 10`; `good` → `>= 10`; `none` → students with no record (`whereDoesntHave('academicRecords')`).
- New `certificate=<value>` (one of `AcademicCertificate::values()`; ignore others) → students with a record carrying that certificate in the year `Season::current()->startYear`: `whereHas('academicYears', fn ($y) => $y->where('academic_year', $current)->whereHas('records', fn ($r) => $r->where('certificate', $value)))`. Add `'certificate'` to the `filters` echo list.
- `MemberStats::academic()` returns `students`, `average` (mean of latestOn20, 2 dp, null if none), `at_risk` (latestOn20 < 10), `missing`, plus `certificates` => `['excellence' => n, 'congratulations' => n, 'encouragement' => n, 'honor_roll' => n]` = count of trimester records with that certificate in the current school year among the branch-scoped active students (a student with 2 excellence trimesters counts 2 — label it "awarded"). Keep `students` counting active students.

**Tests:**
- Filter: at_risk/good/none with a /10 student (latest 4/10 → at risk; 5/10 → good boundary), a /20 student latest 9.99 → at risk and 10 → good, a worker excluded; certificate filter returns only the current-year holder (use `$this->travelTo('2026-05-14')` so the current school year is 2025; a 2024 excellence must not match).
- Dashboard: students 3, average in /20 including a primary student converted, at_risk, missing, certificates counts for current year only.

Commit: "feat(academic): /20-normalised at-risk filter, certificate filter and counts".

---

### Task 5: Academic report PDF v2

**Files:** `ReportController::academicReport`, `resources/views/pdf/academic-report.blade.php`, `tests/Feature/ReportPdfTest.php`.

- Load `academicYears.records`. Header: player identity + photo + current school (latest year's level/institution/class).
- Table, one row per year (oldest first): Year (`<bdi dir="ltr">2025/2026</bdi>`) · Level · Institution · Class · T1 · T2 · T3 (each: grade `/scale`, pass/fail colour on scale/2, certificate label under it via `UiLang::get('certificate_'.$value)`) · Year average (`/scale`, plus `UiLang::get('provisional')` when provisional). Use `<bdi dir="ltr">` around every "x / scale". Empty trimester → "—".
- Remove the v1 overall "average"/"latest" summary; add at the bottom the pass-mark note: "/10: 5 · /20: 10".
- Tests: student with a /20 year and a /10 year renders 200 PDF; worker 404; view-only role 200; a student with no years still 200.

i18n (if not already added): `provisional` "مؤقت" / "provisional" / "provisoire"; `year_average` "معدل السنة" / "Year average" / "Moyenne annuelle".

Commit: "feat(academic): report lists each school year with its trimesters and certificates".

---

### Task 6: Profile UI v2 + player form cleanup

**Files:** rewrite `resources/js/Pages/Players/Partials/AcademicSection.vue`; `resources/js/Pages/Players/Show.vue` (pass `certificateThresholds` prop); `resources/js/Pages/Players/Partials/PlayerForm.vue` (remove the three school fields, `EDUCATION_LEVELS`, and their transform/init lines); i18n.

**AcademicSection spec** (props: `player`, `certificateThresholds`):
- Header: title `academic_progress`, buttons: print report, `add_gpa` (can players.add).
- Current school line (latest year): level · institution · class.
- Stats row (only when any record): latest trimester `grade / scale` (green ≥ half, red below); current year average (latest year's `average` / scale, "provisional" tag); change vs previous year's average **in % of scale points** — show `▲ +x.xx` / `▼ x.xx` on the /20 equivalent when both years exist, else "—".
- Chart (≥ 2 years with an average): line of year averages as % of scale (0–100 y-axis, dashed line at 50), x labels "2024/2025".
- Years table, newest year first, one row per year: Year (`<bdi dir="ltr">`) · level · institution · class · T1 · T2 · T3 · average · actions. Each trimester cell: grade `x / scale` coloured pass/fail, certificate badge under it (short label, colour: excellence emerald, congratulations sky, encouragement amber, honor_roll violet), remark as `title` tooltip; clicking opens edit (can edit); empty cell shows "+" that opens add prefilled with that year+trimester (can add). Row actions: edit year (school info modal, can edit), delete year (confirm `delete_year_warning`, can delete). On narrow screens the table scrolls horizontally.
- Add/Edit GPA modal: academic year select (existing years + recent years via the v1 `yearOptions` logic; disabled when editing), and when the chosen year does not exist yet: level (required), institution, class inputs with hint `new_year_school_info`; trimester select T1–T3 (disabled options for trimesters already filled in that year when adding); grade input `max = scale` (scale from existing year or chosen level; label "GPA / 10|20" with `<bdi>`); certificate select (none + 4) auto-set from `certificateThresholds[scale]` whenever the grade changes **unless the user already picked a certificate manually in this modal session**; remark. Errors shown per field + `student`.
- Year modal: level, institution, class; errors (`education_level` shows the scale-conflict message).
- Delete trimester from the edit modal (button, confirm) — close only on success.
- All strings via `t()`; RTL chart via `locale === 'ar'`.

**i18n (merge helper; skip keys that already exist):**
- `current_school`: "المؤسسة الحالية" / "Current school" / "Établissement actuel"
- `year_average`: see Task 5 (skip if present); `provisional`: see Task 5
- `edit_year`: "تعديل السنة" / "Edit year" / "Modifier l'année"
- `delete_year`: "حذف السنة" / "Delete year" / "Supprimer l'année"
- `delete_year_warning`: "حذف هذه السنة الدراسية وكل معدلاتها نهائيًا؟" / "Delete this school year and all its grades permanently?" / "Supprimer définitivement cette année scolaire et toutes ses moyennes ?"
- `new_year_school_info`: "سنة جديدة: أدخل معلومات المؤسسة لهذه السنة." / "New year: enter this year's school info." / "Nouvelle année : saisissez l'établissement de cette année."
- `certificate`: "الشهادة" / "Certificate" / "Distinction"
- `trimester`: "الفصل" / "Trimester" / "Trimestre"
- `year_change`: "التغير عن السنة الماضية" / "Change vs last year" / "Évolution sur un an"
- Remove now-unused v1 keys only if no file references them (`grep -rn "'key'" resources/js resources/views app`): e.g. `gpa_change`, `average_gpa`.

Verify `npm run build`, `npm run i18n:check`, `php artisan test --filter="PlayerAcademic"`. Commit: "feat(academic): profile shows one line per school year with trimesters and certificates".

---

### Task 7: Certificate filter UI + dashboard counts

**Files:** `resources/js/Pages/Players/Index.vue`, `resources/js/Pages/Dashboard/Partials/MembersTab.vue`, i18n.

- Index: `certificateFilter` ref from `props.filters?.certificate`, added to `useListFilters` params as `certificate`, select after the academic select: first option `t('certificate_filter_all')`, then the four certificates (`t('certificate_<v>')`).
- MembersTab academic section: under the 4 tiles, a compact row listing the 4 certificates with counts for the current school year (title `t('dashboard.mem_certificates_year')`), each linking to `route('players.index', { certificate: v, branch_id })` via the existing `academicLink`-style helper (extend it to accept extra params). Hidden when all counts are 0.
- i18n: `certificate_filter_all`: "كل الشهادات" / "Certificates: all" / "Distinctions : toutes"; `dashboard.mem_certificates_year`: "شهادات هذه السنة الدراسية" / "Certificates this school year" / "Distinctions de l'année scolaire".
- Verify build, i18n:check, full suite `php artisan test --compact` (all green now). Commit: "feat(academic): certificate filter and dashboard certificate counts".

---

## Deployment note
`php artisan migrate` (the academic migration is new to every database). Worktree test DB: re-copy the snapshot and migrate.
