# Competitions & Matches — Phase 2 (Match day) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Coaches set a line-up on a pitch (drag and drop) and print a line-up sheet. After the match they enter the report: score per period, event timeline, and a per-player grid with minutes, sets, rating, note and man of the match. The app works out minutes played and prints a match report.

**Architecture:**
- Each match carries its own copy of the game rules in `games.rules`, filled when the match is created by a `Game::creating` hook. Every rule is read from the match, never from the format template.
- Pure calculators (`MinutesCalculator`, `ScoreCalculator`) and a `ReportValidator` hold the logic.
- Controllers stay thin. `MatchPage` builds the line-up and report props for `Games/Show`. `MatchSheets` builds the data for the two PDFs.
- The Vue work lives in three new components so `Games/Show.vue` stays small.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`), Inertia + Vue 3 `<script setup>`, Tailwind, HTML5 drag-and-drop (no new dependency), mPDF via `App\Services\Pdf\PdfService`, vue-i18n flat keys.

Spec: `docs/superpowers/specs/2026-10-14-competitions-p2-match-day-design.md`. Phase 1 code: `app/Http/Controllers/Game*Controller.php`, `app/Services/Competitions/*`, `resources/js/Pages/Games/Show.vue`.

## Global Constraints

- Work only in the worktree `D:/irnb-competitions` (branch `feat/competitions-p2`). Never touch `D:/irnb-laravel`.
- Each match's rules come from `games.rules` (a JSON snapshot). Never read rules from `games.game_format_id` after creation.
- Dates are `'Y-m-d'` strings and times `'H:i'` strings. Seasons are `season_start_year` integers.
- Checks fall into two groups. Integrity problems block a save. Warnings never block: they are shown in the UI, either computed client-side for the line-up or by `ReportWarnings` for the report.
- Coach scope: every write calls `TeamAccess::authorize($user, $game->team, $game->season_start_year)`. PDFs need games/view only.
- Ratings and coach notes are never passed to any PDF view.
- No CDN or remote assets. Drawings are inline SVG.
- UI strings go in ar, en and fr through `node .superpowers/sdd/i18n-by-locale.mjs <<'EOF' {"en":{},"fr":{},"ar":{}} EOF`, run from the worktree root. Do not call `scripts/i18n-add.mjs` directly.
  - Use `t()` directly; never `te()` on flat keys.
  - Validation errors are i18n keys (e.g. `comp.error.*`) shown with `t(form.errors[x])`.
  - Flash messages are `flash.*` keys.
- Player names always come from `Player::fullname`.
- Data changes for existing installs go in migrations; the desktop build never seeds.
- After adding or changing `.vue` files, run `npm run build` before the PHP page tests, because Inertia page tests need `public/build/manifest.json`.
- Stage explicit files only. Commit messages end with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `tests/Unit/ActivityRecorderTest.php` hard-codes the number of activity codes. Adding a code means updating that number: 104 now, 105 after Task 3, 106 after Task 5.

---

### Task 1: Each match keeps its own copy of the rules

**Files:**
- Create: `database/migrations/2026_10_16_100001_add_match_rules.php`
- Modify: `app/Models/GameFormat.php`, `app/Models/Game.php`
- Modify: `app/Http/Controllers/GameFormatController.php`, `resources/js/Pages/Settings/GameFormats.vue`
- Modify: `app/Http/Controllers/GameCallUpController.php` (max called-up comes from the rules), `app/Http/Controllers/GameController.php` (show: format props come from the rules)
- Test: `tests/Feature/Competitions/MatchRulesTest.php`

**Interfaces:**
- Produces:
  - `GameFormat::rulesSnapshot(): array`, with the keys `sport, players_on_field, max_bench, max_called_up, max_substitutions, periods_count, period_minutes, period_label, scoring, sets_to_win, has_extra_time, extra_time_minutes, has_shootout, rolling_subs, sanction_types`.
  - `Game::rule(string $key, mixed $default = null): mixed`.
  - `Game::matchLength(?bool $withExtraTime = null): int`. When the argument is null, `extra_time_played` decides.
  - `Game::hasGoalkeeper(): bool`, true for football, futsal and handball.
  - `Game::endsPlay(?string $sanctionKey): bool`.
  - `games.rules` is cast to an array and filled automatically on create.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/MatchRulesTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\Game;
use App\Models\GameFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchRulesTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function a_new_match_copies_its_formats_rules_and_later_format_edits_leave_it_alone(): void
    {
        $team = $this->team();
        $team->defaultFormat->update(['period_minutes' => 30, 'max_substitutions' => 3, 'extra_time_minutes' => 20]);
        $game = $this->game($team);

        $team->defaultFormat->update(['period_minutes' => 40, 'max_called_up' => 5]);
        $game->refresh();

        $this->assertSame(30, $game->rule('period_minutes'));
        $this->assertSame(20, $game->rule('max_called_up'));
        $this->assertSame(3, $game->rule('max_substitutions'));
        $this->assertSame('football', $game->rule('sport'));
        $this->assertSame(60, $game->matchLength());
        $this->assertSame(80, $game->matchLength(true));
        $this->assertTrue($game->hasGoalkeeper());
    }

    #[Test]
    public function the_migration_backfills_rules_for_existing_matches(): void
    {
        $game = $this->game($this->team());
        DB::table('games')->where('id', $game->id)->update(['rules' => null]);

        (require database_path('migrations/2026_10_16_100001_add_match_rules.php'))->backfill();

        $this->assertSame(11, $game->fresh()->rule('players_on_field'));
    }

    #[Test]
    public function presets_know_rolling_substitutions_and_extra_time(): void
    {
        $this->assertTrue((bool) GameFormat::where('name', 'Handball')->value('rolling_subs'));
        $this->assertFalse((bool) GameFormat::where('name', 'Football 11')->value('rolling_subs'));
        $this->assertSame(5, GameFormat::where('name', 'Football 11')->value('max_substitutions'));
        $this->assertSame(30, GameFormat::where('name', 'Football 11')->value('extra_time_minutes'));
    }

    #[Test]
    public function the_call_up_limit_comes_from_the_matchs_rules(): void
    {
        $team = $this->team();
        [$a, $b] = [$this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b]);
        $game = $this->game($team);
        $game->update(['rules' => ['max_called_up' => 1] + $game->rules]);
        $team->defaultFormat->update(['max_called_up' => 30]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true], ['player_id' => $b->id, 'called_up' => true],
        ]])->assertSessionHasErrors('players');
    }

    #[Test]
    public function the_format_page_saves_the_new_rule_fields(): void
    {
        $format = $this->format();
        $this->actingAs($this->admin())->put(route('game-formats.update', $format), [
            'branch_id' => $format->branch_id, 'sport' => 'football', 'name' => 'Test 11',
            'players_on_field' => 11, 'max_bench' => 9, 'max_called_up' => 20,
            'periods_count' => 2, 'period_minutes' => 45, 'period_label' => 'half', 'scoring' => 'goals',
            'has_extra_time' => true, 'extra_time_minutes' => 30, 'max_substitutions' => 5, 'rolling_subs' => false,
            'has_shootout' => true, 'active' => true, 'sanction_types' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(30, $format->fresh()->extra_time_minutes);
        $this->assertSame(5, $format->fresh()->max_substitutions);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=MatchRulesTest`
Expected: FAIL, with an unknown column `max_substitutions` or an undefined method `rule`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_16_100001_add_match_rules.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (match day). Formats learn three rules (rolling substitutions, a
 * substitution limit, extra-time length), and every match gets its own copy
 * of the rules it is played under (`games.rules`), so editing a format later
 * never rewrites a played match. Existing matches are backfilled from their
 * format. The column stays nullable at the database level (sqlite cannot
 * tighten it in place); Game::creating always fills it.
 */
return new class extends Migration
{
    private const ROLLING = ['Futsal', 'Handball', 'Basketball', 'Basketball 3x3', 'Volleyball'];

    private const EXTRA_TIME = ['Football 11' => 30, 'Futsal' => 10, 'Handball' => 10, 'Basketball' => 5];

    public function up(): void
    {
        Schema::table('game_formats', function (Blueprint $table) {
            $table->boolean('rolling_subs')->default(false)->after('has_shootout');
            $table->unsignedTinyInteger('max_substitutions')->nullable()->after('max_called_up');
            $table->unsignedTinyInteger('extra_time_minutes')->default(0)->after('has_extra_time');
        });
        Schema::table('games', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('game_format_id');
        });

        DB::table('game_formats')->where('is_preset', true)->whereIn('name', self::ROLLING)->update(['rolling_subs' => true]);
        DB::table('game_formats')->where('is_preset', true)->where('name', 'Football 11')->update(['max_substitutions' => 5]);
        foreach (self::EXTRA_TIME as $name => $minutes) {
            DB::table('game_formats')->where('is_preset', true)->where('name', $name)->update(['extra_time_minutes' => $minutes]);
        }

        $this->backfill();
    }

    /** Fills `rules` for every match that has none, from its format. Public so a test can re-run it. */
    public function backfill(): void
    {
        $formats = DB::table('game_formats')->get()->keyBy('id');
        DB::table('games')->whereNull('rules')->orderBy('id')->get(['id', 'game_format_id'])
            ->each(function ($game) use ($formats) {
                $f = $formats->get($game->game_format_id);
                if ($f === null) {
                    return;
                }
                DB::table('games')->where('id', $game->id)->update(['rules' => json_encode([
                    'sport' => $f->sport,
                    'players_on_field' => (int) $f->players_on_field,
                    'max_bench' => (int) $f->max_bench,
                    'max_called_up' => (int) $f->max_called_up,
                    'max_substitutions' => $f->max_substitutions === null ? null : (int) $f->max_substitutions,
                    'periods_count' => (int) $f->periods_count,
                    'period_minutes' => (int) $f->period_minutes,
                    'period_label' => $f->period_label,
                    'scoring' => $f->scoring,
                    'sets_to_win' => $f->sets_to_win === null ? null : (int) $f->sets_to_win,
                    'has_extra_time' => (bool) $f->has_extra_time,
                    'extra_time_minutes' => (int) $f->extra_time_minutes,
                    'has_shootout' => (bool) $f->has_shootout,
                    'rolling_subs' => (bool) $f->rolling_subs,
                    'sanction_types' => json_decode((string) $f->sanction_types, true) ?: [],
                ])]);
            });
    }

    public function down(): void
    {
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('rules'));
        Schema::table('game_formats', function (Blueprint $table) {
            $table->dropColumn(['rolling_subs', 'max_substitutions', 'extra_time_minutes']);
        });
    }
};
```

- [ ] **Step 4: Models**

In `app/Models/GameFormat.php`:
- In `$fillable`, add `'max_substitutions'` after `'max_called_up'`, `'extra_time_minutes'` after `'has_extra_time'`, and `'rolling_subs'` after `'has_shootout'`.
- In `casts()`, add `'rolling_subs' => 'boolean',`, `'max_substitutions' => 'integer',` and `'extra_time_minutes' => 'integer',`.
- Add this method:

```php
    /** The rules a new match copies onto itself (games.rules), so later edits here never rewrite it. */
    public function rulesSnapshot(): array
    {
        return [
            'sport' => $this->sport->value,
            'players_on_field' => $this->players_on_field,
            'max_bench' => $this->max_bench,
            'max_called_up' => $this->max_called_up,
            'max_substitutions' => $this->max_substitutions,
            'periods_count' => $this->periods_count,
            'period_minutes' => $this->period_minutes,
            'period_label' => $this->period_label->value,
            'scoring' => $this->scoring->value,
            'sets_to_win' => $this->sets_to_win,
            'has_extra_time' => $this->has_extra_time,
            'extra_time_minutes' => $this->extra_time_minutes,
            'has_shootout' => $this->has_shootout,
            'rolling_subs' => $this->rolling_subs,
            'sanction_types' => $this->sanction_types ?? [],
        ];
    }
```

In `app/Models/Game.php`:
- Add `'rules'` to `$fillable` after `'game_format_id'`.
- Add `'rules' => 'array',` to `casts()`.
- Replace the class docblock's sentence about freezing with: "Its game rules are copied from the format (competition override, else team default) into `rules` at creation, so later edits to the format never rewrite history."
- Add:

```php
    protected static function booted(): void
    {
        static::creating(function (Game $game) {
            if (empty($game->rules) && $game->game_format_id) {
                $game->rules = GameFormat::findOrFail($game->game_format_id)->rulesSnapshot();
            }
        });
    }

    /** One rule of this match, from its own copy (never from the format template). */
    public function rule(string $key, mixed $default = null): mixed
    {
        return ($this->rules ?? [])[$key] ?? $default;
    }

    /** Regulation time, plus extra time when it was (or, given true, would be) played. */
    public function matchLength(?bool $withExtraTime = null): int
    {
        $withExtraTime ??= (bool) $this->extra_time_played;

        return (int) $this->rule('periods_count', 0) * (int) $this->rule('period_minutes', 0)
            + ($withExtraTime ? (int) $this->rule('extra_time_minutes', 0) : 0);
    }

    /** Sports with a goalkeeper role on the line-up. */
    public function hasGoalkeeper(): bool
    {
        return in_array($this->rule('sport'), ['football', 'futsal', 'handball'], true);
    }

    /** Whether a sanction of this kind sends the player off (ends their minutes). */
    public function endsPlay(?string $sanctionKey): bool
    {
        foreach ($this->rule('sanction_types', []) as $type) {
            if (($type['key'] ?? null) === $sanctionKey) {
                return (bool) ($type['ends_play'] ?? false);
            }
        }

        return false;
    }
```

- [ ] **Step 5: Read rules from the match**

In `app/Http/Controllers/GameCallUpController.php` → `update`, change `$game->format->max_called_up` to `(int) $game->rule('max_called_up')`.

In `app/Http/Controllers/GameController.php` → `show`, replace the `'format' => [...]` entry with:

```php
                'format' => [
                    'name' => $game->format->localized_name,
                    'players_on_field' => (int) $game->rule('players_on_field'),
                    'max_called_up' => (int) $game->rule('max_called_up'),
                ],
```

- [ ] **Step 6: Format settings fields**

In `app/Http/Controllers/GameFormatController.php` → `validated()`, add to the rules array:

```php
            'max_substitutions' => ['nullable', 'integer', 'min:1', 'max:30'],
            'extra_time_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'rolling_subs' => ['sometimes', 'boolean'],
```

Change the flags loop to `foreach (['has_extra_time', 'has_shootout', 'rolling_subs', 'active'] as $flag)`. After it, add:

```php
        $data['extra_time_minutes'] = $data['has_extra_time'] ? (int) ($data['extra_time_minutes'] ?? 0) : 0;
```

In `resources/js/Pages/Settings/GameFormats.vue`:
- In `blank()`, add `max_substitutions: null, extra_time_minutes: 0, rolling_subs: false,`.
- In the second grid, after the `max_called_up` label, add:

```vue
                    <label class="text-sm">{{ t('comp.max_substitutions') }}
                        <TextInput v-model.number="form.max_substitutions" type="number" min="1" class="mt-1 w-full" :placeholder="t('comp.no_limit')" />
                    </label>
```

- After the `has_extra_time` checkbox label, add:

```vue
                    <label v-if="form.has_extra_time" class="flex items-center gap-2">{{ t('comp.extra_time_minutes') }}
                        <TextInput v-model.number="form.extra_time_minutes" type="number" min="0" class="w-20" />
                    </label>
                    <label class="flex items-center gap-2"><input v-model="form.rolling_subs" type="checkbox" class="rounded" /> {{ t('comp.rolling_subs') }}</label>
```

- In `save()`, transform the form so an empty `max_substitutions` is sent as null. Replace the post/put lines with:

```js
    const send = form.transform((d) => ({ ...d, max_substitutions: d.max_substitutions || null }));
    if (editing.value === 'new') send.post(route('game-formats.store'), options);
    else send.put(route('game-formats.update', editing.value.id), options);
```

- [ ] **Step 7: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.max_substitutions": "Max substitutions", "comp.no_limit": "No limit", "comp.extra_time_minutes": "Extra-time minutes", "comp.rolling_subs": "Rolling substitutions (players go on and off freely)"},
  "fr": {"comp.max_substitutions": "Remplacements max", "comp.no_limit": "Sans limite", "comp.extra_time_minutes": "Minutes de prolongation", "comp.rolling_subs": "Changements volants (entrées et sorties libres)"},
  "ar": {"comp.max_substitutions": "أقصى عدد للتغييرات", "comp.no_limit": "بدون حد", "comp.extra_time_minutes": "دقائق الوقت الإضافي", "comp.rolling_subs": "تغييرات حرة (دخول وخروج بلا قيود)"}
}
EOF
```

- [ ] **Step 8: Run the tests**

