# Branch Document Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each branch ticks which shared document types it requires; a player's per-branch documents tab lists only that branch's types; and receiving a multi-copy document records how many copies came in, fewer = still missing.

**Architecture:** A pivot `branch_document_type` links types to branches. `DocumentChecklist` (PHP checklist + SQL twins) is the single rule engine; every consumer (players list, stats, dashboard, teams, match flags, report) inherits the branch tick and copies rule through it. New column `player_documents.copies_received`; new checklist state `incomplete`.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), vue-i18n JSON (`resources/js/i18n/{ar,fr,en}.json`), PHPUnit feature tests (`php artisan test`), SQLite dev, MySQL/MariaDB and PostgreSQL in production.

**Spec:** `docs/superpowers/specs/2026-11-07-branch-document-types-design.md`

## Global Constraints

- Production is PostgreSQL; migrations and raw SQL must run on SQLite, MySQL/MariaDB and PostgreSQL. No SQLite-only SQL.
- Migrations: idempotent (every step checks its own work), literal table names, never use models.
- Explicit-only ticks: a type applies only to the ticked branches. Migration ticks every existing branch on every existing type. A new branch starts with none; a new type starts with all branches preselected in the form.
- A player with no registration at all keeps owing every active type (no branch to check).
- Unticked records are kept, never counted, shown greyed in a "No longer required in this branch" section, files downloadable.
- Records of inactive types keep their current handling (greyed in `items`, never counted).
- Incomplete = received with `copies_received < type.copies`; counts missing (like expired). Check order in `evaluate()`: exempt → expired → incomplete → expires soon → received.
- PHP checklist and SQL counts must agree (existing tests assert this; extend them).
- i18n: insert-only edits to the three JSON files (never reorder or rewrite), flat keys; run `npm run i18n:check`. No CDN/remote resources.
- Git: work in a worktree branch `feat/branch-document-types` (main moves under other sessions). Stage explicit files only. Never push without checking `git log origin/main..main` and the main tip first.

## File Structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_11_07_100001_create_branch_document_type.php` | pivot + backfill all branches × all types |
| `database/migrations/2026_11_07_100002_add_copies_received_to_player_documents.php` | column + backfill received rows with type copies |
| `app/Models/DocumentType.php` | `branches()`, `idsByBranch()` |
| `app/Models/Branch.php` | `documentTypes()` |
| `app/Models/PlayerDocument.php` | `copies_received` fillable + cast |
| `app/Services/Player/DocumentChecklist.php` | branch tick + retired list + incomplete state, PHP and SQL |
| `app/Services/Player/PlayerDocumentService.php` | store `copies_received` |
| `app/Http/Controllers/PlayerDocumentController.php` | refuse unticked type; validate copies |
| `app/Http/Controllers/DocumentTypeController.php` | sync branches; send branches + per-branch record counts |
| `app/Http/Controllers/PlayerController.php` | "missing type X" options limited to the selected branch |
| `app/Reports/Players/PlayerDocuments.php` | skip unticked types; incomplete state |
| `app/Console/Commands/VerifyRegistrations.php` | warning for records of unticked types |
| `resources/js/Pages/Settings/DocumentTypes.vue` | branches checkboxes, chips, banner, untick confirm, copies hint |
| `resources/js/Components/PlayerDocumentsCard.vue` | copies field, incomplete badge, retired section |
| `resources/js/i18n/{ar,fr,en}.json` | new keys |
| `tests/Support/DocumentBranchFixtures.php` | `requireDocumentsEverywhere()` test helper |
| `tests/Feature/Registrations/BranchDocumentTypesTest.php` | engine tests (branch ticks + copies) |
| `tests/Feature/Registrations/BranchDocumentTypesMigrationTest.php` | both migrations |
| `tests/Feature/Registrations/BranchDocumentWritersTest.php` | controllers, report, list options |

---

### Task 0: Worktree

- [ ] **Step 1: Create the worktree from the current origin tip**

```bash
cd /d/irnb-laravel
git fetch origin
git worktree add -b feat/branch-document-types ../irnb-branch-docs origin/main
cp docs/superpowers/specs/2026-11-07-branch-document-types-design.md ../irnb-branch-docs/docs/superpowers/specs/
cp docs/superpowers/plans/2026-11-07-branch-document-types.md ../irnb-branch-docs/docs/superpowers/plans/
```

- [ ] **Step 2: Give the worktree its own vendor and node_modules (copies, not junctions) and env**

```bash
cd /d/irnb-branch-docs
cp -r ../irnb-laravel/vendor ./vendor
cp -r ../irnb-laravel/node_modules ./node_modules
cp ../irnb-laravel/.env ./.env
php artisan test --filter=DocumentChecklistRegistrationTest
```

Expected: PASS (baseline green).

- [ ] **Step 3: Commit spec and plan**

```bash
git add docs/superpowers/specs/2026-11-07-branch-document-types-design.md docs/superpowers/plans/2026-11-07-branch-document-types.md
git commit -m "docs: branch document types spec and plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

All later paths are relative to `D:\irnb-branch-docs`.

---

### Task 1: Pivot table, relations, test helper

**Files:**
- Create: `database/migrations/2026_11_07_100001_create_branch_document_type.php`
- Modify: `app/Models/DocumentType.php`, `app/Models/Branch.php`
- Create: `tests/Support/DocumentBranchFixtures.php`
- Test: `tests/Feature/Registrations/BranchDocumentTypesMigrationTest.php`

**Interfaces:**
- Produces: table `branch_document_type(branch_id, document_type_id)`; `DocumentType::branches(): BelongsToMany`; `Branch::documentTypes(): BelongsToMany`; `DocumentType::idsByBranch(): array<int, list<int>>` (branch id → ticked type ids); trait `Tests\Support\DocumentBranchFixtures` with `requireDocumentsEverywhere(): void`.

- [ ] **Step 1: Write the failing migration test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BranchDocumentTypesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PIVOT = 'migrations/2026_11_07_100001_create_branch_document_type.php';

    #[Test]
    public function the_backfill_ticks_every_branch_on_every_type_once(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        DB::table('branch_document_type')->delete();

        $migration = require database_path(self::PIVOT);
        $migration->up();
        $migration->up(); // a second run changes nothing

        $types = DocumentType::count();
        $this->assertGreaterThan(0, $types);
        $this->assertSame($types * 2, DB::table('branch_document_type')->count());
        $this->assertSame($types, DB::table('branch_document_type')->where('branch_id', $swim->id)->count());
        $this->assertEqualsCanonicalizing(
            DocumentType::pluck('id')->all(),
            DocumentType::idsByBranch()[$foot->id],
        );
    }

    #[Test]
    public function a_new_branch_starts_with_no_documents(): void
    {
        $branch = Branch::create(['name' => 'Judo', 'code' => 'JU']);

        $this->assertSame(0, $branch->documentTypes()->count());
        $this->assertArrayNotHasKey($branch->id, DocumentType::idsByBranch());
    }

    #[Test]
    public function deleting_a_branch_or_type_removes_its_ticks(): void
    {
        $branch = Branch::create(['name' => 'Judo', 'code' => 'JU']);
        $type = DocumentType::create(['code' => 'x', 'name' => 'X', 'validity' => 'none']);
        $type->branches()->attach($branch->id);

        $type->delete();

        $this->assertSame(0, DB::table('branch_document_type')->where('branch_id', $branch->id)->count());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=BranchDocumentTypesMigrationTest`
