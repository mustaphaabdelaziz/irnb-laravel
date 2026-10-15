# Competitions & Matches — Phase 3 (Insights) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Suspensions (automatic from cards, plus manual ones), eligibility badges (suspended, no licence, documents missing, unpaid), statistics (on the player page, the team and competition tabs, and a club page with Excel/PDF export), and a dashboard matches card.

**Architecture:**
- **Suspensions:** `SuspensionEngine` rebuilds a competition's automatic suspensions from its played match reports. `SuspensionStatus` works out how much of each suspension has been served, at any point in time, with a constant number of queries.
- **Badges:** `PlayerFlags::for()` builds every badge for a list of players in one pass.
- **Statistics:** `MatchStats` aggregates in PHP over three narrow queries. The data volume is small, the code stays portable across sqlite and MySQL, and the sanction-to-card mapping needs each game's own rules anyway.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`), Inertia + Vue 3 `<script setup>`, Tailwind, `App\Support\Export` (xlsx/csv), mPDF via `PdfService`, vue-i18n flat keys.

Spec: `docs/superpowers/specs/2026-10-15-competitions-p3-insights-design.md`.

## Global Constraints

- Work only in the worktree `D:/irnb-competitions` (branch `feat/competitions-p3`). Never touch `D:/irnb-laravel`.
- Read each match's rules from `games.rules` only, through `Game::rule()`.
- Dates are `'Y-m-d'` strings. Seasons are `season_start_year` integers (`App\Support\Season`).
- Warnings never block an action.
- Writes need `TeamAccess::authorize($user, $team, $season)`. Statistics, the player section and exports need games/view only.
- **No ratings in any export or PDF.** Ratings may only appear in the app.
- Show the unpaid amount only to users with finance/view.
- UI strings go in ar, en and fr through `node .superpowers/sdd/i18n-by-locale.mjs <<'EOF' {"en":{},"fr":{},"ar":{}} EOF`, run from the worktree root. If the heredoc breaks on a quote, write the JSON to a temp file under `.superpowers/sdd/`, pipe it in, then delete the file.
  - Use `t()` directly, never `te()`.
  - Errors are i18n keys. Flash messages are `flash.*` keys.
- Player names always come from `Player::fullname`. Narrowed player queries must select `Player::NAME_COLUMNS`.
- Data changes go in migrations, because the desktop build never seeds.
- After changing `.vue` files, run `npm run build` before the PHP page tests. If you hit a cached-config error, run `php artisan config:clear`.
- Stage explicit files only. Commit messages end with a blank line, then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `tests/Unit/ActivityRecorderTest.php` hard-codes the number of activity codes. It is 106 now and becomes 108 after Task 4.

## Deviation from the spec (decided while planning)

`suspensions.team_id` (nullable FK) is added. A suspension that covers all competitions has no competition, so its team context (the team whose competition page created it) is stored to authorize deleting it later.

---

### Task 1: Licence number and documents required for matches

**Files:**
- Create: `database/migrations/2026_10_17_100001_add_licence_and_match_documents.php`
- Modify: `app/Models/Player.php`, `app/Http/Requests/Player/StorePlayerRequest.php`, `app/Http/Requests/Player/UpdatePlayerRequest.php`, `resources/js/Pages/Players/Partials/PlayerForm.vue`, `resources/js/Pages/Players/Show.vue`, `app/Http/Controllers/PlayerImportController.php`
- Modify: `app/Models/DocumentType.php`, `app/Http/Controllers/DocumentTypeController.php`, `resources/js/Pages/Settings/DocumentTypes.vue`, `app/Services/Player/DocumentChecklist.php`
- Test: `tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php`

**Interfaces:**
- Produces:
  - `players.licence_number`.
  - `document_types.required_for_matches` (cast to bool).
  - `DocumentChecklist::missingTypeSql(DocumentType $type, ?CarbonInterface $today = null): array{0: string, 1: list<mixed>}`, a 1/0 SQL expression over `players`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class LicenceAndMatchDocumentsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_licence_number_is_saved_from_the_player_form(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname, 'lastname' => $player->lastname, 'licence_number' => 'DZ-FAF-12345',
        ])->assertSessionHasNoErrors();

        $this->assertSame('DZ-FAF-12345', $player->fresh()->licence_number);
    }

    #[Test]
    public function a_document_type_can_be_required_for_matches(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), [
            'name' => 'Medical certificate', 'validity' => 'season', 'is_required' => false,
            'required_for_matches' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(DocumentType::where('name', 'Medical certificate')->sole()->required_for_matches);
    }

    #[Test]
    public function the_per_type_missing_sql_matches_received_and_missing_players(): void
    {
        $type = DocumentType::create(['code' => 'medical', 'name' => 'Medical', 'validity' => 'none', 'is_required' => false, 'required_for_matches' => true, 'is_active' => true]);
        $category = $this->category();
        [$has, $lacks] = [$this->player($category), $this->player($category)];
        PlayerDocument::create(['player_id' => $has->id, 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => now()->toDateString()]);

        [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
        $missing = Player::query()->whereIn('id', [$has->id, $lacks->id])->select('id')->selectRaw("{$sql} as missing", $bindings)
            ->pluck('missing', 'id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$has->id => 0, $lacks->id => 1], $missing);
    }
}
```

Look at `app/Models/PlayerDocument.php` and `app/Models/DocumentType.php` `$fillable` first. If `code`, `received_at` or other attributes used above are not fillable, or are named differently, adjust the test to the real fillable names (e.g. create the rows with `DB::table(...)->insert`). Report any change you made.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=LicenceAndMatchDocumentsTest`
Expected: FAIL (unknown column `licence_number` / `required_for_matches`, or an undefined method `missingTypeSql`).

- [ ] **Step 3: Migration**

`database/migrations/2026_10_17_100001_add_licence_and_match_documents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: the federation licence on the player, and document types that must be in order to play matches. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('licence_number', 50)->nullable();
        });
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('required_for_matches')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('required_for_matches'));
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('licence_number'));
    }
};
```

- [ ] **Step 4: Player licence field**

- In `app/Models/Player.php`, add `'licence_number',` to `$fillable`, after `'health_blood_group_rhesus',`.
- In both `app/Http/Requests/Player/StorePlayerRequest.php` and `UpdatePlayerRequest.php`, add `'licence_number' => ['nullable', 'string', 'max:50'],` after the `health_blood_group_rhesus` rule.
- In `resources/js/Pages/Players/Partials/PlayerForm.vue`:
  - In the form state, after `health_blood_group_rhesus: p.health_blood_group_rhesus || '',`, add `licence_number: p.licence_number || '',`.
  - In the submit transform, after `health_blood_group_rhesus: data.health_blood_group_rhesus || null,`, add `licence_number: data.licence_number || null,`.
  - After the blood-group `<div>…</div>` block, add:

```vue
                <div>
                    <InputLabel :value="t('licence_number')" />
                    <TextInput v-model="form.licence_number" class="mt-1 w-full" maxlength="50" dir="ltr" />
                    <InputError :message="form.errors.licence_number" class="mt-1" />
                </div>
```

  Check that `TextInput` and `InputError` are already imported in that file. If not, import them from `@/Components/…` like the other inputs.
- In `resources/js/Pages/Players/Show.vue`, after the blood-group `PlayerFieldRow`, add:

```vue
                        <PlayerFieldRow icon="idcard" :label="t('licence_number')" :value="player.licence_number" />
```

- In `app/Http/Controllers/PlayerImportController.php`:
  - Append this to the end of the columns list, after the `other_positions` entry:

```php
        // Appended last: older files have no cell here.
        ['key' => 'licence_number', 'label' => 'col.licence_number', 'legacy' => ['رقم الإجازة'], 'example' => ''],
```

  - In the attributes array, after the `'health_blood_group_rhesus'` line, add:

```php
                    'licence_number' => trim((string) ($data['licence_number'] ?? '')) ?: null,
```

- [ ] **Step 5: Documents required for matches**

- In `app/Models/DocumentType.php`, add `'required_for_matches',` to `$fillable` after `'is_required',`, and `'required_for_matches' => 'boolean',` to `casts()`.
- In `app/Http/Controllers/DocumentTypeController.php` → validation, add `'required_for_matches' => ['boolean'],` after `'is_required'`.
- In `resources/js/Pages/Settings/DocumentTypes.vue`:
  - In `FIELDS`, add `required_for_matches: false,` after `is_required: false,`.
  - After the `is_required` checkbox `<label>`, add:

```vue
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.required_for_matches" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('doc_required_for_matches') }}
                    </label>
```

  - In the list, next to the `doc_required` badge, add `<Badge v-if="type.required_for_matches" :label="t('doc_required_for_matches')" color="amber" />`. If `Badge` has no `amber` colour, use one it supports.
- In `app/Services/Player/DocumentChecklist.php`, add after `missingCountSql()`:

```php
    /**
     * One document type's "missing" test as an SQL expression over `players`
     * (1 = missing, 0 = settled) — the per-type term missingCountSql() sums,
     * for lists that need to know WHICH types are missing (match eligibility).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingTypeSql(DocumentType $type, ?CarbonInterface $today = null): array
    {
        return self::missingTermSql($type, self::today($today));
    }
```

- [ ] **Step 6: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"licence_number": "Licence number", "col.licence_number": "Licence number", "doc_required_for_matches": "Required to play matches"},
  "fr": {"licence_number": "Numéro de licence", "col.licence_number": "Numéro de licence", "doc_required_for_matches": "Exigé pour jouer les matchs"},
  "ar": {"licence_number": "رقم الإجازة", "col.licence_number": "رقم الإجازة", "doc_required_for_matches": "مطلوب للمشاركة في المباريات"}
}
EOF
```

- [ ] **Step 7: Build and run**

Run `npm run build`, then `php artisan test --filter="LicenceAndMatchDocumentsTest|PlayerFormContactFieldsTest|PlayerImportTest|ImportTemplatesTest|DocumentType|DocumentChecklist"`.
Expected: PASS. If an import/template test hard-codes the column count or header list, update it for the one appended column and report it.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_17_100001_add_licence_and_match_documents.php app/Models/Player.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Show.vue app/Http/Controllers/PlayerImportController.php app/Models/DocumentType.php app/Http/Controllers/DocumentTypeController.php resources/js/Pages/Settings/DocumentTypes.vue app/Services/Player/DocumentChecklist.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php
git commit -m "feat(competitions): licence number on players; documents required to play matches

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Also stage any import or template test you had to update.

---

### Task 2: Suspensions table and the automatic engine

**Files:**
- Create: `database/migrations/2026_10_17_100002_create_suspensions_table.php`, `app/Models/Suspension.php`, `app/Services/Competitions/SuspensionEngine.php`
- Modify: `app/Models/Game.php` (`countsAs()`), `app/Models/Player.php` (`suspensions()`)
- Modify (triggers):
  - `app/Http/Controllers/GameReportController.php`
  - `app/Http/Controllers/GameController.php` (update + destroy)
  - `app/Http/Controllers/CompetitionController.php` (update)
  - `app/Http/Controllers/GameCallUpController.php`
- Test: `tests/Feature/Competitions/SuspensionEngineTest.php`

**Interfaces:**
- Produces:
  - `Suspension` model. Fillable: `player_id, competition_id, team_id, source, kind, matches_count, starts_on, ends_on, cause, game_id, reason, created_by`. Relations: `player`, `competition`, `team`, `game`, `creator`.
  - `Game::countsAs(?string $sanctionKey): ?string`, returning `'yellow'`, `'red'` or null.
  - `SuspensionEngine::recompute(Competition $competition): void`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/SuspensionEngineTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Suspension;
use App\Services\Competitions\SuspensionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SuspensionEngineTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private const CARDS = [
        ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
        ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
        ['key' => 'two_minutes', 'ends_play' => false, 'counts_as' => null],
    ];

    /** A played competition game on $date where each [player, key] got a sanction. */
    private function played(Competition $competition, string $date, array $sanctions = [], GameStatus $status = GameStatus::Played): Game
    {
        $game = $this->game($competition->team, ['competition_id' => $competition->id, 'date' => $date, 'status' => $status, 'our_score' => 0, 'their_score' => 0]);
        $game->update(['rules' => ['sanction_types' => self::CARDS] + $game->rules]);
        foreach ($sanctions as [$player, $key]) {
            GamePlayer::firstOrCreate(['game_id' => $game->id, 'player_id' => $player->id], ['called_up' => true]);
            GameEvent::create(['game_id' => $game->id, 'type' => 'sanction', 'subtype' => $key, 'minute' => 30, 'player_id' => $player->id]);
        }

        return $game;
    }

    #[Test]
    public function a_red_card_bans_for_the_competitions_red_ban_matches(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['red_ban_matches' => 2]);
        $p = $this->player($team->category);
        $game = $this->played($competition, '2026-10-01', [[$p, 'red']]);

        app(SuspensionEngine::class)->recompute($competition);

        $ban = Suspension::sole();
        $this->assertSame(['auto', 'matches', 2, 'red', $game->id, $competition->id, $team->id],
            [$ban->source, $ban->kind, $ban->matches_count, $ban->cause, $ban->game_id, $ban->competition_id, $ban->team_id]);
    }

    #[Test]
    public function yellows_add_up_to_the_threshold_then_the_count_restarts(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['yellow_threshold' => 2, 'yellow_ban_matches' => 1]);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'yellow']]);
        $second = $this->played($competition, '2026-10-08', [[$p, 'yellow'], [$p, 'two_minutes']]);
        $this->played($competition, '2026-10-15', [[$p, 'yellow']]);

        app(SuspensionEngine::class)->recompute($competition);

        $ban = Suspension::sole();
        $this->assertSame(['yellows', $second->id, 1], [$ban->cause, $ban->game_id, $ban->matches_count]);
    }

    #[Test]
    public function no_threshold_no_ban_and_zero_matches_means_no_ban(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['yellow_threshold' => null, 'red_ban_matches' => 0]);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'yellow'], [$p, 'yellow'], [$p, 'red']]);

        app(SuspensionEngine::class)->recompute($competition);

        $this->assertSame(0, Suspension::count());
    }

    #[Test]
    public function only_played_games_count_and_manual_bans_survive_a_recompute(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->played($competition, '2026-10-01', [[$p, 'red']], GameStatus::Postponed);
        Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 3, 'starts_on' => '2026-10-01']);

        app(SuspensionEngine::class)->recompute($competition);

        $this->assertSame(['manual'], Suspension::pluck('source')->all());
    }

    #[Test]
    public function saving_a_report_or_changing_the_rules_recomputes(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026, ['red_ban_matches' => 1]);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $game = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-01']);
        $game->update(['rules' => ['sanction_types' => self::CARDS] + $game->rules]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true, 'role' => 'starter']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('games.report.update', $game), [
            'period_scores' => [[0, 0], [0, 0]], 'extra_time_played' => false, 'our_score' => null, 'their_score' => null,
            'shootout_us' => null, 'shootout_them' => null, 'mvp_player_id' => null, 'notes' => null, 'players' => [],
            'events' => [['type' => 'sanction', 'subtype' => 'red', 'period' => 1, 'minute' => 40, 'player_id' => $p->id, 'related_player_id' => null]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Suspension::sole()->matches_count);

        $this->actingAs($admin)->put(route('competitions.update', $competition), [
            'name' => $competition->name, 'type' => 'league', 'game_format_id' => null,
            'red_ban_matches' => 3, 'yellow_threshold' => null, 'yellow_ban_matches' => 1, 'notes' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, Suspension::sole()->matches_count);

        $this->actingAs($admin)->delete(route('games.destroy', $game))->assertRedirect();
        $this->assertSame(0, Suspension::count());
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=SuspensionEngineTest`
Expected: FAIL (class `Suspension` not found).

