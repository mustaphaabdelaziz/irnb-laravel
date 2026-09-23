# Round 2 · P2 Player Identity — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the P2 package of `docs/superpowers/specs/2026-09-22-post-testing-round-2-design.md`:
- #6 membership ID frozen + permanent file number + printable folder label and category board table
- #14 the 58 official wilayas, stored by id instead of free text
- #10 a main position plus other positions
- #15 jobs in three languages, created inline from the player form, without duplicates
- #16 the player page rebuilt with icons and the fields it already loads but never shows

**Architecture:**
- **Paper files stop moving.** A player keeps one permanent `file_number` for life; the cabinet is sorted by it. What changes every season is the printed **category board table**, produced from the app.
- **Reference data lives in migrations**, because desktop installs run `migrate` on boot and never run seeders. The official wilaya list becomes one PHP data file that both the migration and the seeder read.
- **Backwards-compatible shapes.** `players.position_id` stays the main position (stats, bulk edit, import and the member card keep working) and a pivot holds the rest. `players.state` stays for one release beside the new `wilaya_id`.
- **One lookup-table convention.** Jobs gain `name_ar/name_fr/name_en` + `HasLocalizedName` + an in-use delete guard, copying `Category` and `PlayerStatus`.

**Tech Stack:** PHP 8.x, Laravel 13, Inertia v2 + Vue 3 `<script setup>`, vue-i18n (flat keys), Tailwind, mPDF 8.3 (+ `mpdf/qrcode`), PHPUnit 12, SQLite.

## Global Constraints

**Branch**
- Start from `feat/player-previous-debts` (P1 is merged into it): `git switch -c feat/round2-p2-player-identity`.

**Committing**
- Stage **explicit file paths only**. Never stage a directory. After each commit run `git show --stat HEAD` and check the file list.

**Two behaviour changes that break existing tests on purpose**
- The membership ID is **frozen**: it must no longer be regenerated when the join year changes. `tests/Feature/PlayerMembershipIdTest.php::it_regenerates_membership_id_when_join_year_changes` asserts today's behaviour and is **replaced** in Task 2, not deleted silently.
- `players.state` stops being written by the form. It stays in the database for one release.

**Tests that constrain the design — keep them green**
- `tests/Feature/PlayerFormPropsTest.php:30` asserts **58 wilayas** and that `wilayas.0` has `id`, `name`, `ar_name`. Keep those three keys in the prop, whatever else is added.
- `tests/Feature/PlayerImportTest.php` builds a **19-column positional CSV**. New import columns are **appended at the end**, never inserted, so old files still import.
- `tests/Feature/PlayerBulkUpdateTest.php` and `tests/Feature/PlayerStatsFilterTest.php` require `players.position_id` to stay a single scalar column.
- `tests/Feature/ListFilterPartialReloadTest.php:63-82` pins the partial-reload `only` list for `players.index` on both sides (Vue and controller). Any new filter prop must be added to both in lockstep.

**Desktop**
- Reference data goes in migrations, never seeders.
- No enum column changes (SQLite rebuilds the table).
- New migrations are named `database/migrations/2026_09_23_1000NN_*.php`.
- After any schema change, `storage/app/seed/database.sqlite` is refreshed before the next desktop build (final task).

**UI text**
- Flat keys in `resources/js/i18n/{ar,en,fr}.json`, called with `t('key')`, never `te()`.
- Keys are added ONLY with `node scripts/i18n-add.mjs <file>`; the scratch file is deleted and never staged.
- vue-i18n treats `{ } @ $ |` as syntax: values may carry `{name}` placeholders and none of the others.
- Server-rendered text uses `App\Support\UiLang` (P1) or `__()` with entries in `lang/{ar,fr}.json` in alphabetical order.

**Files with a byte-order mark**
- `resources/js/Pages/Players/Show.vue` and `resources/js/Pages/Equipment/Catalog/Show.vue` start with a UTF-8 BOM. Targeted edits only; verify with `head -c 3 <file> | od -An -tx1` → `ef bb bf`.

**Tests and tooling**
- PHPUnit class style, `#[Test]`, `RefreshDatabase`. Always `php artisan config:clear` before running tests.
- Actor: `User::factory()->admin()->create(['email_verified_at' => now()])`. No Player factory — build players with `Player::create([...])` including a unique `membership_id`.
- Read Inertia props with `->viewData('page')['props']`.
- `php vendor/bin/pint <changed php files>` before each commit.
- Frontend verification is `npm run build` + `node scripts/i18n-check.mjs` (no JS test runner in this project) plus the manual list in the final task.

**Composer**
- Task 4 runs `composer require mpdf/qrcode`. **A composer install/update reverts the NativePHP vendor patch** that copies the VC++ runtime DLLs (`afterPack` in `vendor/nativephp/desktop/resources/electron/electron-builder.mjs`). That patch must be reapplied before the next desktop build or fresh installs will not start. The final task carries this as a deploy note.

---

## File structure

**New**

| File | Responsibility |
|---|---|
| `app/Support/Season.php` | The club season (start month from settings): current, for a date, label, bounds |
| `app/Services/Player/FileNumber.php` | Allocate, format and locate a permanent file number |
| `database/data/algeria_wilayas_official.php` | The 58 official wilayas: code, French name, Arabic name |
| `app/Support/NameNormalizer.php` | Normalise a name for duplicate detection (case, accents, Arabic forms) |
| `app/Services/Lookup/JobDuplicateFinder.php` | Find an exact or near-duplicate job |
| `app/Http/Controllers/PlayerLabelController.php` | Folder label PDF (one or many) and the category board table |
| `resources/views/pdf/folder-label.blade.php` | The folder label itself |
| `resources/views/pdf/board-table.blade.php` | The per-category board table |
| `resources/js/Components/PlayerFieldRow.vue` | One icon + label + value row on the player page |
| `resources/js/Components/JobQuickCreateModal.vue` | Create a job without leaving the player form |
| migrations `100001`–`100006` | settings keys; file number; wilaya columns + data; `players.wilaya_id`; other-positions pivot; job locale columns |
| tests | `SeasonTest`, `FileNumberTest`, `PlayerFileNumberTest`, `PlayerLabelTest`, `BoardTablePdfTest`, `WilayaDataTest`, `PlayerWilayaTest`, `PlayerPositionsTest`, `JobLocalizationTest`, `JobDuplicateTest` |

**Modified**

| Area | Files |
|---|---|
| Settings | `UpdateWebsiteConfigRequest`, `Settings/General.vue` |
| Players | `Player`, `PlayerController`, `RegisterPlayerService`, `StorePlayerRequest`, `UpdatePlayerRequest`, `BulkUpdatePlayersRequest`, `PlayerImportController`, `PlayerForm.vue`, `Players/Index.vue`, `Players/Show.vue`, `Players/Create.vue`, `Players/Edit.vue` |
| Geography | `CountryState`, `CountrySeeder` |
| Lookups | `MemberJob`, `MemberJobController`, `Position`, `Settings/Jobs.vue`, `Settings/Positions.vue` |
| PDFs | `ReportController`, `pdf/member-card.blade.php` |
| Shared | `routes/web.php`, `config/permissions.php`, `Icon.vue`, `AuthenticatedLayout.vue`, `lang/{ar,fr}.json`, `resources/js/i18n/*.json` |
| Tests | `PlayerMembershipIdTest` (replaced case), `PlayerFormPropsTest`, `PlayerImportTest` |

---

### Task 1: The club season and the two file settings

**Why:** "Renew every season" (P3) and the board tables need one definition of a season. The drawer size decides which drawer a file number lives in. Both are club settings, not code.

**Files:**
- Create: `app/Support/Season.php`
- Create: `database/migrations/2026_09_23_100001_add_club_file_settings.php`
- Modify: `app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php`
- Modify: `resources/js/Pages/Settings/General.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/SeasonTest.php`

**Interfaces:**
- Produces settings keys `settings.seasonStartMonth` (int 1–12, default 9) and `settings.fileDrawerSize` (int 10–1000, default 100).
- Produces `App\Support\Season`:
  - `Season::startMonth(): int`
  - `Season::current(): self`, `Season::forDate(\DateTimeInterface|string $date): self`
  - `$season->startYear: int`, `start(): CarbonImmutable`, `end(): CarbonImmutable`, `label(): string`, `contains($date): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SeasonTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\WebsiteConfig;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    use RefreshDatabase;

    private function startMonth(int $month): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => $month]]);
    }

    #[Test]
    public function the_season_starts_in_september_unless_the_club_says_otherwise(): void
    {
        $this->assertSame(9, Season::startMonth());
    }

    #[Test]
    public function a_date_before_the_start_month_belongs_to_the_previous_season(): void
    {
        $this->startMonth(9);

        $this->assertSame(2025, Season::forDate('2026-08-31')->startYear);
        $this->assertSame(2026, Season::forDate('2026-09-01')->startYear);
    }

    #[Test]
    public function a_season_knows_its_bounds_and_label(): void
    {
        $this->startMonth(9);
        $season = Season::forDate('2026-10-05');

        $this->assertSame('2026-09-01', $season->start()->toDateString());
        $this->assertSame('2027-08-31', $season->end()->toDateString());
        $this->assertSame('2026/27', $season->label());
        $this->assertTrue($season->contains('2027-03-01'));
        $this->assertFalse($season->contains('2027-09-01'));
    }

    #[Test]
    public function a_january_start_makes_the_season_a_plain_calendar_year(): void
    {
        $this->startMonth(1);
        $season = Season::forDate('2026-05-05');

        $this->assertSame('2026-01-01', $season->start()->toDateString());
        $this->assertSame('2026-12-31', $season->end()->toDateString());
        $this->assertSame('2026', $season->label());
    }

    #[Test]
    public function an_impossible_start_month_falls_back_to_september(): void
    {
        $this->startMonth(13);

        $this->assertSame(9, Season::startMonth());
    }

    #[Test]
    public function the_settings_form_stores_both_file_settings(): void
    {
        $admin = \App\Models\User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['seasonStartMonth' => 7, 'fileDrawerSize' => 250]])
            ->assertRedirect();

        $settings = WebsiteConfig::singleton()->fresh()->settings;
        $this->assertSame(7, $settings['seasonStartMonth']);
        $this->assertSame(250, $settings['fileDrawerSize']);
        // The merge must not drop the keys the other tabs own.
        $this->assertArrayHasKey('currency', $settings);
    }

    #[Test]
    public function an_out_of_range_setting_is_rejected(): void
    {
        $admin = \App\Models\User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['seasonStartMonth' => 13]])
            ->assertSessionHasErrors('settings.seasonStartMonth');

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['fileDrawerSize' => 0]])
            ->assertSessionHasErrors('settings.fileDrawerSize');
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=SeasonTest`

Expected: FAIL with `Class "App\Support\Season" not found`.

- [ ] **Step 3: The Season helper**

Create `app/Support/Season.php`:

```php
<?php

namespace App\Support;

use App\Models\WebsiteConfig;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The club's season. A sports season rarely matches the calendar year — this
 * club's starts in September — so "this season" is defined once, from a
 * setting, and every feature that needs it (board tables, documents that are
 * renewed each season) reads it here instead of inventing its own rule.
 */
final class Season
{
    private const DEFAULT_START_MONTH = 9;

    private function __construct(public readonly int $startYear, private readonly int $startMonth) {}

    /** The month a season starts in, 1-12. Falls back to September when unset or out of range. */
    public static function startMonth(): int
    {
        $month = (int) (WebsiteConfig::singleton()->settings['seasonStartMonth'] ?? self::DEFAULT_START_MONTH);

        return ($month >= 1 && $month <= 12) ? $month : self::DEFAULT_START_MONTH;
    }

    public static function current(): self
    {
        return self::forDate(CarbonImmutable::now());
    }

    public static function forDate(DateTimeInterface|string $date): self
    {
        $moment = CarbonImmutable::parse($date);
        $startMonth = self::startMonth();

        // Before the start month the date still belongs to the season that opened last year.
        $startYear = $moment->month >= $startMonth ? $moment->year : $moment->year - 1;

        return new self($startYear, $startMonth);
    }

    public static function forStartYear(int $startYear): self
    {
        return new self($startYear, self::startMonth());
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear, $this->startMonth, 1)->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return $this->start()->addYear()->subDay()->endOfDay();
    }

    public function contains(DateTimeInterface|string $date): bool
    {
        return CarbonImmutable::parse($date)->between($this->start(), $this->end());
    }

    /** "2026/27" — or just "2026" when the season is a calendar year. */
    public function label(): string
    {
        if ($this->startMonth === 1) {
            return (string) $this->startYear;
        }

        return $this->startYear.'/'.str_pad((string) (($this->startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Seed the defaults and validate the keys**

Create `database/migrations/2026_09_23_100001_add_club_file_settings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two club settings, seeded so an existing install has them without
     * anyone visiting the settings page: the month a season starts (September
     * here) and how many paper files fit in one drawer.
     *
     * Reference data goes in a migration because the desktop build runs
     * migrate on boot and never runs seeders.
     */
    public function up(): void
    {
        $row = DB::table('website_configs')->orderBy('id')->first();

        if ($row === null) {
            return; // A fresh install seeds these through WebsiteConfig::singleton().
        }

        $settings = json_decode((string) $row->settings, true) ?: [];
        $settings['seasonStartMonth'] ??= 9;
        $settings['fileDrawerSize'] ??= 100;

        DB::table('website_configs')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
    }

    public function down(): void
    {
        $row = DB::table('website_configs')->orderBy('id')->first();

        if ($row === null) {
            return;
        }

        $settings = json_decode((string) $row->settings, true) ?: [];
        unset($settings['seasonStartMonth'], $settings['fileDrawerSize']);

        DB::table('website_configs')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
    }
};
```

In `app/Models/WebsiteConfig.php`, add both keys to the defaults inside `singleton()`'s `settings` array, next to `fiscalYearStart`:

```php
                    'seasonStartMonth' => 9,
                    'fileDrawerSize' => 100,
```

In `app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php`, replace the `'settings' => ['nullable', 'array'],` line with:

```php
            'settings' => ['nullable', 'array'],
            // The only two settings with a meaning the server must defend: a month
            // outside 1-12 or a drawer of zero files would break file locations.
            'settings.seasonStartMonth' => ['nullable', 'integer', 'min:1', 'max:12'],
            'settings.fileDrawerSize' => ['nullable', 'integer', 'min:10', 'max:1000'],
```

- [ ] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=SeasonTest`

Expected: PASS (7 tests).

- [ ] **Step 6: The settings screen**

Add the keys. Save as `i18n-keys.tmp.json`:

```json
{
    "club_files": { "en": "Season and files", "fr": "Saison et dossiers", "ar": "الموسم والملفات" },
    "season_start_month": { "en": "Season starts in", "fr": "La saison commence en", "ar": "يبدأ الموسم في" },
    "season_start_hint": { "en": "Used for board tables and documents renewed each season.", "fr": "Utilisé pour les tableaux par catégorie et les documents renouvelés chaque saison.", "ar": "يُستعمل في جداول الفئات والوثائق التي تُجدَّد كل موسم." },
    "file_drawer_size": { "en": "Files per drawer", "fr": "Dossiers par tiroir", "ar": "عدد الملفات في الدرج" },
    "file_drawer_hint": { "en": "Decides which drawer a file number falls in.", "fr": "Détermine dans quel tiroir se trouve un numéro de dossier.", "ar": "يحدد الدرج الذي يقع فيه رقم الملف." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

In `resources/js/Pages/Settings/General.vue`:

Add the two keys to `settingsForm` (the `useForm` around line 178), after the existing keys:

```js
    seasonStartMonth: props.config?.settings?.seasonStartMonth ?? 9,
    fileDrawerSize: props.config?.settings?.fileDrawerSize ?? 100,
