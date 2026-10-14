# Competitions & Matches — Phase 1 (Setup & fixtures) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clubs can set up game formats, opponents, venues, teams (with per-season rosters and coaches) and competitions (with squads), schedule matches, and call players up for a match with a printable convocation sheet.

**Architecture:** This is a new Laravel module that follows the attendance module's patterns:
- Thin controllers render Inertia pages, and small services in `app/Services/Competitions` hold the logic.
- Permissions come from route names (`config/permissions.php`). A new `own_teams_only` role flag limits coaches to the teams they coach each season, checked by `TeamAccess`.
- Dates are stored as `Y-m-d` strings and seasons as `season_start_year` integers (`App\Support\Season`).

**Tech Stack:** Laravel 13, PHPUnit feature tests (class-based, `#[Test]`), Inertia + Vue 3 `<script setup>`, Tailwind, mPDF via `App\Services\Pdf\PdfService`, vue-i18n flat dotted keys in `resources/js/i18n/{ar,en,fr}.json`.

Spec: `docs/superpowers/specs/2026-10-14-competitions-matches-design.md` (Phase 1 only).

## Global Constraints

- Team sports only. Model named `Game` (table `games`) — `match` is reserved in PHP 8.
- Season everywhere = `season_start_year` integer from `App\Support\Season`.
- Dates are `'Y-m-d'` strings and times `'H:i'` strings (same as attendance), so sqlite (desktop) and MySQL (web) behave the same.
- Eligibility checks are warnings only, never blocks. In Phase 1 the only badge is "injured".
- Coach scope: a user whose role has `own_teams_only = true` edits only teams they are assigned to (`team_coaches`) for that season, and views all. God admins and unscoped roles manage every team.
- New role rights start empty except on system roles (full) and the seeded `coach` role (owner preference). Grants are done in a **migration**, because the desktop build never runs seeders.
- No CDN or remote assets. PDFs use local fonts via mPDF.
- Every UI string goes in all three catalogs (ar, en, fr). Use `t()` directly; never `te()` on flat keys. Flash messages are keys (`flash.*`) and must exist in all catalogs (`FlashTranslationTest`).
- Player names always come from `Player::fullname`. Narrowed queries must select `Player::NAME_COLUMNS`.
- Uploaded files are stored through `FileStorageService` as `/media`-relative URLs.
- Stage explicit files only (`git add <paths>`); never `git add -A`.

## Deviations from the spec (decided while planning)

1. `game_formats` gains a `sport` column (football / futsal / handball / basketball / volleyball / other). Presets need a sport before any Branch exists. `branch_id` is nullable: a preset links itself to a branch whose name matches its sport, and otherwise an admin links it on the Game formats page. Teams can only pick formats of their own branch.
2. Formation slots are `{label, x, y}`. `label` is the position abbreviation; Phase 2 maps it to a `positions` row of the same sport.
3. `teams.archived` replaces the spec's `teams.active`, to match `competitions.archived`.
4. Phase 1 doesn't create `game_events` or `suspensions`, and doesn't add `players.licence_number` or `document_types.required_for_matches`. Those arrive in Phase 2/3 with the features that use them.
5. A match's final score (`our_score` / `their_score`) can be entered in Phase 1 when its status is set to "played", so the competition record works before the Phase 2 match report.
6. A game's competition can't be changed after creation. Its game format is frozen when it's created.

## File map

**Create**
- `scripts/i18n-add.mjs`: merges new keys into the three catalogs without reordering existing ones.
- `app/Enums/{Sport,CompetitionType,GameSide,GameStatus,CoachRole,ScoringType,PeriodLabel}.php`
- `app/Models/{GameFormat,Formation,Team,Opponent,Venue,Competition,Game,GamePlayer}.php`
- `database/migrations/2026_10_15_100001_create_competition_tables.php`
- `database/migrations/2026_10_15_100002_seed_game_format_presets.php`
- `database/migrations/2026_10_15_100003_grant_competition_permissions.php`
- `app/Services/Competitions/{TeamAccess,TeamRoster,PlayerFlags,CompetitionRecord,TrainingCanceller,CallUpCandidates}.php`
- `app/Http/Controllers/{OpponentController,VenueController,GameFormatController,TeamController,TeamRosterController,TeamCoachController,CompetitionController,CompetitionSquadController,GameController,GameCallUpController}.php`
- `resources/js/Pages/Settings/{Opponents,Venues,GameFormats}.vue`
- `resources/js/Pages/Teams/{Index,Show}.vue`, `resources/js/Pages/Competitions/{Index,Show}.vue`, `resources/js/Pages/Games/{Index,Show}.vue`
- `resources/js/Components/Competitions/SeasonSelect.vue`
- `resources/views/pdf/game-convocation.blade.php`
- `tests/Support/CompetitionFixtures.php`
- `tests/Feature/Competitions/*Test.php`, `tests/Unit/CompetitionRecordTest.php`

**Modify**
- `app/Models/{Role,Position}.php`, `app/Support/Season.php`
- `app/Http/Controllers/{PositionController,RoleController,CategoryController,BranchController}.php`
- `config/permissions.php`, `config/activity.php`
- `app/Services/Activity/{ActivityAction,ActivitySubjectLink}.php`
- `database/seeders/RoleSeeder.php`
- `routes/web.php`
- `resources/js/Pages/Settings/Positions.vue`, `resources/js/Pages/Roles/Index.vue`, `resources/js/Components/PermissionGrid.vue`, `resources/js/Composables/useNavigation.js`
- `resources/js/i18n/{ar,en,fr}.json`, `tests/js/navI18n.test.mjs`

---

### Task 0: Worktree preparation

The worktree `D:/irnb-competitions` on branch `feat/competitions-p1` already exists. It was branched from `main` with the spec commit cherry-picked. The main checkout `D:/irnb-laravel` belongs to another session, so don't touch its branch or files.

- [ ] **Step 1: Copy the untracked runtime pieces (copies, not junctions)**

```bash
cd D:/irnb-competitions
cp -r ../irnb-laravel/vendor ./vendor
cp -r ../irnb-laravel/node_modules ./node_modules
cp ../irnb-laravel/.env ./.env
php artisan config:clear
```

- [ ] **Step 2: Baseline test run**

Run: `composer test`
Expected: the whole suite passes. If something fails here, report it before starting. Don't fix unrelated failures.

---

### Task 1: i18n helper, enums, schema and models

**Files:**
- Create: `scripts/i18n-add.mjs`
- Create: `app/Enums/Sport.php`, `CompetitionType.php`, `GameSide.php`, `GameStatus.php`, `CoachRole.php`, `ScoringType.php`, `PeriodLabel.php`
- Create: `database/migrations/2026_10_15_100001_create_competition_tables.php`
- Create: `app/Models/GameFormat.php`, `Formation.php`, `Team.php`, `Opponent.php`, `Venue.php`, `Competition.php`, `Game.php`, `GamePlayer.php`
- Modify: `app/Models/Role.php` (fillable + cast `own_teams_only`), `app/Models/Position.php` (fillable `branch_id`, `branch()`)
- Create: `tests/Support/CompetitionFixtures.php`
- Test: `tests/Feature/Competitions/CompetitionSchemaTest.php`

**Interfaces:**
- Produces: the enums (each with `values(): array`); the models and relations listed below; `Team::rosterFor(int $season): BelongsToMany`; `Team::coachesFor(int $season): BelongsToMany`; `GameFormat::inUse(): bool`; the fixture helpers `sportBranch()`, `format()`, `team()`, `onRoster()`, `opponent()`, `competition()`, `game()`, `coach()`, `assignCoach()`, plus `admin()`, `category()` and `player()` from `AttendanceFixtures`.

- [ ] **Step 1: Create the i18n helper**

`scripts/i18n-add.mjs`:

```js
// Adds translation keys to resources/js/i18n/{ar,en,fr}.json without
// reordering the existing ones: a new key goes in front of the first existing
// key that sorts after it; an existing key keeps its place and gets the new text.
// Every key must be given in all three locales.
//
// Usage: node scripts/i18n-add.mjs < keys.json
//        keys.json = {"en": {"key": "text"}, "fr": {...}, "ar": {...}}
import { readFileSync, writeFileSync } from 'node:fs';

const LOCALES = ['ar', 'en', 'fr'];
const input = JSON.parse(readFileSync(0, 'utf8'));

const all = new Set(LOCALES.flatMap((l) => Object.keys(input[l] ?? {})));
for (const locale of LOCALES) {
    const missing = [...all].filter((key) => !(key in (input[locale] ?? {})));
    if (missing.length) {
        console.error(`[${locale}] missing: ${missing.join(', ')}`);
        process.exit(1);
    }
}

for (const locale of LOCALES) {
    const url = new URL(`../resources/js/i18n/${locale}.json`, import.meta.url);
    const entries = Object.entries(JSON.parse(readFileSync(url, 'utf8')));
    for (const [key, value] of Object.entries(input[locale])) {
        const at = entries.findIndex(([k]) => k === key);
        if (at !== -1) {
            entries[at][1] = value;
            continue;
        }
        const before = entries.findIndex(([k]) => k > key);
        entries.splice(before === -1 ? entries.length : before, 0, [key, value]);
    }
    writeFileSync(url, JSON.stringify(Object.fromEntries(entries), null, 4) + '\n');
}
console.log(`added/updated ${all.size} keys`);
```

Run: `node scripts/i18n-add.mjs <<'EOF'
{"en": {}, "fr": {}, "ar": {}}
EOF
git diff --stat resources/js/i18n`
Expected: `added/updated 0 keys`. The diff is either empty or only adds a final newline. If it shows reformatted lines, the catalogs use a different style: stop and report.

- [ ] **Step 2: Create the enums**

`app/Enums/Sport.php`:

```php
<?php

namespace App\Enums;

/** The sport a game format is played under; drives presets and (Phase 2) the pitch drawing. */
enum Sport: string
{
    case Football = 'football';
    case Futsal = 'futsal';
    case Handball = 'handball';
    case Basketball = 'basketball';
    case Volleyball = 'volleyball';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Create the following enums with the same shape (namespace `App\Enums`, backed by `string`, the same `values()` method), with these doc lines and cases:

- `CompetitionType`, doc `/** What kind of competition a team enters; friendlies may also stand alone without one. */`. Cases: `League = 'league'`, `Cup = 'cup'`, `Tournament = 'tournament'`, `Friendly = 'friendly'`.
- `GameSide`, doc `/** Where the club plays the match. */`. Cases: `Home = 'home'`, `Away = 'away'`, `Neutral = 'neutral'`.
- `GameStatus`, doc `/** A match's life: scheduled until played, or postponed / cancelled. */`. Cases: `Scheduled = 'scheduled'`, `Played = 'played'`, `Postponed = 'postponed'`, `Cancelled = 'cancelled'`.
- `CoachRole`, doc `/** A coach's role in a team for a season. */`. Cases: `Head = 'head'`, `Assistant = 'assistant'`.
- `ScoringType`, doc `/** How a sport counts the score. */`. Cases: `Goals = 'goals'`, `Points = 'points'`, `Sets = 'sets'`.
- `PeriodLabel`, doc `/** What a sport calls a period of play. */`. Cases: `Half = 'half'`, `Quarter = 'quarter'`, `Set = 'set'`, `Period = 'period'`.

- [ ] **Step 3: Create the schema migration**

`database/migrations/2026_10_15_100001_create_competition_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Competitions & matches, phase 1. Dates are 'Y-m-d' and times 'H:i'
     * strings, like attendance, so sqlite (desktop) and MySQL (web) compare
     * them the same. Seasons are `season_start_year` integers (App\Support\Season).
     */
    public function up(): void
    {
        Schema::create('game_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sport', 16);
            $table->string('name', 100);
            $table->string('name_ar', 100)->nullable();
            $table->string('name_fr', 100)->nullable();
            $table->string('name_en', 100)->nullable();
            $table->unsignedTinyInteger('players_on_field');
            $table->unsignedTinyInteger('max_bench');
            $table->unsignedTinyInteger('max_called_up');
            $table->unsignedTinyInteger('periods_count');
            $table->unsignedTinyInteger('period_minutes');
            $table->string('period_label', 16);
            $table->string('scoring', 8);
            $table->unsignedTinyInteger('sets_to_win')->nullable();
            $table->boolean('has_extra_time')->default(false);
            $table->boolean('has_shootout')->default(false);
            $table->json('sanction_types');
            $table->boolean('is_preset')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('formations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_format_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->json('slots');
            $table->timestamps();
            $table->unique(['game_format_id', 'name']);
        });

        // Positions become per sport: the same abbreviation (GK) may exist once per branch.
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['abbreviation']);
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'abbreviation']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('own_teams_only')->default(false)->after('is_system');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->foreignId('default_game_format_id')->constrained('game_formats')->restrictOnDelete();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('team_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'season_start_year', 'player_id']);
            $table->unique(['team_id', 'season_start_year', 'shirt_number']);
        });

        Schema::create('team_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['team_id', 'season_start_year', 'user_id']);
        });

        Schema::create('opponents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('city', 100)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('logo_filename')->nullable();
            $table->timestamps();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('address')->nullable();
            $table->boolean('is_home')->default(false);
            $table->timestamps();
        });

        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->string('name', 150);
            $table->string('type', 16);
            $table->foreignId('game_format_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('red_ban_matches')->default(1);
            $table->unsignedTinyInteger('yellow_threshold')->nullable();
            $table->unsignedTinyInteger('yellow_ban_matches')->default(1);
            $table->boolean('archived')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'season_start_year']);
        });

        Schema::create('competition_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['competition_id', 'player_id']);
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('season_start_year');
            $table->foreignId('game_format_id')->constrained()->restrictOnDelete();
            $table->foreignId('opponent_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 8);
            $table->string('round_label', 100)->nullable();
            $table->string('date', 10);
            $table->string('kickoff_time', 5)->nullable();
            $table->string('meeting_time', 5)->nullable();
            $table->string('meeting_place', 150)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('our_score')->nullable();
            $table->unsignedSmallInteger('their_score')->nullable();
            $table->json('period_scores')->nullable();
            $table->boolean('extra_time_played')->default(false);
            $table->unsignedTinyInteger('shootout_us')->nullable();
            $table->unsignedTinyInteger('shootout_them')->nullable();
            $table->foreignId('formation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('date');
            $table->index(['team_id', 'date']);
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->boolean('called_up')->default(false);
            $table->string('not_called_reason', 150)->nullable();
            $table->string('role', 8)->default('none');
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('slot')->nullable();
            $table->boolean('is_captain')->default(false);
            $table->boolean('is_goalkeeper')->default(false);
            $table->unsignedSmallInteger('minutes_played')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('coach_note')->nullable();
            $table->boolean('is_mvp')->default(false);
            $table->timestamps();
            $table->unique(['game_id', 'player_id']);
            $table->unique(['game_id', 'shirt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('games');
        Schema::dropIfExists('competition_players');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('opponents');
        Schema::dropIfExists('team_coaches');
        Schema::dropIfExists('team_players');
        Schema::dropIfExists('teams');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('own_teams_only'));
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'name']);
            $table->dropUnique(['branch_id', 'abbreviation']);
            $table->dropConstrainedForeignId('branch_id');
            $table->unique('name');
            $table->unique('abbreviation');
        });
        Schema::dropIfExists('formations');
        Schema::dropIfExists('game_formats');
    }
};
```

- [ ] **Step 4: Create the models**

`app/Models/GameFormat.php`:

```php
<?php

namespace App\Models;

use App\Enums\PeriodLabel;
use App\Enums\ScoringType;
use App\Enums\Sport;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sport's game rules (football 11, handball, volleyball…): players on the
 * field, bench, periods, scoring, sanctions. Presets ship with the app and
 * stay editable. A team plays its default format; a competition may override it.
 */
class GameFormat extends Model
{
    use HasLocalizedName;

    protected $fillable = [
        'branch_id', 'sport', 'name', 'name_ar', 'name_fr', 'name_en',
        'players_on_field', 'max_bench', 'max_called_up',
        'periods_count', 'period_minutes', 'period_label', 'scoring', 'sets_to_win',
        'has_extra_time', 'has_shootout', 'sanction_types', 'is_preset', 'active',
    ];

    protected $appends = ['localized_name'];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'period_label' => PeriodLabel::class,
            'scoring' => ScoringType::class,
            'sanction_types' => 'array',
            'has_extra_time' => 'boolean',
            'has_shootout' => 'boolean',
            'is_preset' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function formations(): HasMany
    {
        return $this->hasMany(Formation::class);
    }

    /** Whether a team, competition or game uses it — such a format can be edited but not deleted. */
    public function inUse(): bool
    {
        return Team::where('default_game_format_id', $this->id)->exists()
            || Competition::where('game_format_id', $this->id)->exists()
            || Game::where('game_format_id', $this->id)->exists();
    }
}
```

`app/Models/Formation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named line-up shape (4-3-3) of a game format: one slot {label, x, y} per player on the field, x/y in 0–100. */
class Formation extends Model
{
    protected $fillable = ['game_format_id', 'name', 'slots'];

    protected function casts(): array
    {
        return ['slots' => 'array'];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }
}
```

`app/Models/Team.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A club team: a sport (branch), an age category and a name ("Cadets A").
 * Permanent; its roster and coaches are kept per season. A player of another
 * category may be on the roster (playing up).
 */
class Team extends Model
{
    protected $fillable = ['branch_id', 'category_id', 'name', 'default_game_format_id', 'archived'];

