# Player Academic Tracking (Semester GPAs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Student players get school info and semester GPAs (/20) on their profile, with a trend chart, an A4 academic report PDF, an "Academic" players-list filter and a dashboard card.

**Architecture:** New `player_academic_records` table (one row per player + academic year + period) plus three nullable school columns on `players`. One SQL helper (`PlayerAcademicRecord::latestGpaSql()`) defines "latest GPA" once and is reused by the list filter, the dashboard stats and nowhere else; the profile and PDF compute latest/average/delta from the chronologically ordered collection. Routes nest under `players.` so the existing route-name permission map gates them.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), vue-i18n (flat keys in `resources/js/i18n/{ar,en,fr}.json`), chart.js + vue-chartjs, mPDF via `App\Services\Pdf\PdfService`, PHPUnit feature tests with `#[Test]`, SQLite (tests: in-memory; desktop: file) — every raw SQL fragment must work on SQLite **and** MySQL.

**Spec:** `docs/superpowers/specs/2026-09-23-player-academic-gpa-design.md`

## Global Constraints

- Grade scale fixed /20; pass mark 10 (`gpa >= 10` passes).
- Periods: `S1`, `S2`, `T1`, `T2`, `T3`, `ANNUAL`. Chronological rank `T1=1, S1=2, T2=3, S2=4, T3=5, ANNUAL=6`.
- Education levels: `primary`, `middle`, `secondary`, `vocational`, `licence`, `master`, `doctorate`.
- `academic_year` is the start year (2025 means "2025/2026"), integer 1990–2100.
- Unique `(player_id, academic_year, period)`.
- Section hidden entirely when `is_student = false`; records are never deleted by switching status.
- Permissions: reuse `players` module. `players.academic-report` → `['players', 'view']` override.
- Every user-visible string goes into all three catalogs `resources/js/i18n/ar.json`, `en.json`, `fr.json` (flat keys, 4-space indent). `npm run i18n:check` must pass.
- Git: the working tree may contain unrelated uncommitted work. **Stage explicit file paths only — never `git add -A` / `git add .`.**
- Commit messages end with: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- Work on branch `feat/player-academic-gpa` (already created, spec committed).

## File Map

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_23_110000_add_academic_tracking.php` | school columns + records table |
| `app/Enums/AcademicPeriod.php` | period cases, rank, SQL rank expression |
| `app/Enums/EducationLevel.php` | level cases |
| `app/Models/PlayerAcademicRecord.php` | model, `chronological` scope, `latestGpaSql()` |
| `app/Models/Player.php` | fillable school fields, `academicRecords()` |
| `app/Http/Requests/Player/SaveAcademicRecordRequest.php` | record validation (store + update) |
| `app/Http/Controllers/PlayerAcademicRecordController.php` | store/update/destroy |
| `app/Http/Controllers/PlayerController.php` | show props, `academic` list filter |
| `app/Http/Requests/Player/StorePlayerRequest.php`, `UpdatePlayerRequest.php` | school field rules |
| `app/Services/Dashboard/MemberStats.php` | `academic` block |
| `app/Http/Controllers/ReportController.php` + `resources/views/pdf/academic-report.blade.php` | A4 PDF |
| `routes/web.php`, `config/permissions.php` | routes + PDF permission override |
| `resources/js/Pages/Players/Partials/AcademicSection.vue` | profile section (stats, chart, table, modal) |
| `resources/js/Pages/Players/Show.vue` | mounts section |
| `resources/js/Pages/Players/Partials/PlayerForm.vue` | school fields |
| `resources/js/Pages/Players/Index.vue` | Academic filter |
| `resources/js/Pages/Dashboard/Partials/MembersTab.vue` | academic card |
| `resources/js/i18n/{ar,en,fr}.json` | labels |
| `tests/Feature/PlayerAcademicRecordTest.php`, `tests/Feature/PlayerAcademicFilterTest.php`, `tests/Feature/Dashboard/MemberStatsTest.php`, `tests/Feature/ReportPdfTest.php` | tests |

---

### Task 1: Schema, enums, model, ordering

**Files:**
- Create: `database/migrations/2026_09_23_110000_add_academic_tracking.php`
- Create: `app/Enums/AcademicPeriod.php`, `app/Enums/EducationLevel.php`
- Create: `app/Models/PlayerAcademicRecord.php`
- Modify: `app/Models/Player.php` (fillable list lines 19-52, relations near `achievements()`)
- Test: `tests/Feature/PlayerAcademicRecordTest.php`

**Interfaces:**
- Produces:
  - `AcademicPeriod` (string-backed enum) with `rank(): int`, `static values(): array<string>`, `static rankSql(string $column): string`
  - `EducationLevel` (string-backed enum) with `static values(): array<string>`
  - `PlayerAcademicRecord` fillable `player_id, academic_year, period, gpa, remark`; casts `academic_year` int, `gpa` `decimal:2`; scope `chronological()`; `static latestGpaSql(string $playerIdColumn = 'players.id'): string`; `player()` belongsTo
  - `Player::academicRecords(): HasMany`; `Player` fillable gains `education_level, institution, field_of_study`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerAcademicRecordTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicRecordTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makePlayer(bool $student = true): Player
    {
        return Player::create([
            'membership_id' => '8'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'S'.$this->seq,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);
    }

    private function record(Player $player, int $year, string $period, float $gpa): PlayerAcademicRecord
    {
        return $player->academicRecords()->create([
            'academic_year' => $year,
            'period' => $period,
            'gpa' => $gpa,
        ]);
    }

    #[Test]
    public function records_order_chronologically_with_annual_last_in_a_year(): void
    {
        $player = $this->makePlayer();
        $this->record($player, 2025, 'ANNUAL', 13);
        $this->record($player, 2024, 'S2', 11);
        $this->record($player, 2025, 'S1', 12);
        $this->record($player, 2024, 'S1', 9.5);

        $order = $player->academicRecords()->chronological()->get()
            ->map(fn ($r) => $r->academic_year.'-'.$r->period)->all();

        $this->assertSame(['2024-S1', '2024-S2', '2025-S1', '2025-ANNUAL'], $order);
    }

    #[Test]
    public function latest_gpa_sql_picks_the_last_record_in_chronological_order(): void
    {
        $a = $this->makePlayer();
        $this->record($a, 2024, 'S2', 15);
        $this->record($a, 2025, 'T1', 8.25);   // later year wins over higher rank
        $b = $this->makePlayer();
        $this->record($b, 2025, 'S2', 9);
        $this->record($b, 2025, 'ANNUAL', 10.5); // ANNUAL after S2
        $c = $this->makePlayer();                 // no records

        $latest = DB::table('players')
            ->selectRaw('players.id, ('.PlayerAcademicRecord::latestGpaSql().') as latest_gpa')
            ->pluck('latest_gpa', 'id');

        $this->assertEquals(8.25, (float) $latest[$a->id]);
        $this->assertEquals(10.5, (float) $latest[$b->id]);
        $this->assertNull($latest[$c->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerAcademicRecordTest`
Expected: FAIL — `Class "App\Models\PlayerAcademicRecord" not found`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_09_23_110000_add_academic_tracking.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('education_level', 20)->nullable()->after('is_student');
            $table->string('institution')->nullable()->after('education_level');
            $table->string('field_of_study')->nullable()->after('institution');
        });

        Schema::create('player_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Start year of the school year: 2025 means "2025/2026".
            $table->unsignedSmallInteger('academic_year');
            $table->string('period', 10);
            $table->decimal('gpa', 4, 2);
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'academic_year', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_academic_records');

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['education_level', 'institution', 'field_of_study']);
        });
    }
};
```

- [ ] **Step 4: Write the enums**

`app/Enums/AcademicPeriod.php`:

```php
<?php

