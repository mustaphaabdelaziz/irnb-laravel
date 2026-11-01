# Statistics redesign — Phase 3 (document completion) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show how much of the club's paperwork is in. The figure is documents received out of documents required, plus how many players have a complete file. It goes on the Dashboard Members tab, the Players list and Teams/Show.

**Architecture:** `DocumentChecklist` gets two new per-type SQL twins: `requiredTypeSql` and `expiringTypeSql`. The existing `missingTypeSql` is the third. A new `App\Services\Player\DocumentStats` takes any players builder and works in two levels:
- an inner derived table computes one 1/0 column per type and per measure (required / expiring / missing) for each player;
- the outer query sums those columns. It is ungrouped for `summary()` and grouped by category for `breakdown()`.

The three pages add one new nullable key each, gated by `documents → view`. The frontend adds a pure helper (`lib/completion.js`) and a `CompletionBar` component, then places them on the three pages.

**Tech Stack:** Laravel 13, PHPUnit `#[Test]`, Inertia v2, Vue 3 `<script setup>`, Tailwind v3.4 (shadcn tokens, RTL logical utilities), chart.js 4.5 + vue-chartjs 5, vue-i18n flat dotted keys, node:test.

Spec: `docs/superpowers/specs/2026-11-01-stats-redesign-p3-documents-design.md` (owner decisions in its §2 are final). Parent spec: `docs/superpowers/specs/2026-11-01-stats-redesign-design.md` (§2.8, §5, §6 phase 3).

## Global Constraints

- No schema change, no route rename, no change to existing calculations or existing props' shape. In particular, `missingCountSql`, `missingTypeSql`, `expiringSoonSql`, `missing_documents_count`, `DocumentChecklist::for()` / `evaluate()` and the player checklist stay exactly as they are. New numbers = new keys.
- Shared components' public API unchanged: ChartCard, StatTable and StatTile keep their props, slots and emits. **StatTable and ChartCard are shared with the Reports Center** (`resources/js/Pages/Reports/*`).
- `documents → view` gates every placement: `$request->user()?->hasPermission('documents', 'view')`. Without it the server sends `null` and nothing renders.
- Offline only: no CDN, no remote resource, no new npm or composer dependency.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. Insert keys ONLY with the git-ignored insert-only helper:
  1. Write `{"key":{"ar":"…","fr":"…","en":"…"}}` to a JSON file in the scratchpad `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/`.
  2. From `D:\irnb-stats` run `node .superpowers/sdd/i18n-insert.mjs <that json>`.
  3. `git diff --stat resources/js/i18n` must show insertions only.
  4. `npm run i18n:check` must stay green.

  NEVER use `scripts/i18n-add.mjs`: it re-sorts the files and moves unrelated lines. A key segment after an existing message key must not collide with a String method name (`link`, `search`, …). `documents` is already a top-level message, so **no `documents.*` keys**. Every key in this plan is `stats.doc_*`.
- Totals: `columnTotals(columns, rows)` from `@/lib/statTable`. Pass the SAME computed object to `<StatTable :totals>` and to `csv.totals`. Never `total: 'sum'` a percent. Never `total: 'sum'` a count of distinct entities that can repeat across rows. `players_total` / `players_complete` per **category** may be summed, because categories partition players: each player has at most one `category_id`, and the null category is its own row.
- Charts read `themeVersion.value` (from `useChartTheme()`) in every computed that builds data or options, so they recolour on a theme toggle.
- Colours: `chartTheme.js` exports **no** positive / warning / negative tokens. It has only categorical slots plus sequential and ordinal ramps. Completion colours therefore come from one helper, `completionColors()` in `resources/js/lib/completion.js`:
  - received = `seriesColor(2)` (green);
  - expiring = `seriesColor(3)` (amber);
  - missing = `seriesColor(7)` (red).

  The CompletionBar and the dashboard charts both use this helper. Neighbouring parts are always separated by a 2 px surface-coloured edge, and every colour has its number printed beside it. Colour is never the only channel.
- Layout: logical Tailwind properties only (`ms-/me-/ps-/pe-/start/end/border-e/rounded-s`). It must work at 375 px with no page-level horizontal scroll. Use dark-mode tokens (`bg-card`, `border-border`, `text-muted-foreground`, `bg-muted`).
- Numbers and percents format through `useFormatNumber()` (`formatNumber`, `formatPercent`). Never `toFixed` + `'%'` in a template.
- Inertia test props round-trip through JSON, so a whole float (`50.0`) can arrive as an int (`50`). On Inertia props, assert pct with `assertEquals`, not `assertSame`. Direct PHP calls to `DocumentStats` keep floats: use `assertSame(50.0, …)` there.
- The finance migration seeds a `fiscal_years` row for the current year. A test that creates one must delete it first. No task here touches seasons; this note is only for reference.
- `ManualSuspensionsTest` "a manual ban is added in matches or dates and listed" fails from 2026-11-01 (a pre-existing date time-bomb). Not our problem; never "fix" it.
- Commits: explicit paths only (`git add <paths>`, never `-A` or `.`). Message trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never write outside `D:\irnb-stats` except in the scratchpad above. Never touch `D:\irnb-laravel`.
- Verification per task: the task's own PHPUnit filter and/or node tests. Frontend tasks also run `npm run build` and `npm run i18n:check`. Build before PHP feature tests (they need the Vite manifest). Run PHP tests serially (`php artisan test --filter=…`, no `--parallel`).

## Counting rules (from spec §3 — the code below implements exactly these)

Per player × per **active, required** document type:

| Measure | Rule |
|---|---|
| required | The type applies to the player's age (`earliestApplicableBirthdate`; a player with no birth date is not limited) AND the player has no `exempt` record for it. Photo exception: a non-empty `players.picture_url` beats an exempt record (`evaluate()` checks the picture first). The age limit still applies to a Photo; see "Spec ambiguities" at the end. |
| missing | The existing `missingTypeSql` (= `missingTermSql`): no exempt record and no received record valid today; Photo without picture. Missing ⊆ required. |
| expiring | Required, received record with `valid_until` in `[today, today+30]` (half-open `< today+31`, as `expiringSoonSql`), and for a Photo, no picture. A received record valid today means not missing. The `(player_id, document_type_id)` unique index means it is not exempt either. |
| received | required − missing (includes expiring). |
| complete player | missing total = 0 (a player with nothing required is complete). |

PHP oracle used by the agreement test, per type:
- `required = is_active && is_required && appliesTo(player) && evaluate().state !== NOT_REQUIRED`
- `expiring = required && state === EXPIRES_SOON`

## File structure

| File | Responsibility |
|---|---|
| `app/Services/Player/DocumentChecklist.php` (modify) | + `requiredTypeSql`, `expiringTypeSql`, private `ageConditions` (new code only) |
| `app/Services/Player/DocumentStats.php` (create) | Aggregates required / received / expiring / missing over any players builder |
| `app/Services/Dashboard/MemberStats.php` (modify) | + constructor (DocumentStats), + `documents(DashboardFilters)`: current members in the branch |
| `app/Http/Controllers/DashboardController.php` (modify) | members payload + `documents` key (gated) |
| `app/Http/Controllers/PlayerController.php` (modify) | + `documentStats` prop over `statsQuery($request)` (gated) |
| `app/Http/Controllers/TeamController.php` (modify) | + `documentStats` prop over the season roster (gated) |
| `resources/js/lib/completion.js` (create) | `segments`, `completionPct`, `completionColors`, `completionBarProps` |
| `resources/js/Components/Stats/CompletionBar.vue` (create) | The bar, headline, legend, missing link |
| `resources/js/Pages/Dashboard/Partials/MembersTab.vue` (modify) | Big bar + two ChartCards (by category, by type) |
| `resources/js/Pages/Players/Index.vue` (modify) | Slim strip above the results |
| `resources/js/Pages/Teams/Show.vue` (modify) | Strip + `<details>` fold-out over the roster |
| Tests | `tests/Feature/DocumentChecklistTest.php` (extend), `tests/Feature/DocumentStatsTest.php`, `tests/Feature/Dashboard/DashboardDocumentsTest.php`, `tests/Feature/Dashboard/DashboardPageTest.php` (budget), `tests/Feature/PlayerDocumentStatsTest.php`, `tests/Feature/Competitions/TeamDocumentStatsTest.php`, `tests/js/completion.test.mjs` |

---

### Task 1: Per-type `required` and `expiring` SQL twins

**Files:**
- Modify: `app/Services/Player/DocumentChecklist.php`. Insert the new public methods directly after `expiringSoonSql()` (it starts at line 177), before `/** @return … */ private static function missingTermSql` (line ~196). Insert the private helper `ageConditions` directly after `missingTermSql()`. Do not edit any existing method.
- Test: `tests/Feature/DocumentChecklistTest.php` (append tests + two private helpers)

**Interfaces:**
- Produces (Task 2 relies on these exact signatures):
  - `DocumentChecklist::requiredTypeSql(DocumentType $type, ?CarbonInterface $today = null): array{0: string, 1: array<int, mixed>}`: a 1/0 SQL expression over `players`; `['0', []]` for an inactive or optional type.
  - `DocumentChecklist::expiringTypeSql(DocumentType $type, ?CarbonInterface $today = null): array{0: string, 1: array<int, mixed>}`: same contract.
  - Existing, unchanged: `DocumentChecklist::missingTypeSql(DocumentType $type, ?CarbonInterface $today = null)`.

- [ ] **Step 1: Write the failing tests**

Append inside `class DocumentChecklistTest` (after `assertAgreement()`, before the closing brace). Add `use App\Models\PlayerDocument;` only if it is missing (it is already imported).

