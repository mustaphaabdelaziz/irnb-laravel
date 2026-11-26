# Duplicate Players — Find and Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A «Doublons potentiels» page (reached from the Players list) lists scored pairs of possible duplicate players, filterable by status, minimum score and criteria chips; each row can be dismissed ("not duplicate", remembered, undoable) or merged through a side-by-side screen that moves every linked record to the kept player with smart clash rules, after an automatic backup.

**Architecture:** Pairs are computed live (`DuplicateScorer` pure scoring + `DuplicateFinder` bucketed candidates); only decisions are stored (`player_duplicate_decisions`). Merging is split into read-only rule classes (`FieldMerge` for player columns, `MergeClashes` for once-only records, `MergePreview` for counts) used by both the preview page and `PlayerMerger`, which applies everything in one DB transaction with query-builder updates (no model events). `PreMergeBackup` runs before. New extra role action `players → merge`.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), PHPUnit feature tests (`#[Test]`, `RefreshDatabase`), SQLite (dev/desktop) + MySQL compatible SQL.

Spec: `docs/superpowers/specs/2026-11-26-duplicate-players-design.md`.

## Global Constraints

- **Worktree:** all work happens in `D:\irnb-duplicates` on branch `feat/duplicate-players`, created from `main`: `git worktree add -b feat/duplicate-players D:/irnb-duplicates main`. Copy `vendor` (a real copy, no junctions): `cp -r D:/irnb-laravel/vendor D:/irnb-duplicates/vendor`; copy `node_modules` the same way: `cp -r D:/irnb-laravel/node_modules D:/irnb-duplicates/node_modules` (no `npm ci`: the app works offline); copy `.env`: `cp D:/irnb-laravel/.env D:/irnb-duplicates/.env`; then once in the worktree: `npm run build`. Never run tests in `D:\irnb-laravel`. If `bootstrap/cache/config.php` exists in the worktree, run `php artisan config:clear` first.
- **Tests:** single file `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/X.php` (from the worktree root); full suite `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`. Never run two test processes at once. Every new feature test class calls `$this->withoutVite()` in `setUp()`.
- **Staging:** stage explicit paths only (`git add <paths>`), never `git add -A` / `git add .` — the repo root holds untracked private files.
- **Commits:** conventional message, body ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **SQL:** portable SQLite + MySQL — no `strftime`, no `||` concatenation, no SQLite-only functions.
- **Merging moves rows with `DB::table(...)->update()` / `->delete()`** — never model saves on child rows (registration / document hooks would fire). Only the keeper's own column update uses `$keeper->forceFill(...)->save()`.
- **Migrations:** dated `2026_11_26_3000xx` (other sessions already used `2026_11_26_1000xx`).
- **i18n:** ar / fr / en, flat dotted keys, added only through `node scripts/i18n-add.mjs <keys.json>` (insert-only: never re-run it on an existing key, never re-sort catalogs). Keys file at a scratch path, e.g. `C:\Users\MUSTAPHA\AppData\Local\Temp\dup-tN-keys.json`. Feature keys use the prefix `dup.` (`merge` is already a message, so `merge.x` would break). No key may equal the dotted prefix of another key (that is why the column header is `dup.col_status`, not `dup.status`). Vue uses `t()` directly, never `te()`. `npm run i18n:check` must pass after every task that adds keys; every `flash.*` key emitted by PHP must exist in all three catalogs.
- **Activity log properties:** ids and counts only — never names, phones or free text.
- **Permissions:** route-name derived (`config/permissions.php`). New extra action `merge` on module `players` (`Role::EXTRA_ACTIONS`). Every `players.duplicates.*` route maps to `['players', 'merge']` through `overrides`. Grant migration: system roles get `merge` added to `players`; other roles get nothing (owner: new rights start empty).
- **UI:** action buttons are `IconButton`; dark mode on every colored class (`dark:text-primary-300`, never `-400`); layouts work at 320 px (`grid-cols-1` base, tables in `overflow-x-auto`); no CDN / remote resources; never name an Inertia `useForm` field `progress`.
- **Names:** always `Player::fullname` (select `Player::NAME_COLUMNS` when narrowing columns).
- **Test data:** fake names only ("Test", Arabic demo names like فاطمة بن علي are fine).

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/Role.php` (modify) | `EXTRA_ACTIONS['players'] = ['merge']` |
| `database/migrations/2026_11_26_300000_grant_player_merge_permission.php` | system roles get `players.merge` |
| `database/migrations/2026_11_26_300001_create_player_duplicate_decisions_table.php` | decisions table |
| `app/Models/PlayerDuplicateDecision.php` | constants, casts, `orderedPair`, `snapshot` |
| `app/Services/Player/Duplicates/PlayerCards.php` | batched display cards (name, membership IDs, photo, archived) |
| `app/Services/Player/Duplicates/DuplicateScorer.php` | pure: profile, phone normalising, score, criteria |
| `app/Services/Player/Duplicates/DuplicateFinder.php` | candidates by bucket, pending pairs, score one pair |
| `app/Http/Controllers/PlayerDuplicateController.php` | list page, dismiss, undo |
| `resources/js/Pages/Players/Duplicates.vue` | list page |
| `resources/js/Pages/Players/Index.vue` (modify) | "Find duplicates" header action |
| `resources/js/Components/Icon.vue` (modify) | `merge` icon |
| `app/Services/Player/Merge/FieldMerge.php` | player-column diff + resolve |
| `app/Services/Player/Merge/MergeClashes.php` | once-only records + winners |
| `app/Services/Player/Merge/MergePreview.php` | counts of rows that move |
| `app/Services/Player/Merge/PlayerMerger.php` | the merge transaction |
| `app/Services/Player/Merge/PreMergeBackup.php` | backup before merge |
| `app/Http/Controllers/PlayerMergeController.php` | merge page + confirm |
| `resources/js/Pages/Players/MergeDuplicates.vue` | merge page |
| `app/Services/Activity/ActivityAction.php` (modify) | `PLAYERS_MERGED` |
| `config/permissions.php`, `routes/web.php` (modify) | routes + overrides |
| `tests/Support/BuildsDuplicatePlayers.php` | shared fixtures |
| `resources/help/{ar,fr,en}/players/merge-duplicates.md`, `resources/help/topics.php` | Help Center article |

---

### Task 1: `players → merge` right + grant migration

**Files:**
- Modify: `app/Models/Role.php` (`EXTRA_ACTIONS`)
- Create: `database/migrations/2026_11_26_300000_grant_player_merge_permission.php`
- Test: `tests/Feature/PlayerMergeRightTest.php`

**Interfaces:**
- Produces: `Role::actionsFor('players') === ['view','add','edit','delete','merge']`; role editor label key `perm_merge`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerMergeRightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function players_has_the_extra_merge_action(): void
    {
        $this->assertSame(['view', 'add', 'edit', 'delete', 'merge'], Role::actionsFor('players'));
        $this->assertContains('merge', Role::allPermissions()['players']);
    }

    #[Test]
    public function the_grant_migration_adds_merge_to_system_roles_only_and_runs_twice_safely(): void
    {
        $system = Role::factory()->create(['is_system' => true, 'permissions' => ['players' => ['view', 'edit']]]);
        $bare = Role::factory()->create(['is_system' => true, 'permissions' => ['documents' => ['view']]]);
        $other = Role::factory()->create(['is_system' => false, 'permissions' => ['players' => ['view']]]);
        $migration = require database_path('migrations/2026_11_26_300000_grant_player_merge_permission.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['view', 'edit', 'merge'], $system->fresh()->permissions['players']);
        $this->assertSame(['view', 'add', 'edit', 'delete', 'merge'], $bare->fresh()->permissions['players']);
        $this->assertSame(['view'], $other->fresh()->permissions['players']);

        $migration->down();
        $this->assertSame(['view', 'edit'], $system->fresh()->permissions['players']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergeRightTest.php`
Expected: FAIL (`merge` missing; migration file not found).

- [ ] **Step 3: Implement**

In `app/Models/Role.php` replace the `EXTRA_ACTIONS` constant and extend its doc comment:

```php
    /**
     * Actions only some modules have, after the canonical four. Approving a
     * payment order is a separate right from editing it (owner, 2026-11-25):
     * the accountant prepares, the president or treasurer approves. Merging
     * duplicate players deletes one of them, so it is its own right too
     * (owner, 2026-11-26).
     */
    public const EXTRA_ACTIONS = [
        'payment_orders' => ['approve'],
        'players' => ['merge'],
    ];
```

Create `database/migrations/2026_11_26_300000_grant_player_merge_permission.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merging duplicate players. Owner's rule for new rights: they start empty
 * except the system roles (full access). A migration because the desktop
 * build never seeds. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $players = $permissions['players'] ?? ['view', 'add', 'edit', 'delete'];
            if (! in_array('merge', $players, true)) {
                $players[] = 'merge';
            }
            $permissions['players'] = array_values($players);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            if (! isset($permissions['players'])) {
                continue;
            }
            $permissions['players'] = array_values(array_diff($permissions['players'], ['merge']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
```

Write `C:\Users\MUSTAPHA\AppData\Local\Temp\dup-t1-keys.json`:

```json
{
    "perm_merge": { "ar": "دمج المكررين", "fr": "Fusionner les doublons", "en": "Merge duplicates" }
}
```

Run: `node scripts/i18n-add.mjs C:/Users/MUSTAPHA/AppData/Local/Temp/dup-t1-keys.json` then `npm run i18n:check`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergeRightTest.php`
Expected: PASS. Then run the role/permission tests that might assert the players action list: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit --filter "Role|Permission"` — expected PASS; if one asserts `allPermissions()['players']` equals the canonical four, update that assertion to include `merge` (the spec makes it an extra action).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Role.php database/migrations/2026_11_26_300000_grant_player_merge_permission.php tests/Feature/PlayerMergeRightTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(players): merge duplicates role right"
```

---

### Task 2: Decisions table, model, player cards, test fixtures

**Files:**
- Create: `database/migrations/2026_11_26_300001_create_player_duplicate_decisions_table.php`
- Create: `app/Models/PlayerDuplicateDecision.php`
- Create: `app/Services/Player/Duplicates/PlayerCards.php`
- Create: `tests/Support/BuildsDuplicatePlayers.php`
- Test: `tests/Feature/PlayerDuplicateDecisionTest.php`

**Interfaces:**
- Produces:
  - `PlayerDuplicateDecision::DISMISSED = 'dismissed'`, `::MERGED = 'merged'`
  - `PlayerDuplicateDecision::orderedPair(int $x, int $y): array{0:int,1:int}` (smaller first)
  - `PlayerDuplicateDecision::snapshot(Player $p): array` (same shape as a card)
  - `PlayerCards::for(array $ids): Collection<int, array{id:int,name:string,membership_ids:list<string>,birthdate:?string,picture_url:?string,archived:bool}>` keyed by id
  - Trait `Tests\Support\BuildsDuplicatePlayers`: `player(array $attrs = []): Player`, `branch(string $name = 'Football'): int`, `registration(Player $p, int $branchId, array $extra = []): int`, `category(int $branchId): int`, `trainingSession(int $categoryId, string $date = '2026-10-01'): int`, `documentType(): int`

- [ ] **Step 1: Write the fixture trait**

`tests/Support/BuildsDuplicatePlayers.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/** Small rows for duplicate / merge tests, inserted with the query builder (no hooks). */
trait BuildsDuplicatePlayers
{
    private int $dupSeq = 0;

    protected function player(array $attributes = []): Player
    {
        $this->dupSeq++;

        return Player::create(array_merge([
            'membership_id' => 'T'.str_pad((string) $this->dupSeq, 6, '0', STR_PAD_LEFT),
            'firstname' => 'Test'.$this->dupSeq,
        ], $attributes))->fresh();
    }