namespace App\Enums;

/**
 * A grading period inside a school year. Universities grade per semester,
 * schools per trimester, and either may also publish a yearly average.
 */
enum AcademicPeriod: string
{
    case T1 = 'T1';
    case S1 = 'S1';
    case T2 = 'T2';
    case S2 = 'S2';
    case T3 = 'T3';
    case Annual = 'ANNUAL';

    /** Position inside one school year; the yearly average always comes last. */
    public function rank(): int
    {
        return match ($this) {
            self::T1 => 1,
            self::S1 => 2,
            self::T2 => 3,
            self::S2 => 4,
            self::T3 => 5,
            self::Annual => 6,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The same rank as a SQL CASE over $column, for ORDER BY in raw queries. */
    public static function rankSql(string $column): string
    {
        $whens = collect(self::cases())
            ->map(fn (self $p) => "WHEN '{$p->value}' THEN {$p->rank()}")
            ->implode(' ');

        return "CASE {$column} {$whens} ELSE 0 END";
    }
}
```

`app/Enums/EducationLevel.php`:

```php
<?php

namespace App\Enums;

enum EducationLevel: string
{
    case Primary = 'primary';
    case Middle = 'middle';
    case Secondary = 'secondary';
    case Vocational = 'vocational';
    case Licence = 'licence';
    case Master = 'master';
    case Doctorate = 'doctorate';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 5: Write the model**

`app/Models/PlayerAcademicRecord.php`:

```php
<?php

namespace App\Models;

use App\Enums\AcademicPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One graded period (semester, trimester or yearly average) of a student player, out of 20. */
class PlayerAcademicRecord extends Model
{
    public const PASS_MARK = 10;

    protected $fillable = [
        'player_id',
        'academic_year',
        'period',
        'gpa',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'academic_year' => 'integer',
            'gpa' => 'decimal:2',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Oldest first: by school year, then by period rank (the yearly average last). */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('academic_year')
            ->orderByRaw(AcademicPeriod::rankSql('period'))
            ->orderBy('id');
    }

    /**
     * Correlated subquery yielding a player's most recent GPA (NULL when none).
     * The one definition of "latest" for list filters and dashboard stats.
     */
    public static function latestGpaSql(string $playerIdColumn = 'players.id'): string
    {
        $rank = AcademicPeriod::rankSql('par.period');

        return 'SELECT par.gpa FROM player_academic_records par'
            ." WHERE par.player_id = {$playerIdColumn}"
            ." ORDER BY par.academic_year DESC, {$rank} DESC, par.id DESC LIMIT 1";
    }
}
```

- [ ] **Step 6: Wire the Player model**

In `app/Models/Player.php` add to `$fillable` right after `'is_student',`:

```php
        'is_student',
        'education_level',
        'institution',
        'field_of_study',
```

Add after `achievements()`:

```php
    public function academicRecords(): HasMany
    {
        return $this->hasMany(PlayerAcademicRecord::class);
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=PlayerAcademicRecordTest`
Expected: 2 passed.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_23_110000_add_academic_tracking.php app/Enums/AcademicPeriod.php app/Enums/EducationLevel.php app/Models/PlayerAcademicRecord.php app/Models/Player.php tests/Feature/PlayerAcademicRecordTest.php
git commit -m "feat(academic): academic records table, periods and latest-GPA query

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Record CRUD endpoints

**Files:**
- Create: `app/Http/Requests/Player/SaveAcademicRecordRequest.php`
- Create: `app/Http/Controllers/PlayerAcademicRecordController.php`
- Modify: `routes/web.php` (after the player subscription routes, ~line 106)
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json` (flash + validation keys)
- Test: `tests/Feature/PlayerAcademicRecordTest.php` (append)

**Interfaces:**
- Consumes: `PlayerAcademicRecord`, `Player::academicRecords()`, `AcademicPeriod::values()` (Task 1)
- Produces: routes `players.academic-records.store` (POST `/players/{player}/academic-records`), `players.academic-records.update` (PUT `/players/{player}/academic-records/{academicRecord}`), `players.academic-records.destroy` (DELETE same). Request fields: `academic_year`, `period`, `gpa`, `remark`. Non-student error key: `student`. Flash keys `flash.academic_record_added|updated|deleted`.

- [ ] **Step 1: Write the failing tests** (append inside `PlayerAcademicRecordTest`)

```php
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'academic_year' => 2025,
            'period' => 'S1',
            'gpa' => 12.5,
            'remark' => 'Good start',
        ], $overrides);
    }

    #[Test]
    public function admin_adds_updates_and_deletes_a_record(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.academic_record_added');

        $record = PlayerAcademicRecord::firstOrFail();
        $this->assertSame(2025, $record->academic_year);
        $this->assertSame('S1', $record->period);
        $this->assertEquals(12.5, (float) $record->gpa);

        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $record]), $this->payload(['gpa' => 14, 'remark' => null]))
            ->assertSessionHas('success', 'flash.academic_record_updated');
        $this->assertEquals(14.0, (float) $record->fresh()->gpa);
        $this->assertNull($record->fresh()->remark);

        $this->actingAs($admin)
            ->delete(route('players.academic-records.destroy', [$player, $record]))
            ->assertSessionHas('success', 'flash.academic_record_deleted');
        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function gpa_must_be_between_0_and_20_and_period_known(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $player), $this->payload(['gpa' => 20.5, 'period' => 'S9']))
            ->assertSessionHasErrors(['gpa', 'period']);

        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function the_same_year_and_period_cannot_be_recorded_twice(): void
    {
        $player = $this->makePlayer();
        $admin = $this->admin();
        $this->record($player, 2025, 'S1', 11);
        $other = $this->record($player, 2025, 'S2', 12);

        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $player), $this->payload())
            ->assertSessionHasErrors('period');

        // Updating a record onto another record's slot is also refused…
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $other]), $this->payload())
            ->assertSessionHasErrors('period');

        // …but saving a record onto its own slot is fine.
        $this->actingAs($admin)
            ->put(route('players.academic-records.update', [$player, $other]), $this->payload(['period' => 'S2']))
            ->assertSessionHasNoErrors();

        // Another player may use the same slot.
        $this->actingAs($admin)
            ->post(route('players.academic-records.store', $this->makePlayer()), $this->payload())
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function records_cannot_be_added_to_a_worker(): void
    {
        $worker = $this->makePlayer(student: false);

        $this->actingAs($this->admin())
            ->post(route('players.academic-records.store', $worker), $this->payload())
            ->assertSessionHasErrors('student');

        $this->assertDatabaseCount('player_academic_records', 0);
    }

    #[Test]
    public function a_record_of_another_player_is_not_found(): void
    {
        $owner = $this->makePlayer();
        $record = $this->record($owner, 2025, 'S1', 11);
        $stranger = $this->makePlayer();

        $this->actingAs($this->admin())
            ->delete(route('players.academic-records.destroy', [$stranger, $record]))
            ->assertNotFound();

        $this->assertDatabaseCount('player_academic_records', 1);
    }

    #[Test]
    public function adding_a_record_needs_players_add(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'approved' => true,
            'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)
            ->post(route('players.academic-records.store', $this->makePlayer()), $this->payload())
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PlayerAcademicRecordTest`
Expected: new tests FAIL — `Route [players.academic-records.store] not defined.`

- [ ] **Step 3: Write the form request**

`app/Http/Requests/Player/SaveAcademicRecordRequest.php`:

```php
<?php

namespace App\Http\Requests\Player;

use App\Enums\AcademicPeriod;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Support\UiLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Store and update share one shape: a GPA out of 20 for one year + period. */
class SaveAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route-name `permission` middleware
    }

    public function rules(): array
    {
        /** @var Player $player */
        $player = $this->route('player');
        /** @var PlayerAcademicRecord|null $record */
        $record = $this->route('academicRecord');

        return [
            'academic_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'period' => [
                'required',
                Rule::in(AcademicPeriod::values()),
                Rule::unique('player_academic_records', 'period')
                    ->where('player_id', $player->id)
                    ->where('academic_year', (int) $this->input('academic_year'))
                    ->ignore($record?->id),
            ],
            'gpa' => ['required', 'numeric', 'min:0', 'max:20'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->route('player')->is_student) {
                $validator->errors()->add('student', UiLang::get('academic_not_student'));
            }
        });
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/PlayerAcademicRecordController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\SaveAcademicRecordRequest;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use Illuminate\Http\RedirectResponse;