```php
    #[Test]
    public function the_per_type_required_and_expiring_sql_agree_with_the_checklist(): void
    {
        $players = $this->fixtures() + $this->typeFixtures();

        $this->assertTypeAgreement($players);

        // Rules change under existing records: a type is switched off, another
        // loses its age limit, an optional type becomes required.
        $this->type('medical_certificate')->update(['is_active' => false]);
        $this->type('parental_authorization')->update(['max_age' => null]);
        $this->type('school_certificate')->update(['is_required' => true]);

        $this->assertTypeAgreement($players);
    }

    #[Test]
    public function inactive_and_optional_types_are_never_required_nor_expiring(): void
    {
        $optional = $this->type('school_certificate');
        $this->assertSame(['0', []], DocumentChecklist::requiredTypeSql($optional));
        $this->assertSame(['0', []], DocumentChecklist::expiringTypeSql($optional));

        $this->type('medical_certificate')->update(['is_active' => false]);
        $inactive = $this->type('medical_certificate');
        $this->assertSame(['0', []], DocumentChecklist::requiredTypeSql($inactive));
        $this->assertSame(['0', []], DocumentChecklist::expiringTypeSql($inactive));
    }

    /** @return array<string, Player> situations only the per-type twins care about */
    private function typeFixtures(): array
    {
        $players = [
            'exempt photo and picture' => $this->player(['picture_url' => '/media/players/e.jpg']),
            'expiring photo and picture' => $this->player(['picture_url' => '/media/players/f.jpg']),
            'expiring paper photo' => $this->player(),
            'adult, expiring parental' => $this->player(),
            'expiring optional' => $this->player(),
            'minor, exempt parental' => $this->player(['birthdate' => '2012-03-01']),
        ];

        $this->exempt($players['exempt photo and picture'], 'photo');
        $this->received($players['expiring photo and picture'], 'photo', '2026-10-20');
        $this->received($players['expiring paper photo'], 'photo', '2026-10-20');
        $this->received($players['adult, expiring parental'], 'parental_authorization', '2026-10-20');
        $this->received($players['expiring optional'], 'school_certificate', '2026-10-20');
        $this->exempt($players['minor, exempt parental'], 'parental_authorization');

        return $players;
    }

    /**
     * Every type (active or not, required or not) × every player: the SQL
     * twins say 1 exactly where the checklist says required / expiring.
     *
     * @param  array<string, Player>  $players
     */
    private function assertTypeAgreement(array $players): void
    {
        $seen = ['required' => 0, 'not required' => 0, 'expiring' => 0];

        foreach (DocumentType::query()->orderBy('id')->get() as $type) {
            [$requiredSql, $requiredBindings] = DocumentChecklist::requiredTypeSql($type);
            [$expiringSql, $expiringBindings] = DocumentChecklist::expiringTypeSql($type);

            $fromSql = Player::query()
                ->select('players.id')
                ->selectRaw("{$requiredSql} as is_required", $requiredBindings)
                ->selectRaw("{$expiringSql} as is_expiring", $expiringBindings)
                ->get()
                ->keyBy('id');

            foreach ($players as $label => $player) {
                $player = $player->fresh();
                $document = PlayerDocument::query()
                    ->where('player_id', $player->id)
                    ->where('document_type_id', $type->id)
                    ->first();
                $result = DocumentChecklist::evaluate($type, $document, $player);

                $required = $type->is_active && $type->is_required && $type->appliesTo($player)
                    && $result['state'] !== DocumentChecklist::NOT_REQUIRED;
                $expiring = $required && $result['state'] === DocumentChecklist::EXPIRES_SOON;

                $this->assertSame($required ? 1 : 0, (int) $fromSql[$player->id]->is_required, "required {$type->code}: {$label}");
                $this->assertSame($expiring ? 1 : 0, (int) $fromSql[$player->id]->is_expiring, "expiring {$type->code}: {$label}");

                // DocumentStats computes received = required − missing, so a
                // missing document must always be a required one.
                if ($result['counts_missing']) {
                    $this->assertTrue($required, "missing implies required {$type->code}: {$label}");
                }

                $seen[$required ? 'required' : 'not required']++;
                $seen['expiring'] += $expiring ? 1 : 0;
            }
        }

        // The fixtures must exercise both sides, or agreement proves nothing.
        $this->assertGreaterThan(0, $seen['required']);
        $this->assertGreaterThan(0, $seen['not required']);
        $this->assertGreaterThan(0, $seen['expiring']);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php artisan test --filter=DocumentChecklistTest`
Expected: the two new tests ERROR with `Call to undefined method App\Services\Player\DocumentChecklist::requiredTypeSql()`. Every existing test passes.

- [ ] **Step 3: Implement the twins**

In `app/Services/Player/DocumentChecklist.php`, insert after the closing brace of `expiringSoonSql()`:

```php
    /**
     * One type's "required for this player" test as an SQL expression over
     * `players` (1/0) — the twin of evaluate(): active, required, applies to
     * the player's age, and not exempted. A profile picture settles the Photo
     * whatever its record says, so a picture beats an exempt record (the age
     * limit still applies). Inactive and optional types are never required.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function requiredTypeSql(DocumentType $type, ?CarbonInterface $today = null): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        [$conditions, $bindings] = self::ageConditions($type, self::today($today));

        $notExempt = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
            ." AND pd.state = 'exempt')";

        $conditions[] = $type->isPhoto()
            ? "((players.picture_url IS NOT NULL AND players.picture_url <> '') OR {$notExempt})"
            : $notExempt;
        $bindings[] = $type->id;

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN 1 ELSE 0 END)', $bindings];
    }

    /**
     * One type's "required and expires within 30 days" test as an SQL
     * expression over `players` (1/0) — the twin of evaluate() returning
     * EXPIRES_SOON for a required document. A received record valid today is
     * never missing, and one record per player and type means it is not
     * exempt either. A Photo settled by a picture never expires.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringTypeSql(DocumentType $type, ?CarbonInterface $today = null): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        $today = self::today($today);
        [$conditions, $bindings] = self::ageConditions($type, $today);

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
        }

        $conditions[] = 'EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE pd.player_id = players.id AND pd.document_type_id = ?'
            ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?)";
        array_push(
            $bindings,
            $type->id,
            $today->toDateString(),
            $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
        );

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN 1 ELSE 0 END)', $bindings];
    }
```

Insert after the closing brace of `missingTermSql()`:

```php
    /**
     * The age test of the per-type twins: the same boundary missingTermSql()
     * uses (DocumentType::earliestApplicableBirthdate), no birth date = not
     * limited. Kept apart so missingTermSql() itself stays untouched.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function ageConditions(DocumentType $type, CarbonImmutable $today): array
    {
        $earliest = $type->earliestApplicableBirthdate($today);

        if ($earliest === null) {
            return [[], []];
        }

        return [['(players.birthdate IS NULL OR players.birthdate >= ?)'], [$earliest->toDateString()]];
    }
```

Also extend the class docblock's first list. After the line ` *  - missingCountSql() / expiringSoonSql(): the same rules as SQL, so the` and its two continuation lines, add:

```php
 *  - requiredTypeSql() / expiringTypeSql() / missingTypeSql(): per-type 1/0
 *    terms, summed by DocumentStats into completion figures.
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php artisan test --filter=DocumentChecklistTest`
Expected: all tests PASS, including `the_sql_count_agrees_with_the_checklist_for_every_situation`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/DocumentChecklist.php tests/Feature/DocumentChecklistTest.php
git commit -m "feat(documents): per-type required and expiring SQL twins of the checklist

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `DocumentStats` service

**Files:**
- Create: `app/Services/Player/DocumentStats.php`
- Test: `tests/Feature/DocumentStatsTest.php`