```

Add them to what `saveSettings()` sends, inside its `transform`'s `settings` object:

```js
        seasonStartMonth: Number(settingsForm.seasonStartMonth) || 9,
        fileDrawerSize: Number(settingsForm.fileDrawerSize) || 100,
```

In the `settings` tab's template block, append this group as the last child of the tab's field grid:

```html
                    <div class="sm:col-span-2 mt-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('club_files') }}</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('season_start_month') }}</span>
                                <select v-model="settingsForm.seasonStartMonth" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                                    <option v-for="m in 12" :key="m" :value="m">{{ monthName(m) }}</option>
                                </select>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('season_start_hint') }}</span>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('file_drawer_size') }}</span>
                                <input v-model="settingsForm.fileDrawerSize" type="number" min="10" max="1000" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('file_drawer_hint') }}</span>
                            </label>
                        </div>
                    </div>
```

Add the month-name helper next to the other helpers in the script:

```js
// Month names come from the browser in the active language, so no catalog keys.
function monthName(month) {
    return new Date(2000, month - 1, 1).toLocaleString(locale.value === 'ar' ? 'ar' : locale.value, { month: 'long' });
}
```

If `locale` is not already destructured from `useI18n()` in this file, change that line to `const { t, locale } = useI18n();`.

- [ ] **Step 7: Verify**

Run: `npm run build` (success), `node scripts/i18n-check.mjs` (`✓ …`), `php artisan test --filter="SeasonTest|FlashTranslationTest"` (PASS).

- [ ] **Step 8: Commit**

```bash
php vendor/bin/pint app/Support/Season.php database/migrations/2026_09_23_100001_add_club_file_settings.php app/Models/WebsiteConfig.php app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php tests/Feature/SeasonTest.php
git add app/Support/Season.php database/migrations/2026_09_23_100001_add_club_file_settings.php app/Models/WebsiteConfig.php app/Http/Requests/WebsiteConfig/UpdateWebsiteConfigRequest.php resources/js/Pages/Settings/General.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/SeasonTest.php
git commit -m "feat(settings): club season start month and files per drawer"
```

---

### Task 2: A permanent file number, and a membership ID that stops moving

**Why:** paper files are filed by a number written on the folder. That number must never change, and the membership ID printed on cards must not change either — today it is regenerated whenever the join year is edited.

**Files:**
- Create: `app/Services/Player/FileNumber.php`
- Create: `database/migrations/2026_09_23_100002_add_file_number_to_players.php`
- Modify: `app/Models/Player.php` (fillable + `scopeSearch`)
- Modify: `app/Services/Player/RegisterPlayerService.php`
- Modify: `app/Http/Controllers/PlayerController.php` (remove the regeneration block)
- Modify: `tests/Feature/PlayerMembershipIdTest.php` (replace one case)
- Test: `tests/Feature/PlayerFileNumberTest.php`

**Interfaces:**
- Produces `players.file_number` (unsigned integer, unique, nullable in the schema, always set by the app).
- Produces `App\Services\Player\FileNumber`:
  - `next(): int`
  - `assign(Player $player): int` (no-op when the player already has one)
  - `format(?int $number): string` → `'0123'`, `''` for null
  - `drawerSize(): int`, `drawer(int $number, ?int $size = null): int`
- `Player::query()->search($term)` also matches a file number, with or without leading zeros.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlayerFileNumberTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\FileNumber;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFileNumberTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function register(string $firstname, int $joinYear = 2026): Player
    {
        return app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'join_year' => $joinYear,
        ]);
    }

    #[Test]
    public function a_registered_player_gets_the_next_file_number(): void
    {
        $first = $this->register('Amine');
        $second = $this->register('Yanis');

        $this->assertSame(1, $first->fresh()->file_number);
        $this->assertSame(2, $second->fresh()->file_number);
    }

    #[Test]
    public function a_number_is_never_reused_after_a_player_leaves(): void
    {
        $this->register('Amine');
        $second = $this->register('Yanis');
        $second->forceFill(['archived' => true])->save();

        $this->assertSame(3, $this->register('Sami')->fresh()->file_number);
    }

    #[Test]
    public function both_identifiers_survive_a_join_year_change(): void
    {
        $player = $this->register('Amine', 2024);
        $membership = $player->membership_id;
        $fileNumber = $player->fresh()->file_number;

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), ['firstname' => 'Amine', 'join_year' => 2025])
            ->assertRedirect();

        $player->refresh();
        $this->assertSame($membership, $player->membership_id, 'the card and the folder carry this number');
        $this->assertSame($fileNumber, $player->file_number);
        $this->assertSame(2025, $player->join_year);
    }

    #[Test]
    public function the_search_finds_a_player_by_file_number_with_or_without_leading_zeros(): void
    {
        $this->register('Amine');
        $target = $this->register('Yanis'); // file number 2

        $this->assertSame([$target->id], Player::query()->search('2')->pluck('id')->all());
        $this->assertSame([$target->id], Player::query()->search('0002')->pluck('id')->all());
    }

    #[Test]
    public function a_file_number_is_shown_padded_and_sits_in_a_drawer(): void
    {
        $this->assertSame('0123', FileNumber::format(123));
        $this->assertSame('', FileNumber::format(null));

        // Default drawer holds 100 files.
        $this->assertSame(1, FileNumber::drawer(1));
        $this->assertSame(1, FileNumber::drawer(100));
        $this->assertSame(2, FileNumber::drawer(101));

        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'fileDrawerSize' => 50]]);

        $this->assertSame(2, FileNumber::drawer(51));
    }
}
```

In `tests/Feature/PlayerMembershipIdTest.php`, **replace** `it_regenerates_membership_id_when_join_year_changes` with:

```php
    #[Test]
    public function it_keeps_the_membership_id_when_the_join_year_changes(): void
    {
        // The id is printed on the member card and written on the paper folder:
        // correcting the join year must not renumber the member.
        $player = $this->makePlayer(2024);
        $original = $player->membership_id;

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), [
                'firstname' => 'Test',
                'join_year' => 2025,
            ])
            ->assertRedirect();

        $player->refresh();
        $this->assertSame($original, $player->membership_id);
        $this->assertSame(2025, $player->join_year);
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan config:clear; php artisan test --filter="PlayerFileNumberTest|PlayerMembershipIdTest"`

Expected: FAIL. `PlayerFileNumberTest` fails on the missing `App\Services\Player\FileNumber`, and `it_keeps_the_membership_id_when_the_join_year_changes` fails because the id is still regenerated (it starts with `2025`, not the original `2024…`).

- [ ] **Step 3: The column and the backfill**

Create `database/migrations/2026_09_23_100002_add_file_number_to_players.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number written on a player's paper folder. One club-wide sequence,
     * assigned once and never reused: the cabinet is sorted by it, so a number
     * that moved would send someone to the wrong drawer.
     *
     * Existing players are numbered in the order they joined, so the oldest
     * members sit at the front of the first drawer.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('file_number')->nullable()->unique()->after('membership_id');
        });

        $next = 1;

        $rows = DB::table('players')
            ->select('id')
            ->orderByRaw('join_year is null, join_year, membership_id, id')
            ->get();

        foreach ($rows as $row) {
            DB::table('players')->where('id', $row->id)->update(['file_number' => $next++]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['file_number']);
            $table->dropColumn('file_number');
        });
    }
};
```

- [ ] **Step 4: The allocator**

Create `app/Services/Player/FileNumber.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\Player;
use App\Models\WebsiteConfig;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The permanent number on a player's paper folder.
 *
 * One club-wide sequence rather than a per-year or per-category one: a
 * category changes every season, and a file that has to move every season is
 * a file that gets lost. The number is allocated once, never reused, and the
 * cabinet is sorted by it.
 */
final class FileNumber
{
    private const DEFAULT_DRAWER_SIZE = 100;

    public static function next(): int
    {
        return (int) Player::query()->max('file_number') + 1;
    }

    /**
     * Give a player their number. Does nothing when they already have one —
     * re-registering or editing must never renumber a folder.
     */
    public static function assign(Player $player): int
    {
        if ($player->file_number !== null) {
            return (int) $player->file_number;
        }

        // The unique index is the real guard; two registrations at the same
        // moment both read the same max, and the loser simply takes the next.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::next();

            try {
                $player->forceFill(['file_number' => $candidate])->save();

                return $candidate;
            } catch (QueryException $exception) {
                if (! self::isDuplicate($exception)) {
                    throw $exception;
                }

                $player->file_number = null;
            }
        }

        throw new RuntimeException('Could not allocate a file number after 5 attempts.');
    }

    /** Zero-padded for the folder label and the screen; empty when unassigned. */
    public static function format(?int $number): string
    {
        return $number === null ? '' : str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public static function drawerSize(): int
    {
        $size = (int) (WebsiteConfig::singleton()->settings['fileDrawerSize'] ?? self::DEFAULT_DRAWER_SIZE);

        return ($size >= 10 && $size <= 1000) ? $size : self::DEFAULT_DRAWER_SIZE;
    }

    /** Which drawer holds this file: 1-100 is drawer 1, 101-200 drawer 2, and so on. */
    public static function drawer(int $number, ?int $size = null): int
    {
        return (int) ceil($number / ($size ?? self::drawerSize()));
    }

    private static function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
```

- [ ] **Step 5: Assign on registration, and freeze the membership ID**

In `app/Services/Player/RegisterPlayerService.php`, right after `$player = Player::query()->create($attributes);`, add:

```php
            // The folder number is allocated once, inside the same transaction
            // that creates the member, so a failed registration leaves no gap.
            FileNumber::assign($player);
```

(no new import is needed — `FileNumber` lives in the same namespace.)

In `app/Http/Controllers/PlayerController.php`, **delete** this block from `update()`:

```php
        // Membership id encodes the enrollment year (YYYYNNNNN). If the year changes,
        // regenerate the id for the new year so the two stay consistent.
        if (array_key_exists('join_year', $validated)
            && (int) $validated['join_year'] !== (int) $player->join_year) {
            $validated['membership_id'] = MembershipNumber::generateUnique((int) $validated['join_year']);
        }
```

and put this comment in its place, so the next reader knows it was deliberate:

```php
        // The membership id is NOT regenerated when the join year changes: it is
        // printed on the member card and written on the paper folder. The year it
        // encodes is the year the member was first enrolled, which never changes.
```

If `MembershipNumber` is now unused in the file, leave the import — `nextSequenceByYear()` still calls it (`PlayerController.php:233`). Confirm with `grep -n "MembershipNumber" app/Http/Controllers/PlayerController.php`.

In `app/Models/Player.php`, add `'file_number',` to `$fillable` right after `'membership_id',`.

- [ ] **Step 6: Find a player by their file number**

In `app/Models/Player.php`, inside `scopeSearch`, replace the inner per-token closure with one that also matches the file number:

```php
        $query->where(function (Builder $outer) use ($tokens, $columns) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $inner) use ($token, $columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', '%'.$token.'%');
                    }

                    // A folder number, typed with or without its leading zeros.
                    if (ctype_digit((string) $token)) {
                        $inner->orWhere('file_number', (int) ltrim((string) $token, '0'));
                    }
                });
            }
        });
```

Update the method's docblock to mention the file number alongside the name columns.

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerFileNumberTest|PlayerMembershipIdTest|PlayerSearchTest|MembershipNumberTest|Services"`

Expected: PASS.

- [ ] **Step 8: Full suite and commit**

Run: `php artisan test --compact` — expected: PASS (baseline before this task + your new tests).

```bash
php vendor/bin/pint app/Services/Player/FileNumber.php database/migrations/2026_09_23_100002_add_file_number_to_players.php app/Models/Player.php app/Services/Player/RegisterPlayerService.php app/Http/Controllers/PlayerController.php tests/Feature/PlayerFileNumberTest.php tests/Feature/PlayerMembershipIdTest.php
git add app/Services/Player/FileNumber.php database/migrations/2026_09_23_100002_add_file_number_to_players.php app/Models/Player.php app/Services/Player/RegisterPlayerService.php app/Http/Controllers/PlayerController.php tests/Feature/PlayerFileNumberTest.php tests/Feature/PlayerMembershipIdTest.php
git commit -m "feat(players): permanent file number, frozen membership id"
```

---

### Task 3: Both numbers on screen and in the export

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php` (`export`)
- Modify: `resources/js/Pages/Players/Index.vue` (column + search hint)
- Modify: `resources/js/Pages/Players/Show.vue` (info card — **BOM file**)
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue` (read-only file number on edit)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/PlayerFileNumberTest.php` (append two cases)

**Interfaces:**
- Consumes `file_number` (Task 2) and `FileNumber::format()`/`drawer()`.
- Produces: the players list, player page, edit form and CSV export all show the file number; `player.file_number` reaches the pages as a plain integer, formatted client-side by a shared helper.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PlayerFileNumberTest.php`:

```php
    #[Test]
    public function the_export_carries_the_file_number(): void
    {
        $this->register('Amine');

        $csv = $this->actingAs($this->admin())->get(route('players.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('File number', $csv);
        $this->assertStringContainsString('0001', $csv);
    }

    #[Test]
    public function the_player_page_receives_the_file_number(): void
    {
        $player = $this->register('Amine');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['player']['file_number']);
    }
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `php artisan test --filter=PlayerFileNumberTest`

Expected: FAIL — the export has no `File number` header. (The props case may already pass, since `file_number` is a column on the serialized model; keep it as a regression guard.)

- [ ] **Step 3: Export column**

In `app/Http/Controllers/PlayerController.php` `export()`, add the file number as the second column. In the row mapping, right after the membership id, insert:

```php
            \App\Services\Player\FileNumber::format($player->file_number),
```

and in `$headers`, insert `'File number',` right after `'Membership ID'` (keep the header spelling used by the surrounding array).

- [ ] **Step 4: UI keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "file_number": { "en": "File number", "fr": "N° de dossier", "ar": "رقم الملف" },
    "drawer": { "en": "Drawer", "fr": "Tiroir", "ar": "الدرج" },
    "file_number_assigned_on_save": { "en": "Assigned when the member is saved", "fr": "Attribué à l'enregistrement du membre", "ar": "يُمنح عند حفظ العضو" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

- [ ] **Step 5: A shared formatter for the screens**

Create `resources/js/lib/fileNumber.js`:

```js
/**
 * The folder number as it is written on paper: four digits, zero-padded.
 * Kept in one place so the list, the player page and the print screens cannot
 * drift apart.
 */
export function formatFileNumber(number) {
    if (number === null || number === undefined || number === '') return '—';
    return String(number).padStart(4, '0');
}

/** Which drawer holds it. `size` comes from the club setting. */
export function fileDrawer(number, size = 100) {
    if (!number) return null;
    return Math.ceil(Number(number) / (Number(size) || 100));
}
```

- [ ] **Step 6: Players list column**

In `resources/js/Pages/Players/Index.vue`:

Add the import next to the others:

```js
import { formatFileNumber } from '@/lib/fileNumber';
```

In the table header, after the membership-ID `<th>`, add:

```html
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('file_number') }}</th>
```

In the body, after the membership-ID `<td>` (`{{ player.membership_id }}`), add:

```html
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">{{ formatFileNumber(player.file_number) }}</td>
```

- [ ] **Step 7: Player page and the edit form**

In `resources/js/Pages/Players/Show.vue` (BOM file — targeted edits only), add the import:

```js
import { formatFileNumber } from '@/lib/fileNumber';
```

In the personal-info card, directly after the `<dt>/<dd>` pair that shows the membership ID, add:

```html
                            <div>
                                <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('file_number') }}</dt>
                                <dd class="font-mono text-sm text-slate-900 dark:text-slate-100">{{ formatFileNumber(player.file_number) }}</dd>
                            </div>