Run: `npm run build`, then `php artisan test --filter="MatchRulesTest|CallUpTest|GameFormatSettingsTest|GamesTest|GameFormatPresetsTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_10_16_100001_add_match_rules.php app/Models/GameFormat.php app/Models/Game.php app/Http/Controllers/GameFormatController.php resources/js/Pages/Settings/GameFormats.vue app/Http/Controllers/GameCallUpController.php app/Http/Controllers/GameController.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/MatchRulesTest.php
git commit -m "feat(competitions): every match keeps its own copy of the game rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Events table and the pure calculators

**Files:**
- Create: `database/migrations/2026_10_16_100002_create_game_events_table.php`
- Create: `app/Models/GameEvent.php`, `app/Services/Competitions/MinutesCalculator.php`, `app/Services/Competitions/ScoreCalculator.php`
- Modify: `app/Models/Game.php` (`events()`), `app/Models/GamePlayer.php` (new columns)
- Test: `tests/Unit/MinutesCalculatorTest.php`, `tests/Unit/ScoreCalculatorTest.php`

**Interfaces:**
- Produces:
  - Table `game_events`.
  - New `game_players` columns: `pos_x`, `pos_y`, `sets_played`, `played`, `shirt_override`.
  - `GameEvent::TYPES = ['goal','sanction','substitution']` and `GameEvent::GOAL_KINDS = ['normal','penalty','free_kick','opponent_own_goal']`.
  - `Game::events(): HasMany`.
  - `MinutesCalculator::compute(list<int> $starters, list<array{type:string, minute:?int, player_id:?int, related_player_id:?int, ends_play:bool}> $events, int $length): array{minutes: array<int,int>, appeared: list<int>}`.
  - `ScoreCalculator::derive(list<?array{0:?int,1:?int}> $rows, string $scoring): ?array{0:int,1:int}`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/MinutesCalculatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Competitions\MinutesCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MinutesCalculatorTest extends TestCase
{
    private function sub(int $minute, int $in, int $out): array
    {
        return ['type' => 'substitution', 'minute' => $minute, 'player_id' => $in, 'related_player_id' => $out, 'ends_play' => false];
    }

    private function red(int $minute, int $player): array
    {
        return ['type' => 'sanction', 'minute' => $minute, 'player_id' => $player, 'related_player_id' => null, 'ends_play' => true];
    }

    #[Test]
    public function starters_play_the_whole_match_without_events(): void
    {
        $result = MinutesCalculator::compute([1, 2], [], 90);

        $this->assertSame([1 => 90, 2 => 90], $result['minutes']);
        $this->assertSame([1, 2], $result['appeared']);
    }

    #[Test]
    public function substitutions_split_minutes_and_a_double_change_chains(): void
    {
        $result = MinutesCalculator::compute([1, 2], [$this->sub(80, 4, 3), $this->sub(60, 3, 1)], 90);

        $this->assertSame(60, $result['minutes'][1]);
        $this->assertSame(90, $result['minutes'][2]);
        $this->assertSame(20, $result['minutes'][3]);
        $this->assertSame(10, $result['minutes'][4]);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $result['appeared']);
    }

    #[Test]
    public function a_send_off_stops_the_clock_and_extra_time_counts(): void
    {
        $result = MinutesCalculator::compute([1, 2], [$this->red(70, 2)], 120);

        $this->assertSame(120, $result['minutes'][1]);
        $this->assertSame(70, $result['minutes'][2]);
    }

    #[Test]
    public function events_without_a_minute_or_beyond_the_end_are_handled(): void
    {
        $result = MinutesCalculator::compute([1], [
            ['type' => 'goal', 'minute' => null, 'player_id' => 1, 'related_player_id' => null, 'ends_play' => false],
            $this->sub(95, 5, 1),
        ], 90);

        $this->assertSame(90, $result['minutes'][1]);
        $this->assertSame(0, $result['minutes'][5]);
        $this->assertContains(5, $result['appeared']);
    }
}
```

`tests/Unit/ScoreCalculatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Competitions\ScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScoreCalculatorTest extends TestCase
{
    #[Test]
    public function goals_and_points_are_summed_over_filled_rows(): void
    {
        $this->assertSame([3, 1], ScoreCalculator::derive([[1, 0], [2, 1]], 'goals'));
        $this->assertSame([2, 2], ScoreCalculator::derive([[2, 2], [null, null], null], 'points'));
    }

    #[Test]
    public function sets_count_who_won_each_set(): void
    {
        $this->assertSame([3, 1], ScoreCalculator::derive([[25, 20], [22, 25], [25, 18], [25, 23], [null, null]], 'sets'));
    }

    #[Test]
    public function no_filled_row_means_no_derived_score(): void
    {
        $this->assertNull(ScoreCalculator::derive([[null, null], [1, null]], 'goals'));
        $this->assertNull(ScoreCalculator::derive([], 'goals'));
    }
}
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter="MinutesCalculatorTest|ScoreCalculatorTest"`
Expected: FAIL, with class not found.

- [ ] **Step 3: Write the calculators**

`app/Services/Competitions/MinutesCalculator.php`:

```php
<?php

namespace App\Services\Competitions;

/**
 * Minutes played from the line-up and the timeline (formats without rolling
 * substitutions). A starter plays from 0; a substitute from coming on; either
 * stops when subbed off or sent off, else at the end. Events without a minute
 * are ignored; a minute past the end counts as the end.
 */
final class MinutesCalculator
{
    /**
     * @param  list<int>  $starters
     * @param  list<array{type: string, minute: ?int, player_id: ?int, related_player_id: ?int, ends_play: bool}>  $events
     * @return array{minutes: array<int, int>, appeared: list<int>}
     */
    public static function compute(array $starters, array $events, int $length): array
    {
        $since = array_fill_keys($starters, 0);
        $minutes = array_fill_keys($starters, 0);
        $appeared = array_fill_keys($starters, true);

        $steps = array_values(array_filter($events, fn (array $e) => $e['minute'] !== null
            && ($e['type'] === 'substitution' || ($e['type'] === 'sanction' && $e['ends_play']))));
        usort($steps, fn (array $a, array $b) => $a['minute'] <=> $b['minute']);

        foreach ($steps as $event) {
            $at = min((int) $event['minute'], $length);
            $leaving = $event['type'] === 'substitution' ? $event['related_player_id'] : $event['player_id'];
            if ($leaving !== null && array_key_exists($leaving, $since)) {
                $minutes[$leaving] = ($minutes[$leaving] ?? 0) + $at - $since[$leaving];
                unset($since[$leaving]);
            }
            if ($event['type'] === 'substitution' && $event['player_id'] !== null) {
                $since[$event['player_id']] = $at;
                $minutes[$event['player_id']] ??= 0;
                $appeared[$event['player_id']] = true;
            }
        }

        foreach ($since as $playerId => $from) {
            $minutes[$playerId] += $length - $from;
        }

        return ['minutes' => $minutes, 'appeared' => array_keys($appeared)];
    }
}
```

`app/Services/Competitions/ScoreCalculator.php`:

```php
<?php

namespace App\Services\Competitions;

/** The final score from per-period rows: summed for goals/points, sets won for volleyball. */
final class ScoreCalculator
{
    /**
     * @param  list<?array{0: ?int, 1: ?int}>  $rows  one [us, them] per period (and extra time); empty rows are skipped
     * @return array{0: int, 1: int}|null null when no row is filled in
     */
    public static function derive(array $rows, string $scoring): ?array
    {
        $filled = array_values(array_filter($rows, fn ($row) => is_array($row) && isset($row[0], $row[1])));
        if ($filled === []) {
            return null;
        }
        if ($scoring === 'sets') {
            return [
                count(array_filter($filled, fn (array $r) => $r[0] > $r[1])),
                count(array_filter($filled, fn (array $r) => $r[1] > $r[0])),
            ];
        }

        return [array_sum(array_column($filled, 0)), array_sum(array_column($filled, 1))];
    }
}
```

- [ ] **Step 4: Schema and models**

`database/migrations/2026_10_16_100002_create_game_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Match day: the report's timeline, and line-up / report fields per player. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('subtype', 32)->nullable();
            $table->unsignedTinyInteger('period')->nullable();
            $table->unsignedSmallInteger('minute')->nullable();
            $table->foreignId('player_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('related_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamps();
            $table->index(['game_id', 'minute']);
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->unsignedTinyInteger('pos_x')->nullable()->after('slot');
            $table->unsignedTinyInteger('pos_y')->nullable()->after('pos_x');
            $table->boolean('shirt_override')->default(false)->after('shirt_number');
            $table->unsignedTinyInteger('sets_played')->nullable()->after('minutes_played');
            $table->boolean('played')->default(false)->after('sets_played');
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y', 'shirt_override', 'sets_played', 'played']);
        });
        Schema::dropIfExists('game_events');
    }
};
```

`app/Models/GameEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a match report's timeline: a goal (scorer + assist), a sanction
 * (subtype = a key of the match's sanction_types), or a substitution
 * (player_id comes on, related_player_id goes off). An opponent's own goal
 * is our goal with no scorer.
 */
class GameEvent extends Model
{
    public const TYPES = ['goal', 'sanction', 'substitution'];

    public const GOAL_KINDS = ['normal', 'penalty', 'free_kick', 'opponent_own_goal'];

    protected $fillable = ['game_id', 'type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id'];

    protected function casts(): array
    {
        return ['period' => 'integer', 'minute' => 'integer'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function relatedPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'related_player_id');
    }
}
```

In `app/Models/Game.php`, add:

```php
    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }
```

In `app/Models/GamePlayer.php`:
- Add `'pos_x', 'pos_y', 'shirt_override', 'sets_played', 'played'` to `$fillable`.
- Add `'shirt_override' => 'boolean', 'played' => 'boolean', 'pos_x' => 'integer', 'pos_y' => 'integer', 'sets_played' => 'integer', 'minutes_played' => 'integer', 'rating' => 'integer', 'slot' => 'integer', 'shirt_number' => 'integer'` to `casts()`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="MinutesCalculatorTest|ScoreCalculatorTest|Competition"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_16_100002_create_game_events_table.php app/Models/GameEvent.php app/Models/Game.php app/Models/GamePlayer.php app/Services/Competitions/MinutesCalculator.php app/Services/Competitions/ScoreCalculator.php tests/Unit/MinutesCalculatorTest.php tests/Unit/ScoreCalculatorTest.php
git commit -m "feat(competitions): match events table, minutes and score calculators

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Line-up backend

**Files:**
- Create: `app/Services/Competitions/MatchPage.php`, `app/Services/Competitions/GameMinutes.php`, `app/Http/Controllers/GameLineupController.php`
- Modify:
  - `app/Http/Controllers/GameController.php`: `show` passes `lineup`.
  - `app/Http/Controllers/GameCallUpController.php`: keeps per-match shirt overrides, and deletes the events of players who are un-called.
  - `routes/web.php`, `config/activity.php`, `app/Services/Activity/ActivityAction.php`, `tests/Unit/ActivityRecorderTest.php` (count 104 → 105).
- Test: `tests/Feature/Competitions/LineupTest.php`