**Interfaces:**
- Consumes: `DocumentChecklist::requiredTypeSql`, `::expiringTypeSql`, `::missingTypeSql` (Task 1).
- Produces (Tasks 4, 6, 7 rely on these):
  - `DocumentStats::summary(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array`. It runs 2 queries: the active required types, then one aggregate. It returns `null` when no active required type exists. Otherwise:
    ```
    [
      'required' => int, 'received' => int, 'expiring' => int, 'missing' => int,
      'pct' => ?float,            // received / required × 100, 1 decimal; null when required = 0
      'players_complete' => int, 'players_total' => int,
      'by_type' => list<['id' => int, 'name' => string, 'required' => int, 'received' => int, 'expiring' => int, 'missing' => int, 'pct' => ?float]>,
    ]
    ```
  - `DocumentStats::breakdown(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array`. It runs 2 queries: the types, then ONE `GROUP BY category` aggregate. It returns `null` when no active required type exists. Otherwise:
    ```
    [
      'summary' => <same shape as summary()>,   // the sum of the category rows
      'by_category' => list<['category_id' => ?int, 'name' => ?string, 'required' => int, 'received' => int, 'expiring' => int, 'missing' => int, 'pct' => ?float, 'players_total' => int, 'players_complete' => int]>,
    ]
    ```
    `name` is null for players without a category; the frontend labels it.
  - Every row list (`by_type`, `by_category`) is sorted by `pct` ascending, null last, then by name.
  - `$players` may be an Eloquent builder (dashboard, Teams) or a query builder (the Players list's `statsQuery()`). Its select list and ordering are replaced. Its wheres are kept.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DocumentStatsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeded types (migration 2026_09_24_100002): birth_certificate, photo,
 * medical_certificate (required); parental_authorization (required, max_age
 * 17); id_card_copy, residence_certificate, school_certificate (optional).
 * Adults below are born 1990, so three types apply to them, four to minors.
 */
class DocumentStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20267'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Player'.$this->sequence,
            'lastname' => 'Stats',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function received(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    private function exempt(Player $player, string $code): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Handled by the federation',
        ]);
    }

    private function stats(): DocumentStats
    {
        return app(DocumentStats::class);
    }

    private function summary(): ?array
    {
        return $this->stats()->summary(Player::query());
    }

    /** @return array<string, array<string, mixed>> code => by_type row */
    private function byType(array $summary): array
    {
        $codes = DocumentType::pluck('code', 'id');

        return collect($summary['by_type'])->mapWithKeys(fn (array $row) => [$codes[$row['id']] => $row])->all();
    }

    /** The five counters of a row, in the order DocumentStats writes them. */
    private function counts(array $row): array
    {
        return array_intersect_key($row, array_flip(['required', 'received', 'expiring', 'missing', 'pct']));
    }

    /**
     * Nine players, one situation each (required / received / missing):
     *  a nothing 3/0/3 · b complete 3/3/0 · c medical expiring 3/1/2 ·
     *  d medical expired 3/0/3 · e medical exempt 2/0/2 ·
     *  f picture + exempt photo 3/1/2 · g exempt photo, no picture 2/0/2 ·
     *  h minor 4/0/4 · i optional documents only 3/0/3.
     *
     * @return array<string, Player>
     */
    private function example(): array
    {
        $a = $this->player();

        $b = $this->player(['picture_url' => '/media/players/b.jpg']);
        $this->received($b, 'birth_certificate');
        $this->received($b, 'medical_certificate', '2027-08-31');

        $c = $this->player();
        $this->received($c, 'medical_certificate', '2026-10-20');

        $d = $this->player();
        $this->received($d, 'medical_certificate', '2026-10-04');

        $e = $this->player();
        $this->exempt($e, 'medical_certificate');

        $f = $this->player(['picture_url' => '/media/players/f.jpg']);
        $this->exempt($f, 'photo');

        $g = $this->player();
        $this->exempt($g, 'photo');

        $h = $this->player(['birthdate' => '2012-03-01']);

        $i = $this->player();
        $this->received($i, 'school_certificate', '2026-10-20');
        $this->received($i, 'id_card_copy', '2026-10-10');

        return compact('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i');
    }

    #[Test]
    public function the_summary_counts_documents_the_way_the_player_checklist_does(): void
    {
        $this->example();

        $summary = $this->summary();

        $this->assertSame(26, $summary['required']);
        $this->assertSame(5, $summary['received']);
        $this->assertSame(1, $summary['expiring']);
        $this->assertSame(21, $summary['missing']);
        $this->assertSame(19.2, $summary['pct']);
        $this->assertSame(1, $summary['players_complete']);
        $this->assertSame(9, $summary['players_total']);

        $types = $this->byType($summary);
        $this->assertSame(['required' => 9, 'received' => 1, 'expiring' => 0, 'missing' => 8, 'pct' => 11.1], $this->counts($types['birth_certificate']));
        // f's picture settles the photo despite the exempt record; g's exempt photo is not required.
        $this->assertSame(['required' => 8, 'received' => 2, 'expiring' => 0, 'missing' => 6, 'pct' => 25.0], $this->counts($types['photo']));
        // c's expiring certificate is received; d's expired one is missing; e is exempt.
        $this->assertSame(['required' => 8, 'received' => 2, 'expiring' => 1, 'missing' => 6, 'pct' => 25.0], $this->counts($types['medical_certificate']));
        // Only the minor needs the parental authorization.
        $this->assertSame(['required' => 1, 'received' => 0, 'expiring' => 0, 'missing' => 1, 'pct' => 0.0], $this->counts($types['parental_authorization']));
        // Optional types never count.
        $this->assertArrayNotHasKey('school_certificate', $types);
        $this->assertArrayNotHasKey('id_card_copy', $types);
        $this->assertSame('Birth certificate', $types['birth_certificate']['name']);
    }

    #[Test]
    public function rows_come_worst_first_with_nothing_required_last(): void
    {
        $this->player();
        $done = $this->player();
        $this->received($done, 'birth_certificate');

        $codes = DocumentType::pluck('code', 'id');
        $byType = $this->summary()['by_type'];

        // medical 0 % and photo 0 % tie → by name (Medical < Photo); birth 50 %;
        // nobody is young enough for the parental authorization → null, last.
        $this->assertSame(
            ['medical_certificate', 'photo', 'birth_certificate', 'parental_authorization'],
            array_map(fn (array $row) => $codes[$row['id']], $byType),
        );
        $this->assertNull($byType[3]['pct']);
        $this->assertSame(0, $byType[3]['required']);
    }

    #[Test]
    public function inactive_and_optional_types_are_never_counted(): void
    {
        $player = $this->player();
        $this->received($player, 'school_certificate');
        $this->type('medical_certificate')->update(['is_active' => false]);

        $summary = $this->summary();

        $this->assertSame(2, $summary['required']);
        $this->assertSame(2, $summary['missing']);
        $this->assertSame(
            ['birth_certificate', 'parental_authorization', 'photo'],
            collect(array_keys($this->byType($summary)))->sort()->values()->all(),
        );
    }

    #[Test]
    public function a_player_with_nothing_required_has_a_complete_file(): void
    {
        $player = $this->player();
        foreach (['birth_certificate', 'photo', 'medical_certificate'] as $code) {
            $this->exempt($player, $code);
        }

        $summary = $this->summary();

        $this->assertSame(0, $summary['required']);
        $this->assertNull($summary['pct']);
        $this->assertSame(1, $summary['players_complete']);
        $this->assertSame(1, $summary['players_total']);
    }

    #[Test]
    public function without_any_active_required_type_there_is_nothing_to_report(): void
    {
        $this->player();
        DocumentType::query()->update(['is_required' => false]);

        $this->assertNull($this->summary());
        $this->assertNull($this->stats()->breakdown(Player::query()));
    }

    #[Test]
    public function an_empty_population_reports_zeros_and_no_percent(): void
    {
        $summary = $this->summary();

        $this->assertSame(0, $summary['players_total']);
        $this->assertSame(0, $summary['players_complete']);
        $this->assertSame(0, $summary['required']);
        $this->assertNull($summary['pct']);
        $this->assertCount(4, $summary['by_type']);
        $this->assertSame([null, null, null, null], array_column($summary['by_type'], 'pct'));

        $this->assertSame([], $this->stats()->breakdown(Player::query())['by_category']);
    }

    #[Test]
    public function the_population_is_whatever_builder_it_is_given_in_two_queries(): void
    {
        ['a' => $a, 'b' => $b] = $this->example();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $eloquent = $this->stats()->summary(Player::query()->whereKey([$a->id, $b->id])->orderBy('lastname'));
        $this->assertSame(2, $queries, 'one read of the types, one aggregate');

        // The players list hands over a query builder (PlayerController::statsQuery).
        $base = $this->stats()->summary(Player::query()->whereKey([$a->id, $b->id])->getQuery()->from('players'));

        $this->assertSame(2, $eloquent['players_total']);
        $this->assertSame(6, $eloquent['required']);
        $this->assertSame(3, $eloquent['received']);
        $this->assertSame(1, $eloquent['players_complete']);
        $this->assertSame($eloquent, $base);
    }

    #[Test]
    public function the_breakdown_groups_by_category_in_one_query_and_adds_up_to_the_summary(): void
    {
        $seniors = Category::create(['name' => 'Seniors']);
        $u13 = Category::create(['name' => 'U13']);
        $complete = $this->player(['category_id' => $seniors->id, 'picture_url' => '/media/players/s.jpg']);
        $this->received($complete, 'birth_certificate');
        $this->received($complete, 'medical_certificate', '2027-08-31');
        $this->player(['category_id' => $seniors->id]);                            // 0 of 3
        $this->player(['category_id' => $u13->id, 'birthdate' => '2014-01-01']);  // 0 of 4
        $this->player();                                                           // no category, 0 of 3

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $result = $this->stats()->breakdown(Player::query());
        $this->assertSame(2, $queries, 'one read of the types, one grouped query');

        // Worst first: two 0 % rows (no category sorts before "U13" by name), then Seniors.
        $this->assertSame([null, 'U13', 'Seniors'], array_column($result['by_category'], 'name'));

        $rows = collect($result['by_category'])->keyBy(fn (array $row) => $row['name'] ?? 'none');
        $this->assertSame(['required' => 6, 'received' => 3, 'expiring' => 0, 'missing' => 3, 'pct' => 50.0], $this->counts($rows['Seniors']));
        $this->assertSame($seniors->id, $rows['Seniors']['category_id']);
        $this->assertSame(2, $rows['Seniors']['players_total']);
        $this->assertSame(1, $rows['Seniors']['players_complete']);
        $this->assertSame(4, $rows['U13']['required']);
        $this->assertSame(0.0, $rows['U13']['pct']);
        $this->assertNull($rows['none']['category_id']);
        $this->assertSame(3, $rows['none']['required']);

        // Categories partition the players, so their rows add up to the summary.
        $this->assertSame(13, $result['summary']['required']);
        $this->assertSame(4, $result['summary']['players_total']);
        $this->assertSame($result['summary'], $this->summary());
    }
}
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php artisan test --filter=DocumentStatsTest`
Expected: every test ERRORS with `Target class [App\Services\Player\DocumentStats] does not exist.`

- [ ] **Step 3: Implement the service**

Create `app/Services/Player/DocumentStats.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\DocumentType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of the paperwork is in, over any set of players: documents
 * required, received (expiring ones included), expiring within 30 days and
 * missing, plus how many players have a complete file.
 *
 * The caller passes an already-filtered players builder; its select list and
 * ordering are replaced and its filters kept. An inner derived table gives one
 * 1/0 column per active required type and measure (r_ = required,
 * e_ = expiring, m_ = missing — DocumentChecklist's SQL twins), and the outer
 * query sums them. No per-player PHP loop.
 */
final class DocumentStats
{
    /** @return array<string, mixed>|null null when no active required type exists */
    public function summary(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array
    {
        $types = $this->types();

        if ($types->isEmpty()) {
            return null;
        }

        $row = DB::query()
            ->fromSub($this->perPlayer($players, $types, $this->today($today), withCategory: false), 'per_player')
            ->selectRaw($this->aggregateSql($types))
            ->first();

        return $this->summaryFrom([$row], $types);
    }

    /**
     * The summary and one row per category, from ONE grouped query: the
     * categories partition the players, so the summary is their sum.
     *
     * @return array{summary: array<string, mixed>, by_category: list<array<string, mixed>>}|null
     */
    public function breakdown(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null): ?array
    {
        $types = $this->types();

        if ($types->isEmpty()) {
            return null;
        }

        // Current-locale name with the base name as fallback, like the
        // players list's category chart.
        $locale = in_array(app()->getLocale(), ['ar', 'fr', 'en'], true) ? app()->getLocale() : 'en';
        $localeColumn = 'categories.name_'.$locale;

        $rows = DB::query()
            ->fromSub($this->perPlayer($players, $types, $this->today($today), withCategory: true), 'per_player')
            ->leftJoin('categories', 'categories.id', '=', 'per_player.category_id')
            ->groupBy('per_player.category_id', 'categories.name', $localeColumn)
            ->selectRaw(
                "per_player.category_id, COALESCE(NULLIF({$localeColumn}, ''), categories.name) as category_name, "
                .$this->aggregateSql($types)
            )
            ->get();

        $byCategory = $rows->map(fn (object $row): array => [
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'name' => $row->category_name ?: null,
            ...$this->rowCounts($row, $types),
            'players_total' => (int) $row->players_total,
            'players_complete' => (int) $row->players_complete,
        ])->all();

        return [
            'summary' => $this->summaryFrom($rows->all(), $types),
            'by_category' => self::worstFirst($byCategory),
        ];
    }

    /** @return Collection<int, DocumentType> */
    private function types(): Collection
    {
        return DocumentType::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->ordered()
            ->get();
    }

    private function today(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();
    }

    /** One row per player: r_{id}, e_{id}, m_{id} for every type (and category_id when grouping). */
    private function perPlayer(EloquentBuilder|QueryBuilder $players, Collection $types, CarbonImmutable $today, bool $withCategory): QueryBuilder
    {
        $query = $players instanceof EloquentBuilder ? (clone $players)->toBase() : clone $players;
        $query->reorder()->select($withCategory ? ['players.category_id'] : []);

        foreach ($types as $type) {
            $terms = [
                'r' => DocumentChecklist::requiredTypeSql($type, $today),
                'e' => DocumentChecklist::expiringTypeSql($type, $today),
                'm' => DocumentChecklist::missingTypeSql($type, $today),
            ];

            foreach ($terms as $prefix => [$sql, $bindings]) {
                $query->selectRaw("{$sql} as {$prefix}_{$type->id}", $bindings);
            }
        }

        return $query;
    }

    /** Outer select: player counts and one SUM per inner column. Type ids are integers from the database. */
    private function aggregateSql(Collection $types): string
    {
        $missingTotal = $types->map(fn (DocumentType $type) => "m_{$type->id}")->implode(' + ');

        $columns = [
            'COUNT(*) as players_total',
            "SUM(CASE WHEN ({$missingTotal}) = 0 THEN 1 ELSE 0 END) as players_complete",
        ];

        foreach ($types as $type) {
            foreach (['r', 'e', 'm'] as $prefix) {
                $columns[] = "SUM({$prefix}_{$type->id}) as {$prefix}_{$type->id}";
            }
        }

        return implode(', ', $columns);
    }

    /**
     * @param  iterable<object>  $rows  aggregate rows (one, or one per category)
     * @return array<string, mixed>
     */
    private function summaryFrom(iterable $rows, Collection $types): array
    {
        $perType = $types->mapWithKeys(fn (DocumentType $type) => [$type->id => ['required' => 0, 'expiring' => 0, 'missing' => 0]])->all();
        $playersTotal = 0;
        $playersComplete = 0;

        foreach ($rows as $row) {
            $playersTotal += (int) $row->players_total;
            $playersComplete += (int) $row->players_complete;

            foreach ($types as $type) {
                $perType[$type->id]['required'] += (int) $row->{"r_{$type->id}"};
                $perType[$type->id]['expiring'] += (int) $row->{"e_{$type->id}"};
                $perType[$type->id]['missing'] += (int) $row->{"m_{$type->id}"};
            }
        }

        $byType = $types->map(fn (DocumentType $type): array => [
            'id' => $type->id,
            'name' => $type->localized_name,
            ...self::counts(...$perType[$type->id]),
        ])->all();

        return [
            ...self::counts(
                required: array_sum(array_column($perType, 'required')),
                expiring: array_sum(array_column($perType, 'expiring')),
                missing: array_sum(array_column($perType, 'missing')),
            ),
            'players_complete' => $playersComplete,
            'players_total' => $playersTotal,
            'by_type' => self::worstFirst($byType),
        ];
    }

    /** Totals of one aggregate row over every type. */
    private function rowCounts(object $row, Collection $types): array
    {
        $required = $expiring = $missing = 0;

        foreach ($types as $type) {
            $required += (int) $row->{"r_{$type->id}"};
            $expiring += (int) $row->{"e_{$type->id}"};
            $missing += (int) $row->{"m_{$type->id}"};
        }

        return self::counts(required: $required, expiring: $expiring, missing: $missing);
    }

    /** @return array{required: int, received: int, expiring: int, missing: int, pct: ?float} */
    private static function counts(int $required, int $expiring, int $missing): array
    {
        $received = $required - $missing;

        return [
            'required' => $required,
            'received' => $received,
            'expiring' => $expiring,
            'missing' => $missing,
            'pct' => $required > 0 ? round($received / $required * 100, 1) : null,
        ];
    }

    /**
     * Worst percentage first, rows with nothing required last, then by name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function worstFirst(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$a['pct'] === null, $a['pct'] ?? 0, (string) $a['name']]
            <=> [$b['pct'] === null, $b['pct'] ?? 0, (string) $b['name']]);

        return $rows;
    }
}
```

Note: `self::counts(...$perType[$type->id])` spreads a string-keyed array (`required`, `expiring`, `missing`) as named arguments (PHP 8.1+). The keys must match the parameter names exactly.

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php artisan test --filter="DocumentStatsTest|DocumentChecklistTest"`
Expected: all PASS.

If `the_population_is_whatever_builder_it_is_given_in_two_queries` counts more than 2, print the SQL (`DB::listen(fn ($q) => dump($q->sql))`) and remove the extra query. Do not raise the number.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/DocumentStats.php tests/Feature/DocumentStatsTest.php
git commit -m "feat(documents): DocumentStats — completion summary and per-category breakdown in SQL

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `lib/completion.js` and `CompletionBar`

**Files:**
- Create: `resources/js/lib/completion.js`
- Create: `tests/js/completion.test.mjs`
- Create: `resources/js/Components/Stats/CompletionBar.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (insert-only helper)

**Interfaces:**
- Consumes: the `summary()` shape from Task 2 (snake_case keys).
- Produces (Tasks 5, 6, 7 use these):
  - `MIN_SLICE = 2`
  - `segments({ received, expiring, missing, required }) → Array<{ key: 'received'|'expiring'|'missing', count: number, width: number }>`. It returns only non-zero parts, in that order. The widths are percentages that sum to 100, and every part is at least `MIN_SLICE` wide. It returns `[]` when `required <= 0`. The `received` part is received − expiring.
  - `completionPct({ received, required }) → number|null`, one decimal (the same rounding as the PHP `round(…, 1)`).
  - `completionColors() → { received: string, expiring: string, missing: string }` (current theme).
  - `completionBarProps(summary) → { received, expiring, missing, required, playersComplete, playersTotal }` (zeros for null).
  - `<CompletionBar :received :expiring :missing :required :players-complete :players-total :links="{ missing?: string|null }" size="lg|sm" />`

- [ ] **Step 1: Write the failing node tests**

Create `tests/js/completion.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MIN_SLICE, completionBarProps, completionColors, completionPct, segments } from '../../resources/js/lib/completion.js';

const sum = (parts) => parts.reduce((total, part) => total + part.width, 0);

test('everything received fills the bar with one part', () => {
    assert.deepEqual(segments({ received: 12, expiring: 0, missing: 0, required: 12 }), [
        { key: 'received', count: 12, width: 100 },
    ]);
});

test('parts are shares of the required documents, expiring carved out of received', () => {
    const parts = segments({ received: 80, expiring: 10, missing: 20, required: 100 });

    assert.deepEqual(parts.map((p) => [p.key, p.count, p.width]), [
        ['received', 70, 70],
        ['expiring', 10, 10],
        ['missing', 20, 20],
    ]);
});

test('a tiny non-zero part stays visible and the bar still sums to 100', () => {
    const parts = segments({ received: 999, expiring: 0, missing: 1, required: 1000 });
    const missing = parts.find((p) => p.key === 'missing');

    assert.equal(missing.width, MIN_SLICE);
    assert.ok(Math.abs(sum(parts) - 100) < 1e-9, `sum was ${sum(parts)}`);
    assert.equal(parts.find((p) => p.key === 'received').width, 98);
});

test('awkward thirds still sum to 100', () => {
    const parts = segments({ received: 2, expiring: 1, missing: 1, required: 3 });

    assert.equal(parts.length, 3);
    assert.ok(Math.abs(sum(parts) - 100) < 1e-9, `sum was ${sum(parts)}`);
});

test('nothing required draws nothing', () => {
    assert.deepEqual(segments({ received: 0, expiring: 0, missing: 0, required: 0 }), []);
    assert.deepEqual(segments({}), []);
});

test('all received documents expiring leaves no green part', () => {
    const parts = segments({ received: 5, expiring: 5, missing: 5, required: 10 });

    assert.deepEqual(parts.map((p) => p.key), ['expiring', 'missing']);
});

test('the percentage is rounded to one decimal like the server', () => {
    assert.equal(completionPct({ received: 5, required: 26 }), 19.2);
    assert.equal(completionPct({ received: 1, required: 9 }), 11.1);
    assert.equal(completionPct({ received: 0, required: 0 }), null);
});

test('bar props map the server summary, zeros for nothing', () => {
    assert.deepEqual(completionBarProps({
        required: 26, received: 5, expiring: 1, missing: 21, pct: 19.2, players_complete: 1, players_total: 9, by_type: [],
    }), { received: 5, expiring: 1, missing: 21, required: 26, playersComplete: 1, playersTotal: 9 });
    assert.deepEqual(completionBarProps(null), { received: 0, expiring: 0, missing: 0, required: 0, playersComplete: 0, playersTotal: 0 });
});

test('the three parts get three distinct theme colours', () => {
    const colors = completionColors();

    assert.deepEqual(Object.keys(colors), ['received', 'expiring', 'missing']);
    assert.equal(new Set(Object.values(colors)).size, 3);
    Object.values(colors).forEach((color) => assert.match(color, /^#[0-9a-f]{6}$/i));
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npm run test:js`
Expected: `completion.test.mjs` fails with `Cannot find module …/resources/js/lib/completion.js`. The other suites pass.

- [ ] **Step 3: Implement the helper**

Create `resources/js/lib/completion.js`:

```js
/**
 * Document completion: the pure parts of CompletionBar and the dashboard's
 * completion charts, kept free of Vue so node:test covers them.
 *
 * The bar has three parts, in this order: received and still valid,
 * received but expiring within 30 days (still counted as received), missing.
 * They add up to the required documents.
 */
import { seriesColor } from './chartTheme.js';

/** Narrowest a non-zero part is drawn (percent of the bar), so one missing document stays visible. */
export const MIN_SLICE = 2;

const round3 = (value) => Math.round(value * 1000) / 1000;

/**
 * @param {{ received?: number, expiring?: number, missing?: number, required?: number }} counts
 * @returns {{ key: 'received'|'expiring'|'missing', count: number, width: number }[]}
 */
export function segments({ received = 0, expiring = 0, missing = 0, required = 0 } = {}) {
    if (!(required > 0)) return [];

    const parts = [
        { key: 'received', count: Math.max(0, received - expiring) },
        { key: 'expiring', count: Math.max(0, expiring) },
        { key: 'missing', count: Math.max(0, missing) },
    ].filter((part) => part.count > 0);

    const total = parts.reduce((sum, part) => sum + part.count, 0);
    if (total === 0) return [];

    parts.forEach((part) => { part.width = (part.count / total) * 100; });

    // Widen tiny parts to MIN_SLICE and take the difference from the widest
    // part (at least a third of the bar, so it can always give).
    let deficit = 0;
    parts.forEach((part) => {
        if (part.width < MIN_SLICE) {
            deficit += MIN_SLICE - part.width;
            part.width = MIN_SLICE;
        }
    });
    const widest = parts.reduce((a, b) => (b.width > a.width ? b : a));
    widest.width -= deficit;

    // Round the others; the widest takes the remainder so the bar is exactly 100.
    const others = parts.filter((part) => part !== widest);
    others.forEach((part) => { part.width = round3(part.width); });
    widest.width = round3(100 - others.reduce((sum, part) => sum + part.width, 0));

    return parts;
}

/** received / required × 100 with one decimal (PHP round(…, 1)); null when nothing is required. */
export function completionPct({ received = 0, required = 0 } = {}) {
    if (!(required > 0)) return null;

    return Math.round((received / required) * 1000) / 10;
}

/**
 * Part colours for the current theme. chartTheme has no semantic tokens, so
 * these are its validated categorical slots: green, amber, red. Neighbouring
 * parts are always separated by a surface-coloured edge and labelled with
 * their numbers, so colour is never the only channel.
 */
export function completionColors() {
    return {
        received: seriesColor(2),
        expiring: seriesColor(3),
        missing: seriesColor(7),
    };
}

/** CompletionBar props from a DocumentStats summary (snake_case → camelCase). */
export function completionBarProps(summary) {
    return {
        received: summary?.received ?? 0,
        expiring: summary?.expiring ?? 0,
        missing: summary?.missing ?? 0,
        required: summary?.required ?? 0,
        playersComplete: summary?.players_complete ?? 0,
        playersTotal: summary?.players_total ?? 0,
    };
}
```

- [ ] **Step 4: Run the node tests and watch them pass**

Run: `npm run test:js`
Expected: all suites PASS.

- [ ] **Step 5: Insert the bar's i18n keys**

Write `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task3.json`:

```json
{
    "stats.doc_received_headline": { "ar": "الوثائق المستلمة", "fr": "Documents reçus", "en": "Documents received" },
    "stats.doc_players_complete": { "ar": "{complete} من {total} لاعب ملفهم كامل", "fr": "{complete} joueurs sur {total} ont un dossier complet", "en": "{complete} of {total} players have a complete file" },
    "stats.doc_valid": { "ar": "سارية", "fr": "Valides", "en": "Valid" },
    "stats.doc_expiring": { "ar": "تنتهي خلال 30 يومًا", "fr": "Expirent sous 30 jours", "en": "Expiring within 30 days" },
    "stats.doc_missing": { "ar": "ناقصة", "fr": "Manquants", "en": "Missing" },
    "stats.doc_value_text": { "ar": "{received} من {required} وثيقة مستلمة، {expiring} تنتهي قريبًا، {missing} ناقصة", "fr": "{received} documents reçus sur {required}, {expiring} expirent bientôt, {missing} manquants", "en": "{received} of {required} documents received, {expiring} expiring soon, {missing} missing" },
    "stats.doc_no_players": { "ar": "لا يوجد لاعبون هنا", "fr": "Aucun joueur ici", "en": "No players here" },
    "stats.doc_none_required": { "ar": "لا توجد وثائق مطلوبة لهؤلاء اللاعبين", "fr": "Aucun document n'est exigé pour ces joueurs", "en": "No documents are required for these players" },
    "stats.doc_see_missing": { "ar": "عرض اللاعبين الذين تنقصهم وثائق", "fr": "Voir les joueurs à qui il manque des documents", "en": "See the players missing documents" }
}
```

Run from `D:\irnb-stats`:
`node .superpowers/sdd/i18n-insert.mjs "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task3.json"`
Then: `git diff --stat resources/js/i18n`
Expected: `3 files changed, 27 insertions(+)`, no deletions.

- [ ] **Step 6: Create the component**

Create `resources/js/Components/Stats/CompletionBar.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useChartTheme } from '@/Composables/useChartTheme';
import { useFormatNumber } from '@/Composables/useFormatNumber';
import { completionColors, completionPct, segments } from '@/lib/completion';

/**
 * "How much of the paperwork is in": one bar split into valid, expiring
 * (still counted as received) and missing documents, plus how many players
 * have a complete file. The numbers come from App\Services\Player\DocumentStats.
 */
const props = defineProps({
    received: { type: Number, default: 0 },
    expiring: { type: Number, default: 0 },
    missing: { type: Number, default: 0 },
    required: { type: Number, default: 0 },
    playersComplete: { type: Number, default: 0 },
    playersTotal: { type: Number, default: 0 },
    // { missing?: href } — the missing count links to those players.
    links: { type: Object, default: () => ({}) },
    size: { type: String, default: 'lg', validator: (value) => ['lg', 'sm'].includes(value) },
});

const { t } = useI18n();
const { themeVersion } = useChartTheme();
const { formatNumber, formatPercent } = useFormatNumber();

const lg = computed(() => props.size === 'lg');
const pct = computed(() => completionPct(props));
const parts = computed(() => segments(props));

// The same colours as the dashboard's completion charts, re-read on a theme toggle.
const colors = computed(() => {
    themeVersion.value;

    return completionColors();
});

const legend = computed(() => [
    { key: 'received', label: t('stats.doc_valid'), count: Math.max(0, props.received - props.expiring), href: null },
    { key: 'expiring', label: t('stats.doc_expiring'), count: props.expiring, href: null },
    { key: 'missing', label: t('stats.doc_missing'), count: props.missing, href: props.missing > 0 ? (props.links?.missing ?? null) : null },
]);

const valueText = computed(() => t('stats.doc_value_text', {
    received: formatNumber(props.received),
    required: formatNumber(props.required),
    expiring: formatNumber(props.expiring),
    missing: formatNumber(props.missing),
}));
</script>

<template>
    <section class="rounded-xl border border-border/70 bg-card text-card-foreground" :class="lg ? 'p-5' : 'px-4 py-3'">
        <p v-if="!playersTotal" class="text-sm text-muted-foreground">{{ t('stats.doc_no_players') }}</p>
        <p v-else-if="!required" class="text-sm text-muted-foreground">{{ t('stats.doc_none_required') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="font-semibold" :class="lg ? 'text-base' : 'text-sm'">
                    {{ t('stats.doc_received_headline') }} —
                    <bdi class="tabular-nums">{{ formatPercent(pct) }}</bdi>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('stats.doc_players_complete', { complete: formatNumber(playersComplete), total: formatNumber(playersTotal) }) }}
                </p>
            </div>

            <div
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="pct"
                :aria-valuetext="valueText"
                :aria-label="t('stats.doc_received_headline')"
                class="flex w-full overflow-hidden rounded-full bg-muted"
                :class="lg ? 'mt-3 h-3.5' : 'mt-2 h-2'"
            >
                <!-- A card-coloured end edge separates neighbouring parts, so two colours never touch. -->
                <div
                    v-for="part in parts"
                    :key="part.key"
                    class="h-full border-e-2 border-card last:border-e-0"
                    :style="{ width: `${part.width}%`, backgroundColor: colors[part.key] }"
                />
            </div>

            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" :class="lg ? 'mt-3' : 'mt-2'">
                <li v-for="item in legend" :key="item.key" class="inline-flex items-center gap-1.5">
                    <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: colors[item.key] }" aria-hidden="true" />
                    <Link
                        v-if="item.href"
                        :href="item.href"
                        :title="t('stats.doc_see_missing')"
                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ item.label }} <bdi class="tabular-nums">{{ formatNumber(item.count) }}</bdi>
                    </Link>
                    <span v-else>{{ item.label }} <bdi class="tabular-nums text-foreground">{{ formatNumber(item.count) }}</bdi></span>
                </li>
            </ul>
        </template>
    </section>