class PlayerAcademicRecordController extends Controller
{
    public function store(SaveAcademicRecordRequest $request, Player $player): RedirectResponse
    {
        $player->academicRecords()->create($request->validated());

        return back()->with('success', 'flash.academic_record_added');
    }

    // {academicRecord} is scope-bound to {player} in routes/web.php, so a
    // record of another player 404s before reaching here.
    public function update(SaveAcademicRecordRequest $request, Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->update($request->validated());

        return back()->with('success', 'flash.academic_record_updated');
    }

    public function destroy(Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->delete();

        return back()->with('success', 'flash.academic_record_deleted');
    }
}
```

Note: `validated()` omits `remark` only when absent; the Vue form always sends it (possibly `null`), so clearing works.

- [ ] **Step 5: Add routes**

In `routes/web.php` add the import `use App\Http\Controllers\PlayerAcademicRecordController;` with the other controller imports, then after the `players.subscriptions.destroy` route:

```php
    // Student players' graded periods (semester / trimester / yearly GPA out of 20)
    Route::scopeBindings()->group(function () {
        Route::post('/players/{player}/academic-records', [PlayerAcademicRecordController::class, 'store'])->name('players.academic-records.store');
        Route::put('/players/{player}/academic-records/{academicRecord}', [PlayerAcademicRecordController::class, 'update'])->name('players.academic-records.update');
        Route::delete('/players/{player}/academic-records/{academicRecord}', [PlayerAcademicRecordController::class, 'destroy'])->name('players.academic-records.destroy');
    });
```

(`scopeBindings` resolves `{academicRecord}` through `$player->academicRecords()`.)

- [ ] **Step 6: Add translations**

Create a reusable merge helper in the scratchpad (not committed), e.g. `$SCRATCH/i18n-merge.mjs`:

```js
// Usage: node i18n-merge.mjs keys.json   (keys.json = { "ar": {...}, "en": {...}, "fr": {...} })
import { readFileSync, writeFileSync } from 'node:fs';
const add = JSON.parse(readFileSync(process.argv[2], 'utf8'));
for (const locale of ['ar', 'en', 'fr']) {
  const path = `resources/js/i18n/${locale}.json`;
  const raw = readFileSync(path, 'utf8').replace(/^﻿/, '');
  const data = JSON.parse(raw);
  for (const [k, v] of Object.entries(add[locale])) {
    if (k in data) throw new Error(`${locale}: key exists: ${k}`);
    data[k] = v;
  }
  writeFileSync(path, JSON.stringify(data, null, 4) + (raw.endsWith('\n') ? '\n' : ''));
}
```

Run from repo root with this `keys.json`:

```json
{
  "ar": {
    "flash.academic_record_added": "تمت إضافة المعدل.",
    "flash.academic_record_updated": "تم تحديث المعدل.",
    "flash.academic_record_deleted": "تم حذف المعدل.",
    "academic_not_student": "هذا العضو ليس طالبًا."
  },
  "en": {
    "flash.academic_record_added": "GPA added.",
    "flash.academic_record_updated": "GPA updated.",
    "flash.academic_record_deleted": "GPA deleted.",
    "academic_not_student": "This member is not a student."
  },
  "fr": {
    "flash.academic_record_added": "Moyenne ajoutée.",
    "flash.academic_record_updated": "Moyenne mise à jour.",
    "flash.academic_record_deleted": "Moyenne supprimée.",
    "academic_not_student": "Ce membre n'est pas étudiant."
  }
}
```

Then check the diff touches only the added lines: `git diff --stat resources/js/i18n` → 3 files, ~4 insertions each (plus possibly 1 changed last line for the comma). If far more lines changed, the file had different formatting — revert (`git checkout -- resources/js/i18n/<file>`) and add the keys by hand instead.

- [ ] **Step 7: Run tests + i18n check**

Run: `php artisan test --filter=PlayerAcademicRecordTest` → all pass.
Run: `npm run i18n:check` → `✓ all … flash keys … resolve`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Player/SaveAcademicRecordRequest.php app/Http/Controllers/PlayerAcademicRecordController.php routes/web.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/PlayerAcademicRecordTest.php
git commit -m "feat(academic): add, edit and delete a student's GPA records

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: School fields on the player + profile props

**Files:**
- Modify: `app/Http/Requests/Player/StorePlayerRequest.php` and `UpdatePlayerRequest.php` (after `'is_student'` rule, line 38)
- Modify: `app/Http/Controllers/PlayerController.php` `show()` (lines 135-179)
- Test: `tests/Feature/PlayerAcademicRecordTest.php` (append)

**Interfaces:**
- Consumes: `EducationLevel::values()`, `PlayerAcademicRecord::scopeChronological` (Task 1)
- Produces: Inertia `Players/Show` prop `player.academic_records` (array, chronological, only present when student) and `player.education_level|institution|field_of_study`.

- [ ] **Step 1: Write the failing tests** (append; add `use Inertia\Testing\AssertableInertia as Assert;` to imports)

```php
    #[Test]
    public function profile_shows_records_in_order_for_students_only(): void
    {
        $student = $this->makePlayer();
        $this->record($student, 2025, 'S2', 13);
        $this->record($student, 2025, 'S1', 11);
        $worker = $this->makePlayer(student: false);
        $this->record($worker, 2025, 'S1', 11); // kept from when they studied

        $this->actingAs($this->admin())->get(route('players.show', $student))
            ->assertInertia(fn (Assert $page) => $page
                ->where('player.academic_records.0.period', 'S1')
                ->where('player.academic_records.1.period', 'S2'));

        $this->actingAs($this->admin())->get(route('players.show', $worker))
            ->assertInertia(fn (Assert $page) => $page->missing('player.academic_records'));
    }

    #[Test]
    public function school_info_is_saved_from_the_player_form(): void
    {
        $player = $this->makePlayer();

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'is_student' => true,
            'education_level' => 'licence',
            'institution' => 'Université de Béjaïa',
            'field_of_study' => 'L2 Informatique',
        ])->assertSessionHasNoErrors();

        $player->refresh();
        $this->assertSame('licence', $player->education_level);
        $this->assertSame('Université de Béjaïa', $player->institution);
        $this->assertSame('L2 Informatique', $player->field_of_study);

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname,
            'lastname' => $player->lastname,
            'education_level' => 'kindergarten',
        ])->assertSessionHasErrors('education_level');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PlayerAcademicRecordTest`
Expected: the two new tests FAIL (`academic_records` missing; `education_level` not saved — `validated()` drops unknown keys).

If `school_info_is_saved_from_the_player_form` fails for another required field (e.g. `gender`), add that field to the payload with a valid value — the point of the test is the three school fields.

- [ ] **Step 3: Add validation rules**

In both `StorePlayerRequest::rules()` and `UpdatePlayerRequest::rules()`, after the `'is_student'` line (add `use App\Enums\EducationLevel;` at top; `Rule` is already imported in both — verify, and import `Illuminate\Validation\Rule` if not):

```php
            'is_student' => ['nullable', 'boolean'],
            'education_level' => ['nullable', Rule::in(EducationLevel::values())],
            'institution' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 4: Load records in `show()`**