Expected: FAIL (`no such table: branch_document_type` / migration file missing).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which document types each branch requires (owner, 2026-11-07: one shared list,
 * each branch ticks its own). Explicit only: a type applies to the ticked
 * branches and nothing else. Every existing branch gets every existing type, so
 * nothing changes on deploy day; a branch created later starts with none.
 *
 * The backfill runs only on an empty table, so a second run changes nothing.
 * Literal values, not the models: a migration must not move when the code does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_document_type')) {
            Schema::create('branch_document_type', function (Blueprint $table) {
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
                $table->unique(['branch_id', 'document_type_id']);
                $table->index('document_type_id');
            });
        }

        if (DB::table('branch_document_type')->doesntExist()) {
            DB::table('branch_document_type')->insertUsing(
                ['branch_id', 'document_type_id'],
                DB::table('branches')->crossJoin('document_types')->select('branches.id as branch_id', 'document_types.id as document_type_id'),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_document_type');
    }
};
```

- [ ] **Step 4: Add the relations**

In `app/Models/DocumentType.php` add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and `use Illuminate\Support\Facades\DB;`, then after `playerDocuments()`:

```php
    /** The branches that require this type. Explicit only: none ticked = required nowhere. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_document_type');
    }

    /**
     * Branch id → the type ids that branch requires, in one query, for code that
     * walks many registrations (the documents report). A branch with no ticked
     * type has no key.
     *
     * @return array<int, list<int>>
     */
    public static function idsByBranch(): array
    {
        $map = [];
        foreach (DB::table('branch_document_type')->get(['branch_id', 'document_type_id']) as $row) {
            $map[(int) $row->branch_id][] = (int) $row->document_type_id;
        }

        return $map;
    }
```

In `app/Models/Branch.php` after `subscriptions()`:

```php
    /** The document types this branch requires (Settings → Document types). */
    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'branch_document_type');
    }
```

(`BelongsToMany` is already imported in Branch.php — check the `use` lines; add it if missing.)

- [ ] **Step 5: Write the test helper**

`tests/Support/DocumentBranchFixtures.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\DocumentType;

/**
 * Types and branches created inside a test are not linked: the migration's
 * backfill ran before they existed. Call this after creating them when a test
 * means "every branch requires every type" (the behaviour before 2026-11-07).
 */
trait DocumentBranchFixtures
{
    private function requireDocumentsEverywhere(): void
    {
        $branchIds = Branch::pluck('id')->all();

        DocumentType::all()->each(fn (DocumentType $type) => $type->branches()->syncWithoutDetaching($branchIds));
    }
}
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=BranchDocumentTypesMigrationTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_07_100001_create_branch_document_type.php app/Models/DocumentType.php app/Models/Branch.php tests/Support/DocumentBranchFixtures.php tests/Feature/Registrations/BranchDocumentTypesMigrationTest.php
git commit -m "feat(documents): branches tick the document types they require

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Rule engine follows the branch ticks (PHP + SQL)

**Files:**
- Modify: `app/Services/Player/DocumentChecklist.php`
- Test: `tests/Feature/Registrations/BranchDocumentTypesTest.php`
- Modify (fixtures only): every existing test that fails after this change — see Step 6.

**Interfaces:**
- Consumes: `DocumentType::branches()`, table `branch_document_type`.
- Produces: `DocumentChecklist::for()` return gains `'retired' => list<item>` (same item shape as `items`, never counted). `forPlayer()` tabs carry it too. SQL helper signatures unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Registrations/BranchDocumentTypesTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchDocumentTypesTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
        // Football requires it, Swimming does not.
        $this->medical->branches()->sync([$this->foot->id]);
    }

    private function received(Player $player, Branch $branch, array $attributes = []): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->subMonth()->toDateString(),
            ...$attributes,
        ]);
    }

    private function checklist(Player $player, Branch $branch): array
    {
        return DocumentChecklist::for($player, null, $player->registrations()->where('branch_id', $branch->id)->firstOrFail());
    }

    /** @param array{0: string, 1: array<int, mixed>} $term */
    private function term(Player $player, array $term): int
    {
        return (int) Player::query()->whereKey($player->id)->selectRaw("{$term[0]} as n", $term[1])->toBase()->first()->n;
    }

    private function expiring(Player $player, ?Branch $branch = null): bool
    {
        [$sql, $bindings] = DocumentChecklist::expiringSoonSql(null, $branch?->id);

        return Player::query()->whereKey($player->id)->whereRaw($sql, $bindings)->exists();
    }

    #[Test]
    public function only_the_ticked_branch_owes_the_type(): void
    {
        $player = $this->bothActive();

        $foot = $this->checklist($player, $this->foot);
        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([$this->medical->id], array_column(array_column($foot['items'], 'type'), 'id'));
        $this->assertSame(1, $foot['missing_count']);
        $this->assertSame([], $swim['items']);
        $this->assertSame(0, $swim['missing_count']);

        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->foot->id)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->swim->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql()));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingRowsSql($this->medical)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingTypeSql($this->medical, null, $this->swim->id)));
    }

    #[Test]
    public function a_record_in_an_unticked_branch_is_retired_not_counted(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim, ['valid_until' => now()->addDays(10)->toDateString()]);

        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([], $swim['items']);
        $this->assertCount(1, $swim['retired']);
        $this->assertSame($this->medical->id, $swim['retired'][0]['type']['id']);
        $this->assertFalse($swim['retired'][0]['counts_missing']);
        $this->assertSame(0, $swim['expiring_count']);
        $this->assertFalse($this->expiring($player, $this->swim));
        $this->assertFalse($this->expiring($player));
        $this->assertSame(0, $this->term($player, DocumentChecklist::expiringTypeSql($this->medical, null, $this->swim->id)));
    }

    #[Test]
    public function re_ticking_brings_the_record_back(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim);
        $this->medical->branches()->attach($this->swim->id);

        $swim = $this->checklist($player, $this->swim);
        $this->assertSame([], $swim['retired']);
        $this->assertSame(0, $swim['missing_count']);
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->swim->id)));
    }

    #[Test]
    public function a_player_with_no_registration_owes_every_active_type(): void
    {
        $player = $this->noRegistration();
        $this->medical->branches()->sync([]);

        $this->assertSame(1, DocumentChecklist::for($player)['missing_count']);
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql()));
    }

    #[Test]
    public function the_player_page_tabs_carry_their_retired_records(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->swim);

        $tabs = collect(DocumentChecklist::forPlayer($player)['tabs'])->keyBy('branch_id');
        $this->assertSame([], $tabs[$this->foot->id]['retired']);
        $this->assertCount(1, $tabs[$this->swim->id]['retired']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=BranchDocumentTypesTest`
Expected: FAIL (Swimming still lists Medical; `retired` key undefined).

- [ ] **Step 3: PHP side — filter types by branch, build `retired`**

In `DocumentChecklist::for()`, replace the block from `$types = DocumentType::query()` down to the `return [` array with:

```php
        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $documents->keys()->all()))
            ->ordered()
            ->get();

        // Explicit ticks (owner, 2026-11-07): a registration owes the active types
        // its branch requires. A player with no registration has no branch and owes
        // every active type. An active type its branch no longer requires is
        // "retired": kept and shown when a record exists, never counted. Inactive
        // types keep their old handling (greyed in items, never counted).
        $ticked = $registration === null ? null
            : DB::table('branch_document_type')->where('branch_id', $registration->branch_id)
                ->pluck('document_type_id')->map(fn ($id) => (int) $id)->all();
        $required = fn (DocumentType $type) => $ticked === null || ! $type->is_active || in_array($type->id, $ticked, true);

        $items = $types->filter($required)
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today, $left))
            ->values()
            ->all();

        $retired = $types->reject($required)
            ->filter(fn (DocumentType $type) => $documents->has($type->id))
            // As on a left registration: nothing is expected, so nothing counts.
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today, true))
            ->values()
            ->all();

        return [
            'registration_id' => $registration?->id,
            'left' => $left,
            'items' => $items,
            'retired' => $retired,
            'missing_count' => count(array_filter($items, fn (array $item) => $item['counts_missing'])),
            'expiring_count' => $left ? 0 : count(array_filter(
                $items,
                fn (array $item) => $item['state'] === self::EXPIRES_SOON && $item['type']['is_active']
            )),
        ];
