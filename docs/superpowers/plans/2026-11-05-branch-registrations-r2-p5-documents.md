# Branch registrations, Release 2, Part 5: Documents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track each document per registration, so a two-branch player's football papers no longer settle swimming, a left registration owes nothing, and one profile picture settles Photo everywhere.

**Architecture:** `player_documents` gains `branch_registration_id`. `DocumentChecklist` stays the one rule set in its PHP and SQL forms. Its SQL terms now count *rows*: one per active registration, or one for a player with no registration at all. `DocumentStats`, the Players list, `PlayerFlags` and the report all read those terms. The player page shows one tab per registration through `BranchSwitch`.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia, Tailwind. Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`, section 8.

**Global Constraints:** see the main plan `2026-11-05-branch-registrations-r2.md`, which apply in full. Parts 0 to 4 are done before this part. Parts 6 and 7 are not.

**Part-specific constraints:**

- **No foreign key on `branch_registration_id`.** Adding one on SQLite rebuilds the table inside the migration's transaction, where `PRAGMA foreign_keys` is ignored, so the drop would cascade away `player_document_files`. A column with a foreign key also can't be dropped on SQLite. This follows `2026_10_23_300001_add_branch_to_injury_notes`. `registrations:verify` reports a row pointing nowhere.
- A player with no registration keeps `branch_registration_id` NULL. NULLs never collide in a unique index, so for those players "one row per type" is held by the writers (the `store` check and `firstOrNew` in `exempt`) and by `registrations:verify`. A virtual key column isn't used, because it needs PostgreSQL 18.
- SQL terms use a subquery for the `left` status, not a lookup, so no read is added per call. `DocumentStatsTest` pins the query counts.
- A player with no registration has their own row in every term, **even with a branch given**. A team's roster may hold such a player, and they have no branch to be excluded from.
- **Expected difference for one-branch players:** a player who left their only branch used to show missing documents and now shows none (spec section 8, "a left registration counts as nothing missing"). Report it in `.superpowers/r2/differences.md`.
- The "bars can exceed the total" note on the documents chart belongs to Part 6, with the other category charts. This part only supplies the figures.

---

### Task P5.1: The column, the backfill and who owns a new row

**Files:**
- Create: `database/migrations/2026_11_05_500001_add_registration_to_player_documents.php`
- Create: `tests/Feature/Registrations/DocumentRegistrationMigrationTest.php`
- Modify: `app/Models/PlayerDocument.php`
- Modify: `app/Models/BranchRegistration.php`
- Modify: `tests/Feature/PlayerDocumentModelTest.php` (`a_player_has_at_most_one_row_per_type`)

**Interfaces:**
- Consumes: `RegistrationService::primary()`, `RegistrationFixtures`.
- Produces: column `player_documents.branch_registration_id`; `PlayerDocument::registration(): BelongsTo`; `BranchRegistration::documents(): HasMany`. A `PlayerDocument` created without naming a registration goes to the player's main one. A player's first registration adopts their rows with no registration.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DocumentRegistrationMigrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\PlayerDocumentFile;
use App\Services\Player\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentRegistrationMigrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private const MIGRATION = 'migrations/2026_11_05_500001_add_registration_to_player_documents.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function typeId(string $code = 'birth_certificate'): int
    {
        return DocumentType::where('code', $code)->value('id');
    }

    private function row(Player $player, ?int $registrationId, string $code = 'birth_certificate'): int
    {
        return DB::table('player_documents')->insertGetId([
            'player_id' => $player->id,
            'branch_registration_id' => $registrationId,
            'document_type_id' => $this->typeId($code),
            'state' => 'received',
            'received_at' => '2026-09-01',
        ]);
    }

    private function runUp(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    #[Test]
    public function existing_rows_go_to_the_main_registration_and_it_can_run_twice(): void
    {
        $both = $this->bothActive();
        $leftMain = $this->leftFootball();
        $flat = $this->noRegistration();
        $ids = [$this->row($both, null), $this->row($leftMain, null), $this->row($flat, null)];

        $this->runUp();
        $this->runUp();

        $service = app(RegistrationService::class);
        $this->assertSame($service->primary($both)->id, (int) DB::table('player_documents')->where('id', $ids[0])->value('branch_registration_id'));
        $this->assertSame($service->primary($leftMain)->id, (int) DB::table('player_documents')->where('id', $ids[1])->value('branch_registration_id'));
        $this->assertSame($this->swim->id, $service->primary($leftMain)->branch_id);
        $this->assertNull(DB::table('player_documents')->where('id', $ids[2])->value('branch_registration_id'));
    }

    #[Test]
    public function the_old_key_is_gone_and_the_new_one_holds(): void
    {
        $player = $this->bothActive();
        $foot = $player->registrationIn($this->foot->id)->id;
        $swim = $player->registrationIn($this->swim->id)->id;

        $this->row($player, $foot);
        $this->row($player, $swim);
        $this->assertSame(2, PlayerDocument::count());

        $this->expectException(QueryException::class);
        $this->row($player, $foot);
    }

    #[Test]
    public function rolling_back_keeps_one_row_per_type_and_every_file(): void
    {
        $player = $this->bothActive();
        $first = $this->row($player, $player->registrationIn($this->foot->id)->id);
        $second = $this->row($player, $player->registrationIn($this->swim->id)->id);
        DB::table('player_document_files')->insert([
            ['player_document_id' => $first, 'path' => 'a', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1],
            ['player_document_id' => $second, 'path' => 'b', 'original_name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 1],
        ]);

        (require database_path(self::MIGRATION))->down();

        $this->assertFalse(Schema::hasColumn('player_documents', 'branch_registration_id'));
        $this->assertSame([$first], DB::table('player_documents')->pluck('id')->all());
        $this->assertSame(2, PlayerDocumentFile::where('player_document_id', $first)->count());

        $this->runUp();
        $this->assertSame(app(RegistrationService::class)->primary($player)->id, (int) DB::table('player_documents')->value('branch_registration_id'));
    }

    #[Test]
    public function a_new_row_naming_no_registration_goes_to_the_main_one(): void
    {
        $player = $this->leftFootball();

        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $this->assertSame($player->registrationIn($this->swim->id)->id, $document->branch_registration_id);
        $this->assertTrue($document->registration->is($player->registrationIn($this->swim->id)));
    }

    #[Test]
    public function a_row_for_a_player_with_no_registration_stays_blank(): void
    {
        $document = PlayerDocument::create(['player_id' => $this->noRegistration()->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $this->assertNull($document->branch_registration_id);
    }

    #[Test]
    public function a_first_registration_adopts_the_rows_with_none_and_a_second_does_not(): void
    {
        $player = $this->noRegistration();
        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $first = app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);
        $this->assertSame($first->id, $document->fresh()->branch_registration_id);

        app(RegistrationService::class)->register($player, ['branch_id' => $this->swim->id]);
        $this->assertSame($first->id, $document->fresh()->branch_registration_id);
    }

    #[Test]
    public function a_bare_attach_adopts_them_too(): void
    {
        $player = $this->noRegistration();
        $document = PlayerDocument::create(['player_id' => $player->id, 'document_type_id' => $this->typeId(), 'state' => 'received']);

        $player->branches()->attach($this->foot->id);

        $this->assertSame($player->registrations()->value('id'), $document->fresh()->branch_registration_id);
    }

    #[Test]
    public function a_registration_lists_its_documents(): void
    {
        $player = $this->bothActive();
        $this->row($player, $player->registrationIn($this->swim->id)->id);

        $this->assertCount(1, $player->registrationIn($this->swim->id)->documents);
        $this->assertCount(0, $player->registrationIn($this->foot->id)->documents);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/DocumentRegistrationMigrationTest.php`
Expected: FAIL. The migration file is missing, or the `branch_registration_id` column doesn't exist.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_11_05_500001_add_registration_to_player_documents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents per registration. Existing rows go to the player's main registration,
 * so a two-branch player's other branch starts with its documents missing, as the
 * owner chose. A player with no registration keeps a blank value.
 *
 * No foreign key: on SQLite one would rebuild this table inside the migration's
 * transaction, where foreign_keys can't be switched off, and the drop would
 * cascade away player_document_files. registrations:verify reports a row that
 * points nowhere.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL is not
 * transactional) finishes on the next run, and a second run changes nothing.
 * Literal values, not the models: a migration must not move when the code does.
 */
return new class extends Migration
{
    private const UNIQUE = 'player_documents_registration_type_unique';

    private const OLD_UNIQUE = 'player_documents_player_id_document_type_id_unique';

    private const PLAYER_INDEX = 'player_documents_player_id_index';

    public function up(): void
    {
        if (! Schema::hasColumn('player_documents', 'branch_registration_id')) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->unsignedBigInteger('branch_registration_id')->nullable());
        }
        // MySQL's player_id foreign key must keep an index once the old key is gone.
        if (! Schema::hasIndex('player_documents', self::PLAYER_INDEX)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->index('player_id', self::PLAYER_INDEX));
        }

        $this->backfill();

        if (! Schema::hasIndex('player_documents', self::UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->unique(['branch_registration_id', 'document_type_id'], self::UNIQUE));
        }
        if (Schema::hasIndex('player_documents', self::OLD_UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
        }
    }

    public function backfill(): void
    {
        $mainBranch = DB::table('website_configs')->value('main_branch_id');
        $left = DB::table('player_statuses')->where('code', 'left')->value('id');

        DB::table('branch_player')
            ->whereIn('player_id', DB::table('player_documents')->whereNull('branch_registration_id')->select('player_id'))
            ->orderBy('id')
            ->get(['id', 'player_id', 'branch_id', 'status_id', 'join_date', 'legacy_join_year'])
            ->groupBy('player_id')
            ->each(function (Collection $rows, $playerId) use ($mainBranch, $left) {
                DB::table('player_documents')
                    ->where('player_id', $playerId)
                    ->whereNull('branch_registration_id')
                    ->update(['branch_registration_id' => $this->mainOf($rows, $mainBranch, $left)->id]);
            });
    }

    /** RegistrationService::primary()'s rule, kept here so this migration never moves. */
    private function mainOf(Collection $rows, mixed $mainBranch, mixed $left): object
    {
        $active = $rows->reject(fn ($r) => $left !== null && (int) $r->status_id === (int) $left);

        return $active->first(fn ($r) => $mainBranch !== null && (int) $r->branch_id === (int) $mainBranch)
            ?? $active->sortBy(fn ($r) => [
                $r->join_date !== null ? substr((string) $r->join_date, 0, 10)
                    : ($r->legacy_join_year !== null ? $r->legacy_join_year.'-12-31' : '9999-12-31'),
                $r->id,
            ])->first()
            ?? $rows->first();
    }

    /**
     * Back to one row per player and type. The oldest row stays, and the others'
     * files move onto it, so no scan loses its record.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('player_documents', 'branch_registration_id')) {
            return;
        }

        DB::table('player_documents')->orderBy('id')->get(['id', 'player_id', 'document_type_id'])
            ->groupBy(fn ($row) => $row->player_id.'-'.$row->document_type_id)
            ->each(function (Collection $rows) {
                $extra = $rows->slice(1)->pluck('id');
                if ($extra->isNotEmpty()) {
                    DB::table('player_document_files')->whereIn('player_document_id', $extra)->update(['player_document_id' => $rows->first()->id]);
                    DB::table('player_documents')->whereIn('id', $extra)->delete();
                }
            });

        if (Schema::hasIndex('player_documents', self::UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropUnique(self::UNIQUE));
        }
        if (! Schema::hasIndex('player_documents', self::OLD_UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->unique(['player_id', 'document_type_id'], self::OLD_UNIQUE));
        }
        if (Schema::hasIndex('player_documents', self::PLAYER_INDEX)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropIndex(self::PLAYER_INDEX));
        }
        Schema::table('player_documents', fn (Blueprint $t) => $t->dropColumn('branch_registration_id'));
    }
};
```

- [ ] **Step 4: Change the two models**

In `app/Models/PlayerDocument.php`, add the import `use App\Services\Player\RegistrationService;`, add `'branch_registration_id',` to `$fillable` after `'player_id',`, and add before `casts()`:

```php
    /** A row that names no registration belongs to the main one. A player with none keeps it blank. */
    protected static function booted(): void
    {
        static::creating(function (PlayerDocument $document) {
            if (! array_key_exists('branch_registration_id', $document->getAttributes())) {
                $document->branch_registration_id = app(RegistrationService::class)->primary($document->player)?->id;
            }
        });
    }