**Interfaces:**
- Consumes: `Game::rule()`, `Game::hasGoalkeeper()`, `Game::matchLength()`, `Game::endsPlay()`, `Game::events()`, `MinutesCalculator::compute()`, `PlayerFlags::injured()`, `TeamAccess::authorize()`.
- Produces:
  - `MatchPage::lineup(Game): array`, with the shape `{formations: [{id,name,slots}], formation_id, has_goalkeeper, players_on_field, max_bench, sport, players: [{player_id, name, shirt_number, usual_position, injured, role ('starter'|'bench'), slot, pos_x, pos_y, is_captain, is_goalkeeper}]}`.
  - `GameMinutes::refresh(Game): void`.
  - Route `games.lineup.update`: `PUT /games/{game}/lineup`, payload `{formation_id: ?int, players: [{player_id, role, slot, pos_x, pos_y, shirt_number, is_captain, is_goalkeeper}]}`.
  - `ActivityAction::LINEUP_SAVED`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/LineupTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class LineupTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    /** A team with a 3-slot formation and $n called-up players. */
    private function matchWithCallUp(int $n = 4): array
    {
        $team = $this->team();
        $team->defaultFormat->update(['players_on_field' => 3, 'max_bench' => 1]);
        $formation = Formation::create(['game_format_id' => $team->default_game_format_id, 'name' => '1-2', 'slots' => [
            ['label' => 'GK', 'x' => 50, 'y' => 5], ['label' => 'LB', 'x' => 30, 'y' => 30], ['label' => 'ST', 'x' => 50, 'y' => 80],
        ]]);
        $players = array_map(fn () => $this->player($team->category), range(1, $n));
        $this->onRoster($team, 2026, $players);
        $game = $this->game($team);
        foreach ($players as $i => $p) {
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true, 'shirt_number' => $i + 1]);
        }

        return [$game, $formation, $players, $team];
    }

    private function row($player, string $role, ?int $slot = null, array $extra = []): array
    {
        return $extra + ['player_id' => $player->id, 'role' => $role, 'slot' => $slot, 'pos_x' => null, 'pos_y' => null,
            'shirt_number' => null, 'is_captain' => false, 'is_goalkeeper' => false];
    }

    #[Test]
    public function the_match_page_carries_the_line_up_with_formations_and_the_bench(): void
    {
        [$game, $formation, $players] = $this->matchWithCallUp(2);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Games/Show')
                ->where('lineup.players_on_field', 3)
                ->where('lineup.has_goalkeeper', true)
                ->where('lineup.formations.0.id', $formation->id)
                ->has('lineup.players', 2)
                ->where('lineup.players.0.role', 'bench'));
    }

    #[Test]
    public function the_line_up_is_saved_with_slot_positions_shirt_overrides_and_free_placement(): void
    {
        [$game, $formation, [$a, $b, $c, $d], $team] = $this->matchWithCallUp();
        $lb = Position::create(['name' => 'Left back', 'abbreviation' => 'LB', 'branch_id' => $team->branch_id]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0, ['is_goalkeeper' => true, 'shirt_number' => 1]),
            $this->row($b, 'starter', 1, ['pos_x' => 20, 'pos_y' => 40, 'shirt_number' => 14]),
            $this->row($c, 'starter', 2, ['is_captain' => true, 'shirt_number' => 3]),
            $this->row($d, 'bench'),
        ]])->assertSessionHas('success', 'flash.lineup_saved');

        $bRow = GamePlayer::where('game_id', $game->id)->where('player_id', $b->id)->sole();
        $this->assertSame('starter', $bRow->role);
        $this->assertSame(1, $bRow->slot);
        $this->assertSame([20, 40], [$bRow->pos_x, $bRow->pos_y]);
        $this->assertSame($lb->id, $bRow->position_id);
        $this->assertSame(14, $bRow->shirt_number);
        $this->assertTrue($bRow->shirt_override);
        $this->assertFalse(GamePlayer::where('player_id', $a->id)->sole()->shirt_override);
        $this->assertSame($formation->id, $game->fresh()->formation_id);
    }

    #[Test]
    public function line_up_integrity_rules_block_the_save(): void
    {
        [$game, $formation, [$a, $b, $c, $d]] = $this->matchWithCallUp();
        $outsider = $this->player($game->team->category);
        $admin = $this->admin();
        $save = fn (array $players) => $this->actingAs($admin)->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => $players]);

        $save([$this->row($a, 'starter', 0), $this->row($b, 'starter', 1), $this->row($c, 'starter', 2), $this->row($d, 'starter', 2)])
            ->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0), $this->row($b, 'starter', 0)])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['is_captain' => true]), $this->row($b, 'starter', 1, ['is_captain' => true])])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['is_goalkeeper' => true]), $this->row($b, 'starter', 1, ['is_goalkeeper' => true])])->assertSessionHasErrors('players');
        $save([$this->row($a, 'starter', 0, ['shirt_number' => 7]), $this->row($b, 'starter', 1, ['shirt_number' => 7])])->assertSessionHasErrors('players.1.shirt_number');
        $save([$this->row($outsider, 'starter', 0)])->assertSessionHasErrors('players.0.player_id');
    }

    #[Test]
    public function players_left_out_of_the_payload_go_to_the_bench(): void
    {
        [$game, $formation, [$a, $b]] = $this->matchWithCallUp(2);
        GamePlayer::where('player_id', $b->id)->update(['role' => 'starter', 'slot' => 2]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0),
        ]])->assertSessionHasNoErrors();

        $this->assertSame('bench', GamePlayer::where('player_id', $b->id)->sole()->role);
        $this->assertNull(GamePlayer::where('player_id', $b->id)->sole()->slot);
    }

    #[Test]
    public function a_saved_line_up_recomputes_minutes_once_the_match_has_a_report(): void
    {
        [$game, $formation, [$a, $b, $c, $d]] = $this->matchWithCallUp();
        $game->update(['status' => GameStatus::Played, 'our_score' => 0, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'substitution', 'minute' => 60, 'player_id' => $d->id, 'related_player_id' => $c->id]);

        $this->actingAs($this->admin())->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [
            $this->row($a, 'starter', 0), $this->row($b, 'starter', 1), $this->row($c, 'starter', 2), $this->row($d, 'bench'),
        ]])->assertSessionHasNoErrors();

        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertSame(90, $rows[$a->id]->minutes_played);
        $this->assertSame(60, $rows[$c->id]->minutes_played);
        $this->assertSame(30, $rows[$d->id]->minutes_played);
        $this->assertTrue($rows[$d->id]->played);
    }

    #[Test]
    public function only_the_assigned_coach_saves_the_line_up(): void
    {
        [$game, $formation, [$a]] = $this->matchWithCallUp(1);
        $coach = $this->coach();

        $this->actingAs($coach)->put(route('games.lineup.update', $game), ['formation_id' => $formation->id, 'players' => [$this->row($a, 'bench')]])
            ->assertForbidden();
    }

    #[Test]
    public function the_call_up_keeps_a_per_match_shirt_and_un_calling_deletes_the_players_events(): void
    {
        [$game, , [$a, $b]] = $this->matchWithCallUp(2);
        GamePlayer::where('player_id', $a->id)->update(['shirt_number' => 9, 'shirt_override' => true]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $b->id, 'related_player_id' => $a->id]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id, 'related_player_id' => $b->id]);

        $this->actingAs($this->admin())->put(route('games.call-up.update', $game), ['players' => [
            ['player_id' => $a->id, 'called_up' => true],
            ['player_id' => $b->id, 'called_up' => false],
        ]])->assertSessionHas('success', 'flash.call_up_saved_events_removed');

        $this->assertSame(9, GamePlayer::where('player_id', $a->id)->sole()->shirt_number);
        $events = GameEvent::where('game_id', $game->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame($a->id, $events[0]->player_id);
        $this->assertNull($events[0]->related_player_id);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=LineupTest`
Expected: FAIL (`Route [games.lineup.update] not defined`, missing `lineup` prop).

- [ ] **Step 3: Write GameMinutes and MatchPage**

`app/Services/Competitions/GameMinutes.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;

/**
 * Stores minutes played and appearances for a match from its line-up and
 * timeline. Rolling-substitution formats are skipped: there the coach types
 * minutes and ticks "played" in the report grid.
 */
final class GameMinutes
{
    public function refresh(Game $game): void
    {
        if ($game->rule('rolling_subs')) {
            return;
        }
        $players = $game->players()->where('called_up', true)->get();
        $starters = $players->where('role', 'starter')->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $events = $game->events()->get()->map(fn (GameEvent $e) => [
            'type' => $e->type,
            'minute' => $e->minute,
            'player_id' => $e->player_id,
            'related_player_id' => $e->related_player_id,
            'ends_play' => $e->type === 'sanction' && $game->endsPlay($e->subtype),
        ])->all();

        $result = MinutesCalculator::compute($starters, $events, $game->matchLength());
        $appeared = array_flip($result['appeared']);

        $players->each(fn (GamePlayer $gp) => $gp->update([
            'minutes_played' => $result['minutes'][$gp->player_id] ?? 0,
            'played' => isset($appeared[$gp->player_id]),
        ]));
    }
}
```

`app/Services/Competitions/MatchPage.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Formation;
use App\Models\Game;
use App\Models\GamePlayer;

/** The match page's line-up (and, from the report task, report) props. */
final class MatchPage
{
    public function __construct(private readonly PlayerFlags $flags) {}

    /** @return array<string, mixed> */
    public function lineup(Game $game): array
    {
        $game->loadMissing(['format.formations', 'players.player.position']);
        $injured = $this->flags->injured();

        return [
            'formations' => $game->format->formations
                ->map(fn (Formation $f) => ['id' => $f->id, 'name' => $f->name, 'slots' => $f->slots])->values(),
            'formation_id' => $game->formation_id,
            'has_goalkeeper' => $game->hasGoalkeeper(),
            'players_on_field' => (int) $game->rule('players_on_field'),
            'max_bench' => (int) $game->rule('max_bench'),
            'sport' => $game->rule('sport'),
            'players' => $game->players->where('called_up', true)
                ->sortBy(fn (GamePlayer $gp) => $gp->shirt_number ?? 999)
                ->map(fn (GamePlayer $gp) => [
                    'player_id' => $gp->player_id,
                    'name' => $gp->player->fullname,
                    'shirt_number' => $gp->shirt_number,
                    'usual_position' => $gp->player->position?->abbreviation,
                    'injured' => isset($injured[$gp->player_id]),
                    'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                    'slot' => $gp->slot,
                    'pos_x' => $gp->pos_x,
                    'pos_y' => $gp->pos_y,
                    'is_captain' => $gp->is_captain,
                    'is_goalkeeper' => $gp->is_goalkeeper,
                ])->values(),
        ];
    }
}
```

- [ ] **Step 4: Write the line-up controller**

`app/Http/Controllers/GameLineupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Position;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameLineupController extends Controller
{
    /**
     * Saves the line-up: starters with their slot (and free placement, shirt,
     * captain, goalkeeper); every other called-up player is on the bench.
     * Limits are blocking only where they break the match (too many starters,
     * a slot or number used twice); "too few" is a warning shown on the page.
     */
    public function update(Request $request, Game $game, GameMinutes $minutes): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $called = $game->players()->where('called_up', true)->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'formation_id' => ['nullable', 'integer', Rule::exists('formations', 'id')->where('game_format_id', $game->game_format_id)],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct', Rule::in($called)],
            'players.*.role' => ['required', Rule::in(['starter', 'bench'])],
            'players.*.slot' => ['nullable', 'integer', 'min:0', 'max:29'],
            'players.*.pos_x' => ['nullable', 'integer', 'min:0', 'max:100'],
            'players.*.pos_y' => ['nullable', 'integer', 'min:0', 'max:100'],
            'players.*.shirt_number' => ['nullable', 'integer', 'min:0', 'max:99', 'distinct'],
            'players.*.is_captain' => ['sometimes', 'boolean'],
            'players.*.is_goalkeeper' => ['sometimes', 'boolean'],
        ]);

        $rows = collect($data['players']);
        $starters = $rows->where('role', 'starter');
        $slots = $starters->pluck('slot')->filter(fn ($slot) => $slot !== null);
        $goalkeepers = $rows->filter(fn (array $r) => ! empty($r['is_goalkeeper']))->count();
        $error = match (true) {
            $starters->count() > (int) $game->rule('players_on_field') => 'comp.error.too_many_starters',
            $slots->count() !== $slots->unique()->count() => 'comp.error.slot_taken',
            $rows->filter(fn (array $r) => ! empty($r['is_captain']))->count() > 1 => 'comp.error.one_captain',
            $goalkeepers > 1 || ($goalkeepers > 0 && ! $game->hasGoalkeeper()) => 'comp.error.one_goalkeeper',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['players' => $error]);
        }

        $formation = isset($data['formation_id']) ? Formation::find($data['formation_id']) : null;
        $positions = Position::query()->where('branch_id', $game->team->branch_id)->pluck('id', 'abbreviation');
        $roster = DB::table('team_players')->where('team_id', $game->team_id)->where('season_start_year', $game->season_start_year)
            ->pluck('shirt_number', 'player_id');

        DB::transaction(function () use ($game, $rows, $formation, $positions, $roster, $minutes) {
            $game->update(['formation_id' => $formation?->id]);
            $byPlayer = $rows->keyBy('player_id');
            // Numbers first cleared, so moving #7 from one player to another never trips the unique index.
            GamePlayer::where('game_id', $game->id)->update(['shirt_number' => null]);

            foreach (GamePlayer::where('game_id', $game->id)->where('called_up', true)->get() as $gp) {
                $row = $byPlayer->get($gp->player_id);
                $starter = ($row['role'] ?? 'bench') === 'starter';
                $label = $starter && $formation && isset($row['slot']) ? ($formation->slots[$row['slot']]['label'] ?? null) : null;
                $rosterShirt = isset($roster[$gp->player_id]) ? (int) $roster[$gp->player_id] : null;
                $shirt = $row !== null && array_key_exists('shirt_number', $row) && $row['shirt_number'] !== null
                    ? (int) $row['shirt_number'] : $rosterShirt;

                $gp->update([
                    'role' => $starter ? 'starter' : 'bench',
                    'slot' => $starter ? ($row['slot'] ?? null) : null,
                    'pos_x' => $starter ? ($row['pos_x'] ?? null) : null,
                    'pos_y' => $starter ? ($row['pos_y'] ?? null) : null,
                    'position_id' => $label !== null ? ($positions[$label] ?? null) : null,
                    'shirt_number' => $shirt,
                    'shirt_override' => $shirt !== $rosterShirt,
                    'is_captain' => (bool) ($row['is_captain'] ?? false),
                    'is_goalkeeper' => (bool) ($row['is_goalkeeper'] ?? false),
                ]);
            }

            if ($game->status === GameStatus::Played || $game->events()->exists()) {
                $minutes->refresh($game);
            }
        });

        return back()->with('success', 'flash.lineup_saved');
    }
}
```

- [ ] **Step 5: Call-up keeps overrides and cleans events**

In `app/Http/Controllers/GameCallUpController.php` → `update`, replace the whole `DB::transaction(function () use ($game, $data, $allowed) { … });` block and the `return` line with:

```php
        $removedEvents = 0;
        DB::transaction(function () use ($game, $data, $allowed, &$removedEvents) {
            // A number the coach set for this match (shirt_override) wins; everyone else
            // wears their roster number unless an override already took it. Numbers are
            // cleared first so a renumbered roster never trips the unique index.
            $before = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
            $departed = $before->filter(fn (GamePlayer $gp) => ! $allowed->has($gp->player_id) && $gp->shirt_number !== null)
                ->map(fn (GamePlayer $gp) => $gp->shirt_number);
            $overrides = $before->filter(fn (GamePlayer $gp) => $gp->shirt_override && $gp->shirt_number !== null)
                ->map(fn (GamePlayer $gp) => (int) $gp->shirt_number);
            GamePlayer::where('game_id', $game->id)->update(['shirt_number' => null]);
            // Reloaded AFTER clearing, so every number written below is seen as a change.
            $existing = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');

            $calledIds = [];
            $keep = [];
            foreach ($data['players'] as $row) {
                $calledUp = (bool) $row['called_up'];
                $reason = $calledUp ? null : (trim((string) ($row['not_called_reason'] ?? '')) ?: null);
                if (! $calledUp && $reason === null) {
                    continue;
                }
                $keep[] = (int) $row['player_id'];
                if ($calledUp) {
                    $calledIds[] = (int) $row['player_id'];
                }
            }

            $taken = [];
            foreach ($calledIds as $playerId) {
                if ($overrides->has($playerId)) {
                    $taken[$playerId] = $overrides[$playerId];
                }
            }
            $assign = [];
            foreach ($calledIds as $playerId) {
                if (isset($taken[$playerId])) {
                    $assign[$playerId] = [$taken[$playerId], true];

                    continue;
                }
                $rosterShirt = $allowed[$playerId]->shirt_number;
                $assign[$playerId] = [$rosterShirt !== null && ! in_array($rosterShirt, $taken, true) ? $rosterShirt : null, false];
            }

            foreach ($data['players'] as $row) {
                $playerId = (int) $row['player_id'];
                if (! in_array($playerId, $keep, true)) {
                    continue;
                }
                $calledUp = (bool) $row['called_up'];
                $attributes = $calledUp
                    ? ['called_up' => true, 'not_called_reason' => null, 'shirt_number' => $assign[$playerId][0], 'shirt_override' => $assign[$playerId][1]]
                    : ['called_up' => false, 'not_called_reason' => trim((string) $row['not_called_reason']), 'shirt_number' => null, 'shirt_override' => false,
                        'role' => 'none', 'slot' => null, 'pos_x' => null, 'pos_y' => null, 'is_captain' => false, 'is_goalkeeper' => false];
                $current = $existing->get($playerId);
                $current
                    ? $current->update($attributes)
                    : GamePlayer::create($attributes + ['game_id' => $game->id, 'player_id' => $playerId]);
            }

            // Only current candidates can be dropped: a player who has since left
            // the roster or squad keeps their row (and number, if still free), so
            // re-saving an old match never erases who played in it.
            GamePlayer::where('game_id', $game->id)->whereIn('player_id', $allowed->keys())->whereNotIn('player_id', $keep)->delete();
            $used = array_filter(array_column($assign, 0), fn ($n) => $n !== null);
            foreach ($departed as $playerId => $shirt) {
                if (! in_array((int) $shirt, $used, true)) {
                    GamePlayer::where('game_id', $game->id)->where('player_id', $playerId)->update(['shirt_number' => $shirt]);
                }
            }

            // Players no longer called up leave the report: their events go, and an
            // assist they gave is cleared from the scorer's goal.
            $out = $allowed->keys()->map(fn ($id) => (int) $id)->diff($calledIds)->values()->all();
            if ($out !== []) {
                $removedEvents = GameEvent::where('game_id', $game->id)->where(fn ($q) => $q
                    ->whereIn('player_id', $out)
                    ->orWhere(fn ($q) => $q->where('type', 'substitution')->whereIn('related_player_id', $out)))->delete();
                $removedEvents += GameEvent::where('game_id', $game->id)->where('type', '!=', 'substitution')
                    ->whereIn('related_player_id', $out)->update(['related_player_id' => null]);
            }
        });

        return back()->with('success', $removedEvents > 0 ? 'flash.call_up_saved_events_removed' : 'flash.call_up_saved');
```

Add `use App\Models\GameEvent;` to the imports.

- [ ] **Step 6: Wire the page, route, activity**

In `app/Http/Controllers/GameController.php`:
- Add `use App\Services\Competitions\MatchPage;`.
- Add `MatchPage $page` to the `show` signature (after `PlayerFlags $flags`).
- Add `'lineup' => $page->lineup($game),` to the props array, after `'candidates'`.

In `routes/web.php`, add `use App\Http\Controllers\GameLineupController;` (alphabetical). After the `games.convocation` route, add:

```php
        Route::put('/games/{game}/lineup', [GameLineupController::class, 'update'])->name('games.lineup.update');
```

In `app/Services/Activity/ActivityAction.php`:
- After `const CALL_UP_SAVED = 'call_up_saved';`, add `const LINEUP_SAVED = 'lineup_saved';` (with the usual blank line).
- Add `self::LINEUP_SAVED,` after `self::CALL_UP_SAVED,` in both `ALL` and `AREAS['competitions']`.

In `config/activity.php`, after `'games.call-up.update' => ActivityAction::CALL_UP_SAVED,`, add `'games.lineup.update' => ActivityAction::LINEUP_SAVED,`.

In `tests/Unit/ActivityRecorderTest.php`, change the hard-coded code count from 104 to 105.

- [ ] **Step 7: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"flash.lineup_saved": "Line-up saved.", "flash.call_up_saved_events_removed": "Call-up saved. Report events of players no longer called up were removed.",
    "comp.error.too_many_starters": "More starters than players on the field.", "comp.error.slot_taken": "Two players are on the same spot.",
    "comp.error.one_captain": "Only one captain.", "comp.error.one_goalkeeper": "Only one goalkeeper (and only in sports that have one).",
    "activity.action.lineup_saved": "Line-ups saved"},
  "fr": {"flash.lineup_saved": "Composition enregistrée.", "flash.call_up_saved_events_removed": "Convocation enregistrée. Les événements des joueurs retirés ont été supprimés du rapport.",
    "comp.error.too_many_starters": "Plus de titulaires que de joueurs sur le terrain.", "comp.error.slot_taken": "Deux joueurs occupent le même poste.",
    "comp.error.one_captain": "Un seul capitaine.", "comp.error.one_goalkeeper": "Un seul gardien (et seulement dans les sports qui en ont un).",
    "activity.action.lineup_saved": "Compositions enregistrées"},
  "ar": {"flash.lineup_saved": "تم حفظ التشكيلة الأساسية.", "flash.call_up_saved_events_removed": "تم حفظ الاستدعاء. حُذفت من التقرير أحداث اللاعبين غير المستدعين.",
    "comp.error.too_many_starters": "عدد الأساسيين يفوق عدد اللاعبين في الميدان.", "comp.error.slot_taken": "لاعبان في نفس المركز.",
    "comp.error.one_captain": "قائد واحد فقط.", "comp.error.one_goalkeeper": "حارس مرمى واحد فقط (وفي الرياضات التي فيها حارس).",
    "activity.action.lineup_saved": "تشكيلات حُفظت"}
}
EOF
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="LineupTest|CallUpTest|GamesTest|Activity|UsersBatch"`
Expected: PASS. The Phase 1 CallUpTest renumber case still passes, because rows without `shirt_override` follow the roster.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Competitions/MatchPage.php app/Services/Competitions/GameMinutes.php app/Http/Controllers/GameLineupController.php app/Http/Controllers/GameCallUpController.php app/Http/Controllers/GameController.php routes/web.php app/Services/Activity/ActivityAction.php config/activity.php tests/Unit/ActivityRecorderTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/LineupTest.php
git commit -m "feat(competitions): save match line-ups with slots, shirts and free placement

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Line-up UI (pitch with drag-and-drop)

**Files:**
- Create: `resources/js/Components/Competitions/PitchBackground.vue`, `resources/js/Components/Competitions/LineupBoard.vue`
- Modify: `resources/js/Pages/Games/Show.vue`

**Interfaces:**
- Consumes: the `lineup` prop (Task 3) and the `games.lineup.update` route.
- Produces: `<LineupBoard :game-id :lineup :can-manage />`, and `<PitchBackground :sport />`, an SVG that fills its parent.

- [ ] **Step 1: PitchBackground**

`resources/js/Components/Competitions/PitchBackground.vue`:

```vue
<script setup>
// The playing surface behind the line-up dots: football/futsal markings, else a
// plain court. viewBox units are metres of a 68 x 105 pitch; it stretches to fill.
defineProps({ sport: { type: String, default: 'football' } });
</script>

<template>
    <svg viewBox="0 0 68 105" preserveAspectRatio="none" class="absolute inset-0 h-full w-full" aria-hidden="true">
        <template v-if="sport === 'football' || sport === 'futsal'">
            <rect x="0" y="0" width="68" height="105" class="fill-emerald-600 dark:fill-emerald-800" />
            <g fill="none" stroke="white" stroke-width="0.4" opacity="0.85">
                <rect x="2" y="2" width="64" height="101" />
                <line x1="2" y1="52.5" x2="66" y2="52.5" />
                <circle cx="34" cy="52.5" r="9.15" />
                <rect x="13.85" y="2" width="40.3" height="16.5" />
                <rect x="24.85" y="2" width="18.3" height="5.5" />
                <rect x="13.85" y="86.5" width="40.3" height="16.5" />
                <rect x="24.85" y="97.5" width="18.3" height="5.5" />
            </g>
        </template>
        <template v-else>
            <rect x="0" y="0" width="68" height="105" class="fill-amber-200 dark:fill-amber-900" />
            <g fill="none" stroke="white" stroke-width="0.5" opacity="0.9">
                <rect x="2" y="2" width="64" height="101" />
                <line x1="2" y1="52.5" x2="66" y2="52.5" />
                <circle cx="34" cy="52.5" r="6" />
            </g>
        </template>
    </svg>
</template>
```

- [ ] **Step 2: LineupBoard**

`resources/js/Components/Competitions/LineupBoard.vue`:

```vue
<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import PitchBackground from '@/Components/Competitions/PitchBackground.vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const props = defineProps({ gameId: Number, lineup: Object, canManage: Boolean });

const formationId = ref(props.lineup.formation_id ?? props.lineup.formations[0]?.id ?? null);
const formation = computed(() => props.lineup.formations.find((f) => f.id === formationId.value) ?? null);
const slots = computed(() => formation.value?.slots ?? []);
const rows = ref(props.lineup.players.map((p) => ({ ...p })));

const starterAt = (slot) => rows.value.find((r) => r.role === 'starter' && r.slot === slot) ?? null;
const starters = computed(() => rows.value.filter((r) => r.role === 'starter').sort((a, b) => (a.slot ?? 99) - (b.slot ?? 99)));
const bench = computed(() => rows.value.filter((r) => r.role !== 'starter'));
const find = (id) => rows.value.find((r) => r.player_id === id);

// Where a spot's dot sits: the player's dragged position for this match, else the slot's.
function dot(slot, index) {
    const r = starterAt(index);
    return { x: r?.pos_x ?? slot.x, y: r?.pos_y ?? slot.y };
}

function toBench(r) {
    Object.assign(r, { role: 'bench', slot: null, pos_x: null, pos_y: null });
}

function changeFormation(id) {
    formationId.value = id;
    const count = slots.value.length;
    rows.value.forEach((r) => {
        r.pos_x = null;
        r.pos_y = null;
        if (r.role === 'starter' && (r.slot === null || r.slot >= count)) toBench(r);
    });
}

// Drag and drop (HTML5): names from the bench, or dots on the pitch.
const dragging = ref(null);
function startDrag(event, playerId) {
    if (!props.canManage) return;
    dragging.value = playerId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(playerId));
}
function take() {
    const id = dragging.value;
    dragging.value = null;
    return id === null ? null : find(id);
}
function dropOnSlot(index) {
    const mover = take();
    if (!mover) return;
    const occupant = starterAt(index);
    if (occupant && occupant.player_id === mover.player_id) return;
    if (occupant) {
        if (mover.role === 'starter') Object.assign(occupant, { slot: mover.slot, pos_x: null, pos_y: null });
        else toBench(occupant);
    }
    Object.assign(mover, { role: 'starter', slot: index, pos_x: null, pos_y: null });
}
function dropOnBench() {
    const mover = take();
    if (mover) toBench(mover);
}
function dropOnPitch(event) {
    const mover = take();
    if (!mover || mover.role !== 'starter') return;
    const box = event.currentTarget.getBoundingClientRect();
    const clamp = (v) => Math.max(0, Math.min(100, Math.round(v)));
    mover.pos_x = clamp(((event.clientX - box.left) / box.width) * 100);
    mover.pos_y = clamp((1 - (event.clientY - box.top) / box.height) * 100);
}

function autoFill() {
    slots.value.forEach((slot, i) => {
        if (starterAt(i)) return;
        const match = bench.value.find((r) => r.usual_position && r.usual_position.toUpperCase() === String(slot.label).toUpperCase());
        if (match) Object.assign(match, { role: 'starter', slot: i });
    });
    slots.value.forEach((slot, i) => {
        if (starterAt(i)) return;
        const pick = bench.value.find((r) => !r.injured) ?? bench.value[0];
        if (pick) Object.assign(pick, { role: 'starter', slot: i });
    });
}

const warnings = computed(() => {
    const list = [];
    if (starters.value.length < props.lineup.players_on_field) list.push(t('comp.warn.few_starters', { n: starters.value.length, max: props.lineup.players_on_field }));
    if (bench.value.length > props.lineup.max_bench) list.push(t('comp.warn.big_bench', { n: bench.value.length, max: props.lineup.max_bench }));
    return list;
});

const form = useForm({ formation_id: null, players: [] });
function save() {
    form.formation_id = formationId.value;
    form.players = rows.value.map((r) => ({
        player_id: r.player_id,
        role: r.role,
        slot: r.role === 'starter' ? r.slot : null,
        pos_x: r.role === 'starter' ? r.pos_x : null,
        pos_y: r.role === 'starter' ? r.pos_y : null,
        shirt_number: r.shirt_number === '' || r.shirt_number === null ? null : Number(r.shirt_number),
        is_captain: !!r.is_captain,
        is_goalkeeper: !!r.is_goalkeeper,
    }));
    form.put(route('games.lineup.update', props.gameId), { preserveScroll: true });
}

function setCaptain(r) {
    const value = !r.is_captain;
    rows.value.forEach((x) => { x.is_captain = false; });
    r.is_captain = value;
}
function setKeeper(r) {
    const value = !r.is_goalkeeper;
    rows.value.forEach((x) => { x.is_goalkeeper = false; });
    r.is_goalkeeper = value;
}
const shortName = (name) => String(name ?? '').split(' ').slice(0, 2).join(' ');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm">{{ t('comp.formation') }}
                <select :value="formationId" :disabled="!canManage" class="ms-2 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" @change="changeFormation(Number($event.target.value))">
                    <option v-for="f in lineup.formations" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
            </label>
            <span class="text-sm text-slate-600 dark:text-slate-300">{{ t('comp.starters_count', { n: starters.length, max: lineup.players_on_field }) }} · {{ t('comp.bench_count', { n: bench.length, max: lineup.max_bench }) }}</span>
            <SecondaryButton v-if="canManage" @click="autoFill">{{ t('comp.auto_fill') }}</SecondaryButton>
        </div>
        <ul v-if="warnings.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <li v-for="w in warnings" :key="w">{{ w }}</li>
        </ul>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
            <!-- Pitch: coordinates stay left-to-right in every language. -->
            <div dir="ltr" class="relative mx-auto w-full max-w-sm overflow-hidden rounded-xl" style="aspect-ratio: 68 / 105"
                @dragover.prevent @drop.prevent="dropOnPitch">
                <PitchBackground :sport="lineup.sport" />
                <div v-for="(slot, i) in slots" :key="i" class="absolute -translate-x-1/2 translate-y-1/2"
                    :style="{ left: `${dot(slot, i).x}%`, bottom: `${dot(slot, i).y}%` }"
                    @dragover.prevent @drop.prevent.stop="dropOnSlot(i)">
                    <div v-if="starterAt(i)" :draggable="canManage" class="flex w-16 cursor-grab flex-col items-center" @dragstart="startDrag($event, starterAt(i).player_id)">
                        <span class="relative flex h-9 w-9 items-center justify-center rounded-full bg-white text-sm font-bold text-slate-900 shadow ring-2 ring-primary-600">
                            {{ starterAt(i).shirt_number ?? '·' }}
                            <span v-if="starterAt(i).is_captain" class="absolute -end-1 -top-1 rounded-full bg-amber-400 px-1 text-[10px] text-slate-900">C</span>
                            <span v-if="starterAt(i).is_goalkeeper" class="absolute -bottom-1 -end-1 rounded-full bg-sky-500 px-1 text-[10px] text-white">GK</span>
                        </span>
                        <span class="mt-0.5 max-w-full truncate rounded bg-black/50 px-1 text-[10px] text-white" :dir="'auto'">{{ shortName(starterAt(i).name) }}</span>
                    </div>
                    <div v-else class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-dashed border-white/80 text-[10px] font-semibold text-white">{{ slot.label }}</div>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Bench / called-up list: drop a dot here to send the player to the bench. -->
                <div class="min-h-24 rounded-xl border border-dashed border-slate-300 p-3 dark:border-slate-700" @dragover.prevent @drop.prevent="dropOnBench">
                    <h3 class="mb-2 text-sm font-semibold">{{ t('comp.bench') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <div v-for="r in bench" :key="r.player_id" :draggable="canManage" class="cursor-grab rounded-lg bg-slate-100 px-2 py-1 text-sm dark:bg-slate-800"
                            @dragstart="startDrag($event, r.player_id)">
                            <span class="font-mono text-slate-500">{{ r.shirt_number }}</span> {{ r.name }}
                            <span v-if="r.usual_position" class="text-xs text-slate-400">· {{ r.usual_position }}</span>
                            <span v-if="r.injured" class="ms-1 rounded bg-amber-100 px-1 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
                        </div>
                        <p v-if="!bench.length" class="text-sm text-slate-500">{{ t('comp.bench_empty') }}</p>
                    </div>
                </div>

                <!-- Starters: number, captain, goalkeeper. -->
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr>
                        <th class="py-1 text-start">{{ t('comp.spot') }}</th><th class="py-1 text-start">{{ t('comp.player') }}</th>
                        <th class="w-20 py-1">{{ t('comp.shirt') }}</th><th class="py-1">C</th><th v-if="lineup.has_goalkeeper" class="py-1">GK</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in starters" :key="r.player_id">
                            <td class="py-1 font-mono text-xs">{{ slots[r.slot]?.label }}</td>
                            <td class="py-1">{{ r.name }}</td>
                            <td class="py-1"><input v-model="r.shirt_number" :disabled="!canManage" type="number" min="0" max="99" class="w-16 rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                            <td class="py-1 text-center"><input type="checkbox" :checked="r.is_captain" :disabled="!canManage" class="rounded" @change="setCaptain(r)" /></td>
                            <td v-if="lineup.has_goalkeeper" class="py-1 text-center"><input type="checkbox" :checked="r.is_goalkeeper" :disabled="!canManage" class="rounded" @change="setKeeper(r)" /></td>
                        </tr>
                    </tbody>
                </table>
                <InputError :message="form.errors.players ? t(form.errors.players) : ''" />
                <InputError v-for="(msg, key) in form.errors" v-show="key !== 'players'" :key="key" :message="t(msg)" />
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('save') }}</PrimaryButton>
                    <a :href="route('games.lineup-sheet', gameId)" target="_blank" class="self-center text-sm text-primary-600 hover:underline">{{ t('comp.lineup_sheet_pdf') }}</a>
                </div>
            </div>
        </div>
    </div>
</template>
```

**Note:** the `games.lineup-sheet` route is added in Task 7. Until then, the link only breaks at runtime in the browser; PHP tests don't render Vue.

- [ ] **Step 3: Wire the tab**

In `resources/js/Pages/Games/Show.vue`:
- Add `import LineupBoard from '@/Components/Competitions/LineupBoard.vue';`.
- Change props to `defineProps({ game: Object, candidates: Array, lineup: Object, opponents: Array, venues: Array, canManage: Boolean })`.
- Change the tab list to `['info', 'callup', 'lineup']`.
- After the call-up `<section>`, add:

```vue
            <section v-if="tab === 'lineup'" class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p v-if="!lineup.players.length" class="text-sm text-slate-500">
                    {{ t('comp.do_callup_first') }}
                    <button type="button" class="text-primary-600 hover:underline" @click="tab = 'callup'">{{ t('comp.tab_callup') }}</button>
                </p>
                <LineupBoard v-else :game-id="game.id" :lineup="lineup" :can-manage="canManage" />
            </section>
```

- [ ] **Step 4: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.tab_lineup": "Line-up", "comp.formation": "Formation", "comp.starters_count": "Starters {n}/{max}", "comp.bench_count": "Bench {n}/{max}",
    "comp.auto_fill": "Auto-fill", "comp.bench": "Bench (drag onto the pitch)", "comp.bench_empty": "Everyone is on the pitch.", "comp.spot": "Spot",
    "comp.lineup_sheet_pdf": "Line-up sheet (PDF)", "comp.do_callup_first": "Call players up first:",
    "comp.warn.few_starters": "Only {n} starters out of {max}.", "comp.warn.big_bench": "{n} on the bench, more than the {max} allowed."},
  "fr": {"comp.tab_lineup": "Composition", "comp.formation": "Système", "comp.starters_count": "Titulaires {n}/{max}", "comp.bench_count": "Banc {n}/{max}",
    "comp.auto_fill": "Remplir automatiquement", "comp.bench": "Banc (glisser sur le terrain)", "comp.bench_empty": "Tout le monde est sur le terrain.", "comp.spot": "Poste",
    "comp.lineup_sheet_pdf": "Feuille de composition (PDF)", "comp.do_callup_first": "Convoquez d'abord les joueurs :",
    "comp.warn.few_starters": "Seulement {n} titulaires sur {max}.", "comp.warn.big_bench": "{n} sur le banc, plus que les {max} autorisés."},
  "ar": {"comp.tab_lineup": "التشكيلة الأساسية", "comp.formation": "الخطة", "comp.starters_count": "الأساسيون {n}/{max}", "comp.bench_count": "الاحتياط {n}/{max}",
    "comp.auto_fill": "ملء تلقائي", "comp.bench": "الاحتياط (اسحب إلى الميدان)", "comp.bench_empty": "الجميع في الميدان.", "comp.spot": "المركز",
    "comp.lineup_sheet_pdf": "ورقة التشكيلة (PDF)", "comp.do_callup_first": "استدعِ اللاعبين أولاً:",
    "comp.warn.few_starters": "{n} أساسيين فقط من أصل {max}.", "comp.warn.big_bench": "{n} في الاحتياط، أكثر من {max} المسموح بهم."}
}
EOF
```

- [ ] **Step 5: Build and run**

Run: `npm run build`, then `php artisan test --filter="LineupTest|GamesTest|CallUpTest"` and `npm run i18n:check`.
Expected: the build succeeds, the tests PASS, and the i18n check exits 0.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Competitions/PitchBackground.vue resources/js/Components/Competitions/LineupBoard.vue resources/js/Pages/Games/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(competitions): drag players onto a pitch to build the line-up

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Match report backend

**Files:**
- Create: `app/Services/Competitions/ReportValidator.php`, `app/Services/Competitions/ReportWarnings.php`, `app/Http/Controllers/GameReportController.php`
- Modify:
  - `app/Services/Competitions/MatchPage.php`: add `report()`.
  - `app/Http/Controllers/GameController.php`: `show` passes `report`.
  - `routes/web.php`, `app/Services/Activity/ActivityAction.php`, `config/activity.php`, `tests/Unit/ActivityRecorderTest.php` (count 105 → 106).
- Test: `tests/Unit/ReportValidatorTest.php`, `tests/Feature/Competitions/MatchReportTest.php`

**Interfaces:**
- Consumes: `Game::rule()`, `Game::matchLength(?bool)`, `GameEvent::TYPES` / `GOAL_KINDS`, `ScoreCalculator::derive()`, `GameMinutes::refresh()`.
- Produces:
  - `ReportValidator::errors(Game $game, array<int,string> $roles, array $data): array<string,string>`. `$roles` maps `player_id` to `'starter'` or `'bench'`.
  - `ReportWarnings::for(Game): list<string>`, a list of i18n keys.
  - `MatchPage::report(Game): array`, with the shape `{rules:{periods_count, period_label, scoring, has_extra_time, extra_time_minutes, has_shootout, rolling_subs, sanction_types, sport, length}, period_scores, extra_time_played, our_score, their_score, shootout_us, shootout_them, notes, status, events:[{type,subtype,period,minute,player_id,related_player_id}], players:[{player_id,name,shirt_number,role,played,minutes_played,sets_played,rating,coach_note,is_mvp}], warnings}`.
  - Route `games.report.update`: `PUT /games/{game}/report`.
  - `ActivityAction::REPORT_SAVED`.
  - Report payload: `{period_scores: list<[?int,?int]>` (regular periods, then one extra-time row when `extra_time_played`), `extra_time_played, our_score, their_score, shootout_us, shootout_them, events: [...], players: [{player_id, played, minutes_played, sets_played, rating, coach_note}], mvp_player_id, notes}`.

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/ReportValidatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Game;
use App\Services\Competitions\ReportValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReportValidatorTest extends TestCase
{
    private function game(bool $rolling = false): Game
    {
        return (new Game)->forceFill(['extra_time_played' => false, 'rules' => [
            'periods_count' => 2, 'period_minutes' => 45, 'extra_time_minutes' => 30, 'rolling_subs' => $rolling,
            'sanction_types' => [['key' => 'yellow', 'ends_play' => false], ['key' => 'red', 'ends_play' => true]],
        ]]);
    }

    private function data(array $events, array $players = []): array
    {
        return ['extra_time_played' => false, 'events' => $events, 'players' => $players, 'mvp_player_id' => null];
    }

    private const ROLES = [1 => 'starter', 2 => 'starter', 3 => 'bench', 4 => 'bench'];

    private function sub(int $minute, int $in, int $out): array
    {
        return ['type' => 'substitution', 'subtype' => null, 'minute' => $minute, 'player_id' => $in, 'related_player_id' => $out];
    }

    #[Test]
    public function a_clean_report_has_no_errors(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            ['type' => 'goal', 'subtype' => 'normal', 'minute' => null, 'player_id' => 1, 'related_player_id' => 2],
            ['type' => 'goal', 'subtype' => 'opponent_own_goal', 'minute' => 30, 'player_id' => null, 'related_player_id' => null],
            $this->sub(60, 3, 1),
            ['type' => 'sanction', 'subtype' => 'yellow', 'minute' => null, 'player_id' => 3, 'related_player_id' => null],
        ]));

        $this->assertSame([], $errors);
    }

    #[Test]
    public function substitution_timing_is_checked_in_minute_order(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            $this->sub(70, 4, 1),
            $this->sub(60, 3, 1),
            $this->sub(80, 1, 2),
        ]));

        $this->assertSame('comp.error.not_on_pitch', $errors['events.0.related_player_id']);
        $this->assertSame('comp.error.cannot_come_on', $errors['events.2.player_id']);
        $this->assertArrayNotHasKey('events.1.player_id', $errors);
    }

    #[Test]
    public function a_sent_off_player_cannot_be_subbed_off_and_minutes_must_fit(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            ['type' => 'sanction', 'subtype' => 'red', 'minute' => 50, 'player_id' => 2, 'related_player_id' => null],
            $this->sub(60, 3, 2),
            ['type' => 'sanction', 'subtype' => 'red', 'minute' => null, 'player_id' => 1, 'related_player_id' => null],
            ['type' => 'goal', 'subtype' => 'normal', 'minute' => 95, 'player_id' => 1, 'related_player_id' => 1],
        ]));

        $this->assertSame('comp.error.not_on_pitch', $errors['events.1.related_player_id']);
        $this->assertSame('comp.error.minute_required', $errors['events.2.minute']);
        $this->assertSame('comp.error.minute_out_of_range', $errors['events.3.minute']);
        $this->assertSame('comp.error.assist_is_scorer', $errors['events.3.related_player_id']);
    }

    #[Test]
    public function rolling_formats_refuse_substitution_events_and_players_must_be_in_the_line_up(): void
    {
        $errors = (new ReportValidator)->errors($this->game(true), self::ROLES, $this->data(
            [$this->sub(10, 3, 1), ['type' => 'goal', 'subtype' => 'normal', 'minute' => 5, 'player_id' => 9, 'related_player_id' => null]],
            [['player_id' => 1, 'minutes_played' => 120]],
        ));

        $this->assertSame('comp.error.no_substitutions', $errors['events.0.type']);
        $this->assertSame('comp.error.not_in_lineup', $errors['events.1.player_id']);
        $this->assertSame('comp.error.minute_out_of_range', $errors['players.0.minutes_played']);
    }
}
```