    protected function casts(): array
    {
        return ['archived' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function defaultFormat(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'default_game_format_id');
    }

    /** Every season's roster rows; use rosterFor() for one season. */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'team_players')
            ->withPivot(['season_start_year', 'shirt_number'])->withTimestamps();
    }

    public function rosterFor(int $season): BelongsToMany
    {
        return $this->players()->wherePivot('season_start_year', $season);
    }

    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_coaches')
            ->withPivot(['season_start_year', 'role'])->withTimestamps();
    }

    public function coachesFor(int $season): BelongsToMany
    {
        return $this->coaches()->wherePivot('season_start_year', $season);
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
```

`app/Models/Opponent.php`:

```php
<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A club we play against — a saved list so head-to-head history adds up. */
class Opponent extends Model
{
    protected $fillable = ['name', 'city', 'logo_url', 'logo_filename'];

    protected $hidden = ['logo_filename'];

    /** Host-relative /media URL, whatever was stored (see App\Support\Media). */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => Media::path($value));
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
```

`app/Models/Venue.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stadium or hall. `is_home` marks the club's own grounds (offered first). */
class Venue extends Model
{
    protected $fillable = ['name', 'address', 'is_home'];

    protected function casts(): array
    {
        return ['is_home' => 'boolean'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
```

`app/Models/Competition.php`:

```php
<?php

namespace App\Models;

use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One team's entry in a league, cup, tournament or friendly series for a
 * season. Its squad (competition_players) is picked from the team's roster;
 * a `hidden` squad row is a player removed after already playing in it.
 */
class Competition extends Model
{
    protected $fillable = [
        'team_id', 'season_start_year', 'name', 'type', 'game_format_id',
        'red_ban_matches', 'yellow_threshold', 'yellow_ban_matches', 'archived', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => CompetitionType::class, 'archived' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'competition_players')->withPivot('hidden')->withTimestamps();
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
```

`app/Models/Game.php`:

```php
<?php

namespace App\Models;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A match ("Game": `match` is a reserved word in PHP 8). Its game format is
 * resolved (competition override, else team default) and frozen at creation,
 * so later edits to the format never rewrite history. A game without a
 * competition is a friendly and calls players up from the team roster.
 */
class Game extends Model
{
    protected $fillable = [
        'team_id', 'competition_id', 'season_start_year', 'game_format_id', 'opponent_id', 'venue_id',
        'side', 'round_label', 'date', 'kickoff_time', 'meeting_time', 'meeting_place', 'status',
        'our_score', 'their_score', 'period_scores', 'extra_time_played', 'shootout_us', 'shootout_them',
        'formation_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'side' => GameSide::class,
            'status' => GameStatus::class,
            'period_scores' => 'array',
            'extra_time_played' => 'boolean',
            'our_score' => 'integer',
            'their_score' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(GameFormat::class, 'game_format_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Opponent::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }
}
```

`app/Models/GamePlayer.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's part in one game: called up or not (with a reason), and — from
 * phase 2 — lineup role, shirt, position, minutes, rating. A row exists only
 * for a called-up player or one left out with a reason.
 */
class GamePlayer extends Model
{
    protected $fillable = [
        'game_id', 'player_id', 'called_up', 'not_called_reason', 'role', 'shirt_number', 'position_id',
        'slot', 'is_captain', 'is_goalkeeper', 'minutes_played', 'rating', 'coach_note', 'is_mvp',
    ];

    protected function casts(): array
    {
        return [
            'called_up' => 'boolean',
            'is_captain' => 'boolean',
            'is_goalkeeper' => 'boolean',
            'is_mvp' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
```

In `app/Models/Role.php`, change `$fillable` and `casts()`:

```php
    protected $fillable = ['key', 'name', 'permissions', 'is_system', 'own_teams_only'];
```

```php
        return [
            'name' => 'array',
            'permissions' => 'array',
            'is_system' => 'boolean',
            // Coaches: edit only the teams they are assigned to for the season (see TeamAccess).
            'own_teams_only' => 'boolean',
        ];
```

In `app/Models/Position.php`, change `$fillable` to `['branch_id', 'name', 'abbreviation']` and add:

```php
    /** The sport this position belongs to; null for positions not tied to a sport yet. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
```

Also add `use Illuminate\Database\Eloquent\Relations\BelongsTo;`.

- [ ] **Step 5: Create the test fixtures**

`tests/Support/CompetitionFixtures.php`:

```php
<?php

namespace Tests\Support;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Opponent;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;

/** Builders for the competitions module; admins, categories and players come from AttendanceFixtures. */
trait CompetitionFixtures
{
    use AttendanceFixtures;

    private function sportBranch(string $name = 'Football'): Branch
    {
        return Branch::firstOrCreate(['name' => $name]);
    }

    private function format(?Branch $branch = null, array $extra = []): GameFormat
    {
        return GameFormat::create($extra + [
            'branch_id' => ($branch ?? $this->sportBranch())->id,
            'sport' => 'football', 'name' => 'Test 11',
            'players_on_field' => 11, 'max_bench' => 9, 'max_called_up' => 20,
            'periods_count' => 2, 'period_minutes' => 45, 'period_label' => 'half',
            'scoring' => 'goals', 'sanction_types' => [],
        ]);
    }

    private function team(?Category $category = null, array $extra = []): Team
    {
        $branch = isset($extra['branch_id']) ? Branch::findOrFail($extra['branch_id']) : $this->sportBranch();

        return Team::create($extra + [
            'branch_id' => $branch->id,
            'category_id' => ($category ?? Category::firstOrCreate(['name' => 'U15']))->id,
            'name' => 'Team '.uniqid(),
            'default_game_format_id' => $this->format($branch)->id,
        ]);
    }

    /** Puts $players on the team's roster for $season with shirts 1, 2, 3… */
    private function onRoster(Team $team, int $season, array $players): void
    {
        foreach (array_values($players) as $i => $player) {
            $team->players()->attach($player->id, ['season_start_year' => $season, 'shirt_number' => $i + 1]);
        }
    }

    private function opponent(string $name = 'Rivals FC'): Opponent
    {
        return Opponent::firstOrCreate(['name' => $name]);
    }

    private function competition(Team $team, int $season, array $extra = []): Competition
    {
        return Competition::create($extra + [
            'team_id' => $team->id, 'season_start_year' => $season, 'name' => 'League', 'type' => 'league',
        ]);
    }

    private function game(Team $team, array $extra = []): Game
    {
        return Game::create($extra + [
            'team_id' => $team->id, 'season_start_year' => 2026,
            'game_format_id' => $team->default_game_format_id,
            'opponent_id' => $this->opponent()->id,
            'side' => GameSide::Home, 'date' => '2026-10-17', 'status' => GameStatus::Scheduled,
        ]);
    }

    /** A user whose role limits them to the teams they coach. */
    private function coach(array $permissions = []): User
    {
        $role = Role::factory()->create([
            'own_teams_only' => true,
            'permissions' => $permissions + [
                'teams' => ['view', 'edit'],
                'competitions' => ['view', 'add', 'edit'],
                'games' => ['view', 'add', 'edit'],
            ],
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function assignCoach(Team $team, User $user, int $season, string $role = 'head'): void
    {
        $team->coaches()->attach($user->id, ['season_start_year' => $season, 'role' => $role]);
    }
}
```

- [ ] **Step 6: Write the failing test**

`tests/Feature/Competitions/CompetitionSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\CompetitionType;
use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionSchemaTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_team_keeps_one_roster_per_season(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $b = $this->player($team->category);
        $this->onRoster($team, 2025, [$a]);
        $this->onRoster($team, 2026, [$a, $b]);

        $this->assertSame([$a->id], $team->rosterFor(2025)->pluck('players.id')->all());
        $this->assertCount(2, $team->rosterFor(2026)->get());
        $this->assertSame(2, $team->rosterFor(2026)->where('players.id', $b->id)->first()->pivot->shirt_number);
    }

    #[Test]
    public function a_shirt_number_is_unique_within_a_team_season(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $team->players()->attach($a->id, ['season_start_year' => 2026, 'shirt_number' => 10]);
        $team->players()->attach($b->id, ['season_start_year' => 2025, 'shirt_number' => 10]);

        $this->expectException(QueryException::class);
        $team->players()->attach($b->id, ['season_start_year' => 2026, 'shirt_number' => 10]);
    }

    #[Test]
    public function a_game_casts_its_enums_and_reaches_its_relations(): void
    {
        $team = $this->team();
        $competition = $this->competition($team, 2026);
        $game = $this->game($team, ['competition_id' => $competition->id, 'period_scores' => [[1, 0], [1, 1]]])->fresh();

        $this->assertSame(GameSide::Home, $game->side);
        $this->assertSame(GameStatus::Scheduled, $game->status);
        $this->assertSame([[1, 0], [1, 1]], $game->period_scores);
        $this->assertSame(CompetitionType::League, $game->competition->type);
        $this->assertSame('Rivals FC', $game->opponent->name);
        $this->assertSame($team->default_game_format_id, $game->format->id);
        $this->assertTrue($game->format->inUse());
    }

    #[Test]
    public function positions_are_unique_per_sport_not_club_wide(): void
    {
        $football = $this->sportBranch('Football');
        $handball = $this->sportBranch('Handball');
        Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $football->id]);
        Position::create(['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $handball->id]);
        $this->assertSame(2, Position::where('abbreviation', 'GK')->count());

        $this->expectException(QueryException::class);
        Position::create(['name' => 'Keeper', 'abbreviation' => 'GK', 'branch_id' => $football->id]);
    }
}
```

- [ ] **Step 7: Run the test**

Run: `php artisan test --filter=CompetitionSchemaTest`
Expected: PASS, 4 tests. The test was written alongside the schema. Before Step 3 it fails with "no such table: game_formats".

- [ ] **Step 8: Run the existing position and role tests**

Run: `php artisan test --filter="Position|Role|PerList"`
Expected: PASS. (Position validation still treats names as globally unique until Task 5.)

- [ ] **Step 9: Commit**

```bash
git add scripts/i18n-add.mjs app/Enums/Sport.php app/Enums/CompetitionType.php app/Enums/GameSide.php app/Enums/GameStatus.php app/Enums/CoachRole.php app/Enums/ScoringType.php app/Enums/PeriodLabel.php database/migrations/2026_10_15_100001_create_competition_tables.php app/Models/GameFormat.php app/Models/Formation.php app/Models/Team.php app/Models/Opponent.php app/Models/Venue.php app/Models/Competition.php app/Models/Game.php app/Models/GamePlayer.php app/Models/Role.php app/Models/Position.php tests/Support/CompetitionFixtures.php tests/Feature/Competitions/CompetitionSchemaTest.php
git commit -m "feat(competitions): schema, models and enums for teams, competitions and matches

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Game format presets

**Files:**
- Create: `database/migrations/2026_10_15_100002_seed_game_format_presets.php`
- Test: `tests/Feature/Competitions/GameFormatPresetsTest.php`

**Interfaces:**
- Consumes: the `game_formats`, `formations` and `positions.branch_id` tables (Task 1).
- Produces: 8 preset formats (`is_preset = true`), named Football 11, Football 8, Football 5, Futsal, Handball, Basketball, Volleyball and Basketball 3x3, each with formations. Existing positions with no sport are linked to the football branch.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/GameFormatPresetsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Branch;
use App\Models\GameFormat;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GameFormatPresetsTest extends TestCase
{
    use RefreshDatabase;

    private function runPresets(): void
    {
        (require database_path('migrations/2026_10_15_100002_seed_game_format_presets.php'))->up();
    }

    #[Test]
    public function presets_exist_with_formations_whose_slots_match_the_players_on_field(): void
    {
        $this->assertSame(8, GameFormat::where('is_preset', true)->count());

        foreach (GameFormat::with('formations')->get() as $format) {
            $this->assertNotEmpty($format->formations, $format->name);
            foreach ($format->formations as $formation) {
                $this->assertCount($format->players_on_field, $formation->slots, "{$format->name} {$formation->name}");
            }
        }
        $this->assertSame(['yellow', 'red'], array_column(GameFormat::where('name', 'Football 11')->sole()->sanction_types, 'key'));
    }

    #[Test]
    public function presets_link_to_the_branch_of_their_sport_and_running_again_adds_nothing(): void
    {
        GameFormat::query()->delete();
        $football = Branch::create(['name' => 'Football']);
        $handball = Branch::create(['name' => 'كرة اليد', 'name_en' => 'Handball']);
        $position = Position::create(['name' => 'Striker', 'abbreviation' => 'ST']);

        $this->runPresets();
        $this->runPresets();

        $this->assertSame(8, GameFormat::count());
        $this->assertSame($football->id, GameFormat::where('name', 'Football 11')->value('branch_id'));
        $this->assertSame($football->id, GameFormat::where('name', 'Futsal')->value('branch_id'));
        $this->assertSame($handball->id, GameFormat::where('name', 'Handball')->value('branch_id'));
        $this->assertNull(GameFormat::where('name', 'Volleyball')->value('branch_id'));
        $this->assertSame($football->id, $position->fresh()->branch_id);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=GameFormatPresetsTest`
Expected: FAIL (`0` presets / migration file missing).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_15_100002_seed_game_format_presets.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ships the built-in game formats (editable afterwards). A migration, not a
 * seeder, because the desktop build runs `migrate` on boot and never seeds.
 * Each preset links to the branch whose name (any language) matches its
 * sport; unmatched presets wait for an admin to pick a branch. Positions that
 * belong to no sport yet were all football ones, so they move to football.
 * Idempotent: a preset already present (by name) is skipped.
 */
return new class extends Migration
{
    private const SPORT_NAMES = [
        'football' => ['football', 'soccer', 'foot', 'كرة القدم'],
        'futsal' => ['futsal', 'football', 'soccer', 'foot', 'كرة القدم'],
        'handball' => ['handball', 'hand', 'كرة اليد'],
        'basketball' => ['basketball', 'basket', 'كرة السلة'],
        'volleyball' => ['volleyball', 'volley', 'كرة الطائرة'],
    ];

    public function up(): void
    {
        $branches = DB::table('branches')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']);
        $branchFor = function (string $sport) use ($branches): ?int {
            foreach (self::SPORT_NAMES[$sport] ?? [] as $wanted) {
                foreach ($branches as $branch) {
                    foreach (['name', 'name_en', 'name_fr', 'name_ar'] as $column) {
                        if (mb_strtolower(trim((string) $branch->{$column})) === $wanted) {
                            return (int) $branch->id;
                        }
                    }
                }
            }

            return null;
        };

        $now = now();
        foreach (self::presets() as $preset) {
            if (DB::table('game_formats')->where('is_preset', true)->where('name', $preset['name'])->exists()) {
                continue;
            }
            $formations = $preset['formations'];
            unset($preset['formations']);

            $id = DB::table('game_formats')->insertGetId([
                ...$preset,
                'branch_id' => $branchFor($preset['sport']),
                'sanction_types' => json_encode($preset['sanction_types']),
                'is_preset' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($formations as $name => $slots) {
                DB::table('formations')->insert([
                    'game_format_id' => $id,
                    'name' => $name,
                    'slots' => json_encode(array_map(fn (array $s) => ['label' => $s[0], 'x' => $s[1], 'y' => $s[2]], $slots)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $football = $branchFor('football');
        if ($football !== null) {
            DB::table('positions')->whereNull('branch_id')->update(['branch_id' => $football]);
        }
    }

    public function down(): void
    {
        DB::table('game_formats')->where('is_preset', true)->delete();
    }

    /** @return list<array<string, mixed>> */
    private static function presets(): array
    {
        $cards = [
            ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
            ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
        ];
        // Slots: [label, x, y] — x left→right, y own goal (0) → opponent goal (100).
        $gk = ['GK', 50, 5];
        $back4 = [['LB', 15, 25], ['CB', 38, 22], ['CB', 62, 22], ['RB', 85, 25]];

        $base = fn (string $sport, string $name, string $ar, string $fr, int $field, int $bench, int $called, int $periods, int $minutes, string $label, string $scoring) => [
            'sport' => $sport, 'name' => $name, 'name_ar' => $ar, 'name_fr' => $fr, 'name_en' => $name,
            'players_on_field' => $field, 'max_bench' => $bench, 'max_called_up' => $called,
            'periods_count' => $periods, 'period_minutes' => $minutes, 'period_label' => $label, 'scoring' => $scoring,
            'sets_to_win' => null, 'has_extra_time' => false, 'has_shootout' => false,
        ];

        return [
            $base('football', 'Football 11', 'كرة القدم 11', 'Football à 11', 11, 9, 20, 2, 45, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true, 'sanction_types' => $cards,
                'formations' => [
                    '4-4-2' => [$gk, ...$back4, ['LM', 15, 55], ['CM', 38, 50], ['CM', 62, 50], ['RM', 85, 55], ['ST', 38, 82], ['ST', 62, 82]],
                    '4-3-3' => [$gk, ...$back4, ['CM', 30, 50], ['CM', 50, 45], ['CM', 70, 50], ['LW', 18, 80], ['ST', 50, 85], ['RW', 82, 80]],
                    '3-5-2' => [$gk, ['CB', 28, 22], ['CB', 50, 20], ['CB', 72, 22], ['LWB', 10, 50], ['CM', 32, 48], ['CM', 50, 55], ['CM', 68, 48], ['RWB', 90, 50], ['ST', 38, 82], ['ST', 62, 82]],
                    '4-2-3-1' => [$gk, ...$back4, ['DM', 38, 40], ['DM', 62, 40], ['LW', 18, 65], ['AM', 50, 65], ['RW', 82, 65], ['ST', 50, 85]],
                ],
            ],
            $base('football', 'Football 8', 'كرة القدم 8', 'Football à 8', 8, 6, 14, 2, 30, 'half', 'goals') + [
                'sanction_types' => $cards,
                'formations' => [
                    '3-3-1' => [$gk, ['DF', 25, 25], ['DF', 50, 22], ['DF', 75, 25], ['MF', 25, 55], ['MF', 50, 50], ['MF', 75, 55], ['ST', 50, 82]],
                    '2-3-2' => [$gk, ['DF', 35, 25], ['DF', 65, 25], ['MF', 20, 52], ['MF', 50, 50], ['MF', 80, 52], ['ST', 35, 80], ['ST', 65, 80]],
                ],
            ],
            $base('football', 'Football 5', 'كرة القدم 5', 'Football à 5', 5, 5, 10, 2, 20, 'half', 'goals') + [
                'sanction_types' => $cards,
                'formations' => [
                    '2-2' => [$gk, ['DF', 30, 30], ['DF', 70, 30], ['ST', 30, 70], ['ST', 70, 70]],
                    '1-2-1' => [$gk, ['DF', 50, 28], ['MF', 20, 52], ['MF', 80, 52], ['ST', 50, 80]],
                ],
            ],
            $base('futsal', 'Futsal', 'كرة القدم داخل القاعة', 'Futsal', 5, 9, 14, 2, 20, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true, 'sanction_types' => $cards,
                'formations' => [
                    '2-2' => [$gk, ['DF', 30, 30], ['DF', 70, 30], ['ST', 30, 70], ['ST', 70, 70]],
                    '1-2-1' => [$gk, ['DF', 50, 28], ['MF', 20, 52], ['MF', 80, 52], ['ST', 50, 80]],
                    '3-1' => [$gk, ['DF', 20, 35], ['DF', 50, 30], ['DF', 80, 35], ['ST', 50, 75]],
                ],
            ],
            $base('handball', 'Handball', 'كرة اليد', 'Handball', 7, 9, 16, 2, 30, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true,
                'sanction_types' => [
                    ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
                    ['key' => 'two_minutes', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    '3-3' => [$gk, ['LW', 8, 70], ['LB', 28, 55], ['CB', 50, 52], ['RB', 72, 55], ['RW', 92, 70], ['PV', 50, 82]],
                ],
            ],
            $base('basketball', 'Basketball', 'كرة السلة', 'Basketball', 5, 7, 12, 4, 10, 'quarter', 'points') + [
                'has_extra_time' => true,
                'sanction_types' => [
                    ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'technical', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'unsportsmanlike', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'disqualifying', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    'standard' => [['PG', 50, 55], ['SG', 80, 65], ['SF', 20, 65], ['PF', 35, 85], ['C', 65, 85]],
                ],
            ],
            $base('basketball', 'Basketball 3x3', 'كرة السلة 3×3', 'Basket 3x3', 3, 1, 4, 1, 10, 'period', 'points') + [
                'has_extra_time' => true,
                'sanction_types' => [
                    ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'technical', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'disqualifying', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    'standard' => [['G', 50, 55], ['F', 25, 80], ['F', 75, 80]],
                ],
            ],
            $base('volleyball', 'Volleyball', 'الكرة الطائرة', 'Volley-ball', 6, 8, 14, 5, 0, 'set', 'sets') + [
                'sets_to_win' => 3,
                'sanction_types' => [
                    ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
                    ['key' => 'red', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'expulsion', 'ends_play' => true, 'counts_as' => null],
                    ['key' => 'disqualification', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    '5-1' => [['S', 80, 20], ['OH', 80, 45], ['MB', 50, 45], ['OP', 20, 45], ['OH', 20, 20], ['MB', 50, 20]],
                ],
            ],
        ];
    }
};
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=GameFormatPresetsTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_15_100002_seed_game_format_presets.php tests/Feature/Competitions/GameFormatPresetsTest.php
git commit -m "feat(competitions): built-in game formats for football, futsal, handball, basketball and volleyball

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Permissions, coach scope and role flag

**Files:**
- Modify: `app/Models/Role.php` (MODULE_GROUPS, MODULES)
- Modify: `config/permissions.php`
- Create: `database/migrations/2026_10_15_100003_grant_competition_permissions.php`
- Modify: `database/seeders/RoleSeeder.php` (coach role)
- Create: `app/Services/Competitions/TeamAccess.php`
- Modify: `app/Http/Controllers/RoleController.php`, `resources/js/Pages/Roles/Index.vue`, `resources/js/Components/PermissionGrid.vue`
- Test: `tests/Feature/Competitions/CompetitionPermissionsTest.php`

**Interfaces:**
- Produces: these modules: `teams`, `competitions`, `games`, `game_formats`, `opponents`, `venues`.
- Produces: `TeamAccess::isScoped(User): bool`, `TeamAccess::canManage(User, Team, int $season): bool`, `TeamAccess::authorize(User, Team, int $season): void` (aborts 403), and `TeamAccess::manageableTeamIds(User, int $season): ?array` (null means all teams).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/CompetitionPermissionsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Role;
use App\Models\User;
use App\Services\Competitions\TeamAccess;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionPermissionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_new_modules_exist_and_routes_map_to_them(): void
    {
        foreach (['teams', 'competitions', 'games', 'game_formats', 'opponents', 'venues'] as $module) {
            $this->assertContains($module, Role::MODULES);
        }
        $this->assertSame(['teams', 'view'], PermissionMap::resolve('teams.index'));
        $this->assertSame(['teams', 'edit'], PermissionMap::resolve('teams.roster.update'));
        $this->assertSame(['competitions', 'edit'], PermissionMap::resolve('competitions.squad.update'));
        $this->assertSame(['games', 'add'], PermissionMap::resolve('games.store'));
        $this->assertSame(['games', 'edit'], PermissionMap::resolve('games.call-up.update'));
        // "convocation" is not a view verb: printing the sheet must not need edit rights.
        $this->assertSame(['games', 'view'], PermissionMap::resolve('games.convocation'));
        $this->assertSame(['game_formats', 'view'], PermissionMap::resolve('game-formats.index'));
        $this->assertSame(['opponents', 'add'], PermissionMap::resolve('opponents.store'));
        $this->assertSame(['venues', 'delete'], PermissionMap::resolve('venues.destroy'));
    }

    #[Test]
    public function the_migration_grants_system_roles_everything_and_the_coach_role_its_scoped_rights(): void
    {
        $admin = Role::factory()->create(['key' => 'administrator', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $coach = Role::factory()->create(['key' => 'coach', 'permissions' => ['players' => ['view']]]);
        $cashier = Role::factory()->create(['key' => 'cashier', 'permissions' => ['transactions' => ['view']]]);

        (require database_path('migrations/2026_10_15_100003_grant_competition_permissions.php'))->up();

        $this->assertSame(Role::ACTIONS, $admin->fresh()->permissions['games']);
        $this->assertSame(Role::ACTIONS, $admin->fresh()->permissions['venues']);
        $this->assertFalse($admin->fresh()->own_teams_only);
        $this->assertSame(['view', 'add', 'edit'], $coach->fresh()->permissions['games']);
        $this->assertSame(['view', 'edit'], $coach->fresh()->permissions['teams']);
        $this->assertTrue($coach->fresh()->own_teams_only);
        $this->assertArrayNotHasKey('games', $cashier->fresh()->permissions);
    }

    #[Test]
    public function unscoped_users_manage_every_team(): void
    {
        $team = $this->team();
        $director = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['teams' => ['view', 'edit']]])->id]);

        $this->assertTrue(TeamAccess::canManage($this->admin(), $team, 2026));
        $this->assertTrue(TeamAccess::canManage($director, $team, 2026));
        $this->assertNull(TeamAccess::manageableTeamIds($director, 2026));
    }

    #[Test]
    public function a_scoped_coach_manages_only_the_teams_coached_that_season(): void
    {
        $team = $this->team();
        $other = $this->team();
        $coach = $this->coach();
        $this->assignCoach($team, $coach, 2026);

        $this->assertTrue(TeamAccess::isScoped($coach));
        $this->assertTrue(TeamAccess::canManage($coach, $team, 2026));
        $this->assertFalse(TeamAccess::canManage($coach, $team, 2025));
        $this->assertFalse(TeamAccess::canManage($coach, $other, 2026));
        $this->assertSame([$team->id], TeamAccess::manageableTeamIds($coach, 2026));
    }

    #[Test]
    public function the_role_editor_saves_the_own_teams_flag(): void
    {
        $superadmin = User::factory()->create(['privileges' => ['superadmin']]);

        $this->actingAs($superadmin)->post(route('roles.store'), [
            'name' => ['en' => 'Assistant coach'],
            'permissions' => ['games' => ['view']],
            'own_teams_only' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Role::latest('id')->first()->own_teams_only);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=CompetitionPermissionsTest`
Expected: FAIL (`teams` not in `Role::MODULES`).

- [ ] **Step 3: Register modules and routes**

In `app/Models/Role.php`:

```php
    public const MODULE_GROUPS = [
        'members' => ['players', 'documents', 'attendance', 'subscriptions'],
        'competitions' => ['teams', 'competitions', 'games'],
        'finance' => ['transactions', 'finance', 'reports'],
        'equipment' => ['equipment', 'inventory'],
        'board' => ['board'],
        'config' => [
            'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
            'board_roles', 'equipment_categories', 'storage_locations',
            'game_formats', 'opponents', 'venues',
        ],
        'system' => ['users', 'settings'],
    ];

    /** Permission-controlled modules. */
    public const MODULES = [
        'players', 'documents', 'attendance', 'subscriptions',
        'teams', 'competitions', 'games',
        'transactions', 'finance', 'reports',
        'equipment', 'inventory',
        'board',
        'categories', 'branches', 'positions', 'player_statuses', 'document_types', 'jobs',
        'board_roles', 'equipment_categories', 'storage_locations',
        'game_formats', 'opponents', 'venues',
        'users', 'settings',
    ];
```

In `config/permissions.php` → `'modules'`, after `'attendance' => 'attendance',` add:

```php
        // Competitions & matches. Coaches are further limited to their own teams (TeamAccess).
        'teams' => 'teams',
        'competitions' => 'competitions',
        'games' => 'games',
```

And after `'storage-locations' => 'storage_locations',` add:

```php
        'game-formats' => 'game_formats',
        'opponents' => 'opponents',
        'venues' => 'venues',
```

In `'overrides'`, after the attendance overrides block, add:

```php
        // "convocation" is not a view verb: printing the call-up sheet must not need edit rights.
        'games.convocation' => ['games', 'view'],
```

- [ ] **Step 4: Write the grant migration**

`database/migrations/2026_10_15_100003_grant_competition_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The competitions modules. Owner's rule for new rights: they start empty,
 * except the system roles (full access) and the seeded coach role, which gets
 * what a coach does on match days — limited to the teams they coach
 * (`own_teams_only`). A migration because the desktop build never seeds.
 * Idempotent: a module a role already has is left alone.
 */
return new class extends Migration
{
    private const MODULES = ['teams', 'competitions', 'games', 'game_formats', 'opponents', 'venues'];

    private const ALL = ['view', 'add', 'edit', 'delete'];

    private const COACH = [
        'teams' => ['view', 'edit'],
        'competitions' => ['view', 'add', 'edit'],
        'games' => ['view', 'add', 'edit'],
        'game_formats' => ['view'],
        'opponents' => ['view', 'add'],
        'venues' => ['view', 'add'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'key', 'is_system', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $grant = $role->is_system ? array_fill_keys(self::MODULES, self::ALL) : ($role->key === 'coach' ? self::COACH : []);

            foreach ($grant as $module => $actions) {
                $permissions[$module] ??= $actions;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($permissions),
                'own_teams_only' => $role->key === 'coach',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            foreach (self::MODULES as $module) {
                unset($permissions[$module]);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'own_teams_only' => false]);
        }
    }
};
```

In `database/seeders/RoleSeeder.php`, change the coach role's `updateOrCreate` attributes to:

```php
            'permissions' => [
                'players' => ['view', 'add', 'edit'],
                'attendance' => ['view', 'add', 'edit'],
                'teams' => ['view', 'edit'],
                'competitions' => ['view', 'add', 'edit'],
                'games' => ['view', 'add', 'edit'],
                'game_formats' => ['view'],
                'opponents' => ['view', 'add'],
                'venues' => ['view', 'add'],
                'equipment' => ['view'],
                'inventory' => ['view'],
            ],
            'is_system' => false,
            'own_teams_only' => true,
```

- [ ] **Step 5: Write TeamAccess**

`app/Services/Competitions/TeamAccess.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may change a team's squads, matches and call-ups. Role permissions
 * decide WHAT a user may do; this decides FOR WHICH TEAMS. A user whose role
 * is limited to its own teams (`own_teams_only`, the coach role) manages only
 * the teams they are assigned to for that season, and still views every team.
 */
final class TeamAccess
{
    public static function isScoped(User $user): bool
    {
        return ! $user->isGodAdmin() && (bool) $user->role?->own_teams_only;
    }

    public static function canManage(User $user, Team $team, int $season): bool
    {
        if (! self::isScoped($user)) {
            return true;
        }

        return DB::table('team_coaches')
            ->where('team_id', $team->id)->where('season_start_year', $season)->where('user_id', $user->id)
            ->exists();
    }

    public static function authorize(User $user, Team $team, int $season): void
    {
        abort_unless(self::canManage($user, $team, $season), 403);
    }

    /** @return list<int>|null the team ids the user manages in $season; null means every team. */
    public static function manageableTeamIds(User $user, int $season): ?array
    {
        if (! self::isScoped($user)) {
            return null;
        }

        return DB::table('team_coaches')->where('user_id', $user->id)->where('season_start_year', $season)
            ->pluck('team_id')->map(fn ($id) => (int) $id)->all();
    }
}
```

- [ ] **Step 6: Role editor flag**

In `app/Http/Controllers/RoleController.php` → `validateRole()`, add to the rules array:

```php
            'own_teams_only' => ['sometimes', 'boolean'],
```

In both `store()` and `update()`, right after the `sanitisePermissions` line, add:

```php
        $validated['own_teams_only'] = $request->boolean('own_teams_only');
```

In `resources/js/Pages/Roles/Index.vue`:
- Change the form to `useForm({ name: { en: '', fr: '', ar: '' }, permissions: {}, own_teams_only: false })`.
- In `openCreate()`, add `form.own_teams_only = false;`.
- In `openEdit(role)`, add `form.own_teams_only = !!role.own_teams_only;`.
- After the `<PermissionGrid … />` line, add:

```vue
                <label class="mt-4 flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input v-model="form.own_teams_only" type="checkbox" class="mt-0.5 rounded border-slate-300 dark:border-slate-700" />
                    <span>
                        <span class="font-medium">{{ t('comp.own_teams_only') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('comp.own_teams_only_help') }}</span>
                    </span>
                </label>
```

In `resources/js/Components/PermissionGrid.vue` → `SECTION_LABELS`, add `competitions: 'nav.competitions',` after `members`.

- [ ] **Step 7: Add the translations**

Run:

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "nav.competitions": "Competitions & matches",
    "teams": "Teams", "competitions": "Competitions", "games": "Matches",
    "game_formats": "Game formats", "opponents": "Opponents", "venues": "Venues",
    "comp.own_teams_only": "Only the teams they coach",
    "comp.own_teams_only_help": "Users with this role edit squads, matches and call-ups only for the teams they are assigned to this season. They can still view every team."
  },
  "fr": {
    "nav.competitions": "Compétitions et matchs",
    "teams": "Équipes", "competitions": "Compétitions", "games": "Matchs",
    "game_formats": "Formats de jeu", "opponents": "Adversaires", "venues": "Terrains et salles",
    "comp.own_teams_only": "Seulement les équipes qu'il entraîne",
    "comp.own_teams_only_help": "Les utilisateurs de ce rôle modifient les effectifs, matchs et convocations uniquement pour les équipes qui leur sont confiées cette saison. Ils voient toutes les équipes."
  },
  "ar": {
    "nav.competitions": "المنافسات والمباريات",
    "teams": "الفرق", "competitions": "المنافسات", "games": "المباريات",
    "game_formats": "أنظمة اللعب", "opponents": "المنافسون", "venues": "الملاعب والقاعات",
    "comp.own_teams_only": "الفرق التي يدرّبها فقط",
    "comp.own_teams_only_help": "مستخدمو هذا الدور يعدّلون القوائم والمباريات والاستدعاءات للفرق المسندة إليهم هذا الموسم فقط، ويمكنهم الاطلاع على كل الفرق."
  }
}
EOF
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="CompetitionPermissionsTest|RoleManagement|PerListPermissions|ModuleAccess|AttendancePermission"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Role.php config/permissions.php database/migrations/2026_10_15_100003_grant_competition_permissions.php database/seeders/RoleSeeder.php app/Services/Competitions/TeamAccess.php app/Http/Controllers/RoleController.php resources/js/Pages/Roles/Index.vue resources/js/Components/PermissionGrid.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/CompetitionPermissionsTest.php
git commit -m "feat(competitions): permission modules and coach scope to own teams

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Opponents and venues lists

**Files:**
- Create: `app/Http/Controllers/OpponentController.php`, `app/Http/Controllers/VenueController.php`
- Create: `resources/js/Pages/Settings/Opponents.vue`, `resources/js/Pages/Settings/Venues.vue`
- Modify: `routes/web.php`, `config/activity.php`
- Test: `tests/Feature/Competitions/OpponentVenueSettingsTest.php`

**Interfaces:**
- Consumes: the `Opponent` and `Venue` models (Task 1) and the `opponents` / `venues` modules (Task 3).
- Produces: routes `opponents.{index,store,update,destroy}` and `venues.{index,store,update,destroy}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/OpponentVenueSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Opponent;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class OpponentVenueSettingsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function opponents_are_listed_added_renamed_and_kept_unique(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('opponents.store'), ['name' => 'MC Alger', 'city' => 'Alger'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.opponent_created');
        $this->actingAs($admin)->post(route('opponents.store'), ['name' => 'MC Alger'])->assertSessionHasErrors('name');

        $opponent = Opponent::sole();
        $this->actingAs($admin)->put(route('opponents.update', $opponent), ['name' => 'MC Alger U17', 'city' => ''])
            ->assertSessionHas('success', 'flash.opponent_updated');
        $this->assertSame('MC Alger U17', $opponent->fresh()->name);
        $this->assertNull($opponent->fresh()->city);

        $this->actingAs($admin)->get(route('opponents.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Opponents')
                ->has('opponents', 1)->where('opponents.0.games_count', 0));
    }

    #[Test]
    public function an_opponent_with_matches_cannot_be_deleted(): void
    {
        $team = $this->team();
        $this->game($team);
        $opponent = Opponent::sole();

        $this->actingAs($this->admin())->delete(route('opponents.destroy', $opponent))
            ->assertSessionHas('error', 'flash.opponent_in_use');
        $this->assertModelExists($opponent);
    }

    #[Test]
    public function venues_are_managed_and_deleting_one_keeps_its_matches(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('venues.store'), ['name' => 'Stade 5 Juillet', 'address' => 'Alger', 'is_home' => true])
            ->assertSessionHas('success', 'flash.venue_created');
        $venue = Venue::sole();
        $this->assertTrue($venue->is_home);

        $game = $this->game($this->team(), ['venue_id' => $venue->id]);
        $this->actingAs($admin)->delete(route('venues.destroy', $venue))->assertSessionHas('success', 'flash.venue_deleted');

        $this->assertNull($game->fresh()->venue_id);
        $this->actingAs($admin)->get(route('venues.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Venues')->has('venues', 0));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=OpponentVenueSettingsTest`
Expected: FAIL (`Route [opponents.store] not defined`).

- [ ] **Step 3: Write the controllers**

`app/Http/Controllers/OpponentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Opponent;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OpponentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Opponents', [
            'opponents' => Opponent::query()->withCount('games')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, FileStorageService $files): RedirectResponse
    {
        Opponent::create($this->validated($request, $files));

        return back()->with('success', 'flash.opponent_created');
    }

    public function update(Request $request, Opponent $opponent, FileStorageService $files): RedirectResponse
    {
        $data = $this->validated($request, $files, $opponent);
        if (isset($data['logo_filename'])) {
            $files->delete($opponent->logo_filename);
        }
        $opponent->update($data);

        return back()->with('success', 'flash.opponent_updated');
    }

    public function destroy(Opponent $opponent, FileStorageService $files): RedirectResponse
    {
        if ($opponent->games()->exists()) {
            return back()->with('error', 'flash.opponent_in_use');
        }
        $files->delete($opponent->logo_filename);
        $opponent->delete();

        return back()->with('success', 'flash.opponent_deleted');
    }

    /** @return array<string, mixed> the columns to save, with the stored logo when one was uploaded */
    private function validated(Request $request, FileStorageService $files, ?Opponent $opponent = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('opponents', 'name')->ignore($opponent)],
            'city' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);
        unset($data['logo']);

        if ($request->hasFile('logo')) {
            $stored = $files->storeImage($request->file('logo'), 'opponents', 256);
            $data['logo_url'] = $stored['url'];
            $data['logo_filename'] = $stored['filename'];
        }

        return $data;
    }
}
```

`app/Http/Controllers/VenueController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VenueController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Venues', [
            'venues' => Venue::query()->withCount('games')->orderByDesc('is_home')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Venue::create($this->validated($request));

        return back()->with('success', 'flash.venue_created');
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $venue->update($this->validated($request, $venue));

        return back()->with('success', 'flash.venue_updated');
    }

    /** Matches played there keep their record; only the venue link is cleared. */
    public function destroy(Venue $venue): RedirectResponse
    {
        $venue->delete();

        return back()->with('success', 'flash.venue_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Venue $venue = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('venues', 'name')->ignore($venue)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_home' => ['sometimes', 'boolean'],
        ]);
        $data['is_home'] = $request->boolean('is_home');

        return $data;
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the imports `use App\Http\Controllers\OpponentController;` and `use App\Http\Controllers\VenueController;` (keep the alphabetical order of the existing `use` lines). Directly after the line `Route::resource('document-types', DocumentTypeController::class)->except(['show', 'create', 'edit']);` add:

```php
        // Competitions & matches — configuration lists
        Route::resource('opponents', OpponentController::class)->except(['show', 'create', 'edit']);
        Route::resource('venues', VenueController::class)->except(['show', 'create', 'edit']);
```

In `config/activity.php` → `$settingLists`, add:

```php
    'opponents' => 'opponents',
    'venues' => 'venues',
```

- [ ] **Step 5: Write the pages**

`resources/js/Pages/Settings/Opponents.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({ opponents: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', city: '', logo: null });
// Files only travel in multipart POSTs, so the update is a POST spoofing PUT.
const editForm = useForm({ _method: 'put', name: '', city: '', logo: null });

function add() {
    form.post(route('opponents.store'), { forceFormData: true, onSuccess: () => form.reset() });
}

function startEdit(opponent) {
    editingId.value = opponent.id;
    editForm.name = opponent.name;
    editForm.city = opponent.city || '';
    editForm.logo = null;
}

function saveEdit(id) {
    editForm.post(route('opponents.update', id), { forceFormData: true, onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('opponents.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('opponents')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('opponents') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="w-40">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('city') }}</label>
                    <TextInput v-model="form.city" class="mt-1 w-full" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('comp.logo') }}</label>
                    <input type="file" accept="image/*" class="mt-1 block w-48 text-xs" @change="form.logo = $event.target.files[0]" />
                    <InputError :message="form.errors.logo" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('city') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('games') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="opponent in opponents" :key="opponent.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === opponent.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                    <input type="file" accept="image/*" class="block text-xs" @change="editForm.logo = $event.target.files[0]" />
                                </div>
                                <div v-else class="flex items-center gap-2">
                                    <img v-if="opponent.logo_url" :src="opponent.logo_url" alt="" class="h-7 w-7 rounded object-contain" />
                                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ opponent.name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <TextInput v-if="editingId === opponent.id" v-model="editForm.city" class="w-full" />
                                <span v-else>{{ opponent.city }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ opponent.games_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === opponent.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(opponent.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(opponent)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = opponent.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!opponents?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

`resources/js/Pages/Settings/Venues.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({ venues: Array });

const editingId = ref(null);
const deleteId = ref(null);
const form = useForm({ name: '', address: '', is_home: false });
const editForm = useForm({ name: '', address: '', is_home: false });

function add() {
    form.post(route('venues.store'), { onSuccess: () => form.reset() });
}

function startEdit(venue) {
    editingId.value = venue.id;
    editForm.name = venue.name;
    editForm.address = venue.address || '';
    editForm.is_home = venue.is_home;
}

function saveEdit(id) {
    editForm.put(route('venues.update', id), { onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('venues.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('venues')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('venues') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('address') }}</label>
                    <TextInput v-model="form.address" class="mt-1 w-full" />
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700 dark:text-slate-200">
                    <input v-model="form.is_home" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                    {{ t('comp.home_ground') }}
                </label>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('address') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('games') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="venue in venues" :key="venue.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === venue.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                    <label class="flex items-center gap-2 text-xs"><input v-model="editForm.is_home" type="checkbox" class="rounded" /> {{ t('comp.home_ground') }}</label>
                                </div>
                                <div v-else class="flex items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                                    {{ venue.name }}
                                    <span v-if="venue.is_home" class="rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">{{ t('comp.home_ground') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <TextInput v-if="editingId === venue.id" v-model="editForm.address" class="w-full" />
                                <span v-else>{{ venue.address }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ venue.games_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === venue.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(venue.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(venue)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = venue.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!venues?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Add the translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.logo": "Logo", "comp.home_ground": "Home ground",
    "flash.opponent_created": "Opponent added.", "flash.opponent_updated": "Opponent updated.",
    "flash.opponent_deleted": "Opponent deleted.", "flash.opponent_in_use": "This opponent has matches and cannot be deleted.",
    "flash.venue_created": "Venue added.", "flash.venue_updated": "Venue updated.", "flash.venue_deleted": "Venue deleted.",
    "activity.kind.opponents": "Opponents", "activity.kind.venues": "Venues"
  },
  "fr": {
    "comp.logo": "Logo", "comp.home_ground": "Terrain du club",
    "flash.opponent_created": "Adversaire ajouté.", "flash.opponent_updated": "Adversaire modifié.",
    "flash.opponent_deleted": "Adversaire supprimé.", "flash.opponent_in_use": "Cet adversaire a des matchs et ne peut pas être supprimé.",
    "flash.venue_created": "Lieu ajouté.", "flash.venue_updated": "Lieu modifié.", "flash.venue_deleted": "Lieu supprimé.",
    "activity.kind.opponents": "Adversaires", "activity.kind.venues": "Terrains et salles"
  },
  "ar": {
    "comp.logo": "الشعار", "comp.home_ground": "ملعب النادي",
    "flash.opponent_created": "تمت إضافة المنافس.", "flash.opponent_updated": "تم تعديل المنافس.",
    "flash.opponent_deleted": "تم حذف المنافس.", "flash.opponent_in_use": "لهذا المنافس مباريات ولا يمكن حذفه.",
    "flash.venue_created": "تمت إضافة الملعب.", "flash.venue_updated": "تم تعديل الملعب.", "flash.venue_deleted": "تم حذف الملعب.",
    "activity.kind.opponents": "المنافسون", "activity.kind.venues": "الملاعب والقاعات"
  }
}
EOF
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="OpponentVenueSettingsTest|FlashTranslation|UsersBatch"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/OpponentController.php app/Http/Controllers/VenueController.php resources/js/Pages/Settings/Opponents.vue resources/js/Pages/Settings/Venues.vue routes/web.php config/activity.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/OpponentVenueSettingsTest.php
git commit -m "feat(competitions): opponents and venues lists

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Game formats page and positions per sport

**Files:**
- Create: `app/Http/Controllers/GameFormatController.php`, `resources/js/Pages/Settings/GameFormats.vue`
- Modify: `app/Http/Controllers/PositionController.php`, `resources/js/Pages/Settings/Positions.vue`, `routes/web.php`, `config/activity.php`
- Test: `tests/Feature/Competitions/GameFormatSettingsTest.php`

**Interfaces:**
- Consumes: `GameFormat`, `Formation`, `Sport`, `PeriodLabel`, `ScoringType`.
- Produces: routes `game-formats.{index,store,update,destroy}`. The Positions page and API now carry `branch_id`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/GameFormatSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\GameFormat;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class GameFormatSettingsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + [
            'branch_id' => $this->sportBranch()->id, 'sport' => 'football', 'name' => 'Football 7',
            'players_on_field' => 7, 'max_bench' => 5, 'max_called_up' => 12,
            'periods_count' => 2, 'period_minutes' => 25, 'period_label' => 'half', 'scoring' => 'goals',
            'has_extra_time' => false, 'has_shootout' => false, 'active' => true,
            'sanction_types' => [['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow']],
        ];
    }

    #[Test]
    public function the_page_lists_presets_with_their_formations(): void
    {
        $this->actingAs($this->admin())->get(route('game-formats.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/GameFormats')
                ->has('formats', 8)->has('sports')->has('branches'));
    }

    #[Test]
    public function a_format_is_created_and_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload(['max_called_up' => 5]))
            ->assertSessionHasErrors('max_called_up');
        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload(['scoring' => 'sets']))
            ->assertSessionHasErrors('sets_to_win');

        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.game_format_created');
        $format = GameFormat::where('name', 'Football 7')->sole();
        $this->assertFalse($format->is_preset);
        $this->assertSame('yellow', $format->sanction_types[0]['key']);
    }

    #[Test]
    public function a_preset_can_be_edited_but_a_format_in_use_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $preset = GameFormat::where('name', 'Football 11')->sole();
        $this->actingAs($admin)->put(route('game-formats.update', $preset), $this->payload(['name' => 'Football 11', 'players_on_field' => 11, 'max_called_up' => 18]))
            ->assertSessionHas('success', 'flash.game_format_updated');
        $this->assertSame(18, $preset->fresh()->max_called_up);

        $team = $this->team();
        $this->actingAs($admin)->delete(route('game-formats.destroy', $team->defaultFormat))
            ->assertSessionHas('error', 'flash.game_format_in_use');
        $this->actingAs($admin)->delete(route('game-formats.destroy', $preset))
            ->assertSessionHas('success', 'flash.game_format_deleted');
        $this->assertModelMissing($preset);
    }

    #[Test]
    public function positions_belong_to_a_sport(): void
    {
        $admin = $this->admin();
        $football = $this->sportBranch('Football');
        $handball = $this->sportBranch('Handball');

        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Goalkeeper', 'abbreviation' => 'gk', 'branch_id' => $football->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $handball->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Keeper', 'abbreviation' => 'GK', 'branch_id' => $football->id])
            ->assertSessionHasErrors('abbreviation');

        $this->assertSame(2, Position::where('abbreviation', 'GK')->count());
        $this->actingAs($admin)->get(route('positions.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Positions')->has('branches', 2));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=GameFormatSettingsTest`
Expected: FAIL (`Route [game-formats.index] not defined`).

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/GameFormatController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\PeriodLabel;
use App\Enums\ScoringType;
use App\Enums\Sport;
use App\Models\Branch;
use App\Models\GameFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GameFormatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/GameFormats', [
            'formats' => GameFormat::query()->with(['branch', 'formations:id,game_format_id,name'])
                ->orderBy('sport')->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'sports' => Sport::values(),
            'periodLabels' => PeriodLabel::values(),
            'scorings' => ScoringType::values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        GameFormat::create($this->validated($request) + ['is_preset' => false]);

        return back()->with('success', 'flash.game_format_created');
    }

    public function update(Request $request, GameFormat $gameFormat): RedirectResponse
    {
        $gameFormat->update($this->validated($request));

        return back()->with('success', 'flash.game_format_updated');
    }

    public function destroy(GameFormat $gameFormat): RedirectResponse
    {
        if ($gameFormat->inUse()) {
            return back()->with('error', 'flash.game_format_in_use');
        }
        $gameFormat->delete();

        return back()->with('success', 'flash.game_format_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sport' => ['required', Rule::enum(Sport::class)],
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'name_fr' => ['nullable', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'players_on_field' => ['required', 'integer', 'min:1', 'max:30'],
            'max_bench' => ['required', 'integer', 'min:0', 'max:30'],
            'max_called_up' => ['required', 'integer', 'max:60', 'gte:players_on_field'],
            'periods_count' => ['required', 'integer', 'min:1', 'max:9'],
            'period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'period_label' => ['required', Rule::enum(PeriodLabel::class)],
            'scoring' => ['required', Rule::enum(ScoringType::class)],
            'sets_to_win' => ['nullable', 'required_if:scoring,sets', 'integer', 'min:1', 'max:5'],
            'has_extra_time' => ['sometimes', 'boolean'],
            'has_shootout' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'sanction_types' => ['present', 'array', 'max:10'],
            'sanction_types.*.key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'sanction_types.*.ends_play' => ['sometimes', 'boolean'],
            'sanction_types.*.counts_as' => ['nullable', Rule::in(['yellow', 'red'])],
        ]);

        foreach (['has_extra_time', 'has_shootout', 'active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['sanction_types'] = array_map(fn (array $s) => [
            'key' => $s['key'],
            'ends_play' => (bool) ($s['ends_play'] ?? false),
            'counts_as' => $s['counts_as'] ?? null,
        ], $data['sanction_types']);
        if ($data['scoring'] !== ScoringType::Sets->value) {
            $data['sets_to_win'] = null;
        }

        return $data;
    }
}
```

- [ ] **Step 4: Positions become per sport**

Replace `app/Http/Controllers/PositionController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Position;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Positions', [
            'positions' => Position::query()->with('branch')->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Position::create($this->validated($request));

        return back()->with('success', 'flash.position_created');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $position->update($this->validated($request, $position));

        return back()->with('success', 'flash.position_updated');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('success', 'flash.position_deleted');
    }

    /** Names and abbreviations are unique within a sport: football and handball each have their GK. */
    private function validated(Request $request, ?Position $position = null): array
    {
        $request->merge(['abbreviation' => strtoupper((string) $request->input('abbreviation'))]);
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $sameSport = fn (Builder $q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId);

        return $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('positions', 'name')->where($sameSport)->ignore($position)],
            'abbreviation' => ['required', 'string', 'max:16', Rule::unique('positions', 'abbreviation')->where($sameSport)->ignore($position)],
        ]);
    }
}
```

In `resources/js/Pages/Settings/Positions.vue`:
- Change the props to `defineProps({ positions: Array, branches: Array })` (and keep `const props =`).
- Change the forms to `useForm({ name: '', abbreviation: '', branch_id: '' })` (both of them).
- In `startEdit`, add `editForm.branch_id = pos.branch_id ?? '';`.
- In the add form, before the abbreviation field, add:

```vue
                <div class="w-40">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('comp.sport') }}</label>
                    <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">—</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                    </select>
                </div>
```

- Add `<InputError :message="form.errors.abbreviation" class="mt-1" />` under the abbreviation input.
- In the table, add a first column header `<th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('comp.sport') }}</th>`, and a first cell in each row:

```vue
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <select v-if="editingId === pos.id" v-model="editForm.branch_id" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                                    <option value="">—</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                                </select>
                                <span v-else>{{ pos.branch?.localized_name || pos.branch?.name || '—' }}</span>
                            </td>
```

- Change the empty row's `colspan="3"` to `colspan="4"`.

- [ ] **Step 5: Write the Game formats page**

`resources/js/Pages/Settings/GameFormats.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    formats: Array,
    branches: Array,
    sports: Array,
    periodLabels: Array,
    scorings: Array,
});

const editing = ref(null); // null | 'new' | format
const deleteId = ref(null);

const blank = () => ({
    branch_id: '', sport: 'football', name: '', name_ar: '', name_fr: '', name_en: '',
    players_on_field: 11, max_bench: 9, max_called_up: 20, periods_count: 2, period_minutes: 45,
    period_label: 'half', scoring: 'goals', sets_to_win: null,
    has_extra_time: false, has_shootout: false, active: true, sanction_types: [],
});
const form = useForm(blank());

const bySport = computed(() => props.sports
    .map((sport) => ({ sport, formats: props.formats.filter((f) => f.sport === sport) }))
    .filter((group) => group.formats.length));

// Unknown sanction keys (added by an admin) show as typed.
function sanctionLabel(key) {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
}

function openNew() {
    editing.value = 'new';
    form.defaults(blank());
    form.reset();
    form.clearErrors();
}

function openEdit(format) {
    editing.value = format;
    form.clearErrors();
    Object.assign(form, {
        ...blank(),
        ...Object.fromEntries(Object.keys(blank()).map((k) => [k, format[k] ?? blank()[k]])),
        branch_id: format.branch_id ?? '',
        sanction_types: (format.sanction_types ?? []).map((s) => ({ ...s })),
    });
}

function addSanction() {
    form.sanction_types.push({ key: '', ends_play: false, counts_as: null });
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    if (editing.value === 'new') form.post(route('game-formats.store'), options);
    else form.put(route('game-formats.update', editing.value.id), options);
}

function destroy() {
    router.delete(route('game-formats.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('game_formats')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('game_formats') }}</h1>
                <PrimaryButton @click="openNew">{{ t('comp.new_format') }}</PrimaryButton>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <form v-if="editing" @submit.prevent="save" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.sport') }}
                        <select v-model="form.sport" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in sports" :key="s" :value="s">{{ t(`comp.sport_${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('branch') }}
                        <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">—</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm sm:col-span-2">{{ t('name') }}
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </label>
                    <label class="text-sm">العربية <TextInput v-model="form.name_ar" dir="rtl" class="mt-1 w-full" /></label>
                    <label class="text-sm">Français <TextInput v-model="form.name_fr" class="mt-1 w-full" /></label>
                    <label class="text-sm">English <TextInput v-model="form.name_en" class="mt-1 w-full" /></label>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.players_on_field') }}
                        <TextInput v-model.number="form.players_on_field" type="number" min="1" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.max_bench') }}
                        <TextInput v-model.number="form.max_bench" type="number" min="0" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.max_called_up') }}
                        <TextInput v-model.number="form.max_called_up" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="form.errors.max_called_up" class="mt-1" />
                    </label>
                    <label class="text-sm">{{ t('comp.scoring') }}
                        <select v-model="form.scoring" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in scorings" :key="s" :value="s">{{ t(`comp.scoring_${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.periods') }}
                        <TextInput v-model.number="form.periods_count" type="number" min="1" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.period_minutes') }}
                        <TextInput v-model.number="form.period_minutes" type="number" min="0" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.period_label') }}
                        <select v-model="form.period_label" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="p in periodLabels" :key="p" :value="p">{{ t(`comp.period_${p}`) }}</option>
                        </select>
                    </label>
                    <label v-if="form.scoring === 'sets'" class="text-sm">{{ t('comp.sets_to_win') }}
                        <TextInput v-model.number="form.sets_to_win" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="form.errors.sets_to_win" class="mt-1" />
                    </label>
                </div>

                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="form.has_extra_time" type="checkbox" class="rounded" /> {{ t('comp.has_extra_time') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.has_shootout" type="checkbox" class="rounded" /> {{ t('comp.has_shootout') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" class="rounded" /> {{ t('comp.active') }}</label>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ t('comp.sanctions') }}</h3>
                        <SecondaryButton type="button" @click="addSanction">{{ t('add') }}</SecondaryButton>
                    </div>
                    <div v-for="(s, i) in form.sanction_types" :key="i" class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                        <TextInput v-model="s.key" class="w-40 font-mono" placeholder="yellow" />
                        <span class="w-32 text-slate-500">{{ sanctionLabel(s.key) }}</span>
                        <label class="flex items-center gap-1"><input v-model="s.ends_play" type="checkbox" class="rounded" /> {{ t('comp.ends_play') }}</label>
                        <select v-model="s.counts_as" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option :value="null">{{ t('comp.counts_as_none') }}</option>
                            <option value="yellow">{{ t('comp.counts_as_yellow') }}</option>
                            <option value="red">{{ t('comp.counts_as_red') }}</option>
                        </select>
                        <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="form.sanction_types.splice(i, 1)" />
                        <InputError :message="form.errors[`sanction_types.${i}.key`]" class="w-full" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>

            <section v-for="group in bySport" :key="group.sport" class="space-y-2">
                <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t(`comp.sport_${group.sport}`) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="format in group.formats" :key="format.id" class="rounded-2xl bg-white dark:bg-slate-900 p-4 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800" :class="{ 'opacity-60': !format.active }">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ format.localized_name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ format.branch ? (format.branch.localized_name || format.branch.name) : t('comp.no_branch') }}
                                    <span v-if="format.is_preset"> · {{ t('comp.preset') }}</span>
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="openEdit(format)" />
                                <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = format.id" />
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ t('comp.format_summary', { field: format.players_on_field, bench: format.max_bench, called: format.max_called_up }) }}
                            · {{ format.periods_count }} × {{ t(`comp.period_${format.period_label}`) }}<span v-if="format.period_minutes"> ({{ format.period_minutes }}′)</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span v-for="f in format.formations" :key="f.id" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs dark:bg-slate-800">{{ f.name }}</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Routes, activity and translations**

In `routes/web.php`, add `use App\Http\Controllers\GameFormatController;`, and inside the "Competitions & matches — configuration lists" block from Task 4 add:

```php
        Route::resource('game-formats', GameFormatController::class)->except(['show', 'create', 'edit']);
```

In `config/activity.php` → `$settingLists`, add `'game-formats' => 'game_formats',`.

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.sport": "Sport", "comp.new_format": "New format", "comp.no_branch": "No branch linked", "comp.preset": "built-in",
    "comp.players_on_field": "Players on the field", "comp.max_bench": "Max substitutes", "comp.max_called_up": "Max called up",
    "comp.scoring": "Scoring", "comp.periods": "Periods", "comp.period_minutes": "Minutes per period", "comp.period_label": "Period name",
    "comp.sets_to_win": "Sets to win", "comp.has_extra_time": "Extra time", "comp.has_shootout": "Penalty shootout", "comp.active": "Active",
    "comp.sanctions": "Sanctions", "comp.ends_play": "Sends the player off", "comp.counts_as_none": "Not counted for bans",
    "comp.counts_as_yellow": "Counts as a yellow card", "comp.counts_as_red": "Counts as a red card",
    "comp.format_summary": "{field} on the field, {bench} subs, {called} called up",
    "comp.sport_football": "Football", "comp.sport_futsal": "Futsal", "comp.sport_handball": "Handball",
    "comp.sport_basketball": "Basketball", "comp.sport_volleyball": "Volleyball", "comp.sport_other": "Other",
    "comp.scoring_goals": "Goals", "comp.scoring_points": "Points", "comp.scoring_sets": "Sets",
    "comp.period_half": "half", "comp.period_quarter": "quarter", "comp.period_set": "set", "comp.period_period": "period",
    "comp.sanction.yellow": "Yellow card", "comp.sanction.red": "Red card", "comp.sanction.two_minutes": "2-minute suspension",
    "comp.sanction.foul": "Foul", "comp.sanction.technical": "Technical foul", "comp.sanction.unsportsmanlike": "Unsportsmanlike foul",
    "comp.sanction.disqualifying": "Disqualifying foul", "comp.sanction.expulsion": "Expulsion", "comp.sanction.disqualification": "Disqualification",
    "flash.game_format_created": "Game format added.", "flash.game_format_updated": "Game format updated.",
    "flash.game_format_deleted": "Game format deleted.", "flash.game_format_in_use": "This format is used by a team, competition or match and cannot be deleted.",
    "activity.kind.game_formats": "Game formats"
  },
  "fr": {
    "comp.sport": "Sport", "comp.new_format": "Nouveau format", "comp.no_branch": "Aucune section liée", "comp.preset": "intégré",
    "comp.players_on_field": "Joueurs sur le terrain", "comp.max_bench": "Remplaçants max", "comp.max_called_up": "Convoqués max",
    "comp.scoring": "Score", "comp.periods": "Périodes", "comp.period_minutes": "Minutes par période", "comp.period_label": "Nom de la période",
    "comp.sets_to_win": "Sets gagnants", "comp.has_extra_time": "Prolongation", "comp.has_shootout": "Tirs au but", "comp.active": "Actif",
    "comp.sanctions": "Sanctions", "comp.ends_play": "Exclut le joueur", "comp.counts_as_none": "Non compté pour les suspensions",
    "comp.counts_as_yellow": "Compte comme carton jaune", "comp.counts_as_red": "Compte comme carton rouge",
    "comp.format_summary": "{field} sur le terrain, {bench} remplaçants, {called} convoqués",
    "comp.sport_football": "Football", "comp.sport_futsal": "Futsal", "comp.sport_handball": "Handball",
    "comp.sport_basketball": "Basketball", "comp.sport_volleyball": "Volley-ball", "comp.sport_other": "Autre",
    "comp.scoring_goals": "Buts", "comp.scoring_points": "Points", "comp.scoring_sets": "Sets",
    "comp.period_half": "mi-temps", "comp.period_quarter": "quart-temps", "comp.period_set": "set", "comp.period_period": "période",
    "comp.sanction.yellow": "Carton jaune", "comp.sanction.red": "Carton rouge", "comp.sanction.two_minutes": "Exclusion 2 minutes",
    "comp.sanction.foul": "Faute", "comp.sanction.technical": "Faute technique", "comp.sanction.unsportsmanlike": "Faute antisportive",
    "comp.sanction.disqualifying": "Faute disqualifiante", "comp.sanction.expulsion": "Expulsion", "comp.sanction.disqualification": "Disqualification",
    "flash.game_format_created": "Format de jeu ajouté.", "flash.game_format_updated": "Format de jeu modifié.",
    "flash.game_format_deleted": "Format de jeu supprimé.", "flash.game_format_in_use": "Ce format est utilisé par une équipe, une compétition ou un match et ne peut pas être supprimé.",
    "activity.kind.game_formats": "Formats de jeu"
  },
  "ar": {
    "comp.sport": "الرياضة", "comp.new_format": "نظام جديد", "comp.no_branch": "غير مرتبط بفرع", "comp.preset": "مدمج",
    "comp.players_on_field": "اللاعبون في الميدان", "comp.max_bench": "أقصى عدد للبدلاء", "comp.max_called_up": "أقصى عدد للمستدعين",
    "comp.scoring": "طريقة التسجيل", "comp.periods": "الفترات", "comp.period_minutes": "دقائق الفترة", "comp.period_label": "اسم الفترة",
    "comp.sets_to_win": "الأشواط اللازمة للفوز", "comp.has_extra_time": "وقت إضافي", "comp.has_shootout": "ركلات الترجيح", "comp.active": "مفعّل",
    "comp.sanctions": "العقوبات", "comp.ends_play": "يُخرج اللاعب", "comp.counts_as_none": "لا تُحتسب للإيقاف",
    "comp.counts_as_yellow": "تُحتسب بطاقة صفراء", "comp.counts_as_red": "تُحتسب بطاقة حمراء",
    "comp.format_summary": "{field} في الميدان، {bench} بدلاء، {called} مستدعى",
    "comp.sport_football": "كرة القدم", "comp.sport_futsal": "كرة القدم داخل القاعة", "comp.sport_handball": "كرة اليد",
    "comp.sport_basketball": "كرة السلة", "comp.sport_volleyball": "الكرة الطائرة", "comp.sport_other": "أخرى",
    "comp.scoring_goals": "أهداف", "comp.scoring_points": "نقاط", "comp.scoring_sets": "أشواط",
    "comp.period_half": "شوط", "comp.period_quarter": "ربع", "comp.period_set": "شوط", "comp.period_period": "فترة",
    "comp.sanction.yellow": "بطاقة صفراء", "comp.sanction.red": "بطاقة حمراء", "comp.sanction.two_minutes": "إقصاء دقيقتين",
    "comp.sanction.foul": "خطأ", "comp.sanction.technical": "خطأ فني", "comp.sanction.unsportsmanlike": "خطأ غير رياضي",
    "comp.sanction.disqualifying": "خطأ إقصائي", "comp.sanction.expulsion": "طرد", "comp.sanction.disqualification": "إقصاء",
    "flash.game_format_created": "تمت إضافة نظام اللعب.", "flash.game_format_updated": "تم تعديل نظام اللعب.",
    "flash.game_format_deleted": "تم حذف نظام اللعب.", "flash.game_format_in_use": "هذا النظام مستعمل من فريق أو منافسة أو مباراة ولا يمكن حذفه.",
    "activity.kind.game_formats": "أنظمة اللعب"
  }
}
EOF
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="GameFormatSettingsTest|Position|FlashTranslation|UsersBatch"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/GameFormatController.php app/Http/Controllers/PositionController.php resources/js/Pages/Settings/GameFormats.vue resources/js/Pages/Settings/Positions.vue routes/web.php config/activity.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/GameFormatSettingsTest.php
git commit -m "feat(competitions): game formats page; positions belong to a sport

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Teams, season rosters and coaches

**Files:**
- Modify: `app/Support/Season.php` (add `options()`)
- Create: `app/Services/Competitions/TeamRoster.php`, `app/Services/Competitions/PlayerFlags.php`
- Create: `app/Http/Controllers/TeamController.php`, `TeamRosterController.php`, `TeamCoachController.php`
- Create: `resources/js/Components/Competitions/SeasonSelect.vue`, `resources/js/Pages/Teams/Index.vue`, `resources/js/Pages/Teams/Show.vue`
- Modify: `app/Http/Controllers/CategoryController.php`, `app/Http/Controllers/BranchController.php` (block deleting a category or branch that has teams)
- Modify: `routes/web.php`
- Test: `tests/Feature/Competitions/TeamsTest.php`

**Interfaces:**
- Consumes: `TeamAccess` (Task 3), the models (Task 1), `InjurySpells::club(string $from, string $to): array{current: list<array{player_id:int,...}>}`.
- Produces: `Season::options(): list<array{value:int,label:string}>`; `TeamRoster::replace(Team, int $season, list<array{player_id:int, shirt_number:?int}>): void`; `TeamRoster::copyFromPreviousSeason(Team, int $season): int`; `PlayerFlags::injured(): array<int, true>`; routes `teams.{index,store,show,update,destroy}`, `teams.roster.update`, `teams.roster.copy`, `teams.coaches.update`; the shared component `SeasonSelect` (props `modelValue: Number`, `seasons: Array`; emits `update:modelValue`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/TeamsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Category;
use App\Models\Team;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function an_admin_creates_a_team_with_a_format_of_its_sport(): void
    {
        $admin = $this->admin();
        $football = $this->sportBranch();
        $handballFormat = $this->format($this->sportBranch('Handball'));
        $category = Category::firstOrCreate(['name' => 'U17']);
        $payload = ['branch_id' => $football->id, 'category_id' => $category->id, 'name' => 'U17 A'];

        $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $handballFormat->id])
            ->assertSessionHasErrors('default_game_format_id');

        $response = $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $this->format($football)->id]);
        $team = Team::sole();
        $response->assertRedirect(route('teams.show', $team))->assertSessionHas('success', 'flash.team_created');

        $this->actingAs($admin)->post(route('teams.store'), $payload + ['default_game_format_id' => $team->default_game_format_id])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_scoped_coach_cannot_create_teams_even_with_the_add_right(): void
    {
        $coach = $this->coach(['teams' => ['view', 'add', 'edit']]);
        $this->actingAs($coach)->post(route('teams.store'), [])->assertForbidden();
    }

    #[Test]
    public function the_team_page_shows_the_season_roster_and_candidates(): void
    {
        $team = $this->team();
        $season = Season::current()->startYear;
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->player($team->category, ['archived' => true]);
        $this->onRoster($team, $season, [$a]);

        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Teams/Show')
                ->where('season', $season)
                ->has('roster', 1)->where('roster.0.player_id', $a->id)->where('roster.0.shirt_number', 1)
                ->has('candidates', 1)->where('candidates.0.id', $b->id)
                ->where('canManage', true));
    }

    #[Test]
    public function the_roster_is_saved_and_shirt_numbers_can_be_swapped(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $admin = $this->admin();
        $save = fn (array $rows) => $this->actingAs($admin)->put(route('teams.roster.update', $team), ['season' => 2026, 'players' => $rows]);

        $save([['player_id' => $a->id, 'shirt_number' => 10], ['player_id' => $b->id, 'shirt_number' => 7]])
            ->assertSessionHas('success', 'flash.team_roster_saved');
        $save([['player_id' => $a->id, 'shirt_number' => 7], ['player_id' => $b->id, 'shirt_number' => 10]])->assertSessionHasNoErrors();
        $this->assertSame(7, $team->rosterFor(2026)->where('players.id', $a->id)->first()->pivot->shirt_number);

        $save([['player_id' => $a->id, 'shirt_number' => 7], ['player_id' => $b->id, 'shirt_number' => 7]])
            ->assertSessionHasErrors('players.1.shirt_number');

        $save([['player_id' => $b->id, 'shirt_number' => null]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id], $team->rosterFor(2026)->pluck('players.id')->all());
    }

    #[Test]
    public function last_seasons_roster_is_copied_without_archived_players(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2025, [$a, $b]);
        $b->update(['archived' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('teams.roster.copy', $team), ['season' => 2026])
            ->assertSessionHas('success', 'flash.team_roster_copied');
        $this->assertSame([$a->id], $team->rosterFor(2026)->pluck('players.id')->all());
        $this->assertSame(1, $team->rosterFor(2026)->first()->pivot->shirt_number);

        $this->actingAs($admin)->post(route('teams.roster.copy', $team), ['season' => 2026])
            ->assertSessionHas('error', 'flash.team_roster_not_empty');
    }

    #[Test]
    public function only_the_assigned_coach_edits_a_teams_roster_and_coaches(): void
    {
        $team = $this->team();
        $player = $this->player($team->category);
        $coach = $this->coach();
        $rows = ['season' => 2026, 'players' => [['player_id' => $player->id, 'shirt_number' => 9]]];

        $this->actingAs($coach)->put(route('teams.roster.update', $team), $rows)->assertForbidden();
        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->put(route('teams.roster.update', $team), $rows)->assertSessionHasNoErrors();

        $assistant = $this->coach();
        $this->actingAs($coach)->put(route('teams.coaches.update', $team), [
            'season' => 2026,
            'coaches' => [['user_id' => $coach->id, 'role' => 'head'], ['user_id' => $assistant->id, 'role' => 'assistant']],
        ])->assertSessionHas('success', 'flash.team_coaches_saved');
        $this->assertSame('assistant', $team->coachesFor(2026)->where('users.id', $assistant->id)->first()->pivot->role);
    }

    #[Test]
    public function a_team_with_matches_is_archived_instead_of_deleted(): void
    {
        $admin = $this->admin();
        $used = $this->team();
        $this->game($used);
        $unused = $this->team();

        $this->actingAs($admin)->delete(route('teams.destroy', $used))->assertSessionHas('success', 'flash.team_archived');
        $this->assertTrue($used->fresh()->archived);
        $this->actingAs($admin)->delete(route('teams.destroy', $unused))->assertSessionHas('success', 'flash.team_deleted');
        $this->assertModelMissing($unused);
    }

    #[Test]
    public function a_category_or_branch_with_teams_cannot_be_deleted(): void
    {
        $team = $this->team();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('categories.destroy', $team->category_id))->assertSessionHas('error', 'flash.category_has_teams');
        $this->actingAs($admin)->delete(route('branches.destroy', $team->branch_id))->assertSessionHas('error', 'flash.branch_has_teams');
        $this->assertModelExists($team->category);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=TeamsTest`
Expected: FAIL (`Route [teams.store] not defined`).

- [ ] **Step 3: Season options and the services**

In `app/Support/Season.php`, add after `label()`:

```php
    /** @return list<array{value: int, label: string}> what a season picker offers: next season, this one, two back. */
    public static function options(): array
    {
        $current = self::current()->startYear;

        return array_map(
            fn (int $year) => ['value' => $year, 'label' => self::forStartYear($year)->label()],
            range($current + 1, $current - 2),
        );
    }