```

Add after `player()`:

```php
    public function registration(): BelongsTo
    {
        return $this->belongsTo(BranchRegistration::class, 'branch_registration_id');
    }
```

Update the class comment's first sentence to: `One document of one player's registration (of the player alone when they have none): received (with its dates) or exempt (with a reason).`

In `app/Models/BranchRegistration.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;`. Inside `booted()`, after the `creating` listener, add:

```php
        // A player's first registration takes over the documents held before it.
        static::created(function (BranchRegistration $registration) {
            DB::table('player_documents')
                ->where('player_id', $registration->player_id)
                ->whereNull('branch_registration_id')
                ->update(['branch_registration_id' => $registration->id]);
        });
```

Add after `sportingStatus()`:

```php
    public function documents(): HasMany
    {
        return $this->hasMany(PlayerDocument::class, 'branch_registration_id');
    }
```

`DB` is already imported there since Part 1.

- [ ] **Step 5: Update the one test this rule changes**

In `tests/Feature/PlayerDocumentModelTest.php`, replace `a_player_has_at_most_one_row_per_type` with:

```php
    #[Test]
    public function a_registration_has_at_most_one_row_per_type(): void
    {
        $player = $this->player();
        $player->branches()->attach(Branch::create(['name' => 'Football'])->id);
        $this->document($player);

        $this->expectException(QueryException::class);
        $this->document($player);
    }
```

Add `use App\Models\Branch;` to its imports.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DocumentRegistrationMigrationTest.php tests/Feature/PlayerDocumentModelTest.php tests/Feature/DocumentChecklistTest.php`
Expected: all PASS. `DocumentChecklistTest` still passes because its players have no registration, and `player_id` still finds their rows.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_05_500001_add_registration_to_player_documents.php app/Models/PlayerDocument.php app/Models/BranchRegistration.php tests/Feature/Registrations/DocumentRegistrationMigrationTest.php tests/Feature/PlayerDocumentModelTest.php
git commit -m "feat(documents): each document belongs to a registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.2: `DocumentChecklist` per registration, in PHP and SQL

**Files:**
- Create: `tests/Feature/Registrations/DocumentChecklistRegistrationTest.php`
- Modify: `app/Services/Player/DocumentChecklist.php`

**Interfaces:**
- Consumes: `PlayerDocument.branch_registration_id`, `RegistrationService::primary()`.
- Produces:
  - `DocumentChecklist::for(Player $player, ?CarbonInterface $today = null, ?BranchRegistration $registration = null): array{registration_id: ?int, left: bool, items: list, missing_count: int, expiring_count: int}`. With no registration given: the main one, or the player alone if they have none.
  - `DocumentChecklist::forPlayer(Player $player, ?CarbonInterface $today = null): array`, which is the main tab's fields plus `tabs: list<array{registration_id, branch_id, branch_name, logo_url, left, items, missing_count, expiring_count}>`, main first, empty for a player with no registration.
  - `DocumentChecklist::evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null, bool $left = false): array`. New reason `left`.
  - `DocumentChecklist::missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null, ?int $branchId = null)`: the sum over active registrations.
  - `DocumentChecklist::missingTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null)`: 1/0, missing on any of them.
  - `DocumentChecklist::missingRowsSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false)`, and `requiredTypeSql` and `expiringTypeSql` with the same two added parameters: counts of rows. `$joinedRow` limits the count to a registration joined as `reg`.
  - `DocumentChecklist::expiringSoonSql(?CarbonInterface $today = null, ?int $branchId = null)`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DocumentChecklistRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentChecklistRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        // One required type keeps the counts readable.
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true]);
    }

    private function received(Player $player, ?Branch $branch, ?string $validUntil = null, ?DocumentType $type = null): PlayerDocument
    {
        return PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $branch === null ? null : $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => ($type ?? $this->medical)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => now()->subMonth()->toDateString(),
            'valid_until' => $validUntil,
        ]);
    }

    private function checklist(Player $player, ?Branch $branch = null): array
    {
        $registration = $branch === null ? null : $player->registrations()->where('branch_id', $branch->id)->firstOrFail();

        return DocumentChecklist::for($player, null, $registration);
    }

    private function missingSql(Player $player, ?Branch $branch = null): int
    {
        [$sql, $bindings] = DocumentChecklist::missingCountSql(null, null, $branch?->id);

        return (int) Player::query()->whereKey($player->id)->selectRaw("{$sql} as n", $bindings)->toBase()->first()->n;
    }

    /** @param array{0: string, 1: array<int, mixed>} $term */
    private function term(Player $player, array $term): int
    {
        return (int) Player::query()->whereKey($player->id)->selectRaw("{$term[0]} as n", $term[1])->toBase()->first()->n;
    }

    private function expiringSql(Player $player, ?Branch $branch = null): bool
    {
        [$sql, $bindings] = DocumentChecklist::expiringSoonSql(null, $branch?->id);

        return Player::query()->whereKey($player->id)->whereRaw($sql, $bindings)->exists();
    }

    #[Test]
    public function a_document_settles_only_its_own_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertSame(0, $this->checklist($player, $this->foot)['missing_count']);
        $this->assertSame(1, $this->checklist($player, $this->swim)['missing_count']);
        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
        $this->assertSame(1, $this->missingSql($player));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingTypeSql($this->medical)));
        $this->assertSame(0, $this->term($player, DocumentChecklist::missingTypeSql($this->medical, null, $this->foot->id)));
    }

    #[Test]
    public function club_wide_count_adds_up_the_active_registrations(): void
    {
        $player = $this->bothActive();

        $this->assertSame(2, $this->missingSql($player));
        $this->assertSame(1, $this->term($player, DocumentChecklist::missingTypeSql($this->medical)));
        $this->assertSame(2, $this->term($player, DocumentChecklist::missingRowsSql($this->medical)));
        $this->assertSame(2, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
    }

    #[Test]
    public function a_left_registration_owes_nothing(): void
    {
        $player = $this->leftFootball();

        $list = $this->checklist($player, $this->foot);
        $this->assertTrue($list['left']);
        $this->assertSame(0, $list['missing_count']);
        $this->assertSame(DocumentChecklist::NOT_REQUIRED, $list['items'][0]['state']);
        $this->assertSame('left', $list['items'][0]['reason']);

        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
        $this->assertSame(1, $this->missingSql($player));
    }

    #[Test]
    public function left_everywhere_owes_nothing_anywhere(): void
    {
        $player = $this->leftBoth();

        $this->assertSame(0, $this->missingSql($player));
        $this->assertSame(0, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
        $this->assertSame(0, DocumentChecklist::for($player)['missing_count']);
    }

    #[Test]
    public function an_expired_paper_on_a_left_registration_is_shown_but_not_counted(): void
    {
        $player = $this->leftFootball();
        $this->received($player, $this->foot, now()->subDay()->toDateString());

        $list = $this->checklist($player, $this->foot);

        $this->assertSame(DocumentChecklist::EXPIRED, $list['items'][0]['state']);
        $this->assertFalse($list['items'][0]['counts_missing']);
        $this->assertSame(0, $list['missing_count']);
    }

    #[Test]
    public function a_player_with_no_registration_uses_their_own_rows_even_with_a_branch_given(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame(1, $this->checklist($flat)['missing_count']);
        $this->assertSame(1, $this->missingSql($flat));
        $this->assertSame(1, $this->missingSql($flat, $this->foot));

        $this->received($flat, null);

        $this->assertSame(0, $this->checklist($flat)['missing_count']);
        $this->assertSame(0, $this->missingSql($flat));
        $this->assertSame(0, $this->missingSql($flat, $this->foot));
    }

    #[Test]
    public function one_picture_settles_photo_on_every_registration(): void
    {
        $photo = DocumentType::where('code', DocumentType::PHOTO)->firstOrFail();
        $photo->update(['is_active' => true]);
        $this->medical->update(['is_active' => false]);
        $player = $this->bothActive();

        $this->assertSame(2, $this->missingSql($player));

        Player::query()->whereKey($player->id)->update(['picture_url' => '/media/players/1.jpg']);
        $player->refresh();

        foreach ([$this->foot, $this->swim] as $branch) {
            $this->assertSame(DocumentChecklist::RECEIVED_SCANNED, $this->checklist($player, $branch)['items'][0]['state']);
            $this->assertSame(0, $this->missingSql($player, $branch));
        }
        $this->assertSame(0, $this->missingSql($player));
    }

    #[Test]
    public function without_a_picture_a_paper_photo_settles_only_its_registration(): void
    {
        $photo = DocumentType::where('code', DocumentType::PHOTO)->firstOrFail();
        $photo->update(['is_active' => true]);
        $this->medical->update(['is_active' => false]);
        $player = $this->bothActive();
        $this->received($player, $this->foot, null, $photo);

        $this->assertSame(0, $this->missingSql($player, $this->foot));
        $this->assertSame(1, $this->missingSql($player, $this->swim));
    }

    #[Test]
    public function an_exemption_holds_on_its_registration_only(): void
    {
        $player = $this->bothActive();
        PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $this->foot->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => 'Doctor',
        ]);

        $this->assertSame(0, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical, null, $this->foot->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical, null, $this->swim->id)));
        $this->assertSame(1, $this->term($player, DocumentChecklist::requiredTypeSql($this->medical)));
    }

    #[Test]
    public function expiring_soon_is_per_registration_and_ignores_a_left_one(): void
    {
        $soon = now()->addDays(10)->toDateString();

        $both = $this->bothActive();
        $this->received($both, $this->swim, $soon);
        $this->assertTrue($this->expiringSql($both));
        $this->assertTrue($this->expiringSql($both, $this->swim));
        $this->assertFalse($this->expiringSql($both, $this->foot));
        $this->assertSame(1, $this->checklist($both, $this->swim)['expiring_count']);
        $this->assertSame(1, $this->term($both, DocumentChecklist::expiringTypeSql($this->medical)));

        $left = $this->leftFootball();
        $this->received($left, $this->foot, $soon);
        $this->assertFalse($this->expiringSql($left));
        $this->assertSame(0, $this->checklist($left, $this->foot)['expiring_count']);
        $this->assertSame(0, $this->term($left, DocumentChecklist::expiringTypeSql($this->medical)));

        $flat = $this->noRegistration();
        $this->received($flat, null, $soon);
        $this->assertTrue($this->expiringSql($flat));
    }

    #[Test]
    public function the_page_gets_one_tab_per_registration_with_the_main_one_first(): void
    {
        DB::table('website_configs')->update(['main_branch_id' => $this->swim->id]);
        $player = $this->leftFootball();

        $page = DocumentChecklist::forPlayer($player);

        $this->assertSame([$this->swim->id, $this->foot->id], array_column($page['tabs'], 'branch_id'));
        $this->assertSame(['Swimming', 'Football'], array_column($page['tabs'], 'branch_name'));
        $this->assertSame([false, true], array_column($page['tabs'], 'left'));
        $this->assertSame($page['tabs'][0]['registration_id'], $page['registration_id']);
        $this->assertSame($page['tabs'][0]['items'], $page['items']);
    }

    #[Test]
    public function a_player_with_no_registration_has_no_tabs(): void
    {
        $page = DocumentChecklist::forPlayer($this->noRegistration());

        $this->assertSame([], $page['tabs']);
        $this->assertNull($page['registration_id']);
        $this->assertFalse($page['left']);
        $this->assertSame(1, $page['missing_count']);
    }

    #[Test]
    public function an_inactive_type_shows_only_on_the_registration_that_holds_it(): void
    {
        $old = DocumentType::create(['code' => 'old', 'name' => 'Old', 'validity' => 'none', 'is_required' => true, 'is_active' => false]);
        $player = $this->bothActive();
        $this->received($player, $this->swim, null, $old);

        $this->assertCount(1, $this->checklist($player, $this->foot)['items']);
        $this->assertCount(2, $this->checklist($player, $this->swim)['items']);
    }

    #[Test]
    public function the_sql_runs_when_the_left_status_is_missing(): void
    {
        $player = $this->bothActive();
        DB::table('player_statuses')->where('code', 'left')->delete();

        $this->assertSame(2, $this->missingSql($player));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/DocumentChecklistRegistrationTest.php`