- [ ] **Step 3: Migration and model**

`database/migrations/2026_10_17_100002_create_suspensions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match bans. "auto" rows are rebuilt from a competition's match reports
 * (SuspensionEngine); "manual" rows are federation or club decisions. A ban is
 * either N matches (served by the team's played matches) or a date range.
 * competition_id null = all competitions (manual only); team_id is the team
 * context used to authorize it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 8);
            $table->string('kind', 8);
            $table->unsignedTinyInteger('matches_count')->nullable();
            $table->string('starts_on', 10)->nullable();
            $table->string('ends_on', 10)->nullable();
            $table->string('cause', 8)->nullable();
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['competition_id', 'source']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensions');
    }
};
```

`app/Models/Suspension.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A match ban. source auto|manual; kind matches|dates. Served counts are never
 * stored — SuspensionStatus works them out from the played matches.
 */
class Suspension extends Model
{
    protected $fillable = [
        'player_id', 'competition_id', 'team_id', 'source', 'kind', 'matches_count',
        'starts_on', 'ends_on', 'cause', 'game_id', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['matches_count' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

In `app/Models/Game.php`, after `endsPlay()`, add:

```php
    /** What a sanction of this kind counts as for bans ('yellow' / 'red'), per this match's own rules. */
    public function countsAs(?string $sanctionKey): ?string
    {
        foreach ($this->rule('sanction_types', []) as $type) {
            if (($type['key'] ?? null) === $sanctionKey) {
                return in_array($type['counts_as'] ?? null, ['yellow', 'red'], true) ? $type['counts_as'] : null;
            }
        }

        return null;
    }
```

In `app/Models/Player.php`, add (import `HasMany` if it isn't already imported):

```php
    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }
```

- [ ] **Step 4: The engine**

`app/Services/Competitions/SuspensionEngine.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Suspension;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a competition's automatic bans from its played match reports, in
 * match order: a sanction counting as red gives red_ban_matches; sanctions
 * counting as yellow add up to yellow_threshold, give yellow_ban_matches and
 * the count restarts. Manual bans are never touched. Friendlies (no
 * competition) never reach here.
 */
final class SuspensionEngine
{
    public function recompute(Competition $competition): void
    {
        DB::transaction(function () use ($competition) {
            Suspension::where('competition_id', $competition->id)->where('source', 'auto')->delete();

            $games = Game::query()->where('competition_id', $competition->id)->where('status', GameStatus::Played->value)
                ->orderBy('date')->orderBy('id')->get(['id', 'date', 'rules']);
            if ($games->isEmpty()) {
                return;
            }
            $events = GameEvent::query()->whereIn('game_id', $games->modelKeys())->where('type', 'sanction')->whereNotNull('player_id')
                ->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get()->groupBy('game_id');

            $red = (int) $competition->red_ban_matches;
            $threshold = $competition->yellow_threshold === null ? null : (int) $competition->yellow_threshold;
            $yellowBan = (int) $competition->yellow_ban_matches;
            $yellows = [];

            foreach ($games as $game) {
                foreach ($events->get($game->id, []) as $event) {
                    $kind = $game->countsAs($event->subtype);
                    if ($kind === 'red' && $red > 0) {
                        $this->ban($competition, $event->player_id, $game, 'red', $red);
                    } elseif ($kind === 'yellow' && $threshold !== null) {
                        $yellows[$event->player_id] = ($yellows[$event->player_id] ?? 0) + 1;
                        if ($yellows[$event->player_id] >= $threshold) {
                            $yellows[$event->player_id] = 0;
                            if ($yellowBan > 0) {
                                $this->ban($competition, $event->player_id, $game, 'yellows', $yellowBan);
                            }
                        }
                    }
                }
            }
        });
    }

    private function ban(Competition $competition, int $playerId, Game $game, string $cause, int $matches): void
    {
        Suspension::create([
            'player_id' => $playerId,
            'competition_id' => $competition->id,
            'team_id' => $competition->team_id,
            'source' => 'auto',
            'kind' => 'matches',
            'matches_count' => $matches,
            'starts_on' => $game->date,
            'cause' => $cause,
            'game_id' => $game->id,
        ]);
    }
}
```

- [ ] **Step 5: Triggers**

- `app/Http/Controllers/GameReportController.php` → `update`: add `SuspensionEngine $engine` to the method signature. Inside the transaction closure (add `$engine` to its `use`), after `$minutes->refresh($game->fresh());`, add:

```php
            if ($game->competition_id !== null) {
                $engine->recompute($game->competition);
            }
```

- `app/Http/Controllers/GameController.php`:
  - `update`: add `SuspensionEngine $engine` to the signature. After `$game->update($data);`, add:

```php
        // A status change (played ↔ postponed) changes which reports count.
        if ($game->competition_id !== null) {
            $engine->recompute($game->competition);
        }
```

  - `destroy`: add `SuspensionEngine $engine` to the signature. Replace `$game->delete();` with:

```php
        $competition = $game->competition;
        $game->delete();
        if ($competition !== null) {
            $engine->recompute($competition);
        }
```

- `app/Http/Controllers/CompetitionController.php` → `update`: add `SuspensionEngine $engine` to the signature. Replace `$competition->update($data);` with:

```php
        $competition->fill($data);
        $rulesChanged = $competition->isDirty(['red_ban_matches', 'yellow_threshold', 'yellow_ban_matches']);
        $competition->save();
        if ($rulesChanged) {
            $engine->recompute($competition);
        }
```

- `app/Http/Controllers/GameCallUpController.php` → inside the `if ($removedEvents > 0 && …)` block, after the minutes refresh, add:

```php
                    if ($game->competition_id !== null) {
                        app(SuspensionEngine::class)->recompute($game->competition);
                    }
```

  This should only run when events were removed, so put it inside that `$removedEvents > 0` condition, even if the existing condition also checks the game status.

In each file, add `use App\Services\Competitions\SuspensionEngine;`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="SuspensionEngineTest|MatchReportTest|GamesTest|CompetitionsTest|CallUpTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_17_100002_create_suspensions_table.php app/Models/Suspension.php app/Services/Competitions/SuspensionEngine.php app/Models/Game.php app/Models/Player.php app/Http/Controllers/GameReportController.php app/Http/Controllers/GameController.php app/Http/Controllers/CompetitionController.php app/Http/Controllers/GameCallUpController.php tests/Feature/Competitions/SuspensionEngineTest.php
git commit -m "feat(competitions): automatic card suspensions rebuilt from match reports

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Suspension status and eligibility badges

**Files:**
- Create: `app/Services/Competitions/SuspensionStatus.php`, `resources/js/Components/Competitions/PlayerBadges.vue`
- Modify:
  - `app/Services/Competitions/PlayerFlags.php`: add `for()`.
  - `app/Http/Controllers/GameController.php`: call-up candidates get `flags`.
  - `app/Http/Controllers/TeamController.php`: roster rows get `flags`.
  - `app/Http/Controllers/CompetitionController.php`: squad rows get `flags`.
  - `resources/js/Pages/Games/Show.vue`, `resources/js/Pages/Teams/Show.vue`, `resources/js/Pages/Competitions/Show.vue`
- Test: `tests/Feature/Competitions/SuspensionStatusTest.php`, `tests/Feature/Competitions/PlayerFlagsTest.php`

**Interfaces:**
- Consumes: `Suspension`, `Game::countsAs()`, `DocumentChecklist::missingTypeSql()`, `InjurySpells`.
- Produces:
  - `SuspensionStatus::evaluate(Collection<int, Suspension> $suspensions, ?Game $at = null): array<int, array{served: ?int, remaining: ?int, active: bool, until: ?string}>`, keyed by suspension id.
  - `SuspensionStatus::forPlayers(array $playerIds, ?Game $game = null, ?Competition $competition = null): array<int, array{remaining: ?int, until: ?string, cause: ?string, reason: ?string, opponent: ?string, competition: ?string}>`, keyed by player id and holding only players with an applicable active ban.
  - `PlayerFlags::for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null): array<int, array{injured: bool, suspended: bool, suspension: ?array, no_licence: bool, documents_missing: bool, documents: list<string>, unpaid: bool, debt: ?float}>`.
  - `<PlayerBadges :flags />`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Competitions/SuspensionStatusTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Suspension;
use App\Services\Competitions\SuspensionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class SuspensionStatusTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_match_ban_counts_down_with_the_teams_played_competition_matches(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $trigger = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-01', 'status' => GameStatus::Played]);
        $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-08', 'status' => GameStatus::Played]);
        $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-12', 'status' => GameStatus::Postponed]);
        $this->game($team, ['date' => '2026-10-13', 'status' => GameStatus::Played]); // friendly: never counts
        $next = $this->game($team, ['competition_id' => $competition->id, 'date' => '2026-10-15']);
        $ban = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 2, 'cause' => 'red', 'game_id' => $trigger->id, 'starts_on' => '2026-10-01']);
        $status = app(SuspensionStatus::class);

        $this->assertSame(['served' => 1, 'remaining' => 1, 'active' => true, 'until' => null], $status->evaluate(collect([$ban]), $next)[$ban->id]);
        $this->assertArrayHasKey($p->id, $status->forPlayers([$p->id], $next));
        $this->assertSame([], $status->forPlayers([$p->id], $trigger), 'a ban never applies to the match that caused it');
        $friendly = $this->game($team, ['date' => '2026-10-20']);
        $this->assertSame([], $status->forPlayers([$p->id], $friendly), 'competition bans skip friendlies');
    }

    #[Test]
    public function date_bans_and_all_competition_bans(): void
    {
        $team = $this->team();
        $league = $this->competition($team, 2026);
        $cup = $this->competition($team, 2026, ['name' => 'Cup', 'type' => 'cup']);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1, 'starts_on' => '2026-10-01']);
        $this->game($team, ['competition_id' => $cup->id, 'date' => '2026-10-05', 'status' => GameStatus::Played]);
        $later = $this->game($team, ['competition_id' => $league->id, 'date' => '2026-10-10']);
        $status = app(SuspensionStatus::class);

        $this->assertSame([], $status->forPlayers([$p->id], $later), 'served by the cup match');

        Suspension::create(['player_id' => $p->id, 'competition_id' => null, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-30']);
        $this->assertSame('2026-10-30', $status->forPlayers([$p->id], $later)[$p->id]['until']);
        $friendly = $this->game($team, ['date' => '2026-10-11']);
        $this->assertArrayHasKey($p->id, $status->forPlayers([$p->id], $friendly), 'an all-competitions date ban covers friendlies too');
    }
}
```