```

`app/Services/Competitions/TeamRoster.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/** A team's roster for one season: who is on it and with which shirt number. */
final class TeamRoster
{
    /**
     * Makes the season's roster exactly $rows. Numbers are cleared first, so
     * swapping two players' numbers never trips the unique (team, season, number) index.
     *
     * @param  list<array{player_id: int, shirt_number: ?int}>  $rows
     */
    public function replace(Team $team, int $season, array $rows): void
    {
        DB::transaction(function () use ($team, $season, $rows) {
            $scope = fn () => DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season);
            $ids = array_map(fn (array $row) => (int) $row['player_id'], $rows);

            $scope()->whereNotIn('player_id', $ids)->delete();
            $scope()->update(['shirt_number' => null]);
            $existing = $scope()->pluck('player_id')->map(fn ($id) => (int) $id)->all();

            $now = now();
            foreach ($rows as $row) {
                $values = ['shirt_number' => $row['shirt_number'] ?? null, 'updated_at' => $now];
                if (in_array((int) $row['player_id'], $existing, true)) {
                    $scope()->where('player_id', $row['player_id'])->update($values);
                } else {
                    DB::table('team_players')->insert($values + [
                        'team_id' => $team->id, 'season_start_year' => $season,
                        'player_id' => $row['player_id'], 'created_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Copies last season's roster (and numbers) onto an empty $season, leaving out archived players and those who left. */
    public function copyFromPreviousSeason(Team $team, int $season): int
    {
        $rows = DB::table('team_players')
            ->where('team_id', $team->id)->where('season_start_year', $season - 1)
            ->whereIn('player_id', Player::query()->where('archived', false)->notLeft()->select('id'))
            ->get(['player_id', 'shirt_number'])
            ->map(fn ($row) => ['player_id' => (int) $row->player_id, 'shirt_number' => $row->shirt_number === null ? null : (int) $row->shirt_number])
            ->all();

        $this->replace($team, $season, $rows);

        return count($rows);
    }
}
```

`app/Services/Competitions/PlayerFlags.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Services\Attendance\InjurySpells;

/**
 * Warning badges shown when picking players. Warnings only — the coach always
 * decides. Phase 1 has "injured" (an injury spell still open today, from the
 * attendance marks); phase 3 adds suspended, licence, documents and unpaid.
 */
final class PlayerFlags
{
    public function __construct(private readonly InjurySpells $spells) {}

    /** @return array<int, true> ids of players injured today */
    public function injured(): array
    {
        $today = now()->toDateString();

        return array_fill_keys(array_column($this->spells->club($today, $today)['current'], 'player_id'), true);
    }
}
```

- [ ] **Step 4: Write the controllers**

`app/Http/Controllers/TeamController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'archived' => ['nullable', 'boolean']]);
        $season = $request->integer('season') ?: Season::current()->startYear;

        $teams = Team::query()
            ->with([
                'branch', 'category', 'defaultFormat',
                'coaches' => fn ($q) => $q->wherePivot('season_start_year', $season)->wherePivot('role', 'head'),
            ])
            ->withCount(['players as roster_count' => fn ($q) => $q->where('team_players.season_start_year', $season)])
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('archived', false))
            ->orderBy('name')->get();

        return Inertia::render('Teams/Index', [
            'teams' => $teams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'archived' => $team->archived,
                'branch' => $team->branch?->localized_name,
                'category' => $team->category?->localized_name,
                'format' => $team->defaultFormat?->localized_name,
                'roster_count' => $team->roster_count,
                'head_coaches' => $team->coaches->map(fn (User $u) => $u->fullname)->values(),
            ]),
            'branches' => Branch::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'formats' => GameFormat::query()->where('active', true)->whereNotNull('branch_id')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'season' => $season,
            'seasons' => Season::options(),
            'showArchived' => $request->boolean('archived'),
            'canCreate' => ! TeamAccess::isScoped($request->user()) && $request->user()->hasPermission('teams', 'add'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(TeamAccess::isScoped($request->user()), 403);
        $team = Team::create($this->validated($request));

        return redirect()->route('teams.show', $team)->with('success', 'flash.team_created');
    }

    public function show(Request $request, Team $team, PlayerFlags $flags): Response
    {
        $request->validate(['season' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $season = $request->integer('season') ?: Season::current()->startYear;
        $team->load(['branch', 'category', 'defaultFormat']);
        $injured = $flags->injured();

        $roster = $team->rosterFor($season)->with('category')->get()
            ->map(fn (Player $p) => [
                'player_id' => $p->id,
                'name' => $p->fullname,
                'category' => $p->category?->localized_name,
                'shirt_number' => $p->pivot->shirt_number,
                'injured' => isset($injured[$p->id]),
            ])->sortBy('name')->values();

        $candidates = Player::query()->where('archived', false)->notLeft()
            ->whereNotIn('id', $roster->pluck('player_id'))
            ->with('branches:id')
            ->get(['id', 'category_id', ...Player::NAME_COLUMNS])
            ->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => $p->fullname,
                'category_id' => $p->category_id,
                'in_sport' => $p->branches->contains('id', $team->branch_id),
            ])->sortBy('name')->values();

        return Inertia::render('Teams/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'archived' => $team->archived,
                'branch' => $team->branch?->localized_name,
                'category_id' => $team->category_id,
                'category' => $team->category?->localized_name,
                'default_game_format_id' => $team->default_game_format_id,
                'format' => $team->defaultFormat?->localized_name,
            ],
            'season' => $season,
            'seasons' => Season::options(),
            'roster' => $roster,
            'candidates' => $candidates,
            'previousRosterCount' => DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season - 1)->count(),
            'coaches' => $team->coachesFor($season)->get()->map(fn (User $u) => ['user_id' => $u->id, 'role' => $u->pivot->role])->values(),
            'users' => User::query()->where('is_active', true)->get()
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->fullname])->sortBy('label')->values(),
            'competitions' => $team->competitions()->where('season_start_year', $season)->orderBy('name')->get(['id', 'name', 'type', 'archived']),
            'games' => $team->games()->where('season_start_year', $season)->with('opponent:id,name')->orderBy('date')->get()
                ->map(fn (Game $g) => [
                    'id' => $g->id, 'date' => $g->date, 'opponent' => $g->opponent->name, 'side' => $g->side->value,
                    'status' => $g->status->value, 'our_score' => $g->our_score, 'their_score' => $g->their_score,
                ]),
            'formats' => GameFormat::query()->where('branch_id', $team->branch_id)->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categories' => Category::query()->orderBy('name')->get(),
            'canManage' => TeamAccess::canManage($request->user(), $team, $season),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $team, Season::current()->startYear);
        $team->update($this->validated($request, $team));

        return back()->with('success', 'flash.team_updated');
    }

    /** A team with competitions or matches keeps its history: it is archived, not deleted. */
    public function destroy(Team $team): RedirectResponse
    {
        if ($team->games()->exists() || $team->competitions()->exists()) {
            $team->update(['archived' => true]);

            return redirect()->route('teams.index')->with('success', 'flash.team_archived');
        }
        $team->delete();

        return redirect()->route('teams.index')->with('success', 'flash.team_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Team $team = null): array
    {
        $rules = [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'default_game_format_id' => ['required', 'integer', 'exists:game_formats,id'],
            'archived' => ['sometimes', 'boolean'],
        ];
        if ($team === null) {
            $rules['branch_id'] = ['required', 'integer', 'exists:branches,id'];
        }
        $data = $request->validate($rules);
        $branchId = $team?->branch_id ?? (int) $data['branch_id'];

        if ((int) GameFormat::whereKey($data['default_game_format_id'])->value('branch_id') !== $branchId) {
            throw ValidationException::withMessages(['default_game_format_id' => 'comp.error.format_other_sport']);
        }
        $duplicate = Team::query()->where('branch_id', $branchId)->where('category_id', $data['category_id'])
            ->where('name', $data['name'])->when($team, fn ($q) => $q->whereKeyNot($team->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'comp.error.team_exists']);
        }

        return $data;
    }
}
```

`app/Http/Controllers/TeamRosterController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Competitions\TeamAccess;
use App\Services\Competitions\TeamRoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamRosterController extends Controller
{
    public function update(Request $request, Team $team, TeamRoster $roster): RedirectResponse
    {
        $data = $request->validate([
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'players.*.shirt_number' => ['nullable', 'integer', 'min:0', 'max:99', 'distinct'],
        ]);
        TeamAccess::authorize($request->user(), $team, (int) $data['season']);

        $roster->replace($team, (int) $data['season'], $data['players']);

        return back()->with('success', 'flash.team_roster_saved');
    }

    public function copy(Request $request, Team $team, TeamRoster $roster): RedirectResponse
    {
        $season = (int) $request->validate(['season' => ['required', 'integer', 'min:2000', 'max:2100']])['season'];
        TeamAccess::authorize($request->user(), $team, $season);

        if (DB::table('team_players')->where('team_id', $team->id)->where('season_start_year', $season)->exists()) {
            return back()->with('error', 'flash.team_roster_not_empty');
        }
        $roster->copyFromPreviousSeason($team, $season);

        return back()->with('success', 'flash.team_roster_copied');
    }
}
```

`app/Http/Controllers/TeamCoachController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\CoachRole;
use App\Models\Team;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamCoachController extends Controller
{
    /** Replaces the team's coaches for one season. */
    public function update(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate([
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
            'coaches' => ['present', 'array'],
            'coaches.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'coaches.*.role' => ['required', Rule::enum(CoachRole::class)],
        ]);
        $season = (int) $data['season'];
        TeamAccess::authorize($request->user(), $team, $season);

        DB::transaction(function () use ($team, $season, $data) {
            DB::table('team_coaches')->where('team_id', $team->id)->where('season_start_year', $season)->delete();
            $now = now();
            DB::table('team_coaches')->insert(array_map(fn (array $c) => [
                'team_id' => $team->id, 'season_start_year' => $season, 'user_id' => $c['user_id'],
                'role' => $c['role'], 'created_at' => $now, 'updated_at' => $now,
            ], $data['coaches']));
        });

        return back()->with('success', 'flash.team_coaches_saved');
    }
}
```

- [ ] **Step 5: Guard category and branch deletion**

In `app/Http/Controllers/CategoryController.php` → `destroy()`, add this as the first statement (and `use App\Models\Team;`):

```php
        // A team's age category cannot vanish under its competitions and matches.
        if (Team::where('category_id', $category->id)->exists()) {
            return back()->with('error', 'flash.category_has_teams');
        }