Expected: FAIL, for example `Undefined array key "left"` or a wrong count, because `for()` still ignores the registration.

- [ ] **Step 3: Rewrite the PHP form**

In `app/Services/Player/DocumentChecklist.php`, add imports `use App\Models\BranchRegistration;`. Replace the class comment's first paragraph with:

```php
/**
 * Which documents a player's registration has, lacks, or no longer needs. A
 * player with no registration holds them alone. A left registration owes nothing.
 *
 * Two forms of ONE rule set:
 *  - for() / evaluate(): the per-document states the player page shows;
 *  - missingCountSql() / expiringSoonSql(): the same rules as SQL, so the
 *    players list can count and filter over every player without loading
 *    their documents into PHP.
 *  - requiredTypeSql() / expiringTypeSql() / missingRowsSql(): per-type counts,
 *    summed by DocumentStats into completion figures. They count rows: one per
 *    active registration (only that branch's when given), or one for a player
 *    with no registration at all. missingTypeSql() says 1/0 for "any of them".
```

Keep the remainder of that comment (`DocumentChecklistTest runs both...` and the `Dates:` paragraph).

Replace `for()` and `evaluate()` with:

```php
    /**
     * One registration's checklist (the main one when none is named; the player
     * alone when they have none): every active type, plus any inactive type it
     * still has a record for (shown greyed, never counted).
     *
     * @return array{registration_id: ?int, left: bool, items: list<array<string, mixed>>, missing_count: int, expiring_count: int}
     */
    public static function for(Player $player, ?CarbonInterface $today = null, ?BranchRegistration $registration = null): array
    {
        $today = self::today($today);
        $registration ??= app(RegistrationService::class)->primary($player);
        $left = $registration !== null && Player::isLeftStatus($registration->status_id);

        $documents = $player->documents()
            ->where('branch_registration_id', $registration?->id)
            ->with(['files.uploadedBy:id,name', 'recordedBy:id,name'])
            ->get()
            ->keyBy('document_type_id');

        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $documents->keys()->all()))
            ->ordered()
            ->get();

        $items = $types
            ->map(fn (DocumentType $type) => self::item($type, $documents->get($type->id), $player, $today, $left))
            ->values()
            ->all();

        return [
            'registration_id' => $registration?->id,
            'left' => $left,
            'items' => $items,
            'missing_count' => count(array_filter($items, fn (array $item) => $item['counts_missing'])),
            'expiring_count' => $left ? 0 : count(array_filter(
                $items,
                fn (array $item) => $item['state'] === self::EXPIRES_SOON && $item['type']['is_active']
            )),
        ];
    }

    /**
     * What the player page needs: the main registration's checklist, plus one tab
     * per registration, main first. A player with no registration has no tabs.
     *
     * @return array<string, mixed>
     */
    public static function forPlayer(Player $player, ?CarbonInterface $today = null): array
    {
        $main = app(RegistrationService::class)->primary($player);

        $tabs = $player->registrations()->with('branch')->orderBy('id')->get()
            ->sortBy(fn (BranchRegistration $r) => $r->id === $main?->id ? 0 : 1)
            ->values()
            ->map(fn (BranchRegistration $r) => [
                'branch_id' => $r->branch_id,
                'branch_name' => $r->branch?->localized_name,
                'logo_url' => $r->branch?->logo_url,
                ...self::for($player, $today, $r),
            ])
            ->all();

        return [...($tabs[0] ?? self::for($player, $today)), 'tabs' => $tabs];
    }

    /**
     * The state of one type for one player. See the rule table in the P3 plan
     * (Task 6); the order of the checks below IS that table. On a left
     * registration nothing is expected, so nothing counts as missing.
     *
     * @return array{state: string, reason: ?string, counts_missing: bool}
     */
    public static function evaluate(DocumentType $type, ?PlayerDocument $document, Player $player, ?CarbonInterface $today = null, bool $left = false): array
    {
        $today = self::today($today);
        $applies = $type->appliesTo($player, $today);
        $expected = ! $left && $type->is_active && $type->is_required && $applies;

        // Owner decision: the profile picture counts as the Photo, on every
        // registration, whatever its record says. Without a picture the Photo is
        // a document like any other, so a paper photo can be marked received by hand.
        if ($type->isPhoto()) {
            $picture = $player->getAttributes()['picture_url'] ?? null;

            if ($picture !== null && $picture !== '') {
                return self::result(self::RECEIVED_SCANNED);
            }
        }

        if ($document === null) {
            return $left ? self::result(self::NOT_REQUIRED, 'left') : self::absent($applies, $expected);
        }

        if ($document->state === PlayerDocument::EXEMPT) {
            return self::result(self::NOT_REQUIRED, 'exempt');
        }

        $validUntil = $document->valid_until;

        if ($validUntil !== null && $validUntil->lt($today)) {
            return self::result(self::EXPIRED, null, $expected);
        }

        if ($validUntil !== null && $validUntil->lt($today->addDays(self::EXPIRES_SOON_DAYS + 1))) {
            return self::result(self::EXPIRES_SOON);
        }

        return self::result($document->files->isNotEmpty() ? self::RECEIVED_SCANNED : self::RECEIVED_PAPER);
    }
```

Change `item()`'s signature and its evaluate call:

```php
    private static function item(DocumentType $type, ?PlayerDocument $document, Player $player, CarbonImmutable $today, bool $left = false): array
```

```php
            ...self::evaluate($type, $document, $player, $today, $left),
```

- [ ] **Step 4: Rewrite the SQL form**

Replace `missingCountSql`, `missingTypeSql`, `expiringSoonSql`, `requiredTypeSql`, `expiringTypeSql` and `missingTermSql` with:

```php
    /**
     * How many documents a player is missing, as an SQL expression over
     * `players`: for()['missing_count'] added up over their active registrations,
     * or for that one branch's. With $onlyTypeId it counts that single type (the
     * "missing type X" filter).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingCountSql(?CarbonInterface $today = null, ?int $onlyTypeId = null, ?int $branchId = null): array
    {
        $today = self::today($today);

        $types = DocumentType::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->when($onlyTypeId !== null, fn ($query) => $query->whereKey($onlyTypeId))
            ->orderBy('id')
            ->get();

        if ($types->isEmpty()) {
            return ['0', []];
        }

        $terms = [];
        $bindings = [];

        foreach ($types as $type) {
            [$term, $termBindings] = self::missingRowsSql($type, $today, $branchId);
            $terms[] = $term;
            array_push($bindings, ...$termBindings);
        }

        return ['('.implode(' + ', $terms).')', $bindings];
    }

    /**
     * One document type's "missing" test as an SQL expression over `players`
     * (1 = missing on an active registration, or on that branch's; 0 = settled),
     * for lists that need to know WHICH types are missing (match eligibility).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null): array
    {
        [$rows, $bindings] = self::missingRowsSql($type, $today, $branchId);

        return ["(CASE WHEN {$rows} > 0 THEN 1 ELSE 0 END)", $bindings];
    }

    /**
     * How many of the player's rows lack this type: the term missingCountSql() sums.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function missingRowsSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        $today = self::today($today);
        [$conditions, $bindings] = self::ageConditions($type, $today);

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
        }

        $unsettled = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND (pd.state = 'exempt' OR (pd.state = 'received'"
            .' AND (pd.valid_until IS NULL OR pd.valid_until >= ?))))';

        return self::whenPlayer($conditions, $bindings, self::rowsSql($unsettled, [$type->id, $today->toDateString()], $branchId, $joinedRow));
    }

    /**
     * "Has an active document that expires within 30 days", as an SQL condition
     * over `players`: on an active registration, or on that branch's. The twin of
     * for()['expiring_count'] > 0.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringSoonSql(?CarbonInterface $today = null, ?int $branchId = null): array
    {
        $today = self::today($today);

        return [
            'EXISTS (SELECT 1 FROM player_documents pd'
                .' INNER JOIN document_types dt ON dt.id = pd.document_type_id'
                .' WHERE pd.player_id = players.id AND dt.is_active = TRUE'
                // A profile picture settles the Photo, so its record cannot expire.
                ." AND (dt.code <> ? OR players.picture_url IS NULL OR players.picture_url = '')"
                ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?"
                .' AND (pd.branch_registration_id IS NULL OR pd.branch_registration_id IN (SELECT r.id FROM branch_player r'
                .' WHERE r.player_id = players.id AND '.self::ACTIVE_ROW
                .($branchId !== null ? ' AND r.branch_id = ?' : '')
                .')))',
            [
                DocumentType::PHOTO,
                $today->toDateString(),
                $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
                ...($branchId !== null ? [$branchId] : []),
            ],
        ];
    }

    /**
     * One type's "required" test as a count of the player's rows: the twin of
     * evaluate(): active, required, applies to the player's age, and not exempted.
     * A profile picture settles the Photo whatever its record says, so a picture
     * beats an exempt record (the age limit still applies). Inactive and optional
     * types are never required.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function requiredTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        [$conditions, $bindings] = self::ageConditions($type, self::today($today));

        $notExempt = 'NOT EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND pd.state = 'exempt')";
        $test = $type->isPhoto()
            ? "((players.picture_url IS NOT NULL AND players.picture_url <> '') OR {$notExempt})"
            : $notExempt;

        return self::whenPlayer($conditions, $bindings, self::rowsSql($test, [$type->id], $branchId, $joinedRow));
    }

    /**
     * One type's "required and expires within 30 days" test as a count of the
     * player's rows: the twin of evaluate() returning EXPIRES_SOON for a required
     * document. A received record valid today is never missing, and one record per
     * registration and type means it is not exempt either. A Photo settled by a
     * picture never expires.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function expiringTypeSql(DocumentType $type, ?CarbonInterface $today = null, ?int $branchId = null, bool $joinedRow = false): array
    {
        if (! $type->is_active || ! $type->is_required) {
            return ['0', []];
        }

        $today = self::today($today);
        [$conditions, $bindings] = self::ageConditions($type, $today);

        if ($type->isPhoto()) {
            $conditions[] = "(players.picture_url IS NULL OR players.picture_url = '')";
        }

        $expiring = 'EXISTS (SELECT 1 FROM player_documents pd'
            .' WHERE {owner} AND pd.document_type_id = ?'
            ." AND pd.state = 'received' AND pd.valid_until >= ? AND pd.valid_until < ?)";

        return self::whenPlayer($conditions, $bindings, self::rowsSql($expiring, [
            $type->id,
            $today->toDateString(),
            $today->addDays(self::EXPIRES_SOON_DAYS + 1)->toDateString(),
        ], $branchId, $joinedRow));
    }

    /**
     * How many of a player's rows a test holds on: one per active registration
     * (only that branch's when given), or one for a player with no registration
     * at all. {owner} in the test names the documents of the row being counted.
     * With $joinedRow only the registration joined as `reg` counts, for a query
     * with one line per registration.
     *
     * @param  list<mixed>  $bindings  the test's bindings
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function rowsSql(string $test, array $bindings, ?int $branchId, bool $joinedRow): array
    {
        $registered = '(SELECT COUNT(*) FROM branch_player r WHERE r.player_id = players.id AND '.self::ACTIVE_ROW
            .($branchId !== null ? ' AND r.branch_id = ?' : '')
            .($joinedRow ? ' AND r.id = reg.id' : '')
            .' AND '.str_replace('{owner}', 'pd.branch_registration_id = r.id', $test).')';

        $alone = '(CASE WHEN NOT EXISTS (SELECT 1 FROM branch_player r0 WHERE r0.player_id = players.id) AND '
            .str_replace('{owner}', 'pd.player_id = players.id AND pd.branch_registration_id IS NULL', $test)
            .' THEN 1 ELSE 0 END)';

        return ["({$registered} + {$alone})", [...($branchId !== null ? [$branchId] : []), ...$bindings, ...$bindings]];
    }

    /**
     * The rows' count, for a player who meets conditions on the player alone.
     *
     * @param  list<string>  $conditions
     * @param  list<mixed>  $bindings
     * @param  array{0: string, 1: array<int, mixed>}  $rows
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function whenPlayer(array $conditions, array $bindings, array $rows): array
    {
        if ($conditions === []) {
            return $rows;
        }

        return ['(CASE WHEN '.implode(' AND ', $conditions).' THEN '.$rows[0].' ELSE 0 END)', [...$bindings, ...$rows[1]]];
    }
```