`tests/Feature/Competitions/PlayerFlagsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Suspension;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class PlayerFlagsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function flags_cover_licence_documents_debt_and_suspension(): void
    {
        $team = $this->team();
        DocumentType::create(['code' => 'medical', 'name' => 'Medical', 'validity' => 'none', 'is_required' => false, 'required_for_matches' => true, 'is_active' => true]);
        $p = $this->player($team->category);
        $p->update(['outstanding_debt' => 1500]);
        $ok = $this->player($team->category, ['licence_number' => 'L-1']);
        Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString(), 'reason' => 'Discipline']);

        $flags = app(PlayerFlags::class)->for([$p->id, $ok->id], $this->admin());

        $this->assertTrue($flags[$p->id]['no_licence']);
        $this->assertTrue($flags[$p->id]['documents_missing']);
        $this->assertSame(['Medical'], $flags[$p->id]['documents']);
        $this->assertTrue($flags[$p->id]['unpaid']);
        $this->assertSame(1500.0, $flags[$p->id]['debt']);
        $this->assertTrue($flags[$p->id]['suspended']);
        $this->assertSame('Discipline', $flags[$p->id]['suspension']['reason']);
        $this->assertFalse($flags[$ok->id]['no_licence']);
        $this->assertFalse($flags[$ok->id]['unpaid']);
        $this->assertFalse($flags[$ok->id]['suspended']);
    }

    #[Test]
    public function the_debt_amount_is_hidden_without_finance_view_and_the_call_up_carries_flags(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $p->update(['outstanding_debt' => 900]);
        $this->onRoster($team, 2026, [$p]);
        $game = $this->game($team);
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['games' => ['view']]])->id]);

        $this->assertNull(app(PlayerFlags::class)->for([$p->id], $viewer)[$p->id]['debt']);

        $this->actingAs($viewer)->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.flags.unpaid', true)
                ->where('candidates.0.flags.debt', null));
    }
}
```

`AttendanceFixtures::player()` merges as `[defaults] + $extra`, so its `outstanding_debt => 0` default always wins. That is why the tests set the debt with `update()` after creating the player.

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter="SuspensionStatusTest|PlayerFlagsTest"`
Expected: FAIL (class `SuspensionStatus` not found / undefined method `for`).

- [ ] **Step 3: SuspensionStatus**

`app/Services/Competitions/SuspensionStatus.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Suspension;
use App\Support\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each ban has been served, as of a match (or now), and who is
 * suspended for a given match. A matches ban is served by the team's PLAYED
 * matches after it starts — in its competition, or (all competitions) any
 * competition match of a team the player is on that season; postponed,
 * cancelled and friendly matches never count. A date ban is active between
 * its two dates. A constant number of queries, whatever the list size.
 */
final class SuspensionStatus
{
    /**
     * @param  Collection<int, Suspension>  $suspensions
     * @return array<int, array{served: ?int, remaining: ?int, active: bool, until: ?string}>
     */
    public function evaluate(Collection $suspensions, ?Game $at = null): array
    {
        if ($suspensions->isEmpty()) {
            return [];
        }
        // Plain collections (tests) work too: wrap, then load the trigger matches once.
        $suspensions = (new \Illuminate\Database\Eloquent\Collection($suspensions->all()))->loadMissing('game');
        $atDate = $at?->date ?? now()->toDateString();
        $atId = $at?->id ?? PHP_INT_MAX;
        $before = fn ($g) => $g->date < $atDate || ($g->date === $atDate && $g->id < $atId);

        $matchBans = $suspensions->where('kind', 'matches');
        $competitionIds = $matchBans->whereNotNull('competition_id')->pluck('competition_id')->unique()->values();
        $byCompetition = $competitionIds->isEmpty() ? collect() : Game::query()
            ->whereIn('competition_id', $competitionIds)->where('status', GameStatus::Played->value)
            ->get(['id', 'date', 'competition_id'])->groupBy('competition_id');

        $everywhere = $matchBans->whereNull('competition_id');
        $playerTeams = [];
        $teamGames = collect();
        if ($everywhere->isNotEmpty()) {
            $pairs = DB::table('team_players')->whereIn('player_id', $everywhere->pluck('player_id')->unique())
                ->get(['player_id', 'team_id', 'season_start_year']);
            foreach ($pairs as $pair) {
                $playerTeams[(int) $pair->player_id][(int) $pair->season_start_year][] = (int) $pair->team_id;
            }
            $teamGames = Game::query()->whereIn('team_id', $pairs->pluck('team_id')->unique())
                ->whereNotNull('competition_id')->where('status', GameStatus::Played->value)
                ->get(['id', 'date', 'team_id'])->groupBy('team_id');
        }

        $out = [];
        foreach ($suspensions as $s) {
            if ($s->kind === 'dates') {
                $out[$s->id] = ['served' => null, 'remaining' => null, 'active' => $s->starts_on <= $atDate && $atDate <= $s->ends_on, 'until' => $s->ends_on];

                continue;
            }
            if ($s->competition_id !== null) {
                $trigger = $s->game;
                $after = $trigger
                    ? fn ($g) => $g->date > $trigger->date || ($g->date === $trigger->date && $g->id > $trigger->id)
                    : fn ($g) => $g->date >= (string) $s->starts_on;
                $served = collect($byCompetition->get($s->competition_id, []))->filter(fn ($g) => $after($g) && $before($g))->count();
            } else {
                $season = Season::forDate((string) $s->starts_on)->startYear;
                $served = collect($playerTeams[$s->player_id][$season] ?? [])
                    ->flatMap(fn (int $teamId) => $teamGames->get($teamId, []))->unique('id')
                    ->filter(fn ($g) => $g->date >= (string) $s->starts_on && $before($g))->count();
            }
            $served = min($served, (int) $s->matches_count);
            $out[$s->id] = ['served' => $served, 'remaining' => (int) $s->matches_count - $served, 'active' => $served < (int) $s->matches_count, 'until' => null];
        }

        return $out;
    }

    /**
     * Players banned for $game — or, without a game, for $competition, or
     * anywhere (roster view). One entry per banned player.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{remaining: ?int, until: ?string, cause: ?string, reason: ?string, opponent: ?string, competition: ?string}>
     */
    public function forPlayers(array $playerIds, ?Game $game = null, ?Competition $competition = null): array
    {
        if ($playerIds === []) {
            return [];
        }
        $list = Suspension::query()->whereIn('player_id', $playerIds)->with(['game.opponent', 'competition'])->get();
        if ($list->isEmpty()) {
            return [];
        }
        $competitionId = $game !== null ? $game->competition_id : $competition?->id;
        $atDate = $game?->date ?? now()->toDateString();
        $status = $this->evaluate($list, $game);

        $out = [];
        foreach ($list as $s) {
            if (! $status[$s->id]['active'] || ! $this->applies($s, $game, $competitionId, $atDate)) {
                continue;
            }
            $out[$s->player_id] ??= [
                'remaining' => $status[$s->id]['remaining'],
                'until' => $status[$s->id]['until'],
                'cause' => $s->cause,
                'reason' => $s->reason,
                'opponent' => $s->game?->opponent?->name,
                'competition' => $s->competition?->name,
            ];
        }

        return $out;
    }

    private function applies(Suspension $s, ?Game $game, ?int $competitionId, string $atDate): bool
    {
        // Earned in this match (or a later one): it starts after it.
        if ($s->game !== null && $game !== null
            && ($s->game->date > $game->date || ($s->game->date === $game->date && $s->game->id >= $game->id))) {
            return false;
        }
        if ($s->source === 'manual' && $s->starts_on !== null && $s->starts_on > $atDate) {
            return false;
        }
        if ($game === null && $competitionId === null) {
            return true;
        }
        if ($s->competition_id !== null) {
            return $s->competition_id === $competitionId;
        }

        // All competitions: a date ban covers every match; a matches ban only competition matches.
        return $s->kind === 'dates' || $competitionId !== null;
    }
}
```

- [ ] **Step 4: PlayerFlags::for**

In `app/Services/Competitions/PlayerFlags.php`:
- Change the constructor to `public function __construct(private readonly InjurySpells $spells, private readonly SuspensionStatus $suspensions) {}`.
- Update the class docblock: "suspended, no licence, documents for matches and unpaid are in for()".
- Add the imports `App\Models\Competition`, `App\Models\DocumentType`, `App\Models\Game`, `App\Models\Player`, `App\Models\User` and `App\Services\Player\DocumentChecklist`.
- Add:

```php
    /**
     * Every badge for a list of players, in one pass. The owed amount is only
     * given to viewers with finance/view; everyone else just sees "unpaid".
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{injured: bool, suspended: bool, suspension: ?array, no_licence: bool, documents_missing: bool, documents: list<string>, unpaid: bool, debt: ?float}>
     */
    public function for(array $playerIds, ?User $viewer = null, ?Game $game = null, ?Competition $competition = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        if ($ids === []) {
            return [];
        }
        $injured = $this->injured();
        $suspended = $this->suspensions->forPlayers($ids, $game, $competition);
        $types = DocumentType::query()->where('is_active', true)->where('required_for_matches', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        $query = Player::query()->whereIn('id', $ids)->select(['id', 'licence_number', 'outstanding_debt']);
        foreach ($types as $type) {
            [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
            $query->selectRaw("{$sql} as doc_{$type->id}", $bindings);
        }
        $seeMoney = (bool) $viewer?->hasPermission('finance', 'view');

        $flags = [];
        foreach ($query->get() as $player) {
            $documents = $types->filter(fn (DocumentType $t) => (int) $player->{'doc_'.$t->id} === 1)
                ->map(fn (DocumentType $t) => (string) ($t->localized_name ?? $t->name))->values()->all();
            $debt = (float) $player->outstanding_debt;
            $flags[$player->id] = [
                'injured' => isset($injured[$player->id]),
                'suspended' => isset($suspended[$player->id]),
                'suspension' => $suspended[$player->id] ?? null,
                'no_licence' => trim((string) $player->licence_number) === '',
                'documents_missing' => $documents !== [],
                'documents' => $documents,
                'unpaid' => $debt > 0,
                'debt' => $seeMoney && $debt > 0 ? $debt : null,
            ];
        }

        return $flags;
    }
```

Check that `DocumentType` exposes `localized_name`, either an accessor or `HasLocalizedName`. If it doesn't, use `$t->name`.

- [ ] **Step 5: Wire the badges into the three lists**

`app/Http/Controllers/GameController.php` → `show`: compute the candidate list once and attach flags. Replace the `'candidates' => (function () … })(),` entry with:

```php
            'candidates' => (function () use ($game, $candidates, $injured, $flags, $request) {
                $rows = $game->players->keyBy('player_id');
                $list = $candidates->for($game);
                $badges = $flags->for($list->pluck('id')->all(), $request->user(), $game);

                return $list->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $p->category?->localized_name,
                    'shirt_number' => $rows->get($p->id)?->shirt_number ?? $p->shirt_number,
                    'called_up' => (bool) $rows->get($p->id)?->called_up,
                    'not_called_reason' => $rows->get($p->id)?->not_called_reason,
                    'injured' => isset($injured[$p->id]),
                    'flags' => $badges[$p->id] ?? null,
                ])->values();
            })(),
```

Keep any other keys the current mapping has. Compare with the existing closure and add `flags` without dropping anything.

`app/Http/Controllers/TeamController.php` → `show`: after building `$roster`, attach `flags`:

```php
        $badges = $flags->for($roster->pluck('player_id')->all(), $request->user());
        $roster = $roster->map(fn (array $row) => $row + ['flags' => $badges[$row['player_id']] ?? null])->values();
```

`app/Http/Controllers/CompetitionController.php` → `show`: inject `PlayerFlags $flags` and add `use App\Services\Competitions\PlayerFlags;`. Build the roster collection into a variable, then attach the flags the same way:

```php
        $badges = $flags->for($roster->pluck('player_id')->all(), $request->user(), null, $competition);
        $roster = $roster->map(fn (array $row) => $row + ['flags' => $badges[$row['player_id']] ?? null])->values();
```

`resources/js/Components/Competitions/PlayerBadges.vue`:

```vue
<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

// Eligibility warnings next to a player's name — information only, never a block.
const { t } = useI18n();
const props = defineProps({ flags: { type: Object, default: null } });