- [ ] **Step 2: Write the failing feature test**

`tests/Feature/Competitions/MatchReportTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchReportTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    /** Two starters, two on the bench. */
    private function match(array $format = []): array
    {
        $team = $this->team();
        if ($format !== []) {
            $team->defaultFormat->update($format);
        }
        $players = array_map(fn () => $this->player($team->category), range(1, 4));
        $this->onRoster($team, 2026, $players);
        $game = $this->game($team);
        foreach ($players as $i => $p) {
            GamePlayer::create(['game_id' => $game->id, 'player_id' => $p->id, 'called_up' => true,
                'shirt_number' => $i + 1, 'role' => $i < 2 ? 'starter' : 'bench']);
        }

        return [$game, $players];
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'period_scores' => [[1, 0], [1, 1]], 'extra_time_played' => false,
            'our_score' => null, 'their_score' => null, 'shootout_us' => null, 'shootout_them' => null,
            'events' => [], 'players' => [], 'mvp_player_id' => null, 'notes' => null,
        ];
    }

    #[Test]
    public function saving_the_report_plays_the_match_derives_the_score_and_computes_minutes(): void
    {
        [$game, [$a, $b, $c, $d]] = $this->match();

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload([
            'events' => [
                ['type' => 'goal', 'subtype' => 'normal', 'period' => 1, 'minute' => 12, 'player_id' => $a->id, 'related_player_id' => $b->id],
                ['type' => 'goal', 'subtype' => 'penalty', 'period' => 2, 'minute' => null, 'player_id' => $b->id, 'related_player_id' => null],
                ['type' => 'substitution', 'subtype' => null, 'period' => 2, 'minute' => 70, 'player_id' => $c->id, 'related_player_id' => $a->id],
            ],
            'players' => [
                ['player_id' => $a->id, 'rating' => 8, 'coach_note' => 'Sharp'],
                ['player_id' => $c->id, 'rating' => 6, 'coach_note' => null],
            ],
            'mvp_player_id' => $a->id,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.report_saved');

        $game->refresh();
        $this->assertSame(GameStatus::Played, $game->status);
        $this->assertSame([2, 1], [$game->our_score, $game->their_score]);
        $this->assertSame(3, $game->events()->count());
        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertSame(70, $rows[$a->id]->minutes_played);
        $this->assertSame(90, $rows[$b->id]->minutes_played);
        $this->assertSame(20, $rows[$c->id]->minutes_played);
        $this->assertFalse($rows[$d->id]->played);
        $this->assertTrue($rows[$a->id]->is_mvp);
        $this->assertSame(8, $rows[$a->id]->rating);
        $this->assertSame('Sharp', $rows[$a->id]->coach_note);
    }

    #[Test]
    public function without_period_rows_the_typed_score_is_required(): void
    {
        [$game] = $this->match();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('games.report.update', $game), $this->payload(['period_scores' => [[null, null], [null, null]]]))
            ->assertSessionHasErrors('our_score');
        $this->actingAs($admin)->put(route('games.report.update', $game), $this->payload(['period_scores' => [], 'our_score' => 3, 'their_score' => 2]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $game->fresh()->our_score);
    }

    #[Test]
    public function rolling_formats_take_typed_minutes_and_volleyball_counts_sets(): void
    {
        [$game, [$a, $b]] = $this->match(['rolling_subs' => true, 'scoring' => 'sets', 'periods_count' => 5, 'period_minutes' => 0, 'sets_to_win' => 3]);

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload([
            'period_scores' => [[25, 20], [25, 22], [20, 25], [25, 18], [null, null]],
            'players' => [
                ['player_id' => $a->id, 'played' => true, 'sets_played' => 4],
                ['player_id' => $b->id, 'played' => false],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([3, 1], [$game->fresh()->our_score, $game->fresh()->their_score]);
        $rows = GamePlayer::where('game_id', $game->id)->get()->keyBy('player_id');
        $this->assertTrue($rows[$a->id]->played);
        $this->assertSame(4, $rows[$a->id]->sets_played);
        $this->assertFalse($rows[$b->id]->played);
    }

    #[Test]
    public function the_page_shows_the_report_with_warnings(): void
    {
        [$game, [$a]] = $this->match(['max_substitutions' => 0]);
        $game->update(['status' => GameStatus::Played, 'our_score' => 2, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);

        $this->actingAs($this->admin())->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.rules.length', 90)
                ->has('report.events', 1)
                ->has('report.players', 4)
                ->where('report.warnings', ['comp.warn.goals_mismatch']));
    }

    #[Test]
    public function a_re_saved_report_replaces_its_events_and_coaches_are_scoped(): void
    {
        [$game, [$a]] = $this->match();
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'player_id' => $a->id]);

        $this->actingAs($this->admin())->put(route('games.report.update', $game), $this->payload(['period_scores' => [[0, 0], [0, 0]]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $game->events()->count());

        $this->actingAs($this->coach())->put(route('games.report.update', $game), $this->payload())->assertForbidden();
    }
}
```

- [ ] **Step 3: Run them to make sure they fail**

Run: `php artisan test --filter="ReportValidatorTest|MatchReportTest"`
Expected: FAIL, with class not found or route not defined.

- [ ] **Step 4: Write the validator and warnings**

`app/Services/Competitions/ReportValidator.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\GameEvent;

/**
 * Blocking checks on a match report. Substitutions and send-offs are walked
 * in minute order to know who is on the pitch at each moment: a player comes
 * on only from the bench and only once (no re-entry), and goes off only while
 * on the pitch. Returns field => i18n key; empty means valid.
 */
final class ReportValidator
{
    /**
     * @param  array<int, string>  $roles  player_id => 'starter'|'bench' for every called-up player
     * @param  array<string, mixed>  $data  the validated report payload
     * @return array<string, string>
     */
    public function errors(Game $game, array $roles, array $data): array
    {
        $errors = [];
        $length = $game->matchLength((bool) ($data['extra_time_played'] ?? false));
        $rolling = (bool) $game->rule('rolling_subs');
        $sanctions = collect($game->rule('sanction_types', []))->keyBy('key');
        $timeline = [];

        foreach ($data['events'] as $i => $event) {
            $at = "events.{$i}";
            $minute = $event['minute'] ?? null;
            $player = $event['player_id'] ?? null;
            $related = $event['related_player_id'] ?? null;

            if ($minute !== null && $minute > $length) {
                $errors["{$at}.minute"] = 'comp.error.minute_out_of_range';
            }
            if ($player !== null && ! isset($roles[$player])) {
                $errors["{$at}.player_id"] = 'comp.error.not_in_lineup';
            }
            if ($related !== null && ! isset($roles[$related])) {
                $errors["{$at}.related_player_id"] = 'comp.error.not_in_lineup';
            }

            switch ($event['type']) {
                case 'goal':
                    $kind = $event['subtype'] ?? null;
                    if (! in_array($kind, GameEvent::GOAL_KINDS, true)) {
                        $errors["{$at}.subtype"] = 'comp.error.invalid_subtype';
                    } elseif ($kind === 'opponent_own_goal') {
                        if ($player !== null) {
                            $errors["{$at}.player_id"] ??= 'comp.error.own_goal_no_scorer';
                        }
                    } elseif ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if ($related !== null && $related === $player) {
                        $errors["{$at}.related_player_id"] ??= 'comp.error.assist_is_scorer';
                    }
                    break;

                case 'sanction':
                    $kind = $sanctions->get($event['subtype'] ?? '');
                    if ($kind === null) {
                        $errors["{$at}.subtype"] = 'comp.error.invalid_subtype';
                    }
                    if ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if (($kind['ends_play'] ?? false) && $minute === null) {
                        $errors["{$at}.minute"] ??= 'comp.error.minute_required';
                    }
                    if (($kind['ends_play'] ?? false) && $minute !== null && $player !== null) {
                        $timeline[] = ['minute' => $minute, 'index' => $i, 'kind' => 'off', 'player' => $player];
                    }
                    break;

                case 'substitution':
                    if ($rolling) {
                        $errors["{$at}.type"] = 'comp.error.no_substitutions';
                        break;
                    }
                    if ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if ($related === null) {
                        $errors["{$at}.related_player_id"] ??= 'comp.error.player_required';
                    }
                    if ($minute === null) {
                        $errors["{$at}.minute"] ??= 'comp.error.minute_required';
                    }
                    if ($player !== null && $related !== null && $minute !== null) {
                        $timeline[] = ['minute' => $minute, 'index' => $i, 'kind' => 'sub', 'in' => $player, 'out' => $related];
                    }
                    break;
            }
        }

        usort($timeline, fn (array $a, array $b) => [$a['minute'], $a['index']] <=> [$b['minute'], $b['index']]);
        $onPitch = array_fill_keys(array_keys(array_filter($roles, fn (string $role) => $role === 'starter')), true);
        $hasPlayed = $onPitch;
        foreach ($timeline as $step) {
            if ($step['kind'] === 'off') {
                unset($onPitch[$step['player']]);
                $hasPlayed[$step['player']] = true;

                continue;
            }
            if (! isset($onPitch[$step['out']])) {
                $errors["events.{$step['index']}.related_player_id"] ??= 'comp.error.not_on_pitch';
            }
            if (isset($hasPlayed[$step['in']]) || ($roles[$step['in']] ?? null) !== 'bench') {
                $errors["events.{$step['index']}.player_id"] ??= 'comp.error.cannot_come_on';
            }
            unset($onPitch[$step['out']]);
            $onPitch[$step['in']] = true;
            $hasPlayed[$step['in']] = true;
        }

        foreach ($data['players'] as $i => $row) {
            if (! isset($roles[$row['player_id']])) {
                $errors["players.{$i}.player_id"] = 'comp.error.not_in_lineup';
            }
            if (($row['minutes_played'] ?? null) !== null && $row['minutes_played'] > $length) {
                $errors["players.{$i}.minutes_played"] = 'comp.error.minute_out_of_range';
            }
            if (($row['sets_played'] ?? null) !== null && $row['sets_played'] > (int) $game->rule('periods_count')) {
                $errors["players.{$i}.sets_played"] = 'comp.error.too_many_sets';
            }
        }
        if (($data['mvp_player_id'] ?? null) !== null && ! isset($roles[$data['mvp_player_id']])) {
            $errors['mvp_player_id'] = 'comp.error.not_in_lineup';
        }

        return $errors;
    }
}
```

`app/Services/Competitions/ReportWarnings.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Enums\GameStatus;
use App\Models\Game;

/** Non-blocking report hints shown on the Report tab (i18n keys). */
final class ReportWarnings
{
    /** @return list<string> */
    public static function for(Game $game): array
    {
        if ($game->status !== GameStatus::Played) {
            return [];
        }
        $events = $game->events()->get(['type', 'subtype']);
        $warnings = [];

        if ($game->rule('scoring') === 'goals' && $events->where('type', 'goal')->count() !== (int) $game->our_score) {
            $warnings[] = 'comp.warn.goals_mismatch';
        }
        $limit = $game->rule('max_substitutions');
        if ($limit !== null && $events->where('type', 'substitution')->count() > (int) $limit) {
            $warnings[] = 'comp.warn.too_many_subs';
        }
        if ($game->formation_id !== null && $game->players()->where('role', 'starter')->whereNull('position_id')->exists()) {
            $warnings[] = 'comp.warn.starter_without_position';
        }
        if (($game->shootout_us !== null || $game->shootout_them !== null) && $game->our_score !== $game->their_score) {
            $warnings[] = 'comp.warn.shootout_not_draw';
        }

        return $warnings;
    }
}
```

- [ ] **Step 5: Report props and controller**

In `app/Services/Competitions/MatchPage.php`, add `use App\Models\GameEvent;` and this method:

```php
    /** @return array<string, mixed> ratings and notes appear here only — never in a PDF. */
    public function report(Game $game): array
    {
        $game->loadMissing(['players.player']);

        return [
            'rules' => [
                'periods_count' => (int) $game->rule('periods_count'),
                'period_label' => $game->rule('period_label'),
                'scoring' => $game->rule('scoring'),
                'has_extra_time' => (bool) $game->rule('has_extra_time'),
                'extra_time_minutes' => (int) $game->rule('extra_time_minutes', 0),
                'has_shootout' => (bool) $game->rule('has_shootout'),
                'rolling_subs' => (bool) $game->rule('rolling_subs'),
                'sanction_types' => $game->rule('sanction_types', []),
                'sport' => $game->rule('sport'),
                'length' => $game->matchLength(false),
            ],
            'period_scores' => $game->period_scores ?? [],
            'extra_time_played' => (bool) $game->extra_time_played,
            'our_score' => $game->our_score,
            'their_score' => $game->their_score,
            'shootout_us' => $game->shootout_us,
            'shootout_them' => $game->shootout_them,
            'notes' => $game->notes,
            'status' => $game->status->value,
            'events' => $game->events()->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get()
                ->map(fn (GameEvent $e) => $e->only(['type', 'subtype', 'period', 'minute', 'player_id', 'related_player_id']))->values(),
            'players' => $game->players->where('called_up', true)
                ->sortBy([fn ($a, $b) => ($a->role === 'starter' ? 0 : 1) <=> ($b->role === 'starter' ? 0 : 1), fn ($a, $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999)])
                ->map(fn (GamePlayer $gp) => [
                    'player_id' => $gp->player_id,
                    'name' => $gp->player->fullname,
                    'shirt_number' => $gp->shirt_number,
                    'role' => $gp->role === 'starter' ? 'starter' : 'bench',
                    'played' => $gp->played,
                    'minutes_played' => $gp->minutes_played,
                    'sets_played' => $gp->sets_played,
                    'rating' => $gp->rating,
                    'coach_note' => $gp->coach_note,
                    'is_mvp' => $gp->is_mvp,
                ])->values(),
            'warnings' => ReportWarnings::for($game),
        ];
    }
```