</template>
```

The parts sit in a flex row, so under `dir="rtl"` the valid part starts on the right with no extra code. `border-e-2` is logical.

- [ ] **Step 7: Verify**

Run: `npm run test:js`, `npm run build`, `npm run i18n:check`
Expected: all green. The component is not imported yet, so the build only proves the helper compiles. Task 5 renders it.

- [ ] **Step 8: Commit**

```bash
git add resources/js/lib/completion.js tests/js/completion.test.mjs resources/js/Components/Stats/CompletionBar.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(stats): CompletionBar and its segment helper

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Dashboard backend — `members.documents`

**Files:**
- Modify: `app/Services/Dashboard/MemberStats.php` (constructor + public `documents()`)
- Modify: `app/Http/Controllers/DashboardController.php:94-102` (members resolver)
- Modify: `tests/Feature/Dashboard/DashboardPageTest.php:226-268` (members budget, measured)
- Create: `tests/Feature/Dashboard/DashboardDocumentsTest.php`

**Interfaces:**
- Consumes: `DocumentStats::breakdown()` (Task 2).
- Produces: the members tab payload gains `documents`, which is `null` without `documents → view`, else `DocumentStats::breakdown()` over the current members (`players.archived = false`, `notLeft()`, dashboard branch). The dashboard date range does not apply. Task 5 reads `data.documents.summary` and `data.documents.by_category`.
- `MemberStats::documents(DashboardFilters $filters): ?array` (public; the controller checks the permission).