const suspensionTitle = computed(() => {
    const s = props.flags?.suspension;
    if (!s) return '';
    const what = s.until ? t('comp.flag.suspended_until', { date: s.until }) : t('comp.flag.suspended_left', { n: s.remaining });
    const why = s.reason || (s.cause ? t(`comp.flag.cause_${s.cause}`) : '');
    return [what, why, s.opponent].filter(Boolean).join(' — ');
});
const debtTitle = computed(() => (props.flags?.debt != null ? t('comp.flag.debt', { amount: Number(props.flags.debt).toLocaleString() }) : ''));
const chip = 'rounded px-1.5 py-0.5 text-xs';
</script>

<template>
    <span v-if="flags" class="inline-flex flex-wrap items-center gap-1 align-middle">
        <span v-if="flags.injured" :class="chip" class="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
        <span v-if="flags.suspended" :class="chip" :title="suspensionTitle" class="bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">⛔ {{ t('comp.flag.suspended') }}</span>
        <span v-if="flags.no_licence" :class="chip" class="bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200">🪪 {{ t('comp.flag.no_licence') }}</span>
        <span v-if="flags.documents_missing" :class="chip" :title="flags.documents.join(', ')" class="bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200">📄 {{ t('comp.flag.documents') }}</span>
        <span v-if="flags.unpaid" :class="chip" :title="debtTitle" class="bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200">💰 {{ t('comp.flag.unpaid') }}</span>
    </span>
</template>
```

Use it in the pages, importing it with `import PlayerBadges from '@/Components/Competitions/PlayerBadges.vue';`:
- `resources/js/Pages/Games/Show.vue` (call-up table): replace the `<span v-if="row.injured" …>` badge with `<PlayerBadges :flags="row.flags" class="ms-2" />`. Keep the `injured` key in the data, because LineupBoard uses it.
- `resources/js/Pages/Teams/Show.vue` (roster table): replace the injured `<span>` with `<PlayerBadges :flags="row.flags" class="ms-2" />`.
- `resources/js/Pages/Competitions/Show.vue` (squad list): after the player name `<span class="font-medium">{{ r.name }}</span>`, add `<PlayerBadges :flags="r.flags" />`.

- [ ] **Step 6: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.flag.suspended": "Suspended", "comp.flag.suspended_left": "{n} match(es) left", "comp.flag.suspended_until": "until {date}",
    "comp.flag.cause_red": "red card", "comp.flag.cause_yellows": "yellow cards", "comp.flag.no_licence": "No licence",
    "comp.flag.documents": "Documents", "comp.flag.unpaid": "Unpaid", "comp.flag.debt": "Owes {amount}"},
  "fr": {"comp.flag.suspended": "Suspendu", "comp.flag.suspended_left": "{n} match(s) restant(s)", "comp.flag.suspended_until": "jusqu'au {date}",
    "comp.flag.cause_red": "carton rouge", "comp.flag.cause_yellows": "cartons jaunes", "comp.flag.no_licence": "Sans licence",
    "comp.flag.documents": "Documents", "comp.flag.unpaid": "Impayé", "comp.flag.debt": "Doit {amount}"},
  "ar": {"comp.flag.suspended": "موقوف", "comp.flag.suspended_left": "{n} مباراة متبقية", "comp.flag.suspended_until": "حتى {date}",
    "comp.flag.cause_red": "بطاقة حمراء", "comp.flag.cause_yellows": "بطاقات صفراء", "comp.flag.no_licence": "بلا إجازة",
    "comp.flag.documents": "وثائق ناقصة", "comp.flag.unpaid": "غير مسدد", "comp.flag.debt": "عليه {amount}"}
}
EOF
```

- [ ] **Step 7: Build and run**

Run `npm run build`, then `php artisan test --filter="SuspensionStatusTest|PlayerFlagsTest|CallUpTest|TeamsTest|CompetitionsTest|LineupTest"`.
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Competitions/SuspensionStatus.php app/Services/Competitions/PlayerFlags.php app/Http/Controllers/GameController.php app/Http/Controllers/TeamController.php app/Http/Controllers/CompetitionController.php resources/js/Components/Competitions/PlayerBadges.vue resources/js/Pages/Games/Show.vue resources/js/Pages/Teams/Show.vue resources/js/Pages/Competitions/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/SuspensionStatusTest.php tests/Feature/Competitions/PlayerFlagsTest.php
git commit -m "feat(competitions): suspended, licence, documents and unpaid badges on call-ups, squads and rosters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Manual suspensions and the Suspensions tab

**Files:**
- Create: `app/Http/Controllers/CompetitionSuspensionController.php`
- Modify:
  - `app/Http/Controllers/CompetitionController.php`: `show` passes `suspensions`.
  - `resources/js/Pages/Competitions/Show.vue`: Suspensions tab.
  - `routes/web.php`, `config/permissions.php`, `app/Services/Activity/ActivityAction.php`, `config/activity.php`, `tests/Unit/ActivityRecorderTest.php` (count 106 → 108).
- Test: `tests/Feature/Competitions/ManualSuspensionsTest.php`