```

In `app/Http/Controllers/BranchController.php` → `destroy()`, add this as the first statement (and `use App\Models\Team;`):

```php
        if (Team::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_teams');
        }
```

- [ ] **Step 6: Routes**

In `routes/web.php`, add `use App\Http\Controllers\TeamController;`, `use App\Http\Controllers\TeamCoachController;` and `use App\Http\Controllers\TeamRosterController;`. Directly after the line containing `->name('attendance.preseason-targets.store');` add:

```php
        // Competitions & matches. Coaches are limited to their own teams in the controllers (TeamAccess).
        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::put('/teams/{team}/roster', [TeamRosterController::class, 'update'])->name('teams.roster.update');
        Route::post('/teams/{team}/roster/copy', [TeamRosterController::class, 'copy'])->name('teams.roster.copy');
        Route::put('/teams/{team}/coaches', [TeamCoachController::class, 'update'])->name('teams.coaches.update');
```

- [ ] **Step 7: Write the pages**

`resources/js/Components/Competitions/SeasonSelect.vue`:

```vue
<script setup>
defineProps({ modelValue: Number, seasons: Array });
defineEmits(['update:modelValue']);
</script>

<template>
    <select :value="modelValue" @change="$emit('update:modelValue', Number($event.target.value))"
        class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ s.label }}</option>
    </select>
</template>
```

`resources/js/Pages/Teams/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    teams: Array, branches: Array, categories: Array, formats: Array,
    season: Number, seasons: Array, showArchived: Boolean, canCreate: Boolean,
});

const creating = ref(false);
const form = useForm({ branch_id: '', category_id: '', name: '', default_game_format_id: '' });
const formatsOfBranch = computed(() => props.formats.filter((f) => f.branch_id === Number(form.branch_id)));

function reload(params) {
    router.get(route('teams.index'), { season: props.season, archived: props.showArchived ? 1 : 0, ...params }, { preserveState: true });
}

function create() {
    form.post(route('teams.store'));
}
</script>

<template>
    <Head :title="t('teams')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('teams') }}</h1>
                <div class="flex items-center gap-3">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event })" />
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" :checked="showArchived" class="rounded" @change="reload({ archived: $event.target.checked ? 1 : 0 })" />
                        {{ t('comp.show_archived') }}
                    </label>
                    <PrimaryButton v-if="canCreate" @click="creating = !creating">{{ t('comp.new_team') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-5">
                <label class="text-sm">{{ t('comp.sport') }}
                    <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required @change="form.default_game_format_id = ''">
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('category') }}
                    <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('name') }}
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('comp.team_name_hint')" required />
                    <InputError :message="form.errors.name ? t(form.errors.name) : ''" class="mt-1" />
                </label>
                <label class="text-sm">{{ t('comp.game_format') }}
                    <select v-model="form.default_game_format_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                        <option v-for="f in formatsOfBranch" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                    </select>
                    <InputError :message="form.errors.default_game_format_id ? t(form.errors.default_game_format_id) : ''" class="mt-1" />
                    <p v-if="form.branch_id && !formatsOfBranch.length" class="mt-1 text-xs text-amber-600">{{ t('comp.no_format_for_sport') }}</p>
                </label>
                <div class="flex items-end gap-2">
                    <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="team in teams" :key="team.id" :href="route('teams.show', { team: team.id, season })"
                    class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-400 dark:ring-slate-800"
                    :class="{ 'opacity-60': team.archived }">
                    <div class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ team.name }}</div>
                    <div class="text-sm text-slate-500 dark:text-slate-400">{{ team.branch }} · {{ team.category }} · {{ team.format }}</div>
                    <div class="mt-3 flex items-center justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-300">{{ t('comp.players_count', { n: team.roster_count }) }}</span>
                        <span class="truncate text-slate-500 dark:text-slate-400">{{ team.head_coaches.join(', ') || t('comp.no_coach') }}</span>
                    </div>
                </Link>
                <p v-if="!teams.length" class="col-span-full py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