Add the constant after `EXPIRES_SOON_DAYS`:

```php
    /** Not left. A subquery, so it costs no extra lookup. */
    private const ACTIVE_ROW = "(r.status_id IS NULL OR r.status_id NOT IN (SELECT id FROM player_statuses WHERE code = 'left'))";
```

Change the docblock on `ageConditions()` to:

```php
    /**
     * The age test of the terms (DocumentType::earliestApplicableBirthdate), no
     * birth date = not limited.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
```

`dt.is_active = TRUE` replaces `dt.is_active = 1`, which PostgreSQL refuses (`boolean = integer`).

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DocumentChecklistRegistrationTest.php tests/Feature/DocumentChecklistTest.php tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php tests/Feature/PlayerDocumentFiltersTest.php tests/Feature/DocumentStatsTest.php tests/Feature/PlayerDocumentStatsTest.php`
Expected: all PASS. The old test files use players with no registration, whose rows give the same 1/0 as before. If `the_sql_runs_when_the_left_status_is_missing` fails, the `NOT IN` subquery lost its `IS NULL` arm.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Player/DocumentChecklist.php tests/Feature/Registrations/DocumentChecklistRegistrationTest.php
git commit -m "feat(documents): the checklist works per registration, in PHP and SQL

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.3: Writers, removing a registration, and `registrations:verify`

**Files:**
- Create: `tests/Feature/Registrations/DocumentWritersRegistrationTest.php`
- Modify: `app/Services/Player/PlayerDocumentService.php`
- Modify: `app/Http/Controllers/PlayerDocumentController.php`
- Modify: `app/Services/Player/RegistrationService.php` (`removeMistake`)
- Modify: `app/Console/Commands/VerifyRegistrations.php`
- Modify: `tests/Feature/Registrations/VerifyRegistrationsTest.php`
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json`, through `scripts/i18n-add.mjs`