**Interfaces:**
- Consumes: `Suspension`, `SuspensionStatus::evaluate()`, `TeamAccess`.
- Produces:
  - Routes `competitions.suspensions.store` (`POST /competitions/{competition}/suspensions`) and `competitions.suspensions.destroy` (`DELETE /competitions/{competition}/suspensions/{suspension}`), both mapped to competitions/edit.
  - The `suspensions` prop on `Competitions/Show`: `list<{id, player, source, kind, cause, scope ('competition'|'all'), matches_count, served, remaining, active, starts_on, ends_on, reason, trigger: {date, opponent}|null}>`.
  - `ActivityAction::SUSPENSION_ADDED` and `SUSPENSION_REMOVED`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/ManualSuspensionsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Suspension;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class ManualSuspensionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_manual_ban_is_added_in_matches_or_dates_and_listed(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 2, 'reason' => 'Federation letter 12/26',
        ])->assertSessionHas('success', 'flash.suspension_added');
        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), [
            'player_id' => $p->id, 'scope' => 'all', 'kind' => 'dates', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame([['competition', 2], ['all', null]],
            Suspension::orderBy('id')->get()->map(fn ($s) => [$s->competition_id ? 'competition' : 'all', $s->matches_count])->all());
        $this->assertSame($admin->id, Suspension::first()->created_by);

        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->has('suspensions', 2)->where('suspensions.0.reason', 'Federation letter 12/26')); // active first; the date ban starts in November
    }

    #[Test]
    public function validation_and_only_manual_bans_can_be_deleted(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), ['player_id' => $outsider->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 1])
            ->assertSessionHasErrors('player_id');
        $this->actingAs($admin)->post(route('competitions.suspensions.store', $competition), ['player_id' => $p->id, 'scope' => 'competition', 'kind' => 'dates', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-01'])
            ->assertSessionHasErrors('ends_on');

        $auto = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'auto', 'kind' => 'matches', 'matches_count' => 1]);
        $this->actingAs($admin)->delete(route('competitions.suspensions.destroy', [$competition, $auto]))->assertForbidden();

        $manual = Suspension::create(['player_id' => $p->id, 'competition_id' => $competition->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'matches', 'matches_count' => 1]);
        $this->actingAs($admin)->delete(route('competitions.suspensions.destroy', [$competition, $manual]))->assertSessionHas('success', 'flash.suspension_removed');
        $this->assertModelMissing($manual);
    }

    #[Test]
    public function coaches_are_scoped_and_routes_need_competition_edit(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $p = $this->player($team->category);
        $this->onRoster($team, 2026, [$p]);

        $this->actingAs($this->coach())->post(route('competitions.suspensions.store', $competition), ['player_id' => $p->id, 'scope' => 'competition', 'kind' => 'matches', 'matches_count' => 1])
            ->assertForbidden();
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.suspensions.store'));
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.suspensions.destroy'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=ManualSuspensionsTest`
Expected: FAIL (route not defined).

- [ ] **Step 3: Controller**

`app/Http/Controllers/CompetitionSuspensionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Suspension;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual bans (federation decisions, club discipline), added from a
 * competition page: for this competition or all competitions, as N matches or
 * a date range. Automatic bans come from match reports and cannot be deleted.
 */
class CompetitionSuspensionController extends Controller
{
    public function store(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $roster = DB::table('team_players')->where('team_id', $competition->team_id)
            ->where('season_start_year', $competition->season_start_year)->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'player_id' => ['required', 'integer', Rule::in($roster)],
            'scope' => ['required', Rule::in(['competition', 'all'])],
            'kind' => ['required', Rule::in(['matches', 'dates'])],
            'matches_count' => ['nullable', 'required_if:kind,matches', 'integer', 'min:1', 'max:50'],
            'starts_on' => ['nullable', 'required_if:kind,dates', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_if:kind,dates', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $dates = $data['kind'] === 'dates';

        Suspension::create([
            'player_id' => $data['player_id'],
            'competition_id' => $data['scope'] === 'competition' ? $competition->id : null,
            'team_id' => $competition->team_id,
            'source' => 'manual',
            'kind' => $data['kind'],
            'matches_count' => $dates ? null : (int) $data['matches_count'],
            // A matches ban starts counting from today unless a start date is given.
            'starts_on' => $data['starts_on'] ?? now()->toDateString(),
            'ends_on' => $dates ? $data['ends_on'] : null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'flash.suspension_added');
    }

    public function destroy(Request $request, Competition $competition, Suspension $suspension): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        abort_unless($suspension->source === 'manual'
            && ($suspension->competition_id === $competition->id || ($suspension->competition_id === null && $suspension->team_id === $competition->team_id)), 403);
        $suspension->delete();

        return back()->with('success', 'flash.suspension_removed');
    }
}
```

- [ ] **Step 4: Props, routes, permissions, activity**

In `app/Http/Controllers/CompetitionController.php` → `show`: inject `SuspensionStatus $status`, and add the imports `App\Models\Suspension` and `App\Services\Competitions\SuspensionStatus`. Add the prop:

```php
            'suspensions' => (function () use ($competition, $team, $season, $status) {
                $rosterIds = DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season)->pluck('player_id');
                $list = Suspension::query()
                    ->where(fn ($q) => $q->where('competition_id', $competition->id)
                        ->orWhere(fn ($q) => $q->whereNull('competition_id')->whereIn('player_id', $rosterIds)))
                    ->with(['player', 'game.opponent'])->orderByDesc('id')->get();
                $eval = $status->evaluate($list);

                return $list->map(fn (Suspension $s) => [
                    'id' => $s->id,
                    'player' => $s->player->fullname,
                    'source' => $s->source,
                    'kind' => $s->kind,
                    'cause' => $s->cause,
                    'scope' => $s->competition_id ? 'competition' : 'all',
                    'matches_count' => $s->matches_count,
                    'served' => $eval[$s->id]['served'],
                    'remaining' => $eval[$s->id]['remaining'],
                    'active' => $eval[$s->id]['active'],
                    'starts_on' => $s->starts_on,
                    'ends_on' => $s->ends_on,
                    'reason' => $s->reason,
                    'trigger' => $s->game ? ['date' => $s->game->date, 'opponent' => $s->game->opponent?->name] : null,
                ])->sortByDesc('active')->values();
            })(),
```

In `routes/web.php`, add `use App\Http\Controllers\CompetitionSuspensionController;` in alphabetical order. After the `competitions.squad.update` route, add:

```php
        Route::post('/competitions/{competition}/suspensions', [CompetitionSuspensionController::class, 'store'])->name('competitions.suspensions.store');
        Route::delete('/competitions/{competition}/suspensions/{suspension}', [CompetitionSuspensionController::class, 'destroy'])->name('competitions.suspensions.destroy');
```

In `config/permissions.php` → `overrides`, add:

```php
        // Manual bans change who may play: both need competitions/edit, not add/delete.
        'competitions.suspensions.store' => ['competitions', 'edit'],
        'competitions.suspensions.destroy' => ['competitions', 'edit'],
```

In `app/Services/Activity/ActivityAction.php`:
- Add `const SUSPENSION_ADDED = 'suspension_added';` and `const SUSPENSION_REMOVED = 'suspension_removed';` after `REPORT_SAVED`, with a blank line between constants.
- Add both to `ALL` and to `AREAS['competitions']` after `self::REPORT_SAVED,`.

In `config/activity.php`, add:

```php
    'competitions.suspensions.store' => ActivityAction::SUSPENSION_ADDED,
    'competitions.suspensions.destroy' => ActivityAction::SUSPENSION_REMOVED,
```

In `tests/Unit/ActivityRecorderTest.php`, change 106 to 108.

- [ ] **Step 5: Suspensions tab**

In `resources/js/Pages/Competitions/Show.vue`:
- Add `suspensions: Array` to the props.
- Change `tabs` to `['squad', 'games', 'record', 'suspensions', 'rules']`.
- Add to `<script setup>`:

```js
const banForm = useForm({ player_id: '', scope: 'competition', kind: 'matches', matches_count: 1, starts_on: '', ends_on: '', reason: '' });
function addBan() {
    banForm.transform((d) => ({ ...d, matches_count: d.kind === 'matches' ? d.matches_count : null, starts_on: d.starts_on || null, ends_on: d.kind === 'dates' ? d.ends_on : null }))
        .post(route('competitions.suspensions.store', props.competition.id), { preserveScroll: true, onSuccess: () => banForm.reset() });
}
function removeBan(id) {
    router.delete(route('competitions.suspensions.destroy', [props.competition.id, id]), { preserveScroll: true });
}
const banLabel = (s) => (s.kind === 'dates'
    ? t('comp.ban.dates', { from: s.starts_on, to: s.ends_on })
    : t('comp.ban.matches', { served: s.served ?? 0, total: s.matches_count }));
```

- After the `record` section, add:

```vue
            <section v-if="tab === 'suspensions'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="text-xs uppercase text-slate-500"><tr>
                        <th class="py-1 text-start">{{ t('comp.player') }}</th><th class="py-1 text-start">{{ t('comp.ban.cause') }}</th>
                        <th class="py-1 text-start">{{ t('comp.ban.length') }}</th><th class="py-1 text-start">{{ t('status') }}</th><th class="w-10"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in suspensions" :key="s.id" :class="{ 'opacity-60': !s.active }">
                            <td class="py-1 font-medium">{{ s.player }}</td>
                            <td class="py-1">
                                <template v-if="s.source === 'auto'">{{ t(`comp.flag.cause_${s.cause}`) }}<span v-if="s.trigger" class="text-xs text-slate-500"> · <bdi dir="ltr">{{ s.trigger.date }}</bdi> {{ s.trigger.opponent }}</span></template>
                                <template v-else>{{ s.reason || t('comp.ban.manual') }}</template>
                                <span v-if="s.scope === 'all'" class="ms-1 rounded bg-slate-100 px-1 text-xs dark:bg-slate-800">{{ t('comp.ban.all_competitions') }}</span>
                            </td>
                            <td class="py-1"><bdi dir="ltr">{{ banLabel(s) }}</bdi></td>
                            <td class="py-1">{{ s.active ? t('comp.ban.active') : t('comp.ban.served') }}</td>
                            <td class="py-1 text-end"><IconButton v-if="canManage && s.source === 'manual'" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="removeBan(s.id)" /></td>
                        </tr>
                        <tr v-if="!suspensions.length"><td colspan="5" class="py-6 text-center text-slate-500">{{ t('comp.ban.none') }}</td></tr>
                    </tbody>
                </table>

                <form v-if="canManage" class="grid gap-3 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-3" @submit.prevent="addBan">
                    <h3 class="text-sm font-semibold sm:col-span-3">{{ t('comp.ban.add_manual') }}</h3>
                    <label class="text-sm">{{ t('comp.player') }}
                        <select v-model="banForm.player_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                            <option v-for="r in roster" :key="r.player_id" :value="r.player_id">{{ r.name }}</option>
                        </select>
                        <InputError :message="banForm.errors.player_id ? t(banForm.errors.player_id) : ''" />
                    </label>
                    <label class="text-sm">{{ t('comp.ban.scope') }}
                        <select v-model="banForm.scope" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="competition">{{ t('comp.ban.this_competition') }}</option>
                            <option value="all">{{ t('comp.ban.all_competitions') }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.ban.kind') }}
                        <select v-model="banForm.kind" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="matches">{{ t('comp.ban.kind_matches') }}</option>
                            <option value="dates">{{ t('comp.ban.kind_dates') }}</option>
                        </select>
                    </label>
                    <label v-if="banForm.kind === 'matches'" class="text-sm">{{ t('comp.ban.matches_count') }}
                        <TextInput v-model.number="banForm.matches_count" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="banForm.errors.matches_count" />
                    </label>
                    <label class="text-sm">{{ t('comp.ban.starts_on') }}
                        <TextInput v-model="banForm.starts_on" type="date" class="mt-1 w-full" :required="banForm.kind === 'dates'" />
                        <InputError :message="banForm.errors.starts_on" />
                    </label>
                    <label v-if="banForm.kind === 'dates'" class="text-sm">{{ t('comp.ban.ends_on') }}
                        <TextInput v-model="banForm.ends_on" type="date" class="mt-1 w-full" required />
                        <InputError :message="banForm.errors.ends_on" />
                    </label>
                    <label class="text-sm sm:col-span-2">{{ t('comp.ban.reason') }} <TextInput v-model="banForm.reason" class="mt-1 w-full" /></label>
                    <div class="flex items-end"><PrimaryButton :disabled="banForm.processing">{{ t('add') }}</PrimaryButton></div>
                </form>
            </section>
```

Make sure `IconButton`, `TextInput`, `InputError`, `PrimaryButton`, `router` and `useForm` are imported in that file. Import any that are missing.

- [ ] **Step 6: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.tab_suspensions": "Suspensions", "comp.ban.cause": "Cause", "comp.ban.length": "Ban", "comp.ban.manual": "Manual decision",
    "comp.ban.all_competitions": "All competitions", "comp.ban.this_competition": "This competition", "comp.ban.matches": "{served}/{total} matches served",
    "comp.ban.dates": "{from} → {to}", "comp.ban.active": "Active", "comp.ban.served": "Served", "comp.ban.none": "No suspensions.",
    "comp.ban.add_manual": "Add a manual suspension", "comp.ban.scope": "Applies to", "comp.ban.kind": "Type", "comp.ban.kind_matches": "Number of matches",
    "comp.ban.kind_dates": "Date range", "comp.ban.matches_count": "Matches", "comp.ban.starts_on": "From", "comp.ban.ends_on": "Until", "comp.ban.reason": "Reason",
    "flash.suspension_added": "Suspension added.", "flash.suspension_removed": "Suspension removed.",
    "activity.action.suspension_added": "Suspensions added", "activity.action.suspension_removed": "Suspensions removed"},
  "fr": {"comp.tab_suspensions": "Suspensions", "comp.ban.cause": "Motif", "comp.ban.length": "Sanction", "comp.ban.manual": "Décision manuelle",
    "comp.ban.all_competitions": "Toutes compétitions", "comp.ban.this_competition": "Cette compétition", "comp.ban.matches": "{served}/{total} matchs purgés",
    "comp.ban.dates": "{from} → {to}", "comp.ban.active": "En cours", "comp.ban.served": "Purgée", "comp.ban.none": "Aucune suspension.",
    "comp.ban.add_manual": "Ajouter une suspension manuelle", "comp.ban.scope": "S'applique à", "comp.ban.kind": "Type", "comp.ban.kind_matches": "Nombre de matchs",
    "comp.ban.kind_dates": "Période", "comp.ban.matches_count": "Matchs", "comp.ban.starts_on": "Du", "comp.ban.ends_on": "Au", "comp.ban.reason": "Motif",
    "flash.suspension_added": "Suspension ajoutée.", "flash.suspension_removed": "Suspension supprimée.",
    "activity.action.suspension_added": "Suspensions ajoutées", "activity.action.suspension_removed": "Suspensions supprimées"},
  "ar": {"comp.tab_suspensions": "الإيقافات", "comp.ban.cause": "السبب", "comp.ban.length": "العقوبة", "comp.ban.manual": "قرار يدوي",
    "comp.ban.all_competitions": "كل المنافسات", "comp.ban.this_competition": "هذه المنافسة", "comp.ban.matches": "{served}/{total} مباريات منفذة",
    "comp.ban.dates": "{from} → {to}", "comp.ban.active": "سارٍ", "comp.ban.served": "منفّذ", "comp.ban.none": "لا توجد إيقافات.",
    "comp.ban.add_manual": "إضافة إيقاف يدوي", "comp.ban.scope": "يسري على", "comp.ban.kind": "النوع", "comp.ban.kind_matches": "عدد المباريات",
    "comp.ban.kind_dates": "فترة زمنية", "comp.ban.matches_count": "المباريات", "comp.ban.starts_on": "من", "comp.ban.ends_on": "إلى", "comp.ban.reason": "السبب",
    "flash.suspension_added": "تمت إضافة الإيقاف.", "flash.suspension_removed": "تم حذف الإيقاف.",
    "activity.action.suspension_added": "إيقافات أُضيفت", "activity.action.suspension_removed": "إيقافات حُذفت"}
}
EOF
```

- [ ] **Step 7: Build and run**

Run `npm run build`, then `php artisan test --filter="ManualSuspensionsTest|CompetitionsTest|Activity|UsersBatch|FlashTranslation"`.
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/CompetitionSuspensionController.php app/Http/Controllers/CompetitionController.php resources/js/Pages/Competitions/Show.vue routes/web.php config/permissions.php app/Services/Activity/ActivityAction.php config/activity.php tests/Unit/ActivityRecorderTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/ManualSuspensionsTest.php
git commit -m "feat(competitions): manual suspensions and a Suspensions tab on competitions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The statistics engine, and the team and competition Stats tabs

**Files:**
- Create: `app/Services/Competitions/MatchStats.php`, `resources/js/Components/Competitions/StatsLeaders.vue`
- Modify: `app/Http/Controllers/TeamController.php`, `app/Http/Controllers/CompetitionController.php` (`stats` prop), `resources/js/Pages/Teams/Show.vue`, `resources/js/Pages/Competitions/Show.vue`
- Test: `tests/Feature/Competitions/MatchStatsTest.php`

**Interfaces:**
- Consumes: `Game::rule()`, `Game::countsAs()`, `CompetitionRecord::fromGames()`.
- Produces:
  - `MatchStats::players(int $season, ?int $teamId = null, ?int $competitionId = null, ?int $playerId = null): list<array{player_id, name, sport, appearances, starts, minutes, sets, goals, assists, yellows, reds, other_sanctions, avg_rating: ?float, rated, motm}>`, one row per player and sport, sorted by name.
  - `MatchStats::leaderboards(list<array> $rows, int $top = 5): array{goals, assists, minutes, appearances, cards, rating}`.
  - `MatchStats::games(int $playerId, int $season): list<array{game_id, date, team, opponent, competition, side, our_score, their_score, role, minutes, sets, goals, assists, cards: list<string>, rating, motm}>`.
  - `<StatsLeaders :leaders :show-rating />`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/MatchStatsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\MatchStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function playedGame($team, array $extra = []): Game
    {
        $game = $this->game($team, $extra + ['status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        $game->update(['rules' => ['sanction_types' => [
            ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
            ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
            ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
        ]] + $game->rules]);

        return $game;
    }

    #[Test]
    public function totals_count_appearances_starts_minutes_goals_assists_cards_ratings_and_motm(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $g1 = $this->playedGame($team, ['date' => '2026-10-01']);
        $g2 = $this->playedGame($team, ['date' => '2026-10-08']);
        $scheduled = $this->game($team, ['date' => '2026-10-20']);
        foreach ([$g1, $g2] as $g) {
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90, 'rating' => 8, 'is_mvp' => $g->is($g1)]);
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $b->id, 'called_up' => true, 'role' => 'bench', 'played' => $g->is($g2), 'minutes_played' => $g->is($g2) ? 20 : 0]);
        }
        GamePlayer::create(['game_id' => $scheduled->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90]);
        GameEvent::create(['game_id' => $g1->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id, 'related_player_id' => $b->id]);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'goal', 'subtype' => 'opponent_own_goal']);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'sanction', 'subtype' => 'yellow', 'player_id' => $a->id]);
        GameEvent::create(['game_id' => $g2->id, 'type' => 'sanction', 'subtype' => 'foul', 'player_id' => $a->id]);

        $rows = collect(app(MatchStats::class)->players(2026, $team->id))->keyBy('player_id');

        $this->assertSame([2, 2, 180, 1, 0, 1, 0, 1, 8.0, 2, 1], [
            $rows[$a->id]['appearances'], $rows[$a->id]['starts'], $rows[$a->id]['minutes'], $rows[$a->id]['goals'], $rows[$a->id]['assists'],
            $rows[$a->id]['yellows'], $rows[$a->id]['reds'], $rows[$a->id]['other_sanctions'], $rows[$a->id]['avg_rating'], $rows[$a->id]['rated'], $rows[$a->id]['motm'],
        ]);
        $this->assertSame([1, 0, 20, 1], [$rows[$b->id]['appearances'], $rows[$b->id]['starts'], $rows[$b->id]['minutes'], $rows[$b->id]['assists']]);
        $this->assertSame('football', $rows[$a->id]['sport']);

        $leaders = MatchStats::leaderboards($rows->values()->all());
        $this->assertSame($a->id, $leaders['goals'][0]['player_id']);
        $this->assertSame([], $leaders['rating'], 'rating board needs 3 rated matches');

        $games = app(MatchStats::class)->games($a->id, 2026);
        $this->assertCount(2, $games);
        $this->assertSame(1, collect($games)->firstWhere('game_id', $g1->id)['goals']);
    }

    #[Test]
    public function team_and_competition_pages_carry_stats(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $a = $this->player($team->category);
        $g = $this->playedGame($team, ['competition_id' => $competition->id]);
        GamePlayer::create(['game_id' => $g->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90]);
        GameEvent::create(['game_id' => $g->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('teams.show', ['team' => $team, 'season' => 2026]))
            ->assertInertia(fn (Assert $page) => $page->where('stats.leaders.goals.0.player_id', $a->id)->where('stats.record.wins', 1));
        $this->actingAs($admin)->get(route('competitions.show', $competition))
            ->assertInertia(fn (Assert $page) => $page->where('stats.leaders.goals.0.goals', 1));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=MatchStatsTest`