In `PlayerController::show()`, after the `$player->load([...]);` call:

```php
        // Only students carry an education section; a worker's past records
        // stay stored but are not sent, so the page has nothing to show.
        if ($player->is_student) {
            $player->load(['academicRecords' => fn ($query) => $query->chronological()]);
        }
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=PlayerAcademicRecordTest` → all pass.
Run: `php artisan test --filter=Player` → no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerController.php tests/Feature/PlayerAcademicRecordTest.php
git commit -m "feat(academic): school info on players, GPA history on the profile

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Players list "Academic" filter

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php` — `applyPlayerFilters()` (~line 581) and `filters` prop list in `index()` (~line 131)
- Test: `tests/Feature/PlayerAcademicFilterTest.php`

**Interfaces:**
- Consumes: `PlayerAcademicRecord::latestGpaSql()`, `PlayerAcademicRecord::PASS_MARK`
- Produces: query param `academic` ∈ `at_risk | good | none`, echoed back in `filters.academic`; applies to index and export.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PlayerAcademicFilterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerAcademicFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function player(string $name, bool $student = true, array $gpas = []): Player
    {
        $player = Player::create([
            'membership_id' => '7'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $name,
            'lastname' => 'Test',
            'is_student' => $student,
            'outstanding_debt' => 0,
        ]);

        foreach ($gpas as [$year, $period, $gpa]) {
            $player->academicRecords()->create(['academic_year' => $year, 'period' => $period, 'gpa' => $gpa]);
        }

        return $player;
    }

    /** @return array<int, string> firstnames the list returns for ?academic=$bucket */
    private function listed(string $bucket): array
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $names = [];

        $this->actingAs($admin)->get(route('players.index', ['academic' => $bucket]))
            ->assertInertia(function (Assert $page) use (&$names, $bucket) {
                $names = collect($page->toArray()['props']['players']['data'])->pluck('firstname')->sort()->values()->all();
                $page->where('filters.academic', $bucket);
            });

        return $names;
    }

    #[Test]
    public function buckets_use_the_latest_gpa_of_students_only(): void
    {
        $this->player('Recovered', gpas: [[2024, 'S1', 8], [2024, 'S2', 12]]);   // latest 12 → good
        $this->player('Slipping', gpas: [[2024, 'S2', 15], [2025, 'S1', 9.99]]); // latest 9.99 → at risk
        $this->player('Borderline', gpas: [[2025, 'ANNUAL', 10]]);               // exactly 10 → good
        $this->player('Blank');                                                   // student, no GPA
        $this->player('Worker', student: false, gpas: [[2025, 'S1', 5]]);       // never listed

        $this->assertSame(['Slipping'], $this->listed('at_risk'));
        $this->assertSame(['Borderline', 'Recovered'], $this->listed('good'));
        $this->assertSame(['Blank'], $this->listed('none'));
    }
}
```

Note: if the index `players` prop is not paginated as `players.data`, inspect `index()` and adjust the path — check with `grep -n "'players' =>" app/Http/Controllers/PlayerController.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerAcademicFilterTest`
Expected: FAIL — all five players listed for each bucket.

- [ ] **Step 3: Implement the filter**

In `PlayerController::applyPlayerFilters()`, after the `wilaya_id` block (add `use App\Models\PlayerAcademicRecord;` at top):

```php
        if ($request->filled('academic')) {
            $latest = '('.PlayerAcademicRecord::latestGpaSql().')';
            $pass = PlayerAcademicRecord::PASS_MARK;

            match ($request->input('academic')) {
                'at_risk' => $query->where('is_student', true)->whereRaw("{$latest} < ?", [$pass]),
                'good' => $query->where('is_student', true)->whereRaw("{$latest} >= ?", [$pass]),
                'none' => $query->where('is_student', true)->whereDoesntHave('academicRecords'),
                default => null,
            };
        }
```

In `index()`, add `'academic'` to the `filters` list:

```php
            'filters' => $request->only(['search', 'category_id', 'status', 'position_id', 'branch_id', 'age', 'archived', 'wilaya_id', 'academic']),
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=PlayerAcademicFilterTest` → PASS.
Run: `php artisan test --filter=Player` → no regressions.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PlayerController.php tests/Feature/PlayerAcademicFilterTest.php
git commit -m "feat(academic): filter players by latest GPA (at risk, good, none)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Dashboard academic stats

**Files:**
- Modify: `app/Services/Dashboard/MemberStats.php` (`get()` line 36-48, new private method before `active()`)
- Test: `tests/Feature/Dashboard/MemberStatsTest.php` (append)

**Interfaces:**
- Consumes: `PlayerAcademicRecord::latestGpaSql()`, `PASS_MARK`
- Produces: `members.academic` = `{ students: int, average: ?float (2 dp), at_risk: int, missing: int }`, respects branch filter via `active()`.

- [ ] **Step 1: Write the failing test** (append in `MemberStatsTest`)

```php
    #[Test]
    public function academic_block_averages_each_students_latest_gpa(): void
    {
        $a = $this->player(['is_student' => true]);
        $a->academicRecords()->create(['academic_year' => 2024, 'period' => 'S1', 'gpa' => 6]);
        $a->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 14]); // latest 14
        $b = $this->player(['is_student' => true]);
        $b->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 9]);  // at risk
        $this->player(['is_student' => true]);                                                     // missing
        $w = $this->player(['is_student' => false]);
        $w->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 2]);  // ignored

        $this->assertSame(
            ['students' => 3, 'average' => 11.5, 'at_risk' => 1, 'missing' => 1],
            $this->members()['academic'],
        );
    }

    #[Test]
    public function academic_average_is_null_without_any_gpa(): void
    {
        $this->player(['is_student' => true]);

        $this->assertNull($this->members()['academic']['average']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=MemberStatsTest`
Expected: FAIL — `Undefined array key "academic"`.

- [ ] **Step 3: Implement**

In `MemberStats::get()` add `'academic' => $this->academic($filters),` after `'split'`. Add `use App\Models\PlayerAcademicRecord;`. Add method before `active()`:

```php
    /** How the club's students are doing at school, judged on each one's latest GPA. */
    private function academic(DashboardFilters $filters): array
    {
        $latest = $this->active($filters)
            ->where('players.is_student', true)
            ->toBase()
            ->selectRaw('('.PlayerAcademicRecord::latestGpaSql().') as latest_gpa')
            ->pluck('latest_gpa');

        $graded = $latest->filter(fn ($gpa) => $gpa !== null)->map(fn ($gpa) => (float) $gpa);

        return [
            'students' => $latest->count(),
            'average' => $graded->isEmpty() ? null : round($graded->avg(), 2),
            'at_risk' => $graded->filter(fn (float $gpa) => $gpa < PlayerAcademicRecord::PASS_MARK)->count(),
            'missing' => $latest->count() - $graded->count(),
        ];
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Dashboard` → all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/MemberStats.php tests/Feature/Dashboard/MemberStatsTest.php
git commit -m "feat(academic): dashboard stats for students' latest GPAs

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: UI translation keys

**Files:**
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json`

**Interfaces:**
- Produces (used by Tasks 7-10, and by the PDF in Task 7 via `UiLang::get`): the keys below, exactly.

- [ ] **Step 1: Merge keys** with the Task 2 helper (`node $SCRATCH/i18n-merge.mjs keys.json`) using:

```json
{
  "ar": {
    "academic_progress": "المسار الدراسي",
    "education_level": "المستوى الدراسي",
    "education_level_primary": "ابتدائي",
    "education_level_middle": "متوسط",
    "education_level_secondary": "ثانوي",
    "education_level_vocational": "تكوين مهني",
    "education_level_licence": "ليسانس",
    "education_level_master": "ماستر",
    "education_level_doctorate": "دكتوراه",
    "institution": "المؤسسة",
    "field_of_study": "التخصص / القسم",
    "academic_year": "السنة الدراسية",
    "period": "الفترة",
    "period_S1": "السداسي 1",
    "period_S2": "السداسي 2",
    "period_T1": "الفصل 1",
    "period_T2": "الفصل 2",
    "period_T3": "الفصل 3",
    "period_ANNUAL": "المعدل السنوي",
    "gpa": "المعدل",
    "remark": "ملاحظة",
    "latest_gpa": "آخر معدل",
    "average_gpa": "المعدل العام",
    "gpa_change": "التغير",
    "add_gpa": "إضافة معدل",
    "edit_gpa": "تعديل المعدل",
    "delete_gpa_warning": "حذف هذا المعدل نهائيًا؟",
    "no_gpa_records": "لا توجد معدلات مسجلة.",
    "gpa_pass": "ناجح",
    "gpa_fail": "راسب",
    "pass_mark": "عتبة النجاح",
    "print_academic_report": "طباعة كشف النتائج",
    "academic_report": "كشف النتائج الدراسية",
    "academic_all": "كل الطلبة",
    "academic_at_risk": "في خطر (أقل من 10)",
    "academic_good": "جيد (10 فما فوق)",
    "academic_none": "بدون معدل",
    "dashboard.mem_academic": "النتائج الدراسية",
    "dashboard.mem_academic_average": "متوسط آخر المعدلات",
    "dashboard.mem_at_risk": "طلبة في خطر",
    "dashboard.mem_missing_gpa": "بدون معدل"
  },
  "en": {
    "academic_progress": "Academic progress",
    "education_level": "Education level",
    "education_level_primary": "Primary school",
    "education_level_middle": "Middle school",
    "education_level_secondary": "High school",
    "education_level_vocational": "Vocational training",
    "education_level_licence": "Bachelor (Licence)",
    "education_level_master": "Master",
    "education_level_doctorate": "Doctorate",
    "institution": "Institution",
    "field_of_study": "Field / class",
    "academic_year": "Academic year",
    "period": "Period",
    "period_S1": "Semester 1",
    "period_S2": "Semester 2",
    "period_T1": "Term 1",
    "period_T2": "Term 2",
    "period_T3": "Term 3",
    "period_ANNUAL": "Yearly average",
    "gpa": "GPA",
    "remark": "Remark",
    "latest_gpa": "Latest GPA",
    "average_gpa": "Overall average",
    "gpa_change": "Change",
    "add_gpa": "Add GPA",
    "edit_gpa": "Edit GPA",
    "delete_gpa_warning": "Delete this GPA permanently?",
    "no_gpa_records": "No GPA recorded yet.",
    "gpa_pass": "Pass",
    "gpa_fail": "Fail",
    "pass_mark": "Pass mark",
    "print_academic_report": "Print academic report",
    "academic_report": "Academic report",
    "academic_all": "All students",
    "academic_at_risk": "At risk (below 10)",
    "academic_good": "Good (10 and above)",
    "academic_none": "No GPA",
    "dashboard.mem_academic": "Academic results",
    "dashboard.mem_academic_average": "Average latest GPA",
    "dashboard.mem_at_risk": "Students at risk",
    "dashboard.mem_missing_gpa": "No GPA"
  },
  "fr": {
    "academic_progress": "Parcours scolaire",
    "education_level": "Niveau d'études",
    "education_level_primary": "Primaire",
    "education_level_middle": "Moyen (CEM)",
    "education_level_secondary": "Secondaire (Lycée)",
    "education_level_vocational": "Formation professionnelle",
    "education_level_licence": "Licence",
    "education_level_master": "Master",
    "education_level_doctorate": "Doctorat",
    "institution": "Établissement",
    "field_of_study": "Filière / classe",
    "academic_year": "Année scolaire",
    "period": "Période",
    "period_S1": "Semestre 1",
    "period_S2": "Semestre 2",
    "period_T1": "Trimestre 1",
    "period_T2": "Trimestre 2",
    "period_T3": "Trimestre 3",
    "period_ANNUAL": "Moyenne annuelle",
    "gpa": "Moyenne",
    "remark": "Remarque",
    "latest_gpa": "Dernière moyenne",
    "average_gpa": "Moyenne générale",
    "gpa_change": "Évolution",
    "add_gpa": "Ajouter une moyenne",
    "edit_gpa": "Modifier la moyenne",
    "delete_gpa_warning": "Supprimer définitivement cette moyenne ?",
    "no_gpa_records": "Aucune moyenne enregistrée.",
    "gpa_pass": "Admis",
    "gpa_fail": "Ajourné",
    "pass_mark": "Seuil de réussite",
    "print_academic_report": "Imprimer le relevé de notes",
    "academic_report": "Relevé de notes",
    "academic_all": "Tous les étudiants",
    "academic_at_risk": "En difficulté (moins de 10)",
    "academic_good": "Bon (10 et plus)",
    "academic_none": "Sans moyenne",
    "dashboard.mem_academic": "Résultats scolaires",
    "dashboard.mem_academic_average": "Moyenne des dernières moyennes",
    "dashboard.mem_at_risk": "Étudiants en difficulté",
    "dashboard.mem_missing_gpa": "Sans moyenne"
  }
}
```

- [ ] **Step 2: Verify**

Run: `git diff --stat resources/js/i18n` → only additions (~41 per file).
Run: `npm run i18n:check` → passes.
Run: `php artisan test --filter=Translation` → passes (if such tests exist; `FlashTranslationTest` checks catalogs).

- [ ] **Step 3: Commit**

```bash
git add resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(academic): ar/fr/en labels for academic tracking

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Academic report PDF

**Files:**
- Create: `resources/views/pdf/academic-report.blade.php`
- Modify: `app/Http/Controllers/ReportController.php` (new method after `playerCard()`)
- Modify: `routes/web.php` (PDF documents block, after `players.label` ~line 126)
- Modify: `config/permissions.php` (overrides, next to `players.card`)
- Test: `tests/Feature/ReportPdfTest.php` (append)