```

(The page is restructured in Task 13; this keeps the number visible in the meantime.)

In `resources/js/Pages/Players/Partials/PlayerForm.vue`, directly after the read-only membership-id preview field, add:

```html
                <div>
                    <InputLabel :value="t('file_number')" />
                    <div class="mt-1 flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200">
                        {{ p?.file_number ? formatFileNumber(p.file_number) : '—' }}
                    </div>
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ t('file_number_assigned_on_save') }}</p>
                </div>
```

and the import:

```js
import { formatFileNumber } from '@/lib/fileNumber';
```

(`p` is the existing alias for the `player` prop in this file; confirm its name with `grep -n "const p = " resources/js/Pages/Players/Partials/PlayerForm.vue` and use whatever it is.)

- [ ] **Step 8: Verify and commit**

Run: `php artisan test --filter=PlayerFileNumberTest` (PASS), `npm run build` (success), `node scripts/i18n-check.mjs` (`✓ …`), `head -c 3 resources/js/Pages/Players/Show.vue | od -An -tx1` (`ef bb bf`).

```bash
php vendor/bin/pint app/Http/Controllers/PlayerController.php tests/Feature/PlayerFileNumberTest.php
git add app/Http/Controllers/PlayerController.php resources/js/lib/fileNumber.js resources/js/Pages/Players/Index.vue resources/js/Pages/Players/Show.vue resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/PlayerFileNumberTest.php
git commit -m "feat(players): show the file number on the list, page, form and export"
```

---

### Task 4: The folder label

**Why:** the number only helps if it is on the folder. One label per player, or a sheet of labels for a whole selection, with a QR the office can scan.

**Files:**
- Create: `app/Http/Controllers/PlayerPrintController.php`
- Create: `resources/views/pdf/folder-label.blade.php`
- Modify: `composer.json` / `composer.lock` (via `composer require mpdf/qrcode`)
- Modify: `routes/web.php`, `config/permissions.php`
- Modify: `resources/views/pdf/member-card.blade.php`, `app/Http/Controllers/ReportController.php` (`playerCard`)
- Modify: `resources/js/Pages/Players/Show.vue` (**BOM**), `resources/js/Pages/Players/Index.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/PlayerLabelTest.php`

**Interfaces:**
- Produces routes `players.label` (`GET /players/{player}/label`) and `players.labels` (`GET /players/labels?ids=1,2,3`), both permission `['players','view']`.
- The QR encodes the **membership ID** only — desktop URLs are a local address with a port that changes, so a URL would be useless on a phone.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerLabelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerLabelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname = 'Amine'): Player
    {
        return app(RegisterPlayerService::class)->handle(['firstname' => $firstname, 'join_year' => 2026]);
    }

    #[Test]
    public function a_label_renders_as_a_pdf(): void
    {
        $player = $this->player();

        $response = $this->actingAs($this->admin())->get(route('players.label', $player))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent() ?: $response->getContent());
    }

    #[Test]
    public function a_sheet_of_labels_renders_for_a_selection(): void
    {
        $first = $this->player('Amine');
        $second = $this->player('Yanis');

        $response = $this->actingAs($this->admin())
            ->get(route('players.labels', ['ids' => $first->id.','.$second->id]))
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->streamedContent() ?: $response->getContent());
    }

    #[Test]
    public function a_selection_that_names_no_real_player_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.labels', ['ids' => '999999']))
            ->assertSessionHasErrors('ids');
    }

    #[Test]
    public function the_label_markup_carries_the_numbers_and_the_drawer(): void
    {
        $player = $this->player();
        $player->refresh();

        $html = view('pdf.folder-label', [
            'club' => ['name' => 'IRNB', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null, 'currency' => 'DZD'],
            'players' => collect([$player]),
        ])->render();

        $this->assertStringContainsString('0001', $html);
        $this->assertStringContainsString($player->membership_id, $html);
        $this->assertStringContainsString('type="QR"', $html);
    }

    #[Test]
    public function printing_labels_needs_only_players_view(): void
    {
        $role = Role::factory()->create(['permissions' => ['players' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->get(route('players.label', $this->player()))->assertOk();
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=PlayerLabelTest`

Expected: FAIL with `Route [players.label] not defined`.

- [ ] **Step 3: Install the QR package**

Run: `composer require mpdf/qrcode`

Expected: it installs (pure PHP, no extensions). Confirm with `php -r "var_dump(class_exists('Mpdf\\QrCode\\QrCode'));"` → `bool(true)`.

**Then reapply the NativePHP build patch that composer just reverted** — the `afterPack` hook in `vendor/nativephp/desktop/resources/electron/electron-builder.mjs` that copies `build-support/php-dll/*.dll` next to the bundled `php.exe`. Check whether it is still there:

```bash
grep -n "afterPack" vendor/nativephp/desktop/resources/electron/electron-builder.mjs
```

If it prints nothing, report it in your task report — the final task carries the deploy note, and a desktop build without that patch produces installers that fail to start on a clean PC.

- [ ] **Step 4: The controller**

Create `app/Http/Controllers/PlayerPrintController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the office prints: the label stuck on a paper folder, and the board
 * table pinned next to the cabinet.
 */
class PlayerPrintController extends Controller
{
    public function __construct(private PdfService $pdf) {}

    public function label(Player $player): Response
    {
        return $this->renderLabels(collect([$player->load('category')]), "folder-label-{$player->membership_id}.pdf");
    }

    public function labels(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['required', 'string'],
        ]);

        $ids = collect(explode(',', $validated['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        $players = Player::query()->with('category')->whereIn('id', $ids)->orderBy('file_number')->get();

        if ($players->isEmpty()) {
            return back()->withErrors(['ids' => __('No player matched that selection.')]);
        }

        return $this->renderLabels($players, 'folder-labels.pdf');
    }

    private function renderLabels(Collection $players, string $filename): Response
    {
        $html = view('pdf.folder-label', [
            'club' => $this->club(),
            'players' => $players,
        ])->render();

        return $this->pdf->stream($html, $filename);
    }

    /** The club header block, same shape ReportController uses. */
    private function club(): array
    {
        $config = WebsiteConfig::singleton();
        $locale = app()->getLocale();
        $name = $config->club_name;

        return [
            'name' => is_array($name) ? ($name[$locale] ?? $name['en'] ?? $name['ar'] ?? '') : $name,
            'logo' => $this->mediaFile($config->branding['logo'] ?? null),
            'address' => $config->full_address ?: null,
            'phone' => $config->contact_phone,
            'email' => $config->contact_email,
            'currency' => $config->settings['currencySymbol'] ?? 'DZD',
        ];
    }

    /** mPDF needs a path on disk, never a /media URL. */
    private function mediaFile(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = ltrim(str_replace('/media/', '', Media::path($url)), '/');
        $full = storage_path('app/public/'.$path);

        return is_file($full) ? $full : null;
    }
}
```

- [ ] **Step 5: The label itself**

Create `resources/views/pdf/folder-label.blade.php`:

```blade
@php
    use App\Services\Player\FileNumber;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .label { border: 2px solid #02a85c; border-radius: 8px; padding: 10px; margin-bottom: 10px; width: 100%; }
        .file { font-size: 30px; font-weight: bold; color: #0f172a; line-height: 1; }
        .drawer { font-size: 10px; color: #64748b; }
        .name { font-size: 14px; font-weight: bold; }
        .mid { font-family: monospace; font-size: 12px; color: #334155; }
        .meta { font-size: 10px; color: #64748b; }
        .club { font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    @foreach ($players as $player)
        <table class="label">
            <tr>
                <td style="width:32%; vertical-align:top;">
                    <div class="file">{{ FileNumber::format($player->file_number) }}</div>
                    <div class="drawer">
                        {{ __('Drawer') }} {{ $player->file_number ? FileNumber::drawer($player->file_number) : '—' }}
                    </div>
                </td>
                <td style="vertical-align:top;">
                    <div class="name">{{ $player->fullname }}</div>
                    <div class="mid">{{ $player->membership_id }}</div>
                    <div class="meta">
                        {{ optional($player->category)->localized_name ?: (optional($player->category)->name ?: '—') }}
                        @if ($player->join_year) &middot; {{ $player->join_year }} @endif
                    </div>
                    <div class="club">{{ $club['name'] }}</div>
                </td>
                <td style="width:22%; text-align:center; vertical-align:top;">
                    {{-- The QR carries the membership id, not a link: the desktop app
                         lives on a local address whose port changes every launch. --}}
                    <barcode code="{{ $player->membership_id }}" type="QR" size="0.9" error="M" disableborder="1" />
                </td>
            </tr>
        </table>
        @if (! $loop->last && $loop->iteration % 6 === 0)
            <pagebreak />
        @endif
    @endforeach
</body>
</html>
```

- [ ] **Step 6: Routes, permissions, and the member card**

In `routes/web.php`, next to the other PDF routes (`players.card`), add:

```php
    Route::get('/players/labels', [PlayerPrintController::class, 'labels'])->name('players.labels');
    Route::get('/players/{player}/label', [PlayerPrintController::class, 'label'])->name('players.label');
```

with `use App\Http\Controllers\PlayerPrintController;` among the imports. The static `labels` path is declared **before** the `{player}` one so it is not swallowed by the parameter.

In `config/permissions.php` `overrides`, next to `'players.card' => ['players', 'view'],` add:

```php
        'players.label' => ['players', 'view'],
        'players.labels' => ['players', 'view'],
```

In `resources/views/pdf/member-card.blade.php`, add a file-number row directly after the category row:

```blade
            <tr><td class="label">{{ __('File number') }}</td><td>{{ \App\Services\Player\FileNumber::format($player->file_number) ?: '—' }}</td></tr>
```

Add `"File number"` and `"Drawer"` to `lang/ar.json` and `lang/fr.json` in alphabetical position:
- `lang/fr.json`: `"Drawer": "Tiroir",` and `"File number": "N° de dossier",`
- `lang/ar.json`: `"Drawer": "الدرج",` and `"File number": "رقم الملف",`
- also `"No player matched that selection.": "Aucun joueur ne correspond à cette sélection."` / `"لا يوجد لاعب مطابق لهذا الاختيار."`

- [ ] **Step 7: Print buttons**

Keys — save as `i18n-keys.tmp.json`:

```json
{
    "print_folder_label": { "en": "Folder label", "fr": "Étiquette de dossier", "ar": "ملصق الملف" },
    "print_selected_labels": { "en": "Labels for selection", "fr": "Étiquettes pour la sélection", "ar": "ملصقات للمحدَّدين" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete the file.

In `resources/js/Pages/Players/Show.vue` (BOM), next to the existing member-card link in the actions card, add:

```html
                        <a :href="route('players.label', player.id)" target="_blank" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                            <Icon name="print" /> {{ t('print_folder_label') }}
                        </a>
```

and import `Icon` if the file does not already import it (`grep -n "Components/Icon.vue" resources/js/Pages/Players/Show.vue`).

In `resources/js/Pages/Players/Index.vue`, in the bulk-action bar that appears when rows are selected, add:

```html
                    <a :href="route('players.labels', { ids: selected.join(',') })" target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                        <Icon name="print" /> {{ t('print_selected_labels') }}
                    </a>
```

- [ ] **Step 8: Verify and commit**

Run: `php artisan test --filter="PlayerLabelTest|ReportPdfTest"` (PASS), `php artisan test --compact` (PASS), `npm run build`, `node scripts/i18n-check.mjs`, BOM check on `Players/Show.vue`.

```bash
php vendor/bin/pint app/Http/Controllers/PlayerPrintController.php app/Http/Controllers/ReportController.php routes/web.php config/permissions.php tests/Feature/PlayerLabelTest.php
git add composer.json composer.lock app/Http/Controllers/PlayerPrintController.php resources/views/pdf/folder-label.blade.php resources/views/pdf/member-card.blade.php routes/web.php config/permissions.php lang/ar.json lang/fr.json resources/js/Pages/Players/Show.vue resources/js/Pages/Players/Index.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/PlayerLabelTest.php
git commit -m "feat(players): printable folder labels with a scannable membership id"
```

---

### Task 5: The category board table

**Why:** the list pinned by the cabinet. It changes every season because categories do; the folders never move, so only this sheet is reprinted.

**Files:**
- Modify: `app/Http/Controllers/PlayerPrintController.php` (add `boardTable`)
- Create: `resources/views/pdf/board-table.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`
- Modify: `resources/js/Pages/Players/Index.vue` (print button when a category is selected)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)
- Test: `tests/Feature/BoardTablePdfTest.php`

**Interfaces:**
- Consumes `App\Support\Season` (Task 1) and `FileNumber` (Task 2).
- Produces route `players.board-table` (`GET /players/board-table?category_id=…`), permission `['players','view']`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardTablePdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\User;
use App\Services\Player\RegisterPlayerService;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTablePdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $firstname, string $lastname, ?Category $category): Player
    {
        return app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'join_year' => 2026,
            'category_id' => $category?->id,
        ]);
    }

    #[Test]
    public function the_board_table_renders_for_one_category(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $this->player('Amine', 'Benali', $cadets);

        $response = $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => $cadets->id]))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_lists_only_that_categorys_active_players_in_name_order(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);

        $this->player('Yanis', 'Ziani', $cadets);
        $this->player('Amine', 'Benali', $cadets);
        $this->player('Sami', 'Kaci', $juniors);
        $archived = $this->player('Old', 'Member', $cadets);
        $archived->forceFill(['archived' => true])->save();

        $html = view('pdf.board-table', [
            'club' => ['name' => 'IRNB', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null, 'currency' => 'DZD'],
            'category' => $cadets,
            'season' => Season::current(),
            'players' => Player::query()->where('category_id', $cadets->id)->where('archived', false)
                ->orderBy('lastname')->orderBy('firstname')->get(),
        ])->render();

        $this->assertStringContainsString('Benali', $html);
        $this->assertStringContainsString('Ziani', $html);
        $this->assertStringNotContainsString('Kaci', $html);
        $this->assertStringNotContainsString('Member', $html);
        $this->assertLessThan(strpos($html, 'Ziani'), strpos($html, 'Benali'), 'sorted by last name');
        $this->assertStringContainsString(Season::current()->label(), $html);
    }

    #[Test]
    public function an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.board-table', ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test --filter=BoardTablePdfTest`

Expected: FAIL with `Route [players.board-table] not defined`.

- [ ] **Step 3: The controller method**

Add to `app/Http/Controllers/PlayerPrintController.php` (with `use App\Models\Category;` and `use App\Support\Season;`):

```php
    /**
     * The sheet pinned next to the cabinet: everyone in one category this
     * season, with the folder number to pull. Folders never move — only this
     * list is reprinted when players change category.
     */
    public function boardTable(Request $request): Response
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $category = Category::findOrFail($validated['category_id']);
        $season = isset($validated['season'])
            ? Season::forStartYear((int) $validated['season'])
            : Season::current();

        $players = Player::query()
            ->where('category_id', $category->id)
            ->where('archived', false)
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();

        $html = view('pdf.board-table', [
            'club' => $this->club(),
            'category' => $category,
            'season' => $season,
            'players' => $players,
        ])->render();

        return $this->pdf->stream($html, 'board-table-'.$category->id.'-'.$season->startYear.'.pdf');
    }
```

- [ ] **Step 4: The sheet**

Create `resources/views/pdf/board-table.blade.php`:

```blade
@php
    use App\Services\Player\FileNumber;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 2px; }
        .sub { font-size: 11px; color: #64748b; margin-bottom: 10px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #f1f5f9; text-align: start; padding: 6px; font-size: 11px; color: #334155; }
        table.rows td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
        .num { font-family: monospace; }
        .empty { padding: 20px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $category->localized_name ?: $category->name }}</div>
    <div class="sub">{{ __('Season') }} {{ $season->label() }} &middot; {{ $players->count() }} {{ __('members') }}</div>

    @if ($players->isEmpty())
        <div class="empty">{{ __('No members in this category.') }}</div>
    @else
        <table class="rows">
            <tr>
                <th style="width:8%;">#</th>
                <th>{{ __('Member') }}</th>
                <th style="width:22%;">{{ __('Membership ID') }}</th>
                <th style="width:15%;">{{ __('File number') }}</th>
                <th style="width:12%;">{{ __('Drawer') }}</th>
            </tr>
            @foreach ($players as $player)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $player->fullname }}</td>
                    <td class="num">{{ $player->membership_id }}</td>
                    <td class="num">{{ FileNumber::format($player->file_number) ?: '—' }}</td>
                    <td class="num">{{ $player->file_number ? FileNumber::drawer($player->file_number) : '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
```

Add to `lang/ar.json` and `lang/fr.json`, in alphabetical position:
- `"Members": …` is not needed; the strings used are `"Season"`, `"members"`, `"No members in this category."`, `"Membership ID"`, `"Member"`.
- `lang/fr.json`: `"No members in this category.": "Aucun membre dans cette catégorie.",` · `"Season": "Saison",` · `"members": "membres",` · `"Membership ID": "N° d'adhérent",`
- `lang/ar.json`: `"No members in this category.": "لا يوجد أعضاء في هذه الفئة.",` · `"Season": "الموسم",` · `"members": "عضو",` · `"Membership ID": "رقم العضوية",`

(If a key already exists in those files, leave the existing value.)

- [ ] **Step 5: Route, permission, button**

`routes/web.php`, next to the label routes:

```php
    Route::get('/players/board-table', [PlayerPrintController::class, 'boardTable'])->name('players.board-table');
```

`config/permissions.php` overrides:

```php
        'players.board-table' => ['players', 'view'],
```

Keys — save as `i18n-keys.tmp.json`:

```json
{
    "print_board_table": { "en": "Board table", "fr": "Tableau par catégorie", "ar": "جدول الفئة" },
    "print_board_table_hint": { "en": "Pick a category first", "fr": "Choisissez d'abord une catégorie", "ar": "اختر فئة أولاً" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

In `resources/js/Pages/Players/Index.vue`, next to the export link in the header, add:

```html
                    <a v-if="categoryFilter" :href="route('players.board-table', { category_id: categoryFilter })" target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                        <Icon name="print" /> {{ t('print_board_table') }}
                    </a>
```

- [ ] **Step 6: Verify and commit**

Run: `php artisan test --filter="BoardTablePdfTest|PlayerLabelTest"` (PASS), `php artisan test --compact` (PASS), `npm run build`, `node scripts/i18n-check.mjs`.

```bash
php vendor/bin/pint app/Http/Controllers/PlayerPrintController.php routes/web.php config/permissions.php tests/Feature/BoardTablePdfTest.php
git add app/Http/Controllers/PlayerPrintController.php resources/views/pdf/board-table.blade.php routes/web.php config/permissions.php lang/ar.json lang/fr.json resources/js/Pages/Players/Index.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/BoardTablePdfTest.php
git commit -m "feat(players): printable category board table for the season"
```

---

### Task 6: The 58 official wilayas as reference data

**Why:** today the list is a JSON file read straight off disk, with typos (`Se9tif`, `Saefda`, `Ghardaefa`, `Tbessa`), no French/Arabic split and no code. The database table exists but nothing reads it.

**Files:**
- Create: `database/data/algeria_wilayas_official.php`
- Create: `database/migrations/2026_09_23_100003_official_wilayas.php`
- Modify: `app/Models/CountryState.php`
- Modify: `database/seeders/CountrySeeder.php` (comment only — see step 5)
- Test: `tests/Feature/WilayaDataTest.php`

**Interfaces:**
- Produces `country_states.code` (char 2, `'01'`–`'58'`), `name_fr`, `name_ar`; `name` holds the official French name.
- `CountryState` uses `HasLocalizedName` and appends `localized_name`.
- Produces `database/data/algeria_wilayas_official.php` returning `['01' => ['fr' => 'Adrar', 'ar' => 'أدرار'], …]`, read by the migration and available to anything else that needs the canonical list.

**⚠️ Verify this list with the club before merging.** It is the official 58 (the 10 southern wilayas promoted in 2019 included). Any spelling the club uses on its own paperwork wins over this file.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WilayaDataTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CountryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WilayaDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_fifty_eight_wilayas_exist_with_a_two_digit_code(): void
    {
        $states = CountryState::query()->orderBy('code')->get();

        $this->assertCount(58, $states);
        $this->assertSame('01', $states->first()->code);
        $this->assertSame('58', $states->last()->code);
    }

    #[Test]
    public function the_names_are_the_official_ones_in_both_languages(): void
    {
        $setif = CountryState::query()->where('code', '19')->firstOrFail();

        $this->assertSame('Sétif', $setif->name_fr);
        $this->assertSame('سطيف', $setif->name_ar);
        $this->assertSame('Sétif', $setif->name, 'the base name mirrors the French official name');
    }

    #[Test]
    public function the_old_misspellings_are_gone(): void
    {
        foreach (['Se9tif', 'Saefda', 'Ghardaefa', 'Tbessa'] as $typo) {
            $this->assertSame(0, CountryState::query()->where('name', $typo)->count(), $typo.' survived');
        }
    }

    #[Test]
    public function a_wilaya_reads_in_the_current_language(): void
    {
        $ghardaia = CountryState::query()->where('code', '47')->firstOrFail();

        App::setLocale('fr');
        $this->assertSame('Ghardaïa', $ghardaia->localized_name);

        App::setLocale('ar');
        $this->assertSame('غرداية', $ghardaia->localized_name);
    }

    #[Test]
    public function the_data_file_and_the_table_agree(): void
    {
        $official = require database_path('data/algeria_wilayas_official.php');

        $this->assertCount(58, $official);

        foreach ($official as $code => $names) {
            $state = CountryState::query()->where('code', $code)->first();
            $this->assertNotNull($state, "wilaya {$code} is missing");
            $this->assertSame($names['fr'], $state->name_fr);
            $this->assertSame($names['ar'], $state->name_ar);
        }
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan config:clear; php artisan test --filter=WilayaDataTest`

Expected: FAIL — `country_states` is empty under `RefreshDatabase` (the rows come from a seeder today, not a migration) and there is no `code` column.

- [ ] **Step 3: The official list**

Create `database/data/algeria_wilayas_official.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| The 58 wilayas of Algeria
|--------------------------------------------------------------------------
|
| Keyed by the official wilaya number, with the official French and Arabic
| names. This file is the single source: the migration writes it into
| country_states, and the seeder leaves those rows alone.
|
| Codes 49-58 are the southern wilayas promoted from delegated status.
|
*/

return [
    '01' => ['fr' => 'Adrar', 'ar' => 'أدرار'],
    '02' => ['fr' => 'Chlef', 'ar' => 'الشلف'],
    '03' => ['fr' => 'Laghouat', 'ar' => 'الأغواط'],
    '04' => ['fr' => 'Oum El Bouaghi', 'ar' => 'أم البواقي'],
    '05' => ['fr' => 'Batna', 'ar' => 'باتنة'],
    '06' => ['fr' => 'Béjaïa', 'ar' => 'بجاية'],
    '07' => ['fr' => 'Biskra', 'ar' => 'بسكرة'],
    '08' => ['fr' => 'Béchar', 'ar' => 'بشار'],
    '09' => ['fr' => 'Blida', 'ar' => 'البليدة'],
    '10' => ['fr' => 'Bouira', 'ar' => 'البويرة'],
    '11' => ['fr' => 'Tamanrasset', 'ar' => 'تمنراست'],
    '12' => ['fr' => 'Tébessa', 'ar' => 'تبسة'],
    '13' => ['fr' => 'Tlemcen', 'ar' => 'تلمسان'],
    '14' => ['fr' => 'Tiaret', 'ar' => 'تيارت'],
    '15' => ['fr' => 'Tizi Ouzou', 'ar' => 'تيزي وزو'],
    '16' => ['fr' => 'Alger', 'ar' => 'الجزائر'],
    '17' => ['fr' => 'Djelfa', 'ar' => 'الجلفة'],
    '18' => ['fr' => 'Jijel', 'ar' => 'جيجل'],
    '19' => ['fr' => 'Sétif', 'ar' => 'سطيف'],
    '20' => ['fr' => 'Saïda', 'ar' => 'سعيدة'],
    '21' => ['fr' => 'Skikda', 'ar' => 'سكيكدة'],
    '22' => ['fr' => 'Sidi Bel Abbès', 'ar' => 'سيدي بلعباس'],
    '23' => ['fr' => 'Annaba', 'ar' => 'عنابة'],
    '24' => ['fr' => 'Guelma', 'ar' => 'قالمة'],
    '25' => ['fr' => 'Constantine', 'ar' => 'قسنطينة'],
    '26' => ['fr' => 'Médéa', 'ar' => 'المدية'],
    '27' => ['fr' => 'Mostaganem', 'ar' => 'مستغانم'],
    '28' => ['fr' => "M'Sila", 'ar' => 'المسيلة'],
    '29' => ['fr' => 'Mascara', 'ar' => 'معسكر'],
    '30' => ['fr' => 'Ouargla', 'ar' => 'ورقلة'],
    '31' => ['fr' => 'Oran', 'ar' => 'وهران'],
    '32' => ['fr' => 'El Bayadh', 'ar' => 'البيض'],
    '33' => ['fr' => 'Illizi', 'ar' => 'إليزي'],
    '34' => ['fr' => 'Bordj Bou Arréridj', 'ar' => 'برج بوعريريج'],
    '35' => ['fr' => 'Boumerdès', 'ar' => 'بومرداس'],
    '36' => ['fr' => 'El Tarf', 'ar' => 'الطارف'],
    '37' => ['fr' => 'Tindouf', 'ar' => 'تندوف'],
    '38' => ['fr' => 'Tissemsilt', 'ar' => 'تيسمسيلت'],
    '39' => ['fr' => 'El Oued', 'ar' => 'الوادي'],
    '40' => ['fr' => 'Khenchela', 'ar' => 'خنشلة'],
    '41' => ['fr' => 'Souk Ahras', 'ar' => 'سوق أهراس'],
    '42' => ['fr' => 'Tipaza', 'ar' => 'تيبازة'],
    '43' => ['fr' => 'Mila', 'ar' => 'ميلة'],
    '44' => ['fr' => 'Aïn Defla', 'ar' => 'عين الدفلى'],
    '45' => ['fr' => 'Naâma', 'ar' => 'النعامة'],
    '46' => ['fr' => 'Aïn Témouchent', 'ar' => 'عين تموشنت'],
    '47' => ['fr' => 'Ghardaïa', 'ar' => 'غرداية'],
    '48' => ['fr' => 'Relizane', 'ar' => 'غليزان'],
    '49' => ['fr' => 'Timimoun', 'ar' => 'تيميمون'],
    '50' => ['fr' => 'Bordj Badji Mokhtar', 'ar' => 'برج باجي مختار'],
    '51' => ['fr' => 'Ouled Djellal', 'ar' => 'أولاد جلال'],
    '52' => ['fr' => 'Béni Abbès', 'ar' => 'بني عباس'],
    '53' => ['fr' => 'In Salah', 'ar' => 'عين صالح'],
    '54' => ['fr' => 'In Guezzam', 'ar' => 'عين قزام'],
    '55' => ['fr' => 'Touggourt', 'ar' => 'تقرت'],
    '56' => ['fr' => 'Djanet', 'ar' => 'جانت'],
    '57' => ['fr' => "El M'Ghair", 'ar' => 'المغير'],
    '58' => ['fr' => 'El Meniaa', 'ar' => 'المنيعة'],
];
```

- [ ] **Step 4: The migration that owns the data**

Create `database/migrations/2026_09_23_100003_official_wilayas.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 58 wilayas, with their official French and Arabic names.
     *
     * They arrive through a migration rather than a seeder because the desktop
     * build runs migrate on boot and never runs seeders — an installed copy
     * would otherwise keep the old misspelt names forever.
     */
    public function up(): void
    {
        Schema::table('country_states', function (Blueprint $table) {
            $table->char('code', 2)->nullable()->after('external_id');
            $table->string('name_fr')->nullable()->after('name');
            $table->string('name_ar')->nullable()->after('name_fr');
        });

        $official = require database_path('data/algeria_wilayas_official.php');
        $now = now();

        $countryId = DB::table('countries')->where('code', 'DZ')->value('id');

        if ($countryId === null) {
            $countryId = DB::table('countries')->insertGetId([
                'name' => 'Algeria',
                'code' => 'DZ',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($official as $code => $names) {
            $number = (int) $code;

            $existing = DB::table('country_states')
                ->where('country_id', $countryId)
                ->where('external_id', $number)
                ->first();

            $values = [
                'code' => $code,
                'name' => $names['fr'],
                'name_fr' => $names['fr'],
                'name_ar' => $names['ar'],
                'ar_name' => $names['ar'],
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('country_states')->where('id', $existing->id)->update($values);

                continue;
            }

            DB::table('country_states')->insert($values + [
                'country_id' => $countryId,
                'external_id' => $number,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('country_states', function (Blueprint $table) {
            $table->dropColumn(['code', 'name_fr', 'name_ar']);
        });
    }
};
```

- [ ] **Step 5: The model, and a note on the seeder**

Replace `app/Models/CountryState.php`'s class body head with:

```php
class CountryState extends Model
{
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'country_id',
        'external_id',
        'code',
        'name',
        'name_fr',
        'name_ar',
        'ar_name',
        'longitude',
        'latitude',
    ];

    protected $appends = [
        'localized_name',
    ];
```

and add `use App\Models\Concerns\HasLocalizedName;` to the imports. Leave the relations untouched.

In `database/seeders/CountrySeeder.php`, add this comment above the `foreach ($data['states'] …)` loop, so nobody re-adds the misspelt names later:

```php
        // The wilaya rows and their official names come from the migration
        // (database/data/algeria_wilayas_official.php). firstOrCreate below
        // therefore finds them and only fills in the communes.
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="WilayaDataTest|PlayerFormPropsTest"`