Expected: FAIL (class not found).

- [ ] **Step 3: MatchStats**

`app/Services/Competitions/MatchStats.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Player;

/**
 * Player statistics from played matches: an appearance is a called-up player
 * marked "played" in a played match. Three narrow queries (games, players,
 * events) aggregated here — portable across sqlite and MySQL, and the
 * yellow/red mapping needs each match's own rules anyway. One row per player
 * and sport (minutes and sets never mix).
 */
final class MatchStats
{
    /** @return list<array<string, mixed>> */
    public function players(int $season, ?int $teamId = null, ?int $competitionId = null, ?int $playerId = null): array
    {
        $games = Game::query()->where('status', GameStatus::Played->value)->where('season_start_year', $season)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when($competitionId, fn ($q) => $q->where('competition_id', $competitionId))
            ->get(['id', 'rules'])->keyBy('id');
        if ($games->isEmpty()) {
            return [];
        }
        $rows = GamePlayer::query()->whereIn('game_id', $games->keys())->where('called_up', true)
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId))
            ->get(['game_id', 'player_id', 'role', 'played', 'minutes_played', 'sets_played', 'rating', 'is_mvp']);
        if ($rows->isEmpty()) {
            return [];
        }
        $events = GameEvent::query()->whereIn('game_id', $games->keys())->whereIn('type', ['goal', 'sanction'])
            ->get(['game_id', 'type', 'subtype', 'player_id', 'related_player_id']);
        $names = Player::query()->whereIn('id', $rows->pluck('player_id')->unique())->get(['id', ...Player::NAME_COLUMNS])->keyBy('id');

        $sport = fn (int $gameId) => (string) ($games[$gameId]->rule('sport') ?? 'other');
        $stats = [];
        $slot = function (int $pid, string $sp) use (&$stats, $names) {
            return $stats["{$pid}|{$sp}"] ??= [
                'player_id' => $pid, 'name' => $names[$pid]?->fullname ?? '', 'sport' => $sp,
                'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'sets' => 0, 'goals' => 0, 'assists' => 0,
                'yellows' => 0, 'reds' => 0, 'other_sanctions' => 0, 'avg_rating' => null, 'rated' => 0, 'motm' => 0, 'rating_sum' => 0,
            ];
        };

        foreach ($rows as $row) {
            $key = "{$row->player_id}|{$sport($row->game_id)}";
            $slot($row->player_id, $sport($row->game_id));
            if ($row->played) {
                $stats[$key]['appearances']++;
                if ($row->role === 'starter') {
                    $stats[$key]['starts']++;
                }
            }
            $stats[$key]['minutes'] += (int) $row->minutes_played;
            $stats[$key]['sets'] += (int) $row->sets_played;
            if ($row->rating !== null) {
                $stats[$key]['rating_sum'] += (int) $row->rating;
                $stats[$key]['rated']++;
            }
            if ($row->is_mvp) {
                $stats[$key]['motm']++;
            }
        }

        foreach ($events as $event) {
            $sp = $sport($event->game_id);
            if ($event->type === 'goal') {
                if ($event->subtype !== 'opponent_own_goal' && $event->player_id && isset($stats["{$event->player_id}|{$sp}"])) {
                    $stats["{$event->player_id}|{$sp}"]['goals']++;
                }
                if ($event->related_player_id && isset($stats["{$event->related_player_id}|{$sp}"])) {
                    $stats["{$event->related_player_id}|{$sp}"]['assists']++;
                }

                continue;
            }
            if (! $event->player_id || ! isset($stats["{$event->player_id}|{$sp}"])) {
                continue;
            }
            $field = match ($games[$event->game_id]->countsAs($event->subtype)) {
                'yellow' => 'yellows',
                'red' => 'reds',
                default => 'other_sanctions',
            };
            $stats["{$event->player_id}|{$sp}"][$field]++;
        }

        return collect($stats)->map(function (array $s) {
            $s['avg_rating'] = $s['rated'] > 0 ? round($s['rating_sum'] / $s['rated'], 1) : null;
            unset($s['rating_sum']);

            return $s;
        })->sortBy('name')->values()->all();
    }

    /** @return array{goals: list, assists: list, minutes: list, appearances: list, cards: list, rating: list} */
    public static function leaderboards(array $rows, int $top = 5): array
    {
        $board = fn (string $metric) => collect($rows)->filter(fn (array $r) => $r[$metric] > 0)
            ->sortByDesc($metric)->take($top)->values()->all();

        return [
            'goals' => $board('goals'),
            'assists' => $board('assists'),
            'minutes' => $board('minutes'),
            'appearances' => $board('appearances'),
            'cards' => collect($rows)->map(fn (array $r) => $r + ['card_points' => $r['reds'] * 3 + $r['yellows']])
                ->filter(fn (array $r) => $r['card_points'] > 0)->sortByDesc('card_points')->take($top)->values()->all(),
            'rating' => collect($rows)->filter(fn (array $r) => $r['rated'] >= 3)->sortByDesc('avg_rating')->take($top)->values()->all(),
        ];
    }

    /** One player's played matches of a season, newest first. @return list<array<string, mixed>> */
    public function games(int $playerId, int $season): array
    {
        $rows = GamePlayer::query()->where('player_id', $playerId)->where('called_up', true)
            ->whereHas('game', fn ($q) => $q->where('status', GameStatus::Played->value)->where('season_start_year', $season))
            ->with(['game.opponent', 'game.competition', 'game.team'])->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $events = GameEvent::query()->whereIn('game_id', $rows->pluck('game_id'))
            ->where(fn ($q) => $q->where('player_id', $playerId)->orWhere('related_player_id', $playerId))->get();

        return $rows->map(function (GamePlayer $gp) use ($events, $playerId) {
            $game = $gp->game;
            $mine = $events->where('game_id', $game->id);

            return [
                'game_id' => $game->id,
                'date' => $game->date,
                'team' => $game->team->name,
                'opponent' => $game->opponent->name,
                'competition' => $game->competition?->name,
                'side' => $game->side->value,
                'our_score' => $game->our_score,
                'their_score' => $game->their_score,
                'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                'played' => $gp->played,
                'minutes' => $gp->minutes_played,
                'sets' => $gp->sets_played,
                'goals' => $mine->where('type', 'goal')->where('player_id', $playerId)->where('subtype', '!=', 'opponent_own_goal')->count(),
                'assists' => $mine->where('type', 'goal')->where('related_player_id', $playerId)->count(),
                'cards' => $mine->where('type', 'sanction')->where('player_id', $playerId)->pluck('subtype')->values()->all(),
                'rating' => $gp->rating,
                'motm' => $gp->is_mvp,
            ];
        })->sortByDesc('date')->values()->all();
    }
}
```

- [ ] **Step 4: Stats props**

In `app/Http/Controllers/TeamController.php` → `show`, inject `MatchStats $stats` (import it, plus `App\Services\Competitions\CompetitionRecord` and `App\Enums\GameStatus` if they are missing). Add the prop:

```php
            'stats' => [
                'record' => CompetitionRecord::fromGames($team->games()->where('season_start_year', $season)->get(['id', 'date', 'status', 'our_score', 'their_score'])),
                'leaders' => MatchStats::leaderboards($stats->players($season, $team->id)),
            ],
```

In `app/Http/Controllers/CompetitionController.php` → `show`, inject `MatchStats $stats`. Add the prop:

```php
            'stats' => ['leaders' => MatchStats::leaderboards($stats->players($season, null, $competition->id))],
```

`resources/js/Components/Competitions/StatsLeaders.vue`:

```vue
<script setup>
import { useI18n } from 'vue-i18n';

// Leaderboards (top 5 per metric). Ratings are shown in the app only, never exported.
const { t } = useI18n();
defineProps({ leaders: Object, showRating: { type: Boolean, default: true } });
const boards = [
    { key: 'goals', value: (r) => r.goals },
    { key: 'assists', value: (r) => r.assists },
    { key: 'minutes', value: (r) => (r.sport === 'volleyball' ? `${r.sets} ${t('comp.sets_played')}` : `${r.minutes}′`) },
    { key: 'appearances', value: (r) => r.appearances },
    { key: 'cards', value: (r) => `🟨${r.yellows} 🟥${r.reds}` },
    { key: 'rating', value: (r) => r.avg_rating },
];
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="b in boards.filter((x) => showRating || x.key !== 'rating')" :key="b.key" class="rounded-xl ring-1 ring-slate-200 p-3 dark:ring-slate-800">
            <h4 class="mb-2 text-xs font-semibold uppercase text-slate-500">{{ t(`comp.stats.${b.key}`) }}</h4>
            <ol class="space-y-1 text-sm">
                <li v-for="(r, i) in leaders[b.key]" :key="`${r.player_id}-${r.sport}`" class="flex justify-between gap-2">
                    <span class="truncate"><span class="text-slate-400">{{ i + 1 }}.</span> {{ r.name }}</span>
                    <bdi dir="ltr" class="font-mono">{{ b.value(r) }}</bdi>
                </li>
                <li v-if="!leaders[b.key].length" class="text-slate-400">—</li>
            </ol>
        </div>
    </div>
</template>
```

In `resources/js/Pages/Teams/Show.vue`:
- Add `stats: Object` to the props, `'stats'` to `tabs` (after `'games'`), and `import StatsLeaders from '@/Components/Competitions/StatsLeaders.vue';`.
- Add this section:

```vue
            <section v-if="tab === 'stats'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <span v-for="key in ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against']" :key="key"><b>{{ stats.record[key] }}</b> {{ t(`comp.record_${key}`) }}</span>
                </div>
                <StatsLeaders :leaders="stats.leaders" />
            </section>
```

In `resources/js/Pages/Competitions/Show.vue`:
- Add `stats: Object` to the props, insert `'stats'` into `tabs` after `'record'`, and import `StatsLeaders`.
- Add:

```vue
            <section v-if="tab === 'stats'" class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <StatsLeaders :leaders="stats.leaders" />
            </section>
```

- [ ] **Step 5: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.tab_stats": "Stats", "comp.stats.goals": "Top scorers", "comp.stats.assists": "Assists", "comp.stats.minutes": "Most played",
    "comp.stats.appearances": "Appearances", "comp.stats.cards": "Cards", "comp.stats.rating": "Best rating (3+ matches)"},
  "fr": {"comp.tab_stats": "Statistiques", "comp.stats.goals": "Buteurs", "comp.stats.assists": "Passeurs", "comp.stats.minutes": "Temps de jeu",
    "comp.stats.appearances": "Matchs joués", "comp.stats.cards": "Cartons", "comp.stats.rating": "Meilleure note (3 matchs et +)"},
  "ar": {"comp.tab_stats": "الإحصائيات", "comp.stats.goals": "الهدافون", "comp.stats.assists": "صناع الأهداف", "comp.stats.minutes": "الأكثر لعباً",
    "comp.stats.appearances": "المشاركات", "comp.stats.cards": "البطاقات", "comp.stats.rating": "أفضل تقييم (3 مباريات فأكثر)"}
}
EOF
```

- [ ] **Step 6: Build and run**

Run `npm run build`, then `php artisan test --filter="MatchStatsTest|TeamsTest|CompetitionsTest"`.
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Competitions/MatchStats.php resources/js/Components/Competitions/StatsLeaders.vue app/Http/Controllers/TeamController.php app/Http/Controllers/CompetitionController.php resources/js/Pages/Teams/Show.vue resources/js/Pages/Competitions/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/MatchStatsTest.php
git commit -m "feat(competitions): match statistics with team and competition leaderboards

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The club statistics page and exports

**Files:**
- Create: `app/Http/Controllers/GameStatsController.php`, `resources/js/Pages/Games/Stats.vue`, `resources/views/pdf/match-stats.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/js/Composables/useNavigation.js`, `resources/js/Pages/Teams/Show.vue` (export menu on the Stats tab)
- Test: `tests/Feature/Competitions/ClubStatsTest.php`

**Interfaces:**
- Consumes: `MatchStats`, `App\Support\Export::download()` and `Export::format()`, `PdfService`, `ClubHeader`, `UiLang`, `Season::options()`, `ExportMenu.vue` (props `href`, `label`, `formats`).
- Produces:
  - Routes `games.stats` (`GET /games/stats`) and `games.stats.export` (`GET /games/stats/export?format=xlsx|csv|pdf&season&team_id&competition_id`), both games/view.
  - Page `Games/Stats`, with props `rows`, `leaders`, `season`, `seasons`, `teams`, `competitions` and `filters`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/ClubStatsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class ClubStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function seed(): array
    {
        [$t1, $t2] = [$this->team(), $this->team()];
        [$a, $b] = [$this->player($t1->category), $this->player($t2->category)];
        foreach ([[$t1, $a], [$t2, $b]] as [$team, $player]) {
            $g = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
            GamePlayer::create(['game_id' => $g->id, 'player_id' => $player->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 90, 'rating' => 7, 'coach_note' => 'NOTE-X']);
        }

        return [$t1, $t2, $a, $b];
    }

    #[Test]
    public function the_page_lists_all_players_and_filters_by_team(): void
    {
        [$t1, , $a] = $this->seed();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.stats', ['season' => 2026]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Stats')->has('rows', 2));
        $this->actingAs($admin)->get(route('games.stats', ['season' => 2026, 'team_id' => $t1->id]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.player_id', $a->id));
    }

    #[Test]
    public function exports_never_carry_ratings_or_notes(): void
    {
        $this->seed();
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get(route('games.stats.export', ['season' => 2026, 'format' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringNotContainsString('NOTE-X', $body);
        $this->assertStringNotContainsString('7.0', $body);

        $pdf = $this->actingAs($admin)->get(route('games.stats.export', ['season' => 2026, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.stats'));
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.stats.export'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=ClubStatsTest`
Expected: FAIL (route not defined).