```

Add `use Illuminate\Support\Facades\DB;` at the top. Update the `@return` docblock of `for()` to include `retired: list<array<string, mixed>>`.

- [ ] **Step 4: SQL side — the branch tick in `rowsSql` and `expiringSoonSql`**

Change `rowsSql` to take the type id and require the tick on a registration row (a player alone is unaffected):

```php
    private static function rowsSql(string $test, array $bindings, int $typeId, ?int $branchId, bool $joinedRow): array
    {
        $registered = '(SELECT COUNT(*) FROM branch_player r WHERE r.player_id = players.id AND '.self::ACTIVE_ROW
            .($branchId !== null ? ' AND r.branch_id = ?' : '')
            .($joinedRow ? ' AND r.id = reg.id' : '')
            // Only a branch that requires the type owes it (explicit ticks).
            .' AND EXISTS (SELECT 1 FROM branch_document_type bdt WHERE bdt.branch_id = r.branch_id AND bdt.document_type_id = ?)'
            .' AND '.str_replace('{owner}', 'pd.branch_registration_id = r.id', $test).')';

        $alone = '(CASE WHEN NOT EXISTS (SELECT 1 FROM branch_player r0 WHERE r0.player_id = players.id) AND '
            .str_replace('{owner}', 'pd.player_id = players.id AND pd.branch_registration_id IS NULL', $test)
            .' THEN 1 ELSE 0 END)';

        return ["({$registered} + {$alone})", [...($branchId !== null ? [$branchId] : []), $typeId, ...$bindings, ...$bindings]];
    }
```

Update its three callers to pass `$type->id` as the third argument:
- `missingRowsSql`: `self::rowsSql($unsettled, [$type->id, $today->toDateString()], $type->id, $branchId, $joinedRow)`
- `requiredTypeSql`: `self::rowsSql($test, [$type->id], $type->id, $branchId, $joinedRow)`
- `expiringTypeSql`: `self::rowsSql($expiring, [ ...same three bindings... ], $type->id, $branchId, $joinedRow)`

In `expiringSoonSql`, inside the `pd.branch_registration_id IN (SELECT r.id FROM branch_player r WHERE ...)` subquery, append after the optional branch clause (no new binding):

```php
                .($branchId !== null ? ' AND r.branch_id = ?' : '')
                .' AND EXISTS (SELECT 1 FROM branch_document_type bdt WHERE bdt.branch_id = r.branch_id AND bdt.document_type_id = pd.document_type_id)'
                .')))',
```

Update the class docblock: add a line "Each registration owes only the types its branch ticks (branch_document_type); a player with no registration owes every active type."

- [ ] **Step 5: Run the new tests**

Run: `php artisan test --filter=BranchDocumentTypesTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Fix existing fixtures that relied on "every branch owes every type"**