`resources/js/Pages/Teams/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import IconButton from '@/Components/IconButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    team: Object, season: Number, seasons: Array, roster: Array, candidates: Array, previousRosterCount: Number,
    coaches: Array, users: Array, competitions: Array, games: Array, formats: Array, categories: Array, canManage: Boolean,
});

const tab = ref('roster');
const confirmDelete = ref(false);

// Roster editing works on a local copy; nothing is saved until "Save".
const rows = ref(props.roster.map((r) => ({ ...r })));
const rosterForm = useForm({ season: props.season, players: [] });
const pick = ref('');
const onlyCategory = ref(true);
const onlySport = ref(true);
const pickable = computed(() => props.candidates
    .filter((c) => !rows.value.some((r) => r.player_id === c.id))
    .filter((c) => !onlyCategory.value || c.category_id === props.team.category_id)
    .filter((c) => !onlySport.value || c.in_sport)
    .map((c) => ({ value: c.id, label: c.name })));

function addPlayer(id) {
    const c = props.candidates.find((x) => x.id === Number(id));
    if (c) rows.value.push({ player_id: c.id, name: c.name, category: null, shirt_number: null, injured: false });
    pick.value = '';
}

function saveRoster() {
    rosterForm.players = rows.value.map((r) => ({ player_id: r.player_id, shirt_number: r.shirt_number === '' ? null : r.shirt_number }));
    rosterForm.put(route('teams.roster.update', props.team.id), { preserveScroll: true });
}

function copyRoster() {
    router.post(route('teams.roster.copy', props.team.id), { season: props.season }, {
        preserveScroll: true,
        onSuccess: () => { rows.value = props.roster.map((r) => ({ ...r })); },
    });
}

const coachRows = ref(props.coaches.map((c) => ({ ...c })));
const coachForm = useForm({ season: props.season, coaches: [] });
function saveCoaches() {
    coachForm.coaches = coachRows.value.filter((c) => c.user_id);
    coachForm.put(route('teams.coaches.update', props.team.id), { preserveScroll: true });
}

const teamForm = useForm({
    name: props.team.name, category_id: props.team.category_id,
    default_game_format_id: props.team.default_game_format_id, archived: props.team.archived,
});
function saveTeam() {
    teamForm.put(route('teams.update', props.team.id), { preserveScroll: true });
}

function changeSeason(season) {
    router.get(route('teams.show', props.team.id), { season });
}

function destroyTeam() {
    router.delete(route('teams.destroy', props.team.id));
}

const tabs = ['roster', 'coaches', 'competitions', 'games', 'settings'];
</script>

<template>
    <Head :title="team.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('teams.index', { season })" class="text-sm text-primary-600 hover:underline">{{ t('teams') }}</Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ team.name }}</h1>
                    <div class="text-sm text-slate-500 dark:text-slate-400">{{ team.branch }} · {{ team.category }} · {{ team.format }}</div>
                </div>
                <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="changeSeason" />
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in tabs" :key="key" type="button" @click="tab = key"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <!-- Roster -->
            <section v-if="tab === 'roster'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div v-if="canManage && !rows.length && previousRosterCount" class="flex items-center justify-between rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                    <span>{{ t('comp.copy_roster_hint', { n: previousRosterCount }) }}</span>
                    <SecondaryButton @click="copyRoster">{{ t('comp.copy_roster') }}</SecondaryButton>
                </div>

                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800 text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500 dark:text-slate-400">
                            <th class="w-20 py-2 text-start">{{ t('comp.shirt') }}</th>
                            <th class="py-2 text-start">{{ t('comp.player') }}</th>
                            <th class="py-2 text-start">{{ t('category') }}</th>
                            <th class="w-12"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(row, i) in rows" :key="row.player_id">
                            <td class="py-2">
                                <TextInput v-if="canManage" v-model.number="row.shirt_number" type="number" min="0" max="99" class="w-16" />
                                <span v-else class="font-mono">{{ row.shirt_number }}</span>
                                <InputError :message="rosterForm.errors[`players.${i}.shirt_number`]" />
                            </td>
                            <td class="py-2 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.injured" class="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
                            </td>
                            <td class="py-2 text-slate-500 dark:text-slate-400">{{ row.category }}</td>
                            <td class="py-2 text-end">
                                <IconButton v-if="canManage" icon="trash" :label="t('comp.remove')" variant="danger" plain size="sm" @click="rows.splice(i, 1)" />
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="4" class="py-6 text-center text-slate-500">{{ t('comp.roster_empty') }}</td></tr>
                    </tbody>
                </table>

                <div v-if="canManage" class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <div class="min-w-64 flex-1">
                        <label class="text-sm font-medium">{{ t('comp.add_player') }}</label>
                        <SearchableSelect v-model="pick" :options="pickable" :placeholder="t('comp.search_player')" class="mt-1" @update:model-value="addPlayer" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="onlyCategory" type="checkbox" class="rounded" /> {{ t('comp.only_team_category') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="onlySport" type="checkbox" class="rounded" /> {{ t('comp.only_this_sport') }}</label>
                    <PrimaryButton :disabled="rosterForm.processing" @click="saveRoster">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Coaches -->
            <section v-if="tab === 'coaches'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div v-for="(c, i) in coachRows" :key="i" class="flex flex-wrap items-center gap-3">
                    <SearchableSelect v-model="c.user_id" :options="users" :disabled="!canManage" class="min-w-64 flex-1" />
                    <select v-model="c.role" :disabled="!canManage" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="head">{{ t('comp.coach_head') }}</option>
                        <option value="assistant">{{ t('comp.coach_assistant') }}</option>
                    </select>
                    <IconButton v-if="canManage" icon="trash" :label="t('comp.remove')" variant="danger" plain size="sm" @click="coachRows.splice(i, 1)" />
                </div>
                <p v-if="!coachRows.length" class="text-sm text-slate-500">{{ t('comp.no_coach') }}</p>
                <InputError :message="coachForm.errors.coaches" />
                <div v-if="canManage" class="flex gap-2">
                    <SecondaryButton @click="coachRows.push({ user_id: '', role: 'head' })">{{ t('add') }}</SecondaryButton>
                    <PrimaryButton :disabled="coachForm.processing" @click="saveCoaches">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Competitions -->
            <section v-if="tab === 'competitions'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="c in competitions" :key="c.id" :href="route('competitions.show', c.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span class="font-medium">{{ c.name }}</span>
                    <span class="text-sm text-slate-500">{{ t(`comp.type_${c.type}`) }}<span v-if="c.archived"> · {{ t('archived') }}</span></span>
                </Link>
                <p v-if="!competitions.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link :href="route('competitions.index', { team_id: team.id, season })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('competitions') }} →</Link>
            </section>

            <!-- Matches -->
            <section v-if="tab === 'games'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="g in games" :key="g.id" :href="route('games.show', g.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span><bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi> · {{ g.opponent }} ({{ t(`comp.side_${g.side}`) }})</span>
                    <span class="text-sm text-slate-500">
                        <template v-if="g.status === 'played'"><bdi dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi></template>
                        <template v-else>{{ t(`comp.status_${g.status}`) }}</template>
                    </span>
                </Link>
                <p v-if="!games.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link :href="route('games.index', { team_id: team.id })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('games') }} →</Link>
            </section>

            <!-- Settings -->
            <section v-if="tab === 'settings'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('name') }}
                        <TextInput v-model="teamForm.name" :disabled="!canManage" class="mt-1 w-full" />
                        <InputError :message="teamForm.errors.name ? t(teamForm.errors.name) : ''" />
                    </label>
                    <label class="text-sm">{{ t('category') }}
                        <select v-model="teamForm.category_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.game_format') }}
                        <select v-model="teamForm.default_game_format_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="f in formats" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                        </select>
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="teamForm.archived" :disabled="!canManage" type="checkbox" class="rounded" /> {{ t('archived') }}</label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canManage" :disabled="teamForm.processing" @click="saveTeam">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="can('teams', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('comp.delete_team_confirm')" @confirm="destroyTeam" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.show_archived": "Show archived", "comp.new_team": "New team", "comp.team_name_hint": "e.g. U17 A",
    "comp.game_format": "Game format", "comp.no_format_for_sport": "No game format is linked to this sport yet — link one in Game formats.",
    "comp.players_count": "{n} players", "comp.no_coach": "No coach assigned",
    "comp.tab_roster": "Roster", "comp.tab_coaches": "Coaches", "comp.tab_competitions": "Competitions", "comp.tab_games": "Matches", "comp.tab_settings": "Team",
    "comp.copy_roster_hint": "This season's roster is empty. Last season had {n} players.", "comp.copy_roster": "Copy last season",
    "comp.shirt": "No.", "comp.player": "Player", "comp.badge_injured": "Injured", "comp.remove": "Remove",
    "comp.roster_empty": "No players on the roster for this season.", "comp.add_player": "Add a player", "comp.search_player": "Search a player…",
    "comp.only_team_category": "Only the team's category", "comp.only_this_sport": "Only players of this sport",
    "comp.coach_head": "Head coach", "comp.coach_assistant": "Assistant",
    "comp.type_league": "League", "comp.type_cup": "Cup", "comp.type_tournament": "Tournament", "comp.type_friendly": "Friendly",
    "comp.side_home": "home", "comp.side_away": "away", "comp.side_neutral": "neutral ground",
    "comp.status_scheduled": "Scheduled", "comp.status_played": "Played", "comp.status_postponed": "Postponed", "comp.status_cancelled": "Cancelled",
    "comp.delete_team_confirm": "Delete this team? A team with competitions or matches is archived instead.",
    "comp.error.format_other_sport": "This game format belongs to another sport.", "comp.error.team_exists": "This sport and category already have a team with this name.",
    "flash.team_created": "Team created.", "flash.team_updated": "Team updated.", "flash.team_deleted": "Team deleted.",
    "flash.team_archived": "The team has competitions or matches, so it was archived.",
    "flash.team_roster_saved": "Roster saved.", "flash.team_roster_copied": "Last season's roster copied.",
    "flash.team_roster_not_empty": "This season's roster is not empty.", "flash.team_coaches_saved": "Coaches saved.",
    "flash.category_has_teams": "This category has teams and cannot be deleted.", "flash.branch_has_teams": "This branch has teams and cannot be deleted."
  },
  "fr": {
    "comp.show_archived": "Afficher les archives", "comp.new_team": "Nouvelle équipe", "comp.team_name_hint": "ex. U17 A",
    "comp.game_format": "Format de jeu", "comp.no_format_for_sport": "Aucun format de jeu n'est lié à ce sport — liez-en un dans Formats de jeu.",
    "comp.players_count": "{n} joueurs", "comp.no_coach": "Aucun entraîneur",
    "comp.tab_roster": "Effectif", "comp.tab_coaches": "Entraîneurs", "comp.tab_competitions": "Compétitions", "comp.tab_games": "Matchs", "comp.tab_settings": "Équipe",
    "comp.copy_roster_hint": "L'effectif de cette saison est vide. La saison passée comptait {n} joueurs.", "comp.copy_roster": "Copier la saison passée",
    "comp.shirt": "N°", "comp.player": "Joueur", "comp.badge_injured": "Blessé", "comp.remove": "Retirer",
    "comp.roster_empty": "Aucun joueur dans l'effectif cette saison.", "comp.add_player": "Ajouter un joueur", "comp.search_player": "Chercher un joueur…",
    "comp.only_team_category": "Seulement la catégorie de l'équipe", "comp.only_this_sport": "Seulement les joueurs de ce sport",
    "comp.coach_head": "Entraîneur principal", "comp.coach_assistant": "Adjoint",
    "comp.type_league": "Championnat", "comp.type_cup": "Coupe", "comp.type_tournament": "Tournoi", "comp.type_friendly": "Amical",
    "comp.side_home": "domicile", "comp.side_away": "extérieur", "comp.side_neutral": "terrain neutre",
    "comp.status_scheduled": "Programmé", "comp.status_played": "Joué", "comp.status_postponed": "Reporté", "comp.status_cancelled": "Annulé",
    "comp.delete_team_confirm": "Supprimer cette équipe ? Une équipe avec des compétitions ou des matchs est archivée à la place.",
    "comp.error.format_other_sport": "Ce format de jeu appartient à un autre sport.", "comp.error.team_exists": "Ce sport et cette catégorie ont déjà une équipe de ce nom.",
    "flash.team_created": "Équipe créée.", "flash.team_updated": "Équipe modifiée.", "flash.team_deleted": "Équipe supprimée.",
    "flash.team_archived": "L'équipe a des compétitions ou des matchs : elle a été archivée.",
    "flash.team_roster_saved": "Effectif enregistré.", "flash.team_roster_copied": "Effectif de la saison passée copié.",
    "flash.team_roster_not_empty": "L'effectif de cette saison n'est pas vide.", "flash.team_coaches_saved": "Entraîneurs enregistrés.",
    "flash.category_has_teams": "Cette catégorie a des équipes et ne peut pas être supprimée.", "flash.branch_has_teams": "Cette section a des équipes et ne peut pas être supprimée."
  },
  "ar": {
    "comp.show_archived": "إظهار المؤرشف", "comp.new_team": "فريق جديد", "comp.team_name_hint": "مثال: أواسط أ",
    "comp.game_format": "نظام اللعب", "comp.no_format_for_sport": "لا يوجد نظام لعب مرتبط بهذه الرياضة — اربطه من أنظمة اللعب.",
    "comp.players_count": "{n} لاعب", "comp.no_coach": "لا يوجد مدرب",
    "comp.tab_roster": "التشكيلة", "comp.tab_coaches": "المدربون", "comp.tab_competitions": "المنافسات", "comp.tab_games": "المباريات", "comp.tab_settings": "الفريق",
    "comp.copy_roster_hint": "تشكيلة هذا الموسم فارغة. كان في الموسم الماضي {n} لاعب.", "comp.copy_roster": "نسخ الموسم الماضي",
    "comp.shirt": "الرقم", "comp.player": "اللاعب", "comp.badge_injured": "مصاب", "comp.remove": "إزالة",
    "comp.roster_empty": "لا يوجد لاعبون في تشكيلة هذا الموسم.", "comp.add_player": "إضافة لاعب", "comp.search_player": "ابحث عن لاعب…",
    "comp.only_team_category": "فئة الفريق فقط", "comp.only_this_sport": "لاعبو هذه الرياضة فقط",
    "comp.coach_head": "المدرب الرئيسي", "comp.coach_assistant": "مساعد",
    "comp.type_league": "بطولة", "comp.type_cup": "كأس", "comp.type_tournament": "دورة", "comp.type_friendly": "ودية",
    "comp.side_home": "داخل الديار", "comp.side_away": "خارج الديار", "comp.side_neutral": "ملعب محايد",
    "comp.status_scheduled": "مبرمجة", "comp.status_played": "لُعبت", "comp.status_postponed": "مؤجلة", "comp.status_cancelled": "ملغاة",
    "comp.delete_team_confirm": "حذف هذا الفريق؟ الفريق الذي له منافسات أو مباريات يُؤرشف بدلاً من ذلك.",
    "comp.error.format_other_sport": "نظام اللعب هذا تابع لرياضة أخرى.", "comp.error.team_exists": "يوجد فريق بهذا الاسم في هذه الرياضة والفئة.",
    "flash.team_created": "تم إنشاء الفريق.", "flash.team_updated": "تم تعديل الفريق.", "flash.team_deleted": "تم حذف الفريق.",
    "flash.team_archived": "للفريق منافسات أو مباريات، لذلك تمت أرشفته.",
    "flash.team_roster_saved": "تم حفظ التشكيلة.", "flash.team_roster_copied": "تم نسخ تشكيلة الموسم الماضي.",
    "flash.team_roster_not_empty": "تشكيلة هذا الموسم ليست فارغة.", "flash.team_coaches_saved": "تم حفظ المدربين.",
    "flash.category_has_teams": "لهذه الفئة فرق ولا يمكن حذفها.", "flash.branch_has_teams": "لهذا الفرع فرق ولا يمكن حذفه."
  }
}
EOF
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter="TeamsTest|CategoryDeletion|BranchFeature|FlashTranslation"`
Expected: PASS. (`Teams/Show.vue` links to `competitions.*` and `games.*` routes, but those only resolve in the browser, and the test doesn't render Vue. Click-through is checked in Task 10.)

- [ ] **Step 10: Commit**

```bash
git add app/Support/Season.php app/Services/Competitions/TeamRoster.php app/Services/Competitions/PlayerFlags.php app/Http/Controllers/TeamController.php app/Http/Controllers/TeamRosterController.php app/Http/Controllers/TeamCoachController.php app/Http/Controllers/CategoryController.php app/Http/Controllers/BranchController.php routes/web.php resources/js/Components/Competitions/SeasonSelect.vue resources/js/Pages/Teams/Index.vue resources/js/Pages/Teams/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/TeamsTest.php
git commit -m "feat(competitions): teams with season rosters, shirt numbers and coaches

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Competitions and squads

**Files:**
- Create: `app/Services/Competitions/CompetitionRecord.php`
- Create: `app/Http/Controllers/CompetitionController.php`, `app/Http/Controllers/CompetitionSquadController.php`
- Create: `resources/js/Pages/Competitions/Index.vue`, `resources/js/Pages/Competitions/Show.vue`
- Modify: `routes/web.php`
- Test: `tests/Unit/CompetitionRecordTest.php`, `tests/Feature/Competitions/CompetitionsTest.php`

**Interfaces:**
- Consumes: `TeamAccess`, `Season::options()`, `SeasonSelect`.
- Produces: `CompetitionRecord::fromGames(iterable<Game>): array{played:int, wins:int, draws:int, losses:int, goals_for:int, goals_against:int, form:list<'W'|'D'|'L'>}`; routes `competitions.{index,store,show,update,destroy}` and `competitions.squad.update`.

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/CompetitionRecordTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\Competitions\CompetitionRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompetitionRecordTest extends TestCase
{
    private function game(string $date, ?int $us, ?int $them, GameStatus $status = GameStatus::Played): Game
    {
        return (new Game)->forceFill(['date' => $date, 'our_score' => $us, 'their_score' => $them, 'status' => $status]);
    }

    #[Test]
    public function it_counts_played_games_only_and_keeps_the_last_five_results_in_date_order(): void
    {
        $record = CompetitionRecord::fromGames([
            $this->game('2026-10-10', 2, 1),
            $this->game('2026-09-01', 0, 0),
            $this->game('2026-09-08', 1, 3),
            $this->game('2026-09-15', 4, 0),
            $this->game('2026-09-22', 1, 1),
            $this->game('2026-09-29', 2, 0),
            $this->game('2026-10-17', null, null, GameStatus::Scheduled),
        ]);

        $this->assertSame(6, $record['played']);
        $this->assertSame([3, 2, 1], [$record['wins'], $record['draws'], $record['losses']]);
        $this->assertSame([10, 5], [$record['goals_for'], $record['goals_against']]);
        $this->assertSame(['L', 'W', 'D', 'W', 'W'], $record['form']);
    }
}
```

- [ ] **Step 2: Write the failing feature test**

`tests/Feature/Competitions/CompetitionsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_competition_is_created_for_a_team_season_with_an_optional_format_of_its_sport(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        $payload = ['team_id' => $team->id, 'season_start_year' => 2026, 'name' => 'Regional league', 'type' => 'league'];

        $this->actingAs($admin)->post(route('competitions.store'), $payload + ['game_format_id' => $this->format($this->sportBranch('Handball'))->id])
            ->assertSessionHasErrors('game_format_id');

        $response = $this->actingAs($admin)->post(route('competitions.store'), $payload);
        $competition = Competition::sole();
        $response->assertRedirect(route('competitions.show', $competition))->assertSessionHas('success', 'flash.competition_created');
        $this->assertNull($competition->game_format_id);
    }

    #[Test]
    public function a_coach_creates_competitions_only_for_teams_coached_that_season(): void
    {
        $team = $this->team();
        $coach = $this->coach();
        $payload = ['team_id' => $team->id, 'season_start_year' => 2026, 'name' => 'Cup', 'type' => 'cup'];

        $this->actingAs($coach)->post(route('competitions.store'), $payload)->assertForbidden();
        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->post(route('competitions.store'), $payload)->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_squad_takes_roster_players_only_and_hides_removed_players_who_already_played(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $competition = $this->competition($team, 2026);

        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id, $outsider->id]])
            ->assertSessionHasErrors('player_ids.1');
        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id, $b->id, $c->id]])
            ->assertSessionHas('success', 'flash.squad_saved');

        $played = $this->game($team, ['competition_id' => $competition->id, 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        GamePlayer::create(['game_id' => $played->id, 'player_id' => $b->id, 'called_up' => true]);

        $this->actingAs($admin)->put(route('competitions.squad.update', $competition), ['player_ids' => [$a->id]])->assertSessionHasNoErrors();

        $rows = $competition->players()->get()->mapWithKeys(fn ($p) => [$p->id => (bool) $p->pivot->hidden])->all();
        $this->assertEquals([$a->id => false, $b->id => true], $rows); // order-insensitive
    }

    #[Test]
    public function the_page_shows_the_squad_and_the_record(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $competition = $this->competition($team, 2026);
        $competition->players()->attach($a->id);
        $this->game($team, ['competition_id' => $competition->id, 'status' => GameStatus::Played, 'our_score' => 3, 'their_score' => 1]);

        $this->actingAs($this->admin())->get(route('competitions.show', $competition))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Competitions/Show')
                ->where('roster.0.in_squad', true)
                ->where('record.wins', 1)->where('record.goals_for', 3)
                ->has('games', 1));
    }

    #[Test]
    public function a_competition_with_matches_is_archived_instead_of_deleted(): void
    {
        $admin = $this->admin();
        $team = $this->team();
        $used = $this->competition($team, 2026);
        $this->game($team, ['competition_id' => $used->id]);
        $unused = $this->competition($team, 2026, ['name' => 'Cup']);

        $this->actingAs($admin)->delete(route('competitions.destroy', $used))->assertSessionHas('success', 'flash.competition_archived');
        $this->assertTrue($used->fresh()->archived);
        $this->actingAs($admin)->delete(route('competitions.destroy', $unused))->assertSessionHas('success', 'flash.competition_deleted');
        $this->assertModelMissing($unused);
    }
}
```

- [ ] **Step 3: Run them to make sure they fail**

Run: `php artisan test --filter="CompetitionRecordTest|CompetitionsTest"`
Expected: FAIL (`Class "App\Services\Competitions\CompetitionRecord" not found`, `Route [competitions.store] not defined`).

- [ ] **Step 4: Write CompetitionRecord**

`app/Services/Competitions/CompetitionRecord.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;

/** Our own record in a set of matches: no league table, just W/D/L, goals and recent form. */
final class CompetitionRecord
{
    /**
     * @param  iterable<Game>  $games
     * @return array{played: int, wins: int, draws: int, losses: int, goals_for: int, goals_against: int, form: list<string>}
     */
    public static function fromGames(iterable $games): array
    {
        $record = ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'form' => []];
        $results = [];

        foreach ($games as $game) {
            if ($game->status !== GameStatus::Played || $game->our_score === null || $game->their_score === null) {
                continue;
            }
            $outcome = $game->our_score <=> $game->their_score;
            $record['played']++;
            $record['goals_for'] += $game->our_score;
            $record['goals_against'] += $game->their_score;
            $record[match ($outcome) { 1 => 'wins', 0 => 'draws', -1 => 'losses' }]++;
            $results[] = [$game->date, match ($outcome) { 1 => 'W', 0 => 'D', -1 => 'L' }];
        }

        usort($results, fn (array $a, array $b) => strcmp($a[0], $b[0]));
        $record['form'] = array_column(array_slice($results, -5), 1);

        return $record;
    }
}
```

- [ ] **Step 5: Write the controllers**

`app/Http/Controllers/CompetitionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameFormat;
use App\Models\Player;
use App\Models\Team;
use App\Services\Competitions\CompetitionRecord;
use App\Services\Competitions\TeamAccess;
use App\Support\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompetitionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'team_id' => ['nullable', 'integer'],
            'archived' => ['nullable', 'boolean'],
        ]);
        $season = $request->integer('season') ?: Season::current()->startYear;

        $competitions = Competition::query()
            ->with(['team.category', 'games:id,competition_id,date,status,our_score,their_score'])
            ->where('season_start_year', $season)
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('team_id', $id))
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('archived', false))
            ->orderBy('name')->get();

        return Inertia::render('Competitions/Index', [
            'competitions' => $competitions->map(fn (Competition $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type->value,
                'archived' => $c->archived,
                'team' => $c->team->name.' · '.$c->team->category?->localized_name,
                'games_count' => $c->games->count(),
                'record' => CompetitionRecord::fromGames($c->games),
            ]),
            'teams' => Team::query()->where('archived', false)->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name, 'branch_id' => $t->branch_id]),
            'formats' => GameFormat::query()->where('active', true)->whereNotNull('branch_id')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'types' => CompetitionType::values(),
            'season' => $season,
            'seasons' => Season::options(),
            'filters' => ['team_id' => $filters['team_id'] ?? null, 'archived' => $request->boolean('archived')],
            'manageableTeamIds' => TeamAccess::manageableTeamIds($request->user(), $season),
            'canAdd' => $request->user()->hasPermission('competitions', 'add'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'season_start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(CompetitionType::class)],
            'game_format_id' => ['nullable', 'integer', 'exists:game_formats,id'],
        ]);
        $team = Team::findOrFail($data['team_id']);
        TeamAccess::authorize($request->user(), $team, (int) $data['season_start_year']);
        $this->assertFormatFits($data['game_format_id'] ?? null, $team);

        $competition = Competition::create($data);

        return redirect()->route('competitions.show', $competition)->with('success', 'flash.competition_created');
    }

    public function show(Request $request, Competition $competition): Response
    {
        $competition->load(['team.branch', 'team.category', 'team.defaultFormat', 'format']);
        $team = $competition->team;
        $season = $competition->season_start_year;
        $squad = DB::table('competition_players')->where('competition_id', $competition->id)->pluck('hidden', 'player_id');
        $games = $competition->games()->with('opponent:id,name')->orderBy('date')->get();

        return Inertia::render('Competitions/Show', [
            'competition' => [
                ...$competition->only(['id', 'name', 'season_start_year', 'game_format_id', 'red_ban_matches', 'yellow_threshold', 'yellow_ban_matches', 'archived', 'notes']),
                'type' => $competition->type->value,
                'team' => ['id' => $team->id, 'name' => $team->name, 'category' => $team->category?->localized_name],
                'season_label' => Season::forStartYear($season)->label(),
                'format' => ($competition->format ?? $team->defaultFormat)?->localized_name,
            ],
            'roster' => $team->rosterFor($season)->with('category')->get()
                ->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $p->category?->localized_name,
                    'shirt_number' => $p->pivot->shirt_number,
                    'in_squad' => $squad->has($p->id) && ! $squad[$p->id],
                ])->sortBy('name')->values(),
            'games' => $games->map(fn (Game $g) => [
                'id' => $g->id, 'date' => $g->date, 'opponent' => $g->opponent->name, 'side' => $g->side->value,
                'round_label' => $g->round_label, 'status' => $g->status->value,
                'our_score' => $g->our_score, 'their_score' => $g->their_score,
            ]),
            'record' => CompetitionRecord::fromGames($games),
            'formats' => GameFormat::query()->where('branch_id', $team->branch_id)->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'types' => CompetitionType::values(),
            'canManage' => TeamAccess::canManage($request->user(), $team, $season),
        ]);
    }

    public function update(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(CompetitionType::class)],
            'game_format_id' => ['nullable', 'integer', 'exists:game_formats,id'],
            'red_ban_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'yellow_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            'yellow_ban_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $this->assertFormatFits($data['game_format_id'] ?? null, $competition->team);
        $competition->update($data);

        return back()->with('success', 'flash.competition_updated');
    }

    /** A competition with matches keeps its history: it is archived, not deleted. */
    public function destroy(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        if ($competition->games()->exists()) {
            $competition->update(['archived' => true]);

            return redirect()->route('competitions.index')->with('success', 'flash.competition_archived');
        }
        $competition->delete();

        return redirect()->route('competitions.index')->with('success', 'flash.competition_deleted');
    }

    private function assertFormatFits(?int $formatId, Team $team): void
    {
        if ($formatId !== null && (int) GameFormat::whereKey($formatId)->value('branch_id') !== $team->branch_id) {
            throw ValidationException::withMessages(['game_format_id' => 'comp.error.format_other_sport']);
        }
    }
}
```

`app/Http/Controllers/CompetitionSquadController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\GamePlayer;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompetitionSquadController extends Controller
{
    /**
     * Makes the squad exactly the given roster players. A removed player who
     * already played in this competition is hidden rather than deleted, so
     * their matches keep pointing at a squad member.
     */
    public function update(Request $request, Competition $competition): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $competition->team, $competition->season_start_year);
        $roster = DB::table('team_players')
            ->where('team_id', $competition->team_id)->where('season_start_year', $competition->season_start_year)
            ->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'distinct', Rule::in($roster)],
        ]);
        $ids = array_map('intval', $data['player_ids']);

        DB::transaction(function () use ($competition, $ids) {
            $squad = fn () => DB::table('competition_players')->where('competition_id', $competition->id);
            $current = $squad()->pluck('player_id')->map(fn ($id) => (int) $id)->all();
            $removed = array_values(array_diff($current, $ids));
            $played = GamePlayer::query()->whereIn('player_id', $removed)->where('called_up', true)
                ->whereHas('game', fn ($q) => $q->where('competition_id', $competition->id)->where('status', GameStatus::Played->value))
                ->pluck('player_id')->map(fn ($id) => (int) $id)->all();

            $now = now();
            $squad()->whereIn('player_id', array_diff($removed, $played))->delete();
            $squad()->whereIn('player_id', $played)->update(['hidden' => true, 'updated_at' => $now]);
            $squad()->whereIn('player_id', $ids)->update(['hidden' => false, 'updated_at' => $now]);
            foreach (array_diff($ids, $current) as $id) {
                DB::table('competition_players')->insert([
                    'competition_id' => $competition->id, 'player_id' => $id, 'hidden' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return back()->with('success', 'flash.squad_saved');
    }
}
```

- [ ] **Step 6: Routes**

In `routes/web.php`, add `use App\Http\Controllers\CompetitionController;` and `use App\Http\Controllers\CompetitionSquadController;`. After the `teams.coaches.update` route from Task 6, add:

```php
        Route::get('/competitions', [CompetitionController::class, 'index'])->name('competitions.index');
        Route::post('/competitions', [CompetitionController::class, 'store'])->name('competitions.store');
        Route::get('/competitions/{competition}', [CompetitionController::class, 'show'])->name('competitions.show');
        Route::put('/competitions/{competition}', [CompetitionController::class, 'update'])->name('competitions.update');
        Route::delete('/competitions/{competition}', [CompetitionController::class, 'destroy'])->name('competitions.destroy');
        Route::put('/competitions/{competition}/squad', [CompetitionSquadController::class, 'update'])->name('competitions.squad.update');
```

- [ ] **Step 7: Write the pages**

`resources/js/Pages/Competitions/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    competitions: Array, teams: Array, formats: Array, types: Array,
    season: Number, seasons: Array, filters: Object, manageableTeamIds: Array, canAdd: Boolean,
});

const creating = ref(false);
const form = useForm({ team_id: props.filters.team_id ?? '', season_start_year: props.season, name: '', type: 'league', game_format_id: '' });
const teamOptions = computed(() => props.manageableTeamIds === null
    ? props.teams
    : props.teams.filter((team) => props.manageableTeamIds.includes(team.value)));
const formatOptions = computed(() => {
    const team = props.teams.find((x) => x.value === Number(form.team_id));
    return team ? props.formats.filter((f) => f.branch_id === team.branch_id) : [];
});

function reload(params) {
    router.get(route('competitions.index'), { season: props.season, ...props.filters, ...params }, { preserveState: true });
}

function create() {
    form.transform((data) => ({ ...data, game_format_id: data.game_format_id || null })).post(route('competitions.store'));
}

const formClass = { W: 'bg-emerald-500', D: 'bg-slate-400', L: 'bg-rose-500' };
</script>

<template>
    <Head :title="t('competitions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('competitions') }}</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event })" />
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56"
                        @update:model-value="reload({ team_id: $event || null })" />
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="filters.archived" class="rounded" @change="reload({ archived: $event.target.checked ? 1 : 0 })" /> {{ t('comp.show_archived') }}</label>
                    <PrimaryButton v-if="canAdd && teamOptions.length" @click="creating = !creating">{{ t('comp.new_competition') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-5">
                <label class="text-sm">{{ t('team') }}
                    <SearchableSelect v-model="form.team_id" :options="teamOptions" class="mt-1" />
                </label>
                <label class="text-sm">{{ t('name') }}
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="text-sm">{{ t('comp.type') }}
                    <select v-model="form.type" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="type in types" :key="type" :value="type">{{ t(`comp.type_${type}`) }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('comp.game_format') }}
                    <select v-model="form.game_format_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('comp.team_default_format') }}</option>
                        <option v-for="f in formatOptions" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                    </select>
                    <InputError :message="form.errors.game_format_id ? t(form.errors.game_format_id) : ''" />
                </label>
                <div class="flex items-end gap-2">
                    <PrimaryButton :disabled="form.processing || !form.team_id">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('team') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('comp.record_short') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('comp.goals_short') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('comp.form') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in competitions" :key="c.id" :class="{ 'opacity-60': c.archived }">
                            <td class="px-4 py-3">
                                <Link :href="route('competitions.show', c.id)" class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ c.name }}</Link>
                                <div class="text-xs text-slate-500">{{ t(`comp.type_${c.type}`) }} · {{ t('comp.games_count', { n: c.games_count }) }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ c.team }}</td>
                            <td class="px-4 py-3 text-center font-mono"><bdi dir="ltr">{{ c.record.wins }}-{{ c.record.draws }}-{{ c.record.losses }}</bdi></td>
                            <td class="px-4 py-3 text-center font-mono"><bdi dir="ltr">{{ c.record.goals_for }}:{{ c.record.goals_against }}</bdi></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    <span v-for="(r, i) in c.record.form" :key="i" class="flex h-5 w-5 items-center justify-center rounded text-xs font-bold text-white" :class="formClass[r]">{{ t(`comp.form_${r}`) }}</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!competitions.length"><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

`resources/js/Pages/Competitions/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    competition: Object, roster: Array, games: Array, record: Object, formats: Array, types: Array, canManage: Boolean,
});