**Interfaces:**
- Consumes: `Player::academicRecords()`, `chronological()`, `PASS_MARK`, `EducationLevel`, UI keys from Task 6
- Produces: route `players.academic-report` GET `/players/{player}/academic-report` → `application/pdf`; 404 for workers; needs `players.view`.

- [ ] **Step 1: Write the failing tests** (append in `ReportPdfTest`; reuse its `admin()` helper; add imports `App\Models\Role`, `App\Models\User` if missing)

```php
    #[Test]
    public function it_generates_an_academic_report_pdf_for_a_student(): void
    {
        $player = Player::create([
            'membership_id' => '2024000077',
            'firstname' => 'Amel',
            'lastname' => 'Haddad',
            'is_student' => true,
            'education_level' => 'licence',
            'institution' => 'Université de Béjaïa',
        ]);
        $player->academicRecords()->create(['academic_year' => 2025, 'period' => 'S1', 'gpa' => 12.75]);

        $response = $this->actingAs($this->admin())->get(route('players.academic-report', $player));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function academic_report_is_not_found_for_a_worker(): void
    {
        $player = Player::create(['membership_id' => '2024000078', 'firstname' => 'W', 'lastname' => 'K', 'is_student' => false]);

        $this->actingAs($this->admin())->get(route('players.academic-report', $player))->assertNotFound();
    }

    #[Test]
    public function academic_report_needs_only_players_view(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);
        $player = Player::create(['membership_id' => '2024000079', 'firstname' => 'V', 'lastname' => 'K', 'is_student' => true]);

        $this->actingAs($viewer)->get(route('players.academic-report', $player))->assertOk();
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=ReportPdfTest`
Expected: FAIL — `Route [players.academic-report] not defined.`

- [ ] **Step 3: Controller method**

In `ReportController` (add `use App\Models\PlayerAcademicRecord;`):

```php
    public function academicReport(Player $player): Response
    {
        abort_unless($player->is_student, 404);

        $player->load(['category', 'academicRecords' => fn ($query) => $query->chronological()]);
        $records = $player->academicRecords;

        $html = view('pdf.academic-report', [
            'club' => $this->club(),
            'player' => $player,
            'photo' => $this->resolveMediaFile($player->picture_url),
            'records' => $records,
            'average' => $records->isEmpty() ? null : round($records->avg(fn ($r) => (float) $r->gpa), 2),
            'latest' => $records->last(),
            'passMark' => PlayerAcademicRecord::PASS_MARK,
        ])->render();

        return $this->pdf->stream($html, "academic-report-{$player->membership_id}.pdf");
    }
```

- [ ] **Step 4: Blade view**

`resources/views/pdf/academic-report.blade.php`:

```blade
@php($L = fn (string $key) => \App\Support\UiLang::get($key))
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin: 6px 0 12px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { padding: 5px 6px; border-bottom: 1px solid #eef2f6; }
        table.info td.label { color: #64748b; width: 30%; }
        table.grades { width: 100%; border-collapse: collapse; }
        table.grades th { background: #f1f5f9; color: #475569; font-size: 11px; padding: 6px; border: 1px solid #e2e8f0; }
        table.grades td { padding: 6px; border: 1px solid #e2e8f0; }
        .num { text-align: center; font-family: monospace; font-size: 13px; }
        .pass { color: #047857; font-weight: bold; }
        .fail { color: #be123c; font-weight: bold; }
        .summary { margin-top: 12px; font-size: 13px; }
        .photo { width: 80px; height: 80px; border-radius: 8px; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('academic_report') }}</div>

    <table style="width:100%; margin-bottom:10px;">
        <tr>
            @if (!empty($photo))
                <td style="width:90px; vertical-align:top;"><img class="photo" src="{{ $photo }}"></td>
            @endif
            <td style="vertical-align:top;">
                <div style="font-size:15px; font-weight:bold;">{{ $player->fullname }}</div>
                <div style="font-family:monospace;">{{ $player->membership_id }}</div>
                <div style="color:#64748b;">{{ optional($player->category)->name }}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr><td class="label">{{ $L('education_level') }}</td><td>{{ $player->education_level ? $L('education_level_'.$player->education_level) : '—' }}</td></tr>
        <tr><td class="label">{{ $L('institution') }}</td><td>{{ $player->institution ?: '—' }}</td></tr>
        <tr><td class="label">{{ $L('field_of_study') }}</td><td>{{ $player->field_of_study ?: '—' }}</td></tr>
    </table>

    @if ($records->isEmpty())
        <p>{{ $L('no_gpa_records') }}</p>
    @else
        <table class="grades">
            <thead>
                <tr>
                    <th>{{ $L('academic_year') }}</th>
                    <th>{{ $L('period') }}</th>
                    <th>{{ $L('gpa') }} / 20</th>
                    <th>{{ $L('status') }}</th>
                    <th>{{ $L('remark') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    @php($passed = (float) $record->gpa >= $passMark)
                    <tr>
                        <td class="num">{{ $record->academic_year }}/{{ $record->academic_year + 1 }}</td>
                        <td>{{ $L('period_'.$record->period) }}</td>
                        <td class="num {{ $passed ? 'pass' : 'fail' }}">{{ number_format((float) $record->gpa, 2) }}</td>
                        <td class="{{ $passed ? 'pass' : 'fail' }}">{{ $L($passed ? 'gpa_pass' : 'gpa_fail') }}</td>
                        <td>{{ $record->remark }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <strong>{{ $L('latest_gpa') }}:</strong> {{ number_format((float) $latest->gpa, 2) }} / 20
            &nbsp;&middot;&nbsp;
            <strong>{{ $L('average_gpa') }}:</strong> {{ number_format($average, 2) }} / 20
            &nbsp;&middot;&nbsp;
            {{ $L('pass_mark') }}: {{ $passMark }}
        </div>
    @endif
</body>
</html>
```

(`status` already exists in the catalogs — `t('status')` is used in `Show.vue`.)

- [ ] **Step 5: Route + permission override**

`routes/web.php`, after the `players.label` route:

```php
    Route::get('/players/{player}/academic-report', [ReportController::class, 'academicReport'])->name('players.academic-report');
```

`config/permissions.php` overrides, after `'players.card' => ['players', 'view'],`:

```php
        // "academic-report" is not a view verb, so without this printing would need edit rights.
        'players.academic-report' => ['players', 'view'],
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=ReportPdfTest` → all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/pdf/academic-report.blade.php routes/web.php config/permissions.php tests/Feature/ReportPdfTest.php
git commit -m "feat(academic): printable academic report PDF

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Player form school fields

**Files:**
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue` (form init ~line 47, `submit()` transform ~line 186, template after the student/worker select ~line 380)

**Interfaces:**
- Consumes: request fields `education_level`, `institution`, `field_of_study` (Task 3); keys `education_level*`, `institution`, `field_of_study` (Task 6)

- [ ] **Step 1: Form state** — after `is_student: …,` in the `useForm({...})` init:

```js
    education_level: p.education_level || '',
    institution: p.institution || '',
    field_of_study: p.field_of_study || '',