Run: `php artisan test`
Expected failures: tests that create branches/registrations and types inside the test (types created after the migration's backfill are ticked nowhere). Known candidates: `tests/Feature/Registrations/DocumentChecklistRegistrationTest.php`, `DocumentStatsRegistrationTest.php`, `DocumentFlagsAndReportTest.php`, `PlayerPageDocumentsTest.php`, `DocumentWritersRegistrationTest.php`, `tests/Feature/Competitions/PlayerFlagsTest.php`, `LicenceAndMatchDocumentsTest.php`, `tests/Feature/Reports/PlayerReportsTest.php`, `tests/Feature/DocumentChecklistTest.php`, `tests/Feature/PlayerDocumentActionsTest.php`.

For each failing test class: add `use Tests\Support\DocumentBranchFixtures;` and the trait to its `use` list, then call `$this->requireDocumentsEverywhere();` at the end of `setUp()` (after its branches and types are created). Where a test creates a branch or type inside the test method itself, call it right after that creation instead. Do not change any assertion. Example for `DocumentChecklistRegistrationTest::setUp()`:

```php
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
        $this->requireDocumentsEverywhere();
```

Re-run `php artisan test` until green. If a failure is NOT fixed by the helper, stop and investigate — it is a real behaviour change, not a fixture gap.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Player/DocumentChecklist.php tests/Feature/Registrations/BranchDocumentTypesTest.php <each fixture file you changed>
git commit -m "feat(documents): each registration owes only its branch's document types

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Copies received — column and incomplete state (PHP + SQL)

**Files:**
- Create: `database/migrations/2026_11_07_100002_add_copies_received_to_player_documents.php`
- Modify: `app/Models/PlayerDocument.php`, `app/Services/Player/DocumentChecklist.php`
- Test: `tests/Feature/Registrations/BranchDocumentTypesTest.php`, `tests/Feature/Registrations/BranchDocumentTypesMigrationTest.php`

**Interfaces:**
- Produces: column `player_documents.copies_received` (nullable unsigned small int); `DocumentChecklist::INCOMPLETE = 'incomplete'`; item `document.copies_received`.

- [ ] **Step 1: Failing tests**

Append to `BranchDocumentTypesTest`:

```php
    #[Test]
    public function fewer_copies_than_asked_is_incomplete_and_counts_missing(): void
    {
        $this->medical->update(['copies' => 3]);
        $player = $this->bothActive();
        $document = $this->received($player, $this->foot, ['copies_received' => 2, 'valid_until' => now()->addDays(10)->toDateString()]);

        $foot = $this->checklist($player, $this->foot);
        $this->assertSame(DocumentChecklist::INCOMPLETE, $foot['items'][0]['state']);
        $this->assertTrue($foot['items'][0]['counts_missing']);
        $this->assertSame(2, $foot['items'][0]['document']['copies_received']);
        $this->assertSame(1, $foot['missing_count']);
        $this->assertSame(0, $foot['expiring_count']);
        $type = $this->medical->fresh();
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->foot->id)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::expiringTypeSql($type, null, $this->foot->id)));
        $this->assertFalse($this->expiring($player, $this->foot));

        $document->update(['copies_received' => 3]);

        $foot = $this->checklist($player, $this->foot);
        $this->assertSame(DocumentChecklist::EXPIRES_SOON, $foot['items'][0]['state']);
        $this->assertSame(0, $foot['missing_count']);
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->foot->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::expiringTypeSql($type, null, $this->foot->id)));
        $this->assertTrue($this->expiring($player, $this->foot));
    }

    #[Test]
    public function an_expired_incomplete_record_reads_expired(): void
    {
        $this->medical->update(['copies' => 3]);
        $player = $this->bothActive();
        $this->received($player, $this->foot, ['copies_received' => 1, 'valid_until' => now()->subDay()->toDateString()]);

        $this->assertSame(DocumentChecklist::EXPIRED, $this->checklist($player, $this->foot)['items'][0]['state']);
    }

    #[Test]
    public function a_blank_copies_count_reads_complete(): void
    {
        $this->medical->update(['copies' => 3]);
        $player = $this->bothActive();
        $this->received($player, $this->foot, ['copies_received' => null]);

        $this->assertSame(0, $this->checklist($player, $this->foot)['missing_count']);
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingCountSql(null, null, $this->foot->id)));
    }
```

Append to `BranchDocumentTypesMigrationTest`:

```php
    private const COPIES = 'migrations/2026_11_07_100002_add_copies_received_to_player_documents.php';

    #[Test]
    public function received_rows_get_their_types_copies_and_exempt_rows_stay_blank(): void
    {
        $type = DocumentType::create(['code' => 'cp', 'name' => 'Copies', 'validity' => 'none', 'copies' => 3]);
        $player = \App\Models\Player::create(['membership_id' => 'CP-1', 'firstname' => 'A', 'lastname' => 'B']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $player->id, 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => '2026-09-01']);
        $other = DocumentType::where('code', 'birth_certificate')->firstOrFail();
        $exempt = DB::table('player_documents')->insertGetId(['player_id' => $player->id, 'document_type_id' => $other->id, 'state' => 'exempt']);

        $migration = require database_path(self::COPIES);
        $migration->up();
        $migration->up();

        $this->assertSame(3, (int) DB::table('player_documents')->where('id', $received)->value('copies_received'));
        $this->assertNull(DB::table('player_documents')->where('id', $exempt)->value('copies_received'));
    }
```

(Inserting with `copies_received` absent works both before and after the column exists; the migration's backfill only touches rows where it is null.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter="BranchDocumentTypesTest|BranchDocumentTypesMigrationTest"`
Expected: FAIL (unknown column `copies_received`, constant `INCOMPLETE` undefined).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many copies of a document came in (owner, 2026-11-07). Fewer than the type
 * asks for = incomplete, still missing. Every received row gets its type's copies,
 * so no old receipt turns incomplete on deploy day. Exempt rows stay blank.
 * Idempotent: the backfill touches blank received rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('player_documents', 'copies_received')) {
            Schema::table('player_documents', fn (Blueprint $table) => $table->unsignedSmallInteger('copies_received')->nullable());
        }

        DB::table('player_documents')
            ->where('state', 'received')
            ->whereNull('copies_received')
            ->update(['copies_received' => DB::raw('(SELECT document_types.copies FROM document_types WHERE document_types.id = player_documents.document_type_id)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('player_documents', 'copies_received')) {
            Schema::table('player_documents', fn (Blueprint $table) => $table->dropColumn('copies_received'));
        }
    }
};
```

- [ ] **Step 4: Model**

`app/Models/PlayerDocument.php`: add `'copies_received'` to `$fillable` (after `'valid_until'`), and `'copies_received' => 'integer'` to `casts()`.

- [ ] **Step 5: PHP rule**

In `DocumentChecklist`, add after `public const EXPIRED = 'expired';`:

```php
    /** Received, but fewer copies than the type asks for: still missing (owner, 2026-11-07). */
    public const INCOMPLETE = 'incomplete';
```

In `evaluate()`, after the expired check and before the expires-soon check:

```php
        if ($document->copies_received !== null && $document->copies_received < $type->copies) {
            return self::result(self::INCOMPLETE, null, $expected);
        }
```

In `item()`, inside the `'document'` array after `'valid_until'`:

```php
                'copies_received' => $document->copies_received,
```

- [ ] **Step 6: SQL rule**

In `missingRowsSql`, replace the `$unsettled` string and its bindings:

```php
        // Settled: exempt, or received, still valid and with every copy in.
        $unsettled = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND (pd.state = 'exempt' OR (pd.state = 'received'"
            .' AND (pd.valid_until IS NULL OR pd.valid_until >= ?)'
            .' AND (pd.copies_received IS NULL OR pd.copies_received >= ?))))';

        return self::whenPlayer($conditions, $bindings, self::rowsSql($unsettled, [$type->id, $today->toDateString(), (int) $type->copies], $type->id, $branchId, $joinedRow));
```

In `expiringTypeSql`, replace `$expiring` and its bindings:

```php
        // An incomplete record is missing, not expiring.
        $expiring = 'EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?"
            .' AND (pd.copies_received IS NULL OR pd.copies_received >= ?))';

        return self::whenPlayer($conditions, $bindings, self::rowsSql($expiring, [
            $type->id,
            $today->toDateString(),
            $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
            (int) $type->copies,
        ], $type->id, $branchId, $joinedRow));
```

In `expiringSoonSql`, after `." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?"` add (no binding):

```php
                .' AND (pd.copies_received IS NULL OR pd.copies_received >= dt.copies)'
```

Update the class docblock's rule summary: "settled = exempt, or received, valid and with every copy in (copies_received >= type copies, blank = complete)".

- [ ] **Step 7: Run**

Run: `php artisan test --filter="BranchDocumentTypesTest|BranchDocumentTypesMigrationTest|DocumentChecklist"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_11_07_100002_add_copies_received_to_player_documents.php app/Models/PlayerDocument.php app/Services/Player/DocumentChecklist.php tests/Feature/Registrations/BranchDocumentTypesTest.php tests/Feature/Registrations/BranchDocumentTypesMigrationTest.php
git commit -m "feat(documents): fewer copies than asked leaves a document incomplete

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Write paths — refuse unticked types, store copies

**Files:**
- Modify: `app/Http/Controllers/PlayerDocumentController.php`, `app/Services/Player/PlayerDocumentService.php`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (flash key only)
- Test: `tests/Feature/Registrations/BranchDocumentWritersTest.php`

**Interfaces:**
- Consumes: `DocumentType::branches()`, `copies_received` column.
- Produces: flash key `flash.document_not_required_in_branch`; receipt array shape `{received_at, valid_until, notes, copies_received: int}`.

- [ ] **Step 1: Failing tests**

`tests/Feature/Registrations/BranchDocumentWritersTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchDocumentWritersTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    private User $admin;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true, 'copies' => 3]);
        $this->medical->branches()->sync([$this->foot->id]);
        $this->player = $this->bothActive();
    }

    private function registrationId(\App\Models\Branch $branch): int
    {
        return $this->player->registrations()->where('branch_id', $branch->id)->value('id');
    }

    private function receive(\App\Models\Branch $branch, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('players.documents.store', $this->player), [
            'document_type_id' => $this->medical->id,
            'branch_registration_id' => $this->registrationId($branch),
            'received_at' => now()->toDateString(),
            ...$extra,
        ]);
    }

    #[Test]
    public function receiving_in_a_branch_that_does_not_require_the_type_is_refused(): void
    {
        $this->receive($this->swim, ['copies_received' => 3])->assertSessionHas('error', 'flash.document_not_required_in_branch');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function exempting_in_a_branch_that_does_not_require_the_type_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('players.documents.exempt', $this->player), [
            'document_type_id' => $this->medical->id,
            'branch_registration_id' => $this->registrationId($this->swim),
            'reason' => 'Not needed',
        ])->assertSessionHas('error', 'flash.document_not_required_in_branch');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function renewing_a_retired_record_is_refused(): void
    {
        $document = PlayerDocument::create(['player_id' => $this->player->id, 'branch_registration_id' => $this->registrationId($this->swim), 'document_type_id' => $this->medical->id, 'state' => 'received', 'received_at' => '2026-09-01', 'copies_received' => 3]);

        $this->actingAs($this->admin)->put(route('players.documents.update', [$this->player, $document]), [
            'received_at' => now()->toDateString(), 'copies_received' => 3,
        ])->assertSessionHas('error', 'flash.document_not_required_in_branch');
    }

    #[Test]
    public function the_copies_received_are_stored(): void
    {
        $this->receive($this->foot, ['copies_received' => 2])->assertSessionHas('success');

        $this->assertSame(2, PlayerDocument::firstOrFail()->copies_received);
    }

    #[Test]
    public function copies_default_to_the_full_number(): void
    {
        $this->receive($this->foot)->assertSessionHas('success');

        $this->assertSame(3, PlayerDocument::firstOrFail()->copies_received);
    }

    #[Test]
    public function copies_out_of_range_are_rejected(): void
    {
        $this->receive($this->foot, ['copies_received' => 0])->assertSessionHasErrors('copies_received');
        $this->receive($this->foot, ['copies_received' => 4])->assertSessionHasErrors('copies_received');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function renewing_can_raise_the_copies(): void
    {
        $this->receive($this->foot, ['copies_received' => 2]);
        $document = PlayerDocument::firstOrFail();

        $this->actingAs($this->admin)->put(route('players.documents.update', [$this->player, $document]), [
            'received_at' => now()->toDateString(), 'copies_received' => 3,
        ])->assertSessionHas('success');

        $this->assertSame(3, $document->fresh()->copies_received);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=BranchDocumentWritersTest`
Expected: FAIL (records created in Swimming; `copies_received` null).

- [ ] **Step 3: Controller**

In `PlayerDocumentController`:

`store()` — after `$registration = $this->registration($request, $player);`:

```php
        if (! $this->requiredIn($registration, $type)) {
            return back()->with('error', 'flash.document_not_required_in_branch');
        }
```

`exempt()` — same three lines after `$registration = ...`.

`update()` — after the `RECEIVED` state check:

```php
        if (! $this->requiredIn($document->registration, $document->type)) {
            return back()->with('error', 'flash.document_not_required_in_branch');
        }
```

New private method:

```php
    /** A registration owes only the types its branch ticks; a player with none owes every active type. */
    private function requiredIn(?BranchRegistration $registration, DocumentType $type): bool
    {
        return $registration === null || $type->branches()->whereKey($registration->branch_id)->exists();
    }
```

`receipt()` — add the rule and the value:

```php
            'copies_received' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) $type->copies)],
```

and return:

```php
        return [
            'received_at' => $data['received_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
            // Left out = every copy came in (the form always sends it).
            'copies_received' => (int) ($data['copies_received'] ?? $type->copies),
        ];
```

Update `receipt()`'s `@return` shape to include `copies_received: int`.

- [ ] **Step 4: Service**

In `PlayerDocumentService::markReceived()` add to the `create([...])` array after `'valid_until'`:

```php
            'copies_received' => $receipt['copies_received'] ?? $type->copies,
```

In `renew()` add to the `update([...])` array after `'valid_until'`:

```php
            'copies_received' => $receipt['copies_received'] ?? $document->type->copies,
```

Update both `@param` receipt shapes to include `copies_received?: int`.

- [ ] **Step 5: Flash key (insert-only)**

In each JSON, insert directly after the `"flash.document_already_recorded"` line:

- en: `"flash.document_not_required_in_branch": "This branch does not require this document.",`
- fr: `"flash.document_not_required_in_branch": "Cette branche n'exige pas ce document.",`
- ar: `"flash.document_not_required_in_branch": "هذا الفرع لا يشترط هذه الوثيقة.",`

- [ ] **Step 6: Run**

Run: `php artisan test --filter="BranchDocumentWritersTest|PlayerDocumentActionsTest|DocumentWritersRegistrationTest|ActivityBoardDocumentEventsTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PlayerDocumentController.php app/Services/Player/PlayerDocumentService.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/BranchDocumentWritersTest.php
git commit -m "feat(documents): record copies received; refuse documents a branch does not require

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Report, players-list options, integrity check

**Files:**
- Modify: `app/Reports/Players/PlayerDocuments.php`, `app/Http/Controllers/PlayerController.php:194-198`, `app/Console/Commands/VerifyRegistrations.php`
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`doc_state_incomplete`)
- Test: `tests/Feature/Registrations/BranchDocumentWritersTest.php`

**Interfaces:**
- Consumes: `DocumentType::idsByBranch()`, `DocumentChecklist::INCOMPLETE`.

- [ ] **Step 1: Failing tests** (append to `BranchDocumentWritersTest`)

```php
    #[Test]
    public function the_report_lists_a_type_only_where_its_branch_requires_it(): void
    {
        $rows = app(\App\Reports\Players\PlayerDocuments::class)->rows(new \App\Reports\ReportFilters([]));
        $mine = $rows->where('player_id', $this->player->id)->where('document', 'Medical');

        $this->assertSame(['Football'], $mine->pluck('branch')->values()->all());
    }

    #[Test]
    public function the_report_shows_an_incomplete_record_with_its_copies(): void
    {
        $this->receive($this->foot, ['copies_received' => 2]);

        $row = app(\App\Reports\Players\PlayerDocuments::class)->rows(new \App\Reports\ReportFilters([]))
            ->where('player_id', $this->player->id)->where('document', 'Medical')->first();

        $this->assertSame(\App\Services\Player\DocumentChecklist::INCOMPLETE, $row['state_code']);
        $this->assertStringContainsString('2 / 3', $row['state']);
    }

    #[Test]
    public function the_missing_type_options_follow_the_selected_branch(): void
    {
        $this->actingAs($this->admin)->get(route('players.index', ['branch_id' => [$this->swim->id]]))
            ->assertInertia(fn ($page) => $page->where('documentTypes', fn ($types) => collect($types)->doesntContain('id', $this->medical->id)));

        $this->actingAs($this->admin)->get(route('players.index', ['branch_id' => [$this->foot->id]]))
            ->assertInertia(fn ($page) => $page->where('documentTypes', fn ($types) => collect($types)->contains('id', $this->medical->id)));
    }
```

Before writing these, check two things and adapt the test (not the code) if they differ: (1) how `ReportFilters` is constructed in `tests/Feature/Reports/PlayerReportsTest.php`; (2) the query parameter name `selectedBranch()` reads in `PlayerController` (search `function selectedBranch`). Also note `documentTypes` is a lazy closure prop: if it is only sent on partial reloads, request it with `->get(route(...), ['X-Inertia' => ...])` the way existing PlayerController tests do (search tests for `documentTypes`).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=BranchDocumentWritersTest`
Expected: the three new tests FAIL.

- [ ] **Step 3: Report**

In `app/Reports/Players/PlayerDocuments.php`:

- `STATES`: `[DocumentChecklist::MISSING, DocumentChecklist::INCOMPLETE, DocumentChecklist::EXPIRED, DocumentChecklist::EXPIRES_SOON]`.
- `summary()`: add after the missing KPI:

```php
            ['label' => 'doc_state_incomplete', 'value' => $rows->where('state_code', DocumentChecklist::INCOMPLETE)->count(), 'format' => 'number'],
```

- `evaluate()`: before the `$query = Player::query()` line add `$ticked = DocumentType::idsByBranch();`. Inside `foreach ($types as $type) {` first line:

```php
                    // A branch owes only the types it ticks; a player with no registration owes them all.
                    if ($registration !== null && ! in_array($type->id, $ticked[(int) $registration->branch_id] ?? [], true)) {
                        continue;
                    }
```

- In the pushed row, replace `'state' => UiLang::get('doc_state_'.$result['state']),` with:

```php
                        'state' => UiLang::get('doc_state_'.$result['state'])
                            .($result['state'] === DocumentChecklist::INCOMPLETE ? " ({$document->copies_received} / {$type->copies})" : ''),
```

- [ ] **Step 4: Players-list options**

In `PlayerController::index()`, replace the `'documentTypes' => fn () => ...` closure with:

```php
            // The "missing type X" options: only types that can be missing, and
            // with one branch selected, only those that branch requires.
            'documentTypes' => fn () => DocumentType::query()
                ->where('is_active', true)
                ->where('is_required', true)
                ->when($this->selectedBranch($request), fn ($query, $branchId) => $query->whereHas('branches', fn ($branches) => $branches->whereKey($branchId)))
                ->ordered()
                ->get(['id', 'code', 'name', 'name_ar', 'name_fr', 'name_en']),
```

- [ ] **Step 5: Integrity check**

In `VerifyRegistrations`, after the `document held twice` report:

```php
        // Kept on purpose when a branch stops requiring a type (owner, 2026-11-07): a warning, never a failure.
        $retiredDocuments = DB::table('player_documents as pd')
            ->join('branch_player as r', 'r.id', '=', 'pd.branch_registration_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('branch_document_type as bdt')
                ->whereColumn('bdt.branch_id', 'r.branch_id')->whereColumn('bdt.document_type_id', 'pd.document_type_id'))
            ->pluck('pd.id')->map(fn ($id) => "document {$id}")->all();
        $this->warning('document of a type its branch no longer requires (kept, not counted)', $retiredDocuments);
```

- [ ] **Step 6: `doc_state_incomplete` key (insert-only)**

Insert after the `"doc_state_expired"` line in each JSON:

- en: `"doc_state_incomplete": "Incomplete",`
- fr: `"doc_state_incomplete": "Incomplet",`
- ar: `"doc_state_incomplete": "ناقص",`

- [ ] **Step 7: Run**

Run: `php artisan test --filter="BranchDocumentWritersTest|PlayerReportsTest|DocumentFlagsAndReportTest|VerifyRegistrations"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Reports/Players/PlayerDocuments.php app/Http/Controllers/PlayerController.php app/Console/Commands/VerifyRegistrations.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/BranchDocumentWritersTest.php
git commit -m "feat(documents): report, list filter and integrity check follow branch ticks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Document Types settings — backend

**Files:**
- Modify: `app/Http/Controllers/DocumentTypeController.php`
- Test: `tests/Feature/DocumentTypeSettingsTest.php`

**Interfaces:**
- Produces Inertia props on `Settings/DocumentTypes`: `documentTypes[].branch_ids: list<int>`; `branches: list<{id:int, name:string}>`; `recordsByBranch: { [typeId]: { [branchId]: int } }`. Accepts `branch_ids` (optional array) on store/update; absent = pivot unchanged.

- [ ] **Step 1: Failing tests** (append to `DocumentTypeSettingsTest`)

```php
    #[Test]
    public function creating_a_type_ticks_the_chosen_branches(): void
    {
        $foot = \App\Models\Branch::create(['name' => 'Football', 'code' => 'FB']);
        \App\Models\Branch::create(['name' => 'Swimming', 'code' => 'SW']);

        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['branch_ids' => [$foot->id]]))
            ->assertSessionHas('success');

        $type = DocumentType::where('name', 'Licence fédérale')->firstOrFail();
        $this->assertSame([$foot->id], $type->branches()->pluck('branches.id')->all());
    }

    #[Test]
    public function updating_syncs_branches_and_leaving_them_out_keeps_them(): void
    {
        $foot = \App\Models\Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = \App\Models\Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $type = $this->type('birth_certificate');
        $type->branches()->sync([$foot->id, $swim->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'branch_ids' => [$swim->id]]));
        $this->assertSame([$swim->id], $type->branches()->pluck('branches.id')->all());

        $this->actingAs($admin)->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'is_active' => false]));
        $this->assertSame([$swim->id], $type->branches()->pluck('branches.id')->all());

        $this->actingAs($admin)->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'branch_ids' => []]));
        $this->assertSame(0, $type->branches()->count());
    }

    #[Test]
    public function an_unknown_branch_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['branch_ids' => [99999]]))
            ->assertSessionHasErrors('branch_ids.0');
    }

    #[Test]
    public function the_page_sends_branches_ticks_and_record_counts_per_branch(): void
    {
        $foot = \App\Models\Branch::create(['name' => 'Football', 'code' => 'FB']);
        $type = $this->type('birth_certificate');
        $type->branches()->sync([$foot->id]);
        $player = Player::create(['membership_id' => '202600602', 'firstname' => 'Sami', 'lastname' => 'Lounis']);
        app(\App\Services\Player\RegistrationService::class)->register($player, ['branch_id' => $foot->id]);
        PlayerDocument::create(['player_id' => $player->id, 'branch_registration_id' => $player->registrations()->value('id'), 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => '2026-09-01']);

        $this->actingAs($this->admin())->get(route('document-types.index'))
            ->assertInertia(fn ($page) => $page
                ->where('branches.0.id', $foot->id)
                ->where('documentTypes', fn ($types) => collect($types)->firstWhere('id', $type->id)['branch_ids'] === [$foot->id])
                ->where("recordsByBranch.{$type->id}.{$foot->id}", 1));
    }