Expected: `WilayaDataTest` PASS. `PlayerFormPropsTest` still passes — it reads the JSON-backed prop, which Task 7 switches over.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint database/data/algeria_wilayas_official.php database/migrations/2026_09_23_100003_official_wilayas.php app/Models/CountryState.php database/seeders/CountrySeeder.php tests/Feature/WilayaDataTest.php
git add database/data/algeria_wilayas_official.php database/migrations/2026_09_23_100003_official_wilayas.php app/Models/CountryState.php database/seeders/CountrySeeder.php tests/Feature/WilayaDataTest.php
git commit -m "feat(geo): the 58 official wilayas as migration-owned reference data"
```

---

### Task 7: Players point at a wilaya instead of holding its name

**Why:** `players.state` is free text holding an English-ish name. The legacy rows say `GHARDAIA`, which matches nothing. A relation is resolved by id, and the label is translated at render time.

**Files:**
- Create: `database/migrations/2026_09_23_100004_add_wilaya_id_to_players.php`
- Modify: `app/Models/Player.php`
- Modify: `app/Http/Controllers/PlayerController.php` (`algeriaGeo`, filters, export)
- Modify: `app/Http/Requests/Player/StorePlayerRequest.php`, `UpdatePlayerRequest.php`
- Modify: `app/Http/Controllers/PlayerImportController.php`
- Test: `tests/Feature/PlayerWilayaTest.php`

**Interfaces:**
- Produces `players.wilaya_id` (FK `country_states`, nullable, `nullOnDelete`) and `Player::wilaya()`.
- The `wilayas` prop keeps `id`, `name`, `ar_name` (pinned by `PlayerFormPropsTest`) and gains `code` and `localized_name`. **`id` is the `country_states` row id** — it is what `wilaya_id` stores — and `communes` is re-keyed to those ids.
- Import gains a `wilaya` column **appended last**, matching a code, a French name, an Arabic name or a legacy spelling.
- `players.index` accepts `wilaya_id`; the partial-reload `only` list is unchanged (no new stat).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerWilayaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CountryState;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerWilayaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function wilaya(string $code): CountryState
    {
        return CountryState::query()->where('code', $code)->firstOrFail();
    }

    #[Test]
    public function the_form_offers_every_wilaya_with_its_code_and_both_names(): void
    {
        $props = $this->actingAs($this->admin())->get(route('players.create'))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(58, $props['wilayas']);

        $ghardaia = collect($props['wilayas'])->firstWhere('code', '47');
        $this->assertSame('Ghardaïa', $ghardaia['name']);
        $this->assertSame('غرداية', $ghardaia['ar_name']);
        $this->assertSame($this->wilaya('47')->id, $ghardaia['id']);

        // Communes are keyed by the same id the form submits.
        $this->assertArrayHasKey($this->wilaya('47')->id, $props['communes']);
    }

    #[Test]
    public function a_player_is_saved_against_a_wilaya_id(): void
    {
        $ghardaia = $this->wilaya('47');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'wilaya_id' => $ghardaia->id,
            'city' => 'Metlili',
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame($ghardaia->id, $player->wilaya_id);
        $this->assertSame('Ghardaïa', $player->wilaya->name_fr);
    }

    #[Test]
    public function the_list_filters_by_wilaya(): void
    {
        $ghardaia = $this->wilaya('47');
        $alger = $this->wilaya('16');

        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'wilaya_id' => $ghardaia->id]);
        Player::create(['membership_id' => '202600002', 'firstname' => 'Yanis', 'wilaya_id' => $alger->id]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['wilaya_id' => $ghardaia->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['players']['data']);
        $this->assertSame('Amine', $props['players']['data'][0]['firstname']);
    }

    #[Test]
    public function the_import_resolves_a_wilaya_by_code_name_or_arabic_name(): void
    {
        $header = implode(',', array_fill(0, 20, 'h'));
        $row = function (string $wilaya, string $membership) {
            $cells = array_fill(0, 20, '');
            $cells[0] = 'Amine'.$membership;
            $cells[19] = $wilaya;

            return implode(',', $cells);
        };

        $csv = "\xEF\xBB\xBF".implode("\n", [$header, $row('47', 'a'), $row('Ghardaïa', 'b'), $row('غرداية', 'c')])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $expected = $this->wilaya('47')->id;
        $this->assertSame([$expected, $expected, $expected], Player::query()->orderBy('id')->pluck('wilaya_id')->all());
    }

    #[Test]
    public function the_export_names_the_wilaya_in_the_users_language(): void
    {
        Player::create([
            'membership_id' => '202600003', 'firstname' => 'Amine',
            'wilaya_id' => $this->wilaya('47')->id,
        ]);

        $csv = $this->actingAs($this->admin())->get(route('players.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Ghardaïa', $csv);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test --filter=PlayerWilayaTest`

Expected: FAIL — the `wilayas` prop has no `code`, and `wilaya_id` does not exist.

- [ ] **Step 3: The column and the backfill**

Create `database/migrations/2026_09_23_100004_add_wilaya_id_to_players.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Players point at a wilaya row instead of holding its name as text.
     *
     * The old `state` column stays for one release: a value that cannot be
     * matched (a typo, a commune written in the wilaya box) is left there
     * rather than thrown away, so nothing is lost silently.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('wilaya_id')->nullable()->after('state')
                ->constrained('country_states')->nullOnDelete();
        });

        $byKey = [];

        foreach (DB::table('country_states')->whereNotNull('code')->get() as $state) {
            foreach ([$state->code, $state->name, $state->name_fr, $state->name_ar, $state->ar_name] as $value) {
                $key = self::normalise((string) $value);
                if ($key !== '') {
                    $byKey[$key] = $state->id;
                }
            }
        }

        // The spellings that reached this database before the official list did.
        foreach (['se9tif' => '19', 'saefda' => '20', 'ghardaefa' => '47', 'tbessa' => '12'] as $typo => $code) {
            $id = DB::table('country_states')->where('code', $code)->value('id');
            if ($id !== null) {
                $byKey[$typo] = $id;
            }
        }

        foreach (DB::table('players')->whereNotNull('state')->select('id', 'state')->get() as $player) {
            $id = $byKey[self::normalise((string) $player->state)] ?? null;

            if ($id !== null) {
                DB::table('players')->where('id', $player->id)->update(['wilaya_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wilaya_id');
        });
    }

    /** Lower-cased, accent-free, letters and digits only — so "GHARDAIA" meets "Ghardaïa". */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        $value = strtr($value, $accents);

        return (string) preg_replace('/[^a-z0-9\p{Arabic}]/u', '', $value);
    }
};
```

- [ ] **Step 4: Model, validation, props, filter, export**

`app/Models/Player.php`:
- add `'wilaya_id',` to `$fillable` right after `'state',`
- add the relation next to `category()`:

```php
    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(CountryState::class, 'wilaya_id');
    }
```

`StorePlayerRequest` and `UpdatePlayerRequest`: add, right after the `state` rule:

```php
            'wilaya_id' => ['nullable', 'integer', 'exists:country_states,id'],
```

`app/Http/Controllers/PlayerController.php`:

Replace `algeriaGeo()` with a version that reads the table and re-keys the commune lists:

```php
    /**
     * The wilaya list for the player form, from the table the migration owns,
     * plus the commune lists keyed by the SAME id the form submits.
     */
    private function algeriaGeo(): array
    {
        $states = CountryState::query()->orderBy('code')->get();

        $wilayas = $states->map(fn (CountryState $state) => [
            'id' => $state->id,
            'code' => $state->code,
            'name' => $state->name_fr ?: $state->name,
            'ar_name' => $state->name_ar ?: $state->ar_name,
            'localized_name' => $state->localized_name,
        ])->values()->all();

        // The commune file is keyed by the official wilaya number; the form works
        // in row ids, so translate the keys once here.
        $data = json_decode(File::get(database_path('seeders/algeria_wilayas.json')), true);
        $communes = [];

        foreach ($states as $state) {
            $list = $data['communes'][(string) $state->external_id] ?? [];
            if ($list !== []) {
                $communes[$state->id] = $list;
            }
        }

        return ['wilayas' => $wilayas, 'communes' => $communes];
    }
```

Add `use App\Models\CountryState;` to the imports.

In `index()`, add `'wilaya_id'` to the `filters` prop list, and in `applyPlayerFilters()` add, next to the other simple filters:

```php
        if ($request->filled('wilaya_id')) {
            $query->where('wilaya_id', $request->input('wilaya_id'));
        }
```

In `index()`'s eager loads (`->with([...])`) add `'wilaya'`, and in `export()` add `'wilaya'` to the eager loads, a `'Wilaya'` header after `'File number'`, and this cell in the row mapping:

```php
            $player->wilaya?->localized_name,
```

- [ ] **Step 5: Import column**

In `app/Http/Controllers/PlayerImportController.php`, append to `COLUMNS` (last entry, so files made from the old 19-column template still import):

```php
        // Appended last on purpose: older files simply have no cell here.
        ['wilaya', 'الولاية (الرمز أو الاسم)', '47'],
```

Build the lookup once next to the other lookups in `store()`:

```php
        $wilayas = [];
        foreach (CountryState::query()->get() as $state) {
            foreach ([$state->code, (string) $state->external_id, $state->name, $state->name_fr, $state->name_ar, $state->ar_name] as $value) {
                $key = mb_strtolower(trim((string) $value));
                if ($key !== '') {
                    $wilayas[$key] = $state->id;
                }
            }
        }
```

and in the attribute array being built for each row, add:

```php
                'wilaya_id' => $wilayas[mb_strtolower(trim((string) $data['wilaya']))] ?? null,
```

Add `use App\Models\CountryState;` to the imports. Leave the existing `state`/`city` handling as it is — the text column stays for one release.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerWilayaTest|PlayerFormPropsTest|PlayerImportTest|ListFilterPartialReloadTest|PlayerListActionsTest"`

Expected: PASS. `PlayerFormPropsTest` still asserts 58 wilayas with `id`/`name`/`ar_name` — the new prop keeps all three.

- [ ] **Step 7: Full suite and commit**

Run: `php artisan test --compact` (PASS).

```bash
php vendor/bin/pint database/migrations/2026_09_23_100004_add_wilaya_id_to_players.php app/Models/Player.php app/Http/Controllers/PlayerController.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerImportController.php tests/Feature/PlayerWilayaTest.php
git add database/migrations/2026_09_23_100004_add_wilaya_id_to_players.php app/Models/Player.php app/Http/Controllers/PlayerController.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerImportController.php tests/Feature/PlayerWilayaTest.php
git commit -m "feat(players): store the wilaya by id, matched from the official list"
```

---

### Task 8: The wilaya on screen

**Files:**
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `resources/js/Pages/Players/Index.vue` (filter)
- Modify: `resources/js/Pages/Players/Show.vue` (**BOM**)
- Modify: `resources/js/Pages/Players/Create.vue`, `Edit.vue` (no prop change — confirm only)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes the `wilayas` (`{id, code, name, ar_name, localized_name}`) and `communes` (keyed by wilaya row id) props from Task 7.
- The form submits `wilaya_id`; it no longer sends `state`.