- [ ] **Step 3: Controller and PDF view**

`app/Http/Controllers/GameStatsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Team;
use App\Services\Competitions\MatchStats;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Export;
use App\Support\Season;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/** Club-wide player statistics, filterable by season, team and competition; exports never include ratings. */
class GameStatsController extends Controller
{
    public function __construct(private readonly MatchStats $stats) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $rows = $this->stats->players($f['season'], $f['team_id'], $f['competition_id']);

        return Inertia::render('Games/Stats', [
            'rows' => $rows,
            'leaders' => MatchStats::leaderboards($rows),
            'season' => $f['season'],
            'seasons' => Season::options(),
            'teams' => Team::query()->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name]),
            'competitions' => Competition::query()->where('season_start_year', $f['season'])->orderBy('name')->get(['id', 'name', 'team_id']),
            'filters' => ['team_id' => $f['team_id'], 'competition_id' => $f['competition_id']],
        ]);
    }

    public function export(Request $request, PdfService $pdf): FileResponse
    {
        $f = $this->filters($request);
        $rows = $this->stats->players($f['season'], $f['team_id'], $f['competition_id']);
        $title = UiLang::get('comp.stats.title').' — '.Season::forStartYear($f['season'])->label();
        $filename = 'match-stats-'.$f['season'];
        // Ratings stay in the app: an export gets shared.
        $columns = ['name', 'appearances', 'starts', 'minutes', 'sets', 'goals', 'assists', 'yellows', 'reds', 'motm'];
        $headers = array_map(fn (string $c) => UiLang::get('comp.stats.col_'.$c), $columns);
        $cells = array_map(fn (array $r) => array_map(fn (string $c) => $r[$c], $columns), $rows);

        if ($request->query('format') === 'pdf') {
            $html = view('pdf.match-stats', ['club' => ClubHeader::data(), 'title' => $title, 'headers' => $headers, 'rows' => $cells])->render();

            return $pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', true);
        }

        return Export::download(Export::format($request), $filename, $headers, $cells, $title);
    }

    /** @return array{season: int, team_id: ?int, competition_id: ?int} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'competition_id' => ['nullable', 'integer', 'exists:competitions,id'],
        ]);

        return [
            'season' => (int) ($data['season'] ?? Season::current()->startYear),
            'team_id' => isset($data['team_id']) ? (int) $data['team_id'] : null,
            'competition_id' => isset($data['competition_id']) ? (int) $data['competition_id'] : null,
        ];
    }
}
```

`resources/views/pdf/match-stats.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10px; color: #1e293b; }
        .title { font-size: 16px; font-weight: bold; color: #02a85c; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; padding: 3px; border: 1px solid #94a3b8; font-size: 9px; }
        td { padding: 3px 5px; border: 1px solid #94a3b8; }
        td.num { text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.header')
    <div class="title">{{ $title }}</div>
    <table>
        <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $i => $cell)<td class="{{ $i > 0 ? 'num' : '' }}">{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 4: Routes, permissions, navigation**

In `routes/web.php`, add `use App\Http\Controllers\GameStatsController;`. The `/games/stats` URL would otherwise be caught by `/games/{game}`, so the two routes must go **before** the `Route::get('/games/{game}', …)` line:

```php
        Route::get('/games/stats', [GameStatsController::class, 'index'])->name('games.stats');
        Route::get('/games/stats/export', [GameStatsController::class, 'export'])->name('games.stats.export');
```

In `config/permissions.php` → `overrides`, add:

```php
        // "stats" is not a view verb: reading the club statistics needs games/view only.
        'games.stats' => ['games', 'view'],
```

In `resources/js/Composables/useNavigation.js`:
- Change the games item to `{ label: t('games'), href: '/games', icon: 'calendar', prefix: '/games', match: /^\/games(?!\/stats)/, module: 'games' },`.
- Add after it: `{ label: t('comp.stats.title'), href: '/games/stats', icon: 'clipboard', prefix: '/games/stats', module: 'games' },`.

- [ ] **Step 5: The page, and export on the team tab**

`resources/js/Pages/Games/Stats.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import StatsLeaders from '@/Components/Competitions/StatsLeaders.vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

const { t } = useI18n();
const props = defineProps({ rows: Array, leaders: Object, season: Number, seasons: Array, teams: Array, competitions: Array, filters: Object });

const query = computed(() => ({ season: props.season, ...(props.filters.team_id ? { team_id: props.filters.team_id } : {}), ...(props.filters.competition_id ? { competition_id: props.filters.competition_id } : {}) }));
function reload(params) {
    router.get(route('games.stats'), { ...query.value, ...params }, { preserveState: true, replace: true });
}
const competitionOptions = computed(() => props.competitions
    .filter((c) => !props.filters.team_id || c.team_id === props.filters.team_id)
    .map((c) => ({ value: c.id, label: c.name })));
const exportHref = computed(() => route('games.stats.export', query.value));
</script>