```

(If `RegistrationService::register()` needs a `category_id` in this test environment, pass one created with `Category::create(['name' => 'Senior', 'branch_id' => $foot->id])`.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=DocumentTypeSettingsTest`
Expected: new tests FAIL.

- [ ] **Step 3: Controller**

Add imports `use App\Models\Branch;`, `use Illuminate\Support\Arr;`, `use Illuminate\Support\Facades\DB;`.

`index()`:

```php
    public function index(): Response
    {
        // withCount so the page can hide "delete" on a type in use.
        $types = DocumentType::withCount('playerDocuments')->with('branches:id')->ordered()->get();
        $types->each(fn (DocumentType $type) => $type
            ->setAttribute('branch_ids', $type->branches->pluck('id')->map(fn ($id) => (int) $id)->values()->all())
            ->unsetRelation('branches'));

        // Records per type and branch, so unticking a branch can say how many
        // records stop counting (they are kept).
        $records = [];
        foreach (DB::table('player_documents')
            ->join('branch_player', 'branch_player.id', '=', 'player_documents.branch_registration_id')
            ->groupBy('player_documents.document_type_id', 'branch_player.branch_id')
            ->selectRaw('player_documents.document_type_id as type_id, branch_player.branch_id as branch_id, COUNT(*) as n')
            ->get() as $row) {
            $records[(int) $row->type_id][(int) $row->branch_id] = (int) $row->n;
        }

        return Inertia::render('Settings/DocumentTypes', [
            'documentTypes' => $types,
            'branches' => Branch::query()->orderBy('name')->get()
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name])->values(),
            'recordsByBranch' => (object) $records,
        ]);
    }
```