const tab = ref('squad');
const confirmDelete = ref(false);

const selected = ref(props.roster.filter((r) => r.in_squad).map((r) => r.player_id));
const squadForm = useForm({ player_ids: [] });
function saveSquad() {
    squadForm.player_ids = selected.value;
    squadForm.put(route('competitions.squad.update', props.competition.id), { preserveScroll: true });
}

const rulesForm = useForm({
    name: props.competition.name, type: props.competition.type, game_format_id: props.competition.game_format_id ?? '',
    red_ban_matches: props.competition.red_ban_matches, yellow_threshold: props.competition.yellow_threshold ?? '',
    yellow_ban_matches: props.competition.yellow_ban_matches, notes: props.competition.notes ?? '', archived: props.competition.archived,
});
function saveRules() {
    rulesForm.transform((d) => ({ ...d, game_format_id: d.game_format_id || null, yellow_threshold: d.yellow_threshold || null }))
        .put(route('competitions.update', props.competition.id), { preserveScroll: true });
}

function destroy() {
    router.delete(route('competitions.destroy', props.competition.id));
}

const tabs = ['squad', 'games', 'record', 'rules'];
const formClass = { W: 'bg-emerald-500', D: 'bg-slate-400', L: 'bg-rose-500' };
</script>

<template>
    <Head :title="competition.name" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('competitions.index', { season: competition.season_start_year })" class="text-sm text-primary-600 hover:underline">{{ t('competitions') }}</Link>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ competition.name }}</h1>
            <div class="text-sm text-slate-500 dark:text-slate-400">
                <Link :href="route('teams.show', { team: competition.team.id, season: competition.season_start_year })" class="hover:underline">{{ competition.team.name }} · {{ competition.team.category }}</Link>
                · {{ t(`comp.type_${competition.type}`) }} · {{ competition.season_label }} · {{ competition.format }}
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in tabs" :key="key" type="button" @click="tab = key" class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <section v-if="tab === 'squad'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p class="text-sm text-slate-500">{{ t('comp.squad_help', { n: selected.length }) }}</p>
                <div class="grid gap-1 sm:grid-cols-2">
                    <label v-for="r in roster" :key="r.player_id" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                        <input v-model="selected" :value="r.player_id" :disabled="!canManage" type="checkbox" class="rounded" />
                        <span class="w-6 text-end font-mono text-slate-400">{{ r.shirt_number }}</span>
                        <span class="font-medium">{{ r.name }}</span>
                        <span class="text-xs text-slate-400">{{ r.category }}</span>
                    </label>
                </div>
                <p v-if="!roster.length" class="text-sm text-slate-500">{{ t('comp.roster_empty') }}</p>
                <InputError :message="squadForm.errors.player_ids" />
                <PrimaryButton v-if="canManage" :disabled="squadForm.processing" @click="saveSquad">{{ t('save') }}</PrimaryButton>
            </section>

            <section v-if="tab === 'games'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="g in games" :key="g.id" :href="route('games.show', g.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span><bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi> · {{ g.opponent }} ({{ t(`comp.side_${g.side}`) }})<span v-if="g.round_label" class="text-slate-400"> · {{ g.round_label }}</span></span>
                    <span class="text-sm text-slate-500">
                        <bdi v-if="g.status === 'played'" dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi>
                        <template v-else>{{ t(`comp.status_${g.status}`) }}</template>
                    </span>
                </Link>
                <p v-if="!games.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link v-if="canManage" :href="route('games.index', { team_id: competition.team.id, competition_id: competition.id, create: 1 })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('comp.new_game') }} →</Link>
            </section>

            <section v-if="tab === 'record'" class="grid gap-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-4">
                <div v-for="key in ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against']" :key="key" class="text-center">
                    <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ record[key] }}</div>
                    <div class="text-xs uppercase text-slate-500">{{ t(`comp.record_${key}`) }}</div>
                </div>
                <div class="text-center sm:col-span-2">
                    <div class="flex justify-center gap-1">
                        <span v-for="(r, i) in record.form" :key="i" class="flex h-7 w-7 items-center justify-center rounded font-bold text-white" :class="formClass[r]">{{ t(`comp.form_${r}`) }}</span>
                    </div>
                    <div class="mt-1 text-xs uppercase text-slate-500">{{ t('comp.form') }}</div>
                </div>
            </section>

            <section v-if="tab === 'rules'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('name') }} <TextInput v-model="rulesForm.name" :disabled="!canManage" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.type') }}
                        <select v-model="rulesForm.type" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="type in types" :key="type" :value="type">{{ t(`comp.type_${type}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.game_format') }}
                        <select v-model="rulesForm.game_format_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ t('comp.team_default_format') }}</option>
                            <option v-for="f in formats" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                        </select>
                        <InputError :message="rulesForm.errors.game_format_id ? t(rulesForm.errors.game_format_id) : ''" />
                    </label>
                </div>
                <h3 class="pt-2 text-sm font-semibold">{{ t('comp.suspension_rules') }}</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('comp.red_ban_matches') }} <TextInput v-model.number="rulesForm.red_ban_matches" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.yellow_threshold') }} <TextInput v-model.number="rulesForm.yellow_threshold" :disabled="!canManage" type="number" min="1" class="mt-1 w-full" :placeholder="t('comp.yellow_threshold_none')" /></label>
                    <label class="text-sm">{{ t('comp.yellow_ban_matches') }} <TextInput v-model.number="rulesForm.yellow_ban_matches" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" /></label>
                </div>
                <p class="text-xs text-slate-500">{{ t('comp.suspension_rules_help') }}</p>
                <label class="block text-sm">{{ t('notes') }}
                    <textarea v-model="rulesForm.notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
                <label class="flex items-center gap-2 text-sm"><input v-model="rulesForm.archived" :disabled="!canManage" type="checkbox" class="rounded" /> {{ t('archived') }}</label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canManage" :disabled="rulesForm.processing" @click="saveRules">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="canManage && can('competitions', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('comp.delete_competition_confirm')" @confirm="destroy" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 8: Translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.new_competition": "New competition", "comp.type": "Type", "comp.team_default_format": "Team's format",
    "comp.record_short": "W-D-L", "comp.goals_short": "Goals", "comp.form": "Form", "comp.games_count": "{n} matches",
    "comp.form_W": "W", "comp.form_D": "D", "comp.form_L": "L",
    "comp.tab_squad": "Squad", "comp.tab_record": "Record", "comp.tab_rules": "Settings",
    "comp.squad_help": "Tick the roster players registered in this competition ({n} selected). Matches call players up from this squad.",
    "comp.new_game": "New match",
    "comp.record_played": "Played", "comp.record_wins": "Won", "comp.record_draws": "Drawn", "comp.record_losses": "Lost",
    "comp.record_goals_for": "Scored", "comp.record_goals_against": "Conceded",
    "comp.suspension_rules": "Suspension rules", "comp.red_ban_matches": "Matches banned after a red card",
    "comp.yellow_threshold": "Yellow cards before a ban", "comp.yellow_threshold_none": "No yellow-card ban",
    "comp.yellow_ban_matches": "Matches banned for yellow cards",
    "comp.suspension_rules_help": "Suspensions are counted from match reports in a later update; these rules are saved now.",
    "comp.delete_competition_confirm": "Delete this competition? A competition with matches is archived instead.",
    "flash.competition_created": "Competition created.", "flash.competition_updated": "Competition updated.",
    "flash.competition_deleted": "Competition deleted.", "flash.competition_archived": "The competition has matches, so it was archived.",
    "flash.squad_saved": "Squad saved."
  },
  "fr": {
    "comp.new_competition": "Nouvelle compétition", "comp.type": "Type", "comp.team_default_format": "Format de l'équipe",
    "comp.record_short": "V-N-D", "comp.goals_short": "Buts", "comp.form": "Forme", "comp.games_count": "{n} matchs",
    "comp.form_W": "V", "comp.form_D": "N", "comp.form_L": "D",
    "comp.tab_squad": "Liste", "comp.tab_record": "Bilan", "comp.tab_rules": "Réglages",
    "comp.squad_help": "Cochez les joueurs de l'effectif inscrits dans cette compétition ({n} sélectionnés). Les matchs convoquent depuis cette liste.",
    "comp.new_game": "Nouveau match",
    "comp.record_played": "Joués", "comp.record_wins": "Victoires", "comp.record_draws": "Nuls", "comp.record_losses": "Défaites",
    "comp.record_goals_for": "Buts marqués", "comp.record_goals_against": "Buts encaissés",
    "comp.suspension_rules": "Règles de suspension", "comp.red_ban_matches": "Matchs de suspension après un rouge",
    "comp.yellow_threshold": "Cartons jaunes avant suspension", "comp.yellow_threshold_none": "Pas de suspension aux jaunes",
    "comp.yellow_ban_matches": "Matchs de suspension pour les jaunes",
    "comp.suspension_rules_help": "Les suspensions seront calculées depuis les feuilles de match dans une prochaine mise à jour ; les règles sont enregistrées dès maintenant.",
    "comp.delete_competition_confirm": "Supprimer cette compétition ? Une compétition avec des matchs est archivée à la place.",
    "flash.competition_created": "Compétition créée.", "flash.competition_updated": "Compétition modifiée.",
    "flash.competition_deleted": "Compétition supprimée.", "flash.competition_archived": "La compétition a des matchs : elle a été archivée.",
    "flash.squad_saved": "Liste enregistrée."
  },
  "ar": {
    "comp.new_competition": "منافسة جديدة", "comp.type": "النوع", "comp.team_default_format": "نظام الفريق",
    "comp.record_short": "ف-ت-خ", "comp.goals_short": "الأهداف", "comp.form": "الأداء", "comp.games_count": "{n} مباراة",
    "comp.form_W": "ف", "comp.form_D": "ت", "comp.form_L": "خ",
    "comp.tab_squad": "القائمة", "comp.tab_record": "الحصيلة", "comp.tab_rules": "الإعدادات",
    "comp.squad_help": "حدّد لاعبي التشكيلة المسجّلين في هذه المنافسة ({n} محدد). تُستدعى المباريات من هذه القائمة.",
    "comp.new_game": "مباراة جديدة",
    "comp.record_played": "لُعبت", "comp.record_wins": "فوز", "comp.record_draws": "تعادل", "comp.record_losses": "خسارة",
    "comp.record_goals_for": "أهداف مسجلة", "comp.record_goals_against": "أهداف مستقبلة",
    "comp.suspension_rules": "قواعد الإيقاف", "comp.red_ban_matches": "مباريات الإيقاف بعد البطاقة الحمراء",
    "comp.yellow_threshold": "البطاقات الصفراء قبل الإيقاف", "comp.yellow_threshold_none": "لا إيقاف بالبطاقات الصفراء",
    "comp.yellow_ban_matches": "مباريات الإيقاف بسبب البطاقات الصفراء",
    "comp.suspension_rules_help": "ستُحسب الإيقافات من أوراق المباريات في تحديث قادم؛ تُحفظ القواعد من الآن.",
    "comp.delete_competition_confirm": "حذف هذه المنافسة؟ المنافسة التي لها مباريات تُؤرشف بدلاً من ذلك.",
    "flash.competition_created": "تم إنشاء المنافسة.", "flash.competition_updated": "تم تعديل المنافسة.",
    "flash.competition_deleted": "تم حذف المنافسة.", "flash.competition_archived": "للمنافسة مباريات، لذلك تمت أرشفتها.",
    "flash.squad_saved": "تم حفظ القائمة."
  }
}
EOF
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter="CompetitionRecordTest|CompetitionsTest|FlashTranslation"`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Services/Competitions/CompetitionRecord.php app/Http/Controllers/CompetitionController.php app/Http/Controllers/CompetitionSquadController.php routes/web.php resources/js/Pages/Competitions/Index.vue resources/js/Pages/Competitions/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Unit/CompetitionRecordTest.php tests/Feature/Competitions/CompetitionsTest.php
git commit -m "feat(competitions): competitions with squads, our record and suspension rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Matches — schedule, list, calendar, cancel training

**Files:**
- Create: `app/Services/Competitions/TrainingCanceller.php`
- Create: `app/Http/Controllers/GameController.php`
- Create: `resources/js/Pages/Games/Index.vue`, `resources/js/Pages/Games/Show.vue` (the Info tab only; the call-up tab is added in Task 9)
- Modify: `routes/web.php`
- Test: `tests/Feature/Competitions/GamesTest.php`

**Interfaces:**
- Consumes: `TeamAccess`, `SessionGenerator::forMonth(int $categoryId, int $year, int $month): int`, `UiLang::get()`, `ActivityRecorder::record()`.
- Produces: `TrainingCanceller::cancelForGame(Game, User): int`; routes `games.{index,store,show,update,destroy}`. The `Games/Show` props are `game`, `opponents`, `venues`, `canManage`, plus `candidates` (filled in Task 9).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/GamesTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Game;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class GamesTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + ['opponent_id' => $this->opponent()->id, 'side' => 'home', 'date' => '2027-02-06', 'kickoff_time' => '15:00'];
    }

    #[Test]
    public function a_friendly_takes_its_season_from_the_date_and_the_team_format(): void
    {
        $team = $this->team();
        $response = $this->actingAs($this->admin())->post(route('games.store'), $this->payload(['team_id' => $team->id]));

        $game = Game::sole();
        $response->assertRedirect(route('games.show', $game))->assertSessionHas('success', 'flash.game_created');
        $this->assertSame(2026, $game->season_start_year);
        $this->assertSame($team->default_game_format_id, $game->game_format_id);
        $this->assertSame(GameStatus::Scheduled, $game->status);
    }

    #[Test]
    public function a_competition_match_takes_the_competition_season_and_format_override(): void
    {
        $team = $this->team();
        $override = $this->format($team->branch, ['name' => 'Cup format']);
        $competition = $this->competition($team, 2026, ['game_format_id' => $override->id]);
        $otherTeams = $this->competition($this->team(), 2026);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('games.store'), $this->payload(['team_id' => $team->id, 'competition_id' => $otherTeams->id]))
            ->assertSessionHasErrors('competition_id');
        $this->actingAs($admin)->post(route('games.store'), $this->payload(['team_id' => $team->id, 'competition_id' => $competition->id, 'date' => '2027-08-30']))
            ->assertSessionHasNoErrors();

        $game = Game::sole();
        $this->assertSame(2026, $game->season_start_year);
        $this->assertSame($override->id, $game->game_format_id);
    }

    #[Test]
    public function creating_a_match_can_cancel_the_teams_training_that_day(): void
    {
        $team = $this->team();
        $session = TrainingSession::create([
            'category_id' => $team->category_id, 'date' => '2027-02-06', 'start_time' => '10:00', 'end_time' => '11:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);

        $this->actingAs($this->admin())->post(route('games.store'), $this->payload(['team_id' => $team->id, 'cancel_training' => true]))
            ->assertSessionHas('success', 'flash.game_created_training_cancelled');

        $this->assertSame(SessionState::Cancelled, $session->fresh()->state);
        $this->assertStringContainsString('Rivals FC', $session->fresh()->cancel_reason);
    }

    #[Test]
    public function a_coach_schedules_matches_only_for_teams_coached(): void
    {
        $team = $this->team();
        $coach = $this->coach();
        $this->actingAs($coach)->post(route('games.store'), $this->payload(['team_id' => $team->id]))->assertForbidden();

        $this->assignCoach($team, $coach, 2026);
        $this->actingAs($coach)->post(route('games.store'), $this->payload(['team_id' => $team->id]))->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_played_match_needs_a_score_and_other_statuses_clear_it(): void
    {
        $team = $this->team();
        $game = $this->game($team);
        $admin = $this->admin();
        $update = fn (array $extra) => $this->actingAs($admin)->put(route('games.update', $game), $this->payload(['date' => '2026-10-17'] + $extra));

        $update(['status' => 'played'])->assertSessionHasErrors(['our_score', 'their_score']);
        $update(['status' => 'played', 'our_score' => 2, 'their_score' => 1])->assertSessionHas('success', 'flash.game_updated');
        $this->assertSame(2, $game->fresh()->our_score);

        $update(['status' => 'postponed', 'our_score' => 2, 'their_score' => 1])->assertSessionHasNoErrors();
        $this->assertNull($game->fresh()->our_score);
    }

    #[Test]
    public function the_list_splits_upcoming_and_past_and_the_month_view_shows_the_teams_training(): void
    {
        $team = $this->team();
        $this->game($team, ['date' => now()->addDays(3)->toDateString()]);
        $this->game($team, ['date' => now()->subDays(3)->toDateString(), 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        TrainingSession::create([
            'category_id' => $team->category_id, 'date' => now()->addDay()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Index')->has('upcoming', 1)->has('past.data', 1));

        $this->actingAs($admin)->get(route('games.index', ['view' => 'month', 'month' => now()->addDay()->format('Y-m'), 'team_id' => $team->id]))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'month')->has('sessions', 1));
    }

    #[Test]
    public function the_match_page_renders_and_a_match_can_be_deleted(): void
    {
        $game = $this->game($this->team());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')->where('game.opponent.name', 'Rivals FC')->where('canManage', true));

        $this->actingAs($admin)->delete(route('games.destroy', $game))->assertRedirect(route('games.index'));
        $this->assertModelMissing($game);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=GamesTest`
Expected: FAIL (`Route [games.store] not defined`).

- [ ] **Step 3: Write TrainingCanceller**

`app/Services/Competitions/TrainingCanceller.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\SessionState;
use App\Models\Game;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Attendance\SessionGenerator;
use App\Support\UiLang;

/**
 * On a match day the team usually does not train. Cancels the planned
 * sessions of the team's category on the match date, giving the match as
 * the reason, exactly as a coach would cancel them on the attendance page.
 */
final class TrainingCanceller
{
    public function __construct(private readonly SessionGenerator $generator) {}

    public function cancelForGame(Game $game, User $user): int
    {
        $game->loadMissing(['team', 'opponent']);
        $categoryId = $game->team->category_id;
        [$year, $month] = array_map('intval', explode('-', substr($game->date, 0, 7)));

        // Regular sessions are generated lazily per month: make sure that day's exist first.
        $this->generator->forMonth($categoryId, $year, $month);

        $reason = mb_substr(strtr(UiLang::get('comp.training_cancelled_reason'), ['{opponent}' => $game->opponent->name]), 0, 255);
        $sessions = TrainingSession::query()
            ->where('category_id', $categoryId)->where('date', $game->date)->where('state', SessionState::Planned->value)
            ->get();

        foreach ($sessions as $session) {
            $session->update(['state' => SessionState::Cancelled, 'cancel_reason' => $reason]);
            ActivityRecorder::record($user, ActivityAction::TRAINING_SESSION_CANCELLED, $session);
        }

        return $sessions->count();
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/GameController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\GameSide;
use App\Enums\GameStatus;
use App\Enums\SessionState;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Opponent;
use App\Models\Team;
use App\Models\TrainingSession;
use App\Models\Venue;
use App\Services\Attendance\SessionGenerator;
use App\Services\Competitions\TeamAccess;
use App\Services\Competitions\TrainingCanceller;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request, SessionGenerator $generator): Response
    {
        $filters = $request->validate([
            'team_id' => ['nullable', 'integer'],
            'competition_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(GameStatus::class)],
            'view' => ['nullable', Rule::in(['list', 'month'])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $view = $filters['view'] ?? 'list';
        $month = $filters['month'] ?? now()->format('Y-m');
        $today = now()->toDateString();
        $open = [GameStatus::Scheduled->value, GameStatus::Postponed->value];

        $base = fn () => Game::query()
            ->with(['team:id,name', 'competition:id,name', 'opponent:id,name,logo_url', 'venue:id,name'])
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('team_id', $id))
            ->when($filters['competition_id'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        $props = ['upcoming' => [], 'past' => null, 'monthGames' => [], 'sessions' => []];
        if ($view === 'month') {
            $from = $month.'-01';
            $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
            $props['monthGames'] = $base()->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('kickoff_time')->get()
                ->map(fn (Game $g) => self::row($g));
            $props['sessions'] = $this->sessions($filters['team_id'] ?? null, $month, $generator);
        } else {
            $props['upcoming'] = $base()->where('date', '>=', $today)->whereIn('status', $open)
                ->orderBy('date')->orderBy('kickoff_time')->get()->map(fn (Game $g) => self::row($g));
            $props['past'] = $base()->where(fn ($q) => $q->where('date', '<', $today)->orWhereNotIn('status', $open))
                ->orderByDesc('date')->paginate(25)->withQueryString()->through(fn (Game $g) => self::row($g));
        }

        $user = $request->user();

        return Inertia::render('Games/Index', $props + [
            'view' => $view,
            'month' => $month,
            'filters' => Arr::only($filters, ['team_id', 'competition_id', 'status']),
            'teams' => Team::query()->where('archived', false)->with('category')->orderBy('name')->get()
                ->map(fn (Team $t) => ['value' => $t->id, 'label' => $t->name.' · '.$t->category?->localized_name]),
            'competitions' => Competition::query()->where('archived', false)->orderByDesc('season_start_year')->orderBy('name')
                ->get(['id', 'team_id', 'name', 'season_start_year']),
            'opponents' => Opponent::query()->orderBy('name')->get(['id', 'name'])->map(fn (Opponent $o) => ['value' => $o->id, 'label' => $o->name]),
            'venues' => Venue::query()->orderByDesc('is_home')->orderBy('name')->get(['id', 'name', 'is_home']),
            'manageableTeamIds' => TeamAccess::manageableTeamIds($user, Season::current()->startYear),
            'canAdd' => $user->hasPermission('games', 'add'),
            'openCreate' => $request->boolean('create'),
        ]);
    }

    public function store(Request $request, TrainingCanceller $canceller): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'competition_id' => ['nullable', 'integer', 'exists:competitions,id'],
            'cancel_training' => ['sometimes', 'boolean'],
        ]);
        $team = Team::findOrFail($data['team_id']);
        $competition = isset($data['competition_id']) ? Competition::findOrFail($data['competition_id']) : null;
        if ($competition !== null && $competition->team_id !== $team->id) {
            throw ValidationException::withMessages(['competition_id' => 'comp.error.competition_other_team']);
        }
        $season = $competition?->season_start_year ?? Season::forDate($data['date'])->startYear;
        TeamAccess::authorize($request->user(), $team, $season);

        $game = Game::create(Arr::except($data, ['cancel_training']) + [
            'season_start_year' => $season,
            'game_format_id' => $competition?->game_format_id ?? $team->default_game_format_id,
            'status' => GameStatus::Scheduled,
        ]);
        $cancelled = $request->boolean('cancel_training') ? $canceller->cancelForGame($game, $request->user()) : 0;

        return redirect()->route('games.show', $game)
            ->with('success', $cancelled > 0 ? 'flash.game_created_training_cancelled' : 'flash.game_created');
    }

    public function show(Request $request, Game $game): Response
    {
        $game->load(['team.category', 'competition', 'opponent', 'venue', 'format']);

        return Inertia::render('Games/Show', [
            'game' => [
                ...$game->only(['id', 'date', 'kickoff_time', 'meeting_time', 'meeting_place', 'round_label', 'our_score', 'their_score', 'notes', 'opponent_id', 'venue_id', 'season_start_year']),
                'side' => $game->side->value,
                'status' => $game->status->value,
                'team' => ['id' => $game->team->id, 'name' => $game->team->name, 'category' => $game->team->category?->localized_name],
                'competition' => $game->competition ? ['id' => $game->competition->id, 'name' => $game->competition->name] : null,
                'opponent' => ['id' => $game->opponent->id, 'name' => $game->opponent->name, 'logo_url' => $game->opponent->logo_url],
                'venue' => $game->venue ? ['name' => $game->venue->name, 'address' => $game->venue->address] : null,
                'format' => [
                    'name' => $game->format->localized_name,
                    'players_on_field' => $game->format->players_on_field,
                    'max_called_up' => $game->format->max_called_up,
                ],
            ],
            'candidates' => [],
            'opponents' => Opponent::query()->orderBy('name')->get(['id', 'name'])->map(fn (Opponent $o) => ['value' => $o->id, 'label' => $o->name]),
            'venues' => Venue::query()->orderByDesc('is_home')->orderBy('name')->get(['id', 'name']),
            'canManage' => TeamAccess::canManage($request->user(), $game->team, $game->season_start_year),
        ]);
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $data = $request->validate($this->rules() + [
            'status' => ['required', Rule::enum(GameStatus::class)],
            'our_score' => ['nullable', 'required_if:status,played', 'integer', 'min:0', 'max:999'],
            'their_score' => ['nullable', 'required_if:status,played', 'integer', 'min:0', 'max:999'],
        ]);
        if ($data['status'] !== GameStatus::Played->value) {
            $data['our_score'] = null;
            $data['their_score'] = null;
        }
        // A friendly's season follows its date; a competition match keeps the competition's.
        if ($game->competition_id === null) {
            $data['season_start_year'] = Season::forDate($data['date'])->startYear;
        }
        $game->update($data);

        return back()->with('success', 'flash.game_updated');
    }

    public function destroy(Request $request, Game $game): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $game->delete();

        return redirect()->route('games.index')->with('success', 'flash.game_deleted');
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'opponent_id' => ['required', 'integer', 'exists:opponents,id'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'side' => ['required', Rule::enum(GameSide::class)],
            'round_label' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kickoff_time' => ['nullable', 'date_format:H:i'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'meeting_place' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    private static function row(Game $game): array
    {
        return [
            'id' => $game->id,
            'date' => $game->date,
            'kickoff_time' => $game->kickoff_time,
            'team' => $game->team->name,
            'competition' => $game->competition?->name,
            'opponent' => $game->opponent->name,
            'opponent_logo' => $game->opponent->logo_url,
            'venue' => $game->venue?->name,
            'side' => $game->side->value,
            'status' => $game->status->value,
            'round_label' => $game->round_label,
            'our_score' => $game->our_score,
            'their_score' => $game->their_score,
        ];
    }

    /** The filtered team's training sessions in $month, so the calendar shows matches next to training. */
    private function sessions(?int $teamId, string $month, SessionGenerator $generator): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        if ($team === null) {
            return [];
        }
        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        $generator->forMonth($team->category_id, $year, $monthNumber);

        return TrainingSession::query()->where('category_id', $team->category_id)->where('date', 'like', $month.'-%')
            ->orderBy('date')->orderBy('start_time')->get(['id', 'date', 'start_time', 'end_time', 'state'])
            ->map(fn (TrainingSession $s) => [
                'id' => $s->id, 'date' => $s->date, 'time' => $s->start_time.'–'.$s->end_time,
                'cancelled' => $s->state === SessionState::Cancelled,
            ])->all();
    }
}
```

- [ ] **Step 5: Routes**

In `routes/web.php`, add `use App\Http\Controllers\GameController;`. After the `competitions.squad.update` route, add:

```php
        Route::get('/games', [GameController::class, 'index'])->name('games.index');
        Route::post('/games', [GameController::class, 'store'])->name('games.store');
        Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
        Route::put('/games/{game}', [GameController::class, 'update'])->name('games.update');
        Route::delete('/games/{game}', [GameController::class, 'destroy'])->name('games.destroy');
```

- [ ] **Step 6: Write the pages**

`resources/js/Pages/Games/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    view: String, month: String, filters: Object, upcoming: Array, past: Object, monthGames: Array, sessions: Array,
    teams: Array, competitions: Array, opponents: Array, venues: Array, manageableTeamIds: Array, canAdd: Boolean, openCreate: Boolean,
});

const creating = ref(props.openCreate);
const form = useForm({
    team_id: props.filters.team_id ?? '', competition_id: props.filters.competition_id ?? '', opponent_id: '', venue_id: '',
    side: 'home', round_label: '', date: '', kickoff_time: '', meeting_time: '', meeting_place: '', notes: '', cancel_training: true,
});
const teamOptions = computed(() => props.manageableTeamIds === null
    ? props.teams
    : props.teams.filter((team) => props.manageableTeamIds.includes(team.value)));
const competitionOptions = computed(() => props.competitions.filter((c) => c.team_id === Number(form.team_id)));

function reload(params) {
    router.get(route('games.index'), { ...props.filters, view: props.view, month: props.month, ...params }, { preserveState: true });
}

function create() {
    form.transform((d) => ({
        ...d,
        competition_id: d.competition_id || null, venue_id: d.venue_id || null,
        kickoff_time: d.kickoff_time || null, meeting_time: d.meeting_time || null,
    })).post(route('games.store'));
}

function shiftMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    reload({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

// Monday-first month grid: leading blanks, then each day with its matches and sessions.
const days = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const count = new Date(y, m, 0).getDate();
    const blanks = (first.getDay() + 6) % 7;
    const cells = Array.from({ length: blanks }, () => null);
    for (let d = 1; d <= count; d++) {
        const date = `${props.month}-${String(d).padStart(2, '0')}`;
        cells.push({
            day: d, date,
            games: props.monthGames.filter((g) => g.date === date),
            sessions: props.sessions.filter((s) => s.date === date),
        });
    }
    return cells;
});

const statusClass = {
    scheduled: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    played: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    postponed: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    cancelled: 'bg-slate-200 text-slate-600 line-through dark:bg-slate-800 dark:text-slate-400',
};
</script>

<template>
    <Head :title="t('games')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('games') }}</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56"
                        @update:model-value="reload({ team_id: $event || null, competition_id: null })" />
                    <select :value="filters.status ?? ''" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" @change="reload({ status: $event.target.value || null })">
                        <option value="">{{ t('status') }}</option>
                        <option v-for="s in ['scheduled', 'played', 'postponed', 'cancelled']" :key="s" :value="s">{{ t(`comp.status_${s}`) }}</option>
                    </select>
                    <div class="flex overflow-hidden rounded-lg ring-1 ring-slate-300 dark:ring-slate-700">
                        <button v-for="v in ['list', 'month']" :key="v" type="button" class="px-3 py-1.5 text-sm"
                            :class="view === v ? 'bg-primary-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'"
                            @click="reload({ view: v })">{{ t(`comp.view_${v}`) }}</button>
                    </div>
                    <PrimaryButton v-if="canAdd && teamOptions.length" @click="creating = !creating">{{ t('comp.new_game') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-4">
                <label class="text-sm">{{ t('team') }}
                    <SearchableSelect v-model="form.team_id" :options="teamOptions" class="mt-1" @update:model-value="form.competition_id = ''" />
                    <InputError :message="form.errors.team_id" />
                </label>
                <label class="text-sm">{{ t('comp.competition') }}
                    <select v-model="form.competition_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('comp.friendly_no_competition') }}</option>
                        <option v-for="c in competitionOptions" :key="c.id" :value="c.id">{{ c.name }} ({{ c.season_start_year }})</option>
                    </select>
                    <InputError :message="form.errors.competition_id ? t(form.errors.competition_id) : ''" />
                </label>
                <label class="text-sm">{{ t('comp.opponent') }}
                    <SearchableSelect v-model="form.opponent_id" :options="opponents" class="mt-1" />
                    <InputError :message="form.errors.opponent_id" />
                    <Link :href="route('opponents.index')" class="text-xs text-primary-600 hover:underline">{{ t('comp.manage_opponents') }}</Link>
                </label>
                <label class="text-sm">{{ t('comp.side') }}
                    <select v-model="form.side" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="s in ['home', 'away', 'neutral']" :key="s" :value="s">{{ t(`comp.side_${s}`) }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('date') }}
                    <TextInput v-model="form.date" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.date" />
                </label>
                <label class="text-sm">{{ t('comp.kickoff') }} <TextInput v-model="form.kickoff_time" type="time" class="mt-1 w-full" /></label>
                <label class="text-sm">{{ t('comp.venue') }}
                    <select v-model="form.venue_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">—</option>
                        <option v-for="v in venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('comp.round_label') }} <TextInput v-model="form.round_label" class="mt-1 w-full" :placeholder="t('comp.round_label_hint')" /></label>
                <label class="text-sm">{{ t('comp.meeting_time') }} <TextInput v-model="form.meeting_time" type="time" class="mt-1 w-full" /></label>
                <label class="text-sm sm:col-span-2">{{ t('comp.meeting_place') }} <TextInput v-model="form.meeting_place" class="mt-1 w-full" /></label>
                <label class="flex items-end gap-2 pb-2 text-sm"><input v-model="form.cancel_training" type="checkbox" class="rounded" /> {{ t('comp.cancel_training') }}</label>
                <div class="flex items-end gap-2 sm:col-span-4">
                    <PrimaryButton :disabled="form.processing || !form.team_id || !form.opponent_id">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <template v-if="view === 'list'">
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('comp.upcoming') }}</h2>
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                        <Link v-for="g in upcoming" :key="g.id" :href="route('games.show', g.id)" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ g.team }} — {{ g.opponent }} <span class="text-sm text-slate-500">({{ t(`comp.side_${g.side}`) }})</span></div>
                                <div class="text-xs text-slate-500">{{ g.competition || t('comp.type_friendly') }}<span v-if="g.round_label"> · {{ g.round_label }}</span><span v-if="g.venue"> · {{ g.venue }}</span></div>
                            </div>
                            <div class="text-end">
                                <bdi dir="ltr" class="font-mono text-sm">{{ g.date }} {{ g.kickoff_time }}</bdi>
                                <div><span class="rounded px-1.5 py-0.5 text-xs" :class="statusClass[g.status]">{{ t(`comp.status_${g.status}`) }}</span></div>
                            </div>
                        </Link>
                        <p v-if="!upcoming.length" class="px-4 py-6 text-center text-sm text-slate-500">{{ t('no_results') }}</p>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('comp.past') }}</h2>
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                        <Link v-for="g in past.data" :key="g.id" :href="route('games.show', g.id)" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ g.team }} — {{ g.opponent }} <span class="text-sm text-slate-500">({{ t(`comp.side_${g.side}`) }})</span></div>
                                <div class="text-xs text-slate-500">{{ g.competition || t('comp.type_friendly') }}<span v-if="g.round_label"> · {{ g.round_label }}</span></div>
                            </div>
                            <div class="text-end">
                                <bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi>
                                <div v-if="g.status === 'played'" class="font-mono font-bold"><bdi dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi></div>
                                <div v-else><span class="rounded px-1.5 py-0.5 text-xs" :class="statusClass[g.status]">{{ t(`comp.status_${g.status}`) }}</span></div>
                            </div>
                        </Link>
                        <p v-if="!past.data.length" class="px-4 py-6 text-center text-sm text-slate-500">{{ t('no_results') }}</p>
                    </div>
                    <Pagination :links="past.links" />
                </section>
            </template>

            <section v-else class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mb-3 flex items-center justify-between">
                    <SecondaryButton @click="shiftMonth(-1)">‹</SecondaryButton>
                    <span class="font-semibold"><bdi dir="ltr">{{ month }}</bdi></span>
                    <SecondaryButton @click="shiftMonth(1)">›</SecondaryButton>
                </div>
                <p v-if="!filters.team_id" class="mb-2 text-xs text-slate-500">{{ t('comp.calendar_training_hint') }}</p>
                <div class="grid grid-cols-7 gap-1 text-xs">
                    <div v-for="d in 7" :key="`h${d}`" class="py-1 text-center font-semibold uppercase text-slate-500">{{ t(`comp.weekday_${d}`) }}</div>
                    <div v-for="(cell, i) in days" :key="i" class="min-h-20 rounded border border-slate-100 p-1 dark:border-slate-800" :class="{ 'bg-slate-50 dark:bg-slate-950': !cell }">
                        <template v-if="cell">
                            <div class="text-slate-400">{{ cell.day }}</div>
                            <Link v-for="g in cell.games" :key="`g${g.id}`" :href="route('games.show', g.id)" class="mt-0.5 block truncate rounded px-1 py-0.5" :class="statusClass[g.status]">
                                <bdi dir="ltr">{{ g.kickoff_time }}</bdi> {{ g.opponent }}
                            </Link>
                            <div v-for="s in cell.sessions" :key="`s${s.id}`" class="mt-0.5 truncate rounded bg-slate-100 px-1 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300" :class="{ 'line-through opacity-60': s.cancelled }">
                                {{ t('comp.training') }} <bdi dir="ltr">{{ s.time }}</bdi>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