```

Add near the top-level constants of `<script setup>`:

```js
const EDUCATION_LEVELS = ['primary', 'middle', 'secondary', 'vocational', 'licence', 'master', 'doctorate'];
```

- [ ] **Step 2: Submit transform** — after `is_student: data.is_student,`:

```js
        // Workers keep whatever school info is stored; only a student edits it.
        ...(data.is_student ? {
            education_level: data.education_level || null,
            institution: data.institution || null,
            field_of_study: data.field_of_study || null,
        } : {}),
```

- [ ] **Step 3: Template** — directly after the `<div v-if="!form.is_student">…job…</div>` block, add:

```vue
                <template v-if="form.is_student">
                    <div>
                        <InputLabel :value="t('education_level')" />
                        <select v-model="form.education_level" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">-</option>
                            <option v-for="level in EDUCATION_LEVELS" :key="level" :value="level">{{ t(`education_level_${level}`) }}</option>
                        </select>
                        <InputError :message="form.errors.education_level" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('institution')" />
                        <TextInput v-model="form.institution" type="text" class="mt-1 w-full" maxlength="255" />
                        <InputError :message="form.errors.institution" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('field_of_study')" />
                        <TextInput v-model="form.field_of_study" type="text" class="mt-1 w-full" maxlength="255" />
                        <InputError :message="form.errors.field_of_study" class="mt-1" />
                    </div>
                </template>
```

Confirm `TextInput` and `InputError` are already imported in `PlayerForm.vue` (`grep -n "import TextInput\|import InputError"`); import them from `@/Components/…` if not.

- [ ] **Step 4: Build**

Run: `npm run build` → succeeds with no errors.

- [ ] **Step 5: Manual check** (use the `run` skill or `php artisan serve` + `npm run dev`): edit a student player, set level/institution/field, save, reopen edit — values persist; switch to worker — the three fields hide.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Partials/PlayerForm.vue
git commit -m "feat(academic): school info fields on the player form

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Profile academic section

**Files:**
- Create: `resources/js/Pages/Players/Partials/AcademicSection.vue`
- Modify: `resources/js/Pages/Players/Show.vue` (import + mount between the info-cards grid and the Subscriptions card, ~line 422)

**Interfaces:**
- Consumes: `player.academic_records`, `player.education_level|institution|field_of_study`, routes from Tasks 2 & 7, keys from Tasks 2 & 6, `useCan()`, `baseOptions`/`lineDataset`/`seriesColor`/`mutedInk` from `@/lib/chartTheme`, `@/lib/registerCharts`
- Produces: `<AcademicSection :player="player" />`

- [ ] **Step 1: Create the component**

`resources/js/Pages/Players/Partials/AcademicSection.vue`:

```vue
<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Line } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { baseOptions, lineDataset, mutedInk } from '@/lib/chartTheme';
import { useCan } from '@/Composables/useCan';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PlayerFieldRow from '@/Components/PlayerFieldRow.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    player: { type: Object, required: true },
});

const PASS_MARK = 10;
const PERIODS = ['S1', 'S2', 'T1', 'T2', 'T3', 'ANNUAL'];

const { t, locale } = useI18n();
const { can } = useCan();
const rtl = computed(() => locale.value === 'ar');

// Server sends them oldest first (year, then period rank with ANNUAL last).
const records = computed(() => props.player.academic_records ?? []);
const gpa = (r) => Number(r.gpa);
const yearLabel = (year) => `${year}/${Number(year) + 1}`;
const passed = (r) => gpa(r) >= PASS_MARK;

const latest = computed(() => records.value.at(-1) ?? null);
const previous = computed(() => records.value.at(-2) ?? null);
const average = computed(() => (records.value.length
    ? records.value.reduce((sum, r) => sum + gpa(r), 0) / records.value.length
    : null));
const delta = computed(() => (latest.value && previous.value ? gpa(latest.value) - gpa(previous.value) : null));
const fmt = (value) => (value === null ? '—' : Number(value).toFixed(2));

// Newest first in the table; the chart reads left-to-right in time.
const tableRows = computed(() => [...records.value].reverse());

const chartData = computed(() => ({
    labels: records.value.map((r) => `${yearLabel(r.academic_year)} ${t(`period_${r.period}`)}`),
    datasets: [
        { ...lineDataset(t('gpa'), records.value.map(gpa), 0), pointRadius: 3 },
        {
            label: t('pass_mark'),
            data: records.value.map(() => PASS_MARK),
            borderColor: mutedInk(),
            borderDash: [6, 4],
            borderWidth: 1,
            pointRadius: 0,
            pointHoverRadius: 0,
            fill: false,
        },
    ],
}));

const chartOptions = computed(() => {
    const options = baseOptions({ rtl: rtl.value });
    options.scales.y = { ...options.scales.y, min: 0, max: 20, ticks: { ...options.scales.y.ticks, stepSize: 5 } };
    return options;
});

// --- add / edit ---
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    academic_year: new Date().getMonth() >= 8 ? new Date().getFullYear() : new Date().getFullYear() - 1,
    period: 'S1',
    gpa: '',
    remark: '',
});

function openAdd() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(record) {
    editingId.value = record.id;
    form.academic_year = record.academic_year;
    form.period = record.period;
    form.gpa = record.gpa;
    form.remark = record.remark ?? '';
    form.clearErrors();
    showForm.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); editingId.value = null; },
    };
    form.transform((data) => ({ ...data, remark: data.remark || null }));
    if (editingId.value) {
        form.put(route('players.academic-records.update', [props.player.id, editingId.value]), options);
    } else {
        form.post(route('players.academic-records.store', props.player.id), options);
    }
}

// --- delete ---
const removingId = ref(null);