`store()`:

```php
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $branchIds = Arr::pull($data, 'branch_ids');

        DB::transaction(function () use ($data, $branchIds) {
            $type = DocumentType::create([...$data, 'code' => $this->uniqueCode($data)]);
            $type->branches()->sync($branchIds ?? []);
        });

        return back()->with('success', 'flash.document_type_created');
    }
```

`update()`:

```php
    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        // The code is deliberately not editable: it is how the app recognises a
        // type (the Photo rule keys off it). Records point at the id, so a
        // rename or a rule change never breaks them.
        $data = $this->validated($request, $documentType);
        $branchIds = Arr::pull($data, 'branch_ids');

        DB::transaction(function () use ($documentType, $data, $branchIds) {
            $documentType->update($data);
            // Left out (the active toggle) = the ticks stay as they are.
            if ($branchIds !== null) {
                $documentType->branches()->sync($branchIds);
            }
        });

        return back()->with('success', 'flash.document_type_updated');
    }
```

In `validated()` add rules:

```php
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
```

and after the copies line:

```php
        if (array_key_exists('branch_ids', $data)) {
            $data['branch_ids'] = array_map('intval', $data['branch_ids'] ?? []);
        }
```

Note: an empty array posted as JSON arrives as `[]` and passes `array`; Inertia `put` sends JSON, so `branch_ids: []` is present (not dropped).