**Interfaces:**
- Consumes: `PlayerDocument.branch_registration_id`, `BranchRegistration::documents()`.
- Produces: `PlayerDocumentService::markReceived(Player, DocumentType, array $receipt, array $files, ?User $by, ?BranchRegistration $registration = null)`; `PlayerDocumentService::exempt(Player, DocumentType, string $reason, ?User $by, ?BranchRegistration $registration = null)`. Both default to the main registration. Request field `branch_registration_id` on `players.documents.store` and `players.documents.exempt`, optional, defaults to the main registration, and must belong to the player. Flash key `flash.registration_has_documents`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DocumentWritersRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\PlayerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentWritersRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private User $admin;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->type = DocumentType::where('code', 'birth_certificate')->firstOrFail();
    }

    private function receive($player, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('players.documents.store', $player), [
            'document_type_id' => $this->type->id,
            'received_at' => now()->toDateString(),
            ...$extra,
        ]);
    }

    #[Test]
    public function receiving_goes_to_the_registration_named(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);

        $this->receive($player, ['branch_registration_id' => $swim->id])->assertSessionHas('success', 'flash.document_received');

        $this->assertSame($swim->id, PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function naming_none_means_the_main_registration(): void
    {
        $player = $this->bothActive();

        $this->receive($player)->assertSessionHas('success');

        $this->assertSame($player->registrationIn($this->foot->id)->id, PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function the_same_type_can_be_received_on_each_registration_once(): void
    {
        $player = $this->bothActive();
        $foot = $player->registrationIn($this->foot->id)->id;
        $swim = $player->registrationIn($this->swim->id)->id;

        $this->receive($player, ['branch_registration_id' => $foot])->assertSessionHas('success');
        $this->receive($player, ['branch_registration_id' => $swim])->assertSessionHas('success');
        $this->receive($player, ['branch_registration_id' => $swim])->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertSame(2, PlayerDocument::count());
    }

    #[Test]
    public function another_players_registration_is_refused(): void
    {
        $player = $this->bothActive();
        $other = $this->bothActive('Other')->registrationIn($this->foot->id);

        $this->receive($player, ['branch_registration_id' => $other->id])->assertSessionHasErrors('branch_registration_id');

        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function a_player_with_no_registration_keeps_one_blank_row_per_type(): void
    {
        $flat = $this->noRegistration();

        $this->receive($flat)->assertSessionHas('success');
        $this->receive($flat)->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertNull(PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function an_exemption_goes_to_the_registration_named_and_reuses_its_row(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);
        $exempt = fn () => $this->actingAs($this->admin)->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type->id, 'reason' => 'Lost', 'branch_registration_id' => $swim->id,
        ])->assertSessionHas('success');

        $exempt();
        $exempt();

        $this->assertSame($swim->id, PlayerDocument::sole()->branch_registration_id);
        $this->assertSame(PlayerDocument::EXEMPT, PlayerDocument::sole()->state);
    }

    #[Test]
    public function an_exemption_for_a_player_with_no_registration_reuses_the_blank_row(): void
    {
        $flat = $this->noRegistration();
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->admin)->post(route('players.documents.exempt', $flat), [
                'document_type_id' => $this->type->id, 'reason' => 'Lost',
            ])->assertSessionHas('success');
        }

        $this->assertNull(PlayerDocument::sole()->branch_registration_id);
    }

    #[Test]
    public function a_registration_holding_documents_cannot_be_removed_as_a_mistake(): void
    {
        $player = $this->bothActive();
        $swim = $player->registrationIn($this->swim->id);
        $this->receive($player, ['branch_registration_id' => $swim->id]);

        $this->actingAs($this->admin)->delete(route('players.registrations.destroy', [$player, $swim]))
            ->assertSessionHas('error', 'flash.registration_has_documents');

        $this->assertSame(2, $player->registrations()->count());
    }

    #[Test]
    public function an_empty_one_still_can(): void
    {
        $player = $this->bothActive();
        $this->receive($player, ['branch_registration_id' => $player->registrationIn($this->foot->id)->id]);

        $this->actingAs($this->admin)->delete(route('players.registrations.destroy', [$player, $player->registrationIn($this->swim->id)]))
            ->assertSessionHas('success');

        $this->assertSame(1, $player->registrations()->count());
        $this->assertSame(1, PlayerDocument::count());
    }
}
```

Add to `tests/Feature/Registrations/VerifyRegistrationsTest.php`, with `use App\Models\DocumentType;` in its imports:

```php
    #[Test]
    public function a_document_with_no_registration_fails_when_the_player_has_one(): void
    {
        $player = $this->member();
        DB::table('player_documents')->insert([
            'player_id' => $player->id, 'branch_registration_id' => null,
            'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received',
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document with no registration of its player')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_document_on_another_players_registration_fails(): void
    {
        $player = $this->member();
        $other = Player::create(['firstname' => 'B']);
        DB::table('player_documents')->insert([
            'player_id' => $other->id, 'branch_registration_id' => $player->registrations()->value('id'),
            'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received',
        ]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document with no registration of its player')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_blank_document_passes_for_a_player_with_no_registration_unless_doubled(): void
    {
        $this->member();
        $flat = Player::create(['firstname' => 'B']);
        $row = ['player_id' => $flat->id, 'branch_registration_id' => null, 'document_type_id' => DocumentType::query()->value('id'), 'state' => 'received'];
        DB::table('player_documents')->insert($row);

        $this->artisan('registrations:verify')->assertExitCode(0);

        DB::table('player_documents')->insert($row);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('document held twice')
            ->assertExitCode(1);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test tests/Feature/Registrations/DocumentWritersRegistrationTest.php tests/Feature/Registrations/VerifyRegistrationsTest.php`
Expected: FAIL. `branch_registration_id` is ignored, and the verify checks don't exist.

- [ ] **Step 3: Change the service**

In `app/Services/Player/PlayerDocumentService.php`, add the import `use App\Models\BranchRegistration;`. Change the constructor:

```php
    public function __construct(private PrivateFileStorage $storage, private RegistrationService $registrations) {}
```

Replace `markReceived()` and `exempt()` with:

```php
    /**
     * @param  array{received_at: string, valid_until: ?string, notes: ?string}  $receipt
     * @param  list<UploadedFile>  $files
     * @param  BranchRegistration|null  $registration  null: the main one
     */
    public function markReceived(Player $player, DocumentType $type, array $receipt, array $files, ?User $by, ?BranchRegistration $registration = null): PlayerDocument
    {
        $document = PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => ($registration ?? $this->registrations->primary($player))?->id,
            'document_type_id' => $type->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => $receipt['received_at'],
            'valid_until' => $type->validUntilFor($receipt['received_at'], $receipt['valid_until']),
            'notes' => $receipt['notes'],
            'recorded_by_user_id' => $by?->id,
        ]);

        $attached = $this->storeFiles($document, $files, $by);

        ActivityRecorder::record($by, ActivityAction::DOCUMENT_RECEIVED, $document);
        $this->recordUpload($document, $attached, $by);

        return $document;
    }
```

```php
    /**
     * With or without an existing row; a received row keeps its dates and files.
     *
     * @param  BranchRegistration|null  $registration  null: the main one
     */
    public function exempt(Player $player, DocumentType $type, string $reason, ?User $by, ?BranchRegistration $registration = null): PlayerDocument
    {
        $document = PlayerDocument::firstOrNew([
            'player_id' => $player->id,
            'branch_registration_id' => ($registration ?? $this->registrations->primary($player))?->id,
            'document_type_id' => $type->id,
        ]);

        $document->fill([
            'state' => PlayerDocument::EXEMPT,
            'exempt_reason' => $reason,
            'recorded_by_user_id' => $by?->id,
        ])->save();

        ActivityRecorder::record($by, ActivityAction::DOCUMENT_EXEMPTED, $document);

        return $document;
    }
```

- [ ] **Step 4: Change the controller**

In `app/Http/Controllers/PlayerDocumentController.php`, add imports `use App\Models\BranchRegistration;` and `use App\Services\Player\RegistrationService;`. Replace `store()` and `exempt()` with:

```php
    public function store(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $registration = $this->registration($request, $player);

        $held = $player->documents()
            ->where('branch_registration_id', $registration?->id)
            ->where('document_type_id', $type->id)
            ->exists();
        if ($held) {
            return back()->with('error', 'flash.document_already_recorded');
        }

        $this->documents->markReceived($player, $type, $this->receipt($request, $type), $this->files($request), $request->user(), $registration);

        return back()->with('success', 'flash.document_received');
    }
```

```php
    public function exempt(Request $request, Player $player): RedirectResponse
    {
        $type = $this->activeType($request);
        $registration = $this->registration($request, $player);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->documents->exempt($player, $type, $data['reason'], $request->user(), $registration);

        return back()->with('success', 'flash.document_exempted');
    }
```

Add after `activeType()`:

```php
    /** The registration the page's tab names, otherwise the main one; none for a player without any. */
    private function registration(Request $request, Player $player): ?BranchRegistration
    {
        $id = $request->validate([
            'branch_registration_id' => ['nullable', 'integer', Rule::exists('branch_player', 'id')->where('player_id', $player->id)],
        ])['branch_registration_id'] ?? null;

        return $id === null ? app(RegistrationService::class)->primary($player) : BranchRegistration::findOrFail($id);
    }
```

- [ ] **Step 5: Refuse to remove a registration that holds documents**

In `app/Services/Player/RegistrationService.php`, change `removeMistake()`'s docblock opening to:

```php
    /**
     * Undo a registration added by mistake. Anything real keeps it: a subscription
     * obligation on its branch, a document recorded on it, or being the player's
     * last one. Later releases add custom values to this check.
```

Add right after the `$hasObligations` check:

```php
        if ($registration->documents()->exists()) {
            return 'flash.registration_has_documents';
        }
```

Scratch file `keys-p5-flash.json`, outside the repo:

```json
{
    "flash.registration_has_documents": {
        "ar": "لا يمكن الحذف: توجد وثائق مسجلة في هذا الفرع.",
        "fr": "Suppression impossible : des documents sont enregistrés dans cette section.",
        "en": "Can't delete: documents are recorded on this branch."
    }
}
```

Run: `node scripts/i18n-add.mjs <path to keys-p5-flash.json>`
Expected: three files change by one inserted line each. Check with `git diff --stat resources/js/i18n`.

- [ ] **Step 6: Add the checks to `registrations:verify`**

In `app/Console/Commands/VerifyRegistrations.php`, add before the `$this->line($failures === 0 ...` line:

```php
        $homelessDocuments = DB::table('player_documents as pd')
            ->where(fn ($q) => $q
                ->where(fn ($blank) => $blank->whereNull('pd.branch_registration_id')
                    ->whereExists(fn ($r) => $r->selectRaw('1')->from('branch_player')->whereColumn('branch_player.player_id', 'pd.player_id')))
                ->orWhere(fn ($named) => $named->whereNotNull('pd.branch_registration_id')
                    ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('branch_player')
                        ->whereColumn('branch_player.id', 'pd.branch_registration_id')
                        ->whereColumn('branch_player.player_id', 'pd.player_id'))))
            ->pluck('pd.id')->map(fn ($id) => "document {$id}")->all();
        $failures += $this->report('document with no registration of its player', $homelessDocuments);

        // The unique key can't see blanks: for a player with no registration, the writers keep it to one.
        $doubledDocuments = DB::table('player_documents')->whereNull('branch_registration_id')
            ->groupBy('player_id', 'document_type_id')->havingRaw('COUNT(*) > 1')
            ->get(['player_id', 'document_type_id'])
            ->map(fn ($row) => "player {$row->player_id}: type {$row->document_type_id}")->all();
        $failures += $this->report('document held twice', $doubledDocuments);

```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Registrations tests/Feature/PlayerDocumentActionsTest.php tests/Feature/PlayerDocumentDeletionTest.php tests/Feature/DocumentsPermissionTest.php tests/Feature/ActivityBoardDocumentEventsTest.php && npm run i18n:check`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Player/PlayerDocumentService.php app/Http/Controllers/PlayerDocumentController.php app/Services/Player/RegistrationService.php app/Console/Commands/VerifyRegistrations.php resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Registrations/DocumentWritersRegistrationTest.php tests/Feature/Registrations/VerifyRegistrationsTest.php
git commit -m "feat(documents): record documents on a chosen registration and verify every row has one

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.4: Completion figures and the Players list

**Files:**
- Create: `tests/Feature/Registrations/DocumentStatsRegistrationTest.php`
- Modify: `app/Services/Player/DocumentStats.php`
- Modify: `app/Services/Dashboard/MemberStats.php` (`documents`)
- Modify: `app/Http/Controllers/TeamController.php` (`documentStats` prop)
- Modify: `app/Http/Controllers/PlayerController.php` (`index`, `documentStats` prop, `applyPlayerFilters`, `applyDocumentsFilter`)
- Modify: `tests/Feature/DocumentStatsTest.php` (`the_breakdown_groups_by_category_in_one_query_and_adds_up_to_the_summary`)

**Interfaces:**
- Consumes: `DocumentChecklist` terms with `$branchId` and `$joinedRow`; `RegistrationDisplay::singleBranch`.
- Produces: `DocumentStats::summary($players, ?CarbonInterface $today = null, ?int $branchId = null)` and `DocumentStats::breakdown($players, ?CarbonInterface $today = null, ?int $branchId = null)`. People are counted once in `summary`. `by_category` has one line per active registration, so its `players_total` values can exceed the summary's. Part 6 adds the note to the chart, using this figure as it stands.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DocumentStatsRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use App\Services\Player\DocumentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentStatsRegistrationTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true]);
    }

    private function received(Player $player, Branch $branch): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::RECEIVED,
        ]);
    }

    private function stats(): DocumentStats
    {
        return app(DocumentStats::class);
    }

    #[Test]
    public function club_wide_a_person_counts_once_and_each_registration_owes(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $summary = $this->stats()->summary(Player::query());

        $this->assertSame(1, $summary['players_total']);
        $this->assertSame(0, $summary['players_complete']);
        $this->assertSame(2, $summary['required']);
        $this->assertSame(1, $summary['received']);
        $this->assertSame(1, $summary['missing']);
    }

    #[Test]
    public function with_a_branch_only_that_registration_owes(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $foot = $this->stats()->summary(Player::query(), null, $this->foot->id);
        $this->assertSame(1, $foot['required']);
        $this->assertSame(0, $foot['missing']);
        $this->assertSame(1, $foot['players_complete']);

        $swim = $this->stats()->summary(Player::query(), null, $this->swim->id);
        $this->assertSame(1, $swim['required']);
        $this->assertSame(1, $swim['missing']);
        $this->assertSame(0, $swim['players_complete']);
    }

    #[Test]
    public function a_left_registration_owes_nothing(): void
    {
        $this->leftFootball();

        $summary = $this->stats()->summary(Player::query());
        $this->assertSame(1, $summary['required']);

        $this->assertSame(0, $this->stats()->summary(Player::query(), null, $this->foot->id)['required']);
    }

    #[Test]
    public function left_everywhere_owes_nothing_and_stays_out_of_the_category_lines(): void
    {
        $this->leftBoth();

        $result = $this->stats()->breakdown(Player::query());

        $this->assertSame(0, $result['summary']['required']);
        $this->assertSame([], $result['by_category']);
    }

    #[Test]
    public function category_lines_count_each_active_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $result = $this->stats()->breakdown(Player::query());
        $rows = collect($result['by_category'])->keyBy('name');

        $this->assertSame(1, $result['summary']['players_total']);
        $this->assertSame(1, $rows['Senior']['players_total']);
        $this->assertSame(1, $rows['Senior']['players_complete']);
        $this->assertSame(0, $rows['Senior']['missing']);
        $this->assertSame(1, $rows['U15']['players_total']);
        $this->assertSame(0, $rows['U15']['players_complete']);
        $this->assertSame(1, $rows['U15']['missing']);
    }

    #[Test]
    public function category_lines_follow_the_branch_chosen(): void
    {
        $this->bothActive();

        $rows = $this->stats()->breakdown(Player::query(), null, $this->swim->id)['by_category'];

        $this->assertSame(['U15'], array_column($rows, 'name'));
    }

    #[Test]
    public function a_player_with_no_registration_shows_under_their_own_category(): void
    {
        $this->noRegistration();

        $result = $this->stats()->breakdown(Player::query());

        $this->assertSame(['Senior'], array_column($result['by_category'], 'name'));
        $this->assertSame(1, $result['summary']['missing']);
    }

    #[Test]
    public function the_dashboard_uses_the_branch_chosen(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $admin = User::factory()->admin()->create();

        $members = fn (array $query) => $this->actingAs($admin)->get(route('dashboard', ['tab' => 'members', ...$query]))
            ->assertOk()->inertiaProps('members');

        $this->assertSame(0, $members(['branch' => $this->foot->id])['documents']['summary']['missing']);
        $this->assertSame(1, $members(['branch' => $this->swim->id])['documents']['summary']['missing']);
    }

    #[Test]
    public function the_team_page_counts_the_teams_own_branch(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $team = $this->team($this->u15, ['branch_id' => $this->swim->id]);
        $this->onRoster($team, \App\Support\Season::current()->startYear, [$player]);

        $this->actingAs($this->admin())->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentStats.required', 1)
                ->where('documentStats.missing', 1));
    }

    #[Test]
    public function the_players_list_counts_and_filters_on_the_branch_chosen(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $list = fn (array $query) => $this->actingAs($admin)->get(route('players.index', $query))->assertOk();

        $list(['branch_id' => [$this->foot->id]])->assertInertia(fn (Assert $page) => $page
            ->where('players.data.0.missing_documents_count', 0)
            ->where('documentStats.missing', 0)
            ->etc());
        $list(['branch_id' => [$this->swim->id]])->assertInertia(fn (Assert $page) => $page
            ->where('players.data.0.missing_documents_count', 1)
            ->where('documentStats.missing', 1)
            ->etc());
        // Several branches, or none: any active registration.
        $list([])->assertInertia(fn (Assert $page) => $page->where('players.data.0.missing_documents_count', 1)->etc());
        $list(['branch_id' => [$this->foot->id, $this->swim->id]])->assertInertia(fn (Assert $page) => $page->where('players.data.0.missing_documents_count', 1)->etc());

        $list(['branch_id' => [$this->foot->id], 'documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 0)->etc());
        $list(['branch_id' => [$this->swim->id], 'documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 1)->etc());
        $list(['documents' => ['missing']])->assertInertia(fn (Assert $page) => $page->has('players.data', 1)->etc());
        $list(['branch_id' => [$this->foot->id], 'documents' => ['missing-'.$this->medical->id]])->assertInertia(fn (Assert $page) => $page->has('players.data', 0)->etc());
    }
}
```

Before running, check the two route shapes against the existing tests, and use what they use: `tests/Feature/Dashboard/DashboardDocumentsTest.php`'s `members()` helper gives the dashboard route and prop name, and `tests/Feature/PlayerDocumentFiltersTest.php` gives the list's prop path. Copy that helper's route and prop name into `the_dashboard_uses_the_branch_chosen` if they differ from the above. `CompetitionFixtures::admin()` is the trait's own helper; `TeamDocumentStatsTest` calls it the same way.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/DocumentStatsRegistrationTest.php`
Expected: FAIL, because `summary()` takes no branch and `breakdown()` still groups by `players.category_id`.

- [ ] **Step 3: Change `DocumentStats`**

In `app/Services/Player/DocumentStats.php`, add the import `use App\Models\PlayerStatus;`. Replace the class comment with:

```php
/**
 * How much of the paperwork is in, over any set of players: documents
 * required, received (expiring ones included), expiring within 30 days and
 * missing, plus how many players have a complete file.
 *
 * The caller passes an already-filtered players builder; its select list and
 * ordering are replaced and its filters kept. An inner derived table gives one
 * count column per active required type and measure (r_ = required,
 * e_ = expiring, m_ = missing: DocumentChecklist's SQL terms), and the outer
 * query sums them. No per-player PHP loop.
 *
 * Each active registration owes its own documents; a person counts once in the
 * summary. With a branch given, only that branch's registration owes. A left
 * registration owes nothing.
 */
```

Replace `summary()` and `breakdown()` with:

```php
    /** @return array<string, mixed>|null null when no active required type exists */
    public function summary(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null, ?int $branchId = null): ?array
    {
        $types = $this->types();

        if ($types->isEmpty()) {
            return null;
        }

        $row = DB::query()
            ->fromSub($this->perPlayer($players, $types, $this->today($today), $branchId), 'per_player')
            ->selectRaw($this->aggregateSql($types))
            ->first();

        return $this->summaryFrom([$row], $types);
    }

    /**
     * The summary, plus one line per category with one row per active
     * registration: a two-branch player sits in each of their categories, so
     * these lines can add up to more people than the summary counts.
     *
     * @return array{summary: array<string, mixed>, by_category: list<array<string, mixed>>}|null
     */
    public function breakdown(EloquentBuilder|QueryBuilder $players, ?CarbonInterface $today = null, ?int $branchId = null): ?array
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
            ->fromSub($this->perRegistration($players, $types, $this->today($today), $branchId), 'per_row')
            ->leftJoin('categories', 'categories.id', '=', 'per_row.category_id')
            ->groupBy('per_row.category_id', 'categories.name', $localeColumn)
            ->selectRaw(
                "per_row.category_id, COALESCE(NULLIF({$localeColumn}, ''), categories.name) as category_name, "
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
            'summary' => $this->summary($players, $today, $branchId),
            'by_category' => self::worstFirst($byCategory),
        ];
    }
```

Replace `perPlayer()` with these two methods and one helper:

```php
    /** One row per player: r_{id}, e_{id}, m_{id} for every type. */
    private function perPlayer(EloquentBuilder|QueryBuilder $players, Collection $types, CarbonImmutable $today, ?int $branchId): QueryBuilder
    {
        $query = $players instanceof EloquentBuilder ? (clone $players)->toBase() : clone $players;
        $query->reorder()->select([]);

        return $this->withTerms($query, $types, $today, $branchId, joinedRow: false);
    }

    /**
     * One row per active registration, or per player with no registration at all:
     * category_id, then the same columns. Players come through a subquery, so their
     * filters' bare column names can't collide with the joined registration's.
     * A player left everywhere, or absent from the branch, has no row.
     */
    private function perRegistration(EloquentBuilder|QueryBuilder $players, Collection $types, CarbonImmutable $today, ?int $branchId): QueryBuilder
    {
        $population = ($players instanceof EloquentBuilder ? (clone $players)->toBase() : clone $players)
            ->reorder()->select('players.id');

        $query = DB::table('players')
            ->leftJoin('branch_player as reg', function ($join) use ($branchId) {
                $join->on('reg.player_id', '=', 'players.id')
                    ->where(fn ($active) => $active->whereNull('reg.status_id')
                        ->orWhereNotIn('reg.status_id', PlayerStatus::query()->select('id')->where('code', 'left')));
                if ($branchId !== null) {
                    $join->where('reg.branch_id', $branchId);
                }
            })
            ->whereIn('players.id', $population)
            ->where(fn ($row) => $row->whereNotNull('reg.id')
                ->orWhereNotExists(fn ($any) => $any->selectRaw('1')->from('branch_player as any_reg')->whereColumn('any_reg.player_id', 'players.id')))
            ->selectRaw('CASE WHEN reg.id IS NULL THEN players.category_id ELSE reg.category_id END as category_id');

        return $this->withTerms($query, $types, $today, $branchId, joinedRow: true);
    }

    private function withTerms(QueryBuilder $query, Collection $types, CarbonImmutable $today, ?int $branchId, bool $joinedRow): QueryBuilder
    {
        foreach ($types as $type) {
            $terms = [
                'r' => DocumentChecklist::requiredTypeSql($type, $today, $branchId, $joinedRow),
                'e' => DocumentChecklist::expiringTypeSql($type, $today, $branchId, $joinedRow),
                'm' => DocumentChecklist::missingRowsSql($type, $today, $branchId, $joinedRow),
            ];

            foreach ($terms as $prefix => [$sql, $bindings]) {
                $query->selectRaw("{$sql} as {$prefix}_{$type->id}", $bindings);
            }
        }

        return $query;
    }
```

Change the doc on `summaryFrom`'s `$rows` to `aggregate rows (the one row)`, and keep its body. Change `aggregateSql`'s doc to `Outer select: row counts and one SUM per inner column. Type ids are integers from the database.`

- [ ] **Step 4: Pass the branch from the three callers**

`app/Services/Dashboard/MemberStats.php`, in `documents()`:

```php
        return $this->documentStats->breakdown($this->active($filters)->notLeft(), null, $filters->branchId);
```

`app/Http/Controllers/TeamController.php`, in the `documentStats` prop:

```php
                ? app(DocumentStats::class)->summary(Player::query()->whereIn('players.id', $roster->pluck('player_id')->all()), null, $team->branch_id)
```

`app/Http/Controllers/PlayerController.php`, adding `use App\Services\Player\RegistrationDisplay;` if an earlier part hasn't. In `index()`:

```php
        // Missing documents per row, computed in SQL: the twin of the player
        // page's checklist (DocumentChecklist; the two are tested to agree). One
        // branch selected: that registration's. Otherwise any active one's.
        [$missingSql, $missingBindings] = DocumentChecklist::missingCountSql(null, null, $this->selectedBranch($request));
```

In the `documentStats` prop:

```php
                ? app(DocumentStats::class)->summary($this->statsQuery($request), null, $this->selectedBranch($request))
```

In `applyPlayerFilters()`:

```php
        if ($documents = $pick('documents')) {
            $branchId = $this->selectedBranch($request);
            $query->where(function ($q) use ($documents, $branchId) {
                foreach ($documents as $filter) {
                    $q->orWhere(fn ($one) => $this->applyDocumentsFilter($one, $filter, $branchId));
                }
            });
        }
```

Replace `applyDocumentsFilter()` with:

```php
    /**
     * missing | expiring | missing-{typeId}. Uses the SQL twin of the
     * checklist, so the list, its charts and the export agree with the
     * player page. An unknown value filters nothing.
     */
    private function applyDocumentsFilter($query, string $filter, ?int $branchId): void
    {
        if ($filter === 'missing') {
            [$sql, $bindings] = DocumentChecklist::missingCountSql(null, null, $branchId);
            $query->whereRaw("{$sql} > 0", $bindings);

            return;
        }

        if ($filter === 'expiring') {
            [$sql, $bindings] = DocumentChecklist::expiringSoonSql(null, $branchId);
            $query->whereRaw($sql, $bindings);

            return;
        }

        if (preg_match('/^missing-(\d+)$/', $filter, $match)) {
            [$sql, $bindings] = DocumentChecklist::missingCountSql(null, (int) $match[1], $branchId);
            $query->whereRaw("{$sql} > 0", $bindings);
        }
    }

    /** The branch the list's filter means: exactly one selected, otherwise none. */
    private function selectedBranch(Request $request): ?int
    {
        return RegistrationDisplay::singleBranch(ListFilter::get($request, 'branch_id', ListFilter::IDS));
    }
```

Then run `grep -n "applyDocumentsFilter\|selectedBranch" app/Http/Controllers/PlayerController.php`. Expected: only the call shown above and the two definitions, plus the uses in `index` and the prop.

- [ ] **Step 5: Update the one test this changes**

In `tests/Feature/DocumentStatsTest.php`, `the_breakdown_groups_by_category_in_one_query_and_adds_up_to_the_summary`, rename it to `the_breakdown_groups_by_category_and_a_club_of_one_category_each_adds_up_to_the_summary`, and change:

```php
        $this->assertSame(2, $queries, 'one read of the types, one grouped query');
```

to:

```php
        $this->assertSame(4, $queries, 'the types and the grouped query, then the types and the summary');
```

Change the comment `// Categories partition the players, so their rows add up to the summary.` to `// Nobody here holds two registrations, so the lines add up to the summary.`

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DocumentStatsRegistrationTest.php tests/Feature/DocumentStatsTest.php tests/Feature/PlayerDocumentStatsTest.php tests/Feature/PlayerDocumentFiltersTest.php tests/Feature/Dashboard/DashboardDocumentsTest.php tests/Feature/Competitions/TeamDocumentStatsTest.php tests/Feature/DocumentChecklistTest.php`
Expected: all PASS. If `DashboardDocumentsTest`'s `the_branch_filter_narrows_the_members_counted` fails, `summary` lost the population's branch scope: it must keep `$this->active($filters)`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Player/DocumentStats.php app/Services/Dashboard/MemberStats.php app/Http/Controllers/TeamController.php app/Http/Controllers/PlayerController.php tests/Feature/Registrations/DocumentStatsRegistrationTest.php tests/Feature/DocumentStatsTest.php
git commit -m "feat(documents): completion figures and list filters follow registrations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.5: Match badge and the documents report

`GameController` and `CompetitionController` have no document query of their own. They read documents only through `PlayerFlags`, so changing `PlayerFlags` covers both, and `TeamController`'s badges too.

**Files:**
- Create: `tests/Feature/Registrations/DocumentFlagsAndReportTest.php`
- Modify: `app/Services/Competitions/PlayerFlags.php`
- Modify: `app/Reports/Players/PlayerDocuments.php`

**Interfaces:**
- Consumes: `DocumentChecklist::missingTypeSql($type, null, $branchId)`; `Player::scopeRegisteredWhere`; `DocumentChecklist::evaluate(..., $left)`. **From Part 4:** `PlayerFlags::for()` knows the team's branch for the list it's given, as a local `$branchId` (`?int`, null when there's no team). Read the method as Part 4 left it. If the local has another name, use that name here. If Part 4's plan was changed so that no such value exists, stop and report it, because the licence rule in spec section 7 needs the same value.
- Produces: report rows with a new `branch` key, one line per active registration.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/DocumentFlagsAndReportTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use App\Services\Competitions\PlayerFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class DocumentFlagsAndReportTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        DocumentType::query()->update(['is_active' => false]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'required_for_matches' => true, 'is_active' => true]);
    }

    private function received(Player $player, Branch $branch): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'branch_registration_id' => $player->registrations()->where('branch_id', $branch->id)->value('id'),
            'document_type_id' => $this->medical->id,
            'state' => PlayerDocument::RECEIVED,
        ]);
    }

    private function flagsOn(Branch $branch, Player $player): array
    {
        $team = $this->team($branch->is($this->foot) ? $this->senior : $this->u15, ['branch_id' => $branch->id]);

        return app(PlayerFlags::class)->for([$player->id], $this->admin(), $this->game($team))[$player->id];
    }

    /** @return list<array<string, mixed>> */
    private function report(array $query = []): array
    {
        $rows = [];
        $this->actingAs(User::factory()->admin()->create())->get(route('reports.view', ['players', 'documents', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['rows']['data'];
            });

        return $rows;
    }

    #[Test]
    public function the_match_badge_reads_the_teams_branch(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertFalse($this->flagsOn($this->foot, $player)['documents_missing']);
        $swim = $this->flagsOn($this->swim, $player);
        $this->assertTrue($swim['documents_missing']);
        $this->assertSame(['Medical'], $swim['documents']);
    }

    #[Test]
    public function a_branch_the_player_left_shows_no_missing_paper(): void
    {
        $this->assertFalse($this->flagsOn($this->foot, $this->leftFootball())['documents_missing']);
    }

    #[Test]
    public function left_everywhere_shows_no_missing_paper(): void
    {
        $this->assertFalse($this->flagsOn($this->swim, $this->leftBoth())['documents_missing']);
    }

    #[Test]
    public function a_player_with_no_registration_is_judged_on_their_own_rows(): void
    {
        $this->assertTrue($this->flagsOn($this->foot, $this->noRegistration())['documents_missing']);
    }

    #[Test]
    public function with_no_team_the_badge_means_any_active_registration(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $this->assertTrue(app(PlayerFlags::class)->for([$player->id], $this->admin())[$player->id]['documents_missing']);
    }

    #[Test]
    public function the_report_has_one_line_per_registration_still_owing(): void
    {
        $player = $this->bothActive();
        $this->received($player, $this->foot);

        $rows = $this->report();

        $this->assertCount(1, $rows);
        $this->assertSame('Swimming', $rows[0]['branch']);
        $this->assertSame('U15', $rows[0]['category']);
        $this->assertSame($player->registrationIn($this->swim->id)->membership_id, $rows[0]['membership_id']);
    }

    #[Test]
    public function both_registrations_owing_give_two_lines(): void
    {
        $this->bothActive();

        $this->assertEqualsCanonicalizing(['Football', 'Swimming'], array_column($this->report(), 'branch'));
    }

    #[Test]
    public function the_report_follows_the_branch_and_category_on_the_same_registration(): void
    {
        $this->bothActive();

        $this->assertSame(['Football'], array_column($this->report(['branch_id' => [$this->foot->id]]), 'branch'));
        $this->assertSame(['Swimming'], array_column($this->report(['category_id' => [$this->u15->id]]), 'branch'));
        $this->assertSame([], $this->report(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
    }

    #[Test]
    public function a_left_registration_owes_nothing_in_the_report(): void
    {
        $this->leftFootball();
        $this->assertSame(['Swimming'], array_column($this->report(), 'branch'));
    }

    #[Test]
    public function left_everywhere_has_no_line(): void
    {
        $this->leftBoth();
        $this->assertSame([], $this->report());
    }

    #[Test]
    public function a_player_with_no_registration_has_a_line_with_no_branch(): void
    {
        $flat = $this->noRegistration();

        $rows = $this->report();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['branch']);
        $this->assertSame('Senior', $rows[0]['category']);
        $this->assertSame($flat->membership_id, $rows[0]['membership_id']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/DocumentFlagsAndReportTest.php`
Expected: FAIL. The football team's badge still counts the missing swimming paper, because `PlayerFlags` passes no branch to the term, and report rows have no `branch` key.

- [ ] **Step 3: Change `PlayerFlags`**

In `app/Services/Competitions/PlayerFlags.php`, in the `foreach ($types as $type)` that builds the select, change the term to:

```php
            [$sql, $bindings] = DocumentChecklist::missingTypeSql($type, null, $branchId);
```

Update the class comment so it says the documents flag reads the team's branch.

- [ ] **Step 4: Change the report**

In `app/Reports/Players/PlayerDocuments.php`, replace the class comment with:

```php
/**
 * One line per document a registration still owes: missing, expired or expiring
 * within 30 days. A left registration owes nothing; a player with no registration
 * owes on their own. Every state comes from DocumentChecklist::evaluate(), the
 * rule set the player page and the players list already use.
 */
```

Add the import `use App\Models\BranchRegistration;`.

In `columns()`, add after `category`:

```php
            new Column('branch', 'branch'),
```

In `sortable()`, add `'branch' => 'branch'`.

Replace `evaluate()` with:

```php
    /** @return Collection<int, array<string, mixed>> */
    private function evaluate(ReportFilters $filters): Collection
    {
        $types = DocumentType::query()->where('is_active', true)->ordered()
            ->when($filters->filled('document_type_id'), fn ($q) => $q->whereIn('id', $filters->get('document_type_id')))
            ->get();
        $states = $filters->filled('state') ? $filters->get('state') : self::STATES;
        $branches = $filters->filled('branch_id') ? array_map('intval', $filters->get('branch_id')) : null;
        $categories = $filters->filled('category_id') ? array_map('intval', $filters->get('category_id')) : null;
        $left = Player::leftStatusId();

        $query = Player::query()->where('archived', false)
            ->with(['category', 'documents.files', 'registrations.branch', 'registrations.category'])
            ->registeredWhere(branches: $branches, categories: $categories);

        $rows = collect();
        foreach ($query->lazy(200) as $player) {
            // Only the registrations the filters speak of, and none the player left.
            $lines = $player->registrations->isEmpty()
                ? collect([null])
                : $player->registrations
                    ->reject(fn (BranchRegistration $r) => $left !== null && (int) $r->status_id === $left)
                    ->filter(fn (BranchRegistration $r) => ($branches === null || in_array((int) $r->branch_id, $branches, true))
                        && ($categories === null || in_array((int) $r->category_id, $categories, true)))
                    ->sortBy('id');

            foreach ($lines as $registration) {
                $documents = $player->documents
                    ->where('branch_registration_id', $registration?->id)
                    ->keyBy('document_type_id');
                foreach ($types as $type) {
                    $document = $documents->get($type->id);
                    $result = DocumentChecklist::evaluate($type, $document, $player);
                    // A missing document only counts when the club expects it (as on the player page).
                    $owed = $result['state'] === DocumentChecklist::MISSING ? $result['counts_missing'] : true;
                    if (! $owed || ! in_array($result['state'], $states, true)) {
                        continue;
                    }
                    $rows->push([
                        'player_id' => $player->id,
                        'membership_id' => $registration?->membership_id ?? $player->membership_id,
                        'name' => $player->fullname,
                        'category' => ($registration === null ? $player->category : $registration->category)?->localized_name,
                        'branch' => $registration?->branch?->localized_name,
                        'phone' => collect($player->phones ?? [])->filter()->first(),
                        'document' => $type->localized_name,
                        'state' => UiLang::get('doc_state_'.$result['state']),
                        'state_code' => $result['state'],
                        'valid_until' => $document?->valid_until?->format('Y-m-d'),
                    ]);
                }
            }
        }

        return $rows;
    }
```

`$documents` uses Collection `where()`, which compares loosely, so a NULL registration matches only NULL rows here: an ID is never `==` null. Remove the now unused `use FiltersPlayers` methods only if nothing else uses them: `filters()` still calls `categoryFilter()` and `branchFilter()`, so the trait stays.

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations/DocumentFlagsAndReportTest.php tests/Feature/Reports/PlayerReportsTest.php tests/Feature/Competitions/PlayerFlagsTest.php tests/Feature/Competitions/LicenceAndMatchDocumentsTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Competitions/PlayerFlags.php app/Reports/Players/PlayerDocuments.php tests/Feature/Registrations/DocumentFlagsAndReportTest.php
git commit -m "feat(documents): the match badge and the report read each registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.6: One tab per registration on the player page

**Files:**
- Create: `tests/Feature/Registrations/PlayerPageDocumentsTest.php`
- Modify: `app/Http/Controllers/PlayerController.php` (`documents` prop in `show`)
- Modify: `resources/js/Pages/Players/Show.vue`
- Modify: `resources/js/Components/PlayerDocumentsCard.vue`
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json`, through `scripts/i18n-add.mjs`

**Interfaces:**
- Consumes: `DocumentChecklist::forPlayer`; `Components/Attendance/BranchSwitch.vue` (`branches: [{ id, name, logo_url }]`, `branchId`, event `switch`). It shows nothing below two branches.
- Produces: `PlayerDocumentsCard` prop `registrationId: Number|null`; i18n keys `doc_reason_left`, `doc_tab_left`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/PlayerPageDocumentsTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayerPageDocumentsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        DocumentType::query()->update(['is_active' => false]);
        DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true]);
    }

    #[Test]
    public function a_two_branch_player_gets_a_tab_per_registration(): void
    {
        $player = $this->leftFootball();

        $this->actingAs($this->admin)->get(route('players.show', $player))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.tabs', 2)
                ->where('documents.tabs.0.branch_name', 'Swimming')
                ->where('documents.tabs.0.left', false)
                ->where('documents.tabs.0.missing_count', 1)
                ->where('documents.tabs.1.branch_name', 'Football')
                ->where('documents.tabs.1.left', true)
                ->where('documents.tabs.1.missing_count', 0)
                ->where('documents.tabs.1.items.0.reason', 'left')
                ->where('documents.registration_id', $player->registrationIn($this->swim->id)->id));
    }

    #[Test]
    public function a_one_branch_player_has_one_tab(): void
    {
        $player = $this->noRegistration();
        app(\App\Services\Player\RegistrationService::class)->register($player, ['branch_id' => $this->foot->id]);

        $this->actingAs($this->admin)->get(route('players.show', $player))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('documents.tabs', 1)->where('documents.missing_count', 1));
    }

    #[Test]
    public function a_player_with_no_registration_has_no_tabs_and_the_same_checklist(): void
    {
        $this->actingAs($this->admin)->get(route('players.show', $this->noRegistration()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.tabs', 0)
                ->has('documents.items', 1)
                ->where('documents.registration_id', null)
                ->where('documents.left', false));
    }

    #[Test]
    public function left_everywhere_shows_every_registration_as_left(): void
    {
        $this->actingAs($this->admin)->get(route('players.show', $this->leftBoth()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.tabs.0.left', true)
                ->where('documents.tabs.1.left', true)
                ->where('documents.missing_count', 0));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerPageDocumentsTest.php`
Expected: FAIL, because `documents.tabs` doesn't exist.

- [ ] **Step 3: Send the tabs**

In `app/Http/Controllers/PlayerController.php`, in `show`:

```php
            'documents' => $request->user()?->hasPermission('documents', 'view')
                ? DocumentChecklist::forPlayer($player)
                : null,
```

- [ ] **Step 4: Add the i18n keys**

Scratch file `keys-p5-tabs.json`, outside the repo:

```json
{
    "doc_reason_left": {
        "ar": "غادر هذا الفرع",
        "fr": "A quitté cette section",
        "en": "Left this branch"
    },
    "doc_tab_left": {
        "ar": "غادر",
        "fr": "a quitté",
        "en": "left"
    }
}
```

Run: `node scripts/i18n-add.mjs <path to keys-p5-tabs.json>`
Expected: each catalog gains two lines.

- [ ] **Step 5: Let the card write to its registration**

In `resources/js/Components/PlayerDocumentsCard.vue`, change `props` to:

```js
const props = defineProps({
    playerId: { type: Number, required: true },
    // DocumentChecklist::for(): { registration_id, left, items, missing_count, expiring_count }
    checklist: { type: Object, required: true },
    // Which registration new records go on; null for a player with none.
    registrationId: { type: Number, default: null },
});
```

Add to `reasonLabels`:

```js
    left: t('doc_reason_left'),
```

Change the two form definitions:

```js
const receiveForm = useForm({ document_type_id: null, branch_registration_id: null, received_at: '', valid_until: '', notes: '', files: [] });
```

```js
const exemptForm = useForm({ document_type_id: null, branch_registration_id: null, reason: '' });
```

In `openReceive`, after `receiveForm.document_type_id = item.type.id;`, add `receiveForm.branch_registration_id = props.registrationId;`. In `openExempt`, after `exemptForm.document_type_id = item.type.id;`, add `exemptForm.branch_registration_id = props.registrationId;`.

The page keeps the chosen tab across saves only if its state is preserved. Change every visit in the card as follows.

`submitReceive`'s options:

```js
    const options = {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: () => { receiving.value = null; },
    };
```

`submitUpload`:

```js
    uploadForm.post(route('players.documents.files.store', { player: props.playerId, document: uploading.value.document.id }), {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: () => { uploading.value = null; },
    });
```

`submitExempt`:

```js
    exemptForm.post(route('players.documents.exempt', props.playerId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { exempting.value = null; },
    });
```

`confirmUnexempt`:

```js
    router.delete(route('players.documents.unexempt', { player: props.playerId, document: item.document.id }), { preserveScroll: true, preserveState: true });
```

`confirmRemoveFile`:

```js
    router.delete(route('players.documents.files.destroy', { player: props.playerId, file: file.id }), { preserveScroll: true, preserveState: true });
```

In the template's badge group, replace the first two `Badge` lines with:

```html
                <Badge v-if="checklist.left" :label="t('doc_reason_left')" color="slate" />
                <Badge v-else-if="checklist.missing_count > 0" :label="t('doc_missing_count', { count: checklist.missing_count })" color="rose" />
                <Badge v-else :label="t('doc_complete')" color="emerald" />
```

- [ ] **Step 6: Add the tabs to the page**

In `resources/js/Pages/Players/Show.vue`, add the import beside the card's:

```js
import BranchSwitch from '@/Components/Attendance/BranchSwitch.vue';
```

Add in the script, after the props and `t` are defined:

```js
// One documents tab per registration, main first. Below two, BranchSwitch shows nothing.
const documentTabs = computed(() => props.documents?.tabs ?? []);
const documentBranchId = ref(documentTabs.value[0]?.branch_id ?? null);
const documentBranches = computed(() => documentTabs.value.map((tab) => ({
    id: tab.branch_id,
    name: tab.left ? `${tab.branch_name} · ${t('doc_tab_left')}` : tab.branch_name,
    logo_url: tab.logo_url,
})));
const shownDocuments = computed(() => documentTabs.value.find((tab) => tab.branch_id === documentBranchId.value) ?? props.documents);
```

If `props` isn't the name the page gives `defineProps`, assign it first: check with `grep -n "defineProps" resources/js/Pages/Players/Show.vue`.

Replace the card line in the template:

```html
            <!-- Documents: checklist + actions (absent without the documents permission) -->
            <div v-if="documents" class="space-y-3">
                <BranchSwitch :branches="documentBranches" :branch-id="documentBranchId" @switch="documentBranchId = $event" />
                <!-- Keyed, so an open form never carries over to another registration. -->
                <PlayerDocumentsCard
                    :key="shownDocuments.registration_id ?? 'alone'"
                    :player-id="player.id"
                    :checklist="shownDocuments"
                    :registration-id="shownDocuments.registration_id"
                />
            </div>
```

- [ ] **Step 7: Run the tests and the build**

Run: `php artisan test tests/Feature/Registrations/PlayerPageDocumentsTest.php tests/Feature/PlayerDocumentActionsTest.php tests/Feature/PlayerDocumentStatsTest.php && npm run i18n:check && npm run build 2>&1 | tail -3`
Expected: all PASS, and the build finishes with no error.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/js/Pages/Players/Show.vue resources/js/Components/PlayerDocumentsCard.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json tests/Feature/Registrations/PlayerPageDocumentsTest.php
git commit -m "feat(documents): the player page shows one documents tab per registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task P5.7: Close the part

**Files:** `.superpowers/r2/differences.md`, git-ignored, and no tracked file.

- [ ] **Step 1: Run the full checks**

```bash
php artisan config:clear
php artisan test 2>&1 | tail -5
npm run test:js 2>&1 | tail -3
npm run i18n:check
npm run build 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail, as at the baseline. Before believing any other failure, read it: a test that creates a document and only then a registration passes through the adoption hook, and a test asserting exact query counts may need the count Part 5 states.

- [ ] **Step 2: Run the new SQL on PostgreSQL and MariaDB**

```bash
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=
  php artisan test tests/Feature/Registrations/DocumentRegistrationMigrationTest.php tests/Feature/Registrations/DocumentChecklistRegistrationTest.php tests/Feature/Registrations/DocumentStatsRegistrationTest.php tests/Feature/Registrations/DocumentFlagsAndReportTest.php tests/Feature/DocumentChecklistTest.php tests/Feature/DocumentStatsTest.php )
( export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=sportclub_r2_test DB_USERNAME=root DB_PASSWORD= DB_URL= APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
  php artisan test tests/Feature/Registrations/DocumentRegistrationMigrationTest.php tests/Feature/Registrations/DocumentChecklistRegistrationTest.php tests/Feature/Registrations/DocumentStatsRegistrationTest.php tests/Feature/Registrations/DocumentFlagsAndReportTest.php tests/Feature/DocumentChecklistTest.php tests/Feature/DocumentStatsTest.php )
```

Start the private instances first if they're stopped, as in the main plan's Tasks 3 and 7. Expected: all PASS on both. Watch `dropUnique` of the old key on MariaDB, `dt.is_active = TRUE` and `CASE` over a `COUNT` on PostgreSQL. Fix anything here, in this part.

- [ ] **Step 3: Migrate the real-data copy and compare**

```bash
. .superpowers/r2/env-sqlite.sh
php artisan config:show database.connections.sqlite.database
php artisan migrate --force
php artisan migrate --force
php artisan registrations:verify
php .superpowers/r2/figures.php p5
php .superpowers/r2/diff-figures.php p4 p5
php .superpowers/r2/sweep.php p5 | tail -5
php .superpowers/r2/diff-sweep.php p4 p5 > .superpowers/r2/data/sweep-p5.txt
```

`config:show` must print the `.superpowers/r2/data/real.sqlite` path. **Stop if it prints anything else.** Expected:

- `registrations:verify` exits 0.
- `unexpected differences: 0`, and no two-branch differences from this part either: `figures.php` doesn't read documents.
- No 5xx in the sweep.
- `sweep-p5.txt` changes only document figures: `documentStats`, `missing_documents_count`, the dashboard's documents block, and `documents` on the player page, which gains `tabs`, `registration_id` and `left`.

- [ ] **Step 4: Trace the differences**

Record in `.superpowers/r2/differences.md`, under "Part 5":

- The count of two-branch players whose second branch now shows documents missing. Expected: the 9 from the spec, each with `tabs` showing the main branch holding the existing rows. Check one by hand on the player page's props in `sweep-p5.json`.
- The `required` and `missing` totals club-wide, before and after, and why they rose: each extra registration owes its own papers.
- Every one-branch player whose missing count changed. Expected: only those who left their only branch, now 0, from spec section 8's "a left registration counts as nothing missing".
- Confirm no row was lost: `SELECT COUNT(*) FROM player_documents` is the same on `real-baseline.sqlite` (read-only) and on `real.sqlite`, and `player_document_files` likewise.

- [ ] **Step 5: Self-review against spec section 8**

Go through it line by line, and fix anything missing before moving to Part 6:

| Spec line | Where |
|---|---|
| `branch_registration_id`, nullable only for players with none | P5.1 migration and hooks; `registrations:verify` in P5.3 |
| Unique (`branch_registration_id`, `document_type_id`); `player_id` stays | P5.1 |
| Existing rows to main; those 9 players' swimming starts missing; report says so | P5.1 backfill; P5.7 Step 4; main plan Task 9 Step 3 |
| Every type applies to every branch | No `document_type_branch`; `for()` and terms read all types |
| `DocumentChecklist` and SQL twins per registration | P5.2 |
| Photo: a picture gives received on every registration | P5.2 |
| Left registration counts as nothing missing | P5.2, P5.4, P5.5 |
| Club-wide badge or filter: any active; with a branch: that branch's | P5.2 terms; P5.4 list; P5.5 flags and report |
| `PlayerDocumentsCard` takes a registration; one tab per registration through `BranchSwitch` | P5.6 |
| `PlayerDocumentService`, `PlayerDocumentController` | P5.3 |
| `DocumentStats`, `MemberStats` | P5.4 |
| `PlayerFlags`, `PlayerDocuments` report | P5.5 |
| Filters in `PlayerController`, `TeamController`; `GameController`, `CompetitionController` through `PlayerFlags` | P5.4, P5.5 |
| `removeMistake` | P5.3: a document on the registration refuses the delete |

No commit in this task unless a fix was needed. Then stage only the fixed paths.