- [ ] **Step 1: Measure the members budget before the change**

In `tests/Feature/Dashboard/DashboardPageTest.php`, temporarily change `foreach (['members' => 33, 'operations' => 12]` to `foreach (['members' => 1, 'operations' => 12]` and run:

`npm run build` then `php artisan test --filter=every_tab_stays_within_its_query_budget`

Expected: FAIL with `The members tab ran N queries`. Write down N. Revert the line to `33`.

- [ ] **Step 2: Write the failing feature tests**

Create `tests/Feature/Dashboard/DashboardDocumentsTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20266'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Member'.$this->sequence,
            'lastname' => 'Docs',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** The members tab payload, as the tab's partial reload receives it. */
    private function members(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('dashboard', $query), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'members',
            ])
            ->assertOk()
            ->json('props.members');
    }

    #[Test]
    public function the_members_tab_reports_the_documents_of_current_members_as_of_today(): void
    {
        $longAgo = $this->player();
        Player::query()->whereKey($longAgo->id)->update(['created_at' => '2020-01-01 00:00:00']);
        $this->player(['archived' => true]);
        $this->player(['status_id' => Player::leftStatusId(), 'left_at' => '2026-01-10']);

        // "This month" does not narrow it: completion is a snapshot of today.
        $documents = $this->members(User::factory()->admin()->create(), ['range' => 'month'])['documents'];

        $this->assertSame(1, $documents['summary']['players_total']);
        $this->assertSame(3, $documents['summary']['required']);
        $this->assertSame(0, $documents['summary']['received']);
        $this->assertSame(3, $documents['summary']['missing']);
        $this->assertEquals(0, $documents['summary']['pct']);
        $this->assertCount(4, $documents['summary']['by_type']);
        $this->assertCount(1, $documents['by_category']);
        $this->assertNull($documents['by_category'][0]['category_id']);
    }

    #[Test]
    public function the_branch_filter_narrows_the_members_counted(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $handball = Branch::create(['name' => 'Handball']);
        $this->player()->branches()->attach($football->id);
        $this->player()->branches()->attach($football->id);
        $this->player()->branches()->attach($handball->id);
        $admin = User::factory()->admin()->create();

        $this->assertSame(3, $this->members($admin)['documents']['summary']['players_total']);
        $this->assertSame(2, $this->members($admin, ['branch' => $football->id])['documents']['summary']['players_total']);
        $this->assertSame(1, $this->members($admin, ['branch' => $handball->id])['documents']['summary']['players_total']);
    }

    #[Test]
    public function without_documents_view_the_members_tab_carries_no_documents(): void
    {
        $this->player();

        $members = $this->members($this->userWith(['players' => ['view']]));
        $this->assertArrayHasKey('documents', $members);
        $this->assertNull($members['documents']);

        $allowed = $this->members($this->userWith(['players' => ['view'], 'documents' => ['view']]));
        $this->assertSame(1, $allowed['documents']['summary']['players_total']);
    }
}
```