```

`resources/js/Pages/Games/Show.vue` (the call-up tab is added in Task 9):

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ game: Object, candidates: Array, opponents: Array, venues: Array, canManage: Boolean });

const tab = ref('info');
const confirmDelete = ref(false);

const form = useForm({
    opponent_id: props.game.opponent_id, venue_id: props.game.venue_id ?? '', side: props.game.side,
    round_label: props.game.round_label ?? '', date: props.game.date, kickoff_time: props.game.kickoff_time ?? '',
    meeting_time: props.game.meeting_time ?? '', meeting_place: props.game.meeting_place ?? '', notes: props.game.notes ?? '',
    status: props.game.status, our_score: props.game.our_score ?? '', their_score: props.game.their_score ?? '',
});

function save() {
    form.transform((d) => ({
        ...d,
        venue_id: d.venue_id || null, kickoff_time: d.kickoff_time || null, meeting_time: d.meeting_time || null,
        our_score: d.our_score === '' ? null : d.our_score, their_score: d.their_score === '' ? null : d.their_score,
    })).put(route('games.update', props.game.id), { preserveScroll: true });
}

function destroy() {
    router.delete(route('games.destroy', props.game.id));
}
</script>

<template>
    <Head :title="`${game.team.name} — ${game.opponent.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('games.index')" class="text-sm text-primary-600 hover:underline">{{ t('games') }}</Link>
            <div class="flex items-center gap-3">
                <img v-if="game.opponent.logo_url" :src="game.opponent.logo_url" alt="" class="h-9 w-9 rounded object-contain" />
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                    {{ game.team.name }} — {{ game.opponent.name }}
                    <bdi v-if="game.status === 'played'" dir="ltr" class="ms-2 font-mono">{{ game.our_score }} – {{ game.their_score }}</bdi>
                </h1>
            </div>
            <div class="text-sm text-slate-500 dark:text-slate-400">
                <bdi dir="ltr">{{ game.date }} {{ game.kickoff_time }}</bdi> · {{ t(`comp.side_${game.side}`) }}
                · <Link v-if="game.competition" :href="route('competitions.show', game.competition.id)" class="hover:underline">{{ game.competition.name }}</Link><span v-else>{{ t('comp.type_friendly') }}</span>
                <span v-if="game.round_label"> · {{ game.round_label }}</span> · {{ game.format.name }}
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in ['info', 'callup']" :key="key" type="button" @click="tab = key" class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <section v-if="tab === 'info'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.opponent') }}
                        <SearchableSelect v-model="form.opponent_id" :options="opponents" :disabled="!canManage" class="mt-1" />
                    </label>
                    <label class="text-sm">{{ t('comp.side') }}
                        <select v-model="form.side" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in ['home', 'away', 'neutral']" :key="s" :value="s">{{ t(`comp.side_${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('date') }} <TextInput v-model="form.date" :disabled="!canManage" type="date" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.kickoff') }} <TextInput v-model="form.kickoff_time" :disabled="!canManage" type="time" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.venue') }}
                        <select v-model="form.venue_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">—</option>
                            <option v-for="v in venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.round_label') }} <TextInput v-model="form.round_label" :disabled="!canManage" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.meeting_time') }} <TextInput v-model="form.meeting_time" :disabled="!canManage" type="time" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.meeting_place') }} <TextInput v-model="form.meeting_place" :disabled="!canManage" class="mt-1 w-full" /></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('status') }}
                        <select v-model="form.status" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in ['scheduled', 'played', 'postponed', 'cancelled']" :key="s" :value="s">{{ t(`comp.status_${s}`) }}</option>
                        </select>
                    </label>
                    <template v-if="form.status === 'played'">
                        <label class="text-sm">{{ t('comp.our_score') }}
                            <TextInput v-model.number="form.our_score" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" />
                            <InputError :message="form.errors.our_score" />
                        </label>
                        <label class="text-sm">{{ t('comp.their_score') }}
                            <TextInput v-model.number="form.their_score" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" />
                            <InputError :message="form.errors.their_score" />
                        </label>
                    </template>
                </div>
                <label class="block text-sm">{{ t('notes') }}
                    <textarea v-model="form.notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="canManage && can('games', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>

            <section v-if="tab === 'callup'" class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p class="text-sm text-slate-500">{{ t('comp.callup_coming') }}</p>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('are_you_sure')" @confirm="destroy" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
```

Check that `resources/js/Components/Pagination.vue` takes a `links` prop: `grep -n "defineProps" -A3 resources/js/Components/Pagination.vue`. If its prop has a different name, use that name in `Games/Index.vue`.

- [ ] **Step 7: Translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.view_list": "List", "comp.view_month": "Calendar", "comp.competition": "Competition",
    "comp.friendly_no_competition": "Friendly (no competition)", "comp.opponent": "Opponent", "comp.manage_opponents": "Manage opponents",
    "comp.side": "Home / away", "comp.kickoff": "Kick-off", "comp.venue": "Venue", "comp.round_label": "Round",
    "comp.round_label_hint": "e.g. Matchday 7, Quarter-final", "comp.meeting_time": "Meeting time", "comp.meeting_place": "Meeting place",
    "comp.cancel_training": "Cancel the team's training that day", "comp.upcoming": "Upcoming", "comp.past": "Past",
    "comp.calendar_training_hint": "Pick a team to see its training sessions too.", "comp.training": "Training",
    "comp.weekday_1": "Mon", "comp.weekday_2": "Tue", "comp.weekday_3": "Wed", "comp.weekday_4": "Thu", "comp.weekday_5": "Fri", "comp.weekday_6": "Sat", "comp.weekday_7": "Sun",
    "comp.tab_info": "Match", "comp.tab_callup": "Call-up", "comp.our_score": "Our score", "comp.their_score": "Their score",
    "comp.callup_coming": "The call-up is being prepared.", "comp.training_cancelled_reason": "Match against {opponent}",
    "comp.error.competition_other_team": "This competition belongs to another team.",
    "flash.game_created": "Match scheduled.", "flash.game_created_training_cancelled": "Match scheduled and that day's training cancelled.",
    "flash.game_updated": "Match updated.", "flash.game_deleted": "Match deleted."
  },
  "fr": {
    "comp.view_list": "Liste", "comp.view_month": "Calendrier", "comp.competition": "Compétition",
    "comp.friendly_no_competition": "Amical (sans compétition)", "comp.opponent": "Adversaire", "comp.manage_opponents": "Gérer les adversaires",
    "comp.side": "Domicile / extérieur", "comp.kickoff": "Coup d'envoi", "comp.venue": "Lieu", "comp.round_label": "Journée / tour",
    "comp.round_label_hint": "ex. 7e journée, quart de finale", "comp.meeting_time": "Heure du rendez-vous", "comp.meeting_place": "Lieu du rendez-vous",
    "comp.cancel_training": "Annuler l'entraînement de l'équipe ce jour-là", "comp.upcoming": "À venir", "comp.past": "Passés",
    "comp.calendar_training_hint": "Choisissez une équipe pour voir aussi ses entraînements.", "comp.training": "Entraînement",
    "comp.weekday_1": "Lun", "comp.weekday_2": "Mar", "comp.weekday_3": "Mer", "comp.weekday_4": "Jeu", "comp.weekday_5": "Ven", "comp.weekday_6": "Sam", "comp.weekday_7": "Dim",
    "comp.tab_info": "Match", "comp.tab_callup": "Convocation", "comp.our_score": "Notre score", "comp.their_score": "Score adverse",
    "comp.callup_coming": "La convocation est en préparation.", "comp.training_cancelled_reason": "Match contre {opponent}",
    "comp.error.competition_other_team": "Cette compétition appartient à une autre équipe.",
    "flash.game_created": "Match programmé.", "flash.game_created_training_cancelled": "Match programmé et entraînement du jour annulé.",
    "flash.game_updated": "Match modifié.", "flash.game_deleted": "Match supprimé."
  },
  "ar": {
    "comp.view_list": "قائمة", "comp.view_month": "تقويم", "comp.competition": "المنافسة",
    "comp.friendly_no_competition": "ودية (بدون منافسة)", "comp.opponent": "المنافس", "comp.manage_opponents": "إدارة المنافسين",
    "comp.side": "داخل / خارج الديار", "comp.kickoff": "انطلاق المباراة", "comp.venue": "الملعب", "comp.round_label": "الجولة",
    "comp.round_label_hint": "مثال: الجولة 7، ربع النهائي", "comp.meeting_time": "ساعة التجمع", "comp.meeting_place": "مكان التجمع",
    "comp.cancel_training": "إلغاء حصة تدريب الفريق في ذلك اليوم", "comp.upcoming": "القادمة", "comp.past": "السابقة",
    "comp.calendar_training_hint": "اختر فريقاً لرؤية حصص تدريبه أيضاً.", "comp.training": "تدريب",
    "comp.weekday_1": "إث", "comp.weekday_2": "ثل", "comp.weekday_3": "أر", "comp.weekday_4": "خم", "comp.weekday_5": "جم", "comp.weekday_6": "سب", "comp.weekday_7": "أح",
    "comp.tab_info": "المباراة", "comp.tab_callup": "الاستدعاء", "comp.our_score": "نتيجتنا", "comp.their_score": "نتيجة المنافس",
    "comp.callup_coming": "الاستدعاء قيد التحضير.", "comp.training_cancelled_reason": "مباراة ضد {opponent}",
    "comp.error.competition_other_team": "هذه المنافسة تابعة لفريق آخر.",
    "flash.game_created": "تمت برمجة المباراة.", "flash.game_created_training_cancelled": "تمت برمجة المباراة وإلغاء تدريب ذلك اليوم.",
    "flash.game_updated": "تم تعديل المباراة.", "flash.game_deleted": "تم حذف المباراة."
  }
}
EOF
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="GamesTest|FlashTranslation|ActivityCoverage"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Competitions/TrainingCanceller.php app/Http/Controllers/GameController.php routes/web.php resources/js/Pages/Games/Index.vue resources/js/Pages/Games/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/GamesTest.php
git commit -m "feat(competitions): schedule matches, list and calendar, cancel that day's training

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Call-up and convocation PDF

**Files:**
- Create: `app/Services/Competitions/CallUpCandidates.php`, `app/Http/Controllers/GameCallUpController.php`
- Create: `resources/views/pdf/game-convocation.blade.php`
- Modify: `app/Http/Controllers/GameController.php` (`show()` fills `candidates`), `resources/js/Pages/Games/Show.vue` (call-up tab), `routes/web.php`
- Test: `tests/Feature/Competitions/CallUpTest.php`

**Interfaces:**
- Consumes: `PlayerFlags::injured()`, `PdfService::stream(string $html, string $filename, bool $rtl)`, `ClubHeader::data()`.
- Produces: `CallUpCandidates::for(Game): Collection<int, Player>` (each with a `shirt_number` attribute from the roster); routes `games.call-up.update` and `games.convocation`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/CallUpTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\GamePlayer;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CallUpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_competition_match_calls_up_from_the_visible_squad_and_flags_injured_players(): void
    {
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $competition = $this->competition($team, 2026);
        $competition->players()->attach([$a->id => ['hidden' => false], $b->id => ['hidden' => false], $c->id => ['hidden' => true]]);
        $game = $this->game($team, ['competition_id' => $competition->id]);

        $session = TrainingSession::create([
            'category_id' => $team->category_id, 'date' => now()->subDays(2)->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Extra, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $b->id, 'category_id' => $team->category_id, 'status' => AttendanceStatus::NotTraining->value]);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')
                ->has('candidates', 2)
                ->where('candidates.0.player_id', $a->id)->where('candidates.0.shirt_number', 1)->where('candidates.0.injured', false)
                ->where('candidates.1.player_id', $b->id)->where('candidates.1.injured', true));
    }

    #[Test]
    public function a_friendly_calls_up_from_the_team_roster(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 1)->where('candidates.0.player_id', $a->id));
    }

    #[Test]
    public function the_call_up_is_saved_with_roster_shirts_and_reasons(): void
    {
        $team = $this->team();
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $outsider = $this->player($team->category);
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $game = $this->game($team);
        $admin = $this->admin();
        $save = fn (array $players) => $this->actingAs($admin)->put(route('games.call-up.update', $game), ['players' => $players]);

        $save([['player_id' => $outsider->id, 'called_up' => true]])->assertSessionHasErrors('players.0.player_id');

        $save([
            ['player_id' => $a->id, 'called_up' => true],
            ['player_id' => $b->id, 'called_up' => false, 'not_called_reason' => 'Exams'],
            ['player_id' => $c->id, 'called_up' => false, 'not_called_reason' => ''],
        ])->assertSessionHas('success', 'flash.call_up_saved');

        $rows = GamePlayer::where('game_id', $game->id)->orderBy('player_id')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]->called_up);
        $this->assertSame(1, $rows[0]->shirt_number);
        $this->assertFalse($rows[1]->called_up);
        $this->assertSame('Exams', $rows[1]->not_called_reason);
        $this->assertNull($rows[1]->shirt_number);
    }

    #[Test]
    public function the_call_up_respects_the_formats_maximum(): void
    {
        $team = $this->team();
        $team->defaultFormat->update(['max_called_up' => 1]);
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true], ['player_id' => $b->id, 'called_up' => true],
        ]])->assertSessionHasErrors('players');
        $this->assertSame(0, GamePlayer::count());
    }

    #[Test]
    public function only_the_assigned_coach_saves_the_call_up(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team);
        $coach = $this->coach();

        $this->actingAs($coach)->put(route('games.call-up.update', $game), ['players' => [['player_id' => $a->id, 'called_up' => true]]])
            ->assertForbidden();
    }

    #[Test]
    public function the_convocation_sheet_is_a_pdf(): void
    {
        $team = $this->team();
        $a = $this->player($team->category);
        $this->onRoster($team, 2026, [$a]);
        $game = $this->game($team, ['meeting_time' => '13:30', 'meeting_place' => 'Club house']);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'shirt_number' => 1]);

        $response = $this->actingAs($this->admin())->get(route('games.convocation', $game));
        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=CallUpTest`
Expected: FAIL (`candidates` is empty / `Route [games.call-up.update] not defined`).

- [ ] **Step 3: Write CallUpCandidates**

`app/Services/Competitions/CallUpCandidates.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may be called up for a game: the competition's visible squad, or the
 * team's season roster for a friendly. Each player carries the roster's
 * shirt number; ordered by number, then name.
 */
final class CallUpCandidates
{
    /** @return Collection<int, Player> */
    public function for(Game $game): Collection
    {
        $shirts = DB::table('team_players')
            ->where('team_id', $game->team_id)->where('season_start_year', $game->season_start_year)
            ->pluck('shirt_number', 'player_id');
        $ids = $game->competition_id !== null
            ? DB::table('competition_players')->where('competition_id', $game->competition_id)->where('hidden', false)->pluck('player_id')
            : $shirts->keys();

        return Player::query()->whereIn('id', $ids)->with('category')->get()
            ->each(fn (Player $p) => $p->setAttribute('shirt_number', ($n = $shirts[$p->id] ?? null) === null ? null : (int) $n))
            ->sortBy([
                fn (Player $a, Player $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (Player $a, Player $b) => strcmp($a->fullname, $b->fullname),
            ])->values();
    }
}
```

- [ ] **Step 4: Fill the candidates on the match page**

In `app/Http/Controllers/GameController.php`:
- Add the imports `use App\Models\Player;`, `use App\Services\Competitions\CallUpCandidates;` and `use App\Services\Competitions\PlayerFlags;`.
- Change the `show` signature to `public function show(Request $request, Game $game, CallUpCandidates $candidates, PlayerFlags $flags): Response`.
- Add `'players'` to the `$game->load([...])` list.
- Replace `'candidates' => [],` with:

```php
            'candidates' => (function () use ($game, $candidates, $flags) {
                $injured = $flags->injured();
                $rows = $game->players->keyBy('player_id');

                return $candidates->for($game)->map(fn (Player $p) => [
                    'player_id' => $p->id,
                    'name' => $p->fullname,
                    'category' => $p->category?->localized_name,
                    'shirt_number' => $rows->get($p->id)?->shirt_number ?? $p->shirt_number,
                    'called_up' => (bool) $rows->get($p->id)?->called_up,
                    'not_called_reason' => $rows->get($p->id)?->not_called_reason,
                    'injured' => isset($injured[$p->id]),
                ])->values();
            })(),
```

- [ ] **Step 5: Write the call-up controller**

`app/Http/Controllers/GameCallUpController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Competitions\CallUpCandidates;
use App\Services\Competitions\TeamAccess;
use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GameCallUpController extends Controller
{
    /**
     * Saves who is called up. A row is kept for each called-up player and
     * for each player left out WITH a reason; the rest have no row. A player
     * dropped from the call-up loses any lineup role (phase 2) with it.
     */
    public function update(Request $request, Game $game, CallUpCandidates $candidates): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $allowed = $candidates->for($game)->keyBy('id');
        $data = $request->validate([
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', Rule::in($allowed->keys()->all())],
            'players.*.called_up' => ['required', 'boolean'],
            'players.*.not_called_reason' => ['nullable', 'string', 'max:150'],
        ]);

        $called = collect($data['players'])->filter(fn (array $row) => (bool) $row['called_up'])->count();
        if ($called > $game->format->max_called_up) {
            throw ValidationException::withMessages(['players' => 'comp.error.too_many_called']);
        }

        DB::transaction(function () use ($game, $data, $allowed) {
            $existing = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
            $keep = [];
            foreach ($data['players'] as $row) {
                $calledUp = (bool) $row['called_up'];
                $reason = $calledUp ? null : (trim((string) ($row['not_called_reason'] ?? '')) ?: null);
                if (! $calledUp && $reason === null) {
                    continue;
                }
                $playerId = (int) $row['player_id'];
                $keep[] = $playerId;
                $current = $existing->get($playerId);

                $attributes = ['called_up' => $calledUp, 'not_called_reason' => $reason];
                if ($calledUp && $current?->shirt_number === null) {
                    $attributes['shirt_number'] = $allowed[$playerId]->shirt_number;
                }
                if (! $calledUp) {
                    $attributes += ['shirt_number' => null, 'role' => 'none', 'slot' => null, 'is_captain' => false, 'is_goalkeeper' => false];
                }

                $current
                    ? $current->update($attributes)
                    : GamePlayer::create($attributes + ['game_id' => $game->id, 'player_id' => $playerId]);
            }
            GamePlayer::where('game_id', $game->id)->whereNotIn('player_id', $keep)->delete();
        });

        return back()->with('success', 'flash.call_up_saved');
    }

    /** The convocation sheet to post or share: match, meeting point and the called-up players. */
    public function convocation(Game $game, PdfService $pdf): Response
    {
        $game->load(['team.category', 'competition', 'opponent', 'venue']);
        $locale = app()->getLocale();

        $players = $game->players()->where('called_up', true)->with('player.category')->get()
            ->sortBy([
                fn (GamePlayer $a, GamePlayer $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999),
                fn (GamePlayer $a, GamePlayer $b) => strcmp($a->player->fullname, $b->player->fullname),
            ])
            ->map(fn (GamePlayer $gp) => [
                'shirt' => $gp->shirt_number,
                'name' => $gp->player->fullname,
                'category' => $gp->player->category?->localized_name,
            ])->values()->all();

        $coaches = $game->team->coachesFor($game->season_start_year)->get()
            ->sortBy(fn (User $u) => $u->pivot->role === 'head' ? 0 : 1)
            ->map(fn (User $u) => $u->fullname)->values()->all();

        $html = view('pdf.game-convocation', [
            'club' => ClubHeader::data(),
            'game' => [
                'team' => $game->team->name.' · '.$game->team->category?->localized_name,
                'opponent' => $game->opponent->name,
                'side' => $game->side->value,
                'competition' => $game->competition?->name,
                'round' => $game->round_label,
                'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $game->date)->locale($locale)->translatedFormat('l j F Y'),
                'kickoff' => $game->kickoff_time,
                'meeting_time' => $game->meeting_time,
                'meeting_place' => $game->meeting_place,
                'venue' => $game->venue?->name,
                'address' => $game->venue?->address,
            ],
            'players' => $players,
            'coaches' => $coaches,
        ])->render();

        return $pdf->stream($html, "convocation-{$game->id}-{$game->date}.pdf", $locale === 'ar');
    }
}
```

- [ ] **Step 6: Write the PDF view**

`resources/views/pdf/game-convocation.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin-bottom: 4px; }
        .sub { font-size: 12px; color: #334155; margin-bottom: 2px; }
        table.info { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.info td { padding: 4px 6px; border: 1px solid #cbd5e1; }
        table.info td.label { width: 30%; color: #64748b; background: #f8fafc; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.list th { background: #f1f5f9; padding: 4px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 5px 6px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 14mm; font-weight: bold; }
        .coaches { margin-top: 16px; color: #475569; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.convocation.title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    @if ($game['competition'])
        <div class="sub">{{ $game['competition'] }}@if ($game['round']) · {{ $game['round'] }}@endif</div>
    @endif

    <table class="info">
        <tr><td class="label">{{ $L('date') }}</td><td>{{ $game['dateLabel'] }}</td></tr>
        @if ($game['kickoff'])
            <tr><td class="label">{{ $L('comp.kickoff') }}</td><td><bdi dir="ltr">{{ $game['kickoff'] }}</bdi></td></tr>
        @endif
        @if ($game['meeting_time'] || $game['meeting_place'])
            <tr><td class="label">{{ $L('comp.convocation.meeting') }}</td><td><bdi dir="ltr">{{ $game['meeting_time'] }}</bdi> {{ $game['meeting_place'] }}</td></tr>
        @endif
        @if ($game['venue'])
            <tr><td class="label">{{ $L('comp.venue') }}</td><td>{{ $game['venue'] }}@if ($game['address']) — {{ $game['address'] }}@endif</td></tr>
        @endif
    </table>

    <table class="list">
        <thead>
            <tr>
                <th>{{ $L('comp.shirt') }}</th>
                <th>{{ $L('comp.player') }}</th>
                <th>{{ $L('category') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($players as $player)
                <tr>
                    <td class="num">{{ $player['shirt'] }}</td>
                    <td>{{ $player['name'] }}</td>
                    <td>{{ $player['category'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ $L('comp.convocation.nobody') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($coaches)
        <div class="coaches">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
```

- [ ] **Step 7: Routes**

In `routes/web.php`, add `use App\Http\Controllers\GameCallUpController;`. After the `games.destroy` route, add:

```php
        Route::put('/games/{game}/call-up', [GameCallUpController::class, 'update'])->name('games.call-up.update');
        Route::get('/games/{game}/convocation', [GameCallUpController::class, 'convocation'])->name('games.convocation');
```

- [ ] **Step 8: The call-up tab**

In `resources/js/Pages/Games/Show.vue`, change the Vue import to `import { computed, ref } from 'vue';` and add to `<script setup>`:

```js
const callRows = ref(props.candidates.map((c) => ({ ...c, not_called_reason: c.not_called_reason ?? '' })));
const calledCount = computed(() => callRows.value.filter((r) => r.called_up).length);
const callForm = useForm({ players: [] });

function saveCallUp() {
    callForm.players = callRows.value.map((r) => ({ player_id: r.player_id, called_up: r.called_up, not_called_reason: r.not_called_reason }));
    callForm.put(route('games.call-up.update', props.game.id), { preserveScroll: true });
}
```

Replace the placeholder call-up `<section>` with:

```vue
            <section v-if="tab === 'callup'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm" :class="calledCount > game.format.max_called_up ? 'font-semibold text-rose-600' : 'text-slate-600 dark:text-slate-300'">
                        {{ t('comp.called_count', { n: calledCount, max: game.format.max_called_up }) }}
                    </span>
                    <a :href="route('games.convocation', game.id)" target="_blank" class="text-sm text-primary-600 hover:underline">{{ t('comp.convocation_pdf') }}</a>
                </div>
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="w-10 py-2"></th>
                            <th class="w-12 py-2 text-start">{{ t('comp.shirt') }}</th>
                            <th class="py-2 text-start">{{ t('comp.player') }}</th>
                            <th class="py-2 text-start">{{ t('comp.not_called_reason') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="row in callRows" :key="row.player_id" :class="{ 'bg-primary-50/50 dark:bg-primary-900/10': row.called_up }">
                            <td class="py-2 text-center"><input v-model="row.called_up" :disabled="!canManage" type="checkbox" class="rounded" /></td>
                            <td class="py-2 font-mono text-slate-500">{{ row.shirt_number }}</td>
                            <td class="py-2">
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ row.name }}</span>
                                <span class="ms-2 text-xs text-slate-400">{{ row.category }}</span>
                                <span v-if="row.injured" class="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
                            </td>
                            <td class="py-2">
                                <TextInput v-if="!row.called_up" v-model="row.not_called_reason" :disabled="!canManage" class="w-full" :placeholder="t('comp.not_called_reason_hint')" />
                            </td>
                        </tr>
                        <tr v-if="!callRows.length"><td colspan="4" class="py-6 text-center text-slate-500">{{ t(game.competition ? 'comp.callup_empty_squad' : 'comp.roster_empty') }}</td></tr>
                    </tbody>
                </table>
                <InputError :message="callForm.errors.players ? t(callForm.errors.players) : ''" />
                <PrimaryButton v-if="canManage" :disabled="callForm.processing" @click="saveCallUp">{{ t('save') }}</PrimaryButton>
            </section>
```

- [ ] **Step 9: Translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "comp.called_count": "{n} called up (max {max})", "comp.convocation_pdf": "Convocation sheet (PDF)",
    "comp.not_called_reason": "Reason if not called", "comp.not_called_reason_hint": "Optional — e.g. tactical choice, exams",
    "comp.callup_empty_squad": "The competition squad is empty. Pick the squad on the competition page.",
    "comp.error.too_many_called": "Too many players called up for this game format.",
    "comp.convocation.title": "Match convocation", "comp.convocation.meeting": "Meeting", "comp.convocation.nobody": "No player called up yet.",
    "flash.call_up_saved": "Call-up saved."
  },
  "fr": {
    "comp.called_count": "{n} convoqués (max {max})", "comp.convocation_pdf": "Feuille de convocation (PDF)",
    "comp.not_called_reason": "Motif si non convoqué", "comp.not_called_reason_hint": "Facultatif — ex. choix tactique, examens",
    "comp.callup_empty_squad": "La liste de la compétition est vide. Choisissez-la sur la page de la compétition.",
    "comp.error.too_many_called": "Trop de joueurs convoqués pour ce format de jeu.",
    "comp.convocation.title": "Convocation de match", "comp.convocation.meeting": "Rendez-vous", "comp.convocation.nobody": "Aucun joueur convoqué pour l'instant.",
    "flash.call_up_saved": "Convocation enregistrée."
  },
  "ar": {
    "comp.called_count": "{n} مستدعى (الحد {max})", "comp.convocation_pdf": "ورقة الاستدعاء (PDF)",
    "comp.not_called_reason": "سبب عدم الاستدعاء", "comp.not_called_reason_hint": "اختياري — مثال: خيار فني، امتحانات",
    "comp.callup_empty_squad": "قائمة المنافسة فارغة. اخترها من صفحة المنافسة.",
    "comp.error.too_many_called": "عدد المستدعين يتجاوز حد نظام اللعب.",
    "comp.convocation.title": "استدعاء لمباراة", "comp.convocation.meeting": "التجمع", "comp.convocation.nobody": "لم يُستدعَ أي لاعب بعد.",
    "flash.call_up_saved": "تم حفظ الاستدعاء."
  }
}
EOF
```

Also remove the now-unused `comp.callup_coming` key from all three catalogs:

```bash
node -e "for (const l of ['ar','en','fr']) { const f = 'resources/js/i18n/' + l + '.json'; const j = JSON.parse(require('fs').readFileSync(f, 'utf8')); delete j['comp.callup_coming']; require('fs').writeFileSync(f, JSON.stringify(j, null, 4) + '\n'); }"
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test --filter="CallUpTest|GamesTest|FlashTranslation"`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Competitions/CallUpCandidates.php app/Http/Controllers/GameCallUpController.php app/Http/Controllers/GameController.php resources/views/pdf/game-convocation.blade.php routes/web.php resources/js/Pages/Games/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/CallUpTest.php
git commit -m "feat(competitions): match call-up with injury badges and a printable convocation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Activity log, sidebar and final verification

**Files:**
- Modify: `app/Services/Activity/ActivityAction.php`, `app/Services/Activity/ActivitySubjectLink.php`, `config/activity.php`
- Modify: `resources/js/Composables/useNavigation.js`, `tests/js/navI18n.test.mjs`
- Test: `tests/Feature/Competitions/CompetitionActivityTest.php`

**Interfaces:**
- Consumes: every route from Tasks 6–9.
- Produces: activity codes `team_created`, `team_updated`, `team_deleted`, `team_roster_saved`, `team_coaches_saved`, `competition_created`, `competition_updated`, `competition_deleted`, `squad_saved`, `game_created`, `game_updated`, `game_deleted` and `call_up_saved` in a new `competitions` area; sidebar section `competitions`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/CompetitionActivityTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\ActivityLog;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class CompetitionActivityTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function competition_writes_are_recorded_in_their_own_area(): void
    {
        $team = $this->team();
        $player = $this->player($team->category);
        $this->onRoster($team, 2026, [$player]);
        $game = $this->game($team);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [['player_id' => $player->id, 'called_up' => true]]])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', ActivityAction::CALL_UP_SAVED)->sole();
        $this->assertSame($game->id, (int) $log->subject_id);
        $this->assertContains(ActivityAction::CALL_UP_SAVED, ActivityAction::AREAS['competitions']);
        $this->assertContains(ActivityAction::GAME_CREATED, ActivityAction::ALL);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=CompetitionActivityTest`