<template>
    <Head :title="t('comp.stats.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('comp.stats.title') }}</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event, competition_id: null })" />
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56" @update:model-value="reload({ team_id: $event || null, competition_id: null })" />
                    <SearchableSelect :model-value="filters.competition_id ?? ''" :options="competitionOptions" :placeholder="t('comp.competition')" class="w-56" @update:model-value="reload({ competition_id: $event || null })" />
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <StatsLeaders :leaders="leaders" />
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950"><tr>
                        <th class="px-3 py-2 text-start">{{ t('comp.player') }}</th>
                        <th v-for="c in ['appearances', 'starts', 'minutes', 'goals', 'assists', 'yellows', 'reds', 'avg_rating', 'motm']" :key="c" class="px-3 py-2 text-center">{{ t(`comp.stats.col_${c}`) }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in rows" :key="`${r.player_id}-${r.sport}`">
                            <td class="px-3 py-2 font-medium">{{ r.name }} <span v-if="r.sport !== 'football'" class="text-xs text-slate-400">· {{ t(`comp.sport_${r.sport}`) }}</span></td>
                            <td class="px-3 py-2 text-center">{{ r.appearances }}</td>
                            <td class="px-3 py-2 text-center">{{ r.starts }}</td>
                            <td class="px-3 py-2 text-center"><bdi dir="ltr">{{ r.sport === 'volleyball' ? r.sets : r.minutes }}</bdi></td>
                            <td class="px-3 py-2 text-center">{{ r.goals }}</td>
                            <td class="px-3 py-2 text-center">{{ r.assists }}</td>
                            <td class="px-3 py-2 text-center">{{ r.yellows }}</td>
                            <td class="px-3 py-2 text-center">{{ r.reds }}</td>
                            <td class="px-3 py-2 text-center">{{ r.avg_rating ?? '—' }}</td>
                            <td class="px-3 py-2 text-center">{{ r.motm }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="10" class="px-3 py-8 text-center text-slate-500">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

In `resources/js/Pages/Teams/Show.vue` → the Stats section, before `<StatsLeaders …>`, add `<ExportMenu :href="route('games.stats.export', { season, team_id: team.id })" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']" />`, and import `ExportMenu`.

- [ ] **Step 6: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.stats.title": "Statistics", "comp.stats.col_name": "Player", "comp.stats.col_appearances": "Apps", "comp.stats.col_starts": "Starts",
    "comp.stats.col_minutes": "Minutes", "comp.stats.col_sets": "Sets", "comp.stats.col_goals": "Goals", "comp.stats.col_assists": "Assists",
    "comp.stats.col_yellows": "Yellow", "comp.stats.col_reds": "Red", "comp.stats.col_avg_rating": "Rating", "comp.stats.col_motm": "MOTM"},
  "fr": {"comp.stats.title": "Statistiques", "comp.stats.col_name": "Joueur", "comp.stats.col_appearances": "Matchs", "comp.stats.col_starts": "Titulaire",
    "comp.stats.col_minutes": "Minutes", "comp.stats.col_sets": "Sets", "comp.stats.col_goals": "Buts", "comp.stats.col_assists": "Passes",
    "comp.stats.col_yellows": "Jaunes", "comp.stats.col_reds": "Rouges", "comp.stats.col_avg_rating": "Note", "comp.stats.col_motm": "Homme du match"},
  "ar": {"comp.stats.title": "الإحصائيات", "comp.stats.col_name": "اللاعب", "comp.stats.col_appearances": "المشاركات", "comp.stats.col_starts": "أساسي",
    "comp.stats.col_minutes": "الدقائق", "comp.stats.col_sets": "الأشواط", "comp.stats.col_goals": "الأهداف", "comp.stats.col_assists": "التمريرات",
    "comp.stats.col_yellows": "صفراء", "comp.stats.col_reds": "حمراء", "comp.stats.col_avg_rating": "التقييم", "comp.stats.col_motm": "رجل المباراة"}
}
EOF
```

- [ ] **Step 7: Build and run**

Run `npm run build`, then `php artisan test --filter="ClubStatsTest|MatchStatsTest|CompetitionPermissionsTest"` and `npm run test:js`.
Expected: PASS. If `tests/js/navigation.test.mjs` asserts the menu shape, update it for the new item.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/GameStatsController.php resources/js/Pages/Games/Stats.vue resources/views/pdf/match-stats.blade.php routes/web.php config/permissions.php resources/js/Composables/useNavigation.js resources/js/Pages/Teams/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/ClubStatsTest.php
git commit -m "feat(competitions): club statistics page with Excel and PDF export

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: The Matches section on the player page

**Files:**
- Create: `app/Http/Controllers/GamePlayerStatsController.php`, `resources/js/Pages/Players/Partials/MatchesSection.vue`
- Modify: `routes/web.php`, `resources/js/Pages/Players/Show.vue`
- Test: `tests/Feature/Competitions/PlayerMatchesSectionTest.php`

**Interfaces:**
- Consumes: `MatchStats::players()` and `games()`, `SuspensionStatus::evaluate()`, `Season::options()`.
- Produces: route `games.players.show` (`GET /games/players/{player}?season=`), games/view, returning JSON `{season, seasons, totals: list<row>, games: list, suspensions: list<{competition, cause, reason, kind, remaining, until}>}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/PlayerMatchesSectionTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use App\Models\Role;
use App\Models\Suspension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class PlayerMatchesSectionTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function it_returns_season_totals_games_and_active_suspensions(): void
    {
        $team = $this->team();
        $p = $this->player($team->category);
        $g = $this->game($team, ['status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 1]);
        GamePlayer::create(['game_id' => $g->id, 'player_id' => $p->id, 'called_up' => true, 'role' => 'starter', 'played' => true, 'minutes_played' => 75]);
        Suspension::create(['player_id' => $p->id, 'team_id' => $team->id, 'source' => 'manual', 'kind' => 'dates', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(), 'reason' => 'Club']);

        $this->actingAs($this->admin())->getJson(route('games.players.show', ['player' => $p, 'season' => 2026]))
            ->assertOk()
            ->assertJsonPath('totals.0.minutes', 75)
            ->assertJsonPath('games.0.game_id', $g->id)
            ->assertJsonPath('suspensions.0.reason', 'Club');
    }

    #[Test]
    public function it_needs_games_view(): void
    {
        $p = $this->player($this->category());
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id]);

        $this->actingAs($user)->getJson(route('games.players.show', $p))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PlayerMatchesSectionTest`
Expected: FAIL (route not defined).

- [ ] **Step 3: Controller and route**

`app/Http/Controllers/GamePlayerStatsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Competitions\MatchStats;
use App\Services\Competitions\SuspensionStatus;
use App\Support\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The player page's Matches section (loaded on its own): season totals, matches and active suspensions. */
class GamePlayerStatsController extends Controller
{
    public function show(Request $request, Player $player, MatchStats $stats, SuspensionStatus $status): JsonResponse
    {
        $season = (int) ($request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100']])['season'] ?? Season::current()->startYear);
        $suspensions = $player->suspensions()->with('competition')->get();
        $eval = $status->evaluate($suspensions);

        return response()->json([
            'season' => $season,
            'seasons' => Season::options(),
            'totals' => $stats->players($season, null, null, $player->id),
            'games' => $stats->games($player->id, $season),
            'suspensions' => $suspensions->filter(fn ($s) => $eval[$s->id]['active'])->map(fn ($s) => [
                'competition' => $s->competition?->name,
                'cause' => $s->cause,
                'reason' => $s->reason,
                'kind' => $s->kind,
                'remaining' => $eval[$s->id]['remaining'],
                'until' => $eval[$s->id]['until'],
            ])->values(),
        ]);
    }
}
```

In `routes/web.php`, add `use App\Http\Controllers\GamePlayerStatsController;` and, next to the stats routes (also before `/games/{game}`):

```php
        Route::get('/games/players/{player}', [GamePlayerStatsController::class, 'show'])->name('games.players.show');
```

- [ ] **Step 4: The section**

`resources/js/Pages/Players/Partials/MatchesSection.vue`:

```vue
<script setup>
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { onMounted, ref } from 'vue';

// Loaded on its own, like the attendance section, so the player page stays fast.
const { t } = useI18n();
const props = defineProps({ player: Object });
const data = ref(null);
const loading = ref(true);

async function load(season = null) {
    loading.value = true;
    try {
        const response = await window.axios.get(route('games.players.show', { player: props.player.id, ...(season ? { season } : {}) }));
        data.value = response.data;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
const sanctionLabel = (key) => {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
};
</script>

<template>
    <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ t('games') }}</h2>
            <SeasonSelect v-if="data" :model-value="data.season" :seasons="data.seasons" @update:model-value="load($event)" />
        </div>
        <p v-if="loading && !data" class="text-sm text-slate-500">…</p>
        <template v-else-if="data">
            <div v-for="s in data.suspensions" :key="`${s.competition}-${s.until}-${s.remaining}`" class="rounded-lg bg-rose-50 p-2 text-sm text-rose-800 dark:bg-rose-900/30 dark:text-rose-200">
                ⛔ {{ t('comp.flag.suspended') }} — {{ s.until ? t('comp.flag.suspended_until', { date: s.until }) : t('comp.flag.suspended_left', { n: s.remaining }) }}
                <span v-if="s.competition">· {{ s.competition }}</span><span v-if="s.reason"> · {{ s.reason }}</span>
            </div>
            <div v-for="row in data.totals" :key="row.sport" class="grid grid-cols-3 gap-2 text-center text-sm sm:grid-cols-9">
                <div v-for="c in ['appearances', 'starts', row.sport === 'volleyball' ? 'sets' : 'minutes', 'goals', 'assists', 'yellows', 'reds', 'avg_rating', 'motm']" :key="c" class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800">
                    <div class="text-lg font-bold">{{ row[c] ?? '—' }}</div>
                    <div class="text-xs text-slate-500">{{ t(`comp.stats.col_${c}`) }}</div>
                </div>
            </div>
            <table v-if="data.games.length" class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="g in data.games" :key="g.game_id">
                        <td class="py-1"><bdi dir="ltr" class="font-mono text-xs">{{ g.date }}</bdi></td>
                        <td class="py-1"><Link :href="route('games.show', g.game_id)" class="hover:underline">{{ g.team }} — {{ g.opponent }}</Link>
                            <span class="text-xs text-slate-400"> · {{ g.competition || t('comp.type_friendly') }}</span></td>
                        <td class="py-1 font-mono"><bdi dir="ltr">{{ g.our_score }}–{{ g.their_score }}</bdi></td>
                        <td class="py-1 text-xs">{{ t(g.role === 'starter' ? 'comp.starter' : 'comp.substitute') }} · <bdi dir="ltr">{{ g.sets ?? g.minutes ?? 0 }}{{ g.sets === null ? '′' : '' }}</bdi></td>
                        <td class="py-1 text-xs"><span v-if="g.goals">⚽×{{ g.goals }} </span><span v-if="g.assists">🅰×{{ g.assists }} </span><span v-for="(c, i) in g.cards" :key="i">{{ sanctionLabel(c) }} </span><span v-if="g.motm">⭐</span></td>
                        <td class="py-1 text-xs text-slate-500">{{ g.rating ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-slate-500">{{ t('comp.no_matches_yet') }}</p>
        </template>
    </section>
</template>
```

In `resources/js/Pages/Players/Show.vue`:
- Import `MatchesSection from '@/Pages/Players/Partials/MatchesSection.vue'`.
- After the `<AttendanceSection …/>` line, add `<MatchesSection v-if="can('games', 'view')" :player="player" />`.

- [ ] **Step 5: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.no_matches_yet": "No matches played this season."},
  "fr": {"comp.no_matches_yet": "Aucun match joué cette saison."},
  "ar": {"comp.no_matches_yet": "لا مباريات لُعبت هذا الموسم."}
}
EOF
```

- [ ] **Step 6: Build and run**

Run `npm run build`, then `php artisan test --filter="PlayerMatchesSectionTest|PlayerShow|Players"`.
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/GamePlayerStatsController.php resources/js/Pages/Players/Partials/MatchesSection.vue resources/js/Pages/Players/Show.vue routes/web.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/PlayerMatchesSectionTest.php
git commit -m "feat(competitions): matches, totals and suspensions on the player page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The dashboard matches card

**Files:**
- Create: `app/Services/Dashboard/MatchesCard.php`
- Modify: `app/Http/Controllers/DashboardController.php`, `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`
- Test: `tests/Feature/Dashboard/DashboardMatchesCardTest.php`

**Interfaces:**
- Consumes: `TeamAccess::manageableTeamIds()`, `Season::current()`.
- Produces: `MatchesCard::get(User $user): array{upcoming: list<row>, results: list<row>}`. Each row is `{id, date, kickoff_time, team, opponent, side, status, our_score, their_score, outcome: 'W'|'D'|'L'|null}`. It is exposed as `overview.matches`, or null without games/view.

- [ ] **Step 1: Write the failing test**

Read `tests/Feature/Dashboard/DashboardAttendanceCardTest.php` first, to see how the dashboard props are asserted (prop name and tab). Then write `tests/Feature/Dashboard/DashboardMatchesCardTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\GameStatus;
use App\Services\Dashboard\MatchesCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class DashboardMatchesCardTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function it_shows_the_next_three_and_last_three_with_a_coachs_teams_first(): void
    {
        $mine = $this->team();
        $other = $this->team();
        foreach (range(1, 4) as $i) {
            $this->game($other, ['date' => now()->addDays($i)->toDateString()]);
            $this->game($other, ['date' => now()->subDays($i)->toDateString(), 'status' => GameStatus::Played, 'our_score' => $i + 1, 'their_score' => 1]);
        }
        $myNext = $this->game($mine, ['date' => now()->addDays(10)->toDateString()]);
        $coach = $this->coach();
        $this->assignCoach($mine, $coach, \App\Support\Season::current()->startYear);

        $admin = app(MatchesCard::class)->get($this->admin());
        $this->assertCount(3, $admin['upcoming']);
        $this->assertCount(3, $admin['results']);
        $this->assertSame('W', $admin['results'][0]['outcome']);

        $forCoach = app(MatchesCard::class)->get($coach);
        $this->assertSame($myNext->id, $forCoach['upcoming'][0]['id']);
    }

    #[Test]
    public function the_overview_carries_the_card_only_with_games_view(): void
    {
        $this->game($this->team(), ['date' => now()->addDay()->toDateString()]);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('overview.matches.upcoming', 1));
    }
}
```

Adjust the second test's prop path if the dashboard exposes the overview data under another name; check `DashboardAttendanceCardTest` and `DashboardPageTest`.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=DashboardMatchesCardTest`
Expected: FAIL (class not found).

- [ ] **Step 3: The service and wiring**

`app/Services/Dashboard/MatchesCard.php`:

```php
<?php

namespace App\Services\Dashboard;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;

/** The Overview's matches card: next 3 and last 3 results — a coach's own teams first. */
final class MatchesCard
{
    /** @return array{upcoming: list<array<string, mixed>>, results: list<array<string, mixed>>} */
    public function get(User $user): array
    {
        $mine = TeamAccess::manageableTeamIds($user, Season::current()->startYear);
        $today = now()->toDateString();
        $first = fn ($q) => $mine ? $q->orderByRaw('CASE WHEN team_id IN ('.implode(',', array_map('intval', $mine)).') THEN 0 ELSE 1 END') : $q;

        $upcoming = $first(Game::query()->with(['team:id,name', 'opponent:id,name'])
            ->where('date', '>=', $today)->whereIn('status', [GameStatus::Scheduled->value, GameStatus::Postponed->value]))
            ->orderBy('date')->orderBy('kickoff_time')->limit(3)->get();
        $results = $first(Game::query()->with(['team:id,name', 'opponent:id,name'])->where('status', GameStatus::Played->value))
            ->orderByDesc('date')->orderByDesc('id')->limit(3)->get();

        return ['upcoming' => $upcoming->map(fn (Game $g) => self::row($g))->all(), 'results' => $results->map(fn (Game $g) => self::row($g))->all()];
    }

    /** @return array<string, mixed> */
    private static function row(Game $g): array
    {
        $outcome = $g->status === GameStatus::Played && $g->our_score !== null && $g->their_score !== null
            ? match ($g->our_score <=> $g->their_score) { 1 => 'W', 0 => 'D', -1 => 'L' } : null;

        return [
            'id' => $g->id, 'date' => $g->date, 'kickoff_time' => $g->kickoff_time, 'team' => $g->team->name,
            'opponent' => $g->opponent->name, 'side' => $g->side->value, 'status' => $g->status->value,
            'our_score' => $g->our_score, 'their_score' => $g->their_score, 'outcome' => $outcome,
        ];
    }
}
```

In `app/Http/Controllers/DashboardController.php`:
- Inject `private readonly MatchesCard $matches` in the constructor and import it.
- Change the overview resolver to:

```php
            'overview' => fn (): array => [
                ...$this->overview->get($filters, $this->money($user)),
                // The matches card needs games/view on top of the Overview.
                'matches' => $user?->hasPermission('games', 'view') ? $this->matches->get($user) : null,
            ],
```

In `resources/js/Pages/Dashboard/Partials/OverviewTab.vue`:
- Add `import { Link } from '@inertiajs/vue3';` and `const matches = computed(() => props.data?.matches ?? null);`.
- Before the recent-activity `<Card>`, add:

```vue
        <Card v-if="matches" class="overflow-hidden border-border/70 shadow-none">
            <CardHeader class="px-5 py-4"><CardTitle class="text-base">{{ t('games') }}</CardTitle></CardHeader>
            <Separator />
            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div v-for="part in ['upcoming', 'results']" :key="part">
                    <h4 class="mb-2 text-xs font-semibold uppercase text-muted-foreground">{{ t(`comp.${part === 'upcoming' ? 'upcoming' : 'past'}`) }}</h4>
                    <Link v-for="g in matches[part]" :key="g.id" :href="route('games.show', g.id)" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-muted">
                        <span class="truncate">{{ g.team }} — {{ g.opponent }}</span>
                        <span class="flex items-center gap-2">
                            <bdi dir="ltr" class="font-mono text-xs">{{ part === 'upcoming' ? `${g.date} ${g.kickoff_time ?? ''}` : `${g.our_score}–${g.their_score}` }}</bdi>
                            <span v-if="g.outcome" class="flex h-5 w-5 items-center justify-center rounded text-xs font-bold text-white"
                                :class="{ W: 'bg-emerald-500', D: 'bg-slate-400', L: 'bg-rose-500' }[g.outcome]">{{ t(`comp.form_${g.outcome}`) }}</span>
                        </span>
                    </Link>
                    <p v-if="!matches[part].length" class="px-2 text-sm text-muted-foreground">{{ t('no_results') }}</p>
                </div>
            </div>
        </Card>
```

- [ ] **Step 4: Build and run**

Run `npm run build`, then `php artisan test --filter="DashboardMatchesCardTest|Dashboard"`.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/MatchesCard.php app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard/Partials/OverviewTab.vue tests/Feature/Dashboard/DashboardMatchesCardTest.php
git commit -m "feat(competitions): next matches and latest results on the dashboard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Final verification

- [ ] **Step 1: Full checks**

Run each of these in `D:/irnb-competitions`:
- `npm run build`
- `php artisan test`. The whole suite must be green; report the counts.
- `npm run i18n:check`, which must exit 0.
- `npm run test:js`, which must pass.
- `vendor/bin/pint --test` on `git diff --name-only 1b849fa..HEAD -- '*.php'`. If it reports problems, run pint on those files and commit the result as `style(competitions): pint`.

- [ ] **Step 2: Report**

Report every failure with the exact test name and output. Do not fix pre-existing failures in unrelated code.