    protected function branch(string $name = 'Football'): int
    {
        $this->dupSeq++;

        return DB::table('branches')->insertGetId([
            'name' => $name, 'code' => 'B'.$this->dupSeq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function registration(Player $player, int $branchId, array $extra = []): int
    {
        return DB::table('branch_player')->insertGetId(array_merge([
            'branch_id' => $branchId, 'player_id' => $player->id,
            'membership_id' => 'M'.$player->id.'-'.$branchId,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    protected function category(int $branchId): int
    {
        $this->dupSeq++;

        return DB::table('categories')->insertGetId([
            'name' => 'U13-'.$this->dupSeq, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function trainingSession(int $categoryId, string $date = '2026-10-01'): int
    {
        return DB::table('training_sessions')->insertGetId([
            'category_id' => $categoryId, 'date' => $date, 'start_time' => '17:00', 'end_time' => '18:30',
            'kind' => 'training', 'state' => 'held', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function documentType(): int
    {
        $this->dupSeq++;

        return DB::table('document_types')->insertGetId([
            'code' => 'doc'.$this->dupSeq, 'name' => 'Photo '.$this->dupSeq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/PlayerDuplicateDecisionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerDuplicateDecisionTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function ordered_pair_puts_the_smaller_id_first(): void
    {
        $this->assertSame([3, 9], PlayerDuplicateDecision::orderedPair(9, 3));
        $this->assertSame([3, 9], PlayerDuplicateDecision::orderedPair(3, 9));
    }

    #[Test]
    public function cards_show_full_name_branch_ids_and_archived(): void
    {
        $player = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'archived' => true]);
        $this->registration($player, $this->branch(), ['membership_id' => 'FB-2024-00012']);

        $card = PlayerCards::for([$player->id])[$player->id];

        $this->assertSame($player->id, $card['id']);
        $this->assertSame($player->fullname, $card['name']);
        $this->assertSame(['FB-2024-00012'], $card['membership_ids']);
        $this->assertSame('2012-03-04', $card['birthdate']);
        $this->assertTrue($card['archived']);
    }

    #[Test]
    public function a_card_without_registrations_falls_back_to_the_player_membership_id(): void
    {
        $player = $this->player(['membership_id' => '2024000007']);

        $this->assertSame(['2024000007'], PlayerCards::for([$player->id])[$player->id]['membership_ids']);
    }

    #[Test]
    public function deleting_a_player_blanks_its_side_and_keeps_the_snapshot(): void
    {
        $a = $this->player();
        $b = $this->player();
        $decision = PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => $b->id, 'status' => PlayerDuplicateDecision::MERGED,
            'player_a_snapshot' => PlayerDuplicateDecision::snapshot($a),
            'player_b_snapshot' => PlayerDuplicateDecision::snapshot($b),
            'kept_player_id' => $a->id, 'reasons' => ['same_name'], 'score' => 80, 'decided_at' => now(),
        ]);

        DB::table('players')->where('id', $b->id)->delete();

        $decision->refresh();
        $this->assertNull($decision->player_b_id);
        $this->assertSame($b->fullname, $decision->player_b_snapshot['name']);
        $this->assertSame(['same_name'], $decision->reasons);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerDuplicateDecisionTest.php`
Expected: FAIL (class `PlayerDuplicateDecision` not found).

- [ ] **Step 4: Implement**

`database/migrations/2026_11_26_300001_create_player_duplicate_decisions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions about possible duplicate players: "not duplicate" (dismissed,
 * remembered forever) and "merged". Pairs themselves are computed live.
 * A deleted player blanks its side; the snapshot keeps the row readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_duplicate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_a_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('player_b_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->json('player_a_snapshot');
            $table->json('player_b_snapshot');
            $table->foreignId('kept_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->json('reasons')->nullable();
            $table->smallInteger('score')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['player_a_id', 'player_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_duplicate_decisions');
    }
};
```

`app/Models/PlayerDuplicateDecision.php`:

```php
<?php

namespace App\Models;

use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Database\Eloquent\Model;

/**
 * A decision about a possible duplicate pair (player_a_id < player_b_id):
 * dismissed ("not duplicate", owner 2026-11-26: remembered forever, undoable)
 * or merged. Snapshots keep merged rows readable after a player is deleted.
 */
class PlayerDuplicateDecision extends Model
{
    public const DISMISSED = 'dismissed';

    public const MERGED = 'merged';

    protected $fillable = [
        'player_a_id', 'player_b_id', 'status', 'player_a_snapshot', 'player_b_snapshot',
        'kept_player_id', 'reasons', 'score', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'player_a_snapshot' => 'array',
            'player_b_snapshot' => 'array',
            'reasons' => 'array',
            'score' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return array{0:int, 1:int} */
    public static function orderedPair(int $x, int $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** The player's card as it is now, kept for when the player is gone. */
    public static function snapshot(Player $player): array
    {
        return PlayerCards::for([$player->id])->get($player->id) ?? [
            'id' => $player->id, 'name' => $player->fullname, 'membership_ids' => [],
            'birthdate' => null, 'picture_url' => null, 'archived' => false,
        ];
    }
}
```

`app/Services/Player/Duplicates/PlayerCards.php`:

```php
<?php

namespace App\Services\Player\Duplicates;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What the duplicates pages show of a player, loaded for many players in two queries. */
final class PlayerCards
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, array{id:int, name:string, membership_ids:list<string>, birthdate:?string, picture_url:?string, archived:bool}>
     */
    public static function for(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $memberships = DB::table('branch_player')
            ->whereIn('player_id', $ids)
            ->whereNotNull('membership_id')
            ->orderBy('branch_id')
            ->get(['player_id', 'membership_id'])
            ->groupBy('player_id');

        return Player::query()
            ->whereIn('id', $ids)
            ->get(['id', 'membership_id', 'birthdate', 'picture_url', 'archived', ...Player::NAME_COLUMNS])
            ->mapWithKeys(fn (Player $p) => [$p->id => [
                'id' => $p->id,
                'name' => $p->fullname,
                'membership_ids' => ($memberships[$p->id] ?? collect())->pluck('membership_id')->map(fn ($m) => (string) $m)->values()->all()
                    ?: array_values(array_filter([(string) $p->membership_id])),
                'birthdate' => $p->birthdate?->toDateString(),
                'picture_url' => $p->picture_url,
                'archived' => (bool) $p->archived,
            ]]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerDuplicateDecisionTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_26_300001_create_player_duplicate_decisions_table.php app/Models/PlayerDuplicateDecision.php app/Services/Player/Duplicates/PlayerCards.php tests/Support/BuildsDuplicatePlayers.php tests/Feature/PlayerDuplicateDecisionTest.php
git commit -m "feat(players): duplicate decisions table and player cards"
```

---

### Task 3: `DuplicateScorer` (pure scoring)

**Files:**
- Create: `app/Services/Player/Duplicates/DuplicateScorer.php`
- Test: `tests/Unit/DuplicateScorerTest.php`

**Interfaces:**
- Produces (all static):
  - `POINTS` (reason => points), `MIN_SCORE = 50`, `CRITERIA` (`name|birthdate|phone|father` => reasons)
  - `profile(array $row): array{id:int, first:string, last:string, father:string, birthdate:?string, gender:string, phones:list<string>}` — `$row` keys `id, firstname, lastname, father, birthdate, gender, phones` (phones: array or JSON string)
  - `phone(mixed $value): string`
  - `score(array $profileA, array $profileB): array{score:int, reasons:list<string>}`
  - `meets(array $reasons, array $criteria): bool`

- [ ] **Step 1: Write the failing test**

`tests/Unit/DuplicateScorerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Player\Duplicates\DuplicateScorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DuplicateScorerTest extends TestCase
{
    private function p(array $row): array
    {
        static $id = 0;

        return DuplicateScorer::profile(array_merge([
            'id' => ++$id, 'firstname' => null, 'lastname' => null, 'father' => null,
            'birthdate' => null, 'gender' => 'Male', 'phones' => [],
        ], $row));
    }

    #[Test]
    public function arabic_spelling_variants_are_the_same_name(): void
    {
        $result = DuplicateScorer::score(
            $this->p(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'gender' => 'Female']),
            $this->p(['firstname' => 'فاطمه', 'lastname' => 'بن على', 'gender' => 'Female']),
        );

        $this->assertSame(['same_name'], $result['reasons']);
        $this->assertSame(80, $result['score']);
    }

    #[Test]
    public function swapped_or_one_letter_off_names_are_similar(): void
    {
        $swapped = DuplicateScorer::score(
            $this->p(['firstname' => 'Said', 'lastname' => 'Badlis']),
            $this->p(['firstname' => 'Badlis', 'lastname' => 'Said']),
        );
        $typo = DuplicateScorer::score(
            $this->p(['firstname' => 'Mohamed', 'lastname' => 'Benali']),
            $this->p(['firstname' => 'Mohammed', 'lastname' => 'Benali']),
        );

        $this->assertSame(['similar_name'], $swapped['reasons']);
        $this->assertSame(['similar_name'], $typo['reasons']);
        $this->assertSame(50, $typo['score']);
    }

    #[Test]
    public function phone_formats_are_folded_before_comparing(): void
    {
        $this->assertSame('0542039525', DuplicateScorer::phone('+213 542 03 95 25'));
        $this->assertSame('0542039525', DuplicateScorer::phone('00213542039525'));
        $this->assertSame('', DuplicateScorer::phone('123'));

        $result = DuplicateScorer::score(
            $this->p(['phones' => ['+213 542 03 95 25'], 'birthdate' => '2012-03-04']),
            $this->p(['phones' => '["0542039525"]', 'birthdate' => '2012-03-04 00:00:00']),
        );

        $this->assertSame(['same_birthdate', 'same_phone'], $result['reasons']);
        $this->assertSame(120, $result['score']);
    }

    #[Test]
    public function siblings_of_different_gender_fall_below_the_floor(): void
    {
        $result = DuplicateScorer::score(
            $this->p(['firstname' => 'Ahmed', 'lastname' => 'Saidi', 'father' => 'Ali', 'gender' => 'Male', 'phones' => ['0550000001']]),
            $this->p(['firstname' => 'Sara', 'lastname' => 'Saidi', 'father' => 'Ali', 'gender' => 'Female', 'phones' => ['0550000001']]),
        );

        $this->assertSame(['same_phone', 'same_father', 'different_gender'], $result['reasons']);
        $this->assertSame(30, $result['score']);
        $this->assertLessThan(DuplicateScorer::MIN_SCORE, $result['score']);
    }

    #[Test]
    public function criteria_must_all_be_met(): void
    {
        $reasons = ['similar_name', 'same_birthdate'];

        $this->assertTrue(DuplicateScorer::meets($reasons, []));
        $this->assertTrue(DuplicateScorer::meets($reasons, ['name']));
        $this->assertTrue(DuplicateScorer::meets($reasons, ['name', 'birthdate']));
        $this->assertFalse(DuplicateScorer::meets($reasons, ['name', 'phone']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/DuplicateScorerTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Services/Player/Duplicates/DuplicateScorer.php`:

```php
<?php

namespace App\Services\Player\Duplicates;

use App\Support\NameNormalizer;

/**
 * How likely two players are one person (owner decisions 2026-11-26). Works on
 * plain profiles so the finder can score thousands of pairs cheaply.
 */
final class DuplicateScorer
{
    public const POINTS = [
        'same_name' => 80,
        'similar_name' => 50,
        'same_birthdate' => 60,
        'same_phone' => 60,
        'same_father' => 30,
        'different_gender' => -60,
    ];

    /** A pair below this is never listed. */
    public const MIN_SCORE = 50;

    /** Criterion chip => reasons that satisfy it. */
    public const CRITERIA = [
        'name' => ['same_name', 'similar_name'],
        'birthdate' => ['same_birthdate'],
        'phone' => ['same_phone'],
        'father' => ['same_father'],
    ];

    /**
     * @param  array<string, mixed>  $row  id, firstname, lastname, father, birthdate, gender, phones
     * @return array{id:int, first:string, last:string, father:string, birthdate:?string, gender:string, phones:list<string>}
     */
    public static function profile(array $row): array
    {
        $phones = $row['phones'] ?? [];
        if (is_string($phones)) {
            $phones = json_decode($phones, true) ?: [];
        }
        $birthdate = $row['birthdate'] ?? null;

        return [
            'id' => (int) $row['id'],
            'first' => NameNormalizer::key($row['firstname'] ?? null),
            'last' => NameNormalizer::key($row['lastname'] ?? null),
            'father' => NameNormalizer::key($row['father'] ?? null),
            'birthdate' => $birthdate ? substr((string) $birthdate, 0, 10) : null,
            'gender' => mb_strtolower(trim((string) ($row['gender'] ?? ''))),
            'phones' => array_values(array_unique(array_filter(array_map(
                self::phone(...),
                is_array($phones) ? $phones : [],
            )))),
        ];
    }

    /** Digits only, Algerian international prefix folded to the local 0; too short to mean anything → ''. */
    public static function phone(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (str_starts_with($digits, '00213')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '213') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }

        return strlen($digits) >= 6 ? $digits : '';
    }

    /** @return array{score:int, reasons:list<string>} */
    public static function score(array $a, array $b): array
    {
        $reasons = [];

        if ($a['first'] !== '' && $a['last'] !== '' && $a['first'] === $b['first'] && $a['last'] === $b['last']) {
            $reasons[] = 'same_name';
        } elseif (self::similarName($a, $b)) {
            $reasons[] = 'similar_name';
        }
        if ($a['birthdate'] !== null && $a['birthdate'] === $b['birthdate']) {
            $reasons[] = 'same_birthdate';
        }
        if (array_intersect($a['phones'], $b['phones']) !== []) {
            $reasons[] = 'same_phone';
        }
        if ($a['father'] !== '' && $a['father'] === $b['father']) {
            $reasons[] = 'same_father';
        }
        if ($a['gender'] !== '' && $b['gender'] !== '' && $a['gender'] !== $b['gender']) {
            $reasons[] = 'different_gender';
        }

        return [
            'score' => array_sum(array_map(fn (string $reason) => self::POINTS[$reason], $reasons)),
            'reasons' => $reasons,
        ];
    }

    /** True when every ticked criterion is met by one of its reasons. */
    public static function meets(array $reasons, array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if (array_intersect(self::CRITERIA[$criterion] ?? [], $reasons) === []) {
                return false;
            }
        }

        return true;
    }

    private static function similarName(array $a, array $b): bool
    {
        if ($a['first'] === '' || $a['last'] === '' || $b['first'] === '' || $b['last'] === '') {
            return false;
        }
        if ($a['first'] === $b['last'] && $a['last'] === $b['first']) {
            return true;
        }
        $x = $a['first'].$a['last'];
        $y = $b['first'].$b['last'];

        return mb_strlen($x) > 4 && mb_strlen($y) > 4 && self::distance($x, $y) <= 2;
    }

    /** Levenshtein over characters: PHP's levenshtein() counts bytes, and Arabic letters are two. */
    private static function distance(string $x, string $y): int
    {
        $a = mb_str_split($x);
        $b = mb_str_split($y);
        if (abs(count($a) - count($b)) > 2) {
            return 3;
        }

        $previous = range(0, count($b));
        foreach ($a as $i => $charA) {
            $current = [$i + 1];
            foreach ($b as $j => $charB) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($charA === $charB ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[count($b)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/DuplicateScorerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/Duplicates/DuplicateScorer.php tests/Unit/DuplicateScorerTest.php
git commit -m "feat(players): duplicate pair scorer"
```

---

### Task 4: `DuplicateFinder` (live candidate pairs)

**Files:**
- Create: `app/Services/Player/Duplicates/DuplicateFinder.php`
- Test: `tests/Feature/DuplicateFinderTest.php`

**Interfaces:**
- Consumes: `DuplicateScorer` (Task 3), `PlayerDuplicateDecision` (Task 2).
- Produces:
  - `DuplicateFinder::pending(array $criteria = [], int $minScore = 0): Collection<int, array{a:int, b:int, score:int, reasons:list<string>}>` — sorted score desc, then a, b asc; `a < b`
  - `DuplicateFinder::scorePair(int $a, int $b): array{score:int, reasons:list<string>}`

- [ ] **Step 1: Write the failing test**

`tests/Feature/DuplicateFinderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\DuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class DuplicateFinderTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function it_lists_scored_pairs_strongest_first_and_skips_strangers(): void
    {
        $a = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'phones' => ['0542039525'], 'gender' => 'Female']);
        $b = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'phones' => ['+213542039525'], 'gender' => 'Female']);
        $c = $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $d = $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2009-05-05']);
        $this->player(['firstname' => 'Nobody', 'lastname' => 'Else', 'birthdate' => '2001-01-01']);

        $pairs = app(DuplicateFinder::class)->pending();

        $this->assertCount(2, $pairs);
        $this->assertSame([$a->id, $b->id, 200], [$pairs[0]['a'], $pairs[0]['b'], $pairs[0]['score']]);
        $this->assertSame(['same_name', 'same_birthdate', 'same_phone'], $pairs[0]['reasons']);
        $this->assertSame([$c->id, $d->id, 80], [$pairs[1]['a'], $pairs[1]['b'], $pairs[1]['score']]);
    }

    #[Test]
    public function criteria_and_min_score_narrow_the_list(): void
    {
        $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);

        $finder = app(DuplicateFinder::class);

        $this->assertCount(2, $finder->pending());
        $this->assertCount(1, $finder->pending(['name', 'birthdate']));
        $this->assertCount(1, $finder->pending([], 100));
        $this->assertCount(0, $finder->pending(['phone']));
    }

    #[Test]
    public function decided_pairs_leave_the_pending_list(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => $b->id, 'status' => PlayerDuplicateDecision::DISMISSED,
            'player_a_snapshot' => [], 'player_b_snapshot' => [], 'decided_at' => now(),
        ]);

        $this->assertCount(0, app(DuplicateFinder::class)->pending());
    }

    #[Test]
    public function score_pair_scores_one_pair_now(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);

        $this->assertSame(['score' => 140, 'reasons' => ['same_name', 'same_birthdate']], app(DuplicateFinder::class)->scorePair($a->id, $b->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/DuplicateFinderTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Services/Player/Duplicates/DuplicateFinder.php`:

```php
<?php

namespace App\Services\Player\Duplicates;

use App\Models\PlayerDuplicateDecision;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Possible duplicate pairs, computed live on every load (owner 2026-11-26):
 * only players sharing a name, birthdate, phone or father are compared, never
 * every player with every other.
 */
final class DuplicateFinder
{
    /** A bucket bigger than this is a common value (a frequent father's name), not a lead. */
    private const MAX_BUCKET = 400;

    /**
     * Pending pairs: at or above the floor and $minScore, meeting every
     * criterion, with no decision recorded.
     *
     * @param  list<string>  $criteria
     * @return Collection<int, array{a:int, b:int, score:int, reasons:list<string>}>
     */
    public function pending(array $criteria = [], int $minScore = 0): Collection
    {
        $profiles = $this->profiles();
        $decided = PlayerDuplicateDecision::query()
            ->whereNotNull('player_a_id')
            ->whereNotNull('player_b_id')
            ->get(['player_a_id', 'player_b_id'])
            ->mapWithKeys(fn (PlayerDuplicateDecision $d) => [$d->player_a_id.'-'.$d->player_b_id => true]);
        $floor = max(DuplicateScorer::MIN_SCORE, $minScore);

        $pairs = [];
        foreach ($this->candidates($profiles) as $key => [$a, $b]) {
            if (isset($decided[$key])) {
                continue;
            }
            $result = DuplicateScorer::score($profiles[$a], $profiles[$b]);
            if ($result['score'] < $floor || ! DuplicateScorer::meets($result['reasons'], $criteria)) {
                continue;
            }
            $pairs[] = ['a' => $a, 'b' => $b, ...$result];
        }

        usort($pairs, fn (array $x, array $y) => [$y['score'], $x['a'], $x['b']] <=> [$x['score'], $y['a'], $y['b']]);

        return collect($pairs);
    }

    /** @return array{score:int, reasons:list<string>} */
    public function scorePair(int $a, int $b): array
    {
        $rows = $this->rows()->whereIn('id', [$a, $b])->get()->keyBy('id');
        if (! $rows->has($a) || ! $rows->has($b)) {
            return ['score' => 0, 'reasons' => []];
        }

        return DuplicateScorer::score(
            DuplicateScorer::profile((array) $rows[$a]),
            DuplicateScorer::profile((array) $rows[$b]),
        );
    }

    private function rows(): Builder
    {
        return DB::table('players')->select(['id', 'firstname', 'lastname', 'father', 'birthdate', 'gender', 'phones']);
    }

    /** @return array<int, array> profiles keyed by player id */
    private function profiles(): array
    {
        $profiles = [];
        foreach ($this->rows()->orderBy('id')->cursor() as $row) {
            $profiles[(int) $row->id] = DuplicateScorer::profile((array) $row);
        }

        return $profiles;
    }

    /** @return array<string, array{0:int, 1:int}> "a-b" => [a, b] with a < b */
    private function candidates(array $profiles): array
    {
        $buckets = [];
        foreach ($profiles as $id => $p) {
            if ($p['first'] !== '' && $p['last'] !== '') {
                $names = [$p['first'], $p['last']];
                sort($names);
                $buckets['n:'.implode('|', $names)][] = $id; // same or swapped name
            }
            if ($p['birthdate'] !== null) {
                $buckets['b:'.$p['birthdate']][] = $id;
            }
            foreach ($p['phones'] as $phone) {
                $buckets['p:'.$phone][] = $id;
            }
            if ($p['father'] !== '') {
                $buckets['f:'.$p['father']][] = $id;
            }
        }

        $pairs = [];
        foreach ($buckets as $ids) {
            $ids = array_values(array_unique($ids));
            $count = count($ids);
            if ($count < 2 || $count > self::MAX_BUCKET) {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = $ids[$i] < $ids[$j] ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                    $pairs[$a.'-'.$b] = [$a, $b];
                }
            }
        }

        return $pairs;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/DuplicateFinderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/Duplicates/DuplicateFinder.php tests/Feature/DuplicateFinderTest.php
git commit -m "feat(players): live duplicate pair finder"
```

---

### Task 5: Duplicates list page (status, min score, criteria, dismiss, undo)

**Files:**
- Create: `app/Http/Controllers/PlayerDuplicateController.php`
- Create: `resources/js/Pages/Players/Duplicates.vue`
- Modify: `routes/web.php` (before `Route::resource('players', …)`, next to the join-dates block)
- Modify: `config/permissions.php` (`overrides`)
- Modify: `resources/js/Pages/Players/Index.vue` (`secondaryActions`)
- Modify: `resources/js/Components/Icon.vue` (add `merge` icon)
- Test: `tests/Feature/PlayerDuplicatesPageTest.php`

**Interfaces:**
- Consumes: `DuplicateFinder::pending/scorePair`, `DuplicateScorer::CRITERIA/meets`, `PlayerCards::for`, `PlayerDuplicateDecision`.
- Produces: routes `players.duplicates.index` (GET `/players/duplicates`), `players.duplicates.dismiss` (POST `/players/duplicates/dismiss`, body `a`, `b`), `players.duplicates.restore` (DELETE `/players/duplicates/{decision}`). The 👁 and ↔ buttons link to Task 8's `players.duplicates.merge`; until that route exists they stay hidden behind `const hasMergeRoute = route().has('players.duplicates.merge');`, so this task ships on its own.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PlayerDuplicatesPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerDuplicatesPageTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function merger(): User
    {
        return $this->userWith(['players' => ['view', 'merge']]);
    }

    #[Test]
    public function every_duplicates_route_needs_the_merge_right(): void
    {
        foreach (['index', 'dismiss', 'restore'] as $name) {
            $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.'.$name), $name);
        }

        $this->actingAs($this->userWith(['players' => ['view', 'add', 'edit', 'delete']]))
            ->get(route('players.duplicates.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_page_lists_pending_pairs_with_cards(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02', 'archived' => true]);

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/Duplicates')
                ->where('total', 1)
                ->where('filters.status', 'pending')
                ->where('pairs.data.0.a.id', $a->id)
                ->where('pairs.data.0.b.id', $b->id)
                ->where('pairs.data.0.b.archived', true)
                ->where('pairs.data.0.score', 140)
                ->where('pairs.data.0.reasons', ['same_name', 'same_birthdate'])
                ->where('pairs.data.0.status', 'pending'));
    }

    #[Test]
    public function criteria_filter_reaches_the_finder(): void
    {
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.index', ['criteria' => ['name', 'birthdate']]))
            ->assertInertia(fn (Assert $page) => $page->where('total', 0)->where('filters.criteria', ['name', 'birthdate']));
    }

    #[Test]
    public function dismiss_remembers_the_pair_and_undo_brings_it_back(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $user = $this->merger();

        $this->actingAs($user)
            ->post(route('players.duplicates.dismiss'), ['a' => $b->id, 'b' => $a->id])
            ->assertSessionHas('success', 'flash.dup.dismissed');

        $decision = PlayerDuplicateDecision::sole();
        $this->assertSame([$a->id, $b->id], [$decision->player_a_id, $decision->player_b_id]);
        $this->assertSame(PlayerDuplicateDecision::DISMISSED, $decision->status);
        $this->assertSame(['same_name'], $decision->reasons);
        $this->assertSame($user->id, $decision->decided_by);

        $this->actingAs($user)->get(route('players.duplicates.index'))
            ->assertInertia(fn (Assert $page) => $page->where('total', 0));
        $this->actingAs($user)->get(route('players.duplicates.index', ['status' => 'dismissed']))
            ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('pairs.data.0.decision_id', $decision->id));

        $this->actingAs($user)
            ->delete(route('players.duplicates.restore', $decision))
            ->assertSessionHas('success', 'flash.dup.restored');
        $this->assertDatabaseCount('player_duplicate_decisions', 0);
    }

    #[Test]
    public function a_player_cannot_be_dismissed_against_itself(): void
    {
        $a = $this->player();

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.dismiss'), ['a' => $a->id, 'b' => $a->id])
            ->assertSessionHasErrors('b');
    }

    #[Test]
    public function merged_decisions_cannot_be_undone(): void
    {
        $a = $this->player();
        $decision = PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => null, 'status' => PlayerDuplicateDecision::MERGED,
            'player_a_snapshot' => PlayerDuplicateDecision::snapshot($a), 'player_b_snapshot' => ['id' => 999, 'name' => 'Gone', 'membership_ids' => [], 'birthdate' => null, 'picture_url' => null, 'archived' => false],
            'kept_player_id' => $a->id, 'decided_at' => now(),
        ]);

        $this->actingAs($this->merger())->delete(route('players.duplicates.restore', $decision))->assertNotFound();
        $this->actingAs($this->merger())->get(route('players.duplicates.index', ['status' => 'merged']))
            ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('pairs.data.0.b.name', 'Gone'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerDuplicatesPageTest.php`
Expected: FAIL (route `players.duplicates.index` not defined).

- [ ] **Step 3: Routes + permissions**

In `routes/web.php`, directly after the three join-dates routes (before `Route::resource('players', …)`), add:

```php
    // Possible duplicate players (before the resource so the static path wins)
    Route::get('/players/duplicates', [PlayerDuplicateController::class, 'index'])->name('players.duplicates.index');
    Route::post('/players/duplicates/dismiss', [PlayerDuplicateController::class, 'dismiss'])->name('players.duplicates.dismiss');
    Route::delete('/players/duplicates/{decision}', [PlayerDuplicateController::class, 'restore'])->name('players.duplicates.restore');
```

and the import at the top with the other controllers: `use App\Http\Controllers\PlayerDuplicateController;`.

In `config/permissions.php` `overrides`, after `'players.academic-results' => ['players', 'view'],` add:

```php
        // Finding and merging duplicates is its own right (Role::EXTRA_ACTIONS): a merge deletes a player.
        'players.duplicates.index' => ['players', 'merge'],
        'players.duplicates.dismiss' => ['players', 'merge'],
        'players.duplicates.restore' => ['players', 'merge'],
```

- [ ] **Step 4: Controller**

`app/Http/Controllers/PlayerDuplicateController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\DuplicateFinder;
use App\Services\Player\Duplicates\DuplicateScorer;
use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «Doublons potentiels»: scored pairs, "not duplicate" decisions and their undo. */
class PlayerDuplicateController extends Controller
{
    private const PER_PAGE = 50;

    private const MIN_SCORES = [0, 50, 100, 150, 200];

    public function index(Request $request, DuplicateFinder $finder): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'dismissed', 'merged'])],
            'min_score' => ['nullable', 'integer', Rule::in(self::MIN_SCORES)],
            'criteria' => ['nullable', 'array'],
            'criteria.*' => [Rule::in(array_keys(DuplicateScorer::CRITERIA))],
        ]);
        $status = $filters['status'] ?? 'pending';
        $minScore = (int) ($filters['min_score'] ?? 0);
        $criteria = array_values($filters['criteria'] ?? []);

        $rows = $status === 'pending'
            ? $finder->pending($criteria, $minScore)->map(fn (array $pair) => [
                ...$pair, 'status' => 'pending', 'decision_id' => null, 'decided_at' => null,
                'kept_player_id' => null, 'snapshot_a' => null, 'snapshot_b' => null,
            ])
            : $this->decided($status, $criteria, $minScore);

        $page = max(1, (int) $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
        $cards = PlayerCards::for($paginator->getCollection()
            ->flatMap(fn (array $row) => [$row['a'], $row['b']])->filter()->unique()->values()->all());

        return Inertia::render('Players/Duplicates', [
            'filters' => ['status' => $status, 'min_score' => $minScore, 'criteria' => $criteria],
            'total' => $rows->count(),
            'criteriaOptions' => array_keys(DuplicateScorer::CRITERIA),
            'minScores' => self::MIN_SCORES,
            'pairs' => $paginator->through(fn (array $row) => [
                'key' => ($row['decision_id'] ?? 'p').'-'.$row['a'].'-'.$row['b'],
                // Merged rows show what the players were; the others show them as they are now.
                'a' => $row['status'] === 'merged' ? $row['snapshot_a'] : ($cards[$row['a']] ?? $row['snapshot_a']),
                'b' => $row['status'] === 'merged' ? $row['snapshot_b'] : ($cards[$row['b']] ?? $row['snapshot_b']),
                'score' => $row['score'],
                'reasons' => $row['reasons'],
                'status' => $row['status'],
                'decision_id' => $row['decision_id'],
                'decided_at' => $row['decided_at'],
                'kept_player_id' => $row['kept_player_id'],
            ]),
        ]);
    }

    public function dismiss(Request $request, DuplicateFinder $finder): RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer', 'exists:players,id'],
            'b' => ['required', 'integer', 'exists:players,id', 'different:a'],
        ]);
        [$a, $b] = PlayerDuplicateDecision::orderedPair((int) $data['a'], (int) $data['b']);
        $players = Player::whereIn('id', [$a, $b])->get()->keyBy('id');
        $result = $finder->scorePair($a, $b);

        PlayerDuplicateDecision::updateOrCreate(
            ['player_a_id' => $a, 'player_b_id' => $b],
            [
                'status' => PlayerDuplicateDecision::DISMISSED,
                'player_a_snapshot' => PlayerDuplicateDecision::snapshot($players[$a]),
                'player_b_snapshot' => PlayerDuplicateDecision::snapshot($players[$b]),
                'kept_player_id' => null,
                'score' => $result['score'],
                'reasons' => $result['reasons'],
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ],
        );

        return back()->with('success', 'flash.dup.dismissed');
    }

    public function restore(PlayerDuplicateDecision $decision): RedirectResponse
    {
        abort_unless($decision->status === PlayerDuplicateDecision::DISMISSED, 404);

        $decision->delete();

        return back()->with('success', 'flash.dup.restored');
    }

    /** Dismissed or merged decisions, newest first, filtered like the live list. */
    private function decided(string $status, array $criteria, int $minScore): Collection
    {
        return PlayerDuplicateDecision::query()
            ->where('status', $status)
            ->latest('decided_at')
            ->latest('id')
            ->get()
            ->filter(fn (PlayerDuplicateDecision $d) => $d->score >= $minScore && DuplicateScorer::meets($d->reasons ?? [], $criteria))
            ->map(fn (PlayerDuplicateDecision $d) => [
                'a' => $d->player_a_id,
                'b' => $d->player_b_id,
                'score' => $d->score,
                'reasons' => $d->reasons ?? [],
                'status' => $d->status,
                'decision_id' => $d->id,
                'decided_at' => $d->decided_at?->toDateString(),
                'kept_player_id' => $d->kept_player_id,
                'snapshot_a' => $d->player_a_snapshot,
                'snapshot_b' => $d->player_b_snapshot,
            ])
            ->values();
    }
}
```

- [ ] **Step 5: Run the PHP test**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerDuplicatesPageTest.php`
Expected: PASS (6 tests). (`withoutVite()` lets the page render before the Vue file exists; it is written next.)

- [ ] **Step 6: Icon + Players list action**

In `resources/js/Components/Icon.vue`, add after the `copy:` entry:

```js
    merge: '<path d="M4 8h13l-3-3"/><path d="M20 16H7l3 3"/>',
```

In `resources/js/Pages/Players/Index.vue`, replace the `secondaryActions` computed with:

```js
const secondaryActions = computed(() => [
    { key: 'import', label: t('import'), icon: 'upload', onClick: () => { showImport.value = true; } },
    ...(can('players', 'edit') ? [{ key: 'join-dates', label: t('join_dates_queue'), icon: 'calendar', onClick: () => router.visit(route('players.join-dates.index')) }] : []),
    ...(can('players', 'merge') ? [{ key: 'duplicates', label: t('dup.find'), icon: 'copy', onClick: () => router.visit(route('players.duplicates.index')) }] : []),
]);
```

- [ ] **Step 7: Vue page**

`resources/js/Pages/Players/Duplicates.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    filters: { type: Object, required: true },
    total: { type: Number, default: 0 },
    pairs: { type: Object, required: true },
    criteriaOptions: { type: Array, default: () => [] },
    minScores: { type: Array, default: () => [] },
});

const open = ref(true);
const status = ref(props.filters.status);
const minScore = ref(props.filters.min_score);
const criteria = ref([...props.filters.criteria]);
const hasMergeRoute = route().has('players.duplicates.merge');
const selectClass = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

const statusClass = {
    pending: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    dismissed: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    merged: 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300',
};

function apply() {
    router.get(
        route('players.duplicates.index'),
        { status: status.value, min_score: minScore.value || undefined, criteria: criteria.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function toggleCriterion(criterion) {
    criteria.value = criteria.value.includes(criterion)
        ? criteria.value.filter((c) => c !== criterion)
        : [...criteria.value, criterion];
    apply();
}

function refresh() {
    router.reload({ preserveScroll: true });
}

function dismiss(pair) {
    router.post(route('players.duplicates.dismiss'), { a: pair.a.id, b: pair.b.id }, { preserveScroll: true });
}

function restore(pair) {
    router.delete(route('players.duplicates.restore', pair.decision_id), { preserveScroll: true });
}

function mergeHref(pair, view = false) {
    return route('players.duplicates.merge', { a: pair.a.id, b: pair.b.id, ...(view ? { view: 1 } : {}) });
}
</script>

<template>
    <Head :title="t('dup.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('dup.title') }}</h1>
        </template>

        <div class="mx-auto max-w-7xl space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-700">
                    <Icon name="copy" class="h-5 w-5 text-amber-600 dark:text-amber-300" />
                    <span class="font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.title') }}</span>
                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ t('dup.pairs_count', { count: total }) }}</span>
                    <button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" :aria-expanded="open" @click="open = !open">
                        <Icon name="chevron" :class="['h-4 w-4 transition', open ? 'rotate-180' : '']" />
                    </button>
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <select v-model="status" :class="selectClass" :aria-label="t('dup.col_status')" @change="apply">
                            <option value="pending">{{ t('dup.status.pending') }}</option>
                            <option value="dismissed">{{ t('dup.status.dismissed') }}</option>
                            <option value="merged">{{ t('dup.status.merged') }}</option>
                        </select>
                        <select v-model.number="minScore" :class="selectClass" :aria-label="t('dup.score')" @change="apply">
                            <option v-for="score in minScores" :key="score" :value="score">{{ score ? t('dup.min_score', { score }) : t('dup.min_score_all') }}</option>
                        </select>
                        <IconButton icon="refresh" :label="t('refresh')" variant="success" plain @click="refresh" />
                    </div>
                </div>

                <div v-show="open">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 text-sm dark:border-slate-700">
                        <span class="text-slate-600 dark:text-slate-300">{{ t('dup.must_match') }}</span>
                        <button
                            v-for="criterion in criteriaOptions"
                            :key="criterion"
                            type="button"
                            :aria-pressed="criteria.includes(criterion)"
                            :class="['rounded-full border px-3 py-1', criteria.includes(criterion)
                                ? 'border-primary-600 bg-primary-600 text-white dark:border-primary-300 dark:bg-primary-300 dark:text-slate-900'
                                : 'border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800']"
                            @click="toggleCriterion(criterion)"
                        >{{ t('dup.criterion.' + criterion) }}</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th class="px-3 py-2 text-start">{{ t('dup.player_a') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.player_b') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.score') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.reasons') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.col_status') }}</th>
                                    <th class="px-3 py-2 text-start">{{ t('dup.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="pair in pairs.data" :key="pair.key" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <td v-for="side in ['a', 'b']" :key="side" class="px-3 py-2 align-top">
                                        <div class="flex items-center gap-2">
                                            <img v-if="pair[side]?.picture_url" :src="pair[side].picture_url" alt="" class="h-8 w-8 rounded-full object-cover" />
                                            <div class="min-w-0">
                                                <Link
                                                    v-if="pair.status !== 'merged' || pair[side]?.id === pair.kept_player_id"
                                                    :href="route('players.show', pair[side].id)"
                                                    class="block break-words font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300"
                                                >{{ pair[side]?.name }}</Link>
                                                <span v-else class="block break-words font-semibold text-slate-500 line-through dark:text-slate-400">{{ pair[side]?.name }}</span>
                                                <span class="block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ (pair[side]?.membership_ids || []).join(' · ') }}<template v-if="pair[side]?.birthdate"> · {{ pair[side].birthdate }}</template></span>
                                                <span v-if="pair[side]?.archived" class="mt-0.5 inline-block rounded bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('dup.archived') }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <span class="inline-block rounded bg-rose-50 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ pair.score }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="reason in pair.reasons"
                                                :key="reason"
                                                :class="['rounded px-2 py-0.5 text-xs', reason === 'different_gender'
                                                    ? 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'
                                                    : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200']"
                                            >{{ t('dup.reason.' + reason) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusClass[pair.status]]">{{ t('dup.status.' + pair.status) }}</span>
                                        <span v-if="pair.decided_at" class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ pair.decided_at }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex gap-1">
                                            <template v-if="pair.status === 'pending'">
                                                <IconButton v-if="hasMergeRoute" icon="eye" :label="t('dup.compare')" :href="mergeHref(pair, true)" icon-only />
                                                <IconButton icon="close" :label="t('dup.not_duplicate')" variant="danger" icon-only @click="dismiss(pair)" />
                                                <IconButton v-if="hasMergeRoute" icon="merge" :label="t('dup.merge_action')" variant="primary" :href="mergeHref(pair)" icon-only />
                                            </template>
                                            <IconButton v-else-if="pair.status === 'dismissed'" icon="restore" :label="t('dup.undo')" icon-only @click="restore(pair)" />
                                            <IconButton v-else-if="pair.kept_player_id" icon="eye" :label="t('dup.open_kept')" :href="route('players.show', pair.kept_player_id)" icon-only />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!pairs.data.length">
                                    <td colspan="6" class="px-3 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('dup.empty') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="pairs.prev_page_url || pairs.next_page_url" class="flex justify-between p-3">
                        <Link v-if="pairs.prev_page_url" :href="pairs.prev_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('previous') }}</Link>
                        <span v-else />
                        <Link v-if="pairs.next_page_url" :href="pairs.next_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('next') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

Check `t('refresh')`, `t('previous')`, `t('next')` exist: `grep -n '"refresh"\|"previous"\|"next"' resources/js/i18n/en.json` — `previous` / `next` are used by `JoinDates.vue`; if `refresh` is missing, add `"dup.refresh"` to the keys file below (ar «تحديث», fr «Actualiser», en «Refresh») and use it instead.

- [ ] **Step 8: i18n keys**

Write `C:\Users\MUSTAPHA\AppData\Local\Temp\dup-t5-keys.json`:

```json
{
    "dup.find": { "ar": "البحث عن المكررين", "fr": "Rechercher les doublons", "en": "Find duplicates" },
    "dup.title": { "ar": "لاعبون مكررون محتملون", "fr": "Doublons potentiels", "en": "Possible duplicate players" },
    "dup.pairs_count": { "ar": "{count} زوج", "fr": "{count} paires", "en": "{count} pairs" },
    "dup.player_a": { "ar": "اللاعب أ", "fr": "Joueur A", "en": "Player A" },
    "dup.player_b": { "ar": "اللاعب ب", "fr": "Joueur B", "en": "Player B" },
    "dup.score": { "ar": "النقاط", "fr": "Score", "en": "Score" },
    "dup.reasons": { "ar": "الأسباب", "fr": "Raisons", "en": "Reasons" },
    "dup.col_status": { "ar": "الحالة", "fr": "Statut", "en": "Status" },
    "dup.col_actions": { "ar": "الإجراءات", "fr": "Actions", "en": "Actions" },
    "dup.must_match": { "ar": "يجب أن يتطابق", "fr": "Doit correspondre", "en": "Must match" },
    "dup.criterion.name": { "ar": "الاسم واللقب", "fr": "Nom et prénom", "en": "Name" },
    "dup.criterion.birthdate": { "ar": "تاريخ الميلاد", "fr": "Date de naissance", "en": "Birthdate" },
    "dup.criterion.phone": { "ar": "الهاتف", "fr": "Téléphone", "en": "Phone" },
    "dup.criterion.father": { "ar": "اسم الأب", "fr": "Prénom du père", "en": "Father's name" },
    "dup.reason.same_name": { "ar": "نفس الاسم", "fr": "Nom identique", "en": "Same name" },
    "dup.reason.similar_name": { "ar": "اسم متشابه", "fr": "Nom proche", "en": "Similar name" },
    "dup.reason.same_birthdate": { "ar": "نفس تاريخ الميلاد", "fr": "Même date de naissance", "en": "Same birthdate" },
    "dup.reason.same_phone": { "ar": "نفس الهاتف", "fr": "Même téléphone", "en": "Same phone" },
    "dup.reason.same_father": { "ar": "نفس اسم الأب", "fr": "Même père", "en": "Same father" },
    "dup.reason.different_gender": { "ar": "جنس مختلف", "fr": "Sexe différent", "en": "Different gender" },
    "dup.status.pending": { "ar": "قيد الانتظار", "fr": "En attente", "en": "Pending" },
    "dup.status.dismissed": { "ar": "ليس مكرراً", "fr": "Pas un doublon", "en": "Not duplicate" },
    "dup.status.merged": { "ar": "مدموج", "fr": "Fusionné", "en": "Merged" },
    "dup.min_score": { "ar": "≥ {score}", "fr": "≥ {score}", "en": "≥ {score}" },
    "dup.min_score_all": { "ar": "كل النقاط", "fr": "Tous les scores", "en": "All scores" },
    "dup.compare": { "ar": "مقارنة", "fr": "Comparer", "en": "Compare" },
    "dup.not_duplicate": { "ar": "ليس مكرراً", "fr": "Pas un doublon", "en": "Not duplicate" },
    "dup.merge_action": { "ar": "دمج", "fr": "Fusionner", "en": "Merge" },
    "dup.undo": { "ar": "تراجع", "fr": "Annuler", "en": "Undo" },
    "dup.empty": { "ar": "لا يوجد مكررون محتملون بهذه الشروط.", "fr": "Aucun doublon possible avec ces filtres.", "en": "No possible duplicates for these filters." },
    "dup.archived": { "ar": "مؤرشف", "fr": "Archivé", "en": "Archived" },
    "dup.open_kept": { "ar": "فتح اللاعب الباقي", "fr": "Ouvrir le joueur conservé", "en": "Open the kept player" },
    "flash.dup.dismissed": { "ar": "تم الحفظ: ليسا مكررين.", "fr": "Enregistré : ce ne sont pas des doublons.", "en": "Saved: not duplicates." },
    "flash.dup.restored": { "ar": "عاد الزوج إلى قائمة الانتظار.", "fr": "La paire est de nouveau en attente.", "en": "The pair is pending again." }
}
```

Run: `node scripts/i18n-add.mjs C:/Users/MUSTAPHA/AppData/Local/Temp/dup-t5-keys.json` then `npm run i18n:check` (expected: passes).

- [ ] **Step 9: Build and check in the browser**

Run: `npm run build` (expected: no errors). Start the worktree app (`php artisan serve --port=2027 --no-reload`), log in as superadmin, open the Players list → "Find duplicates": pairs list shows, chips filter, status/min-score dropdowns filter, ✕ moves a row to Dismissed, Undo brings it back, dark mode and 320 px width keep the table scrollable. Stop the server.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/PlayerDuplicateController.php resources/js/Pages/Players/Duplicates.vue resources/js/Pages/Players/Index.vue resources/js/Components/Icon.vue routes/web.php config/permissions.php tests/Feature/PlayerDuplicatesPageTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(players): possible duplicates page with dismiss and undo"
```

---

### Task 6: Merge rules — `FieldMerge`, `MergeClashes`, `MergePreview`

**Files:**
- Create: `app/Services/Player/Merge/FieldMerge.php`
- Create: `app/Services/Player/Merge/MergeClashes.php`
- Create: `app/Services/Player/Merge/MergePreview.php`
- Test: `tests/Feature/PlayerMergeRulesTest.php`

**Interfaces:**
- Produces:
  - `FieldMerge::FIELDS` (list of field names; `picture` = `picture_url` + `picture_filename`)
  - `FieldMerge::diff(Player $keeper, Player $duplicate): list<array{field:string, keeper:?string, duplicate:?string, mode:'same'|'auto'|'keep'|'choose'}>`
  - `FieldMerge::resolve(Player $keeper, Player $duplicate, array $choices): array` (attributes for the keeper; `$choices` = field => `'keeper'|'duplicate'`)
  - `MergeClashes::find(int $keeper, int $duplicate): list<array{kind:string, label:string, keeper_row:int, duplicate_row:int, winner:'keeper'|'duplicate'}>` — kinds: `document`, `registration`, `attendance`, `team`, `competition`, `game`, `injury`, `academic_year`
  - `MergeClashes::registrationMap(array $clashes): array<int,int>` (loser registration id => winner id)
  - `MergeClashes::TABLES` (kind => table)
  - `MergePreview::FOREIGN_KEYS` (every `table.column` referencing `players`), `MergePreview::counts(int $duplicate): array<string,int>` (table => rows that move, non-zero only)

- [ ] **Step 1: Write the failing test**

`tests/Feature/PlayerMergeRulesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\Merge\FieldMerge;
use App\Services\Player\Merge\MergeClashes;
use App\Services\Player\Merge\MergePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergeRulesTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function clashes(Player $keeper, Player $duplicate): array
    {
        return app(MergeClashes::class)->find($keeper->id, $duplicate->id);
    }

    #[Test]
    public function the_foreign_key_list_matches_the_live_schema(): void
    {
        $live = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $fk) {
                if ($fk['foreign_table'] === 'players') {
                    $live[] = $table['name'].'.'.$fk['columns'][0];
                }
            }
        }
        sort($live);
        $known = MergePreview::FOREIGN_KEYS;
        sort($known);

        $this->assertSame($known, $live, 'A table now points at players: teach PlayerMerger to move it, then list it in MergePreview::FOREIGN_KEYS.');
    }

    #[Test]
    public function field_diff_auto_fills_empties_and_asks_where_both_differ(): void
    {
        $keeper = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'father' => null, 'email' => 'a@example.test', 'phones' => ['0550000001']]);
        $duplicate = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'father' => 'محمد', 'email' => null, 'phones' => ['0550000002', '0550000001']]);

        $rows = collect(app(FieldMerge::class)->diff($keeper, $duplicate))->keyBy('field');

        $this->assertSame('choose', $rows['firstname']['mode']);
        $this->assertSame('same', $rows['lastname']['mode']);
        $this->assertSame('auto', $rows['father']['mode']);
        $this->assertSame('keep', $rows['email']['mode']);

        $attributes = app(FieldMerge::class)->resolve($keeper, $duplicate, ['firstname' => 'duplicate']);

        $this->assertSame('فاطمه', $attributes['firstname']);
        $this->assertSame('محمد', $attributes['father']);
        $this->assertArrayNotHasKey('email', $attributes);
        $this->assertSame(['0550000001', '0550000002'], $attributes['phones']);
        $this->assertFalse($attributes['archived']);
    }

    #[Test]
    public function same_branch_keeps_the_earliest_join_date(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $mine = $this->registration($keeper, $branch, ['join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['join_date' => '2021-09-01']);

        $clash = $this->clashes($keeper, $duplicate)[0];

        $this->assertSame(['registration', $mine, $theirs, 'duplicate'], [$clash['kind'], $clash['keeper_row'], $clash['duplicate_row'], $clash['winner']]);
        $this->assertSame([$mine => $theirs], app(MergeClashes::class)->registrationMap([$clash]));
    }

    #[Test]
    public function a_received_document_beats_a_missing_one_across_merged_registrations(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $type = $this->documentType();
        $mine = $this->registration($keeper, $branch, ['join_date' => '2021-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['join_date' => '2024-09-01']);
        $missing = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'branch_registration_id' => $mine, 'document_type_id' => $type, 'state' => 'missing']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $duplicate->id, 'branch_registration_id' => $theirs, 'document_type_id' => $type, 'state' => PlayerDocument::RECEIVED]);

        $clash = collect($this->clashes($keeper, $duplicate))->firstWhere('kind', 'document');

        $this->assertSame([$missing, $received, 'duplicate'], [$clash['keeper_row'], $clash['duplicate_row'], $clash['winner']]);
    }

    #[Test]
    public function the_keeper_wins_attendance_and_roster_ties(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $session = $this->trainingSession($this->category($this->branch()));
        DB::table('attendances')->insert([
            ['training_session_id' => $session, 'player_id' => $keeper->id, 'status' => 'present'],
            ['training_session_id' => $session, 'player_id' => $duplicate->id, 'status' => 'absent_unexcused'],
        ]);

        $clash = collect($this->clashes($keeper, $duplicate))->firstWhere('kind', 'attendance');

        $this->assertSame('keeper', $clash['winner']);
        $this->assertSame('2026-10-01', $clash['label']);
    }

    #[Test]
    public function preview_counts_the_rows_that_move(): void
    {
        $duplicate = $this->player();
        $this->registration($duplicate, $this->branch());
        DB::table('player_emergency_contacts')->insert(['player_id' => $duplicate->id, 'name' => 'Parent Test', 'phone' => '0550000009', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['player_emergency_contacts' => 1, 'branch_player' => 1], app(MergePreview::class)->counts($duplicate->id));
    }
}
```

Before running, check the emergency-contacts columns: `php -r "\$db=new PDO('sqlite:database/database.sqlite'); foreach(\$db->query('pragma table_info(player_emergency_contacts)') as \$c) echo \$c['name'].' ';"` — if `name` / `phone` differ, use the real required columns in that insert.

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergeRulesTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement `FieldMerge`**

`app/Services/Player/Merge/FieldMerge.php`:

```php
<?php

namespace App\Services\Player\Merge;

use App\Models\Player;

/**
 * The player's own columns in a merge (owner 2026-11-26): where only the
 * duplicate has a value it fills in by itself, where both differ the user
 * chooses, phones from both are kept, and the kept player stays active if
 * either was. Membership ID, status, category and debt stay the keeper's.
 */
final class FieldMerge
{
    public const FIELDS = [
        'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'birthdate', 'gender', 'picture',
        'email', 'city', 'wilaya_id', 'is_student', 'member_job_id',
        'health_medical_conditions', 'health_blood_group_rhesus', 'licence_number',
    ];

    /** @return list<array{field:string, keeper:?string, duplicate:?string, mode:string}> */
    public function diff(Player $keeper, Player $duplicate): array
    {
        return array_map(function (string $field) use ($keeper, $duplicate) {
            $mine = $this->raw($keeper, $field);
            $theirs = $this->raw($duplicate, $field);

            return [
                'field' => $field,
                'keeper' => $this->display($keeper, $field),
                'duplicate' => $this->display($duplicate, $field),
                'mode' => match (true) {
                    $mine === $theirs => 'same',
                    $mine === null => 'auto',
                    $theirs === null => 'keep',
                    default => 'choose',
                },
            ];
        }, self::FIELDS);
    }

    /** @param  array<string, string>  $choices  field => 'keeper' | 'duplicate' */
    public function resolve(Player $keeper, Player $duplicate, array $choices): array
    {
        $attributes = [];
        foreach ($this->diff($keeper, $duplicate) as $row) {
            $take = $row['mode'] === 'auto'
                || ($row['mode'] === 'choose' && ($choices[$row['field']] ?? 'keeper') === 'duplicate');
            if (! $take) {
                continue;
            }
            foreach ($this->columns($row['field']) as $column) {
                $attributes[$column] = $duplicate->getRawOriginal($column);
            }
        }

        $attributes['phones'] = array_values(array_unique(array_filter(
            [...($keeper->phones ?? []), ...($duplicate->phones ?? [])],
            fn ($phone) => trim((string) $phone) !== '',
        )));
        $attributes['archived'] = (bool) $keeper->archived && (bool) $duplicate->archived;

        return $attributes;
    }

    /** @return list<string> */
    private function columns(string $field): array
    {
        return $field === 'picture' ? ['picture_url', 'picture_filename'] : [$field];
    }

    /** Comparable value, null when empty. */
    private function raw(Player $player, string $field): ?string
    {
        $value = $player->getRawOriginal($field === 'picture' ? 'picture_url' : $field);
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            $value = (int) $value;
        }
        $value = trim((string) $value);
        if ($field === 'birthdate') {
            $value = substr($value, 0, 10);
        }

        return $value === '' ? null : $value;
    }

    private function display(Player $player, string $field): ?string
    {
        return match ($field) {
            'member_job_id' => $player->memberJob?->localized_name ?? $player->memberJob?->name,
            'wilaya_id' => $player->wilaya?->name,
            default => $this->raw($player, $field),
        };
    }
}
```

- [ ] **Step 4: Implement `MergeClashes`**

`app/Services/Player/Merge/MergeClashes.php`:

```php
<?php

namespace App\Services\Player\Merge;

use App\Models\PlayerDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records both players hold that may exist only once, and which one stays
 * (owner's smart rules, 2026-11-26). The one source for the merge preview and
 * the merge itself. keeper_row / duplicate_row are ids in the kind's table.
 */
final class MergeClashes
{
    /** Kind => table holding the clashing rows. */
    public const TABLES = [
        'document' => 'player_documents',
        'registration' => 'branch_player',
        'attendance' => 'attendances',
        'team' => 'team_players',
        'competition' => 'competition_players',
        'game' => 'game_players',
        'injury' => 'injury_notes',
        'academic_year' => 'player_academic_years',
    ];

    /** @return list<array{kind:string, label:string, keeper_row:int, duplicate_row:int, winner:string}> */
    public function find(int $keeper, int $duplicate): array
    {
        $registrations = $this->registrations($keeper, $duplicate);

        return [
            ...$this->documents($keeper, $duplicate, $this->registrationMap($registrations)),
            ...$registrations,
            ...$this->attendances($keeper, $duplicate),
            ...$this->teams($keeper, $duplicate),
            ...$this->competitions($keeper, $duplicate),
            ...$this->games($keeper, $duplicate),
            ...$this->injuries($keeper, $duplicate),
            ...$this->academicYears($keeper, $duplicate),
        ];
    }

    /** @return array<int, int> loser registration id => winner registration id */
    public function registrationMap(array $clashes): array
    {
        $map = [];
        foreach ($clashes as $clash) {
            if ($clash['kind'] !== 'registration') {
                continue;
            }
            $keeperWins = $clash['winner'] === 'keeper';
            $map[$keeperWins ? $clash['duplicate_row'] : $clash['keeper_row']] = $keeperWins ? $clash['keeper_row'] : $clash['duplicate_row'];
        }

        return $map;
    }

    /** Same branch: the earliest join wins (its membership ID stays). */
    private function registrations(int $keeper, int $duplicate): array
    {
        $names = DB::table('branches')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('branch_player')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'branch_id', 'join_date', 'legacy_join_year']),
            $keeper,
            fn (object $row) => (string) $row->branch_id,
            fn (object $mine, object $theirs) => $this->joinedOn($theirs) < $this->joinedOn($mine),
            'registration',
            fn (object $row) => (string) ($names[$row->branch_id] ?? ''),
        );
    }

    /** Same registration (after merging registrations) and type: received beats exempt beats missing. */
    private function documents(int $keeper, int $duplicate, array $registrationMap): array
    {
        $names = DB::table('document_types')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('player_documents')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'document_type_id', 'state', 'branch_registration_id']),
            $keeper,
            fn (object $row) => ($registrationMap[$row->branch_registration_id] ?? $row->branch_registration_id ?? 'none').':'.$row->document_type_id,
            fn (object $mine, object $theirs) => $this->documentRank($theirs->state) > $this->documentRank($mine->state),
            'document',
            fn (object $row) => (string) ($names[$row->document_type_id] ?? ''),
        );
    }

    private function attendances(int $keeper, int $duplicate): array
    {
        $rows = DB::table('attendances')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'training_session_id', 'status']);
        $dates = DB::table('training_sessions')->whereIn('id', $rows->pluck('training_session_id')->unique())->pluck('date', 'id');

        return $this->pairUp(
            $rows,
            $keeper,
            fn (object $row) => (string) $row->training_session_id,
            fn (object $mine, object $theirs) => blank($mine->status) && filled($theirs->status),
            'attendance',
            fn (object $row) => substr((string) ($dates[$row->training_session_id] ?? ''), 0, 10),
        );
    }

    /** Same team and season: the keeper's row, unless it has no shirt number and the duplicate's has. */
    private function teams(int $keeper, int $duplicate): array
    {
        $names = DB::table('teams')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('team_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'team_id', 'season_start_year', 'shirt_number']),
            $keeper,
            fn (object $row) => $row->team_id.':'.$row->season_start_year,
            fn (object $mine, object $theirs) => $mine->shirt_number === null && $theirs->shirt_number !== null,
            'team',
            fn (object $row) => ($names[$row->team_id] ?? '').' '.$row->season_start_year,
        );
    }

    private function competitions(int $keeper, int $duplicate): array
    {
        $names = DB::table('competitions')->pluck('name', 'id');

        return $this->pairUp(
            DB::table('competition_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'competition_id']),
            $keeper,
            fn (object $row) => (string) $row->competition_id,
            fn () => false,
            'competition',
            fn (object $row) => (string) ($names[$row->competition_id] ?? ''),
        );
    }

    /** Same match: the keeper's row, unless it is empty (not called up, no minutes, no rating) and the duplicate's is not. */
    private function games(int $keeper, int $duplicate): array
    {
        $rows = DB::table('game_players')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'game_id', 'called_up', 'minutes_played', 'rating', 'played']);
        $dates = DB::table('games')->whereIn('id', $rows->pluck('game_id')->unique())->pluck('date', 'id');
        $empty = fn (object $row) => ! $row->called_up && ! $row->played && $row->minutes_played === null && $row->rating === null;

        return $this->pairUp(
            $rows,
            $keeper,
            fn (object $row) => (string) $row->game_id,
            fn (object $mine, object $theirs) => $empty($mine) && ! $empty($theirs),
            'game',
            fn (object $row) => substr((string) ($dates[$row->game_id] ?? ''), 0, 10),
        );
    }

    private function injuries(int $keeper, int $duplicate): array
    {
        return $this->pairUp(
            DB::table('injury_notes')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'branch_id', 'start_date']),
            $keeper,
            fn (object $row) => ($row->branch_id ?? 0).':'.substr((string) $row->start_date, 0, 10),
            fn () => false,
            'injury',
            fn (object $row) => substr((string) $row->start_date, 0, 10),
        );
    }

    private function academicYears(int $keeper, int $duplicate): array
    {
        return $this->pairUp(
            DB::table('player_academic_years')->whereIn('player_id', [$keeper, $duplicate])->get(['id', 'player_id', 'academic_year']),
            $keeper,
            fn (object $row) => (string) $row->academic_year,
            fn () => false,
            'academic_year',
            fn (object $row) => (string) $row->academic_year,
        );
    }

    /**
     * Rows of both players sharing a slot become clashes.
     *
     * @param  callable(object): string  $slot
     * @param  callable(object, object): bool  $duplicateWins  (keeper's row, duplicate's row)
     * @param  callable(object): string  $label
     */
    private function pairUp(Collection $rows, int $keeper, callable $slot, callable $duplicateWins, string $kind, callable $label): array
    {
        $mine = $rows->filter(fn (object $row) => (int) $row->player_id === $keeper)->keyBy($slot);
        $clashes = [];
        foreach ($rows->filter(fn (object $row) => (int) $row->player_id !== $keeper) as $theirs) {
            $ours = $mine->get($slot($theirs));
            if ($ours === null) {
                continue;
            }
            $clashes[] = [
                'kind' => $kind,
                'label' => $label($theirs),
                'keeper_row' => (int) $ours->id,
                'duplicate_row' => (int) $theirs->id,
                'winner' => $duplicateWins($ours, $theirs) ? 'duplicate' : 'keeper',
            ];
        }

        return $clashes;
    }

    /** Earliest known join: the exact date, else 1 September of the legacy year, else never. */
    private function joinedOn(object $row): string
    {
        if ($row->join_date) {
            return substr((string) $row->join_date, 0, 10);
        }

        return $row->legacy_join_year ? $row->legacy_join_year.'-09-01' : '9999-12-31';
    }

    private function documentRank(?string $state): int
    {
        return match ($state) {
            PlayerDocument::RECEIVED => 2,
            PlayerDocument::EXEMPT => 1,
            default => 0,
        };
    }
}
```

- [ ] **Step 5: Implement `MergePreview`**

`app/Services/Player/Merge/MergePreview.php`:

```php
<?php

namespace App\Services\Player\Merge;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/** How many of the duplicate's records move to the kept player. */
final class MergePreview
{
    /** Every foreign key to players. A test compares this list with the live schema. */
    public const FOREIGN_KEYS = [
        'player_emergency_contacts.player_id', 'player_achievements.player_id', 'player_subscriptions.player_id',
        'board_members.player_id', 'player_other_positions.player_id', 'player_academic_years.player_id',
        'player_documents.player_id', 'attendances.player_id', 'injury_notes.player_id', 'team_players.player_id',
        'competition_players.player_id', 'game_players.player_id', 'game_events.player_id',
        'game_events.related_player_id', 'suspensions.player_id', 'activity_player.player_id',
        'branch_player.player_id', 'training_group_members.player_id',
        'player_duplicate_decisions.player_a_id', 'player_duplicate_decisions.player_b_id',
        'player_duplicate_decisions.kept_player_id',
    ];

    /** @return array<string, int> table => rows that move (non-zero only) */
    public function counts(int $duplicate): array
    {
        $counts = [];
        foreach (self::FOREIGN_KEYS as $key) {
            [$table, $column] = explode('.', $key);
            if ($table === 'player_duplicate_decisions') {
                continue;
            }
            $count = DB::table($table)->where($column, $duplicate)->count();
            if ($count > 0) {
                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }

        $payments = DB::table('transactions')->whereIn('related_entity_type', ['Player', Player::class])->where('related_entity_id', $duplicate)->count();
        if ($payments > 0) {
            $counts['transactions'] = $payments;
        }
        $loans = DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $duplicate)->count();
        if ($loans > 0) {
            $counts['equipment_rentals'] = $loans;
        }

        return $counts;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergeRulesTest.php`
Expected: PASS (6 tests). If `the_foreign_key_list_matches_the_live_schema` fails, the message lists the difference: a new FK table appeared on `main` since this plan — add it to `FOREIGN_KEYS` here and a repoint for it in Task 7's `PlayerMerger::REPOINT` (or a clash rule if it carries a unique key with `player_id`).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Player/Merge/FieldMerge.php app/Services/Player/Merge/MergeClashes.php app/Services/Player/Merge/MergePreview.php tests/Feature/PlayerMergeRulesTest.php
git commit -m "feat(players): merge rules for fields, clashes and counts"
```

---

### Task 7: `PlayerMerger` (the merge transaction)

**Files:**
- Create: `app/Services/Player/Merge/PlayerMerger.php`
- Test: `tests/Feature/PlayerMergerTest.php`

**Interfaces:**
- Consumes: `FieldMerge::resolve`, `MergeClashes::find/registrationMap/TABLES`, `MergePreview::FOREIGN_KEYS`, `DuplicateFinder::scorePair`, `PlayerDuplicateDecision::orderedPair/snapshot`, `App\Services\Finance\RecalculatePlayerDebtService::forPlayer(Player)`.
- Produces: `PlayerMerger::merge(Player $keeper, Player $duplicate, array $choices, ?User $by): PlayerDuplicateDecision` — throws `InvalidArgumentException` when both are the same player; everything inside one `DB::transaction`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PlayerMergerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDuplicateDecision;
use App\Models\User;
use App\Services\Player\Merge\MergePreview;
use App\Services\Player\Merge\PlayerMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergerTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function merge(Player $keeper, Player $duplicate, array $choices = []): PlayerDuplicateDecision
    {
        return app(PlayerMerger::class)->merge($keeper, $duplicate, $choices, User::factory()->admin()->create());
    }

    private function assertNothingPointsAt(int $playerId): void
    {
        foreach (MergePreview::FOREIGN_KEYS as $key) {
            [$table, $column] = explode('.', $key);
            $this->assertSame(0, DB::table($table)->where($column, $playerId)->count(), $key);
        }
        $this->assertSame(0, DB::table('transactions')->where('related_entity_type', 'Player')->where('related_entity_id', $playerId)->count());
        $this->assertSame(0, DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $playerId)->count());
        $this->assertSame(0, DB::table('activity_logs')->where('subject_type', Player::class)->where('subject_id', $playerId)->count());
    }

    #[Test]
    public function a_player_cannot_be_merged_into_itself(): void
    {
        $player = $this->player();

        $this->expectException(InvalidArgumentException::class);
        $this->merge($player, $player);
    }

    #[Test]
    public function it_moves_everything_applies_choices_and_deletes_the_duplicate(): void
    {
        $keeper = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'father' => null, 'phones' => ['0550000001']]);
        $duplicate = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'father' => 'محمد', 'phones' => ['0550000002']]);
        DB::table('player_emergency_contacts')->insert(['player_id' => $duplicate->id, 'name' => 'Parent Test', 'phone' => '0550000009', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('transactions')->insert(['amount' => 1000, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'related_entity_type' => 'Player', 'related_entity_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activity_logs')->insert(['action' => 'player_registered', 'subject_type' => Player::class, 'subject_id' => $duplicate->id, 'occurred_at' => now(), 'created_at' => now()]);
        $branch = $this->branch();
        $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00001', 'join_date' => '2021-09-01']);

        $decision = $this->merge($keeper, $duplicate, ['firstname' => 'duplicate']);

        $this->assertDatabaseMissing('players', ['id' => $duplicate->id]);
        $this->assertNothingPointsAt($duplicate->id);
        $keeper->refresh();
        $this->assertSame('فاطمه', $keeper->firstname);
        $this->assertSame('محمد', $keeper->father);
        $this->assertSame(['0550000001', '0550000002'], $keeper->phones);
        $this->assertSame(1, DB::table('branch_player')->where('player_id', $keeper->id)->where('membership_id', 'FB-2021-00001')->count());
        $this->assertSame(1, DB::table('player_emergency_contacts')->where('player_id', $keeper->id)->count());

        $this->assertSame(PlayerDuplicateDecision::MERGED, $decision->status);
        $this->assertSame($keeper->id, $decision->kept_player_id);
        // The snapshot holds the duplicate as it was before the merge.
        $snapshot = $decision->player_a_id === $keeper->id ? $decision->player_b_snapshot : $decision->player_a_snapshot;
        $this->assertSame($duplicate->id, $snapshot['id']);
    }

    #[Test]
    public function the_earliest_registration_wins_and_takes_the_other_documents_and_history(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $branch = $this->branch();
        $type = $this->documentType();
        $mine = $this->registration($keeper, $branch, ['membership_id' => 'FB-2024-00010', 'join_date' => '2024-09-01']);
        $theirs = $this->registration($duplicate, $branch, ['membership_id' => 'FB-2021-00003', 'join_date' => '2021-09-01']);
        $missing = DB::table('player_documents')->insertGetId(['player_id' => $keeper->id, 'branch_registration_id' => $mine, 'document_type_id' => $type, 'state' => 'missing']);
        $received = DB::table('player_documents')->insertGetId(['player_id' => $duplicate->id, 'branch_registration_id' => $theirs, 'document_type_id' => $type, 'state' => PlayerDocument::RECEIVED]);
        DB::table('player_document_files')->insert(['player_document_id' => $missing, 'path' => 'x/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => now()]);
        DB::table('registration_status_changes')->insert(['branch_registration_id' => $mine, 'changed_on' => '2025-01-01', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$theirs], DB::table('branch_player')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertSame('FB-2021-00003', DB::table('branch_player')->where('id', $theirs)->value('membership_id'));
        $this->assertSame([$received], DB::table('player_documents')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertSame($received, (int) DB::table('player_document_files')->value('player_document_id'));
        $this->assertSame($theirs, (int) DB::table('registration_status_changes')->value('branch_registration_id'));
    }

    #[Test]
    public function the_keeper_wins_session_ties_and_shared_pivots_are_not_doubled(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $category = $this->category($this->branch());
        $shared = $this->trainingSession($category, '2026-10-01');
        $only = $this->trainingSession($category, '2026-10-08');
        DB::table('attendances')->insert([
            ['training_session_id' => $shared, 'player_id' => $keeper->id, 'status' => 'present'],
            ['training_session_id' => $shared, 'player_id' => $duplicate->id, 'status' => 'absent_unexcused'],
            ['training_session_id' => $only, 'player_id' => $duplicate->id, 'status' => 'late'],
        ]);
        $position = DB::table('positions')->insertGetId(['name' => 'Gardien', 'abbreviation' => 'GK']);
        DB::table('player_other_positions')->insert([
            ['player_id' => $keeper->id, 'position_id' => $position],
            ['player_id' => $duplicate->id, 'position_id' => $position],
        ]);

        $this->merge($keeper, $duplicate);

        $this->assertSame(['present', 'late'], DB::table('attendances')->where('player_id', $keeper->id)->orderBy('training_session_id')->pluck('status')->all());
        $this->assertSame(1, DB::table('player_other_positions')->where('player_id', $keeper->id)->count());
    }

    #[Test]
    public function the_keepers_school_year_gains_the_missing_periods(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $mine = DB::table('player_academic_years')->insertGetId(['player_id' => $keeper->id, 'academic_year' => '2025-2026', 'education_level' => 'cem1']);
        $theirs = DB::table('player_academic_years')->insertGetId(['player_id' => $duplicate->id, 'academic_year' => '2025-2026', 'education_level' => 'cem1']);
        DB::table('player_academic_records')->insert([
            ['player_academic_year_id' => $mine, 'period' => 't1', 'gpa' => 12],
            ['player_academic_year_id' => $theirs, 'period' => 't1', 'gpa' => 9],
            ['player_academic_year_id' => $theirs, 'period' => 't2', 'gpa' => 14],
        ]);

        $this->merge($keeper, $duplicate);

        $this->assertSame([$mine], DB::table('player_academic_years')->where('player_id', $keeper->id)->pluck('id')->all());
        $this->assertEquals(['t1' => 12, 't2' => 14], DB::table('player_academic_records')->where('player_academic_year_id', $mine)->pluck('gpa', 'period')->map(fn ($g) => (float) $g)->all());
    }

    #[Test]
    public function dismissed_pairs_of_the_duplicate_are_dropped_and_a_dismissed_pair_becomes_merged(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $third = $this->player();
        foreach ([[$keeper, $duplicate], [$duplicate, $third]] as [$x, $y]) {
            PlayerDuplicateDecision::create([
                'player_a_id' => $x->id, 'player_b_id' => $y->id, 'status' => PlayerDuplicateDecision::DISMISSED,
                'player_a_snapshot' => [], 'player_b_snapshot' => [], 'decided_at' => now(),
            ]);
        }

        $this->merge($keeper, $duplicate);

        $decision = PlayerDuplicateDecision::sole();
        $this->assertSame(PlayerDuplicateDecision::MERGED, $decision->status);
        $this->assertSame($keeper->id, $decision->player_a_id);
        $this->assertNull($decision->player_b_id);
    }

    #[Test]
    public function a_failure_rolls_everything_back(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->registration($duplicate, $this->branch());
        Player::saving(function (Player $player) use ($keeper) {
            if ($player->id === $keeper->id) {
                throw new RuntimeException('boom');
            }
        });

        try {
            $this->merge($keeper, $duplicate);
            $this->fail('The merge should have thrown.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
        $this->assertSame(1, DB::table('branch_player')->where('player_id', $duplicate->id)->count());
        $this->assertDatabaseCount('player_duplicate_decisions', 0);
    }
}
```

Before running, check the required columns used in the inserts above against the live schema (the transactions insert needs every NOT NULL column without default): `php -r "\$db=new PDO('sqlite:database/database.sqlite'); foreach(['transactions','player_emergency_contacts','player_academic_years'] as \$t){ foreach(\$db->query(\"pragma table_info(\$t)\") as \$c) if(\$c['notnull'] && \$c['dflt_value']===null && \$c['name']!='id') echo \"\$t.\".\$c['name'].' '; }"` — add any missing column to the insert with a plausible test value.

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergerTest.php`
Expected: FAIL (class `PlayerMerger` not found).

- [ ] **Step 3: Implement**

`app/Services/Player/Merge/PlayerMerger.php`:

```php
<?php

namespace App\Services\Player\Merge;

use App\Models\BoardMember;
use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Models\User;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Player\Duplicates\DuplicateFinder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges a duplicate player into the kept one (owner 2026-11-26): every
 * record moves, once-only records follow MergeClashes, the duplicate is
 * deleted, all in one transaction. Rows move with the query builder so no
 * registration or document hook fires halfway through.
 */
class PlayerMerger
{
    /** Rows repointed as they are once clashes are settled: table => player column. */
    private const REPOINT = [
        ['player_emergency_contacts', 'player_id'],
        ['player_achievements', 'player_id'],
        ['player_subscriptions', 'player_id'],
        ['board_members', 'player_id'],
        ['suspensions', 'player_id'],
        ['game_events', 'player_id'],
        ['game_events', 'related_player_id'],
        ['training_group_members', 'player_id'],
        ['injury_notes', 'player_id'],
        ['attendances', 'player_id'],
        ['team_players', 'player_id'],
        ['competition_players', 'player_id'],
        ['game_players', 'player_id'],
        ['player_academic_years', 'player_id'],
        ['branch_player', 'player_id'],
        ['player_documents', 'player_id'],
    ];

    /** Pivots: the duplicate's row goes when the keeper already has the same one. */
    private const PIVOTS = [
        'player_other_positions' => 'position_id',
        'activity_player' => 'activity_id',
    ];

    public function __construct(
        private FieldMerge $fields,
        private MergeClashes $clashes,
        private DuplicateFinder $finder,
        private RecalculatePlayerDebtService $debts,
    ) {}

    /** @param  array<string, string>  $choices  field => 'keeper' | 'duplicate' */
    public function merge(Player $keeper, Player $duplicate, array $choices, ?User $by): PlayerDuplicateDecision
    {
        if ($keeper->is($duplicate)) {
            throw new InvalidArgumentException('A player cannot be merged into itself.');
        }

        $k = $keeper->id;
        $d = $duplicate->id;
        [$a, $b] = PlayerDuplicateDecision::orderedPair($k, $d);
        $pair = $this->finder->scorePair($a, $b);
        $snapshots = [$k => PlayerDuplicateDecision::snapshot($keeper), $d => PlayerDuplicateDecision::snapshot($duplicate)];
        $attributes = $this->fields->resolve($keeper, $duplicate, $choices);

        return DB::transaction(function () use ($keeper, $k, $d, $a, $b, $pair, $snapshots, $attributes, $by) {
            $this->settleClashes($this->clashes->find($k, $d));
            $this->movePivots($k, $d);
            $this->dropSameGroupSpells($k, $d);
            foreach (self::REPOINT as [$table, $column]) {
                DB::table($table)->where($column, $d)->update([$column => $k]);
            }
            $this->repointMorphs($k, $d);

            PlayerDuplicateDecision::query()
                ->where('status', PlayerDuplicateDecision::DISMISSED)
                ->where(fn ($q) => $q->where('player_a_id', $d)->orWhere('player_b_id', $d))
                ->where(fn ($q) => $q->where('player_a_id', '!=', $a)->orWhere('player_b_id', '!=', $b))
                ->delete();
            $decision = PlayerDuplicateDecision::updateOrCreate(
                ['player_a_id' => $a, 'player_b_id' => $b],
                [
                    'status' => PlayerDuplicateDecision::MERGED,
                    'player_a_snapshot' => $snapshots[$a],
                    'player_b_snapshot' => $snapshots[$b],
                    'kept_player_id' => $k,
                    'score' => $pair['score'],
                    'reasons' => $pair['reasons'],
                    'decided_by' => $by?->id,
                    'decided_at' => now(),
                ],
            );

            DB::table('players')->where('id', $d)->delete();

            $keeper->forceFill($attributes)->save();
            $keeper->refresh();
            BoardMember::where('player_id', $k)->update(['name' => $keeper->fullname]);
            $this->debts->forPlayer($keeper);

            return $decision->fresh();
        });
    }

    /** Losers go; documents first (they follow registrations), then registrations, then the rest. */
    private function settleClashes(array $clashes): void
    {
        foreach ($clashes as $clash) {
            if ($clash['kind'] !== 'document') {
                continue;
            }
            [$winner, $loser] = $this->sides($clash);
            DB::table('player_document_files')->where('player_document_id', $loser)->update(['player_document_id' => $winner]);
            DB::table('player_documents')->where('id', $loser)->delete();
        }

        foreach ($this->clashes->registrationMap($clashes) as $loser => $winner) {
            DB::table('registration_status_changes')->where('branch_registration_id', $loser)->update(['branch_registration_id' => $winner]);
            DB::table('player_documents')->where('branch_registration_id', $loser)->update(['branch_registration_id' => $winner]);
            DB::table('branch_player')->where('id', $loser)->delete();
        }

        foreach ($clashes as $clash) {
            if (in_array($clash['kind'], ['document', 'registration'], true)) {
                continue;
            }
            [$winner, $loser] = $this->sides($clash);
            if ($clash['kind'] === 'academic_year') {
                $held = DB::table('player_academic_records')->where('player_academic_year_id', $winner)->pluck('period');
                DB::table('player_academic_records')->where('player_academic_year_id', $loser)->whereNotIn('period', $held)
                    ->update(['player_academic_year_id' => $winner]);
                DB::table('player_academic_records')->where('player_academic_year_id', $loser)->delete();
            }
            DB::table(MergeClashes::TABLES[$clash['kind']])->where('id', $loser)->delete();
        }
    }

    /** @return array{0:int, 1:int} [winner row, loser row] */
    private function sides(array $clash): array
    {
        return $clash['winner'] === 'keeper'
            ? [$clash['keeper_row'], $clash['duplicate_row']]
            : [$clash['duplicate_row'], $clash['keeper_row']];
    }

    private function movePivots(int $k, int $d): void
    {
        foreach (self::PIVOTS as $table => $other) {
            $held = DB::table($table)->where('player_id', $k)->pluck($other);
            DB::table($table)->where('player_id', $d)->whereIn($other, $held)->delete();
            DB::table($table)->where('player_id', $d)->update(['player_id' => $k]);
        }
    }

    /** The same group spell recorded twice (same group, same start) is one spell. */
    private function dropSameGroupSpells(int $k, int $d): void
    {
        $mine = DB::table('training_group_members')->where('player_id', $k)->get(['training_group_id', 'from_date']);
        foreach ($mine as $spell) {
            DB::table('training_group_members')->where('player_id', $d)
                ->where('training_group_id', $spell->training_group_id)
                ->where('from_date', $spell->from_date)
                ->delete();
        }
    }

    private function repointMorphs(int $k, int $d): void
    {
        DB::table('equipment_rentals')->where('rentable_type', Player::class)->where('rentable_id', $d)->update(['rentable_id' => $k]);

        $sessions = DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $k)->pluck('inventory_session_id');
        DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $d)->whereIn('inventory_session_id', $sessions)->delete();
        DB::table('inventory_session_participants')->where('participant_type', Player::class)->where('participant_id', $d)->update(['participant_id' => $k]);

        DB::table('transactions')->whereIn('related_entity_type', ['Player', Player::class])->where('related_entity_id', $d)->update(['related_entity_id' => $k]);
        DB::table('activity_logs')->where('subject_type', Player::class)->where('subject_id', $d)->update(['subject_id' => $k]);
    }
}
```

The class is deliberately not `final`: Task 8's controller test mocks it.

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergerTest.php`
Expected: PASS (7 tests). Then re-run `tests/Feature/PlayerMergeRulesTest.php` (expected: PASS).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/Merge/PlayerMerger.php tests/Feature/PlayerMergerTest.php
git commit -m "feat(players): merge a duplicate player into the kept one"
```

---

### Task 8: Merge page — backup, confirm, activity, Vue

**Files:**
- Create: `app/Services/Player/Merge/PreMergeBackup.php`
- Create: `app/Http/Controllers/PlayerMergeController.php`
- Create: `resources/js/Pages/Players/MergeDuplicates.vue`
- Modify: `app/Services/Activity/ActivityAction.php` (`PLAYERS_MERGED`)
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/PlayerMergePageTest.php`

**Interfaces:**
- Consumes: Tasks 2–7.
- Produces: routes `players.duplicates.merge` (GET `/players/duplicates/merge?a=&b=&keeper=&view=`), `players.duplicates.merge.store` (POST `/players/duplicates/merge`, body `a, b, keeper, choices{field: keeper|duplicate}`); `PreMergeBackup::run(): string`; `ActivityAction::PLAYERS_MERGED = 'players_merged'`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PlayerMergePageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Player\Merge\PlayerMerger;
use App\Services\Player\Merge\PreMergeBackup;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergePageTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function merger(): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['players' => ['view', 'merge']]])->id]);
    }

    private function backupWorks(): void
    {
        $this->mock(PreMergeBackup::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->once()->andReturn('backup.zip'));
    }

    #[Test]
    public function merge_routes_need_the_merge_right(): void
    {
        $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.merge'));
        $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.merge.store'));
    }

    #[Test]
    public function the_page_compares_both_with_the_older_record_kept_by_default(): void
    {
        $older = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'father' => null]);
        $newer = $this->player(['firstname' => 'Sammy', 'lastname' => 'Amrani', 'father' => 'Ali']);
        $this->registration($newer, $this->branch());

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $newer->id, 'b' => $older->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/MergeDuplicates')
                ->where('keeper.id', $older->id)
                ->where('duplicate.id', $newer->id)
                ->where('counts.branch_player', 1)
                ->where('viewOnly', false)
                ->has('fields', 16));
    }

    #[Test]
    public function keeper_can_be_swapped_and_view_mode_passes_through(): void
    {
        $a = $this->player();
        $b = $this->player();

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $a->id, 'b' => $b->id, 'keeper' => $b->id, 'view' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('keeper.id', $b->id)->where('viewOnly', true));
    }

    #[Test]
    public function a_vanished_player_sends_back_to_the_list(): void
    {
        $a = $this->player();

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $a->id, 'b' => 999999]))
            ->assertRedirect(route('players.duplicates.index'))
            ->assertSessionHas('error', 'flash.dup.gone');
    }

    #[Test]
    public function confirming_backs_up_merges_logs_and_opens_the_kept_player(): void
    {
        $keeper = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $duplicate = $this->player(['firstname' => 'Sammy', 'lastname' => 'Amrani']);
        $this->backupWorks();
        $user = $this->merger();

        $this->actingAs($user)
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id, 'choices' => ['firstname' => 'duplicate']])
            ->assertRedirect(route('players.show', $keeper))
            ->assertSessionHas('success', 'flash.dup.merged');

        $this->assertDatabaseMissing('players', ['id' => $duplicate->id]);
        $this->assertSame('Sammy', $keeper->fresh()->firstname);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id, 'action' => ActivityAction::PLAYERS_MERGED, 'subject_id' => $keeper->id,
        ]);
    }

    #[Test]
    public function a_failed_backup_merges_nothing(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->mock(PreMergeBackup::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('No backup folder has been chosen yet.')));

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id])
            ->assertSessionHas('error', 'flash.dup.backup_failed');

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
    }

    #[Test]
    public function a_failed_merge_reports_and_changes_nothing(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->backupWorks();
        $this->mock(PlayerMerger::class, fn (MockInterface $mock) => $mock->shouldReceive('merge')->once()->andThrow(new RuntimeException('boom')));

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id])
            ->assertSessionHas('error', 'flash.dup.merge_failed');

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
    }

    #[Test]
    public function the_keeper_must_be_one_of_the_pair(): void
    {
        $a = $this->player();
        $b = $this->player();
        $c = $this->player();

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $a->id, 'b' => $b->id, 'keeper' => $c->id])
            ->assertSessionHasErrors('keeper');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergePageTest.php`
Expected: FAIL (route not defined).

- [ ] **Step 3: Activity action**

In `app/Services/Activity/ActivityAction.php`, add after `const PLAYER_DELETED = 'player_deleted';`:

```php

    const PLAYERS_MERGED = 'players_merged';
```

Then add `self::PLAYERS_MERGED,` right after **every** `self::PLAYER_DELETED,` occurrence in that file (find them with `grep -n "PLAYER_DELETED" app/Services/Activity/ActivityAction.php` — the `ALL` list and the `players` group at least).

- [ ] **Step 4: Backup service**

`app/Services/Player/Merge/PreMergeBackup.php`:

```php
<?php

namespace App\Services\Player\Merge;

use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A backup before every merge (owner 2026-11-26): the usual backup into the
 * chosen folder; without one, a copy of the SQLite file next to it. If no
 * backup can be written, the merge must not run.
 */
class PreMergeBackup
{
    public function __construct(private BackupService $backups) {}

    /** Path of the backup written. */
    public function run(): string
    {
        try {
            return $this->backups->create();
        } catch (Throwable $e) {
            $config = config('database.connections.'.DB::getDefaultConnection());
            $path = (string) ($config['database'] ?? '');
            if (($config['driver'] ?? null) !== 'sqlite' || $path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || ! is_file($path)) {
                throw $e;
            }

            $target = dirname($path).DIRECTORY_SEPARATOR.'database.pre-merge-'.now()->format('Ymd-His').'.sqlite';

            // Same as PreMigrationBackup: release the app's handle, let SQLite write one consistent copy.
            DB::disconnect();
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare('VACUUM INTO ?')->execute([$target]);
            $pdo = null;

            if (! is_file($target)) {
                throw new RuntimeException("The pre-merge backup could not be written to {$target}.");
            }

            return $target;
        }
    }
}
```

- [ ] **Step 5: Routes + permissions**

In `routes/web.php`, add **above** the `Route::delete('/players/duplicates/{decision}', …)` line from Task 5 (static paths before the wildcard):

```php
    Route::get('/players/duplicates/merge', [PlayerMergeController::class, 'show'])->name('players.duplicates.merge');
    Route::post('/players/duplicates/merge', [PlayerMergeController::class, 'store'])->name('players.duplicates.merge.store');
```

with `use App\Http\Controllers\PlayerMergeController;` at the top. In `config/permissions.php` `overrides`, after the three Task 5 lines:

```php
        'players.duplicates.merge' => ['players', 'merge'],
        'players.duplicates.merge.store' => ['players', 'merge'],
```

- [ ] **Step 6: Controller**

`app/Http/Controllers/PlayerMergeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerDuplicateDecision;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Player\Duplicates\DuplicateFinder;
use App\Services\Player\Merge\FieldMerge;
use App\Services\Player\Merge\MergeClashes;
use App\Services\Player\Merge\MergePreview;
use App\Services\Player\Merge\PlayerMerger;
use App\Services\Player\Merge\PreMergeBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Side-by-side comparison of a duplicate pair and the merge itself. */
class PlayerMergeController extends Controller
{
    public function show(Request $request, FieldMerge $fields, MergeClashes $clashes, MergePreview $preview, DuplicateFinder $finder): Response|RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer'],
            'b' => ['required', 'integer', 'different:a'],
            'keeper' => ['nullable', 'integer'],
            'view' => ['nullable', 'boolean'],
        ]);
        $players = $this->pair((int) $data['a'], (int) $data['b']);
        if ($players === null) {
            return redirect()->route('players.duplicates.index')->with('error', 'flash.dup.gone');
        }
        [$keeper, $duplicate] = $this->sides($players, isset($data['keeper']) ? (int) $data['keeper'] : null);
        [$a, $b] = PlayerDuplicateDecision::orderedPair($keeper->id, $duplicate->id);

        return Inertia::render('Players/MergeDuplicates', [
            'a' => $a,
            'b' => $b,
            'keeper' => PlayerDuplicateDecision::snapshot($keeper),
            'duplicate' => PlayerDuplicateDecision::snapshot($duplicate),
            'fields' => $fields->diff($keeper, $duplicate),
            'counts' => (object) $preview->counts($duplicate->id),
            'clashes' => $clashes->find($keeper->id, $duplicate->id),
            'pair' => $finder->scorePair($a, $b),
            'viewOnly' => (bool) ($data['view'] ?? false),
        ]);
    }

    public function store(Request $request, PreMergeBackup $backup, PlayerMerger $merger, MergePreview $preview): RedirectResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer'],
            'b' => ['required', 'integer', 'different:a'],
            'keeper' => ['required', 'integer', Rule::in([(int) $request->input('a'), (int) $request->input('b')])],
            'choices' => ['nullable', 'array'],
            'choices.*' => [Rule::in(['keeper', 'duplicate'])],
        ]);
        $players = $this->pair((int) $data['a'], (int) $data['b']);
        if ($players === null) {
            return redirect()->route('players.duplicates.index')->with('error', 'flash.dup.gone');
        }
        [$keeper, $duplicate] = $this->sides($players, (int) $data['keeper']);
        $moved = array_sum($preview->counts($duplicate->id));

        try {
            $backup->run();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'flash.dup.backup_failed');
        }

        try {
            $merger->merge($keeper, $duplicate, $data['choices'] ?? [], $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'flash.dup.merge_failed');
        }

        ActivityRecorder::record($request->user(), ActivityAction::PLAYERS_MERGED, $keeper, [
            'merged_player_id' => $duplicate->id,
            'count' => $moved,
        ]);

        return redirect()->route('players.show', $keeper)->with('success', 'flash.dup.merged');
    }

    /** Both players, or null when one no longer exists. */
    private function pair(int $a, int $b): ?Collection
    {
        $players = Player::with(['memberJob', 'wilaya'])->whereIn('id', [$a, $b])->get()->keyBy('id');

        return $players->count() === 2 ? $players : null;
    }

    /** @return array{0: Player, 1: Player} the chosen keeper, else the older record, and the other one */
    private function sides(Collection $players, ?int $keeperId): array
    {
        $keeper = $keeperId !== null && $players->has($keeperId)
            ? $players[$keeperId]
            : $players->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();

        return [$keeper, $players->first(fn (Player $p) => $p->id !== $keeper->id)];
    }
}
```

- [ ] **Step 7: Run the PHP test**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Feature/PlayerMergePageTest.php`
Expected: PASS (8 tests). Then `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit tests/Unit/ActivityCoverageTest.php` (expected: PASS — `PLAYERS_MERGED` is recorded in the controller).

- [ ] **Step 8: Vue page**

`resources/js/Pages/Players/MergeDuplicates.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    a: { type: Number, required: true },
    b: { type: Number, required: true },
    keeper: { type: Object, required: true },
    duplicate: { type: Object, required: true },
    fields: { type: Array, required: true },
    counts: { type: Object, default: () => ({}) },
    clashes: { type: Array, default: () => [] },
    pair: { type: Object, default: () => ({ score: 0, reasons: [] }) },
    viewOnly: { type: Boolean, default: false },
});

const form = useForm({
    a: props.a,
    b: props.b,
    keeper: props.keeper.id,
    choices: Object.fromEntries(props.fields.filter((f) => f.mode === 'choose').map((f) => [f.field, 'keeper'])),
});

const countRows = computed(() => Object.entries(props.counts));

function swapKeeper() {
    router.get(route('players.duplicates.merge'), { a: props.a, b: props.b, keeper: props.duplicate.id, ...(props.viewOnly ? { view: 1 } : {}) });
}

function submit() {
    if (!window.confirm(t('dup.confirm_question'))) return;
    form.post(route('players.duplicates.merge.store'), { preserveScroll: true });
}

function rowClass(field) {
    return field.mode === 'same' ? 'text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-100';
}
</script>

<template>
    <Head :title="t('dup.merge_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('dup.merge_title') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('players.duplicates.index')" class="text-sm text-primary-600 dark:text-primary-300">{{ t('dup.back') }}</Link>
                <span v-if="viewOnly" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('dup.view_only') }}</span>
                <span class="ms-auto rounded bg-rose-50 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ pair.score }}</span>
                <span v-for="reason in pair.reasons" :key="reason" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ t('dup.reason.' + reason) }}</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div v-for="(card, side) in { keeper, duplicate }" :key="side" :class="['rounded-xl border p-3', side === 'keeper'
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20'
                    : 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-900/20']">
                    <div class="flex items-center gap-3">
                        <img v-if="card.picture_url" :src="card.picture_url" alt="" class="h-12 w-12 rounded-full object-cover" />
                        <div class="min-w-0">
                            <Link :href="route('players.show', card.id)" class="block break-words font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300">{{ card.name }}</Link>
                            <span class="block text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ card.membership_ids.join(' · ') }}</span>
                            <span :class="['mt-1 inline-block rounded px-2 py-0.5 text-xs font-medium', side === 'keeper'
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300']">{{ side === 'keeper' ? t('dup.kept_badge') : t('dup.removed_badge') }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="swapKeeper">{{ t('dup.swap_keeper') }}</button>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ t('dup.field_col') }}</th>
                            <th class="px-3 py-2 text-start">{{ keeper.name }}</th>
                            <th class="px-3 py-2 text-start">{{ duplicate.name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="field in fields" :key="field.field" :class="rowClass(field)">
                            <td class="px-3 py-2 font-medium">{{ t('dup.field.' + field.field) }}</td>
                            <td v-for="side in ['keeper', 'duplicate']" :key="side" class="px-3 py-2">
                                <label class="flex items-center gap-2">
                                    <input
                                        v-if="field.mode === 'choose' && !viewOnly"
                                        v-model="form.choices[field.field]"
                                        type="radio"
                                        :name="'choice-' + field.field"
                                        :value="side"
                                        class="text-primary-600 focus:ring-primary-500"
                                    />
                                    <img v-if="field.field === 'picture' && field[side]" :src="field[side]" alt="" class="h-10 w-10 rounded object-cover" />
                                    <span v-else class="break-words">{{ field[side] ?? '—' }}</span>
                                    <span v-if="side === 'duplicate' && field.mode === 'auto'" class="rounded bg-amber-50 px-1.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ t('dup.auto_filled') }}</span>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t border-slate-200 p-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ t('dup.phones_joined') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.moves_title') }}</h2>
                    <ul v-if="countRows.length" class="space-y-1 text-sm text-slate-700 dark:text-slate-200">
                        <li v-for="[table, count] in countRows" :key="table" class="flex justify-between gap-2">
                            <span>{{ t('dup.count.' + table) }}</span><span class="font-semibold">{{ count }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">{{ t('dup.nothing_moves') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold text-slate-900 dark:text-slate-100">{{ t('dup.clashes_title') }}</h2>
                    <ul v-if="clashes.length" class="space-y-1 text-sm text-slate-700 dark:text-slate-200">
                        <li v-for="clash in clashes" :key="clash.kind + clash.keeper_row">
                            {{ t('dup.clash.' + clash.kind, { name: clash.label }) }} —
                            <span class="font-medium">{{ t('dup.kept_from', { name: clash.winner === 'keeper' ? keeper.name : duplicate.name }) }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">{{ t('dup.no_clashes') }}</p>
                </div>
            </div>

            <div v-if="!viewOnly" class="flex justify-end">
                <button type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-50" :disabled="form.processing" @click="submit">{{ t('dup.confirm') }}</button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 9: i18n keys**

Write `C:\Users\MUSTAPHA\AppData\Local\Temp\dup-t8-keys.json`:

```json
{
    "dup.merge_title": { "ar": "دمج لاعبين", "fr": "Fusionner deux joueurs", "en": "Merge two players" },
    "dup.back": { "ar": "العودة إلى المكررين", "fr": "Retour aux doublons", "en": "Back to duplicates" },
    "dup.view_only": { "ar": "مقارنة فقط", "fr": "Comparaison seulement", "en": "Comparison only" },
    "dup.kept_badge": { "ar": "يبقى", "fr": "Conservé", "en": "Stays" },
    "dup.removed_badge": { "ar": "يُحذف بعد الدمج", "fr": "Supprimé après fusion", "en": "Removed after merge" },
    "dup.swap_keeper": { "ar": "الاحتفاظ بالآخر", "fr": "Garder l'autre", "en": "Keep the other one" },
    "dup.field_col": { "ar": "المعلومة", "fr": "Information", "en": "Field" },
    "dup.auto_filled": { "ar": "يُملأ من الآخر", "fr": "Repris de l'autre", "en": "Filled from the other" },
    "dup.phones_joined": { "ar": "تُحفظ أرقام الهاتف من الاثنين.", "fr": "Les numéros des deux sont gardés.", "en": "Phone numbers from both are kept." },
    "dup.moves_title": { "ar": "ينتقل إلى اللاعب الباقي", "fr": "Passe au joueur conservé", "en": "Moves to the kept player" },
    "dup.nothing_moves": { "ar": "لا شيء للنقل.", "fr": "Rien à déplacer.", "en": "Nothing to move." },
    "dup.clashes_title": { "ar": "سجلات موجودة عند الاثنين", "fr": "Enregistrements présents chez les deux", "en": "Records both players have" },
    "dup.no_clashes": { "ar": "لا يوجد تعارض.", "fr": "Aucun conflit.", "en": "No clashes." },
    "dup.kept_from": { "ar": "يبقى سجل: {name}", "fr": "Gardé : {name}", "en": "Kept: {name}" },
    "dup.confirm": { "ar": "تأكيد الدمج", "fr": "Confirmer la fusion", "en": "Confirm merge" },
    "dup.confirm_question": { "ar": "لا يمكن التراجع عن الدمج. ستُحفظ نسخة احتياطية أولاً. هل تتابع؟", "fr": "La fusion est définitive. Une sauvegarde est faite avant. Continuer ?", "en": "A merge cannot be undone. A backup is made first. Continue?" },
    "dup.clash.registration": { "ar": "مسجلان في {name}", "fr": "Inscrits tous deux en {name}", "en": "Both registered in {name}" },
    "dup.clash.document": { "ar": "عند الاثنين «{name}»", "fr": "Les deux ont « {name} »", "en": "Both have “{name}”" },
    "dup.clash.attendance": { "ar": "الحصة نفسها يوم {name}", "fr": "Même séance le {name}", "en": "Same training session on {name}" },
    "dup.clash.team": { "ar": "في قائمة {name}", "fr": "Dans l'effectif {name}", "en": "Both on the roster of {name}" },
    "dup.clash.competition": { "ar": "في المنافسة {name}", "fr": "Dans la compétition {name}", "en": "Both in competition {name}" },
    "dup.clash.game": { "ar": "مباراة يوم {name}", "fr": "Match du {name}", "en": "Same match on {name}" },
    "dup.clash.injury": { "ar": "إصابة تبدأ يوم {name}", "fr": "Blessure débutant le {name}", "en": "Injury starting {name}" },
    "dup.clash.academic_year": { "ar": "السنة الدراسية {name}", "fr": "Année scolaire {name}", "en": "School year {name}" },
    "dup.field.firstname": { "ar": "الاسم", "fr": "Prénom", "en": "First name" },
    "dup.field.lastname": { "ar": "اللقب", "fr": "Nom", "en": "Last name" },
    "dup.field.nickname": { "ar": "الكنية", "fr": "Surnom", "en": "Nickname" },
    "dup.field.father": { "ar": "اسم الأب", "fr": "Prénom du père", "en": "Father's name" },
    "dup.field.grandfather": { "ar": "اسم الجد", "fr": "Prénom du grand-père", "en": "Grandfather's name" },
    "dup.field.birthdate": { "ar": "تاريخ الميلاد", "fr": "Date de naissance", "en": "Birthdate" },
    "dup.field.gender": { "ar": "الجنس", "fr": "Sexe", "en": "Gender" },
    "dup.field.picture": { "ar": "الصورة", "fr": "Photo", "en": "Photo" },
    "dup.field.email": { "ar": "البريد الإلكتروني", "fr": "E-mail", "en": "Email" },
    "dup.field.city": { "ar": "البلدية", "fr": "Commune", "en": "Municipality" },
    "dup.field.wilaya_id": { "ar": "الولاية", "fr": "Wilaya", "en": "Province (wilaya)" },
    "dup.field.is_student": { "ar": "متمدرس", "fr": "Élève / étudiant", "en": "Student" },
    "dup.field.member_job_id": { "ar": "المهنة", "fr": "Profession", "en": "Job" },
    "dup.field.health_medical_conditions": { "ar": "الحالة الصحية", "fr": "Antécédents médicaux", "en": "Medical conditions" },
    "dup.field.health_blood_group_rhesus": { "ar": "فصيلة الدم", "fr": "Groupe sanguin", "en": "Blood group" },
    "dup.field.licence_number": { "ar": "رقم الإجازة", "fr": "Numéro de licence", "en": "Licence number" },
    "dup.count.player_emergency_contacts": { "ar": "جهات الاتصال في الطوارئ", "fr": "Contacts d'urgence", "en": "Emergency contacts" },
    "dup.count.player_achievements": { "ar": "الإنجازات", "fr": "Palmarès", "en": "Achievements" },
    "dup.count.player_subscriptions": { "ar": "الاشتراكات", "fr": "Cotisations", "en": "Subscriptions" },
    "dup.count.board_members": { "ar": "عضوية المكتب", "fr": "Membre du bureau", "en": "Board membership" },
    "dup.count.player_other_positions": { "ar": "مراكز أخرى", "fr": "Autres postes", "en": "Other positions" },
    "dup.count.player_academic_years": { "ar": "السنوات الدراسية", "fr": "Années scolaires", "en": "School years" },
    "dup.count.player_documents": { "ar": "الوثائق", "fr": "Documents", "en": "Documents" },
    "dup.count.attendances": { "ar": "الحضور", "fr": "Présences", "en": "Attendance marks" },
    "dup.count.injury_notes": { "ar": "الإصابات", "fr": "Blessures", "en": "Injuries" },
    "dup.count.team_players": { "ar": "قوائم الفرق", "fr": "Effectifs d'équipe", "en": "Team rosters" },
    "dup.count.competition_players": { "ar": "المنافسات", "fr": "Compétitions", "en": "Competitions" },
    "dup.count.game_players": { "ar": "المباريات", "fr": "Matchs", "en": "Matches" },
    "dup.count.game_events": { "ar": "أحداث المباريات", "fr": "Événements de match", "en": "Match events" },
    "dup.count.suspensions": { "ar": "العقوبات", "fr": "Suspensions", "en": "Suspensions" },
    "dup.count.activity_player": { "ar": "الأنشطة", "fr": "Activités", "en": "Activities" },
    "dup.count.branch_player": { "ar": "التسجيلات في الفروع", "fr": "Inscriptions en section", "en": "Branch registrations" },
    "dup.count.training_group_members": { "ar": "أفواج التدريب", "fr": "Groupes d'entraînement", "en": "Training groups" },
    "dup.count.transactions": { "ar": "المدفوعات", "fr": "Paiements", "en": "Payments" },
    "dup.count.equipment_rentals": { "ar": "المعدات المعارة", "fr": "Matériel prêté", "en": "Equipment loans" },
    "flash.dup.merged": { "ar": "تم دمج اللاعبين.", "fr": "Joueurs fusionnés.", "en": "Players merged." },
    "flash.dup.merge_failed": { "ar": "تعذر الدمج. لم يتغير شيء.", "fr": "La fusion a échoué. Rien n'a changé.", "en": "The merge failed. Nothing changed." },
    "flash.dup.backup_failed": { "ar": "تعذرت النسخة الاحتياطية، فلم يتم الدمج.", "fr": "Sauvegarde impossible : fusion annulée.", "en": "The backup failed, so nothing was merged." },
    "flash.dup.gone": { "ar": "أحد اللاعبين لم يعد موجوداً.", "fr": "L'un des joueurs n'existe plus.", "en": "One of the players no longer exists." },
    "activity.action.players_merged": { "ar": "دمج لاعبين مكررين", "fr": "Joueurs fusionnés", "en": "Players merged" }
}
```

Run: `node scripts/i18n-add.mjs C:/Users/MUSTAPHA/AppData/Local/Temp/dup-t8-keys.json` then `npm run i18n:check` (expected: passes).

- [ ] **Step 10: Build and check in the browser**

Run: `npm run build`. Serve the worktree (`php artisan serve --port=2027 --no-reload`, worktree DB = a copy, never the desktop data). On the duplicates page: 👁 opens the comparison without the confirm button; ↔ opens it with radios where values differ, "Filled from the other" tags, counts and clash lists, "Keep the other one" swaps sides; confirming shows the browser confirm, then lands on the kept player's page with the success flash, and the pair shows under Merged. Check dark mode and 320 px. Stop the server.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Player/Merge/PreMergeBackup.php app/Http/Controllers/PlayerMergeController.php resources/js/Pages/Players/MergeDuplicates.vue app/Services/Activity/ActivityAction.php routes/web.php config/permissions.php tests/Feature/PlayerMergePageTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(players): merge page with backup and activity log"
```

---

### Task 9: Help Center article + full suite

**Files:**
- Create: `resources/help/ar/players/merge-duplicates.md`, `resources/help/fr/players/merge-duplicates.md`, `resources/help/en/players/merge-duplicates.md`
- Modify: `resources/help/topics.php` (players `articles`)

- [ ] **Step 1: Register the article**

In `resources/help/topics.php`, players line: append `'merge-duplicates'` after `'status-history'` in the `articles` array.

- [ ] **Step 2: Write the three articles**

`resources/help/ar/players/merge-duplicates.md`:

```markdown
---
title: كيف أجد اللاعبين المكررين وأدمجهم
summary: ابحث عن لاعب سُجّل مرتين، ثم ادمج البطاقتين في بطاقة واحدة.
pages: [players.index, players.duplicates.index, players.duplicates.merge]
keywords: [مكرر, مكررين, دمج, لاعب مرتين, نفس اللاعب, تكرار]
related: [players/find-player, players/edit-player]
---

أحياناً يُسجَّل اللاعب مرتين. هذه الصفحة تجد البطاقات المتشابهة وتدمجها.

:::example
**فاطمة بن علي** مسجلة مرتين: مرة «فاطمة» ومرة «فاطمه»، بنفس تاريخ الميلاد ونفس الهاتف.
:::

:::step افتح «البحث عن المكررين»
في صفحة اللاعبين، اضغط «البحث عن المكررين».
:::

:::step اختر الشروط
اضغط «الاسم واللقب» و«تاريخ الميلاد» لترى الأزواج المتطابقة فيهما فقط.
:::

:::step افحص الزوج
النقاط الأعلى تعني تشابهاً أكبر. اضغط «مقارنة» لترى البطاقتين جنباً إلى جنب.
:::

:::step ليس مكرراً؟
اضغط «ليس مكرراً». لن يظهر هذا الزوج مرة أخرى.
:::

:::step ادمج البطاقتين
اضغط «دمج». اختر اللاعب الذي يبقى، واختر القيمة الصحيحة لكل معلومة مختلفة.
:::

:::step أكّد الدمج
اضغط «تأكيد الدمج». تُحفظ نسخة احتياطية أولاً، ثم تنتقل كل السجلات إلى اللاعب الباقي.
:::

:::tip
الإخوة يتشاركون رقم الهاتف غالباً. اضغط «ليس مكرراً» لهم مرة واحدة.
:::

:::warning
لا يمكن التراجع عن الدمج. البطاقة الثانية تُحذف نهائياً.
:::
```

`resources/help/fr/players/merge-duplicates.md`:

```markdown
---
title: Comment trouver et fusionner des joueurs en double
summary: Repérer un joueur inscrit deux fois, puis fusionner les deux fiches en une seule.
pages: [players.index, players.duplicates.index, players.duplicates.merge]
keywords: [doublon, doublons, fusionner, fusion, joueur en double, même joueur]
related: [players/find-player, players/edit-player]
---

Il arrive qu'un joueur soit inscrit deux fois. Cette page trouve les fiches qui se ressemblent et les fusionne.

:::example
**فاطمة بن علي** est inscrite deux fois : « فاطمة » et « فاطمه », même date de naissance, même téléphone.
:::

:::step Ouvrez « Rechercher les doublons »
Sur la page des joueurs, cliquez sur « Rechercher les doublons ».
:::

:::step Choisissez les critères
Cliquez sur « Nom et prénom » et « Date de naissance » pour ne voir que les paires identiques sur les deux.
:::

:::step Examinez la paire
Plus le score est haut, plus les fiches se ressemblent. Cliquez sur « Comparer » pour les voir côte à côte.
:::

:::step Ce n'est pas un doublon ?
Cliquez sur « Pas un doublon ». Cette paire ne reviendra plus.
:::

:::step Fusionnez les fiches
Cliquez sur « Fusionner ». Choisissez le joueur conservé, puis la bonne valeur pour chaque information différente.
:::

:::step Confirmez
Cliquez sur « Confirmer la fusion ». Une sauvegarde est faite d'abord, puis tout passe au joueur conservé.
:::

:::tip
Des frères et sœurs partagent souvent le même téléphone. Marquez-les « Pas un doublon » une fois pour toutes.
:::

:::warning
Une fusion est définitive : la seconde fiche est supprimée.
:::
```

`resources/help/en/players/merge-duplicates.md`:

```markdown
---
title: How do I find and merge duplicate players
summary: Spot a player registered twice, then merge the two records into one.
pages: [players.index, players.duplicates.index, players.duplicates.merge]
keywords: [duplicate, duplicates, merge, same player, registered twice, double]
related: [players/find-player, players/edit-player]
---

Sometimes a player gets registered twice. This page finds records that look alike and merges them.

:::example
**فاطمة بن علي** is registered twice: “فاطمة” and “فاطمه”, same birthdate, same phone.
:::

:::step Open “Find duplicates”
On the Players page, click “Find duplicates”.
:::

:::step Pick the criteria
Click “Name” and “Birthdate” to see only pairs that match on both.
:::

:::step Look at the pair
A higher score means a closer match. Click “Compare” to see both records side by side.
:::

:::step Not a duplicate?
Click “Not duplicate”. That pair will not come back.
:::

:::step Merge the records
Click “Merge”. Choose the player who stays, then the right value for each detail that differs.
:::

:::step Confirm
Click “Confirm merge”. A backup is made first, then everything moves to the kept player.
:::

:::tip
Brothers and sisters often share a phone. Mark them “Not duplicate” once.
:::

:::warning
A merge cannot be undone: the second record is deleted.
:::
```

- [ ] **Step 3: Run the help tests**

Run: `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit --filter Help`
Expected: PASS. If a test requires every article to have a `::figure`, add `data-help="dup-find"` on the Players "Find duplicates" action and a shot in `resources/help/shots.js` following the README, then `npm run build && npm run help:shots -- <shot>` plus `--locale=fr` / `--locale=en`, and commit `public/help-shots/{ar,fr,en}/*`.

- [ ] **Step 4: Full suite + build**

Run: `npm run i18n:check`, `npm run build`, then `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit` (one process only).
Expected: all green. Fix any failure before committing (an existing test asserting the canonical players actions or listing every route in `config/permissions.php` is the likeliest).

- [ ] **Step 5: Commit**

```bash
git add resources/help/topics.php resources/help/ar/players/merge-duplicates.md resources/help/fr/players/merge-duplicates.md resources/help/en/players/merge-duplicates.md
git commit -m "docs(help): find and merge duplicate players"
```

---

## Deploy notes (for the owner, after merge)

- `php artisan migrate` (decisions table + `players → merge` for system roles).
- Grant `Players → Merge duplicates` to the roles that should merge (Settings → Roles).
- Set a backup folder (Settings → Backups) so each merge writes a normal backup; without one, SQLite installs get a `database.pre-merge-*.sqlite` copy next to the database.
- Desktop: new build needed to ship the feature.