- [ ] **Step 4: Run**

Run: `php artisan test --filter="DocumentTypeSettingsTest|PerListPermissionsTest|DocumentsPermissionTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentTypeController.php tests/Feature/DocumentTypeSettingsTest.php
git commit -m "feat(documents): document types settings save which branches require each type

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Document Types settings — page

**Files:**
- Modify: `resources/js/Pages/Settings/DocumentTypes.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json`

**Interfaces:**
- Consumes props from Task 6: `branches`, `recordsByBranch`, `documentTypes[].branch_ids`.

- [ ] **Step 1: i18n keys (insert-only, after the `"doc_copies_count"` line in each file)**

en:
```json
    "doc_branches": "Branches",
    "doc_branches_hint": "Players registered in the ticked branches must provide this document.",
    "doc_no_branch": "No branch",
    "doc_branches_without_documents": "These branches require no documents: {names}",
    "doc_untick_warning": "{count} records exist in {branch} for this type. They will stop counting but stay viewable.",
    "doc_copies_raise_hint": "Raising the number makes earlier receipts with fewer copies incomplete.",
```
fr:
```json
    "doc_branches": "Branches",
    "doc_branches_hint": "Les joueurs inscrits dans les branches cochées doivent fournir ce document.",
    "doc_no_branch": "Aucune branche",
    "doc_branches_without_documents": "Ces branches n'exigent aucun document : {names}",
    "doc_untick_warning": "{count} enregistrements existent dans {branch} pour ce type. Ils ne compteront plus mais resteront consultables.",
    "doc_copies_raise_hint": "Augmenter ce nombre rend incomplètes les réceptions antérieures avec moins de copies.",
```
ar:
```json
    "doc_branches": "الفروع",
    "doc_branches_hint": "يجب على اللاعبين المسجلين في الفروع المحددة تقديم هذه الوثيقة.",
    "doc_no_branch": "بدون فرع",
    "doc_branches_without_documents": "هذه الفروع لا تشترط أي وثيقة: {names}",
    "doc_untick_warning": "توجد {count} سجلات في {branch} لهذا النوع. لن تُحتسب بعد الآن لكنها تبقى قابلة للاطلاع.",
    "doc_copies_raise_hint": "رفع العدد يجعل الاستلامات السابقة ذات النسخ الأقل ناقصة.",
```

- [ ] **Step 2: Script changes**

Replace `defineProps({ documentTypes: ... })` with:

```js
const props = defineProps({
    documentTypes: { type: Array, default: () => [] },
    // [{ id, name }]
    branches: { type: Array, default: () => [] },
    // { [typeId]: { [branchId]: count } }: records that stop counting when a branch is unticked.
    recordsByBranch: { type: Object, default: () => ({}) },
});
```

In `FIELDS` add `branch_ids: [],` after `is_active: true,`.

Replace `payloadOf`:

```js
function payloadOf(type) {
    return Object.fromEntries(Object.keys(FIELDS).map((key) => {
        if (key === 'max_age') return [key, type.max_age ?? ''];
        if (key === 'branch_ids') return [key, [...(type.branch_ids ?? [])]];
        return [key, type[key] ?? FIELDS[key]];
    }));
}
```

`openCreate()`: after `Object.assign(form, payloadOf(FIELDS));` add:

```js
    // A new type starts required in every branch (owner, 2026-11-07).
    form.branch_ids = props.branches.map((branch) => branch.id);
```

Add below `validityLabels`:

```js
const branchName = (id) => props.branches.find((branch) => branch.id === id)?.name ?? `#${id}`;

// Branches no active type is ticked for: a new branch starts with none.
const branchesWithoutDocuments = computed(() => props.branches.filter(
    (branch) => !props.documentTypes.some((type) => type.is_active && (type.branch_ids ?? []).includes(branch.id)),
));

// Unticking a branch that holds records: say how many stop counting before saving.
const untickWarning = ref('');

function untickedWithRecords() {
    const type = props.documentTypes.find((row) => row.id === editing.value);
    if (!type) return [];
    const counts = props.recordsByBranch[type.id] ?? {};
    return (type.branch_ids ?? [])
        .filter((id) => !form.branch_ids.includes(id) && (counts[id] ?? 0) > 0)
        .map((id) => t('doc_untick_warning', { count: counts[id], branch: branchName(id) }));
}
```

Replace `save()`:

```js
function save(confirmed = false) {
    if (editing.value && !confirmed) {
        const warnings = untickedWithRecords();
        if (warnings.length) {
            untickWarning.value = warnings.join(' ');
            return;
        }
    }
    untickWarning.value = '';

    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };

    if (editing.value) {
        form.put(route('document-types.update', editing.value), options);
    } else {
        form.post(route('document-types.store'), options);
    }
}
```

The form's `@submit.prevent="save"` passes the event as the first argument; change it to `@submit.prevent="save()"`.

`toggleActive` stays as is (it sends the type's own `branch_ids`, so nothing changes).

- [ ] **Step 3: Template changes**

Banner — first child inside `<div class="mx-auto max-w-5xl">`, and add `space-y-4` to that div's class:

```html
            <p v-if="branchesWithoutDocuments.length" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                {{ t('doc_branches_without_documents', { names: branchesWithoutDocuments.map((branch) => branch.name).join('، ') }) }}
            </p>
```

Use `', '` as the separator instead of `'، '` when the locale is not Arabic: `useI18n()` gives `locale`; write `branchesWithoutDocuments.map((branch) => branch.name).join(locale === 'ar' ? '، ' : ', ')` with `const { t, locale } = useI18n();` (use `locale.value` in script, `locale` auto-unwraps in template).

Table header — after the name `<th>`:

```html
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_branches') }}</th>
```

Row cell — after the name `<td>`:

```html
                            <td class="px-4 py-3">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <Badge v-for="id in type.branch_ids" :key="id" :label="branchName(id)" color="primary" />
                                    <Badge v-if="!type.branch_ids?.length" :label="t('doc_no_branch')" color="slate" />
                                </div>
                            </td>
```

Empty-row `colspan="7"` → `colspan="8"`.

Form — after the copies `<div>` add under the copies input (inside the same div, after `InputError`):

```html
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_copies_raise_hint') }}</p>
```

Form — after the `doc_max_age_hint` paragraph, a branches fieldset spanning both columns:

```html
                    <fieldset v-if="branches.length" class="sm:col-span-2">
                        <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('doc_branches') }}</legend>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('doc_branches_hint') }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label v-for="branch in branches" :key="branch.id" class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <input v-model="form.branch_ids" :value="branch.id" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                {{ branch.name }}
                            </label>
                        </div>
                        <InputError :message="form.errors.branch_ids" class="mt-1" />
                    </fieldset>
```

Confirm modal — next to the existing delete `ConfirmModal`:

```html
        <ConfirmModal :show="!!untickWarning" :message="untickWarning" @confirm="save(true)" @cancel="untickWarning = ''" />