- [ ] **Step 3: Run them and watch them fail**

Run: `php artisan test --filter=DashboardDocumentsTest`
Expected: FAIL. The first two error with `Undefined array key "documents"` (or a null offset). The third fails `assertArrayHasKey('documents', …)`.

- [ ] **Step 4: Implement**

`app/Services/Dashboard/MemberStats.php`:
- Add `use App\Services\Player\DocumentStats;` to the imports.
- Directly after `private const TOP_CITIES = 6;`, add:

```php
    public function __construct(private readonly DocumentStats $documentStats) {}
```

- Directly after the `get()` method, add:

```php
    /**
     * Document completion of the current members — not archived, not left —
     * in the dashboard's branch. A snapshot of today: the date range does not
     * apply. The caller checks documents/view (DashboardController).
     *
     * @return array{summary: array<string, mixed>, by_category: list<array<string, mixed>>}|null
     */
    public function documents(DashboardFilters $filters): ?array
    {
        return $this->documentStats->breakdown($this->active($filters)->notLeft());
    }
```

`app/Http/Controllers/DashboardController.php`: in the `'members'` resolver, directly after the `'attendance' => …` line, add:

```php
                    // Document completion rides with the members tab too; it
                    // needs documents/view, the player checklist's right.
                    'documents' => $user->hasPermission('documents', 'view') ? $this->members->documents($filters) : null,
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php artisan test --filter=DashboardDocumentsTest`
Expected: PASS.

- [ ] **Step 6: Measure again and raise the budget deliberately**

Temporarily set `'members' => 1` again and run `php artisan test --filter=every_tab_stays_within_its_query_budget`. Expected: `The members tab ran N+2 queries` (N from Step 1). If it is not N+2, find out why before going on.

Set the budget to `33 + (measured − N)`, which is expected to be **35**. Append to the comment block above the `foreach`:

```php
        // members: +2 for document completion (DocumentStats::breakdown): the
        // active required document types, and one query over the current
        // members grouped by category (the summary is the sum of its rows).
```

Run: `php artisan test --filter=Dashboard`
Expected: all PASS (`ManualSuspensionsTest` is not in this filter).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Dashboard/MemberStats.php app/Http/Controllers/DashboardController.php tests/Feature/Dashboard/DashboardDocumentsTest.php tests/Feature/Dashboard/DashboardPageTest.php
git commit -m "feat(dashboard): document completion in the members payload

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard Members tab UI

**Files:**
- Modify: `resources/js/Pages/Dashboard/Partials/MembersTab.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (insert-only helper)

**Interfaces:**
- Consumes: `data.documents` (Task 4), `CompletionBar`, `completionBarProps`, `completionColors` (Task 3).
- Existing prop `branchId` (already passed by `Dashboard.vue` as `filters.branch`).

- [ ] **Step 1: Insert the dashboard i18n keys**

Write `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task5.json`:

```json
{
    "stats.doc_title": { "ar": "اكتمال الوثائق", "fr": "Complétude des dossiers", "en": "Document completion" },
    "stats.doc_as_of_today": { "ar": "الأعضاء الحاليون، حتى اليوم (لا تتأثر بالفترة)", "fr": "Membres actuels, à ce jour (hors période)", "en": "Current members, as of today (the period does not apply)" },
    "stats.doc_by_category": { "ar": "الوثائق حسب الفئة", "fr": "Documents par catégorie", "en": "Documents by category" },
    "stats.doc_by_type": { "ar": "الوثائق حسب النوع", "fr": "Documents par type", "en": "Documents by type" },
    "stats.doc_document": { "ar": "الوثيقة", "fr": "Document", "en": "Document" },
    "stats.doc_required": { "ar": "مطلوبة", "fr": "Exigés", "en": "Required" },
    "stats.doc_received": { "ar": "مستلمة", "fr": "Reçus", "en": "Received" },
    "stats.doc_pct": { "ar": "نسبة الاستلام", "fr": "% reçus", "en": "% received" },
    "stats.doc_players": { "ar": "اللاعبون", "fr": "Joueurs", "en": "Players" },
    "stats.doc_complete_files": { "ar": "ملفات كاملة", "fr": "Dossiers complets", "en": "Complete files" }
}
```

Run: `node .superpowers/sdd/i18n-insert.mjs "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task5.json"`
Then: `git diff --stat resources/js/i18n` → 30 insertions, no deletions.

- [ ] **Step 2: Script additions**

In `MembersTab.vue` `<script setup>`:
- Change `import { Link } from '@inertiajs/vue3';` to `import { Link, router } from '@inertiajs/vue3';`.
- Change the chartTheme import to `import { barDataset, baseOptions, ordinal, rankedBarDataset, seriesColor, spanLabel } from '@/lib/chartTheme';`.
- Add these imports:

```js
import CompletionBar from '@/Components/Stats/CompletionBar.vue';
import { useFormatNumber } from '@/Composables/useFormatNumber';
import { completionBarProps, completionColors } from '@/lib/completion';
```

- After `const { themeVersion } = useChartTheme();` add `const { formatNumber, formatPercent } = useFormatNumber();`.
- Append, directly before `</script>`:

```js
// --- Document completion (documents/view; null hides the whole section) ---
const documents = computed(() => props.data?.documents ?? null);

// Drill-downs land on the players list with the dashboard's branch, like
// academicLink. The list's default population (not archived, not left) is the
// same as this section's, so the numbers match what the reader clicks.
function playersLink(params) {
    const query = { ...params };
    if (props.branchId) query.branch_id = props.branchId;
    return route('players.index', query);
}

const docMissingHref = computed(() => (documents.value?.summary.missing ? playersLink({ documents: 'missing' }) : null));

const docCountColumns = () => [
    { key: 'required', label: t('stats.doc_required'), format: 'number', sortable: true, total: 'sum' },
    { key: 'received', label: t('stats.doc_received'), format: 'number', sortable: true, total: 'sum' },
    { key: 'expiring', label: t('stats.doc_expiring'), format: 'number', sortable: true, total: 'sum' },
    { key: 'missing', label: t('stats.doc_missing'), format: 'number', sortable: true, total: 'sum' },
    // A percent never sums.
    { key: 'pct', label: t('stats.doc_pct'), format: 'percent', sortable: true },
];

const docTypeColumns = computed(() => [
    { key: 'label', label: t('stats.doc_document'), format: 'text', sortable: true },
    ...docCountColumns(),
]);

const docCategoryColumns = computed(() => [
    { key: 'label', label: t('dashboard.category'), format: 'text', sortable: true },
    ...docCountColumns(),
    // Each player has one category (or none, its own row): categories
    // partition the players, so these two counts add up.
    { key: 'players_complete', label: t('stats.doc_complete_files'), format: 'number', sortable: true, total: 'sum' },
    { key: 'players_total', label: t('stats.doc_players'), format: 'number', sortable: true, total: 'sum' },
]);

const docTypeRows = computed(() => (documents.value?.summary.by_type ?? []).map((row) => ({
    ...row,
    label: row.name,
    href: row.missing > 0 ? playersLink({ documents: `missing-${row.id}` }) : null,
})));

const docCategoryRows = computed(() => (documents.value?.by_category ?? []).map((row) => ({
    ...row,
    label: row.name ?? t('dashboard.fin_uncategorised'),
    // The list has no "no category" filter, so that row has no link.
    href: row.missing > 0 && row.category_id ? playersLink({ category_id: row.category_id, documents: 'missing' }) : null,
})));

const docTypeTotals = computed(() => columnTotals(docTypeColumns.value, docTypeRows.value));
const docCategoryTotals = computed(() => columnTotals(docCategoryColumns.value, docCategoryRows.value));
const docEmpty = computed(() => !documents.value?.summary.players_total);