- [ ] **Step 1: Keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "all_wilayas": { "en": "All wilayas", "fr": "Toutes les wilayas", "ar": "كل الولايات" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

- [ ] **Step 2: The form**

In `resources/js/Pages/Players/Partials/PlayerForm.vue`:

In `useForm`, replace `state: p.state || '',` with:

```js
    wilaya_id: p.wilaya_id || '',
```

Replace the whole wilaya→city computed block with:

```js
// --- Wilaya -> city dependency ---
// The wilaya is chosen by id; the label follows the app language, and the
// code is searchable so "47" finds Ghardaïa.
const wilayaOptions = computed(() => props.wilayas.map((w) => ({
    value: w.id,
    label: `${w.code} · ${w.localized_name || w.name}`,
    keywords: [w.name, w.ar_name, w.code].filter(Boolean).join(' '),
})));
const cityList = computed(() => (form.wilaya_id ? (props.communes[form.wilaya_id] || []) : []));
const hasCityList = computed(() => cityList.value.length > 0);
const cityOptions = computed(() => {
    const opts = cityList.value.map((c) => ({ value: c, label: c }));
    if (form.city && !opts.some((o) => o.value === form.city)) opts.unshift({ value: form.city, label: form.city });
    return opts;
});
// reset city when wilaya changes (fires only on change, not initial mount)
watch(() => form.wilaya_id, () => { form.city = ''; });
```

In the template, replace the wilaya field with:

```html
                <div>
                    <InputLabel :value="t('state')" />
                    <SearchableSelect v-model="form.wilaya_id" :options="wilayaOptions" :placeholder="t('search_wilaya')" />
                    <InputError :message="form.errors.wilaya_id" class="mt-1" />
                </div>
```

In `submit()`'s transform, replace `state: data.state || null,` with:

```js
        wilaya_id: data.wilaya_id || null,
```

- [ ] **Step 3: The list filter**

In `resources/js/Pages/Players/Index.vue`:

Add the prop:

```js
    wilayas: { type: Array, default: () => [] },
```

Add the ref next to the other filters:

```js
const wilayaFilter = ref(props.filters?.wilaya_id || '');
```

Add it to the `useListFilters` params object:

```js
    wilaya_id: wilayaFilter.value,
```

(Leave the `only` array untouched — this filter adds no new stat.)

Add the control next to the other filter selects:

```html
                <select v-model="wilayaFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all_wilayas') }}</option>
                    <option v-for="w in wilayas" :key="w.id" :value="w.id">{{ w.code }} · {{ w.localized_name || w.name }}</option>
                </select>
```

In `app/Http/Controllers/PlayerController.php` `index()`, pass the list as a closure prop so filter reloads skip it, next to `'positions' => fn () => …`:

```php
            'wilayas' => fn () => CountryState::query()->orderBy('code')
                ->get(['id', 'code', 'name', 'name_fr', 'name_ar'])
                ->map(fn (CountryState $state) => [
                    'id' => $state->id,
                    'code' => $state->code,
                    'name' => $state->name_fr ?: $state->name,
                    'localized_name' => $state->localized_name,
                ]),
```

- [ ] **Step 4: The player page**

In `resources/js/Pages/Players/Show.vue` (BOM), replace the city/state line with:

```html
                            <dd class="text-sm">{{ [player.city, player.wilaya?.localized_name || player.wilaya?.name].filter(Boolean).join(', ') || '-' }}</dd>
```

and add `'wilaya'` to the `show()` eager loads in `PlayerController` (next to `'category'`).

- [ ] **Step 5: Verify and commit**

Run: `npm run build`, `node scripts/i18n-check.mjs`, `php artisan test --filter="PlayerWilayaTest|ListFilterPartialReloadTest"` (PASS), BOM check.

```bash
php vendor/bin/pint app/Http/Controllers/PlayerController.php
git add app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Index.vue resources/js/Pages/Players/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(players): pick and filter by wilaya, shown in the active language"
```

---

### Task 9: A main position plus other positions (backend)

**Why:** a player covers more than one position. The main one stays where it is, so stats, bulk edit, import and the member card keep working unchanged.

**Files:**
- Create: `database/migrations/2026_09_23_100005_create_player_other_positions.php`
- Modify: `app/Models/Player.php`, `app/Models/Position.php`
- Modify: `app/Http/Requests/Player/StorePlayerRequest.php`, `UpdatePlayerRequest.php`
- Modify: `app/Http/Controllers/PlayerController.php` (store, update, show/index eager loads, filter, export)
- Modify: `app/Http/Controllers/PlayerImportController.php`
- Test: `tests/Feature/PlayerPositionsTest.php`

**Interfaces:**
- Produces pivot `player_other_positions` (`player_id`, `position_id`, composite primary key, cascade on delete) and `Player::otherPositions()`.
- Requests accept `other_position_ids` (array of position ids). The main position may not appear in it.
- "Plays X" (`position_id` filter) matches the main position **or** any other position.
- Import gains an `other_positions` column **appended last** (comma-separated abbreviations or names).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerPositionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPositionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function position(string $abbr, string $name): Position
    {
        return Position::create(['abbreviation' => $abbr, 'name' => $name]);
    }

    #[Test]
    public function a_player_keeps_one_main_position_and_gains_others(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $back = $this->position('LB', 'Arrière gauche');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$wing->id, $back->id],
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame($mid->id, $player->position_id);
        $this->assertSame([$back->id, $wing->id], $player->otherPositions()->orderBy('positions.id')->pluck('positions.id')->sort()->values()->all());
    }

    #[Test]
    public function the_main_position_cannot_be_repeated_among_the_others(): void
    {
        $mid = $this->position('MF', 'Milieu');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$mid->id],
        ])->assertSessionHasErrors('other_position_ids');

        $this->assertSame(0, Player::query()->count());
    }

    #[Test]
    public function editing_replaces_the_other_positions(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $back = $this->position('LB', 'Arrière gauche');

        $player = Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$back->id],
        ])->assertRedirect();

        $this->assertSame([$back->id], $player->fresh()->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function the_filter_finds_a_player_by_any_position_they_play(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $main = Player::create(['membership_id' => '202600002', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $other = Player::create(['membership_id' => '202600003', 'firstname' => 'Yanis', 'position_id' => $mid->id]);
        $other->otherPositions()->sync([$wing->id]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['position_id' => $wing->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(['Yanis'], collect($props['players']['data'])->pluck('firstname')->all());
    }

    #[Test]
    public function the_stats_chart_still_counts_each_player_once_by_main_position(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $player = Player::create(['membership_id' => '202600004', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $props = $this->actingAs($this->admin())->get(route('players.index'))->viewData('page')['props'];

        $this->assertSame(1, collect($props['positionStats'])->sum('count'));
    }

    #[Test]
    public function the_import_reads_other_positions_from_the_last_column(): void
    {
        $this->position('MF', 'Milieu');
        $this->position('WG', 'Ailier');
        $this->position('LB', 'Arrière gauche');

        $cells = array_fill(0, 21, '');
        $cells[0] = 'Amine';
        $cells[12] = 'MF';          // main position, existing column
        $cells[20] = 'WG, LB';      // other positions, appended column
        $csv = "\xEF\xBB\xBF".implode("\n", [implode(',', array_fill(0, 21, 'h')), implode(',', $cells)])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame('MF', $player->position->abbreviation);
        $this->assertSame(['LB', 'WG'], $player->otherPositions()->orderBy('abbreviation')->pluck('abbreviation')->all());
    }

    #[Test]
    public function the_export_carries_both_columns(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $player = Player::create(['membership_id' => '202600005', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $csv = $this->actingAs($this->admin())->get(route('players.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Main position', $csv);
        $this->assertStringContainsString('Other positions', $csv);
        $this->assertStringContainsString('WG', $csv);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test --filter=PlayerPositionsTest`

Expected: FAIL — `Call to undefined method App\Models\Player::otherPositions()`.

- [ ] **Step 3: The pivot**

Create `database/migrations/2026_09_23_100005_create_player_other_positions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The positions a player also covers.
     *
     * The main one stays on players.position_id: the stats chart, bulk edit,
     * the import and the member card all read that single column, and a player
     * has exactly one main position. This table holds only the extras.
     */
    public function up(): void
    {
        Schema::create('player_other_positions', function (Blueprint $table) {
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();

            $table->primary(['player_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_other_positions');
    }
};
```

- [ ] **Step 4: Models and validation**

`app/Models/Player.php` — add next to `position()`:

```php
    /** Positions the player also covers; the main one is position_id and is never in here. */
    public function otherPositions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'player_other_positions');
    }
```

with `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` if the import is missing.

`app/Models/Position.php` — add:

```php
    /** Players who list this position as one of their other positions. */
    public function otherPlayers(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'player_other_positions');
    }
```

with the matching import.

In **both** `StorePlayerRequest` and `UpdatePlayerRequest`, add after the `position_id` rule:

```php
            'other_position_ids' => ['nullable', 'array'],
            'other_position_ids.*' => ['integer', 'exists:positions,id'],
```

and add this method to each class:

```php
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $main = $this->input('position_id');
            $others = (array) $this->input('other_position_ids', []);

            // The main position is already recorded; repeating it would show the
            // same abbreviation twice on the player's card.
            if ($main && in_array((int) $main, array_map('intval', $others), true)) {
                $validator->errors()->add('other_position_ids', __('The main position is already listed.'));
            }
        });
    }
```

Add `"The main position is already listed."` to `lang/ar.json` (`"المركز الرئيسي مُدرَج بالفعل."`) and `lang/fr.json` (`"Le poste principal est déjà indiqué."`), in alphabetical position.

- [ ] **Step 5: Controller wiring**

In `app/Http/Controllers/PlayerController.php`:

`store()` — pull the ids out before the service call and sync after it, exactly like branches:

```php
        $otherPositionIds = $attributes['other_position_ids'] ?? [];
        unset($attributes['other_position_ids']);
```

then after `$player->branches()->sync($branchIds);`:

```php
        $player->otherPositions()->sync($otherPositionIds);
```

`update()` — same shape:

```php
        $otherPositionIds = $validated['other_position_ids'] ?? null;
        unset($validated['other_position_ids']);
```

and after the branches sync:

```php
        if ($otherPositionIds !== null) {
            $player->otherPositions()->sync($otherPositionIds);
        }
```

`index()` and `show()` — add `'otherPositions'` to the eager loads.

`applyPlayerFilters()` — replace the position branch with one that matches either:

```php
        if ($request->filled('position_id')) {
            $positionId = (int) $request->input('position_id');

            // "Plays X" means the main position or one of the others.
            $query->where(fn ($q) => $q->where('position_id', $positionId)
                ->orWhereHas('otherPositions', fn ($p) => $p->where('positions.id', $positionId)));
        }
```

`export()` — add `'otherPositions'` to the eager loads, `'Main position', 'Other positions',` to the headers (after the wilaya header), and these two cells to the row mapping:

```php
            $player->position?->abbreviation,
            $player->otherPositions->pluck('abbreviation')->implode(', '),
```

- [ ] **Step 6: Import column**

In `app/Http/Controllers/PlayerImportController.php`, append to `COLUMNS`:

```php
        // Appended last: older files have no cell here.
        ['other_positions', 'مراكز أخرى (مفصولة بفاصلة)', 'WG, LB'],
```

After the player is created in the import loop, resolve and sync them:

```php
            $others = collect(explode(',', (string) ($data['other_positions'] ?? '')))
                ->map(fn ($value) => $this->resolvePosition($positions, trim($value)))
                ->filter()
                ->reject(fn ($id) => (int) $id === (int) ($attributes['position_id'] ?? 0))
                ->unique()
                ->values()
                ->all();

            if ($others !== []) {
                $player->otherPositions()->sync($others);
            }
```

(Use whatever variable already holds the created player in that loop; check with `grep -n "RegisterPlayerService\|->handle(" app/Http/Controllers/PlayerImportController.php`.)

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter="PlayerPositionsTest|PlayerBulkUpdateTest|PlayerStatsFilterTest|PlayerImportTest|PlayerListActionsTest"`

Expected: PASS — the bulk-edit and stats tests still pass because `position_id` is untouched.

- [ ] **Step 8: Full suite and commit**

Run: `php artisan test --compact` (PASS).

```bash
php vendor/bin/pint database/migrations/2026_09_23_100005_create_player_other_positions.php app/Models/Player.php app/Models/Position.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerController.php app/Http/Controllers/PlayerImportController.php tests/Feature/PlayerPositionsTest.php
git add database/migrations/2026_09_23_100005_create_player_other_positions.php app/Models/Player.php app/Models/Position.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerController.php app/Http/Controllers/PlayerImportController.php lang/ar.json lang/fr.json tests/Feature/PlayerPositionsTest.php
git commit -m "feat(players): a main position plus the other positions a player covers"
```

---

### Task 10: Positions on screen

**Files:**
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `resources/js/Pages/Players/Index.vue` (list column)
- Modify: `resources/js/Pages/Players/Show.vue` (**BOM**)
- Modify: `resources/js/Pages/Settings/Positions.vue` (remove the dead `description` field)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes `player.other_positions` (array of Position rows) and submits `other_position_ids`.
- The list shows `MF +2`; the form shows the main select plus removable chips.

- [ ] **Step 1: Keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "main_position": { "en": "Main position", "fr": "Poste principal", "ar": "المركز الرئيسي" },
    "other_positions": { "en": "Other positions", "fr": "Autres postes", "ar": "مراكز أخرى" },
    "add_position": { "en": "Add a position", "fr": "Ajouter un poste", "ar": "إضافة مركز" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

- [ ] **Step 2: The form**

In `resources/js/Pages/Players/Partials/PlayerForm.vue`:

Add to `useForm`, next to `position_id`:

```js
    other_position_ids: (p.other_positions || []).map((pos) => pos.id),
```

Add the chip helpers next to the branch helpers:

```js
// Other positions: same add-picker + chips pattern as branches. The main
// position is never offered here — it is already recorded on its own field.
const otherPositionOptions = computed(() => props.positions
    .filter((pos) => String(pos.id) !== String(form.position_id) && !form.other_position_ids.includes(pos.id))
    .map((pos) => ({ value: pos.id, label: `${pos.abbreviation} - ${pos.name}` })));
const chosenOtherPositions = computed(() => form.other_position_ids
    .map((id) => props.positions.find((pos) => pos.id === id))
    .filter(Boolean));
function addOtherPosition(id) {
    if (id && !form.other_position_ids.includes(id)) form.other_position_ids.push(id);
}
function removeOtherPosition(id) {
    form.other_position_ids = form.other_position_ids.filter((value) => value !== id);
}
// Promoting a position to main drops it from the extras.
watch(() => form.position_id, (id) => { form.other_position_ids = form.other_position_ids.filter((value) => String(value) !== String(id)); });
```

In `submit()`'s transform, add:

```js
        other_position_ids: data.other_position_ids,
```

In the template, relabel the existing position select to `t('main_position')` and add this block directly after it:

```html
                <div>
                    <InputLabel :value="t('other_positions')" />
                    <div v-if="chosenOtherPositions.length" class="mt-1 flex flex-wrap gap-1.5">
                        <span v-for="pos in chosenOtherPositions" :key="pos.id"
                            class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ pos.abbreviation }}
                            <button type="button" @click="removeOtherPosition(pos.id)" class="text-slate-400 hover:text-rose-500">&times;</button>
                        </span>
                    </div>
                    <SearchableSelect v-if="otherPositionOptions.length" :model-value="''" :options="otherPositionOptions"
                        :placeholder="t('add_position')" @update:modelValue="addOtherPosition" />
                    <InputError :message="form.errors.other_position_ids" class="mt-1" />
                </div>
```

- [ ] **Step 3: The list column and the player page**

In `resources/js/Pages/Players/Index.vue`, replace the position cell with:

```html
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                    {{ player.position?.abbreviation || '-' }}
                                    <span v-if="player.other_positions?.length" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                                        :title="player.other_positions.map((p) => p.abbreviation).join(', ')">
                                        +{{ player.other_positions.length }}
                                    </span>
                                </td>
```

In `resources/js/Pages/Players/Show.vue` (BOM), replace the position `<dd>` with:

```html
                            <dd class="text-sm">
                                {{ player.position?.abbreviation || '-' }} {{ player.position?.name || '' }}
                                <span v-if="player.other_positions?.length" class="text-slate-500 dark:text-slate-400">
                                    · {{ player.other_positions.map((p) => p.abbreviation).join(', ') }}
                                </span>
                            </dd>
```

- [ ] **Step 4: Remove the dead settings field**

In `resources/js/Pages/Settings/Positions.vue`, remove `description` from the `useForm` initial object, from the add/edit payloads and from the template. The server has never had that column and silently drops it.

- [ ] **Step 5: Verify and commit**

Run: `npm run build`, `node scripts/i18n-check.mjs`, `php artisan test --filter=PlayerPositionsTest` (PASS), BOM check.

```bash
git add resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Index.vue resources/js/Pages/Players/Show.vue resources/js/Pages/Settings/Positions.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(players): pick a main position and the others, shown as chips"
```

---

### Task 11: Jobs in three languages, without duplicates (backend)

**Why:** job names are French-only, deleting a job silently blanks it on every player (the FK is `nullOnDelete` and there is no guard), and nothing stops "Ingénieur" being added three times in three spellings.

**Files:**
- Create: `database/migrations/2026_09_23_100006_add_locale_names_to_member_jobs.php`
- Create: `app/Support/NameNormalizer.php`
- Create: `app/Services/Lookup/JobDuplicateFinder.php`
- Modify: `app/Models/MemberJob.php`
- Modify: `app/Http/Controllers/MemberJobController.php`
- Modify: `app/Http/Controllers/PlayerImportController.php` (match a job in any language)
- Modify: `routes/web.php`
- Test: `tests/Feature/JobLocalizationTest.php`, `tests/Feature/JobDuplicateTest.php`

**Interfaces:**
- Produces `member_jobs.name_ar|name_fr|name_en`; `MemberJob` uses `HasLocalizedName` and appends `localized_name`.
- Produces `App\Support\NameNormalizer::key(string): string` — lower-cased, accent-free, Arabic-normalised, punctuation and spaces removed.
- Produces `App\Services\Lookup\JobDuplicateFinder`:
  - `exact(array $names, ?int $ignoreId = null): ?MemberJob`
  - `similar(array $names, ?int $ignoreId = null): Collection`
  where `$names` is `['name' => …, 'name_ar' => …, 'name_fr' => …, 'name_en' => …]`.
- Produces routes `jobs.quick.store` (`POST /jobs/quick`, JSON) and `jobs.merge` (`POST /jobs/{job}/merge`).
- `MemberJobController::destroy` refuses while any player or user holds the job.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/JobLocalizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MemberJob;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function a_job_stores_a_name_per_language(): void
    {
        $this->actingAs($this->admin())->post(route('jobs.store'), [
            'name' => 'Enseignant',
            'name_fr' => 'Enseignant',
            'name_ar' => 'أستاذ',
            'name_en' => 'Teacher',
        ])->assertRedirect();

        $job = MemberJob::query()->firstOrFail();

        App::setLocale('ar');
        $this->assertSame('أستاذ', $job->localized_name);

        App::setLocale('en');
        $this->assertSame('Teacher', $job->localized_name);
    }

    #[Test]
    public function the_seeded_french_names_become_the_french_column(): void
    {
        $job = MemberJob::query()->where('name', 'Médecin')->first();

        $this->assertNotNull($job, 'the seeder still creates the French names');
        $this->assertSame('Médecin', $job->name_fr);
    }

    #[Test]
    public function a_job_in_use_cannot_be_deleted(): void
    {
        $job = MemberJob::create(['name' => 'Plombier', 'name_fr' => 'Plombier']);
        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'member_job_id' => $job->id]);

        $this->actingAs($this->admin())
            ->delete(route('jobs.destroy', $job))
            ->assertSessionHas('error', 'flash.job_in_use');

        $this->assertNotNull($job->fresh());
    }

    #[Test]
    public function an_unused_job_can_be_deleted(): void
    {
        $job = MemberJob::create(['name' => 'Plombier', 'name_fr' => 'Plombier']);

        $this->actingAs($this->admin())
            ->delete(route('jobs.destroy', $job))
            ->assertSessionHas('success', 'flash.job_deleted');

        $this->assertNull($job->fresh());
    }

    #[Test]
    public function merging_moves_every_member_then_removes_the_duplicate(): void
    {
        $keep = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);
        $duplicate = MemberJob::create(['name' => 'ingenieur', 'name_fr' => 'ingenieur']);
        $player = Player::create(['membership_id' => '202600002', 'firstname' => 'Amine', 'member_job_id' => $duplicate->id]);

        $this->actingAs($this->admin())
            ->post(route('jobs.merge', $duplicate), ['into' => $keep->id])
            ->assertSessionHas('success', 'flash.job_merged');

        $this->assertSame($keep->id, $player->fresh()->member_job_id);
        $this->assertNull($duplicate->fresh());
    }

    #[Test]
    public function a_job_cannot_be_merged_into_itself(): void
    {
        $job = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);

        $this->actingAs($this->admin())
            ->post(route('jobs.merge', $job), ['into' => $job->id])
            ->assertSessionHasErrors('into');
    }

    #[Test]
    public function the_import_matches_a_job_in_any_language(): void
    {
        MemberJob::create(['name' => 'Enseignant', 'name_fr' => 'Enseignant', 'name_ar' => 'أستاذ']);

        $cells = array_fill(0, 21, '');
        $cells[0] = 'Amine';
        $cells[13] = 'أستاذ'; // the job column
        $csv = "\xEF\xBB\xBF".implode("\n", [implode(',', array_fill(0, 21, 'h')), implode(',', $cells)])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $this->assertSame('Enseignant', Player::query()->firstOrFail()->memberJob->name);
    }
}
```

Create `tests/Feature/JobDuplicateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MemberJob;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_normaliser_ignores_case_accents_spacing_and_arabic_forms(): void
    {
        $this->assertSame(NameNormalizer::key('Ingénieur'), NameNormalizer::key('  INGENIEUR '));
        $this->assertSame(NameNormalizer::key('Chef de projet'), NameNormalizer::key('chef-de-projet'));
        // أ إ آ all normalise to ا, and ة to ه.
        $this->assertSame(NameNormalizer::key('أستاذة'), NameNormalizer::key('استاذه'));
    }

    #[Test]
    public function an_exact_duplicate_is_refused_and_names_the_existing_job(): void
    {
        $existing = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);

        $response = $this->actingAs($this->admin())
            ->post(route('jobs.store'), ['name' => 'INGENIEUR', 'name_fr' => 'INGENIEUR'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, MemberJob::query()->count());
        $this->assertSame($existing->id, MemberJob::query()->firstOrFail()->id);
    }

    #[Test]
    public function the_quick_endpoint_returns_the_created_job_as_json(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'Menuisier', 'name_fr' => 'Menuisier'])
            ->assertCreated();

        $response->assertJsonPath('job.name', 'Menuisier');
        $this->assertNotNull($response->json('job.id'));
    }

    #[Test]
    public function the_quick_endpoint_hands_back_the_existing_job_instead_of_a_duplicate(): void
    {
        $existing = MemberJob::create(['name' => 'Menuisier', 'name_fr' => 'Menuisier']);

        $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'menuisier'])
            ->assertStatus(409)
            ->assertJsonPath('duplicate.id', $existing->id);

        $this->assertSame(1, MemberJob::query()->count());
    }

    #[Test]
    public function a_near_duplicate_is_reported_but_still_created(): void
    {
        MemberJob::create(['name' => 'Informaticien', 'name_fr' => 'Informaticien']);

        $response = $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'Informaticienne'])
            ->assertCreated();

        $this->assertNotEmpty($response->json('similar'));
        $this->assertSame(2, MemberJob::query()->count());
    }

    #[Test]
    public function creating_a_job_quickly_needs_the_right_to_add_one(): void
    {
        $role = Role::factory()->create(['permissions' => ['categories' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->postJson(route('jobs.quick.store'), ['name' => 'Menuisier'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `php artisan config:clear; php artisan test --filter="JobLocalizationTest|JobDuplicateTest"`

Expected: FAIL — no `name_fr` column, no `NameNormalizer`, no `jobs.quick.store` route.

- [ ] **Step 3: Columns**

Create `database/migrations/2026_09_23_100006_add_locale_names_to_member_jobs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jobs get a name per language, like categories and player statuses.
     *
     * The names already in the table were seeded in French, so they become the
     * French column; `name` stays as the fallback HasLocalizedName reads.
     */
    public function up(): void
    {
        Schema::table('member_jobs', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_fr')->nullable()->after('name_ar');
            $table->string('name_en')->nullable()->after('name_fr');
        });

        DB::table('member_jobs')->whereNull('name_fr')->update(['name_fr' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('member_jobs', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'name_fr', 'name_en']);
        });
    }
};
```

- [ ] **Step 4: The normaliser and the duplicate finder**

Create `app/Support/NameNormalizer.php`:

```php
<?php

namespace App\Support;

/**
 * One spelling-insensitive key for a name, so "Ingénieur", "INGENIEUR" and
 * "ingenieur " are recognised as the same job — and so are Arabic names
 * written with different alef or taa forms.
 */
final class NameNormalizer
{
    private const LATIN = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
    ];

    private const ARABIC = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        'ـ' => '', // tatweel
    ];

    public static function key(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, self::LATIN);
        $value = strtr($value, self::ARABIC);

        // Harakat (fatha, damma, kasra, shadda, sukun …) are decoration, not spelling.
        $value = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $value);

        // Spaces, hyphens and punctuation never distinguish two jobs.
        return (string) preg_replace('/[^a-z0-9\p{Arabic}]/u', '', $value);
    }
}
```

Create `app/Services/Lookup/JobDuplicateFinder.php`:

```php
<?php

namespace App\Services\Lookup;

use App\Models\MemberJob;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Keeps the job list from filling up with the same job spelled three ways.
 *
 * An exact match (after normalising) is refused outright. A near match is only
 * reported: "Informaticien" and "Informaticienne" are genuinely different
 * jobs, and the club decides, not the app.
 */
class JobDuplicateFinder
{
    /** Names in every language, as submitted: ['name' => …, 'name_ar' => …, …]. */
    public function exact(array $names, ?int $ignoreId = null): ?MemberJob
    {
        $keys = $this->keys($names);

        if ($keys === []) {
            return null;
        }

        return $this->candidates($ignoreId)
            ->first(fn (MemberJob $job) => array_intersect($keys, $this->keys($job->only(['name', 'name_ar', 'name_fr', 'name_en']))) !== []);
    }

    /** @return Collection<int, MemberJob> */
    public function similar(array $names, ?int $ignoreId = null): Collection
    {
        $keys = $this->keys($names);

        if ($keys === []) {
            return collect();
        }

        return $this->candidates($ignoreId)
            ->filter(function (MemberJob $job) use ($keys) {
                foreach ($this->keys($job->only(['name', 'name_ar', 'name_fr', 'name_en'])) as $existing) {
                    foreach ($keys as $key) {
                        if ($existing === $key) {
                            continue; // exact() owns this case
                        }

                        if (str_contains($existing, $key) || str_contains($key, $existing)) {
                            return true;
                        }

                        if (mb_strlen($key) > 4 && levenshtein($key, $existing) <= 2) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }

    /** @return Collection<int, MemberJob> */
    private function candidates(?int $ignoreId): Collection
    {
        return MemberJob::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();
    }

    /** @return list<string> */
    private function keys(array $names): array
    {
        return collect($names)
            ->map(fn ($value) => NameNormalizer::key(is_string($value) ? $value : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
```

- [ ] **Step 5: The model and the controller**

`app/Models/MemberJob.php` — mirror `Category`:

```php
class MemberJob extends Model
{
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'name',
        'name_ar',
        'name_fr',
        'name_en',
        'description',
    ];

    protected $appends = [
        'localized_name',
    ];
```

with `use App\Models\Concerns\HasLocalizedName;` added. Keep both relations.

Replace `app/Http/Controllers/MemberJobController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MemberJob;
use App\Services\Lookup\JobDuplicateFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MemberJobController extends Controller
{
    public function __construct(private JobDuplicateFinder $duplicates) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Jobs', [
            // The counts let the page warn before a delete that cannot happen.
            'jobs' => MemberJob::query()->withCount(['players', 'users'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($existing = $this->duplicates->exact($validated)) {
            return back()->withErrors([
                'name' => __('This job already exists as ":name".', ['name' => $existing->name]),
            ])->withInput();
        }

        MemberJob::create($validated);

        return back()->with('success', 'flash.job_created');
    }

    /**
     * Create a job from inside the player form and hand it straight back, so a
     * half-filled member form is never lost to a page reload.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        if ($existing = $this->duplicates->exact($validated)) {
            return response()->json(['duplicate' => $existing], 409);
        }

        $job = MemberJob::create($validated);

        return response()->json([
            'job' => $job,
            'similar' => $this->duplicates->similar($validated, $job->id)->values(),
        ], 201);
    }

    public function update(Request $request, MemberJob $job): RedirectResponse
    {
        $validated = $this->validated($request, $job);

        if ($existing = $this->duplicates->exact($validated, $job->id)) {
            return back()->withErrors([
                'name' => __('This job already exists as ":name".', ['name' => $existing->name]),
            ])->withInput();
        }

        $job->update($validated);

        return back()->with('success', 'flash.job_updated');
    }

    /** Move every member of one job to another, then drop the duplicate. */
    public function merge(Request $request, MemberJob $job): RedirectResponse
    {
        $validated = $request->validate([
            'into' => ['required', 'integer', 'exists:member_jobs,id'],
        ]);

        if ((int) $validated['into'] === $job->id) {
            return back()->withErrors(['into' => __('Choose a different job to merge into.')]);
        }

        DB::transaction(function () use ($job, $validated) {
            $job->players()->update(['member_job_id' => $validated['into']]);
            $job->users()->update(['member_job_id' => $validated['into']]);
            $job->delete();
        });

        return back()->with('success', 'flash.job_merged');
    }

    public function destroy(MemberJob $job): RedirectResponse
    {
        // The FK blanks the job on every member it is attached to, silently.
        if ($job->players()->exists() || $job->users()->exists()) {
            return back()->with('error', 'flash.job_in_use');
        }

        $job->delete();

        return back()->with('success', 'flash.job_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MemberJob $existing = null): array
    {
        $unique = 'unique:member_jobs,name'.($existing ? ','.$existing->id : '');

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
```

Add the flash keys and sentences:

`i18n-keys.tmp.json`:

```json
{
    "flash.job_in_use": { "en": "This job is still assigned to members, so it cannot be deleted.", "fr": "Ce métier est encore attribué à des membres : il ne peut pas être supprimé.", "ar": "هذه المهنة لا تزال مُسندة إلى أعضاء، لذا لا يمكن حذفها." },
    "flash.job_merged": { "en": "The jobs were merged.", "fr": "Les métiers ont été fusionnés.", "ar": "تم دمج المهنتين." }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

Add to `lang/ar.json` / `lang/fr.json` in alphabetical position:
- `"This job already exists as \":name\"."` → `"Ce métier existe déjà sous le nom « :name »."` / `"هذه المهنة موجودة بالفعل باسم «:name»."`
- `"Choose a different job to merge into."` → `"Choisissez un autre métier pour la fusion."` / `"اختر مهنة أخرى للدمج."`

- [ ] **Step 6: Routes and the import**

In `routes/web.php`, beside the `jobs` resource (keep the static path before the resource):

```php
        Route::post('/jobs/quick', [MemberJobController::class, 'quickStore'])->name('jobs.quick.store');
        Route::post('/jobs/{job}/merge', [MemberJobController::class, 'merge'])->name('jobs.merge');
```

In `app/Http/Controllers/PlayerImportController.php`, replace the job lookup with one that accepts any language:

```php
        $jobs = [];
        foreach (MemberJob::query()->get() as $job) {
            foreach ([$job->name, $job->name_ar, $job->name_fr, $job->name_en] as $value) {
                $key = \App\Support\NameNormalizer::key($value);
                if ($key !== '') {
                    $jobs[$key] = $job->id;
                }
            }
        }
```

and the assignment:

```php
                'member_job_id' => $jobs[\App\Support\NameNormalizer::key($data['job'] ?? null)] ?? null,
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter="JobLocalizationTest|JobDuplicateTest|PlayerImportTest|FlashTranslationTest"` → PASS.

- [ ] **Step 8: Full suite and commit**

Run: `php artisan test --compact` (PASS).

```bash
php vendor/bin/pint database/migrations/2026_09_23_100006_add_locale_names_to_member_jobs.php app/Support/NameNormalizer.php app/Services/Lookup/JobDuplicateFinder.php app/Models/MemberJob.php app/Http/Controllers/MemberJobController.php app/Http/Controllers/PlayerImportController.php routes/web.php tests/Feature/JobLocalizationTest.php tests/Feature/JobDuplicateTest.php
git add database/migrations/2026_09_23_100006_add_locale_names_to_member_jobs.php app/Support/NameNormalizer.php app/Services/Lookup/JobDuplicateFinder.php app/Models/MemberJob.php app/Http/Controllers/MemberJobController.php app/Http/Controllers/PlayerImportController.php routes/web.php lang/ar.json lang/fr.json resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/JobLocalizationTest.php tests/Feature/JobDuplicateTest.php
git commit -m "feat(jobs): names per language, duplicate guard, merge and delete protection"
```

---

### Task 12: Managing jobs, and creating one without leaving the player form

**Files:**
- Create: `resources/js/Components/JobQuickCreateModal.vue`
- Modify: `resources/js/Pages/Settings/Jobs.vue`
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes `jobs.quick.store` (201 `{job, similar}` / 409 `{duplicate}`), `jobs.merge`, and `jobs[*].players_count` / `users_count` (Task 11).
- The modal emits `created(job)`; the player form appends it to its local list and selects it, without a page visit.

- [ ] **Step 1: Keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "new_job": { "en": "New job", "fr": "Nouveau métier", "ar": "مهنة جديدة" },
    "job_name_fr": { "en": "Name (French)", "fr": "Nom (français)", "ar": "الاسم (بالفرنسية)" },
    "job_name_ar": { "en": "Name (Arabic)", "fr": "Nom (arabe)", "ar": "الاسم (بالعربية)" },
    "job_name_en": { "en": "Name (English)", "fr": "Nom (anglais)", "ar": "الاسم (بالإنجليزية)" },
    "job_exists": { "en": "That job already exists — selected it for you.", "fr": "Ce métier existe déjà — il a été sélectionné.", "ar": "هذه المهنة موجودة بالفعل — تم اختيارها." },
    "job_similar_warning": { "en": "Similar jobs already exist: {names}", "fr": "Des métiers proches existent déjà : {names}", "ar": "توجد مهن مشابهة: {names}" },
    "in_use_by": { "en": "Used by {count}", "fr": "Utilisé par {count}", "ar": "مستعملة من طرف {count}" },
    "merge_into": { "en": "Merge into", "fr": "Fusionner avec", "ar": "دمج مع" },
    "merge": { "en": "Merge", "fr": "Fusionner", "ar": "دمج" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

- [ ] **Step 2: The quick-create modal**

Create `resources/js/Components/JobQuickCreateModal.vue`:

```vue
<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * Create a job from inside the player form. It posts straight to the JSON
 * endpoint rather than making an Inertia visit, because a visit would throw
 * away everything already typed into the half-filled member form.
 */
const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'created']);
const { t } = useI18n();

const form = ref({ name_fr: '', name_ar: '', name_en: '' });
const error = ref('');
const similar = ref([]);
const saving = ref(false);

watch(() => props.show, (open) => {
    if (open) {
        form.value = { name_fr: '', name_ar: '', name_en: '' };
        error.value = '';
        similar.value = [];
    }
});

async function submit() {
    // The base `name` is the fallback HasLocalizedName reads, so it takes
    // whichever language was filled in first.
    const name = form.value.name_fr || form.value.name_ar || form.value.name_en;

    if (!name) {
        error.value = t('required');
        return;
    }

    saving.value = true;
    error.value = '';
    similar.value = [];

    try {
        const response = await fetch(route('jobs.quick.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ name, ...form.value }),
        });

        const payload = await response.json();

        if (response.status === 409) {
            // Already there under another spelling: take it rather than add a twin.
            emit('created', payload.duplicate, t('job_exists'));
            emit('close');
            return;
        }

        if (!response.ok) {
            error.value = payload?.message || t('error');
            return;
        }

        similar.value = payload.similar ?? [];
        emit('created', payload.job, similar.value.length
            ? t('job_similar_warning', { names: similar.value.map((j) => j.name).join(', ') })
            : '');
        emit('close');
    } catch (e) {
        error.value = t('error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('new_job') }}</h3>
            <div class="mt-4 space-y-3">
                <div>
                    <InputLabel :value="t('job_name_fr')" />
                    <TextInput v-model="form.name_fr" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('job_name_ar')" />
                    <TextInput v-model="form.name_ar" class="mt-1 w-full" dir="rtl" />
                </div>
                <div>
                    <InputLabel :value="t('job_name_en')" />
                    <TextInput v-model="form.name_en" class="mt-1 w-full" />
                </div>
                <InputError :message="error" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('close')">{{ t('cancel') }}</SecondaryButton>
                <PrimaryButton :disabled="saving" @click="submit">{{ t('save') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
```

Confirm the app's layout renders a `<meta name="csrf-token">`; if it does not, add it to `resources/views/app.blade.php`'s `<head>`:

```blade
        <meta name="csrf-token" content="{{ csrf_token() }}">
```

- [ ] **Step 3: Wire it into the player form**

In `resources/js/Pages/Players/Partials/PlayerForm.vue`:

```js
import JobQuickCreateModal from '@/Components/JobQuickCreateModal.vue';
import { useCan } from '@/Composables/useCan';
```

```js
const { can } = useCan();
// Jobs the form offers: the server list plus anything created in this session.
const jobList = ref([...props.jobs]);
const showJobModal = ref(false);
const jobNotice = ref('');
function onJobCreated(job, notice) {
    if (!jobList.value.some((item) => item.id === job.id)) jobList.value.push(job);
    form.member_job_id = job.id;
    jobNotice.value = notice || '';
}
```

In the job block, iterate `jobList` (not `jobs`), show the localized name, and add the button:

```html
                <div v-if="!form.is_student">
                    <div class="flex items-center justify-between">
                        <InputLabel :value="t('job')" />
                        <button v-if="can('categories', 'add')" type="button" @click="showJobModal = true"
                            class="text-xs font-semibold text-primary-600 hover:text-primary-700">+ {{ t('new_job') }}</button>
                    </div>
                    <select v-model="form.member_job_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="job in jobList" :key="job.id" :value="job.id">{{ job.localized_name || job.name }}</option>
                    </select>
                    <p v-if="jobNotice" class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ jobNotice }}</p>
                </div>
```

and at the end of the template, before the closing `</form>`:

```html
        <JobQuickCreateModal :show="showJobModal" @close="showJobModal = false" @created="onJobCreated" />
```

- [ ] **Step 4: The settings page**

In `resources/js/Pages/Settings/Jobs.vue`:
- add `name_ar`, `name_fr`, `name_en` to the add form and to the inline edit row, following `Settings/Categories.vue`'s layout exactly (read that file and mirror it);
- show the usage count per row: `{{ t('in_use_by', { count: (job.players_count || 0) + (job.users_count || 0) }) }}`;
- disable the delete button when that count is above zero, and offer merge instead: a small select of the other jobs plus a **Merge** button posting `router.post(route('jobs.merge', job.id), { into: target })`;
- keep the existing `ConfirmModal` for the delete itself.

- [ ] **Step 5: Verify and commit**

Run: `npm run build`, `node scripts/i18n-check.mjs`, `php artisan test --filter="JobLocalizationTest|JobDuplicateTest"` (PASS).

```bash
git add resources/js/Components/JobQuickCreateModal.vue resources/js/Pages/Settings/Jobs.vue resources/js/Pages/Players/Partials/PlayerForm.vue resources/views/app.blade.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(jobs): manage translations and create a job from the player form"
```

---

### Task 13: The player page, rebuilt

**Why:** the page shows a fraction of what it loads, has no icons, and prints an emoji as a button.

**Files:**
- Create: `resources/js/Components/PlayerFieldRow.vue`
- Modify: `resources/js/Components/Icon.vue` (three new glyphs)
- Modify: `resources/js/Pages/Players/Show.vue` (**BOM** — targeted edits)
- Modify: `app/Http/Controllers/PlayerController.php` (`show` eager loads)
- Modify: `resources/js/i18n/{ar,en,fr}.json` (via script)

**Interfaces:**
- Consumes everything `show()` already loads plus `status`, `wilaya`, `otherPositions`, `memberJob`, `emergencyContacts`.
- Produces `<PlayerFieldRow icon="…" :label="…" :value="…" mono />`.

- [ ] **Step 1: Load what the page shows**

In `app/Http/Controllers/PlayerController.php` `show()`, the `load([...])` array must contain: `'category'`, `'position'`, `'otherPositions'`, `'memberJob'`, `'status'`, `'wilaya'`, `'branches'`, `'emergencyContacts'`, `'achievements'`, the existing subscription and rental loads. Add the missing ones (`status`, `wilaya`, `otherPositions`).

The drawer size is a club setting, so the page is told it rather than assuming 100. Add to the `Inertia::render('Players/Show', [...])` props:

```php
            'fileDrawerSize' => FileNumber::drawerSize(),
```

with `use App\Services\Player\FileNumber;` imported, and declare it in `Players/Show.vue`'s props:

```js
    fileDrawerSize: { type: Number, default: 100 },
```

importing the helper alongside the formatter:

```js
import { formatFileNumber, fileDrawer } from '@/lib/fileNumber';
```

Add a test to `tests/Feature/PlayerFileNumberTest.php`:

```php
    #[Test]
    public function the_player_page_loads_everything_it_shows(): void
    {
        $player = $this->register('Amine');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        foreach (['status', 'wilaya', 'other_positions', 'member_job', 'emergency_contacts'] as $key) {
            $this->assertArrayHasKey($key, $props['player'], $key.' is not loaded');
        }
    }
```

Run it, watch it fail on `status`, then fix the eager loads and watch it pass.

- [ ] **Step 2: Keys**

Save as `i18n-keys.tmp.json`:

```json
{
    "member_details": { "en": "Member details", "fr": "Détails du membre", "ar": "تفاصيل العضو" },
    "contact_details": { "en": "Contact", "fr": "Contact", "ar": "الاتصال" },
    "emergency_contacts": { "en": "Emergency contacts", "fr": "Contacts d'urgence", "ar": "جهات الاتصال في حالة الطوارئ" },
    "medical_notes": { "en": "Medical notes", "fr": "Notes médicales", "ar": "ملاحظات طبية" },
    "copy": { "en": "Copy", "fr": "Copier", "ar": "نسخ" },
    "copied": { "en": "Copied", "fr": "Copié", "ar": "تم النسخ" }
}
```

Run `node scripts/i18n-add.mjs i18n-keys.tmp.json`, then delete it.

- [ ] **Step 3: Three icons**

In `resources/js/Components/Icon.vue`, add to the `icons` object, in the same hand-drawn 24px style as its neighbours:

```js
    idcard: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5 16c.7-1.4 2-2.2 3.5-2.2S11.3 14.6 12 16"/><path d="M14.5 10h5M14.5 13.5h3.5"/>',
    folder: '<path d="M3 7.5c0-1 .8-1.8 1.8-1.8h3.9l1.8 2.1h7.7c1 0 1.8.8 1.8 1.8v7.6c0 1-.8 1.8-1.8 1.8H4.8c-1 0-1.8-.8-1.8-1.8z"/>',
    drop: '<path d="M12 3.5c3 3.6 5.5 6.4 5.5 9.4a5.5 5.5 0 0 1-11 0c0-3 2.5-5.8 5.5-9.4z"/>',
```

- [ ] **Step 4: The field row**

Create `resources/js/Components/PlayerFieldRow.vue`:

```vue
<script setup>
import Icon from '@/Components/Icon.vue';

/**
 * One line of a member's record: an icon to find it by, the label that says
 * what it is, and the value. The icon never replaces the label — it is there
 * to make the page scannable, not to be decoded.
 */
defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
    mono: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex items-start gap-2.5">
        <span class="mt-0.5 text-slate-400 dark:text-slate-500"><Icon :name="icon" /></span>
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ label }}</dt>
            <dd class="text-sm text-slate-900 dark:text-slate-100" :class="mono ? 'font-mono' : ''">
                <slot>{{ value || '—' }}</slot>
            </dd>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Rebuild the info card**

In `resources/js/Pages/Players/Show.vue` (BOM — targeted edits), import the component, then replace the `<dl>` block inside the personal-info card with this grid. Keep the surrounding card, the photo and the name header exactly as they are.

```html
                    <dl class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <PlayerFieldRow icon="idcard" :label="t('membership_id')" :value="player.membership_id" mono />
                        <PlayerFieldRow icon="folder" :label="t('file_number')" mono>
                            {{ formatFileNumber(player.file_number) }}
                            <span v-if="player.file_number" class="text-xs font-sans text-slate-500 dark:text-slate-400">
                                · {{ t('drawer') }} {{ fileDrawer(player.file_number, fileDrawerSize) }}
                            </span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="calendar" :label="t('birthdate')">
                            {{ formatDate(player.birthdate) }}
                            <span v-if="player.age" class="text-slate-500 dark:text-slate-400">({{ player.age }})</span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="user" :label="t('gender')" :value="player.gender ? t(player.gender.toLowerCase()) : ''" />
                        <PlayerFieldRow icon="positions" :label="t('main_position')">
                            {{ player.position?.abbreviation || '—' }}
                            <span v-if="player.other_positions?.length" class="text-slate-500 dark:text-slate-400">
                                · {{ player.other_positions.map((p) => p.abbreviation).join(', ') }}
                            </span>
                        </PlayerFieldRow>
                        <PlayerFieldRow icon="categories" :label="t('category')" :value="player.category?.localized_name || player.category?.name" />
                        <PlayerFieldRow icon="flag" :label="t('membership_status')" :value="player.status?.localized_name || player.status?.name" />
                        <PlayerFieldRow icon="location" :label="t('state')" :value="[player.city, player.wilaya?.localized_name || player.wilaya?.name].filter(Boolean).join(', ')" />
                        <PlayerFieldRow icon="phone" :label="t('phone')" :value="player.phones?.[0]" />
                        <PlayerFieldRow icon="mail" :label="t('email')" :value="player.email" />
                        <PlayerFieldRow icon="jobs" :label="t('job')" :value="player.is_student ? t('student') : (player.member_job?.localized_name || player.member_job?.name)" />
                        <PlayerFieldRow icon="drop" :label="t('blood_group')" :value="player.health_blood_group_rhesus" />
                        <PlayerFieldRow icon="calendar" :label="t('join_year')" :value="player.join_year" />
                        <PlayerFieldRow icon="home" :label="t('branches')">
                            <span v-if="player.branches?.length" class="flex flex-wrap gap-1">
                                <span v-for="b in player.branches" :key="b.id" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ b.localized_name || b.name }}</span>
                            </span>
                            <span v-else>—</span>
                        </PlayerFieldRow>
                    </dl>
```

If `membership_status` is not already a catalog key, reuse `t('status')` instead — check with `grep -n '"membership_status"' resources/js/i18n/en.json`.

- [ ] **Step 6: Two more cards**

Directly after the info card, add:

```html
                    <div v-if="player.emergency_contacts?.length || player.health_medical_conditions" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="mb-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('emergency_contacts') }}</p>
                        <ul class="space-y-1">
                            <li v-for="contact in player.emergency_contacts" :key="contact.id" class="flex flex-wrap items-center gap-2 text-sm">
                                <Icon name="phone" class="text-slate-400" />
                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ contact.name }}</span>
                                <span v-if="contact.relationship" class="text-xs text-slate-500">{{ contact.relationship }}</span>
                                <span class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ (contact.phones || [])[0] }}</span>
                            </li>
                        </ul>
                        <p v-if="player.health_medical_conditions" class="mt-3 text-sm">
                            <span class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('medical_notes') }}:</span>
                            {{ player.health_medical_conditions }}
                        </p>
                    </div>
```

And replace the member-card link's 🪪 emoji with `<Icon name="idcard" />`, keeping the link itself.

- [ ] **Step 7: Verify and commit**

Run: `npm run build`, `node scripts/i18n-check.mjs`, `php artisan test --filter=PlayerFileNumberTest` (PASS), BOM check on `Players/Show.vue`.

```bash
php vendor/bin/pint app/Http/Controllers/PlayerController.php tests/Feature/PlayerFileNumberTest.php
git add app/Http/Controllers/PlayerController.php resources/js/Components/PlayerFieldRow.vue resources/js/Components/Icon.vue resources/js/Pages/Players/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/PlayerFileNumberTest.php
git commit -m "feat(players): rebuild the member record with icons and the fields it already loads"
```

---

### Task 14: Full verification and manual check

- [ ] **Step 1: Everything green**

| Command | Expected |
|---|---|
| `composer test` | all pass; record the count |
| `node scripts/i18n-check.mjs` | `✓ …` |
| `npm run build` | success |
| `php vendor/bin/pint --test app tests database routes config` | only the 7 pre-existing files this branch never touched |
| `git status --short` | only `?? .claude/`; no `i18n-keys.tmp.json` |
| `head -c 3 resources/js/Pages/Players/Show.vue \| od -An -tx1` | `ef bb bf` |

- [ ] **Step 2: Migrate and check the data**

```bash
php artisan migrate
php -r '$p=new PDO("sqlite:database/database.sqlite");
echo "wilayas=",$p->query("select count(*) from country_states")->fetchColumn(),"\n";
echo "unmatched states=",$p->query("select count(*) from players where state is not null and state != \"Unknown\" and wilaya_id is null")->fetchColumn(),"\n";
echo "players without a file number=",$p->query("select count(*) from players where file_number is null")->fetchColumn(),"\n";'
```

Expected: 58 wilayas, **0** players without a file number. Any unmatched `state` values are listed for the owner — report the count and the distinct values; they are players whose wilaya box held something that is not a wilaya.

- [ ] **Step 3: Manual check in the browser, FR and AR**

1. **Settings → General → Settings:** the season month and files-per-drawer fields save and come back.
2. **Players → a player:** the record shows icons, file number with its drawer, wilaya in the page language, main position plus others, job, status, emergency contact.
3. **Print:** Folder label opens a PDF with a big file number and a QR; scanning it with a phone yields the membership ID. Member card shows the file number.
4. **Players list:** pick a category → **Board table** prints that category with file numbers, sorted by last name, headed with the season.
5. **Select 3 players → Labels for selection:** one PDF, three labels.
6. **Edit a player:** change the join year → membership ID and file number both unchanged.
7. **Wilaya:** the picker searches by code (`47`), by French name and by Arabic name; the list filter narrows.
8. **Positions:** add two other positions; the list shows `MF +2`; filtering by an *other* position finds the player.
9. **Jobs:** in the player form, "+ New job" creates and selects it without losing the rest of the form; adding the same name again selects the existing one instead of duplicating. Settings → Jobs shows usage counts, refuses to delete a job in use, and merges a duplicate.
10. Switch to Arabic and repeat 2, 7 and 9: labels, wilaya names and job names all read in Arabic, layout stays right-to-left.

- [ ] **Step 4: Deploy notes for the PR description**

- Run `php artisan migrate` (six migrations: `2026_09_23_100001`–`100006`).
- **Refresh `storage/app/seed/database.sqlite` from the migrated dev database before the next desktop build**, or new installs miss every new column.
- `composer require mpdf/qrcode` ran in Task 4. **Confirm the NativePHP `afterPack` patch is still in `vendor/nativephp/desktop/resources/electron/electron-builder.mjs`** before building; composer reverts it, and without it a fresh PC cannot start the app.
- Wilaya values that matched nothing are still in `players.state` — hand the owner the list from Step 2.

---

## Spec coverage check

| Spec item (P2) | Task |
|---|---|
| #6 membership ID frozen | 2 |
| #6 permanent file number, drawer, search, list/page/form/export | 2, 3 |
| #6 folder label with QR, batch labels, member card | 4 |
| #6 category board table, season-aware | 1, 5 |
| #14 58 official wilayas, code + FR/AR, typos fixed | 6 |
| #14 `wilaya_id` + backfill + form/filter/import/export, city unchanged | 7, 8 |
| #10 main + other positions everywhere | 9, 10 |
| #15 job translations, inline creation, duplicates, merge, delete guard | 11, 12 |
| #16 player page with icons and the missing fields | 13 |