```

(Check `ConfirmModal`'s z-index renders above the open `Modal`; if it renders behind, close nothing — the existing Modal component stacks in DOM order, and ConfirmModal is placed after it, so it should sit on top. Verify in the browser in Task 9.)

- [ ] **Step 4: Check and build**

Run: `npm run i18n:check && npm run build`
Expected: both succeed, no missing keys.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Settings/DocumentTypes.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(documents): tick branches per document type in settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Player documents card — copies and retired records

**Files:**
- Modify: `resources/js/Components/PlayerDocumentsCard.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/Registrations/PlayerPageDocumentsTest.php`

- [ ] **Step 1: Test** (append to `PlayerPageDocumentsTest`; its `setUp()` creates the `med` type — after Task 2 Step 6 it also calls `requireDocumentsEverywhere()`)

```php
    #[Test]
    public function the_page_sends_retired_records_and_copies(): void
    {
        $medical = DocumentType::where('code', 'med')->firstOrFail();
        $medical->update(['copies' => 3]);
        $medical->branches()->sync([$this->foot->id]);
        $player = $this->bothActive();
        foreach ([$this->foot, $this->swim] as $branch) {
            \App\Models\PlayerDocument::create([
                'player_id' => $player->id,
                'branch_registration_id' => $player->registrationIn($branch->id)->id,
                'document_type_id' => $medical->id,
                'state' => 'received',
                'received_at' => '2026-09-01',
                'copies_received' => 2,
            ]);
        }

        // Main first: Football (index 0), then Swimming (index 1).
        $this->actingAs($this->admin)->get(route('players.show', $player))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.tabs.0.branch_name', 'Football')
                ->where('documents.tabs.0.items.0.state', 'incomplete')
                ->where('documents.tabs.0.items.0.document.copies_received', 2)
                ->where('documents.tabs.0.missing_count', 1)
                ->has('documents.tabs.0.retired', 0)
                ->where('documents.tabs.1.branch_name', 'Swimming')
                ->has('documents.tabs.1.items', 0)
                ->where('documents.tabs.1.retired.0.type.id', $medical->id)
                ->where('documents.tabs.1.missing_count', 0));
    }
```

- [ ] **Step 2: Run** `php artisan test --filter=PlayerPageDocumentsTest` — the new test should PASS already (backend done in Tasks 2–3). If it fails, fix the backend, not the test.

- [ ] **Step 3: i18n keys (insert-only, after the `"doc_state_incomplete"` line in each file)**

en:
```json
    "doc_copies_received": "Copies received",
    "doc_copies_of": "{received} / {total} copies",
    "doc_retired_title": "No longer required in this branch",
```
fr:
```json
    "doc_copies_received": "Copies reçues",
    "doc_copies_of": "{received} / {total} copies",
    "doc_retired_title": "N'est plus exigé dans cette branche",
```
ar:
```json
    "doc_copies_received": "النسخ المستلمة",
    "doc_copies_of": "{received} / {total} نسخ",
    "doc_retired_title": "لم تعد مطلوبة في هذا الفرع",
```

- [ ] **Step 4: Script changes**

`STATE_COLORS`: add `incomplete: 'amber',`. `stateLabels`: add `incomplete: t('doc_state_incomplete'),`.

After `const items = ...`:

```js
// Records of types this branch no longer requires: kept, shown greyed, never counted.
const retired = computed(() => props.checklist?.retired ?? []);
```

`receiveForm`: add `copies_received: null` to the initial object. In `openReceive(item)` after `receiveForm.files = [];`:

```js
    // Prefilled with the full number: a normal receive stays one click.
    receiveForm.copies_received = item.type.copies;
```

- [ ] **Step 5: Template changes**

In the item's type-badges `<p>`, after the copies span:

```html
                            <span v-if="item.state === 'incomplete'" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-200">{{ t('doc_copies_of', { received: item.document.copies_received, total: item.type.copies }) }}</span>
```

In the receive modal, after the validity block and before notes:

```html
                <div v-if="receiving.type.copies > 1">
                    <InputLabel :value="t('doc_copies_received')" />
                    <input v-model.number="receiveForm.copies_received" type="number" min="1" :max="receiving.type.copies" required class="mt-1 w-full rounded-lg border-slate-300 text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    <InputError :message="receiveForm.errors.copies_received" class="mt-1" />
                </div>
```

After the main `</ul>` (the items list), before the modals:

```html
        <details v-if="retired.length" class="border-t border-slate-100 dark:border-slate-800">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-500 dark:text-slate-400">
                {{ t('doc_retired_title') }} ({{ retired.length }})
            </summary>
            <ul class="divide-y divide-slate-100 opacity-60 dark:divide-slate-800">
                <li v-for="item in retired" :key="`retired-${item.type.id}`" class="px-5 py-3">
                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ item.type.name }}</p>
                    <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500 dark:text-slate-400">
                        <span v-if="item.document?.state === 'exempt'">{{ t('doc_reason_exempt') }}</span>
                        <span v-if="item.document?.received_at">{{ t('doc_received_on') }} {{ formatDate(item.document.received_at) }}</span>
                        <span v-if="item.document?.valid_until">{{ t('doc_valid_until') }} {{ formatDate(item.document.valid_until) }}</span>
                    </p>
                    <ul v-if="item.document?.files?.length" class="mt-2 space-y-1">
                        <li v-for="file in item.document.files" :key="file.id" class="flex flex-wrap items-center gap-2 text-xs">
                            <Icon name="document" class="text-slate-400" />
                            <a :href="fileUrl(file)" target="_blank" rel="noopener" class="min-w-0 max-w-xs truncate text-primary-600 hover:underline dark:text-primary-300">{{ file.original_name }}</a>
                            <IconButton :href="downloadUrl(file)" external icon="download" :label="t('doc_download')" plain size="sm" />
                        </li>
                    </ul>
                </li>
            </ul>
        </details>
```

No receive/exempt/renew/delete actions in this section.

- [ ] **Step 6: Check, build, test**

Run: `npm run i18n:check && npm run build && php artisan test --filter=PlayerPageDocumentsTest`
Expected: all succeed.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/PlayerDocumentsCard.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/PlayerPageDocumentsTest.php
git commit -m "feat(documents): copies received on the player page; retired records greyed

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Full verification

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: all green. Report the count.

- [ ] **Step 2: MySQL/MariaDB run (private instance, never production)**

Run the document tests against the private MariaDB as in earlier batches (env override, see memory "MySQL dashboard pipes"):
`php artisan test --filter="Document|BranchDocument|PlayerFlags|PlayerReports"` with the MariaDB env vars.
Expected: green. If no private instance is reachable, say so in the hand-off; do not skip silently.

- [ ] **Step 3: Pint**

Run: `vendor/bin/pint --dirty`
Commit any formatting changes separately.

- [ ] **Step 4: Browser QA (test server in the worktree, port 2027; copy players/branches media per memory "Worktree test server media")**

Check, in Arabic and French, light and dark, desktop and 320 px:
1. Settings → Document types: branches column, "No branch" chip, banner after creating a new branch, checkbox list, untick-with-records confirm appears above the edit modal, copies hint.
2. Player in two branches: each tab lists only its branch's types; retired section collapsed with download working.
3. Receive a 3-copy type with 2 → amber "2 / 3 copies", missing badge counts it; renew to 3 → clears.
4. Players list with one branch selected: "missing document X" options limited to that branch.

- [ ] **Step 5: Hand-off note**

Deploy needs `php artisan migrate` (two migrations). Desktop build after merge. Merge to main and push only on owner approval, after `git fetch && git log origin/main..main` check.