`app/Http/Controllers/GameReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\GameMinutes;
use App\Services\Competitions\ReportValidator;
use App\Services\Competitions\ScoreCalculator;
use App\Services\Competitions\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameReportController extends Controller
{
    /**
     * Saves the whole report at once: scores, the timeline (replaced), and the
     * per-player grid. The match becomes "played"; minutes are worked out from
     * the line-up and timeline unless the format has rolling substitutions.
     */
    public function update(Request $request, Game $game, ReportValidator $validator, GameMinutes $minutes): RedirectResponse
    {
        TeamAccess::authorize($request->user(), $game->team, $game->season_start_year);
        $periods = (int) $game->rule('periods_count');
        $data = $request->validate([
            'period_scores' => ['present', 'array', 'max:'.($periods + 1)],
            'period_scores.*' => ['nullable', 'array', 'size:2'],
            'period_scores.*.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'extra_time_played' => ['sometimes', 'boolean'],
            'our_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'their_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'shootout_us' => ['nullable', 'integer', 'min:0', 'max:99'],
            'shootout_them' => ['nullable', 'integer', 'min:0', 'max:99'],
            'events' => ['present', 'array', 'max:200'],
            'events.*.type' => ['required', Rule::in(GameEvent::TYPES)],
            'events.*.subtype' => ['nullable', 'string', 'max:32'],
            'events.*.period' => ['nullable', 'integer', 'min:1', 'max:'.($periods + 1)],
            'events.*.minute' => ['nullable', 'integer', 'min:0', 'max:300'],
            'events.*.player_id' => ['nullable', 'integer'],
            'events.*.related_player_id' => ['nullable', 'integer'],
            'players' => ['present', 'array'],
            'players.*.player_id' => ['required', 'integer', 'distinct'],
            'players.*.played' => ['sometimes', 'boolean'],
            'players.*.minutes_played' => ['nullable', 'integer', 'min:0', 'max:300'],
            'players.*.sets_played' => ['nullable', 'integer', 'min:0', 'max:9'],
            'players.*.rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'players.*.coach_note' => ['nullable', 'string', 'max:2000'],
            'mvp_player_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['extra_time_played'] = $request->boolean('extra_time_played');

        $roles = $game->players()->where('called_up', true)->get(['player_id', 'role'])
            ->mapWithKeys(fn (GamePlayer $gp) => [$gp->player_id => $gp->role === 'starter' ? 'starter' : 'bench'])->all();
        $errors = $validator->errors($game, $roles, $data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $rows = array_map(fn ($row) => is_array($row) ? [$row[0] ?? null, $row[1] ?? null] : [null, null], $data['period_scores']);
        $score = ScoreCalculator::derive($rows, (string) $game->rule('scoring'));
        if ($score === null && ($data['our_score'] === null || $data['their_score'] === null)) {
            throw ValidationException::withMessages(['our_score' => 'comp.error.score_required']);
        }
        [$us, $them] = $score ?? [(int) $data['our_score'], (int) $data['their_score']];

        DB::transaction(function () use ($game, $data, $rows, $us, $them, $minutes) {
            $game->update([
                'period_scores' => $rows,
                'extra_time_played' => $data['extra_time_played'],
                'our_score' => $us,
                'their_score' => $them,
                'shootout_us' => $data['shootout_us'] ?? null,
                'shootout_them' => $data['shootout_them'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => GameStatus::Played,
            ]);

            $game->events()->delete();
            foreach ($data['events'] as $event) {
                $game->events()->create([
                    'type' => $event['type'],
                    'subtype' => $event['subtype'] ?? null,
                    'period' => $event['period'] ?? null,
                    'minute' => $event['minute'] ?? null,
                    'player_id' => $event['player_id'] ?? null,
                    'related_player_id' => $event['related_player_id'] ?? null,
                ]);
            }

            $rolling = (bool) $game->rule('rolling_subs');
            $sets = $game->rule('scoring') === 'sets';
            $grid = collect($data['players'])->keyBy('player_id');
            foreach ($game->players()->where('called_up', true)->get() as $gp) {
                $row = $grid->get($gp->player_id, []);
                $attributes = [
                    'rating' => $row['rating'] ?? null,
                    'coach_note' => $row['coach_note'] ?? null,
                    'is_mvp' => (int) ($data['mvp_player_id'] ?? 0) === $gp->player_id,
                ];
                if ($rolling) {
                    $attributes += [
                        'played' => (bool) ($row['played'] ?? false),
                        'minutes_played' => $sets ? null : ($row['minutes_played'] ?? null),
                        'sets_played' => $sets ? ($row['sets_played'] ?? null) : null,
                    ];
                }
                $gp->update($attributes);
            }

            $minutes->refresh($game->fresh());
        });

        return back()->with('success', 'flash.report_saved');
    }
}
```

- [ ] **Step 6: Wire page, route, activity**

In `app/Http/Controllers/GameController.php` → `show`, add `'report' => $page->report($game),` after `'lineup'`.

In `routes/web.php`, add `use App\Http\Controllers\GameReportController;` and, after the `games.lineup.update` route:

```php
        Route::put('/games/{game}/report', [GameReportController::class, 'update'])->name('games.report.update');
```

In `ActivityAction.php`, add `const REPORT_SAVED = 'report_saved';` after `LINEUP_SAVED`, then add it to `ALL` and `AREAS['competitions']` after `self::LINEUP_SAVED,`. In `config/activity.php`, add `'games.report.update' => ActivityAction::REPORT_SAVED,`. In `tests/Unit/ActivityRecorderTest.php`, change the count from 105 to 106.

- [ ] **Step 7: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"flash.report_saved": "Match report saved.", "activity.action.report_saved": "Match reports saved",
    "comp.error.minute_out_of_range": "This minute is outside the match.", "comp.error.minute_required": "A minute is required here.",
    "comp.error.not_in_lineup": "This player is not in the match squad.", "comp.error.invalid_subtype": "Choose a valid kind.",
    "comp.error.own_goal_no_scorer": "An opponent's own goal has no scorer.", "comp.error.player_required": "Choose a player.",
    "comp.error.assist_is_scorer": "The assist can't be the scorer.", "comp.error.no_substitutions": "This format has rolling substitutions: no substitution events.",
    "comp.error.not_on_pitch": "This player is not on the pitch at that minute.", "comp.error.cannot_come_on": "This player can't come on (not on the bench, or already played).",
    "comp.error.too_many_sets": "More sets than the match has.", "comp.error.score_required": "Enter the score per period or the final score.",
    "comp.warn.goals_mismatch": "The goals in the timeline don't add up to our score.", "comp.warn.too_many_subs": "More substitutions than the format allows.",
    "comp.warn.starter_without_position": "A starter has no position.", "comp.warn.shootout_not_draw": "A shootout is filled in but the match wasn't a draw."},
  "fr": {"flash.report_saved": "Rapport de match enregistré.", "activity.action.report_saved": "Rapports de match enregistrés",
    "comp.error.minute_out_of_range": "Cette minute est hors du match.", "comp.error.minute_required": "Une minute est requise ici.",
    "comp.error.not_in_lineup": "Ce joueur ne fait pas partie du groupe du match.", "comp.error.invalid_subtype": "Choisissez un type valide.",
    "comp.error.own_goal_no_scorer": "Un but contre son camp adverse n'a pas de buteur.", "comp.error.player_required": "Choisissez un joueur.",
    "comp.error.assist_is_scorer": "Le passeur ne peut pas être le buteur.", "comp.error.no_substitutions": "Ce format a des changements volants : pas d'événements de remplacement.",
    "comp.error.not_on_pitch": "Ce joueur n'est pas sur le terrain à cette minute.", "comp.error.cannot_come_on": "Ce joueur ne peut pas entrer (pas sur le banc, ou déjà joué).",
    "comp.error.too_many_sets": "Plus de sets que le match n'en compte.", "comp.error.score_required": "Saisissez le score par période ou le score final.",
    "comp.warn.goals_mismatch": "Les buts du déroulé ne correspondent pas à notre score.", "comp.warn.too_many_subs": "Plus de remplacements que le format n'en autorise.",
    "comp.warn.starter_without_position": "Un titulaire n'a pas de poste.", "comp.warn.shootout_not_draw": "Des tirs au but sont saisis alors que le match n'était pas nul."},
  "ar": {"flash.report_saved": "تم حفظ تقرير المباراة.", "activity.action.report_saved": "تقارير مباريات حُفظت",
    "comp.error.minute_out_of_range": "هذه الدقيقة خارج وقت المباراة.", "comp.error.minute_required": "الدقيقة مطلوبة هنا.",
    "comp.error.not_in_lineup": "هذا اللاعب ليس ضمن قائمة المباراة.", "comp.error.invalid_subtype": "اختر نوعاً صحيحاً.",
    "comp.error.own_goal_no_scorer": "هدف المنافس في مرماه ليس له مسجل.", "comp.error.player_required": "اختر لاعباً.",
    "comp.error.assist_is_scorer": "صاحب التمريرة لا يمكن أن يكون المسجل.", "comp.error.no_substitutions": "هذا النظام بتغييرات حرة: لا أحداث تغيير.",
    "comp.error.not_on_pitch": "هذا اللاعب ليس في الميدان في تلك الدقيقة.", "comp.error.cannot_come_on": "لا يمكن لهذا اللاعب الدخول (ليس في الاحتياط أو سبق له اللعب).",
    "comp.error.too_many_sets": "عدد الأشواط أكبر من أشواط المباراة.", "comp.error.score_required": "أدخل النتيجة لكل فترة أو النتيجة النهائية.",
    "comp.warn.goals_mismatch": "أهداف سجل الأحداث لا تطابق نتيجتنا.", "comp.warn.too_many_subs": "عدد التغييرات أكثر مما يسمح به النظام.",
    "comp.warn.starter_without_position": "لاعب أساسي بلا مركز.", "comp.warn.shootout_not_draw": "ركلات الترجيح مُدخلة والمباراة لم تنته بالتعادل."}
}
EOF
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="ReportValidatorTest|MatchReportTest|LineupTest|Activity|UsersBatch|FlashTranslation"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Competitions/ReportValidator.php app/Services/Competitions/ReportWarnings.php app/Services/Competitions/MatchPage.php app/Http/Controllers/GameReportController.php app/Http/Controllers/GameController.php routes/web.php app/Services/Activity/ActivityAction.php config/activity.php tests/Unit/ActivityRecorderTest.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Unit/ReportValidatorTest.php tests/Feature/Competitions/MatchReportTest.php
git commit -m "feat(competitions): match report with timeline, grid and minutes played

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Match report UI

**Files:**
- Create: `resources/js/Components/Competitions/MatchReport.vue`
- Modify: `resources/js/Pages/Games/Show.vue`

**Interfaces:**
- Consumes: the `report` prop (Task 5) and the `games.report.update` route.
- Produces: `<MatchReport :game-id :report :can-manage />`.

- [ ] **Step 1: MatchReport component**

`resources/js/Components/Competitions/MatchReport.vue`:

```vue
<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import IconButton from '@/Components/IconButton.vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const props = defineProps({ gameId: Number, report: Object, canManage: Boolean });
const rules = props.report.rules;
const isSets = rules.scoring === 'sets';
const blank = (v) => (v === null || v === undefined ? '' : v);
const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

const periods = ref(Array.from({ length: rules.periods_count }, (_, i) => {
    const row = props.report.period_scores?.[i];
    return { us: blank(row?.[0]), them: blank(row?.[1]) };
}));
const et = ref({
    played: props.report.extra_time_played,
    us: blank(props.report.period_scores?.[rules.periods_count]?.[0]),
    them: blank(props.report.period_scores?.[rules.periods_count]?.[1]),
});
const shootout = ref({ us: blank(props.report.shootout_us), them: blank(props.report.shootout_them) });
const typed = ref({ us: blank(props.report.our_score), them: blank(props.report.their_score) });
const events = ref(props.report.events.map((e) => ({ ...e, minute: blank(e.minute), period: blank(e.period), player_id: blank(e.player_id), related_player_id: blank(e.related_player_id) })));
const players = ref(props.report.players.map((p) => ({ ...p, minutes_played: blank(p.minutes_played), sets_played: blank(p.sets_played), rating: blank(p.rating), coach_note: p.coach_note ?? '' })));
const mvp = ref(props.report.players.find((p) => p.is_mvp)?.player_id ?? null);
const notes = ref(props.report.notes ?? '');

const derived = computed(() => {
    const rows = [...periods.value, ...(et.value.played ? [et.value] : [])].filter((r) => r.us !== '' && r.them !== '');
    if (!rows.length) return null;
    if (isSets) return { us: rows.filter((r) => +r.us > +r.them).length, them: rows.filter((r) => +r.them > +r.us).length };
    return { us: rows.reduce((s, r) => s + +r.us, 0), them: rows.reduce((s, r) => s + +r.them, 0) };
});

const types = computed(() => (rules.rolling_subs ? ['goal', 'sanction'] : ['goal', 'sanction', 'substitution']));
const goalKinds = ['normal', 'penalty', 'free_kick', 'opponent_own_goal'];
const lineupPlayers = computed(() => props.report.players.map((p) => ({ id: p.player_id, label: `${p.shirt_number ?? ''} ${p.name}`.trim() })));
const sanctionLabel = (key) => {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
};
function addEvent(type) {
    events.value.push({
        type,
        subtype: type === 'goal' ? 'normal' : type === 'sanction' ? (rules.sanction_types[0]?.key ?? '') : null,
        period: '', minute: '', player_id: '', related_player_id: '',
    });
}
// Shown in minute order; events without a minute stay at the end in entry order.
const ordered = computed(() => events.value
    .map((e, index) => ({ e, index }))
    .sort((a, b) => (a.e.minute === '' ? 1e9 : +a.e.minute) - (b.e.minute === '' ? 1e9 : +b.e.minute) || a.index - b.index));

const form = useForm({});
function save() {
    form.transform(() => ({
        period_scores: [
            ...periods.value.map((r) => [num(r.us), num(r.them)]),
            ...(et.value.played ? [[num(et.value.us), num(et.value.them)]] : []),
        ],
        extra_time_played: !!et.value.played,
        our_score: num(typed.value.us),
        their_score: num(typed.value.them),
        shootout_us: num(shootout.value.us),
        shootout_them: num(shootout.value.them),
        events: events.value.map((e) => ({
            type: e.type, subtype: e.subtype || null, period: num(e.period), minute: num(e.minute),
            player_id: e.subtype === 'opponent_own_goal' ? null : num(e.player_id),
            related_player_id: e.subtype === 'opponent_own_goal' ? null : num(e.related_player_id),
        })),
        players: players.value.map((p) => ({
            player_id: p.player_id, played: !!p.played, minutes_played: num(p.minutes_played), sets_played: num(p.sets_played),
            rating: num(p.rating), coach_note: p.coach_note || null,
        })),
        mvp_player_id: mvp.value,
        notes: notes.value || null,
    })).put(route('games.report.update', props.gameId), { preserveScroll: true });
}
const err = (key) => (form.errors[key] ? t(form.errors[key]) : '');
const icon = { goal: '⚽', sanction: '🟨', substitution: '🔁' };
</script>

<template>
    <div class="space-y-6">
        <ul v-if="report.warnings.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <li v-for="w in report.warnings" :key="w">{{ t(w) }}</li>
        </ul>

        <!-- 1. Score -->
        <section class="space-y-2">
            <h3 class="font-semibold">{{ t('comp.report_score') }}</h3>
            <div class="grid max-w-md grid-cols-[1fr_5rem_5rem] items-center gap-2 text-sm" dir="ltr">
                <span></span><span class="text-center text-xs uppercase text-slate-500">{{ t('comp.us') }}</span><span class="text-center text-xs uppercase text-slate-500">{{ t('comp.them') }}</span>
                <template v-for="(row, i) in periods" :key="i">
                    <span>{{ t(`comp.period_${rules.period_label}`) }} {{ i + 1 }}</span>
                    <input v-model="row.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="row.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <template v-if="rules.has_extra_time">
                    <label class="flex items-center gap-2"><input v-model="et.played" :disabled="!canManage" type="checkbox" class="rounded" /> {{ t('comp.extra_time') }}</label>
                    <input v-model="et.us" :disabled="!canManage || !et.played" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="et.them" :disabled="!canManage || !et.played" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <template v-if="rules.has_shootout">
                    <span>{{ t('comp.shootout') }}</span>
                    <input v-model="shootout.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="shootout.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
                <span class="font-semibold">{{ t('comp.final_score') }}</span>
                <template v-if="derived">
                    <span class="text-center text-lg font-bold">{{ derived.us }}</span><span class="text-center text-lg font-bold">{{ derived.them }}</span>
                </template>
                <template v-else>
                    <input v-model="typed.us" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                    <input v-model="typed.them" :disabled="!canManage" type="number" min="0" class="rounded border-slate-300 text-center dark:border-slate-700 dark:bg-slate-800" />
                </template>
            </div>
            <InputError :message="err('our_score')" />
        </section>

        <!-- 2. Timeline -->
        <section class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold">{{ t('comp.timeline') }}</h3>
                <template v-if="canManage">
                    <SecondaryButton v-for="type in types" :key="type" @click="addEvent(type)">+ {{ t(`comp.event_${type}`) }}</SecondaryButton>
                </template>
            </div>
            <div v-for="{ e, index } in ordered" :key="index" class="flex flex-wrap items-start gap-2 rounded-lg bg-slate-50 p-2 text-sm dark:bg-slate-800/60">
                <span class="w-6 text-center">{{ icon[e.type] }}</span>
                <div>
                    <input v-model="e.minute" :disabled="!canManage" type="number" min="0" :placeholder="t('comp.minute')" class="w-20 rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <InputError :message="err(`events.${index}.minute`)" />
                </div>
                <select v-if="e.type === 'goal'" v-model="e.subtype" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="k in goalKinds" :key="k" :value="k">{{ t(`comp.goal_${k}`) }}</option>
                </select>
                <select v-if="e.type === 'sanction'" v-model="e.subtype" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="s in rules.sanction_types" :key="s.key" :value="s.key">{{ sanctionLabel(s.key) }}</option>
                </select>
                <div v-if="e.subtype !== 'opponent_own_goal'">
                    <select v-model="e.player_id" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t(e.type === 'substitution' ? 'comp.player_in' : e.type === 'goal' ? 'comp.scorer' : 'comp.player') }}</option>
                        <option v-for="p in lineupPlayers" :key="p.id" :value="p.id">{{ p.label }}</option>
                    </select>
                    <InputError :message="err(`events.${index}.player_id`)" />
                </div>
                <div v-if="e.type !== 'sanction' && e.subtype !== 'opponent_own_goal'">
                    <select v-model="e.related_player_id" :disabled="!canManage" class="rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t(e.type === 'substitution' ? 'comp.player_out' : 'comp.assist') }}</option>
                        <option v-for="p in lineupPlayers" :key="p.id" :value="p.id">{{ p.label }}</option>
                    </select>
                    <InputError :message="err(`events.${index}.related_player_id`)" />
                </div>
                <InputError :message="err(`events.${index}.subtype`) || err(`events.${index}.type`)" />
                <IconButton v-if="canManage" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="events.splice(index, 1)" />
            </div>
            <p v-if="!events.length" class="text-sm text-slate-500">{{ t('comp.no_events') }}</p>
        </section>

        <!-- 3. Player grid -->
        <section class="space-y-2">
            <h3 class="font-semibold">{{ t('comp.player_grid') }}</h3>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="text-xs uppercase text-slate-500"><tr>
                    <th class="py-1 text-start">{{ t('comp.player') }}</th>
                    <th v-if="rules.rolling_subs" class="py-1">{{ t('comp.played') }}</th>
                    <th class="py-1">{{ isSets ? t('comp.sets_played') : t('comp.minutes') }}</th>
                    <th class="py-1">{{ t('comp.rating') }}</th>
                    <th class="py-1 text-start">{{ t('comp.coach_note') }}</th>
                    <th class="py-1">{{ t('comp.mvp_short') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="(p, i) in players" :key="p.player_id">
                        <td class="py-1"><span class="font-mono text-slate-500">{{ p.shirt_number }}</span> {{ p.name }}
                            <span class="text-xs text-slate-400">· {{ t(p.role === 'starter' ? 'comp.starter' : 'comp.substitute') }}</span></td>
                        <td v-if="rules.rolling_subs" class="py-1 text-center"><input v-model="p.played" :disabled="!canManage" type="checkbox" class="rounded" /></td>
                        <td class="py-1 text-center">
                            <input v-if="isSets" v-model="p.sets_played" :disabled="!canManage" type="number" min="0" :max="rules.periods_count" class="w-16 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <input v-else-if="rules.rolling_subs" v-model="p.minutes_played" :disabled="!canManage" type="number" min="0" class="w-16 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-else class="font-mono">{{ p.minutes_played ?? '—' }}</span>
                            <InputError :message="err(`players.${i}.minutes_played`) || err(`players.${i}.sets_played`)" />
                        </td>
                        <td class="py-1 text-center"><input v-model="p.rating" :disabled="!canManage" type="number" min="1" max="10" class="w-14 rounded border-slate-300 text-center text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                        <td class="py-1"><input v-model="p.coach_note" :disabled="!canManage" class="w-full rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                        <td class="py-1 text-center"><input v-model="mvp" :value="p.player_id" :disabled="!canManage" type="radio" name="mvp" /></td>
                    </tr>
                </tbody>
            </table>
            <p class="text-xs text-slate-500">{{ t('comp.ratings_private') }}</p>
        </section>

        <!-- 4. Notes -->
        <label class="block text-sm">{{ t('notes') }}
            <textarea v-model="notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
        </label>

        <div class="flex flex-wrap items-center gap-3">
            <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('comp.save_report') }}</PrimaryButton>
            <a v-if="report.status === 'played'" :href="route('games.report-sheet', gameId)" target="_blank" class="text-sm text-primary-600 hover:underline">{{ t('comp.report_sheet_pdf') }}</a>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Wire the tab**

In `resources/js/Pages/Games/Show.vue`:
- Add `import MatchReport from '@/Components/Competitions/MatchReport.vue';`.
- Add `report: Object` to `defineProps`.
- Change the tab list to `['info', 'callup', 'lineup', 'report']`.
- After the line-up `<section>`, add:

```vue
            <section v-if="tab === 'report'" class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p v-if="!report.players.length" class="text-sm text-slate-500">{{ t('comp.do_callup_first') }}</p>
                <MatchReport v-else :game-id="game.id" :report="report" :can-manage="canManage" />
            </section>
```

- [ ] **Step 3: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.tab_report": "Report", "comp.report_score": "Score", "comp.us": "Us", "comp.them": "Them", "comp.extra_time": "Extra time", "comp.shootout": "Shootout",
    "comp.final_score": "Final score", "comp.timeline": "Timeline", "comp.event_goal": "Goal", "comp.event_sanction": "Sanction", "comp.event_substitution": "Substitution",
    "comp.minute": "Min.", "comp.goal_normal": "Goal", "comp.goal_penalty": "Penalty", "comp.goal_free_kick": "Free kick", "comp.goal_opponent_own_goal": "Opponent's own goal",
    "comp.player_in": "Player on", "comp.player_out": "Player off", "comp.scorer": "Scorer", "comp.assist": "Assist (optional)", "comp.no_events": "No events yet.",
    "comp.player_grid": "Players", "comp.played": "Played", "comp.sets_played": "Sets", "comp.minutes": "Minutes", "comp.rating": "Rating /10", "comp.coach_note": "Coach note",
    "comp.mvp_short": "MOTM", "comp.starter": "starter", "comp.substitute": "substitute", "comp.ratings_private": "Ratings and notes are for staff only and never printed.",
    "comp.save_report": "Save report", "comp.report_sheet_pdf": "Match report (PDF)"},
  "fr": {"comp.tab_report": "Rapport", "comp.report_score": "Score", "comp.us": "Nous", "comp.them": "Eux", "comp.extra_time": "Prolongation", "comp.shootout": "Tirs au but",
    "comp.final_score": "Score final", "comp.timeline": "Déroulé", "comp.event_goal": "But", "comp.event_sanction": "Sanction", "comp.event_substitution": "Remplacement",
    "comp.minute": "Min.", "comp.goal_normal": "But", "comp.goal_penalty": "Penalty", "comp.goal_free_kick": "Coup franc", "comp.goal_opponent_own_goal": "But contre son camp adverse",
    "comp.player_in": "Entrant", "comp.player_out": "Sortant", "comp.scorer": "Buteur", "comp.assist": "Passeur (facultatif)", "comp.no_events": "Aucun événement.",
    "comp.player_grid": "Joueurs", "comp.played": "A joué", "comp.sets_played": "Sets", "comp.minutes": "Minutes", "comp.rating": "Note /10", "comp.coach_note": "Note de l'entraîneur",
    "comp.mvp_short": "Homme du match", "comp.starter": "titulaire", "comp.substitute": "remplaçant", "comp.ratings_private": "Les notes et commentaires sont réservés au staff et jamais imprimés.",
    "comp.save_report": "Enregistrer le rapport", "comp.report_sheet_pdf": "Rapport de match (PDF)"},
  "ar": {"comp.tab_report": "التقرير", "comp.report_score": "النتيجة", "comp.us": "نحن", "comp.them": "المنافس", "comp.extra_time": "الوقت الإضافي", "comp.shootout": "ركلات الترجيح",
    "comp.final_score": "النتيجة النهائية", "comp.timeline": "سجل الأحداث", "comp.event_goal": "هدف", "comp.event_sanction": "عقوبة", "comp.event_substitution": "تغيير",
    "comp.minute": "د.", "comp.goal_normal": "هدف", "comp.goal_penalty": "ركلة جزاء", "comp.goal_free_kick": "ركلة حرة", "comp.goal_opponent_own_goal": "هدف المنافس في مرماه",
    "comp.player_in": "الداخل", "comp.player_out": "الخارج", "comp.scorer": "المسجل", "comp.assist": "صاحب التمريرة (اختياري)", "comp.no_events": "لا أحداث بعد.",
    "comp.player_grid": "اللاعبون", "comp.played": "شارك", "comp.sets_played": "الأشواط", "comp.minutes": "الدقائق", "comp.rating": "التقييم /10", "comp.coach_note": "ملاحظة المدرب",
    "comp.mvp_short": "رجل المباراة", "comp.starter": "أساسي", "comp.substitute": "احتياطي", "comp.ratings_private": "التقييمات والملاحظات للطاقم فقط ولا تُطبع أبداً.",
    "comp.save_report": "حفظ التقرير", "comp.report_sheet_pdf": "تقرير المباراة (PDF)"}
}
EOF
```

- [ ] **Step 4: Build and run**

Run: `npm run build`, then `php artisan test --filter="MatchReportTest|LineupTest|GamesTest"` and `npm run i18n:check`.
Expected: everything passes.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Competitions/MatchReport.vue resources/js/Pages/Games/Show.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(competitions): match report tab with score, timeline and player grid

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Line-up sheet and match report PDFs

**Files:**
- Create: `app/Services/Competitions/MatchSheets.php`, `app/Services/Competitions/PitchDrawing.php`, `app/Http/Controllers/GameSheetController.php`
- Create: `resources/views/pdf/game-lineup-sheet.blade.php`, `resources/views/pdf/game-report-sheet.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/Competitions/MatchSheetsTest.php`

**Interfaces:**
- Consumes: `games.rules`, `game_players`, `game_events`, `ClubHeader::data()`, `PdfService::stream()`, `UiLang::get()`.
- Produces:
  - `MatchSheets::lineup(Game): array`.
  - `MatchSheets::report(Game): array`. Neither includes ratings or coach notes.
  - `PitchDrawing::svg(string $sport, list<array{x:int,y:int,label:string}> $dots): string`.
  - Routes `games.lineup-sheet` (`GET /games/{game}/lineup-sheet`) and `games.report-sheet` (`GET /games/{game}/report-sheet`), both games/view.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Competitions/MatchSheetsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Enums\GameStatus;