// Each row as shares of its own required documents (100 % stacked), so a
// small category and a large one compare on one scale; counts in the tooltip.
const DOC_PARTS = [
    { key: 'received', label: 'stats.doc_valid', count: (row) => row.received - row.expiring },
    { key: 'expiring', label: 'stats.doc_expiring', count: (row) => row.expiring },
    { key: 'missing', label: 'stats.doc_missing', count: (row) => row.missing },
];

function completionChart(rows) {
    themeVersion.value; // recolour on theme toggle
    const colors = completionColors();

    return {
        labels: rows.map((row) => row.label),
        datasets: DOC_PARTS.map((part) => ({
            ...barDataset(
                t(part.label),
                rows.map((row) => (row.required > 0 ? (part.count(row) / row.required) * 100 : 0)),
                0,
                { stack: 'documents', color: colors[part.key] },
            ),
            // barDataset's surface-coloured border, switched on at the end edge:
            // the gap between neighbouring parts, so two colours never touch.
            borderWidth: { top: 0, right: 2, bottom: 0, left: 0 },
        })),
    };
}

function completionOptions(rows) {
    themeVersion.value;
    const base = baseOptions({ rtl: props.rtl, stacked: true, horizontal: true });

    return {
        ...base,
        scales: {
            ...base.scales,
            x: { ...base.scales.x, max: 100, ticks: { ...base.scales.x.ticks, callback: (value) => formatPercent(value, { trim: true }) } },
        },
        plugins: {
            ...base.plugins,
            tooltip: {
                ...base.plugins.tooltip,
                callbacks: {
                    label: (ctx) => ` ${ctx.dataset.label}: ${formatNumber(DOC_PARTS[ctx.datasetIndex].count(rows[ctx.dataIndex]))} (${formatPercent(ctx.parsed.x)})`,
                },
            },
        },
        // A bar opens the players behind it, like its table row.
        onClick: (_event, elements) => {
            const href = rows[elements[0]?.index]?.href;
            if (href) router.visit(href);
        },
        onHover: (event, elements) => {
            const target = event.native?.target;
            if (target) target.style.cursor = elements.length && rows[elements[0].index]?.href ? 'pointer' : 'default';
        },
    };
}

const docTypeChart = computed(() => completionChart(docTypeRows.value));
const docTypeOptions = computed(() => completionOptions(docTypeRows.value));
const docCategoryChart = computed(() => completionChart(docCategoryRows.value));
const docCategoryOptions = computed(() => completionOptions(docCategoryRows.value));
```

- [ ] **Step 3: Template, right below the tiles**

Owner decision (2026-11-01): the bar goes directly BELOW the member tiles. Insert immediately after the tiles `</section>` (the first `<section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" …>` block, closing at ~line 251) and before `<AttendanceCard v-if="data?.attendance" …/>`:

```vue
        <section v-if="documents" class="space-y-4" :aria-label="t('stats.doc_title')">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('stats.doc_title') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('stats.doc_as_of_today') }}</p>
            </div>

            <CompletionBar v-bind="completionBarProps(documents.summary)" :links="{ missing: docMissingHref }" size="lg" />

            <div class="grid gap-5 lg:grid-cols-2">
                <ChartCard
                    :title="t('stats.doc_by_category')"
                    :loading="loading"
                    :empty="docEmpty"
                    :empty-hint="t('dashboard.mem_no_members')"
                    has-table
                    :csv="{ filename: 'dashboard-documents-by-category', columns: docCategoryColumns, rows: docCategoryRows, totals: docCategoryTotals }"
                    height="h-72"
                >
                    <Bar :data="docCategoryChart" :options="docCategoryOptions" />

                    <template #table>
                        <StatTable :columns="docCategoryColumns" :rows="docCategoryRows" :row-key="(r) => r.category_id ?? 'none'" :totals="docCategoryTotals" dense>
                            <template #cell-label="{ row, formatted }">
                                <Link v-if="row.href" :href="row.href" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ formatted }}</Link>
                                <template v-else>{{ formatted }}</template>
                            </template>
                        </StatTable>
                    </template>
                </ChartCard>

                <ChartCard
                    :title="t('stats.doc_by_type')"
                    :loading="loading"
                    :empty="docEmpty"
                    :empty-hint="t('dashboard.mem_no_members')"
                    has-table
                    :csv="{ filename: 'dashboard-documents-by-type', columns: docTypeColumns, rows: docTypeRows, totals: docTypeTotals }"
                    height="h-72"
                >
                    <Bar :data="docTypeChart" :options="docTypeOptions" />

                    <template #table>
                        <StatTable :columns="docTypeColumns" :rows="docTypeRows" row-key="id" :totals="docTypeTotals" dense>
                            <template #cell-label="{ row, formatted }">
                                <Link v-if="row.href" :href="row.href" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ formatted }}</Link>
                                <template v-else>{{ formatted }}</template>
                            </template>
                        </StatTable>
                    </template>
                </ChartCard>
            </div>
        </section>
```

- [ ] **Step 4: Verify**

Run: `npm run build`, `npm run test:js`, `npm run i18n:check`, `php artisan test --filter=Dashboard`
Expected: all green.

Manual check (report it): run `php artisan serve --port=2027` and open `/dashboard?tab=members` as an admin. Check that:
- the bar and both cards show; the bar matches the cards' totals rows;
- clicking "Missing N" lands on `/players?documents=missing` (plus `branch_id` when the branch filter is set);
- a type row link lands on `documents=missing-<id>`;
- the table toggle and CSV work;
- dark mode recolours the bar and the charts;
- at 375 px the page has no horizontal scroll (`document.documentElement.scrollWidth === 375`).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Dashboard/Partials/MembersTab.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(dashboard): document completion bar and breakdown on the members tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Players list strip

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php` (import; new prop next to `'familyStats' => $familyStats,`, line 182)
- Modify: `resources/js/Pages/Players/Index.vue` (props at lines 28-44, `only` at line 84, results block at line 416)
- Create: `tests/Feature/PlayerDocumentStatsTest.php`

**Interfaces:**
- Consumes: `DocumentStats::summary()` (Task 2); `CompletionBar`, `completionBarProps` (Task 3); the existing private `PlayerController::statsQuery(Request $request, ?string $except = null): Illuminate\Database\Query\Builder`.
- Produces: the Inertia prop `documentStats`, which is `null` without `documents → view`, else the `summary()` shape over every player matching the list's filters (all pages). It is a closure, so a filter reload computes it only when it asks for it.

- [ ] **Step 1: Baseline timing at 1,890 players (before any change)**

```bash
cp database/database.sqlite "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-scaled.sqlite"
php scripts/perf/scale.php "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-scaled.sqlite" 10 5
php -r '$p = new PDO("sqlite:C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-scaled.sqlite"); echo $p->query("SELECT COUNT(*) FROM players")->fetchColumn(), " players; admin id ", $p->query("SELECT id FROM users WHERE privileges LIKE \"%admin%\" ORDER BY id LIMIT 1")->fetchColumn(), PHP_EOL;'
php artisan config:clear
PHP_INI_SCAN_DIR=php-ini php -d memory_limit=2G scripts/perf/bench.php "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-scaled.sqlite" <admin id> 8 "players,players?category_id[]=1"
```

Expected: about 1,890 players. Write down the p50 and the query count for both routes.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/PlayerDocumentStatsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20265'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Listed'.$this->sequence,
            'lastname' => 'Strip',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function receive(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => DocumentType::where('code', $code)->value('id'),
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function props(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    #[Test]
    public function the_strip_counts_every_player_the_list_shows_and_follows_its_filters(): void
    {
        $seniors = Category::create(['name' => 'Seniors']);
        $juniors = Category::create(['name' => 'Juniors']);
        $done = $this->player(['category_id' => $seniors->id, 'picture_url' => '/media/players/done.jpg']);
        $this->receive($done, 'birth_certificate');
        $this->receive($done, 'medical_certificate', '2027-08-31');
        $this->player(['category_id' => $juniors->id]);
        $this->player(['category_id' => $juniors->id, 'archived' => true]);   // not in the list
        $this->player(['status_id' => Player::leftStatusId()]);              // hidden by default
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $all = $this->props($admin)['documentStats'];
        $this->assertSame(2, $all['players_total']);
        $this->assertSame(1, $all['players_complete']);
        $this->assertSame(6, $all['required']);
        $this->assertSame(3, $all['received']);
        $this->assertSame(3, $all['missing']);

        $seniorsOnly = $this->props($admin, ['category_id' => [$seniors->id]])['documentStats'];
        $this->assertSame(1, $seniorsOnly['players_total']);
        $this->assertEquals(100, $seniorsOnly['pct']);

        $missing = $this->props($admin, ['documents' => ['missing']])['documentStats'];
        $this->assertSame(1, $missing['players_total']);
        $this->assertSame(0, $missing['players_complete']);

        // Every page, not the 25 rows on screen.
        foreach (range(1, 30) as $i) {
            $this->player();
        }
        $this->assertSame(32, $this->props($admin)['documentStats']['players_total']);
    }

    #[Test]
    public function without_documents_view_the_strip_is_not_sent(): void
    {
        $this->player();

        $this->assertNull($this->props($this->userWith(['players' => ['view']]))['documentStats']);

        $reader = $this->userWith(['players' => ['view'], 'documents' => ['view']]);
        $this->assertSame(1, $this->props($reader)['documentStats']['players_total']);
    }
}
```

- [ ] **Step 3: Run them and watch them fail**

Run: `php artisan test --filter=PlayerDocumentStatsTest`
Expected: both FAIL with `Undefined array key "documentStats"`.

- [ ] **Step 4: Backend**

In `app/Http/Controllers/PlayerController.php` add `use App\Services\Player\DocumentStats;` (alphabetically after `use App\Services\Player\DocumentChecklist;`). Directly after `'familyStats' => $familyStats,` add:

```php
            // Document completion of every player the list matches (all
            // pages, the rows' own filters). Needs documents/view, like the
            // player checklist; a closure so other partial reloads skip it.
            'documentStats' => fn (): ?array => $request->user()?->hasPermission('documents', 'view')
                ? app(DocumentStats::class)->summary($this->statsQuery($request))
                : null,
```

Run: `php artisan test --filter="PlayerDocumentStatsTest|PlayerDocumentFiltersTest|PlayerMultiSelectFiltersTest|ListFilterPartialReloadTest"`
Expected: all PASS.