function confirmRemove() {
    router.delete(route('players.academic-records.destroy', [props.player.id, removingId.value]), {
        preserveScroll: true,
        onFinish: () => { removingId.value = null; },
    });
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('academic_progress') }}</h3>
            <div class="flex items-center gap-2">
                <a :href="route('players.academic-report', player.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <Icon name="print" /> {{ t('print_academic_report') }}
                </a>
                <button v-if="can('players', 'add')" type="button" @click="openAdd" class="rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                    {{ t('add_gpa') }}
                </button>
            </div>
        </div>

        <div class="space-y-5 px-5 py-4">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-3">
                <PlayerFieldRow icon="school" :label="t('education_level')" :value="player.education_level ? t(`education_level_${player.education_level}`) : null" />
                <PlayerFieldRow icon="building" :label="t('institution')" :value="player.institution" />
                <PlayerFieldRow icon="book" :label="t('field_of_study')" :value="player.field_of_study" />
            </dl>

            <div v-if="records.length" class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('latest_gpa') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums" :class="passed(latest) ? 'text-emerald-600' : 'text-rose-600'">{{ fmt(latest.gpa) }}<span class="text-sm font-normal text-slate-400"> / 20</span></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('average_gpa') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ fmt(average) }}<span class="text-sm font-normal text-slate-400"> / 20</span></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('gpa_change') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums"
                       :class="delta === null || delta === 0 ? 'text-slate-400' : delta > 0 ? 'text-emerald-600' : 'text-rose-600'">
                        <template v-if="delta === null">—</template>
                        <template v-else>{{ delta > 0 ? '▲ +' : delta < 0 ? '▼ ' : '' }}{{ delta.toFixed(2) }}</template>
                    </p>
                </div>
            </div>

            <div v-if="records.length >= 2" class="h-64">
                <Line :data="chartData" :options="chartOptions" />
            </div>
        </div>

        <div v-if="!records.length" class="border-t border-slate-100 px-5 py-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ t('no_gpa_records') }}</div>
        <div v-else class="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('academic_year') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('period') }}</th>
                        <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('gpa') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('remark') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="r in tableRows" :key="r.id">
                        <td class="px-4 py-3 text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ yearLabel(r.academic_year) }}</td>
                        <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ t(`period_${r.period}`) }}</td>
                        <td class="px-4 py-3 text-end text-sm font-semibold tabular-nums" :class="passed(r) ? 'text-emerald-700' : 'text-rose-700'">{{ fmt(r.gpa) }}</td>
                        <td class="px-4 py-3"><Badge :label="t(passed(r) ? 'gpa_pass' : 'gpa_fail')" :color="passed(r) ? 'emerald' : 'rose'" /></td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ r.remark || '—' }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <button v-if="can('players', 'edit')" type="button" @click="openEdit(r)" class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">{{ t('edit') }}</button>
                            <button v-if="can('players', 'delete')" type="button" @click="removingId = r.id" class="ms-2 rounded-md px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">{{ t('remove') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showForm" @close="showForm = false" max-width="md">
            <form @submit.prevent="submit" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit_gpa') : t('add_gpa') }}</h3>
                <InputError :message="form.errors.student" class="mt-2" />
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="t('academic_year')" />
                        <select v-model.number="form.academic_year" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="y in yearOptions" :key="y" :value="y">{{ yearLabel(y) }}</option>
                        </select>
                        <InputError :message="form.errors.academic_year" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('period')" />
                        <select v-model="form.period" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="p in PERIODS" :key="p" :value="p">{{ t(`period_${p}`) }}</option>
                        </select>
                        <InputError :message="form.errors.period" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="`${t('gpa')} / 20`" />
                        <TextInput v-model="form.gpa" type="number" step="0.01" min="0" max="20" class="mt-1 w-full" required />
                        <InputError :message="form.errors.gpa" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('remark')" />
                        <textarea v-model="form.remark" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                        <InputError :message="form.errors.remark" class="mt-1" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showForm = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="removingId !== null"
            :title="t('delete')"
            :message="t('delete_gpa_warning')"
            @confirm="confirmRemove"
            @cancel="removingId = null"
        />
    </div>
</template>
```

Icon names: check `school`, `building`, `book` exist in `resources/js/Components/Icon.vue` (`grep -n "school\|building\|book" resources/js/Components/Icon.vue`). Replace any missing one with an existing name (e.g. `folder`, `document`) — do not add new icons.

The academic-year select lists the 12 school years ending with the current one; an older record being edited keeps its own year in the list. Add this to the component's `<script setup>`, after the `form` definition:

```js
const yearOptions = computed(() => {
    const top = new Date().getFullYear();
    const years = Array.from({ length: 12 }, (_, i) => top - i);
    if (form.academic_year && !years.includes(Number(form.academic_year))) years.push(Number(form.academic_year));
    return years.sort((a, b) => b - a);
});
```

- [ ] **Step 2: Mount in Show.vue**

Import after the other component imports:

```js
import AcademicSection from '@/Pages/Players/Partials/AcademicSection.vue';
```

Insert right before `<!-- Subscriptions -->`:

```vue
            <!-- Studies: only students carry an education section -->
            <AcademicSection v-if="player.is_student" :player="player" />
```

- [ ] **Step 3: Build**

Run: `npm run build` → succeeds.

- [ ] **Step 4: Manual check** (run the app): as admin on a student profile — add S1 12.5 (row green), add S2 8 (row red, delta ▼ -4.50, chart appears with dashed 10 line), duplicate S2 shows period error in modal, edit, delete with confirm, print opens PDF. Worker profile shows no section. Arabic locale: chart mirrored, labels translated.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Players/Partials/AcademicSection.vue resources/js/Pages/Players/Show.vue
git commit -m "feat(academic): GPA history, trend chart and stats on the player profile

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: List filter UI + dashboard card

**Files:**
- Modify: `resources/js/Pages/Players/Index.vue` (refs ~line 46, `useListFilters` params ~line 59, template after the wilaya `<select>` ~line 305)
- Modify: `resources/js/Pages/Dashboard/Partials/MembersTab.vue` (computed near line 26, template after the tile `<section>` ~line 148)

**Interfaces:**
- Consumes: `academic` query param + `filters.academic` (Task 4), `members.academic` (Task 5), keys (Task 6)

- [ ] **Step 1: Index filter state** — after `const wilayaFilter = …`:

```js
const academicFilter = ref(props.filters?.academic || '');
```

In the `useListFilters(..., () => ({ ... }))` param object, after `wilaya_id: wilayaFilter.value,`:

```js
    academic: academicFilter.value,
```

- [ ] **Step 2: Index filter select** — after the wilaya `<select>…</select>`:

```vue
                <select
                    v-model="academicFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('academic_all') }}</option>
                    <option value="at_risk">{{ t('academic_at_risk') }}</option>
                    <option value="good">{{ t('academic_good') }}</option>
                    <option value="none">{{ t('academic_none') }}</option>
                </select>
```

Note: "All students" with an empty value means "no academic filter" (all players, students or not) — this matches the other selects' "All …" semantics.

- [ ] **Step 3: Dashboard card** — in `MembersTab.vue` script, after `const topCities = …`:

```js
const academic = computed(() => props.data?.academic ?? null);
```

Template, right after the tile `</section>` (before the growth `ChartCard`):

```vue
        <section v-if="academic && academic.students" class="space-y-3" :aria-label="t('dashboard.mem_academic')">
            <h3 class="text-sm font-semibold text-muted-foreground">{{ t('dashboard.mem_academic') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile :label="t('dashboard.mem_students')" :value="academic.students" icon="players" tone="primary" />
                <StatTile :label="t('dashboard.mem_academic_average')" :value="academic.average === null ? null : `${academic.average.toFixed(2)} / 20`" format="text" icon="chart" tone="positive" />
                <StatTile :label="t('dashboard.mem_at_risk')" :value="academic.at_risk" icon="alert" tone="negative" :href="route('players.index', { academic: 'at_risk' })" />
                <StatTile :label="t('dashboard.mem_missing_gpa')" :value="academic.missing" icon="dot" tone="warning" :href="route('players.index', { academic: 'none' })" />
            </div>
        </section>
```

`StatTile` formats non-money/percent values with `Intl.NumberFormat`, which turns `"11.50 / 20"` into `NaN`. Add a `text` passthrough in `resources/js/Components/Dashboard/StatTile.vue` `display` computed, before the final `return`:

```js
    if (props.format === 'text') return String(props.value);
```

Check icon names `chart`, `alert` exist in `Icon.vue`; swap for existing ones if not.

- [ ] **Step 4: Build + tests**

Run: `npm run build` → succeeds.
Run: `php artisan test` → full suite green.
Run: `npm run i18n:check` → passes.

- [ ] **Step 5: Manual check**: Players list — "At risk" option shows only students with latest GPA < 10; export with filter set exports same rows. Dashboard → Members tab shows the card; clicking "Students at risk" opens the filtered list.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Index.vue resources/js/Pages/Dashboard/Partials/MembersTab.vue resources/js/Components/Dashboard/StatTile.vue
git commit -m "feat(academic): academic filter on players list and dashboard card

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Deployment note

Run `php artisan migrate` at deploy (adds 3 player columns + 1 table). No data backfill. Desktop (NativePHP) build picks it up via its normal migration-on-start.