use App\Models\Formation;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Services\Competitions\MatchSheets;
use App\Services\Competitions\PitchDrawing;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MatchSheetsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function playedMatch(): array
    {
        $team = $this->team();
        $formation = Formation::create(['game_format_id' => $team->default_game_format_id, 'name' => '1-1', 'slots' => [
            ['label' => 'GK', 'x' => 50, 'y' => 5], ['label' => 'ST', 'x' => 50, 'y' => 80],
        ]]);
        [$a, $b, $c] = [$this->player($team->category), $this->player($team->category), $this->player($team->category)];
        $this->onRoster($team, 2026, [$a, $b, $c]);
        $game = $this->game($team, ['formation_id' => $formation->id, 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0, 'period_scores' => [[1, 0], [0, 0]]]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $a->id, 'called_up' => true, 'role' => 'starter', 'slot' => 0, 'shirt_number' => 1, 'is_goalkeeper' => true, 'minutes_played' => 90, 'played' => true, 'rating' => 9, 'coach_note' => 'SECRET-NOTE']);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $b->id, 'called_up' => true, 'role' => 'starter', 'slot' => 1, 'shirt_number' => 9, 'is_captain' => true, 'minutes_played' => 60, 'played' => true, 'is_mvp' => true]);
        GamePlayer::create(['game_id' => $game->id, 'player_id' => $c->id, 'called_up' => true, 'role' => 'bench', 'shirt_number' => 12, 'minutes_played' => 30, 'played' => true]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 20, 'player_id' => $b->id, 'related_player_id' => $a->id]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'substitution', 'minute' => 60, 'player_id' => $c->id, 'related_player_id' => $b->id]);

        return [$game, $a, $b, $c];
    }

    #[Test]
    public function the_line_up_sheet_data_lists_starters_bench_and_pitch_dots(): void
    {
        [$game, $a, $b, $c] = $this->playedMatch();
        $data = app(MatchSheets::class)->lineup($game);

        $this->assertSame([1, 9], array_column($data['starters'], 'shirt'));
        $this->assertTrue($data['starters'][1]['captain']);
        $this->assertTrue($data['starters'][0]['goalkeeper']);
        $this->assertSame('GK', $data['starters'][0]['position']);
        $this->assertSame([$c->fullname], array_column($data['bench'], 'name'));
        $this->assertStringContainsString('<svg', $data['pitch']);
    }

    #[Test]
    public function the_report_data_has_events_minutes_and_mvp_but_never_ratings_or_notes(): void
    {
        [$game, $a, $b, $c] = $this->playedMatch();
        $data = app(MatchSheets::class)->report($game);

        $this->assertSame([1, 0], [$data['score']['us'], $data['score']['them']]);
        $this->assertSame([[1, 0], [0, 0]], $data['score']['periods']);
        $this->assertSame($b->fullname, $data['goals'][0]['scorer']);
        $this->assertSame($a->fullname, $data['goals'][0]['assist']);
        $this->assertSame([$c->fullname, $b->fullname], [$data['subs'][0]['in'], $data['subs'][0]['out']]);
        $this->assertSame($b->fullname, $data['mvp']);
        $this->assertSame(60, collect($data['players'])->firstWhere('name', $b->fullname)['minutes']);
        $flat = json_encode($data);
        $this->assertStringNotContainsString('SECRET-NOTE', $flat);
        $this->assertStringNotContainsString('rating', $flat);
    }

    #[Test]
    public function both_sheets_stream_as_pdfs_for_viewers(): void
    {
        [$game] = $this->playedMatch();
        $admin = $this->admin();

        foreach (['games.lineup-sheet', 'games.report-sheet'] as $route) {
            $response = $this->actingAs($admin)->get(route($route, $game));
            $response->assertOk();
            $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
            $this->assertSame(['games', 'view'], PermissionMap::resolve($route));
        }
    }

    #[Test]
    public function the_pitch_drawing_places_dots_in_svg_coordinates(): void
    {
        $svg = PitchDrawing::svg('football', [['x' => 50, 'y' => 0, 'label' => '1']]);

        $this->assertStringContainsString('cx="34"', $svg);
        $this->assertStringContainsString('cy="105"', $svg);
        $this->assertStringContainsString('>1<', $svg);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=MatchSheetsTest`
Expected: FAIL, with class not found.

- [ ] **Step 3: PitchDrawing and MatchSheets**

`app/Services/Competitions/PitchDrawing.php`:

```php
<?php

namespace App\Services\Competitions;

/**
 * The line-up pitch as standalone SVG for the PDF (mPDF renders it as an
 * image). Only shirt numbers are drawn inside it — names go in the table
 * below, so no Arabic text has to be shaped inside the SVG.
 */
final class PitchDrawing
{
    /** @param  list<array{x: int, y: int, label: string}>  $dots  x/y in 0–100, y from our own goal */
    public static function svg(string $sport, array $dots): string
    {
        $football = in_array($sport, ['football', 'futsal'], true);
        $surface = $football ? '#16a34a' : '#fde68a';
        $lines = $football
            ? '<rect x="2" y="2" width="64" height="101"/><line x1="2" y1="52.5" x2="66" y2="52.5"/><circle cx="34" cy="52.5" r="9.15"/>'
                .'<rect x="13.85" y="2" width="40.3" height="16.5"/><rect x="13.85" y="86.5" width="40.3" height="16.5"/>'
            : '<rect x="2" y="2" width="64" height="101"/><line x1="2" y1="52.5" x2="66" y2="52.5"/><circle cx="34" cy="52.5" r="6"/>';

        $marks = '';
        foreach ($dots as $dot) {
            $cx = round($dot['x'] * 68 / 100, 2);
            $cy = round((100 - $dot['y']) * 105 / 100, 2);
            $label = htmlspecialchars($dot['label'], ENT_XML1);
            $marks .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"3.4\" fill=\"#ffffff\" stroke=\"#0f172a\" stroke-width=\"0.5\"/>"
                ."<text x=\"{$cx}\" y=\"".($cy + 1.3)."\" font-size=\"3.6\" font-family=\"sans-serif\" font-weight=\"bold\" text-anchor=\"middle\" fill=\"#0f172a\">{$label}</text>";
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 68 105" width="68mm" height="105mm">'
            ."<rect x=\"0\" y=\"0\" width=\"68\" height=\"105\" fill=\"{$surface}\"/>"
            ."<g fill=\"none\" stroke=\"#ffffff\" stroke-width=\"0.4\">{$lines}</g>{$marks}</svg>";
    }
}
```

`app/Services/Competitions/MatchSheets.php`:

```php
<?php

namespace App\Services\Competitions;

use App\Models\Formation;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Pdf\ClubHeader;
use Carbon\CarbonImmutable;

/**
 * Data for the two printable match sheets. Ratings and coach notes are
 * deliberately never read here: the sheets get posted and shared.
 */
final class MatchSheets
{
    /** @return array<string, mixed> */
    public function lineup(Game $game): array
    {
        $game->loadMissing(['team.category', 'competition', 'opponent', 'venue', 'players.player']);
        $formation = $game->formation_id ? Formation::find($game->formation_id) : null;
        $called = $game->players->where('called_up', true);
        $starters = $called->where('role', 'starter')->sortBy('slot');

        return [
            'club' => ClubHeader::data(),
            'game' => $this->header($game),
            'pitch' => PitchDrawing::svg((string) $game->rule('sport'), $starters->map(fn (GamePlayer $gp) => [
                'x' => (int) ($gp->pos_x ?? $formation?->slots[$gp->slot]['x'] ?? 50),
                'y' => (int) ($gp->pos_y ?? $formation?->slots[$gp->slot]['y'] ?? 50),
                'label' => (string) ($gp->shirt_number ?? ''),
            ])->values()->all()),
            'formation' => $formation?->name,
            'starters' => $starters->map(fn (GamePlayer $gp) => [
                'shirt' => $gp->shirt_number,
                'name' => $gp->player->fullname,
                'position' => $formation?->slots[$gp->slot]['label'] ?? null,
                'captain' => $gp->is_captain,
                'goalkeeper' => $gp->is_goalkeeper,
            ])->values()->all(),
            'bench' => $called->where('role', '!=', 'starter')->sortBy(fn (GamePlayer $gp) => $gp->shirt_number ?? 999)
                ->map(fn (GamePlayer $gp) => ['shirt' => $gp->shirt_number, 'name' => $gp->player->fullname])->values()->all(),
            'coaches' => $this->coaches($game),
        ];
    }

    /** @return array<string, mixed> */
    public function report(Game $game): array
    {
        $game->loadMissing(['team.category', 'competition', 'opponent', 'venue', 'players.player']);
        $events = $game->events()->with(['player', 'relatedPlayer'])->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get();
        $name = fn (?object $player) => $player?->fullname;
        $called = $game->players->where('called_up', true);
        $goalsBy = $events->where('type', 'goal')->countBy('player_id');
        $cardsBy = $events->where('type', 'sanction')->groupBy('player_id')->map(fn ($list) => $list->pluck('subtype')->all());
        $sets = $game->rule('scoring') === 'sets';
        $regular = (int) $game->rule('periods_count');
        $periods = array_slice($game->period_scores ?? [], 0, $regular);

        return [
            'club' => ClubHeader::data(),
            'game' => $this->header($game),
            'score' => [
                'us' => $game->our_score,
                'them' => $game->their_score,
                'period_label' => $game->rule('period_label'),
                'periods' => $periods,
                'extra_time' => $game->extra_time_played ? (($game->period_scores ?? [])[$regular] ?? null) : null,
                'shootout' => $game->shootout_us !== null ? [$game->shootout_us, $game->shootout_them] : null,
            ],
            'goals' => $events->where('type', 'goal')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'kind' => $e->subtype, 'scorer' => $name($e->player), 'assist' => $name($e->relatedPlayer),
            ])->values()->all(),
            'sanctions' => $events->where('type', 'sanction')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'kind' => $e->subtype, 'player' => $name($e->player),
            ])->values()->all(),
            'subs' => $events->where('type', 'substitution')->map(fn (GameEvent $e) => [
                'minute' => $e->minute, 'in' => $name($e->player), 'out' => $name($e->relatedPlayer),
            ])->values()->all(),
            'players' => $called->filter(fn (GamePlayer $gp) => $gp->played || $gp->role === 'starter')
                ->sortBy([fn ($a, $b) => ($a->role === 'starter' ? 0 : 1) <=> ($b->role === 'starter' ? 0 : 1), fn ($a, $b) => ($a->shirt_number ?? 999) <=> ($b->shirt_number ?? 999)])
                ->map(fn (GamePlayer $gp) => [
                    'shirt' => $gp->shirt_number,
                    'name' => $gp->player->fullname,
                    'starter' => $gp->role === 'starter',
                    'minutes' => $sets ? null : $gp->minutes_played,
                    'sets' => $sets ? $gp->sets_played : null,
                    'goals' => $goalsBy[$gp->player_id] ?? 0,
                    'cards' => $cardsBy[$gp->player_id] ?? [],
                ])->values()->all(),
            'mvp' => $name($called->firstWhere('is_mvp', true)?->player),
            'coaches' => $this->coaches($game),
        ];
    }

    /** @return array<string, mixed> */
    private function header(Game $game): array
    {
        return [
            'team' => $game->team->name.' · '.$game->team->category?->localized_name,
            'opponent' => $game->opponent->name,
            'side' => $game->side->value,
            'competition' => $game->competition?->name,
            'round' => $game->round_label,
            'dateLabel' => CarbonImmutable::createFromFormat('!Y-m-d', $game->date)->locale(app()->getLocale())->translatedFormat('l j F Y'),
            'kickoff' => $game->kickoff_time,
            'venue' => $game->venue?->name,
        ];
    }

    /** @return list<string> */
    private function coaches(Game $game): array
    {
        return $game->team->coachesFor($game->season_start_year)->get()
            ->sortBy(fn (User $u) => $u->pivot->role === 'head' ? 0 : 1)
            ->map(fn (User $u) => $u->fullname)->values()->all();
    }
}
```

- [ ] **Step 4: Controller, views, routes, permissions**

`app/Http/Controllers/GameSheetController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Competitions\MatchSheets;
use App\Services\Pdf\PdfService;
use Symfony\Component\HttpFoundation\Response;

class GameSheetController extends Controller
{
    public function lineup(Game $game, MatchSheets $sheets, PdfService $pdf): Response
    {
        $html = view('pdf.game-lineup-sheet', $sheets->lineup($game))->render();

        return $pdf->stream($html, "lineup-{$game->id}-{$game->date}.pdf", app()->getLocale() === 'ar');
    }

    public function report(Game $game, MatchSheets $sheets, PdfService $pdf): Response
    {
        $html = view('pdf.game-report-sheet', $sheets->report($game))->render();

        return $pdf->stream($html, "match-report-{$game->id}-{$game->date}.pdf", app()->getLocale() === 'ar');
    }
}
```

`resources/views/pdf/game-lineup-sheet.blade.php`:

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
        table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.list th { background: #f1f5f9; padding: 4px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 4px 6px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 12mm; font-weight: bold; }
        .pitch { text-align: center; margin: 8px 0; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.sheet.lineup_title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    <div class="sub">{{ $game['dateLabel'] }}@if ($game['kickoff']) · <bdi dir="ltr">{{ $game['kickoff'] }}</bdi>@endif @if ($game['venue']) · {{ $game['venue'] }}@endif</div>
    @if ($game['competition'])
        <div class="sub">{{ $game['competition'] }}@if ($game['round']) · {{ $game['round'] }}@endif</div>
    @endif

    <div class="pitch"><img src="data:image/svg+xml;base64,{{ base64_encode($pitch) }}" style="width: 68mm; height: 105mm;" />
        @if ($formation)<div class="muted">{{ $L('comp.formation') }}: {{ $formation }}</div>@endif
    </div>

    <table class="list">
        <thead><tr><th>{{ $L('comp.shirt') }}</th><th>{{ $L('comp.player') }}</th><th>{{ $L('comp.spot') }}</th><th></th></tr></thead>
        <tbody>
            @foreach ($starters as $s)
                <tr>
                    <td class="num">{{ $s['shirt'] }}</td>
                    <td>{{ $s['name'] }}</td>
                    <td>{{ $s['position'] }}</td>
                    <td>@if ($s['captain']) C @endif @if ($s['goalkeeper']) GK @endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($bench)
        <div class="sub" style="margin-top: 8px;"><b>{{ $L('comp.substitutes') }}:</b>
            @foreach ($bench as $b){{ $b['shirt'] }} {{ $b['name'] }}@if (! $loop->last) · @endif @endforeach
        </div>
    @endif
    @if ($coaches)
        <div class="sub">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
```

`resources/views/pdf/game-report-sheet.blade.php`:

```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $kind = function (?string $key) use ($L) {
        $label = $L('comp.sanction.'.$key);
        return $label === 'comp.sanction.'.$key ? (string) $key : $label;
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #1e293b; }
        .title { font-size: 18px; font-weight: bold; color: #02a85c; margin-bottom: 4px; }
        .sub { font-size: 12px; color: #334155; margin-bottom: 2px; }
        .score { font-size: 22px; font-weight: bold; text-align: center; margin: 6px 0; }
        h3 { font-size: 12px; margin: 10px 0 2px; color: #0f172a; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #f1f5f9; padding: 3px; border: 1px solid #94a3b8; font-size: 10px; }
        table.list td { padding: 3px 5px; border: 1px solid #94a3b8; }
        td.num { text-align: center; width: 12mm; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <div class="title">{{ $L('comp.sheet.report_title') }}</div>
    <div class="sub"><b>{{ $game['team'] }}</b> — {{ $game['opponent'] }} ({{ $L('comp.side_'.$game['side']) }})</div>
    <div class="sub">{{ $game['dateLabel'] }}@if ($game['competition']) · {{ $game['competition'] }}@endif @if ($game['round']) · {{ $game['round'] }}@endif</div>

    <div class="score"><bdi dir="ltr">{{ $score['us'] }} – {{ $score['them'] }}</bdi></div>
    <div class="sub" style="text-align: center;">
        @foreach ($score['periods'] as $i => $p)
            @if (isset($p[0], $p[1])){{ $L('comp.period_'.$score['period_label']) }} {{ $i + 1 }}: <bdi dir="ltr">{{ $p[0] }}–{{ $p[1] }}</bdi>@if (! $loop->last) · @endif @endif
        @endforeach
        @if ($score['extra_time'] && isset($score['extra_time'][0])) · {{ $L('comp.extra_time') }}: <bdi dir="ltr">{{ $score['extra_time'][0] }}–{{ $score['extra_time'][1] }}</bdi>@endif
        @if ($score['shootout']) · {{ $L('comp.shootout') }}: <bdi dir="ltr">{{ $score['shootout'][0] }}–{{ $score['shootout'][1] }}</bdi>@endif
    </div>

    @if ($goals)
        <h3>{{ $L('comp.sheet.goals') }}</h3>
        @foreach ($goals as $g)
            <div>@if ($g['minute'] !== null)<bdi dir="ltr">{{ $g['minute'] }}'</bdi> @endif
                {{ $g['kind'] === 'opponent_own_goal' ? $L('comp.goal_opponent_own_goal') : $g['scorer'] }}
                @if ($g['kind'] === 'penalty' || $g['kind'] === 'free_kick') <span class="muted">({{ $L('comp.goal_'.$g['kind']) }})</span>@endif
                @if ($g['assist']) <span class="muted">— {{ $L('comp.sheet.assist') }}: {{ $g['assist'] }}</span>@endif
            </div>
        @endforeach
    @endif

    @if ($sanctions)
        <h3>{{ $L('comp.sanctions') }}</h3>
        @foreach ($sanctions as $s)
            <div>@if ($s['minute'] !== null)<bdi dir="ltr">{{ $s['minute'] }}'</bdi> @endif{{ $s['player'] }} — {{ $kind($s['kind']) }}</div>
        @endforeach
    @endif

    @if ($subs)
        <h3>{{ $L('comp.sheet.substitutions') }}</h3>
        @foreach ($subs as $s)
            <div><bdi dir="ltr">{{ $s['minute'] }}'</bdi> ↑ {{ $s['in'] }} ↓ {{ $s['out'] }}</div>
        @endforeach
    @endif

    <h3>{{ $L('comp.player_grid') }}</h3>
    <table class="list">
        <thead><tr>
            <th>{{ $L('comp.shirt') }}</th><th>{{ $L('comp.player') }}</th><th></th>
            <th>{{ $players && $players[0]['sets'] !== null ? $L('comp.sets_played') : $L('comp.minutes') }}</th>
            <th>{{ $L('comp.sheet.goals') }}</th><th>{{ $L('comp.sanctions') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($players as $p)
                <tr>
                    <td class="num">{{ $p['shirt'] }}</td>
                    <td>{{ $p['name'] }}</td>
                    <td>{{ $p['starter'] ? $L('comp.starter') : $L('comp.substitute') }}</td>
                    <td class="num">{{ $p['sets'] ?? $p['minutes'] }}</td>
                    <td class="num">{{ $p['goals'] ?: '' }}</td>
                    <td>{{ implode(', ', array_map($kind, $p['cards'])) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($mvp)
        <div class="sub" style="margin-top: 8px;"><b>{{ $L('comp.sheet.mvp') }}:</b> {{ $mvp }}</div>
    @endif
    @if ($coaches)
        <div class="sub">{{ $L('comp.tab_coaches') }}: {{ implode(' · ', $coaches) }}</div>
    @endif
</body>
</html>
```

In `routes/web.php`, add `use App\Http\Controllers\GameSheetController;`. After the `games.report.update` route, add:

```php
        Route::get('/games/{game}/lineup-sheet', [GameSheetController::class, 'lineup'])->name('games.lineup-sheet');
        Route::get('/games/{game}/report-sheet', [GameSheetController::class, 'report'])->name('games.report-sheet');
```

In `config/permissions.php` → `overrides`, after `'games.convocation' => ['games', 'view'],`, add:

```php
        // Printable sheets only read: "lineup-sheet" / "report-sheet" are not view verbs.
        'games.lineup-sheet' => ['games', 'view'],
        'games.report-sheet' => ['games', 'view'],
```

**mPDF note:** if mPDF won't render the `data:image/svg+xml` image (an exception, or a blank area when you open the PDF), replace the `<img …>` with `{!! $pitch !!}` (inline SVG; mPDF supports both).

- [ ] **Step 5: Translations**

```bash
node .superpowers/sdd/i18n-by-locale.mjs <<'EOF'
{
  "en": {"comp.sheet.lineup_title": "Match line-up", "comp.sheet.report_title": "Match report", "comp.substitutes": "Substitutes",
    "comp.sheet.goals": "Goals", "comp.sheet.assist": "assist", "comp.sheet.substitutions": "Substitutions", "comp.sheet.mvp": "Player of the match"},
  "fr": {"comp.sheet.lineup_title": "Composition du match", "comp.sheet.report_title": "Rapport de match", "comp.substitutes": "Remplaçants",
    "comp.sheet.goals": "Buts", "comp.sheet.assist": "passe", "comp.sheet.substitutions": "Remplacements", "comp.sheet.mvp": "Homme du match"},
  "ar": {"comp.sheet.lineup_title": "تشكيلة المباراة", "comp.sheet.report_title": "تقرير المباراة", "comp.substitutes": "الاحتياط",
    "comp.sheet.goals": "الأهداف", "comp.sheet.assist": "تمريرة", "comp.sheet.substitutions": "التغييرات", "comp.sheet.mvp": "رجل المباراة"}
}
EOF
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="MatchSheetsTest|CompetitionPermissionsTest"`
Expected: PASS. Then generate one PDF by hand to check the pitch renders. Run `php artisan tinker`, create nothing, and use any played match if one exists; otherwise rely on the tests and report "visual check pending".

- [ ] **Step 7: Commit**

```bash
git add app/Services/Competitions/MatchSheets.php app/Services/Competitions/PitchDrawing.php app/Http/Controllers/GameSheetController.php resources/views/pdf/game-lineup-sheet.blade.php resources/views/pdf/game-report-sheet.blade.php routes/web.php config/permissions.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Competitions/MatchSheetsTest.php
git commit -m "feat(competitions): printable line-up sheet and match report

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Final verification

- [ ] **Step 1: Full checks**

Run, in `D:/irnb-competitions`:
- `npm run build`
- `php artisan test`: full suite, all green. The baseline before Phase 2 is the `main` suite. Report the counts.
- `npm run i18n:check`: exit 0.
- `npm run test:js`: passes.
- `vendor/bin/pint --dirty`: if it changes files, commit them as `style(competitions): pint`.

- [ ] **Step 2: Report**

Report every failure with the exact test name and output. Do not fix failures in unrelated pre-existing tests.

## Deferred (Phase 3)

Suspensions; statistics pages (player Matches tab, team / competition stats); the dashboard widget; licence, documents and unpaid warnings.