- [ ] **Step 5: Frontend**

In `resources/js/Pages/Players/Index.vue`:
- Imports:

```js
import CompletionBar from '@/Components/Stats/CompletionBar.vue';
import { completionBarProps } from '@/lib/completion';
```

- In `defineProps`, after `documentTypes: …,` add `documentStats: { type: Object, default: null },`.
- In the `useListFilters` call, append `'documentStats'` to `only`, so a filter change recomputes the strip:

```js
}), { only: ['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats', 'familyStats', 'documentStats'] });
```

- After `const printListHref = …;` add:

```js
// The strip's "missing" link: the current list, narrowed to players missing a
// document. It replaces the documents filter (its values OR together, so
// adding "missing" to "expiring" would widen the list instead).
const documentMissingHref = computed(() => (props.documentStats?.missing
    ? route('players.index', { ...filterParams.value, documents: ['missing'] })
    : null));
```

- In the template, insert as the FIRST child of `<div class="space-y-4" :class="{ 'min-h-screen': hasActiveFilters }">` (line 416):

```vue
                <CompletionBar
                    v-if="documentStats"
                    v-bind="completionBarProps(documentStats)"
                    :links="{ missing: documentMissingHref }"
                    size="sm"
                />
```

- [ ] **Step 6: Verify and time it again**

Run: `npm run build`, `npm run i18n:check`, `php artisan test --filter="Player"`
Expected: green.

Re-run the Step 1 `bench.php` command on the same copy. Expected: p50 of `players` and of `players?category_id[]=1` each within **+30 ms** of the baseline, and +2 queries (the types read and the aggregate). If p50 grows by more than 30 ms, report the numbers and the EXPLAIN of the aggregate. Do not merge-tune blindly.

Manual check (report it), `php artisan serve --port=2027`:
- `/players` shows the strip above the rows;
- picking a category refreshes the strip without a full reload (devtools: the XHR asks for `documentStats`);
- "Missing N" keeps the other filters and sets `documents[]=missing`;
- at 375 px there is no page-level horizontal scroll;
- a user with players/view only sees no strip.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Index.vue tests/Feature/PlayerDocumentStatsTest.php
git commit -m "feat(players): document completion strip over the filtered list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Teams/Show strip and fold-out

**Files:**
- Modify: `app/Http/Controllers/TeamController.php` (import; new prop after `'canEditTeam' => …,` at line 133)
- Modify: `resources/js/Pages/Teams/Show.vue` (imports, props at lines 22-25, roster section at line 113)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (insert-only helper)
- Create: `tests/Feature/Competitions/TeamDocumentStatsTest.php`

**Interfaces:**
- Consumes: `DocumentStats::summary()` (Task 2); `CompletionBar`, `completionBarProps` (Task 3).
- Produces: the Inertia prop `documentStats`, which is `null` without `documents → view`, else `summary()` over the season roster's player ids (`$roster`, already built in `show()`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Competitions/TeamDocumentStatsTest.php`:

```php
<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class TeamDocumentStatsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    #[Test]
    public function the_team_page_counts_the_documents_of_its_season_roster(): void
    {
        $team = $this->team();
        $season = Season::current()->startYear;
        [$a, $b, $notOnRoster, $lastSeason] = [
            $this->player($team->category), $this->player($team->category),
            $this->player($team->category), $this->player($team->category),
        ];
        $this->onRoster($team, $season, [$a, $b]);
        $this->onRoster($team, $season - 1, [$lastSeason]);
        PlayerDocument::create([
            'player_id' => $a->id,
            'document_type_id' => DocumentType::where('code', 'birth_certificate')->value('id'),
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->toDateString(),
        ]);

        // Fixture players have no birth date, so all four required types apply.
        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentStats.players_total', 2)
                ->where('documentStats.required', 8)
                ->where('documentStats.received', 1)
                ->where('documentStats.missing', 7)
                ->where('documentStats.players_complete', 0)
                ->has('documentStats.by_type', 4));
    }

    #[Test]
    public function without_documents_view_the_team_page_sends_no_document_stats(): void
    {
        $team = $this->team();
        $viewer = User::factory()->create(['role_id' => Role::factory()->create([
            'permissions' => ['teams' => ['view'], 'competitions' => ['view']],
        ])->id]);

        $this->actingAs($viewer)->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documentStats', null));
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --filter=TeamDocumentStatsTest`
Expected: both FAIL. The prop `documentStats` does not exist (`Property [documentStats] does not exist` / `Inertia property [documentStats] does not exist`).

- [ ] **Step 3: Backend**

In `app/Http/Controllers/TeamController.php` add `use App\Services\Player\DocumentStats;` (after `use App\Services\Competitions\TeamAccess;`). Directly after the `'canEditTeam' => …,` line add:

```php
            // Document completion of this season's roster; needs documents/view.
            'documentStats' => fn (): ?array => $request->user()->hasPermission('documents', 'view')
                ? app(DocumentStats::class)->summary(Player::query()->whereIn('players.id', $roster->pluck('player_id')->all()))
                : null,
```

Run: `php artisan test --filter="TeamDocumentStatsTest|TeamsTest|MatchStatsTest"`
Expected: all PASS.

- [ ] **Step 4: Insert the fold-out i18n keys**

Write `C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task7.json`:

```json
{
    "stats.doc_missing_by_type": { "ar": "الوثائق الناقصة حسب النوع", "fr": "Documents manquants par type", "en": "Missing documents by type" },
    "stats.doc_missing_for": { "ar": "ناقصة لدى {n} لاعب", "fr": "manquant pour {n} joueurs", "en": "missing for {n} players" }
}
```

Run: `node .superpowers/sdd/i18n-insert.mjs "C:/Users/MUSTAPHA/AppData/Local/Temp/claude/d--irnb-laravel/43c0584d-c7d0-4b0b-940c-0a0eedcaa312/scratchpad/p3-i18n-task7.json"`
Then: `git diff --stat resources/js/i18n` → 6 insertions, no deletions.

- [ ] **Step 5: Frontend**

In `resources/js/Pages/Teams/Show.vue`:
- Imports:

```js
import CompletionBar from '@/Components/Stats/CompletionBar.vue';
import { useFormatNumber } from '@/Composables/useFormatNumber';
import { completionBarProps } from '@/lib/completion';
```

- In `defineProps`, add `documentStats: Object,` to the existing list (e.g. after `canEditTeam: Boolean,`).
- After `const { can } = useCan();` add:

```js
const { formatNumber } = useFormatNumber();

// Types still missing for someone on the roster, in the server's worst-first
// order. No links: the players list has no roster filter.
const missingTypes = computed(() => (props.documentStats?.by_type ?? []).filter((row) => row.missing > 0));
```

- In the roster `<section v-if="tab === 'roster'" …>`, insert directly before `<table class="min-w-full divide-y …">`:

```vue
                <template v-if="documentStats && roster.length">
                    <CompletionBar v-bind="completionBarProps(documentStats)" size="sm" />
                    <details v-if="missingTypes.length" class="rounded-lg border border-border/70 px-4 py-2 text-sm">
                        <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">{{ t('stats.doc_missing_by_type') }}</summary>
                        <ul class="mt-2 space-y-1">
                            <li v-for="row in missingTypes" :key="row.id" class="flex flex-wrap justify-between gap-x-3">
                                <span class="min-w-0">{{ row.name }}</span>
                                <span class="text-slate-500 dark:text-slate-400">— {{ t('stats.doc_missing_for', { n: formatNumber(row.missing) }) }}</span>
                            </li>
                        </ul>
                    </details>
                </template>
```

`roster` (the saved season roster) gates the strip, not `rows` (the unsaved local copy). The strip describes what the server counted.

- [ ] **Step 6: Verify**

Run: `npm run build`, `npm run i18n:check`, `php artisan test --filter="Team"`
Expected: green.

Manual check (report it): open a team with a roster. The strip and the fold-out show, and the fold-out opens with the keyboard (Enter on the summary). It works in RTL and at 375 px. A coach without documents/view sees neither.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TeamController.php resources/js/Pages/Teams/Show.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Competitions/TeamDocumentStatsTest.php
git commit -m "feat(teams): document completion of the season roster

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## After all tasks (controller)

1. Full suite: `npm run build && npm run test:js && npm run i18n:check && php artisan test`. Only the known `ManualSuspensionsTest` failure is allowed.
2. Browser QA in headless Chrome, server on :2027. Cover the dashboard members tab, `/players` and a team page. Check each of these:
   - RTL + dark;
   - 375 px with `scrollWidth === 375`;
   - links land on the filtered list with matching counts;
   - the CSV has a BOM and a totals row with no percent total;
   - the table toggle fires 0 XHR;
   - the console is clean;
   - a user with players/teams view but no documents right sees none of the three.
3. Whole-branch review on the most capable model; one fix round.
4. Owner check before merge. MySQL dry run of the two aggregate queries (`EXPLAIN`) on the production-like copy, if available.

## Spec ambiguities resolved in this plan

- **One grouped query for the dashboard.** The spec has two methods (`summary` one query, `byCategory` one query). This plan has `summary()` plus `breakdown()`. `breakdown()` returns `{summary, by_category}` from ONE `GROUP BY category` query, because categories partition players. The dashboard therefore costs +2 queries (types read + grouped query), within the spec's "≤ 2".
- **The types read is a query.** `summary()` must first read the active required types, so the Players list and Teams/Show each cost +2 queries, not +1. Sharing the types with `missingCountSql()` would mean changing an existing method, which the guardrails forbid.
- **Photo + picture vs age.** The age limit still gates "required" for a Photo. A picture beats only an exempt record. No seeded Photo has `max_age`, so this changes nothing today.
- **Colour tokens.** `chartTheme.js` has no positive / warning / negative exports. Categorical slots 2/3/7 are used through `completionColors()`, with separators and printed counts.
- **Empty text.** `summary()` is null when no type is set up (nothing renders), so the bar's `required = 0` text reads "No documents are required for these players".
- **Uncategorised row.** It has no link, because the list has no "no category" filter.
- **Owner decisions after planning (2026-11-01).** Members tab: the bar goes right below the member tiles (not at the top). Teams/Show: count the season roster as saved (archived / left players still on the roster count), same as the roster table.
- **Players list "missing" link.** It replaces the documents filter value instead of adding to it, because the filter's values OR together.