Expected: FAIL (`Undefined constant ActivityAction::CALL_UP_SAVED`).

- [ ] **Step 3: Activity codes**

In `app/Services/Activity/ActivityAction.php`, before the `// Edits, deletions, sign-ins and settings` comment, add:

```php
    // Competitions & matches — recorded from the route map in config/activity.php.

    const TEAM_CREATED = 'team_created';

    const TEAM_UPDATED = 'team_updated';

    const TEAM_DELETED = 'team_deleted';

    const TEAM_ROSTER_SAVED = 'team_roster_saved';

    const TEAM_COACHES_SAVED = 'team_coaches_saved';

    const COMPETITION_CREATED = 'competition_created';

    const COMPETITION_UPDATED = 'competition_updated';

    const COMPETITION_DELETED = 'competition_deleted';

    const SQUAD_SAVED = 'squad_saved';

    const GAME_CREATED = 'game_created';

    const GAME_UPDATED = 'game_updated';

    const GAME_DELETED = 'game_deleted';

    const CALL_UP_SAVED = 'call_up_saved';
```

In `ALL`, after `self::INJURY_NOTE_DELETED,`, add the same 13 codes (`self::TEAM_CREATED,` … `self::CALL_UP_SAVED,`). In `AREAS`, after the `'attendance' => [...]` entry, add:

```php
        'competitions' => [
            self::TEAM_CREATED,
            self::TEAM_UPDATED,
            self::TEAM_DELETED,
            self::TEAM_ROSTER_SAVED,
            self::TEAM_COACHES_SAVED,
            self::COMPETITION_CREATED,
            self::COMPETITION_UPDATED,
            self::COMPETITION_DELETED,
            self::SQUAD_SAVED,
            self::GAME_CREATED,
            self::GAME_UPDATED,
            self::GAME_DELETED,
            self::CALL_UP_SAVED,
        ],
```

In `config/activity.php` → `$routes`, before the `// settings` comment, add:

```php
    // competitions & matches
    'teams.store' => ActivityAction::TEAM_CREATED,
    'teams.update' => ActivityAction::TEAM_UPDATED,
    'teams.destroy' => ActivityAction::TEAM_DELETED,
    'teams.roster.update' => ActivityAction::TEAM_ROSTER_SAVED,
    'teams.roster.copy' => ActivityAction::TEAM_ROSTER_SAVED,
    'teams.coaches.update' => ActivityAction::TEAM_COACHES_SAVED,
    'competitions.store' => ActivityAction::COMPETITION_CREATED,
    'competitions.update' => ActivityAction::COMPETITION_UPDATED,
    'competitions.destroy' => ActivityAction::COMPETITION_DELETED,
    'competitions.squad.update' => ActivityAction::SQUAD_SAVED,
    'games.store' => ActivityAction::GAME_CREATED,
    'games.update' => ActivityAction::GAME_UPDATED,
    'games.destroy' => ActivityAction::GAME_DELETED,
    'games.call-up.update' => ActivityAction::CALL_UP_SAVED,
```

In `app/Services/Activity/ActivitySubjectLink.php`, import `App\Models\Competition`, `App\Models\Game` and `App\Models\Team`, and add before `$subject instanceof User =>`:

```php
            $subject instanceof Team => [(string) $subject->name, route('teams.show', $subject)],
            $subject instanceof Competition => [(string) $subject->name, route('competitions.show', $subject)],
            $subject instanceof Game => [
                ((string) $subject->opponent?->name).' · '.$subject->date,
                route('games.show', $subject),
            ],
```

- [ ] **Step 4: Sidebar**

In `resources/js/Composables/useNavigation.js`, after the `members` section, add:

```js
            { key: 'competitions', label: t('nav.competitions'), icon: 'flag', items: [
                { label: t('games'), href: '/games', icon: 'calendar', prefix: '/games', module: 'games' },
                { label: t('teams'), href: '/teams', icon: 'players', prefix: '/teams', module: 'teams' },
                { label: t('competitions'), href: '/competitions', icon: 'flag', prefix: '/competitions', module: 'competitions' },
            ] },
```

In the `config` section's items, after `storage_locations`, add:

```js
                { label: t('game_formats'), href: '/game-formats', icon: 'positions', prefix: '/game-formats', module: 'game_formats' },
                { label: t('opponents'), href: '/opponents', icon: 'flag', prefix: '/opponents', module: 'opponents' },
                { label: t('venues'), href: '/venues', icon: 'location', prefix: '/venues', module: 'venues' },
```

In `tests/js/navI18n.test.mjs`, add `'nav.competitions'` to `KEYS`.

- [ ] **Step 5: Translations**

```bash
node scripts/i18n-add.mjs <<'EOF'
{
  "en": {
    "activity.area.competitions": "Competitions & matches",
    "activity.action.team_created": "Teams created", "activity.action.team_updated": "Teams edited", "activity.action.team_deleted": "Teams deleted",
    "activity.action.team_roster_saved": "Rosters saved", "activity.action.team_coaches_saved": "Team coaches saved",
    "activity.action.competition_created": "Competitions created", "activity.action.competition_updated": "Competitions edited",
    "activity.action.competition_deleted": "Competitions deleted", "activity.action.squad_saved": "Squads saved",
    "activity.action.game_created": "Matches scheduled", "activity.action.game_updated": "Matches edited",
    "activity.action.game_deleted": "Matches deleted", "activity.action.call_up_saved": "Call-ups saved"
  },
  "fr": {
    "activity.area.competitions": "Compétitions et matchs",
    "activity.action.team_created": "Équipes créées", "activity.action.team_updated": "Équipes modifiées", "activity.action.team_deleted": "Équipes supprimées",
    "activity.action.team_roster_saved": "Effectifs enregistrés", "activity.action.team_coaches_saved": "Entraîneurs d'équipe enregistrés",
    "activity.action.competition_created": "Compétitions créées", "activity.action.competition_updated": "Compétitions modifiées",
    "activity.action.competition_deleted": "Compétitions supprimées", "activity.action.squad_saved": "Listes enregistrées",
    "activity.action.game_created": "Matchs programmés", "activity.action.game_updated": "Matchs modifiés",
    "activity.action.game_deleted": "Matchs supprimés", "activity.action.call_up_saved": "Convocations enregistrées"
  },
  "ar": {
    "activity.area.competitions": "المنافسات والمباريات",
    "activity.action.team_created": "فرق أُنشئت", "activity.action.team_updated": "فرق عُدّلت", "activity.action.team_deleted": "فرق حُذفت",
    "activity.action.team_roster_saved": "تشكيلات حُفظت", "activity.action.team_coaches_saved": "مدربو فرق حُفظوا",
    "activity.action.competition_created": "منافسات أُنشئت", "activity.action.competition_updated": "منافسات عُدّلت",
    "activity.action.competition_deleted": "منافسات حُذفت", "activity.action.squad_saved": "قوائم حُفظت",
    "activity.action.game_created": "مباريات بُرمجت", "activity.action.game_updated": "مباريات عُدّلت",
    "activity.action.game_deleted": "مباريات حُذفت", "activity.action.call_up_saved": "استدعاءات حُفظت"
  }
}
EOF
```

- [ ] **Step 6: Run the new test and the activity suite**

Run: `php artisan test --filter="CompetitionActivityTest|Activity|UsersBatch|UserActivityPages"`
Expected: PASS.

- [ ] **Step 7: Full verification**

Run each of these and confirm the result:
- `composer test`: the whole suite passes.
- `npm run i18n:check`: exit 0.
- `npm run test:js`: passes.
- `npm run build`: builds with no errors.
- `vendor/bin/pint --dirty`: no remaining changes, or commit them.

Then click through in the browser, following the project's usual way of running the app (memory: tested at `127.0.0.1:2026`):
1. Game formats: presets are listed; edit one.
2. Opponents: add one with a logo.
3. Teams: create a team, add players with numbers, copy the roster, assign a coach.
4. Competitions: create one and tick the squad.
5. Matches: create a match with "cancel training" and check the attendance calendar.
6. Call-up: tick players, save, open the convocation PDF in Arabic and in French.
7. Log in as a coach user and confirm that another team's pages are read-only.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Activity/ActivityAction.php app/Services/Activity/ActivitySubjectLink.php config/activity.php resources/js/Composables/useNavigation.js tests/js/navI18n.test.mjs resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/CompetitionActivityTest.php
git commit -m "feat(competitions): activity log entries and sidebar section

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Deferred to Phase 2/3 (not in this plan)

- Pitch lineup and formation editor, match report (period scores, events, ratings, man of the match), minutes played, match sheet PDF.
- Suspensions (`suspensions` table, automatic and manual), plus the licence, documents and unpaid warnings (`players.licence_number`, `document_types.required_for_matches`).
- Player "Matches" tab, team and competition stats pages, dashboard widget.
- Desktop build version bump. Do it after Phase 1 is merged, following the NativePHP desktop build memory.
