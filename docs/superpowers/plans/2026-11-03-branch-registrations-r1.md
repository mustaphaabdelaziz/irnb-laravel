# Branch registrations, Release 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `branch_player` into real registrations (own date, ID, category, position, licence, statuses), make one service the only writer, and give admins player cards, a join-date correction queue and the membership ID conversion.

**Architecture:** `branch_player` is rebuilt in place with a surrogate key and detail columns, wrapped by the `BranchRegistration` pivot model, so existing `branches()` relations and raw queries keep working. `RegistrationService` is the sole production writer and copies the primary registration into the legacy `players.*` columns, so unmoved readers keep working until Release 2.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia, Tailwind. Spec: `docs/superpowers/specs/2026-11-03-branch-registrations-design.md`.

## Global Constraints

- No new dependencies, no new state library, no API layer. Data reaches forms through Inertia props.
- Must run on SQLite (dev, desktop) and the MySQL family (server). Date parts use `SUBSTR`, never driver functions, outside the one helper Release 4 adds.
- **Never** set `join_date` during migration, never January 1, never from `created_at`.
- **Nothing is dropped.** `players.join_year`, `category_id`, `position_id`, `licence_number`, `status_id`, `left_at` and `membership_id` all stay.
- **No production code may call** `$player->branches()->sync|attach|detach|syncWithoutDetaching` or `$branch->players()->sync|attach|detach`. Task 5 adds a test that enforces this. Test fixtures may keep using them.
- A club with **no branches at all** keeps today's flat form and flat fields, untouched. A player with no registration keeps their own flat fields. Mirror writes happen only for players who have registrations.
- Categories stay shared in this release. The split is Release 3.
- ID format: `{code}-{year}-{NNNNN}`, per branch and year. Branch codes are 2 to 6 Latin capitals.
- Do not rebuild SQLite's `players` table: `->change()` there rebuilds it, and children cascade. Widening `membership_id` runs on non-SQLite only.
- i18n: flat keys through `node scripts/i18n-add.mjs keys.json`; call `t()` directly; a key segment after a message must not be a String method. `npm run i18n:check` must pass.
- `useForm` field names must avoid `progress`. `forceFormData` drops empty arrays, so send `''` to clear a list.
- Work in worktree `D:\irnb-branch-reg`, branch `feat/branch-registrations-r1`, from `origin/main`, with `vendor` and `node_modules` **copied, no junctions**. Stage explicit paths. Merge and push only on the owner's approval. No desktop build.
- Run `php artisan config:clear` before tests. `ManualSuspensionsTest` already fails on date; it is not this work.
- The owner cleared older backups on 2026-11-03, so nothing older exists to fall back on. Take a fresh copy before any migration on real data, keep copies in the worktree or scratchpad, never in the main folder's `database/`, and never touch the owner's runtime database.

**Deployment limit found while planning:** until Release 2 moves the readers, a player who leaves one branch but stays in another still appears in the branch they left, because rosters read the bare `branch_player` row, and readers see only the primary registration's category. Release 1 may merge to `main` but **must not reach production or a desktop build before Release 2**. This narrows the spec's "each is usable alone".

---

### Task 1: Schema, backfill and the registration model

**Files:**
- Create: `database/migrations/2026_11_03_100001_create_sporting_statuses_table.php`
- Create: `database/migrations/2026_11_03_100002_add_membership_settings_to_branches.php`
- Create: `database/migrations/2026_11_03_100003_rebuild_branch_player_as_registrations.php`
- Create: `database/migrations/2026_11_03_100004_widen_players_membership_id.php`
- Create: `app/Models/BranchRegistration.php`, `app/Models/SportingStatus.php`
- Modify: `app/Models/Player.php`, `app/Models/Branch.php`, `app/Models/WebsiteConfig.php`
- Test: `tests/Feature/Registrations/RegistrationMigrationTest.php`

**Interfaces:**
- Produces: `BranchRegistration` (table `branch_player`), `Player::registrations(): HasMany`, `Branch::registrations(): HasMany`, `BranchRegistration::effectiveYear(): ?int`, scope `needsJoinDate()`, `Branch::mainId(): ?int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function oldPivot(): void
    {
        Schema::drop('branch_player');
        Schema::create('branch_player', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->primary(['branch_id', 'player_id']);
        });
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_03_100003_rebuild_branch_player_as_registrations.php'))->up();
    }

    private function player(string $id, array $extra = []): Player
    {
        return Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => 2017, ...$extra]);
    }

    #[Test]
    public function it_copies_year_details_and_id_without_inventing_a_date(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player('201700012', ['category_id' => $category->id, 'licence_number' => 'L1']);
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $player->id]);

        $this->migrate();

        $row = BranchRegistration::firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame(2017, $row->legacy_join_year);
        $this->assertSame('201700012', $row->membership_id);
        $this->assertSame($category->id, $row->category_id);
        $this->assertSame('L1', $row->licence_number);
        $this->assertSame(1, BranchRegistration::needsJoinDate()->count());
    }

    #[Test]
    public function a_two_branch_player_gets_the_year_on_both_the_id_on_one_and_the_position_on_its_owner(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $other = $this->player('201700001');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $other->id]);
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $foot->id]);
        $both = $this->player('201700002', ['position_id' => $gk->id]);
        DB::table('branch_player')->insert([
            ['branch_id' => $swim->id, 'player_id' => $both->id],
            ['branch_id' => $foot->id, 'player_id' => $both->id],
        ]);

        $this->migrate();

        $byBranch = BranchRegistration::where('player_id', $both->id)->get()->keyBy('branch_id');
        $this->assertSame(2017, $byBranch[$foot->id]->legacy_join_year);
        $this->assertSame(2017, $byBranch[$swim->id]->legacy_join_year);
        $this->assertSame('201700002', $byBranch[$foot->id]->membership_id);
        $this->assertNull($byBranch[$swim->id]->membership_id);
        $this->assertSame($gk->id, $byBranch[$foot->id]->position_id);
        $this->assertNull($byBranch[$swim->id]->position_id);
    }

    #[Test]
    public function a_position_no_registration_owns_is_kept_on_the_primary(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $swim = Branch::create(['name' => 'Swimming']);
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $foot->id]);
        $player = $this->player('201700003', ['position_id' => $gk->id]);
        DB::table('branch_player')->insert(['branch_id' => $swim->id, 'player_id' => $player->id]);

        $this->migrate();

        $this->assertSame($gk->id, BranchRegistration::firstOrFail()->position_id);
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->oldPivot();
        $foot = Branch::create(['name' => 'Football']);
        $player = $this->player('201700004');
        DB::table('branch_player')->insert(['branch_id' => $foot->id, 'player_id' => $player->id]);

        $this->migrate();
        $before = BranchRegistration::firstOrFail()->toArray();
        BranchRegistration::query()->update(['licence_number' => 'edited']);
        $this->migrate();

        $this->assertSame(1, BranchRegistration::count());
        $this->assertSame('edited', BranchRegistration::firstOrFail()->licence_number);
        $this->assertSame($before['membership_id'], BranchRegistration::firstOrFail()->membership_id);
    }

    #[Test]
    public function existing_pivot_calls_still_work_and_inherit_the_players_details(): void
    {
        $foot = Branch::create(['name' => 'Football']);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player('201700005', ['category_id' => $category->id]);

        $player->branches()->attach($foot->id);

        $this->assertSame([$foot->id], $player->branches()->pluck('branches.id')->all());
        $row = BranchRegistration::firstOrFail();
        $this->assertSame($category->id, $row->category_id);
        $this->assertSame(2017, $row->legacy_join_year);

        $player->branches()->detach($foot->id);
        $this->assertSame(0, BranchRegistration::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/RegistrationMigrationTest.php`
Expected: FAIL, `Class "App\Models\BranchRegistration" not found`.

- [ ] **Step 3: Write the migrations**

`2026_11_03_100001_create_sporting_statuses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sporting_statuses')) {
            return;
        }

        Schema::create('sporting_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sporting_statuses');
    }
};
```

`2026_11_03_100002_add_membership_settings_to_branches.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's short code (the first part of its membership IDs) and the club's
 * main branch. One column on the club row means there can never be two main
 * branches. Suggested codes can be edited until an ID uses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('code', 6)->nullable();
                $table->unique('code', 'branches_code_unique');
            });
        }
        if (! Schema::hasColumn('website_configs', 'main_branch_id')) {
            Schema::table('website_configs', function (Blueprint $table) {
                $table->foreignId('main_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            });
        }

        $used = [];
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            $code = $branch->code ?? $this->suggest($branch, $used);
            $used[] = $code;
            DB::table('branches')->where('id', $branch->id)->update(['code' => $code]);
        }

        $largest = DB::table('branch_player')->selectRaw('branch_id, COUNT(*) AS c')
            ->groupBy('branch_id')->orderByDesc('c')->orderBy('branch_id')->value('branch_id');
        if ($largest !== null) {
            DB::table('website_configs')->whereNull('main_branch_id')->update(['main_branch_id' => $largest]);
        }
    }

    private function suggest(object $branch, array $used): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $branch->name_en ?: ($branch->name_fr ?: $branch->name)));

        for ($length = 2; $length <= min(6, strlen($letters)); $length++) {
            if (! in_array(substr($letters, 0, $length), $used, true)) {
                return substr($letters, 0, $length);
            }
        }

        $suffix = 'A';
        while (in_array('BR'.$suffix, $used, true)) {
            $suffix++;
        }

        return 'BR'.$suffix;
    }

    public function down(): void
    {
        if (Schema::hasColumn('website_configs', 'main_branch_id')) {
            Schema::table('website_configs', fn (Blueprint $table) => $table->dropConstrainedForeignId('main_branch_id'));
        }
        if (Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropUnique('branches_code_unique');
                $table->dropColumn('code');
            });
        }
    }
};
```

`2026_11_03_100003_rebuild_branch_player_as_registrations.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_player becomes the registration. The table is rebuilt (create, copy,
 * drop, rename) because it needs a surrogate key, and that behaves the same on
 * SQLite and MySQL. Nothing references it, so dropping it cascades nowhere.
 *
 * join_date stays NULL on every row: no trustworthy date exists. The year goes
 * to legacy_join_year, on both registrations of a two-branch player.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL is
 * not transactional) finishes on the next run, and a second run changes nothing.
 * Constraint names are explicit: MySQL names are global while the old table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_player') && Schema::hasTable('branch_player_new')) {
            Schema::rename('branch_player_new', 'branch_player');
        }

        if (! Schema::hasColumn('branch_player', 'id')) {
            Schema::dropIfExists('branch_player_new');
            $this->create('branch_player_new');
            DB::statement('INSERT INTO branch_player_new (branch_id, player_id) SELECT branch_id, player_id FROM branch_player');
            Schema::drop('branch_player');
            Schema::rename('branch_player_new', 'branch_player');
        }

        $this->backfill();
    }

    private function create(string $name): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('player_id');
            $table->string('membership_id', 24)->nullable();
            $table->string('legacy_membership_id', 10)->nullable();
            $table->date('join_date')->nullable();
            $table->unsignedSmallInteger('legacy_join_year')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('licence_number', 50)->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->date('left_at')->nullable();
            $table->unsignedBigInteger('sporting_status_id')->nullable();
            $table->timestamps();

            $table->foreign('branch_id', 'branch_reg_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('player_id', 'branch_reg_player_fk')->references('id')->on('players')->cascadeOnDelete();
            $table->foreign('category_id', 'branch_reg_category_fk')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('position_id', 'branch_reg_position_fk')->references('id')->on('positions')->nullOnDelete();
            $table->foreign('status_id', 'branch_reg_status_fk')->references('id')->on('player_statuses')->nullOnDelete();
            $table->foreign('sporting_status_id', 'branch_reg_sporting_fk')->references('id')->on('sporting_statuses')->nullOnDelete();

            $table->unique(['branch_id', 'player_id'], 'branch_reg_branch_player_unique');
            $table->unique('membership_id', 'branch_reg_membership_unique');
            $table->index('legacy_membership_id', 'branch_reg_legacy_id_index');
            $table->index(['branch_id', 'join_date'], 'branch_reg_branch_date_index');
            $table->index('join_date', 'branch_reg_date_index');
            $table->index('category_id', 'branch_reg_category_index');
            $table->index(['status_id', 'branch_id'], 'branch_reg_status_index');
            $table->index('player_id', 'branch_reg_player_index');
            $table->index('legacy_join_year', 'branch_reg_legacy_year_index');
        });
    }

    private function backfill(): void
    {
        $main = DB::table('website_configs')->value('main_branch_id')
            ?? DB::table('branch_player')->selectRaw('branch_id, COUNT(*) AS c')
                ->groupBy('branch_id')->orderByDesc('c')->orderBy('branch_id')->value('branch_id');
        $positionBranch = DB::table('positions')->pluck('branch_id', 'id');

        DB::table('players')
            ->whereIn('id', DB::table('branch_player')->select('player_id'))
            ->orderBy('id')
            ->chunkById(500, function ($players) use ($main, $positionBranch) {
                $links = DB::table('branch_player')->whereIn('player_id', $players->pluck('id'))
                    ->orderBy('id')->get()->groupBy('player_id');

                foreach ($players as $player) {
                    $rows = $links[$player->id];

                    // Already filled by an earlier run: never overwrite later edits.
                    if ($rows->contains(fn ($row) => $row->membership_id !== null || $row->legacy_join_year !== null)) {
                        continue;
                    }

                    $primary = $rows->firstWhere('branch_id', $main) ?? $rows->first();
                    // A position no registration's branch owns stays on the primary: nothing is lost.
                    $owner = $rows->firstWhere('branch_id', $positionBranch[$player->position_id] ?? null) ?? $primary;

                    foreach ($rows as $row) {
                        DB::table('branch_player')->where('id', $row->id)->update([
                            'membership_id' => $row->id === $primary->id ? $player->membership_id : null,
                            'legacy_join_year' => $player->join_year,
                            'category_id' => $player->category_id,
                            'position_id' => $row->id === $owner->id ? $player->position_id : null,
                            'licence_number' => $player->licence_number,
                            'status_id' => $player->status_id,
                            'left_at' => $player->left_at,
                        ]);
                    }
                }
            });
    }

    /** Back to the plain pivot. The players.* columns still hold their copies. */
    public function down(): void
    {
        Schema::dropIfExists('branch_player_old');
        Schema::create('branch_player_old', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('player_id');
            $table->primary(['branch_id', 'player_id']);
            $table->foreign('branch_id', 'branch_player_old_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('player_id', 'branch_player_old_player_fk')->references('id')->on('players')->cascadeOnDelete();
        });
        DB::statement('INSERT INTO branch_player_old (branch_id, player_id) SELECT branch_id, player_id FROM branch_player');
        Schema::drop('branch_player');
        Schema::rename('branch_player_old', 'branch_player');
    }
};
```

`2026_11_03_100004_widen_players_membership_id.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for FB-2022-00017. Not on SQLite: it ignores string lengths, and
 * ->change() would rebuild players, whose children cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('players', fn (Blueprint $table) => $table->string('membership_id', 24)->change());
        }
    }

    public function down(): void
    {
        // Longer IDs may exist by now, so the column keeps its width.
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/BranchRegistration.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A player's membership of one branch, with its own join date, ID, category,
 * position, licence and statuses. A NULL join_date means it needs verifying.
 *
 * Production writes go through App\Services\Player\RegistrationService.
 */
class BranchRegistration extends Pivot
{
    use SerializesClubDates;

    protected $table = 'branch_player';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'join_date' => 'date:Y-m-d',
            'left_at' => 'date:Y-m-d',
            'legacy_join_year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A bare attach() names no details: start from what the player holds.
        static::creating(function (BranchRegistration $registration) {
            if (array_key_exists('category_id', $registration->getAttributes())) {
                return;
            }
            $player = Player::find($registration->player_id);
            if ($player === null) {
                return;
            }
            $ownsPosition = $player->position_id !== null
                && (int) Position::whereKey($player->position_id)->value('branch_id') === (int) $registration->branch_id;
            $registration->fill([
                'category_id' => $player->category_id,
                'position_id' => $ownsPosition ? $player->position_id : null,
                'licence_number' => $player->licence_number,
                'status_id' => $player->status_id,
                'left_at' => $player->left_at,
                'legacy_join_year' => $player->join_year,
            ]);
        });

        // Same rule as Player: only a "left" registration has a leave date.
        static::saving(function (BranchRegistration $registration) {
            if (! $registration->isDirty(['status_id', 'left_at'])) {
                return;
            }
            if (! Player::isLeftStatus($registration->status_id)) {
                $registration->left_at = null;
            } elseif ($registration->left_at === null) {
                $registration->left_at = now()->toDateString();
            }
        });
    }

    public function scopeNeedsJoinDate(Builder $query): void
    {
        $query->whereNull($query->getModel()->qualifyColumn('join_date'));
    }

    /** The exact date's year, else the year-only value; null when neither is known. */
    public function effectiveYear(): ?int
    {
        return $this->join_date?->year ?? $this->legacy_join_year;
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PlayerStatus::class, 'status_id');
    }

    public function sportingStatus(): BelongsTo
    {
        return $this->belongsTo(SportingStatus::class);
    }
}
```

`app/Models/SportingStatus.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportingStatus extends Model
{
    use HasLocalizedName;
    use SerializesClubDates;

    protected $fillable = ['name', 'name_ar', 'name_fr', 'name_en', 'sort_order', 'is_active'];

    protected $appends = ['localized_name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }
}
```

In `app/Models/Player.php`, replace the `branches()` method and add `registrations()`:

```php
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_player')->using(BranchRegistration::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }
```

In `app/Models/Branch.php`, add `'code'` to `$fillable`, change `players()` and add:

```php
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'branch_player')->using(BranchRegistration::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BranchRegistration::class);
    }

    /** The club's main branch: an organisational default, it grants no privileges. */
    public static function mainId(): ?int
    {
        $id = WebsiteConfig::query()->value('main_branch_id');

        return $id === null ? null : (int) $id;
    }
```

In `app/Models/WebsiteConfig.php`, add `'main_branch_id'` to `$fillable`.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Registrations/RegistrationMigrationTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Run the full suite**

Run: `php artisan config:clear; php artisan test`
Expected: only the known `ManualSuspensionsTest` failure. Any test reading the `branches` pivot exactly as JSON must be fixed by asserting the fields it cares about.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_03_100001_create_sporting_statuses_table.php database/migrations/2026_11_03_100002_add_membership_settings_to_branches.php database/migrations/2026_11_03_100003_rebuild_branch_player_as_registrations.php database/migrations/2026_11_03_100004_widen_players_membership_id.php app/Models/BranchRegistration.php app/Models/SportingStatus.php app/Models/Player.php app/Models/Branch.php app/Models/WebsiteConfig.php tests/Feature/Registrations/RegistrationMigrationTest.php
git commit -m "feat(registrations): branch_player becomes the registration, with no invented dates"
```

---

### Task 2: Membership ID format and branch codes

**Files:**
- Modify: `app/Services/Player/MembershipNumber.php`, `app/Observers/BranchObserver.php`, `app/Http/Controllers/BranchController.php`
- Create: `app/Support/BranchCode.php`
- Test: `tests/Feature/Registrations/MembershipNumberTest.php`

**Interfaces:**
- Produces: `MembershipNumber::forBranch(Branch $branch, int $year): string`, `MembershipNumber::isLegacy(?string $id): bool`, `MembershipNumber::compose(string $code, int $year, int $seq): string`, `BranchCode::suggest(Branch $branch): string`, `BranchCode::isLocked(Branch $branch): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipNumber;
use App\Support\BranchCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function numbers_run_per_branch_and_year(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);

        $this->assertSame('FB-2022-00001', MembershipNumber::forBranch($foot, 2022));
        Player::create(['firstname' => 'A', 'membership_id' => 'FB-2022-00017']);

        $this->assertSame('FB-2022-00018', MembershipNumber::forBranch($foot, 2022));
        $this->assertSame('FB-2023-00001', MembershipNumber::forBranch($foot, 2023));
        $this->assertSame('SW-2022-00001', MembershipNumber::forBranch($swim, 2022));
    }

    #[Test]
    public function it_tells_old_ids_from_new_ones(): void
    {
        $this->assertTrue(MembershipNumber::isLegacy('202200017'));
        $this->assertFalse(MembershipNumber::isLegacy('FB-2022-00017'));
        $this->assertFalse(MembershipNumber::isLegacy(null));
    }

    #[Test]
    public function a_new_branch_gets_a_free_code_and_the_first_one_becomes_main(): void
    {
        $foot = Branch::create(['name' => 'Football']);
        $fox = Branch::create(['name' => 'Fo', 'name_en' => 'Foxes']);
        $arabic = Branch::create(['name' => 'السباحة']);

        $this->assertSame('FO', $foot->code);
        $this->assertSame('FOX', $fox->code);
        $this->assertSame('BRA', $arabic->code);
        WebsiteConfig::singleton();
        $this->assertSame($foot->id, Branch::mainId());
    }

    #[Test]
    public function a_code_locks_once_an_id_uses_it(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->actingAs($admin)->put(route('branches.update', $foot), ['name' => 'Football', 'code' => 'fc'])
            ->assertSessionHasNoErrors();
        $this->assertSame('FC', $foot->fresh()->code);

        $player = Player::create(['firstname' => 'A', 'membership_id' => 'FC-2022-00001']);
        $player->registrations()->create(['branch_id' => $foot->id, 'membership_id' => 'FC-2022-00001', 'category_id' => null]);
        $this->assertTrue(BranchCode::isLocked($foot->fresh()));

        $this->actingAs($admin)->put(route('branches.update', $foot), ['name' => 'Football', 'code' => 'XX'])
            ->assertSessionHasErrors('code');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/MembershipNumberTest.php`
Expected: FAIL, `Call to undefined method ...::forBranch()`.

- [ ] **Step 3: Implement**

`app/Support/BranchCode.php`:

```php
<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchRegistration;

/** A branch's short code: the first part of its membership IDs. */
class BranchCode
{
    public const RULE = '/^[A-Z]{2,6}$/';

    /** Latin letters of its name, the shortest free prefix; BRA, BRB... for a name without any. */
    public static function suggest(Branch $branch): string
    {
        $used = Branch::query()->whereNotNull('code')->pluck('code')->all();
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $branch->name_en ?: ($branch->name_fr ?: (string) $branch->name)));

        for ($length = 2; $length <= min(6, strlen($letters)); $length++) {
            if (! in_array(substr($letters, 0, $length), $used, true)) {
                return substr($letters, 0, $length);
            }
        }

        $suffix = 'A';
        while (in_array('BR'.$suffix, $used, true)) {
            $suffix++;
        }

        return 'BR'.$suffix;
    }

    public static function isLocked(Branch $branch): bool
    {
        return $branch->code !== null && BranchRegistration::query()
            ->where('branch_id', $branch->id)
            ->where('membership_id', 'like', $branch->code.'-%')
            ->exists();
    }
}
```

Add to `app/Services/Player/MembershipNumber.php` (keep every existing method: a club with no branches still uses them):

```php
    /** New format: FB-2022-00017. */
    private const PATTERN = '/^[A-Z]{2,6}-\d{4}-\d{5}$/';

    /** An ID from before branch codes. Null is not an ID, so not legacy. */
    public static function isLegacy(?string $id): bool
    {
        return $id !== null && preg_match(self::PATTERN, $id) !== 1;
    }

    public static function compose(string $code, int $year, int $seq): string
    {
        return sprintf('%s-%04d-%05d', $code, self::clampYear($year), $seq);
    }

    /** The next free ID for a branch and year. Players hold a copy, so both tables count. */
    public static function forBranch(Branch $branch, int $year): string
    {
        $prefix = sprintf('%s-%04d-', $branch->code, self::clampYear($year));

        $max = BranchRegistration::query()->where('membership_id', 'like', $prefix.'%')->pluck('membership_id')
            ->merge(Player::query()->where('membership_id', 'like', $prefix.'%')->pluck('membership_id'))
            ->map(fn (string $id) => (int) substr($id, strlen($prefix)))
            ->max();

        return self::compose($branch->code, $year, (int) $max + 1);
    }
```

Add `use App\Models\Branch;` and `use App\Models\BranchRegistration;` to its imports.

In `app/Observers/BranchObserver.php` add `creating`, and make the first branch the main one:

```php
    public function creating(Branch $branch): void
    {
        $branch->code ??= BranchCode::suggest($branch);
    }
```

Inside `created()`, in the existing `if (Branch::count() === 1)` block, add:

```php
            WebsiteConfig::singleton()->update(['main_branch_id' => $branch->id]);
```

Import `App\Models\WebsiteConfig` and `App\Support\BranchCode`.

In `BranchController::validated()` add to the rules, using the `$ignoreId` already passed:

```php
            'code' => ['nullable', 'string', 'regex:'.BranchCode::RULE, Rule::unique('branches', 'code')->ignore($ignoreId)],
```

Before `$request->validate(...)` in the same method, normalise:

```php
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }
```

After validation, drop an empty code and refuse a locked one:

```php
        if (empty($data['code'])) {
            unset($data['code']);
        } elseif ($ignoreId !== null) {
            $branch = Branch::findOrFail($ignoreId);
            if ($data['code'] !== $branch->code && BranchCode::isLocked($branch)) {
                throw ValidationException::withMessages(['code' => __('validation.branch_code_locked')]);
            }
        }
```

Import `App\Support\BranchCode`, `Illuminate\Validation\Rule`, `Illuminate\Validation\ValidationException`. Add as top-level keys to `lang/ar/validation.php`, `lang/fr/validation.php` and `lang/en/validation.php`, creating a missing file with `<?php return [...];` (Laravel reads these, not the JS catalogs):

| Key | ar | fr | en |
|---|---|---|---|
| `branch_code_locked` | `لا يمكن تغيير الرمز بعد استخدامه في أرقام الانخراط.` | `Ce code ne peut plus changer : des numéros d'adhérent l'utilisent.` | `This code can't change: membership IDs already use it.` |

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Registrations/MembershipNumberTest.php tests/Feature/BranchFeatureTest.php tests/Feature/PlayerMembershipIdTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/BranchCode.php app/Services/Player/MembershipNumber.php app/Observers/BranchObserver.php app/Http/Controllers/BranchController.php tests/Feature/Registrations/MembershipNumberTest.php
git commit -m "feat(registrations): branch codes and the BRANCHCODE-YEAR-NNNNN membership ID"
```

Stage the `lang/*/validation.php` files by explicit path too, in the same commit.

---

### Task 3: RegistrationService, the sole writer

**Files:**
- Create: `app/Services/Player/RegistrationService.php`
- Modify: `app/Services/Activity/ActivityAction.php`
- Test: `tests/Feature/Registrations/RegistrationServiceTest.php`

**Interfaces:**
- Consumes: `MembershipNumber::forBranch`, `MembershipNumber::isLegacy`, `BranchRegistration`, `Branch::mainId`.
- Produces, all on `App\Services\Player\RegistrationService`:
  - `sync(Player $player, array $cards, ?User $user = null): void`
  - `register(Player $player, array $card): BranchRegistration`
  - `change(BranchRegistration $registration, array $card, ?User $user = null): void`
  - `leave(Player $player, array $branchIds, ?string $date = null): void`
  - `setField(Player $player, string $field, mixed $value): void` for `category_id`, `position_id`, `status_id`
  - `setJoinDate(BranchRegistration $registration, string $date, ?User $user = null): void`
  - `primary(Player $player): ?BranchRegistration`
  - `refreshMirror(Player $player): void`
- A card is `['branch_id', 'join_date', 'legacy_join_year', 'category_id', 'position_id', 'other_position_ids', 'licence_number', 'status_id', 'left_at', 'sporting_status_id']`. Only `branch_id` is required. `ActivityAction::MEMBERSHIP_ID_CHANGED`, `ActivityAction::MEMBERSHIP_IDS_CONVERTED`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\WebsiteConfig;
use App\Services\Activity\ActivityAction;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->service = app(RegistrationService::class);
    }

    private function player(array $extra = []): Player
    {
        return Player::create(['firstname' => 'A', 'membership_id' => '202200017', 'join_year' => 2022, ...$extra]);
    }

    private function left(): PlayerStatus
    {
        return PlayerStatus::where('code', 'left')->firstOrFail();
    }

    #[Test]
    public function two_registrations_hold_independent_dates_and_categories(): void
    {
        $a = Category::create(['name' => 'Senior']);
        $b = Category::create(['name' => 'Masters']);
        $player = $this->player();

        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $a->id],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10', 'category_id' => $b->id],
        ]);

        $rows = $player->registrations()->get()->keyBy('branch_id');
        $this->assertSame('2022-09-15', $rows[$this->foot->id]->join_date->toDateString());
        $this->assertSame('2025-03-10', $rows[$this->swim->id]->join_date->toDateString());
        $this->assertSame('SW-2025-00001', $rows[$this->swim->id]->membership_id);
        $this->assertSame('202200017', $rows[$this->foot->id]->membership_id);
    }

    #[Test]
    public function the_player_row_copies_the_main_branch_registration(): void
    {
        $a = Category::create(['name' => 'Senior']);
        $b = Category::create(['name' => 'Masters']);
        $player = $this->player();

        $this->service->sync($player, [
            ['branch_id' => $this->swim->id, 'join_date' => '2020-01-05', 'category_id' => $b->id],
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $a->id, 'licence_number' => 'L9'],
        ]);

        $player->refresh();
        $this->assertSame($a->id, $player->category_id);
        $this->assertSame('L9', $player->licence_number);
        $this->assertSame(2020, $player->join_year, 'join_year is the earliest');
    }

    #[Test]
    public function a_player_is_left_only_after_leaving_every_branch(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);
        $active = $player->fresh()->status_id;

        $this->service->leave($player, [$this->foot->id], '2026-01-10');
        $player->refresh();
        $this->assertNull($player->left_at);
        $this->assertSame($active, $player->status_id);

        $this->service->leave($player, [$this->swim->id], '2026-02-20');
        $player->refresh();
        $this->assertSame($this->left()->id, $player->status_id);
        $this->assertSame('2026-02-20', $player->left_at->toDateString());
    }

    #[Test]
    public function primary_falls_to_the_earliest_active_when_the_main_branch_is_left(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->leave($player, [$this->foot->id]);

        $this->assertSame($this->swim->id, $this->service->primary($player)->branch_id);
        $this->assertSame('SW-2025-00001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_card_left_out_of_sync_is_marked_left_not_deleted(): void
    {
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->sync($player, [['branch_id' => $this->foot->id]]);

        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame($this->left()->id, $swim->status_id);
        $this->assertSame('2025-03-10', $swim->join_date->toDateString());
    }

    #[Test]
    public function adding_the_day_keeps_the_id_and_changing_the_year_renumbers_and_logs(): void
    {
        $player = $this->player();
        $player->branches()->attach($this->foot->id, ['membership_id' => 'FB-2022-00004', 'category_id' => null, 'legacy_join_year' => 2022]);
        $registration = $player->registrations()->firstOrFail();

        $this->service->setJoinDate($registration, '2022-06-01');
        $this->assertSame('FB-2022-00004', $registration->fresh()->membership_id);
        $this->assertSame(0, ActivityLog::where('action', ActivityAction::MEMBERSHIP_ID_CHANGED)->count());

        $this->service->setJoinDate($registration->fresh(), '2021-06-01');
        $fresh = $registration->fresh();
        $this->assertSame('FB-2021-00001', $fresh->membership_id);
        $this->assertSame(2022, $fresh->legacy_join_year, 'the original year is kept');
        $this->assertSame(1, ActivityLog::where('action', ActivityAction::MEMBERSHIP_ID_CHANGED)->count());
    }

    #[Test]
    public function an_old_id_is_kept_when_a_year_change_replaces_it(): void
    {
        $player = $this->player();
        $player->branches()->attach($this->foot->id, ['membership_id' => '202200017', 'category_id' => null, 'legacy_join_year' => 2022]);
        $registration = $player->registrations()->firstOrFail();

        $this->service->setJoinDate($registration, '2021-06-01');

        $fresh = $registration->fresh();
        $this->assertSame('202200017', $fresh->legacy_membership_id);
        $this->assertSame('FB-2021-00001', $fresh->membership_id);
        $this->assertSame('FB-2021-00001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_saved_date_cannot_be_cleared_by_a_card_without_one(): void
    {
        $player = $this->player();
        $this->service->sync($player, [['branch_id' => $this->foot->id, 'join_date' => '2022-09-15']]);

        $this->service->sync($player, [['branch_id' => $this->foot->id, 'join_date' => null]]);

        $this->assertSame('2022-09-15', $player->registrations()->first()->join_date->toDateString());
    }

    #[Test]
    public function other_positions_are_kept_per_card_and_never_repeat_a_main_one(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);
        $df = Position::create(['name' => 'DF', 'abbreviation' => 'DF', 'branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = $this->player();
        $player->otherPositions()->sync([$crawl->id]);
        $this->service->sync($player, [['branch_id' => $this->swim->id, 'join_date' => '2025-03-10']]);

        $this->service->sync($player, [[
            'branch_id' => $this->foot->id, 'join_date' => '2022-09-15',
            'position_id' => $gk->id, 'other_position_ids' => [$df->id, $gk->id],
        ], ['branch_id' => $this->swim->id]]);

        $this->assertEqualsCanonicalizing([$df->id, $crawl->id], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function bulk_fields_go_to_the_right_registration(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $category = Category::create(['name' => 'Senior']);
        $player = $this->player();
        $this->service->sync($player, [
            ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
        ]);

        $this->service->setField($player, 'position_id', $crawl->id);
        $this->service->setField($player, 'category_id', $category->id);
        $this->service->setField($player, 'status_id', $this->left()->id);

        $rows = $player->registrations()->get()->keyBy('branch_id');
        $this->assertSame($crawl->id, $rows[$this->swim->id]->position_id);
        $this->assertNull($rows[$this->foot->id]->position_id);
        $this->assertSame($category->id, $rows[$this->foot->id]->category_id);
        $this->assertSame($this->left()->id, $rows[$this->swim->id]->status_id);
        $this->assertNotNull($player->fresh()->left_at);
    }

    #[Test]
    public function a_first_registration_adopts_the_players_id_and_year(): void
    {
        $player = $this->player();

        $this->service->register($player, ['branch_id' => $this->foot->id]);

        $row = BranchRegistration::firstOrFail();
        $this->assertSame('202200017', $row->membership_id);
        $this->assertSame(2022, $row->legacy_join_year);
        $this->assertNull($row->join_date);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/RegistrationServiceTest.php`
Expected: FAIL, `Class "App\Services\Player\RegistrationService" not found`.

- [ ] **Step 3: Add the activity actions**

In `app/Services/Activity/ActivityAction.php` add the consts after `PLAYER_ARCHIVED`, and list both in `ALL` and in `AREAS['players']`:

```php
    const MEMBERSHIP_ID_CHANGED = 'membership_id_changed';

    const MEMBERSHIP_IDS_CONVERTED = 'membership_ids_converted';
```

Add to a scratch `keys.json` and run `node scripts/i18n-add.mjs keys.json`:

```json
{
  "activity.action.membership_id_changed": { "ar": "تغيير أرقام الانخراط", "fr": "Numéros d'adhérent modifiés", "en": "Membership IDs changed" },
  "activity.action.membership_ids_converted": { "ar": "تحويل أرقام الانخراط", "fr": "Numéros d'adhérent convertis", "en": "Membership IDs converted" }
}
```

- [ ] **Step 4: Implement the service**

`app/Services/Player/RegistrationService.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only production writer of registrations and of the players.* columns they
 * feed. Until every reader has moved, those columns copy the primary registration.
 */
class RegistrationService
{
    private const DETAILS = ['category_id', 'position_id', 'licence_number', 'status_id', 'left_at', 'sporting_status_id'];

    /**
     * Make the player's registrations match the cards. A branch with no card is
     * marked as left, never deleted: it keeps its date, ID and history.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public function sync(Player $player, array $cards, ?User $user = null): void
    {
        DB::transaction(function () use ($player, $cards, $user) {
            $existing = $player->registrations()->get()->keyBy('branch_id');

            foreach ($cards as $card) {
                $registration = $existing[(int) $card['branch_id']] ?? null;
                $registration
                    ? $this->change($registration, $card, $user)
                    : $this->register($player, $card);
            }

            $absent = $existing->keys()->diff(collect($cards)->pluck('branch_id')->map(fn ($id) => (int) $id));
            $this->leave($player, $absent->all());

            $this->syncOtherPositions($player, $cards);
            $this->refreshMirror($player);
        });
    }

    /** @param  array<string, mixed>  $card */
    public function register(Player $player, array $card): BranchRegistration
    {
        return DB::transaction(function () use ($player, $card) {
            $first = ! $player->registrations()->exists();
            $joinDate = $card['join_date'] ?? null;
            $legacyYear = $card['legacy_join_year'] ?? (($first && $joinDate === null) ? $player->join_year : null);
            $year = $joinDate !== null ? (int) substr((string) $joinDate, 0, 4) : $legacyYear;

            $id = match (true) {
                $first && $player->membership_id !== null => $player->membership_id,
                $year !== null => MembershipNumber::forBranch(Branch::findOrFail($card['branch_id']), (int) $year),
                default => null,
            };

            $details = array_fill_keys(self::DETAILS, null);
            foreach (self::DETAILS as $field) {
                $details[$field] = $card[$field] ?? null;
            }
            $details['status_id'] ??= PlayerStatus::where('code', 'registered')->value('id');

            $registration = $player->registrations()->create([
                'branch_id' => (int) $card['branch_id'],
                'membership_id' => $id,
                'join_date' => $joinDate,
                'legacy_join_year' => $legacyYear,
                ...$details,
            ]);

            $this->syncOtherPositions($player, [$card]);
            $this->refreshMirror($player);

            return $registration;
        });
    }

    /**
     * Fields a card does not carry stay as they are. A saved date is never
     * cleared, and legacy_join_year always keeps the original year.
     *
     * @param  array<string, mixed>  $card
     */
    public function change(BranchRegistration $registration, array $card, ?User $user = null): void
    {
        foreach (self::DETAILS as $field) {
            if (array_key_exists($field, $card)) {
                $registration->{$field} = $card[$field];
            }
        }
        if (! empty($card['join_date'])) {
            $this->applyDate($registration, (string) $card['join_date'], $user);
        }
        $registration->save();
    }

    public function setJoinDate(BranchRegistration $registration, string $date, ?User $user = null): void
    {
        DB::transaction(function () use ($registration, $date, $user) {
            $this->applyDate($registration, $date, $user);
            $registration->save();
            $this->refreshMirror($registration->player);
        });
    }

    /** @param  list<int>  $branchIds */
    public function leave(Player $player, array $branchIds, ?string $date = null): void
    {
        if ($branchIds === []) {
            return;
        }
        $left = Player::leftStatusId();

        $player->registrations()->whereIn('branch_id', $branchIds)->get()
            ->reject(fn (BranchRegistration $r) => (int) $r->status_id === (int) $left)
            ->each(function (BranchRegistration $r) use ($left, $date) {
                $r->status_id = $left;
                $r->left_at = $date ?? now()->toDateString();
                $r->save();
            });

        $this->refreshMirror($player);
    }

    /** One field from bulk edit: status on every registration, position on its branch's, category on the primary. */
    public function setField(Player $player, string $field, mixed $value): void
    {
        $registrations = $player->registrations()->get();

        if ($registrations->isEmpty()) {
            $player->update([$field => $value]);

            return;
        }

        $targets = match ($field) {
            'status_id' => $registrations,
            'position_id' => collect([$this->positionOwner($registrations, $player, $value)])->filter(),
            default => collect([$this->primary($player)]),
        };

        foreach ($targets as $registration) {
            $registration->{$field} = $value;
            $registration->save();
        }

        if ($field === 'position_id' && $value) {
            $player->otherPositions()->detach($value);
        }
        $this->refreshMirror($player);
    }

    /**
     * The main branch's registration if not left, else the earliest joined not
     * left, else the lowest id.
     */
    public function primary(Player $player): ?BranchRegistration
    {
        $registrations = $player->registrations()->orderBy('id')->get();
        if ($registrations->isEmpty()) {
            return null;
        }

        $left = Player::leftStatusId();
        $active = $registrations->reject(fn (BranchRegistration $r) => $left !== null && (int) $r->status_id === $left);

        return $active->firstWhere('branch_id', Branch::mainId())
            ?? $active->sortBy(fn (BranchRegistration $r) => [$this->joinSortKey($r), $r->id])->first()
            ?? $registrations->first();
    }

    /** Copy the primary registration into players.*. A player with none keeps their own values. */
    public function refreshMirror(Player $player): void
    {
        $primary = $this->primary($player);
        if ($primary === null) {
            return;
        }

        $registrations = $player->registrations()->get();
        $left = Player::leftStatusId();
        $allLeft = $left !== null && $registrations->every(fn (BranchRegistration $r) => (int) $r->status_id === $left);

        DB::table('players')->where('id', $player->id)->update([
            'category_id' => $primary->category_id,
            'position_id' => $primary->position_id,
            'licence_number' => $primary->licence_number,
            'status_id' => $allLeft ? $left : $primary->status_id,
            'left_at' => $allLeft ? $registrations->max(fn (BranchRegistration $r) => $r->left_at?->toDateString()) : null,
            'join_year' => $registrations->map(fn (BranchRegistration $r) => $r->effectiveYear())->filter()->min() ?? $player->join_year,
            'membership_id' => $primary->membership_id ?? $player->membership_id,
        ]);
    }

    private function applyDate(BranchRegistration $registration, string $date, ?User $user): void
    {
        $before = $registration->effectiveYear();
        $registration->join_date = $date;
        $after = (int) substr($date, 0, 4);

        if ($registration->membership_id === null) {
            $registration->membership_id = MembershipNumber::forBranch($registration->branch, $after);

            return;
        }
        if ($before === null || $before === $after) {
            return;
        }

        $old = $registration->membership_id;
        $registration->membership_id = MembershipNumber::forBranch($registration->branch, $after);
        if (MembershipNumber::isLegacy($old)) {
            $registration->legacy_membership_id ??= $old;
        }
        ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_ID_CHANGED, $registration->player, [
            'from' => $old,
            'to' => $registration->membership_id,
        ]);
    }

    /** An exact date sorts by itself; a year-only row sorts at the end of its year; unknown goes last. */
    private function joinSortKey(BranchRegistration $registration): string
    {
        return $registration->join_date?->toDateString()
            ?? ($registration->legacy_join_year !== null ? $registration->legacy_join_year.'-12-31' : '9999-12-31');
    }

    /** @param  Collection<int, BranchRegistration>  $registrations */
    private function positionOwner(Collection $registrations, Player $player, mixed $positionId): ?BranchRegistration
    {
        if (! $positionId) {
            return $this->primary($player);
        }
        $branchId = Position::whereKey($positionId)->value('branch_id');

        return $branchId === null ? $this->primary($player) : $registrations->firstWhere('branch_id', $branchId);
    }

    /**
     * Other positions stay in player_other_positions: a position's branch says
     * which registration it belongs to. Only the branches the cards speak for
     * change, and a main position is never also an "other" one.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    private function syncOtherPositions(Player $player, array $cards): void
    {
        $speaking = collect($cards)->filter(fn (array $card) => array_key_exists('other_position_ids', $card));

        if ($speaking->isNotEmpty()) {
            $branchIds = $speaking->pluck('branch_id')->map(fn ($id) => (int) $id);
            $kept = $player->otherPositions()->get(['positions.id', 'positions.branch_id'])
                ->reject(fn (Position $p) => $p->branch_id === null || $branchIds->contains((int) $p->branch_id))
                ->modelKeys();
            $chosen = $speaking->flatMap(fn (array $card) => $card['other_position_ids'] ?? [])->map(fn ($id) => (int) $id);

            $player->otherPositions()->sync($chosen->merge($kept)->unique()->values()->all());
        }

        $mains = $player->registrations()->whereNotNull('position_id')->pluck('position_id')->all();
        if ($mains !== []) {
            $player->otherPositions()->detach($mains);
        }
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Registrations/RegistrationServiceTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 6: Run the shared-enum guard**

Run: `php artisan test --filter=Activity`
Expected: PASS (actions must sit in `ALL` and exactly one area).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Player/RegistrationService.php app/Services/Activity/ActivityAction.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/RegistrationServiceTest.php
git commit -m "feat(registrations): one service writes registrations and the player copy"
```

---

### Task 4: Player store and update take registration cards

**Files:**
- Create: `app/Http/Requests/Player/ValidatesRegistrations.php`
- Modify: `app/Http/Requests/Player/StorePlayerRequest.php`, `app/Http/Requests/Player/UpdatePlayerRequest.php`, `app/Http/Controllers/PlayerController.php`, `app/Services/Player/RegisterPlayerService.php`, `app/Support/ValidationAttributes.php`
- Modify tests: `tests/Feature/BranchFeatureTest.php`, `tests/Feature/PlayerPositionsTest.php`, `tests/Feature/Services/RegisterPlayerServiceTest.php`, and any other file the suite flags among `ActivityPlayerEventsTest`, `ClubDateSerializationTest`, `Competitions/LicenceAndMatchDocumentsTest`, `PlayerAcademicRecordTest`, `PlayerFormContactFieldsTest`, `PlayerFormPropsTest`, `PlayerLeaveDateTest`, `PlayerListFiltersTest`, `PlayerMembershipIdTest`, `PlayerPhotoUploadTest`, `PlayerStatusLookupTest`, `PlayerWilayaTest`, `ValidationLanguageTest`
- Test: `tests/Feature/Registrations/PlayerRegistrationCardsTest.php`

**Interfaces:**
- Consumes: `RegistrationService::sync`, `MembershipNumber::forBranch`.
- Produces: request field `registrations`, an array of cards; the trait `ValidatesRegistrations` with `registrationRules(): array` and `validateRegistrations(Validator $validator, ?Player $player): void`; `RegisterPlayerService::handle(array $attributes, array $cards = []): Player`. `branch_ids` is removed.

**Rule for fixing old tests:** a test whose club has no branch keeps its flat fields, which remain valid. A test with a branch replaces `'branch_ids' => [$id]` with `'registrations' => [['branch_id' => $id, 'join_date' => '2024-01-15']]`, and flat `category_id`, `position_id`, `status_id`, `left_at`, `licence_number` or `join_year` move inside that card. `updating_a_player_replaces_its_branches` becomes the "marked left, not deleted" test below.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerRegistrationCardsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function a_new_player_gets_one_registration_and_id_per_card(): void
    {
        $category = Category::create(['name' => 'Senior']);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [
                ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $category->id],
                ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $player = Player::firstOrFail();
        $ids = $player->registrations()->orderBy('branch_id')->pluck('membership_id')->all();
        $this->assertSame(['FB-2022-00001', 'SW-2025-00001'], $ids);
        $this->assertSame('FB-2022-00001', $player->membership_id);
        $this->assertSame($category->id, $player->category_id);
        $this->assertNull($player->position_id);
    }

    #[Test]
    public function a_new_card_needs_a_valid_date(): void
    {
        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => now()->addDay()->toDateString()]],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test', 'birthdate' => '2010-05-01',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => '2009-01-01']],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function a_legacy_registration_can_be_edited_without_a_date_but_a_saved_one_stays(): void
    {
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => null, 'licence_number' => 'L1']],
        ])->assertSessionHasNoErrors();

        $row = $player->registrations()->firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame('L1', $row->licence_number);
        $this->assertSame('201700001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_position_must_belong_to_the_cards_branch_unless_it_was_already_there(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->swim->id, 'join_date' => '2025-03-10', 'position_id' => $gk->id]],
        ])->assertSessionHasErrors('registrations.0.position_id');

        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700002', 'join_year' => 2017, 'position_id' => $gk->id]);
        $player->branches()->attach($this->swim->id, ['position_id' => $gk->id, 'category_id' => null]);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->swim->id, 'position_id' => $gk->id]],
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function other_positions_must_belong_to_the_cards_branch_and_not_repeat_the_main(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [[
                'branch_id' => $this->foot->id, 'join_date' => '2022-09-15',
                'position_id' => $gk->id, 'other_position_ids' => [$crawl->id, $gk->id],
            ]],
        ])->assertSessionHasErrors('registrations.0.other_position_ids');
    }

    #[Test]
    public function removing_a_card_marks_the_registration_left_and_keeps_it(): void
    {
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700003', 'join_year' => 2017]);
        $player->branches()->attach([$this->foot->id, $this->swim->id]);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->assertSame($left, $player->registrations()->where('branch_id', $this->swim->id)->value('status_id'));
    }

    #[Test]
    public function flat_fields_are_ignored_once_a_player_has_cards(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700004', 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T', 'category_id' => $category->id, 'join_year' => 1999,
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($player->fresh()->category_id);
        $this->assertSame(2017, $player->fresh()->join_year);
    }
}
```

Add one no-branch test to the same class, as a method that deletes the setup's branches first:

```php
    #[Test]
    public function a_club_with_no_branches_keeps_its_flat_form(): void
    {
        Branch::query()->delete();

        $this->post(route('players.store'), ['firstname' => 'Ali', 'lastname' => 'Test', 'join_year' => 2024])
            ->assertSessionHasNoErrors();

        $this->assertSame(2024, Player::firstOrFail()->join_year);
        $this->assertStringStartsWith('2024', Player::firstOrFail()->membership_id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerRegistrationCardsTest.php`
Expected: FAIL, validation errors on `branch_ids`.

- [ ] **Step 3: Write the shared validation**

`app/Http/Requests/Player/ValidatesRegistrations.php`:

```php
<?php

namespace App\Http\Requests\Player;

use App\Models\Branch;
use App\Models\Player;
use App\Models\Position;
use App\Support\ValidationAttributes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesRegistrations
{
    /** @return array<string, mixed> */
    protected function registrationRules(): array
    {
        return [
            // Required once the club has branches; with none set up there is nothing to pick.
            'registrations' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'array'],
            'registrations.*.branch_id' => ['required', 'integer', 'distinct', 'exists:branches,id'],
            'registrations.*.join_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'registrations.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'registrations.*.position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'registrations.*.other_position_ids' => ['nullable', 'array'],
            'registrations.*.other_position_ids.*' => ['integer', 'exists:positions,id'],
            'registrations.*.licence_number' => ['nullable', 'string', 'max:50'],
            'registrations.*.status_id' => ['nullable', 'integer', 'exists:player_statuses,id'],
            'registrations.*.left_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'registrations.*.sporting_status_id' => ['nullable', 'integer', 'exists:sporting_statuses,id'],
        ];
    }

    protected function validateRegistrations(Validator $validator, ?Player $player): void
    {
        $validator->after(function (Validator $validator) use ($player) {
            $existing = $player ? $player->registrations()->get()->keyBy('branch_id') : collect();
            $branchOf = Position::query()->pluck('branch_id', 'id');
            $birthdate = $this->input('birthdate') ?: $player?->birthdate?->toDateString();

            foreach ((array) $this->input('registrations', []) as $i => $card) {
                $branchId = (int) ($card['branch_id'] ?? 0);
                $saved = $existing[$branchId] ?? null;
                $date = $card['join_date'] ?? null;

                // A legacy registration can be edited before its date is known; a new one cannot.
                if ($saved === null && empty($date)) {
                    $validator->errors()->add("registrations.$i.join_date", __('validation.required', ['attribute' => ValidationAttributes::for(app()->getLocale())['join_date']]));
                }
                if (! empty($date) && $birthdate && $date < $birthdate) {
                    $validator->errors()->add("registrations.$i.join_date", __('validation.join_date_before_birth'));
                }

                $main = $card['position_id'] ?? null;
                $keepsSaved = $saved !== null && (int) $saved->position_id === (int) $main;
                $owner = $branchOf[$main] ?? null;
                if ($main && ! $keepsSaved && $owner !== null && (int) $owner !== $branchId) {
                    $validator->errors()->add("registrations.$i.position_id", __('validation.position_other_branch'));
                }

                $others = array_map('intval', (array) ($card['other_position_ids'] ?? []));
                if ($main && in_array((int) $main, $others, true)) {
                    $validator->errors()->add("registrations.$i.other_position_ids", __('The main position is already listed.'));
                }
                foreach ($others as $other) {
                    if (($branchOf[$other] ?? null) !== null && (int) $branchOf[$other] !== $branchId) {
                        $validator->errors()->add("registrations.$i.other_position_ids", __('validation.position_other_branch'));
                        break;
                    }
                }
            }
        });
    }
}
```

In both Form Requests: `use ValidatesRegistrations;`, remove the `branch_ids` and `branch_ids.*` rules, append `...$this->registrationRules(),` to `rules()`, and at the end of `withValidator` add:

```php
        // StorePlayerRequest:
        $this->validateRegistrations($validator, null);
        // UpdatePlayerRequest:
        $this->validateRegistrations($validator, $this->route('player'));
```

In `app/Support/ValidationAttributes.php` replace the `branch_ids` entries and add:

```php
        'registrations' => 'branches',
        'registrations.*.branch_id' => 'branch',
        'registrations.*.join_date' => 'join_date',
        'registrations.*.category_id' => 'category',
        'registrations.*.position_id' => 'main_position',
        'registrations.*.other_position_ids' => 'other_positions',
        'registrations.*.licence_number' => 'licence_number',
        'registrations.*.status_id' => 'membership_status',
        'registrations.*.left_at' => 'left_at',
        'registrations.*.sporting_status_id' => 'sporting_status',
        'join_date' => 'join_date',
```

Add to each `lang/{ar,fr,en}/validation.php` the messages, and to `keys.json` (run `node scripts/i18n-add.mjs keys.json`):

| File | Key | ar | fr | en |
|---|---|---|---|---|
| validation | `join_date_before_birth` | `تاريخ الانضمام يسبق تاريخ الميلاد.` | `La date d'adhésion précède la date de naissance.` | `The join date is before the birth date.` |
| validation | `position_other_branch` | `هذا المركز تابع لفرع آخر.` | `Ce poste appartient à une autre branche.` | `This position belongs to another branch.` |
| i18n | `join_date` | `تاريخ الانضمام` | `Date d'adhésion` | `Join date` |
| i18n | `sporting_status` | `الحالة الرياضية` | `Statut sportif` | `Sporting status` |

- [ ] **Step 4: Update the service and controller**

`RegisterPlayerService::handle` becomes:

```php
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $cards  one per branch; empty in a club with no branches
     */
    public function handle(array $attributes, array $cards = []): Player
    {
        return DB::transaction(function () use ($attributes, $cards) {
            // New members default to "registered" when no status is given (e.g.
            // spreadsheet import, which carries no membership-status column).
            // Matched by code: the display name is editable in Settings.
            if (empty($attributes['status_id'])) {
                $attributes['status_id'] = PlayerStatus::where('code', 'registered')->value('id');
            }

            if (empty($attributes['membership_id'])) {
                $attributes['membership_id'] = $this->firstId($attributes, $cards);
            }

            /** @var Player $player */
            $player = Player::query()->create($attributes);

            if ($cards !== []) {
                app(RegistrationService::class)->sync($player, $cards);
            }

            return $player->refresh();
        });
    }

    /** The first card's ID, which that card adopts; the old format with no branches. */
    private function firstId(array $attributes, array $cards): string
    {
        if ($cards === []) {
            return MembershipNumber::generateUnique((int) ($attributes['join_year'] ?? now()->year));
        }

        $card = $cards[0];
        $year = ! empty($card['join_date']) ? (int) substr($card['join_date'], 0, 4) : (int) $card['legacy_join_year'];

        return MembershipNumber::forBranch(Branch::findOrFail($card['branch_id']), $year);
    }
```

Add `use App\Models\Branch;`.

In `PlayerController`, add a private constant and helper:

```php
    /** What a registration holds. These reach the player row only through RegistrationService. */
    private const REGISTRATION_FIELDS = ['category_id', 'position_id', 'status_id', 'left_at', 'licence_number', 'join_year'];

    /** @return array{0: array<string, mixed>, 1: ?array} the player's own fields, and the cards if any were sent */
    private function splitCards(array $validated): array
    {
        $cards = $validated['registrations'] ?? null;
        unset($validated['registrations']);

        if ($cards !== null) {
            foreach (self::REGISTRATION_FIELDS as $field) {
                unset($validated[$field]);
            }
            unset($validated['other_position_ids']);
        }

        return [$validated, $cards];
    }
```

A card's `other_position_ids` of `''` needs no handling: `ConvertEmptyStringsToNull` already gives a present null, which `array_key_exists` in the service treats as "clear".

Replace the head of `store()` and the sync lines:

```php
    public function store(StorePlayerRequest $request, RegisterPlayerService $service, FileStorageService $files): RedirectResponse
    {
        [$attributes, $cards] = $this->splitCards($request->validated());
        $emergencyContacts = $attributes['emergency_contacts'] ?? [];
        $otherPositionIds = $attributes['other_position_ids'] ?? [];
        unset($attributes['emergency_contacts'], $attributes['picture'], $attributes['other_position_ids']);
```

and:

```php
        $player = $service->handle($attributes, $cards ?? []);

        // Only a club with no branches still sends these flat.
        if ($cards === null) {
            $player->otherPositions()->sync($otherPositionIds);
            if ($player->position_id) {
                $player->otherPositions()->detach($player->position_id);
            }
        }
```

In `update()`, replace the opening extraction with:

```php
        [$validated, $cards] = $this->splitCards($request->validated());
```

Keep the existing comment on present keys. Remove the `branch_ids` handling. Guard the flat membership regeneration, which only applies with no cards:

```php
        if ($cards === null && ! empty($validated['join_year'])
            && (int) $validated['join_year'] !== (int) $player->join_year) {
            $validated['membership_id'] = MembershipNumber::generateUnique((int) $validated['join_year']);
        }
```

Replace the `branches()->sync` block with:

```php
        if ($cards !== null) {
            app(RegistrationService::class)->sync($player, $cards, $request->user());
        }
```

and wrap the remaining other-position code in `if ($cards === null) { ... }`.

- [ ] **Step 5: Run tests and fix old ones**

Run: `php artisan test tests/Feature/Registrations/PlayerRegistrationCardsTest.php`
Expected: PASS, 8 tests.

Run: `php artisan test --filter='Player|Branch|Licence|ValidationLanguage|ClubDateSerialization|Activity'`
Fix each failure with the rule stated at the top of this task, then rerun.
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Player/ValidatesRegistrations.php app/Http/Requests/Player/StorePlayerRequest.php app/Http/Requests/Player/UpdatePlayerRequest.php app/Http/Controllers/PlayerController.php app/Services/Player/RegisterPlayerService.php app/Support/ValidationAttributes.php tests/Feature/Registrations/PlayerRegistrationCardsTest.php
git commit -m "feat(registrations): the player form saves one card per branch"
```

Stage the changed `lang/*/validation.php`, the three i18n catalogs and each old test file by explicit path in the same commit.

---

### Task 5: Bulk edit, branch members, deletes and the writer guard

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php`, `app/Http/Requests/Player/BulkUpdatePlayersRequest.php`, `app/Http/Controllers/BranchController.php`, `app/Http/Controllers/CategoryController.php`, `app/Http/Controllers/PlayerStatusController.php`, `app/Services/Attendance/BranchChoice.php`
- Modify: `resources/js/Components/BulkEditModal.vue`, `resources/js/Pages/Players/Index.vue`, `resources/js/Pages/Settings/Branches.vue`
- Modify tests: `tests/Feature/PlayerBulkUpdateTest.php`, `tests/Feature/PlayerLeaveDateTest.php`, `tests/Feature/PlayerPositionsTest.php`, `tests/Feature/BranchFeatureTest.php`
- Create: `app/Http/Controllers/RegistrationController.php`
- Modify: `app/Services/Player/RegistrationService.php`
- Test: `tests/Feature/Registrations/RegistrationWritersTest.php`

**Interfaces:**
- Consumes: `RegistrationService::setField`, `register`, `leave`, `refreshMirror`.
- Produces: `players.bulkUpdate` accepts `join_date` (required for `branches` with mode `replace` or `attach`). `branches.players.sync` accepts `join_date`. `POST branches.main` with `{branch}`. `DELETE players.registrations.destroy` with `{player}` and `{registration}`. `RegistrationService::removeMistake(BranchRegistration $registration): ?string`, returning a flash key when refused.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Attendance\BranchChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationWritersTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function member(string $id = '201700001'): Player
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        return $player;
    }

    #[Test]
    public function bulk_attach_needs_a_date_and_uses_it(): void
    {
        $player = $this->member();
        $payload = ['ids' => [$player->id], 'field' => 'branches', 'mode' => 'attach', 'value' => [$this->swim->id]];

        $this->post(route('players.bulkUpdate'), $payload)->assertSessionHasErrors('join_date');

        $this->post(route('players.bulkUpdate'), [...$payload, 'join_date' => '2025-03-10'])->assertSessionHasNoErrors();

        $row = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $this->assertSame('2025-03-10', $row->join_date->toDateString());
        $this->assertSame('SW-2025-00001', $row->membership_id);
    }

    #[Test]
    public function bulk_detach_and_replace_mark_left_instead_of_deleting(): void
    {
        $player = $this->member();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'replace',
            'value' => [$this->swim->id], 'join_date' => '2025-03-10',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $this->assertSame($left, $player->registrations()->where('branch_id', $this->foot->id)->value('status_id'));

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'branches', 'mode' => 'detach', 'value' => [$this->swim->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $this->assertSame($left, $player->fresh()->status_id);
    }

    #[Test]
    public function bulk_category_reaches_the_registration_and_the_copy(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = $this->member();

        $this->post(route('players.bulkUpdate'), [
            'ids' => [$player->id], 'field' => 'category_id', 'value' => $category->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($category->id, $player->registrations()->firstOrFail()->category_id);
        $this->assertSame($category->id, $player->fresh()->category_id);
    }

    #[Test]
    public function branch_members_keep_their_data_when_removed_and_new_ones_need_a_date(): void
    {
        $stays = $this->member('201700002');
        $newcomer = Player::create(['firstname' => 'B', 'membership_id' => '201700003', 'join_year' => 2017]);

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$stays->id, $newcomer->id]])
            ->assertSessionHasErrors('join_date');

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$stays->id, $newcomer->id], 'join_date' => '2026-01-20'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-01-20', $newcomer->registrations()->firstOrFail()->join_date->toDateString());

        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$newcomer->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $stays->registrations()->firstOrFail()->status_id);
    }

    #[Test]
    public function a_category_or_status_in_use_by_a_registration_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700004', 'join_year' => 2017]);
        $player->registrations()->create(['branch_id' => $this->foot->id, 'category_id' => $category->id, 'status_id' => null]);

        $this->delete(route('categories.destroy', $category))->assertSessionHas('error', 'flash.category_in_use');
        $this->assertNotNull($category->fresh());
    }

    #[Test]
    public function the_main_branch_opens_first_and_can_be_reassigned(): void
    {
        $this->post(route('branches.main', $this->swim))->assertSessionHasNoErrors();
        $this->assertSame($this->swim->id, Branch::mainId());

        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $this->assertSame($this->swim->id, BranchChoice::resolve($request, null));
    }

    #[Test]
    public function a_mistaken_registration_can_be_deleted_outright(): void
    {
        $player = $this->member('201700005');
        $player->branches()->attach($this->swim->id);
        $mistake = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $mistake]))->assertSessionHas('success');

        $this->assertSame([$this->foot->id], $player->registrations()->pluck('branch_id')->all());
    }

    #[Test]
    public function a_registration_with_obligations_or_the_last_one_stays(): void
    {
        $player = $this->member('201700006');
        $only = $player->registrations()->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $only]))->assertSessionHas('error', 'flash.registration_last');

        $player->branches()->attach($this->swim->id);
        $swim = $player->registrations()->where('branch_id', $this->swim->id)->firstOrFail();
        $subscription = Subscription::create(['name' => 'Swim fee', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100]);
        $subscription->branches()->attach($this->swim->id);
        $player->playerSubscriptions()->create(['subscription_id' => $subscription->id, 'year' => 2026, 'amount_owed' => 100, 'amount_paid' => 0]);

        $this->delete(route('players.registrations.destroy', [$player, $swim]))->assertSessionHas('error', 'flash.registration_has_obligations');
        $this->assertSame(2, $player->registrations()->count());
    }

    #[Test]
    public function a_registration_of_another_player_is_not_found(): void
    {
        $player = $this->member('201700007');
        $other = $this->member('201700008');
        $registration = $other->registrations()->firstOrFail();

        $this->delete(route('players.registrations.destroy', [$player, $registration]))->assertNotFound();
    }

    #[Test]
    public function no_production_code_writes_registrations_through_the_bare_pivot(): void
    {
        $pattern = '/\$(player|p)->branches\(\)->(sync|attach|detach|syncWithoutDetaching|toggle)\b|\$branch->players\(\)->(sync|attach|detach|syncWithoutDetaching|toggle)\b/';

        $offenders = collect(File::allFiles(app_path()))
            ->filter(fn ($file) => preg_match($pattern, $file->getContents()) === 1)
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $offenders);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/RegistrationWritersTest.php`
Expected: FAIL on bulk, route `branches.main` not defined, and the guard listing `PlayerController.php`, `BranchController.php`.

- [ ] **Step 3: Implement the backend**

`BulkUpdatePlayersRequest::rules()` adds:

```php
            // A new registration needs its date; leaving a branch does not.
            'join_date' => [
                Rule::requiredIf(fn () => $this->input('field') === 'branches' && $this->input('mode', 'replace') !== 'detach'),
                'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01',
            ],
```

Replace the transaction in `PlayerController::bulkUpdate`:

```php
        $registrations = app(RegistrationService::class);

        DB::transaction(function () use ($ids, $field, $value, $data, $registrations) {
            $players = Player::whereIn('id', $ids)->get();

            if ($field !== 'branches') {
                $players->each(fn (Player $player) => $registrations->setField($player, $field, $value ?: null));

                return;
            }

            $branchIds = array_map('intval', (array) $value);
            $mode = $data['mode'] ?? 'replace';

            // Branches are many-to-many, so "set" is ambiguous: replace the
            // whole set, add to it, or remove from it. Removing marks as left.
            $players->each(function (Player $player) use ($branchIds, $mode, $data, $registrations) {
                $held = $player->registrations()->pluck('branch_id')->map(fn ($id) => (int) $id);

                if ($mode === 'detach') {
                    $registrations->leave($player, $branchIds);

                    return;
                }
                foreach (array_diff($branchIds, $held->all()) as $branchId) {
                    $registrations->register($player, ['branch_id' => $branchId, 'join_date' => $data['join_date']]);
                }
                if ($mode === 'replace') {
                    $registrations->leave($player, array_values(array_diff($held->all(), $branchIds)));
                }
            });
        });
```

`RegistrationService::setField` with no registrations uses `$player->update()`, so the `saving` hook keeps the leave date rule. Delete `Player::leaveDateUpdate` only if the suite shows no caller; otherwise leave it.

`BranchController::syncPlayers`:

```php
    public function syncPlayers(Request $request, Branch $branch, RegistrationService $registrations): RedirectResponse
    {
        $validated = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'join_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $held = $branch->registrations()->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $wanted = array_map('intval', $validated['player_ids']);
        $new = array_values(array_diff($wanted, $held));

        if ($new !== [] && empty($validated['join_date'])) {
            throw ValidationException::withMessages([
                'join_date' => __('validation.required', ['attribute' => ValidationAttributes::for(app()->getLocale())['join_date']]),
            ]);
        }

        DB::transaction(function () use ($branch, $registrations, $validated, $held, $wanted, $new) {
            Player::whereIn('id', $new)->get()->each(fn (Player $player) => $registrations->register($player, [
                'branch_id' => $branch->id,
                'join_date' => $validated['join_date'],
            ]));
            Player::whereIn('id', array_diff($held, $wanted))->get()
                ->each(fn (Player $player) => $registrations->leave($player, [$branch->id]));
        });

        return back()->with('success', 'flash.branch_members_updated');
    }

    public function setMain(Branch $branch): RedirectResponse
    {
        WebsiteConfig::singleton()->update(['main_branch_id' => $branch->id]);
        Player::whereHas('registrations', fn ($q) => $q->where('branch_id', $branch->id))->get()
            ->each(fn (Player $player) => app(RegistrationService::class)->refreshMirror($player));

        return back()->with('success', 'flash.branch_main_set');
    }
```

Import `App\Services\Player\RegistrationService`, `App\Models\WebsiteConfig`, `App\Support\ValidationAttributes`. Add to `routes/web.php` after the `branches.players.sync` line:

```php
        Route::post('/branches/{branch}/main', [BranchController::class, 'setMain'])->name('branches.main');
```

and to `config/activity.php`, beside `'branches.players.sync'`:

```php
    'branches.main' => ActivityAction::PLAYER_UPDATED,
```

Pass `'mainBranchId' => Branch::mainId()` in `BranchController::index`.

Add to `RegistrationService`:

```php
    /**
     * Undo a registration added by mistake. Anything real keeps it: a subscription
     * obligation on its branch, or being the player's last one. Later releases add
     * documents and custom values to this check.
     *
     * @return string|null the flash key when refused
     */
    public function removeMistake(BranchRegistration $registration): ?string
    {
        $player = $registration->player;

        if ($player->registrations()->count() === 1) {
            return 'flash.registration_last';
        }

        $hasObligations = $player->playerSubscriptions()
            ->whereHas('subscription.branches', fn ($q) => $q->where('branches.id', $registration->branch_id))
            ->exists();
        if ($hasObligations) {
            return 'flash.registration_has_obligations';
        }

        DB::transaction(function () use ($player, $registration) {
            $id = $registration->membership_id;
            $branchPositions = Position::where('branch_id', $registration->branch_id)->pluck('id');
            $player->otherPositions()->detach($branchPositions);
            $registration->delete();

            // The player's copy of the ID must keep pointing at a real one.
            $next = $this->primary($player);
            if ($next->membership_id === null) {
                $next->membership_id = MembershipNumber::isLegacy($id)
                    ? $id
                    : ($next->effectiveYear() ? MembershipNumber::forBranch($next->branch, $next->effectiveYear()) : null);
                $next->save();
            }

            $this->refreshMirror($player);
        });

        return null;
    }
```

`app/Http/Controllers/RegistrationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Http\RedirectResponse;

class RegistrationController extends Controller
{
    public function destroy(Player $player, BranchRegistration $registration, RegistrationService $registrations): RedirectResponse
    {
        abort_unless((int) $registration->player_id === (int) $player->id, 404);

        $refused = $registrations->removeMistake($registration);

        return $refused !== null
            ? back()->with('error', $refused)
            : back()->with('success', 'flash.registration_deleted');
    }
}
```

In `routes/web.php`, beside the other `players.*` routes:

```php
    Route::delete('/players/{player}/registrations/{registration}', [RegistrationController::class, 'destroy'])->name('players.registrations.destroy');
```

In `config/activity.php`, beside `players.update`:

```php
    'players.registrations.destroy' => ActivityAction::PLAYER_UPDATED,
```

`CategoryController::destroy`, beside the teams check:

```php
        // A registration's category is restricted: its history must not vanish.
        if (BranchRegistration::where('category_id', $category->id)->exists()) {
            return back()->with('error', 'flash.category_in_use');
        }
```

`PlayerStatusController::destroy`, extend the existing guard:

```php
        if ($playerStatus->players()->exists() || BranchRegistration::where('status_id', $playerStatus->id)->exists()) {
```

`BranchChoice::resolve` default, replacing `$first = Branch::orderBy('id')->value('id');`:

```php
        $first = Branch::mainId() ?? Branch::orderBy('id')->value('id');
```

- [ ] **Step 4: Implement the screens**

In `BulkEditModal.vue`, add `join_date: ''` to `useForm`, reset it in the field watcher, and after the mode buttons:

```vue
                    <div v-if="activeField?.needsJoinDate && form.mode !== 'detach'">
                        <InputLabel :value="t('join_date') + ' *'" />
                        <input v-model="form.join_date" type="date" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        <p v-if="form.errors.join_date" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ form.errors.join_date }}</p>
                    </div>
```

In `Players/Index.vue`, add `needsJoinDate: true` to the `branches` entry of `bulkFields`.

In `Settings/Branches.vue`:
- Props add `mainBranchId: { type: Number, default: null }`.
- Add `const membersJoinDate = ref('');` and send `join_date: membersJoinDate.value` in the `router.post(route('branches.players.sync', ...))` payload. Show a date input labelled `t('join_date')` in the members dialog with the hint `t('join_date_for_new_members')`.
- Add `code` to both forms: `form` and `editForm` get `code: ''`, `startEdit` sets `editForm.code = branch.code || ''`, and each form gets `<TextInput v-model="form.code" class="mt-1 w-full uppercase" maxlength="6" dir="ltr" />` with its `InputError`, labelled `t('branch_code')`.
- In each list row add, beside the name:

```vue
<span v-if="branch.id === mainBranchId" class="ms-2 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-200">{{ t('main_branch') }}</span>
<span class="ms-2 font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ branch.code }}</span>
```

- In the actions cell add:

```vue
<IconButton v-if="branch.id !== mainBranchId" icon="flag" :label="t('set_main_branch')" plain size="sm" @click="router.post(route('branches.main', branch.id), {}, { preserveScroll: true })" />
```

Add to `keys.json` and run `node scripts/i18n-add.mjs keys.json`:

```json
{
  "branch_code": { "ar": "رمز الفرع", "fr": "Code de la branche", "en": "Branch code" },
  "main_branch": { "ar": "الفرع الرئيسي", "fr": "Branche principale", "en": "Main branch" },
  "set_main_branch": { "ar": "تعيينه فرعا رئيسيا", "fr": "Définir comme principale", "en": "Set as main branch" },
  "join_date_for_new_members": { "ar": "يطبق على الأعضاء الجدد في هذا الفرع فقط.", "fr": "S'applique uniquement aux nouveaux membres de cette branche.", "en": "Applies only to new members of this branch." },
  "flash.category_in_use": { "ar": "لا يمكن الحذف: هذه الفئة مستعملة في تسجيلات.", "fr": "Suppression impossible : des inscriptions utilisent cette catégorie.", "en": "Can't delete: registrations use this category." },
  "flash.branch_main_set": { "ar": "تم تعيين الفرع الرئيسي.", "fr": "Branche principale définie.", "en": "Main branch set." },
  "flash.registration_deleted": { "ar": "تم حذف التسجيل.", "fr": "Inscription supprimée.", "en": "Registration deleted." },
  "flash.registration_last": { "ar": "لا يمكن حذف آخر تسجيل للاعب.", "fr": "Impossible de supprimer la dernière inscription du joueur.", "en": "Can't delete a player's last registration." },
  "flash.registration_has_obligations": { "ar": "لا يمكن الحذف: توجد اشتراكات مرتبطة بهذا الفرع. سجل مغادرة الفرع بدلا من ذلك.", "fr": "Suppression impossible : des cotisations sont liées à cette branche. Indiquez plutôt un départ.", "en": "Can't delete: subscriptions are tied to this branch. Mark the player as left instead." }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Registrations/RegistrationWritersTest.php`
Expected: PASS, 10 tests.

Run: `php artisan test tests/Feature/PlayerBulkUpdateTest.php tests/Feature/PlayerLeaveDateTest.php tests/Feature/PlayerPositionsTest.php tests/Feature/BranchFeatureTest.php`
Fix old tests: bulk branch calls add `'join_date' => '2024-01-15'`; `branches_can_be_replaced_outright` and `branches_can_be_removed` assert the left status on the old registration, not its absence.
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RegistrationController.php app/Services/Player/RegistrationService.php app/Http/Controllers/PlayerController.php app/Http/Requests/Player/BulkUpdatePlayersRequest.php app/Http/Controllers/BranchController.php app/Http/Controllers/CategoryController.php app/Http/Controllers/PlayerStatusController.php app/Services/Attendance/BranchChoice.php routes/web.php config/activity.php resources/js/Components/BulkEditModal.vue resources/js/Pages/Players/Index.vue resources/js/Pages/Settings/Branches.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/RegistrationWritersTest.php tests/Feature/PlayerBulkUpdateTest.php tests/Feature/PlayerLeaveDateTest.php tests/Feature/PlayerPositionsTest.php tests/Feature/BranchFeatureTest.php
git commit -m "feat(registrations): bulk edit and branch members mark as left, never delete"
```

---

### Task 6: Spreadsheet import takes a date and a branch

**Files:**
- Modify: `app/Http/Controllers/PlayerImportController.php`
- Modify tests: `tests/Feature/PlayerImportTest.php`
- Test: `tests/Feature/Registrations/PlayerImportRegistrationTest.php`

**Interfaces:**
- Consumes: `RegisterPlayerService::handle($attributes, $cards)`.
- Produces: `private function joinFields(?string $cell): array{join_date: ?string, legacy_join_year: ?int}` on the controller; a new last template column `branch`.

- [ ] **Step 1: Write the failing test**

Follow the upload helper in `tests/Feature/PlayerImportTest.php` (read its `it_imports_players_without_attaching_subscriptions` first and copy how it builds the file into a private `import(array $rows)` here).

```php
    #[Test]
    public function a_full_date_is_exact_and_a_year_goes_to_the_queue(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $foot->id]);

        $this->import([
            ['firstname' => 'Exact', 'lastname' => 'A', 'join_year' => '2022-09-15'],
            ['firstname' => 'Year', 'lastname' => 'B', 'join_year' => '2019'],
        ]);

        $exact = Player::where('firstname', 'Exact')->firstOrFail()->registrations()->firstOrFail();
        $this->assertSame('2022-09-15', $exact->join_date->toDateString());
        $this->assertSame('FB-2022-00001', $exact->membership_id);

        $year = Player::where('firstname', 'Year')->firstOrFail()->registrations()->firstOrFail();
        $this->assertNull($year->join_date);
        $this->assertSame(2019, $year->legacy_join_year);
        $this->assertSame('FB-2019-00001', $year->membership_id);
    }

    #[Test]
    public function an_empty_date_is_a_row_error_not_the_current_year(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->import([['firstname' => 'None', 'lastname' => 'A', 'join_year' => '']])
            ->assertSessionHas('error');

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function the_branch_cell_picks_the_branch_and_an_unknown_one_is_a_row_error(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW', 'name_ar' => 'السباحة']);

        $this->import([['firstname' => 'Swim', 'lastname' => 'A', 'join_year' => '2025-03-10', 'branch' => 'السباحة']]);
        $this->assertSame($swim->id, Player::firstOrFail()->registrations()->firstOrFail()->branch_id);

        $this->import([['firstname' => 'Lost', 'lastname' => 'A', 'join_year' => '2025-03-10', 'branch' => 'Chess']])
            ->assertSessionHas('error');
        $this->assertNull(Player::where('firstname', 'Lost')->first());
    }

    #[Test]
    public function with_no_branches_the_year_stays_on_the_player(): void
    {
        $this->import([['firstname' => 'Flat', 'lastname' => 'A', 'join_year' => '2020-05-01']]);

        $player = Player::firstOrFail();
        $this->assertSame(2020, $player->join_year);
        $this->assertSame(0, $player->registrations()->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/PlayerImportRegistrationTest.php`
Expected: FAIL: an empty date imports with the current year, and no registration is created.

- [ ] **Step 3: Implement**

In `COLUMNS`, change the `join_year` entry's label and keep old headers recognised, and append `branch` last:

```php
        ['key' => 'join_year', 'label' => 'col.join_date', 'hint' => 'col.hint.date_or_year', 'legacy' => ['سنة الانضمام', 'Join year', "Année d'adhésion"], 'example' => ''],
```

```php
        // Appended last: older files have no cell here. Empty means the main branch.
        ['key' => 'branch', 'label' => 'col.branch', 'legacy' => ['الفرع'], 'example' => ''],
```

Add helpers:

```php
    /**
     * A full date is exact. A year alone stays a year: it is never turned into
     * a date, and the registration enters the correction queue.
     *
     * @return array{join_date: ?string, legacy_join_year: ?int}
     */
    private function joinFields(?string $cell): array
    {
        $cell = trim((string) $cell);

        if (preg_match('/^\d{4}$/', $cell)) {
            return ['join_date' => null, 'legacy_join_year' => (int) $cell];
        }

        $date = $this->parseDate($cell);
        if ($date === null) {
            throw new RuntimeException(__('validation.join_date_needed'));
        }

        return ['join_date' => $date, 'legacy_join_year' => null];
    }

    /** The cell's branch by any of its names or code; empty means the main branch, else the only one. */
    private function resolveBranch(Collection $branches, ?string $cell): int
    {
        $cell = mb_strtolower(trim((string) $cell));

        if ($cell === '') {
            $id = Branch::mainId() ?? ($branches->count() === 1 ? $branches->first()->id : null);
        } else {
            $id = $branches->first(fn (Branch $b) => in_array($cell, array_map('mb_strtolower', array_filter(
                [$b->name, $b->name_ar, $b->name_fr, $b->name_en, $b->code]
            )), true))?->id;
        }

        return $id ?? throw new RuntimeException(__('validation.branch_required'));
    }
```

Before the loop: `$branches = Branch::all();`. In the row, replace the `'join_year'` attribute line. The `category_id`, `position_id` and `licence_number` values stay in `$attributes` only with no branches; with branches they go on the card:

```php
                $join = $this->joinFields($data['join_year']);
                $cards = [];

                if ($branches->isEmpty()) {
                    $attributes['join_year'] = $join['join_date'] ? (int) substr($join['join_date'], 0, 4) : $join['legacy_join_year'];
                } else {
                    $branchId = $this->resolveBranch($branches, $data['branch'] ?? null);
                    $cards[] = [
                        'branch_id' => $branchId,
                        ...$join,
                        'category_id' => $attributes['category_id'] ?? null,
                        'position_id' => $attributes['position_id'] ?? null,
                        'licence_number' => $attributes['licence_number'] ?? null,
                    ];
                    unset($attributes['category_id'], $attributes['position_id'], $attributes['licence_number']);
                }

                $player = $service->handle($attributes, $cards);
```

Other-positions code stays as it is, but compares against `$cards[0]['position_id'] ?? $attributes['position_id'] ?? 0`, and each `resolvePosition` keeps its branch-preference order. Import `RuntimeException`, `App\Models\Branch`, `Illuminate\Support\Collection`.

Add to `lang/ar/validation.php`, `lang/fr/validation.php`, `lang/en/validation.php` (the existing `validation.branch_required` lives only in the JS catalogs, so the server needs its own copy):

| Key | ar | fr | en |
|---|---|---|---|
| `join_date_needed` | `تاريخ الانضمام أو سنته مطلوب.` | `La date ou l'année d'adhésion est requise.` | `A join date or year is required.` |
| `branch_required` | `يرجى اختيار فرع واحد على الأقل.` | `Veuillez choisir au moins une branche.` | `Please choose at least one branch.` |

`keys.json`:

```json
{
  "col.join_date": { "ar": "تاريخ الانضمام", "fr": "Date d'adhésion", "en": "Join date" },
  "col.hint.date_or_year": { "ar": "تاريخ كامل أو سنة", "fr": "date complète ou année", "en": "full date or year" },
  "col.branch": { "ar": "الفرع", "fr": "Branche", "en": "Branch" }
}
```

If `col.branch` already exists, `i18n-add` updates it in place; keep the existing wording by removing it from `keys.json` first (`grep '"col.branch"' resources/js/i18n/en.json`).

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Registrations/PlayerImportRegistrationTest.php tests/Feature/PlayerImportTest.php`
Old import rows with no year now need one: add `join_year` values to those fixtures.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PlayerImportController.php tests/Feature/PlayerImportTest.php tests/Feature/Registrations/PlayerImportRegistrationTest.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json lang/ar/validation.php lang/fr/validation.php lang/en/validation.php
git commit -m "feat(registrations): import reads a date or a year and a branch"
```

---

### Task 7: Player cards, player page and search

**Files:**
- Create: `resources/js/Pages/Players/Partials/RegistrationCard.vue`, `resources/js/Pages/Players/Partials/RegistrationsSection.vue`, `resources/js/lib/registrationCards.js`, `tests/js/registrationCards.test.mjs`
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue`, `resources/js/Pages/Players/Create.vue`, `resources/js/Pages/Players/Edit.vue`, `resources/js/Pages/Players/Show.vue`, `app/Http/Controllers/PlayerController.php`, `app/Models/Player.php`
- Modify tests: `tests/Feature/PlayerFormPropsTest.php`
- Test: `tests/Feature/Registrations/PlayerFormDataTest.php`

**Interfaces:**
- Consumes: `registrations` request shape from Task 4.
- Produces: props `sportingStatuses` on create and edit; `player.registrations` on edit and show, each with `branch`, `category`, `position`, `status`, `sporting_status`; JS `cardFromRegistration(registration, otherPositions, isFirst)`, `newCard(branchId, player, isFirst, statusId)`, `cardPayload(card)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Registrations/PlayerFormDataTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\SportingStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFormDataTest extends TestCase
{
    use RefreshDatabase;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        SportingStatus::create(['name' => 'Competing']);
        SportingStatus::create(['name' => 'Hidden', 'is_active' => false]);
        $this->player = Player::create(['firstname' => 'Ali', 'membership_id' => '201700001', 'join_year' => 2017]);
        $this->player->registrations()->create([
            'branch_id' => $foot->id, 'membership_id' => 'FB-2017-00001', 'category_id' => null,
            'join_date' => '2017-03-12', 'legacy_join_year' => 2017, 'legacy_membership_id' => '201700001',
        ]);
    }

    #[Test]
    public function edit_and_show_carry_registrations_and_dates_stay_plain(): void
    {
        foreach (['players.edit', 'players.show'] as $route) {
            $this->get(route($route, $this->player))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('player.registrations.0.join_date', '2017-03-12')
                    ->where('player.registrations.0.membership_id', 'FB-2017-00001')
                    ->has('player.registrations.0.branch')
                    ->etc());
        }
    }

    #[Test]
    public function the_form_offers_active_sporting_statuses_only(): void
    {
        $this->get(route('players.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sportingStatuses', 1)->etc());
    }

    #[Test]
    public function search_finds_any_registrations_id_and_the_old_one(): void
    {
        $this->assertSame(1, Player::query()->search('FB-2017')->count());
        $this->assertSame(1, Player::query()->search('201700001')->count());
        $this->assertSame(0, Player::query()->search('SW-2017')->count());
    }
}
```

`tests/js/registrationCards.test.mjs` (same runner as `tests/js/useFormFields.test.mjs`; copy its import style):

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cardFromRegistration, cardPayload, newCard } from '../../resources/js/lib/registrationCards.js';

test('an existing registration keeps its plain date and its branch\'s other positions', () => {
    const card = cardFromRegistration(
        { branch_id: 1, join_date: '2017-03-12', category_id: 2, status_id: 3, membership_id: 'FB-2017-00001', legacy_join_year: 2017 },
        [{ id: 7, branch_id: 1 }, { id: 8, branch_id: 2 }, { id: 9, branch_id: null }],
        true,
    );
    assert.equal(card.join_date, '2017-03-12');
    assert.deepEqual(card.other_position_ids, [7, 9]);
    assert.equal(card.is_new, false);
});

test('a position with no branch stays with the first card only', () => {
    const card = cardFromRegistration({ branch_id: 2 }, [{ id: 9, branch_id: null }], false);
    assert.deepEqual(card.other_position_ids, []);
});

test('the first new card of a player with no registration starts from their values', () => {
    const card = newCard(1, { category_id: 2, status_id: 3, licence_number: 'L', position_id: 5 }, true, 4);
    assert.equal(card.category_id, 2);
    assert.equal(card.status_id, 3);
    assert.equal(card.licence_number, 'L');
    assert.equal(card.join_date, '');
});

test('a later new card starts blank with the default status', () => {
    const card = newCard(2, { category_id: 2, status_id: 3 }, false, 4);
    assert.equal(card.category_id, '');
    assert.equal(card.status_id, 4);
});

test('the payload sends an empty string to clear other positions and nulls for blanks', () => {
    const payload = cardPayload({
        branch_id: 1, join_date: '', category_id: '', position_id: '', other_position_ids: [],
        licence_number: '', status_id: 3, left_at: '2026-01-01', sporting_status_id: '', is_left: true,
    });
    assert.equal(payload.other_position_ids, '');
    assert.equal(payload.join_date, null);
    assert.equal(payload.category_id, null);
    assert.equal(payload.left_at, '2026-01-01');
});

test('only a left card sends a leave date', () => {
    const payload = cardPayload({ branch_id: 1, other_position_ids: [1], left_at: '2026-01-01', is_left: false });
    assert.equal(payload.left_at, null);
    assert.deepEqual(payload.other_position_ids, [1]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Registrations/PlayerFormDataTest.php`
Expected: FAIL, `player.registrations` missing.

Run: `node --test tests/js/registrationCards.test.mjs`
Expected: FAIL, module not found.

- [ ] **Step 3: Implement the backend**

In `PlayerController::create()` and `edit()` add to props:

```php
            'sportingStatuses' => SportingStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
```

Change `edit()`'s load to include `'registrations'`. Add to `show()`'s `$player->load([...])`:

```php
            'registrations.branch',
            'registrations.category',
            'registrations.position',
            'registrations.status',
            'registrations.sportingStatus',
```

Remove `nextSequenceByYear` and `defaultJoinYear` from create and edit only if the form's no-branch section stops using them; **keep them**, as that section still previews the old format. Import `App\Models\SportingStatus`.

In `Player::scopeSearch`, inside the inner closure after the column loop:

```php
                    // Every branch's ID, and the old one from before conversion.
                    $inner->orWhereExists(fn ($q) => $q->from('branch_player')
                        ->whereColumn('branch_player.player_id', 'players.id')
                        ->where(fn ($ids) => $ids
                            ->where('branch_player.membership_id', 'like', '%'.$token.'%')
                            ->orWhere('branch_player.legacy_membership_id', 'like', '%'.$token.'%')));
```

- [ ] **Step 4: Implement the card logic**

`resources/js/lib/registrationCards.js`:

```js
/**
 * A registration's card in the player form. Dates stay plain Y-m-d strings:
 * they never pass through new Date(), which could move them across midnight.
 */
function otherPositionsFor(branchId, otherPositions, isFirst) {
    return otherPositions
        .filter((position) => (position.branch_id === null || position.branch_id === undefined
            ? isFirst
            : Number(position.branch_id) === Number(branchId)))
        .map((position) => position.id);
}

export function cardFromRegistration(registration, otherPositions = [], isFirst = false) {
    return {
        id: registration.id,
        branch_id: registration.branch_id,
        membership_id: registration.membership_id || '',
        legacy_join_year: registration.legacy_join_year ?? null,
        had_date: !!registration.join_date,
        is_new: false,
        join_date: registration.join_date || '',
        category_id: registration.category_id || '',
        position_id: registration.position_id || '',
        other_position_ids: otherPositionsFor(registration.branch_id, otherPositions, isFirst),
        licence_number: registration.licence_number || '',
        status_id: registration.status_id || '',
        left_at: registration.left_at || '',
        sporting_status_id: registration.sporting_status_id || '',
    };
}

/** A first card carries over what a player with no registration already holds. */
export function newCard(branchId, player = {}, isFirst = false, defaultStatusId = '') {
    return {
        branch_id: branchId,
        membership_id: '',
        legacy_join_year: null,
        had_date: false,
        is_new: true,
        join_date: '',
        category_id: isFirst ? (player.category_id || '') : '',
        position_id: isFirst ? (player.position_id || '') : '',
        other_position_ids: [],
        licence_number: isFirst ? (player.licence_number || '') : '',
        status_id: isFirst ? (player.status_id || defaultStatusId) : defaultStatusId,
        left_at: '',
        sporting_status_id: '',
    };
}

/** What reaches the server. '' keeps an emptied list present: forceFormData drops empty arrays. */
export function cardPayload(card) {
    return {
        branch_id: card.branch_id,
        join_date: card.join_date || null,
        category_id: card.category_id || null,
        position_id: card.position_id || null,
        other_position_ids: card.other_position_ids?.length ? card.other_position_ids : '',
        licence_number: card.licence_number || null,
        status_id: card.status_id || null,
        left_at: card.is_left ? (card.left_at || null) : null,
        sporting_status_id: card.sporting_status_id || null,
    };
}
```

`resources/js/Pages/Players/Partials/RegistrationCard.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();

const props = defineProps({
    card: { type: Object, required: true },
    index: { type: Number, required: true },
    branch: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    playerStatuses: { type: Array, default: () => [] },
    sportingStatuses: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});
defineEmits(['remove', 'delete']);

const fieldError = (field) => props.errors[`registrations.${props.index}.${field}`];
const selectClass = 'mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500';

// A stored position from another branch stays selectable until it is changed.
const branchPositions = computed(() => props.positions.filter((position) => Number(position.branch_id) === Number(props.branch.id)
    || position.branch_id === null
    || Number(position.id) === Number(props.card.position_id)));
const hasPositions = computed(() => branchPositions.value.length > 0);
const otherOptions = computed(() => branchPositions.value
    .filter((position) => Number(position.id) !== Number(props.card.position_id)
        && !props.card.other_position_ids.some((id) => Number(id) === Number(position.id)))
    .map((position) => ({ value: position.id, label: `${position.abbreviation} - ${position.name}` })));
const chosenOthers = computed(() => props.card.other_position_ids
    .map((id) => props.positions.find((position) => Number(position.id) === Number(id)))
    .filter(Boolean));

function addOther(id) {
    if (id === '' || id === null || id === undefined) return;
    if (!props.card.other_position_ids.some((value) => Number(value) === Number(id))) props.card.other_position_ids.push(Number(id));
}
function removeOther(id) {
    props.card.other_position_ids = props.card.other_position_ids.filter((value) => Number(value) !== Number(id));
}
watch(() => props.card.position_id, (id) => { removeOther(id); });

// The built-in "left" status is found by its code, never its editable name.
const isLeft = computed(() => props.playerStatuses.find((s) => Number(s.id) === Number(props.card.status_id))?.code === 'left');
watch(isLeft, (left) => {
    props.card.is_left = left;
    if (left && !props.card.left_at) props.card.left_at = localToday();
}, { immediate: true });

// Empty date on a saved row: the correction queue's case, so it may stay empty here.
const dateRequired = computed(() => props.card.is_new || props.card.had_date);
</script>

<template>
    <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
        <header class="mb-3 flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-6 w-6 rounded-sm object-contain" />
                <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ branch.localized_name || branch.name }}</h3>
                <span v-if="card.membership_id" class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ card.membership_id }}</span>
            </div>
            <IconButton v-if="card.is_new" icon="trash" :label="t('remove')" variant="danger" plain size="sm" @click="$emit('remove')" />
            <IconButton v-else icon="trash" :label="t('registration_delete')" variant="danger" plain size="sm" @click="$emit('delete')" />
        </header>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <InputLabel :value="t('join_date') + (dateRequired ? ' *' : '')" />
                <TextInput v-model="card.join_date" type="date" class="mt-1 w-full" :required="dateRequired" />
                <p v-if="!card.join_date && card.legacy_join_year" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    {{ t('join_year_only', { year: card.legacy_join_year }) }}
                </p>
                <InputError :message="fieldError('join_date')" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('category')" />
                <select v-model="card.category_id" :class="selectClass">
                    <option value="">{{ t('select_category') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.localized_name || category.name }}</option>
                </select>
                <InputError :message="fieldError('category_id')" class="mt-1" />
            </div>
            <div v-if="hasPositions">
                <InputLabel :value="t('main_position')" />
                <select v-model="card.position_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="position in branchPositions" :key="position.id" :value="position.id">{{ position.abbreviation }} - {{ position.name }}</option>
                </select>
                <InputError :message="fieldError('position_id')" class="mt-1" />
            </div>
            <div v-if="hasPositions">
                <InputLabel :value="t('other_positions')" />
                <div v-if="chosenOthers.length" class="mt-1 flex flex-wrap gap-1.5">
                    <span v-for="position in chosenOthers" :key="position.id" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ position.abbreviation }}
                        <button type="button" :aria-label="t('remove')" class="text-slate-400 hover:text-rose-500 dark:hover:text-rose-400" @click="removeOther(position.id)">&times;</button>
                    </span>
                </div>
                <SearchableSelect v-if="otherOptions.length" :model-value="''" :options="otherOptions" :placeholder="t('add_position')" class="mt-1" @update:modelValue="addOther" />
                <InputError :message="fieldError('other_position_ids')" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('licence_number')" />
                <TextInput v-model="card.licence_number" class="mt-1 w-full" maxlength="50" dir="ltr" />
                <InputError :message="fieldError('licence_number')" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('membership_status')" />
                <select v-model="card.status_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="status in playerStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                </select>
                <InputError :message="fieldError('status_id')" class="mt-1" />
            </div>
            <div v-if="isLeft">
                <InputLabel :value="t('left_at')" />
                <TextInput v-model="card.left_at" type="date" class="mt-1 w-full" />
                <InputError :message="fieldError('left_at')" class="mt-1" />
            </div>
            <div v-if="sportingStatuses.length">
                <InputLabel :value="t('sporting_status')" />
                <select v-model="card.sporting_status_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="status in sportingStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                </select>
                <InputError :message="fieldError('sporting_status_id')" class="mt-1" />
            </div>
        </div>
    </section>
</template>
```

- [ ] **Step 5: Wire the form**

In `PlayerForm.vue`:

Add imports:

```js
import RegistrationCard from '@/Pages/Players/Partials/RegistrationCard.vue';
import { cardFromRegistration, cardPayload, newCard } from '@/lib/registrationCards';
```

Add the prop `sportingStatuses: { type: Array, default: () => [] },`. In `useForm`, replace `branch_ids: ...` with:

```js
    registrations: (p.registrations || []).map((registration, index) => cardFromRegistration(registration, p.other_positions || [], index === 0)),
```

Replace the branch multiselect block (lines 72-93) with:

```js
// --- One card per branch ---
const branchPick = ref('');
const branchesById = computed(() => Object.fromEntries(props.branches.map((b) => [b.id, b])));
const branchOptions = computed(() => {
    const taken = new Set(form.registrations.map((card) => card.branch_id));
    return props.branches
        .filter((b) => !taken.has(b.id))
        .map((b) => ({ value: b.id, label: b.localized_name || b.name }));
});
const defaultStatusId = computed(() => props.playerStatuses[0]?.id ?? '');
function addBranch() {
    if (!branchPick.value) return;
    const id = Number(branchPick.value);
    if (!form.registrations.some((card) => card.branch_id === id)) {
        form.registrations.push(newCard(id, p, form.registrations.length === 0 && isEdit, defaultStatusId.value));
    }
    branchPick.value = '';
}
function removeCard(index) {
    form.registrations.splice(index, 1);
}
const usesCards = computed(() => props.branches.length > 0);

// Deleting is for a registration added by mistake; a real departure is a status.
const deletingCard = ref(null);
function deleteCard() {
    const card = deletingCard.value;
    deletingCard.value = null;
    router.delete(route('players.registrations.destroy', [p.id, card.id]), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error) form.registrations = form.registrations.filter((c) => c.id !== card.id);
        },
    });
}
```

Change the Inertia import to `import { Link, router, useForm } from '@inertiajs/vue3';`, import `ConfirmModal from '@/Components/ConfirmModal.vue'`, and add beside `JobQuickCreateModal`, outside the `<form>`:

```vue
    <ConfirmModal :show="!!deletingCard" :message="t('registration_delete_confirm')" @confirm="deleteCard" @cancel="deletingCard = null" />
```

Check the shared flash prop's shape in `app/Http/Middleware/HandleInertiaRequests.php` and match it in `onSuccess`.

Delete the membership preview only for the branched case: wrap it by leaving the code in place, used by the no-branch section.

In `submit()`, replace the `branch_ids` check with:

```js
    form.clearErrors('registrations');
    if (usesCards.value && !form.registrations.length) {
        form.setError('registrations', t('validation.branch_required'));
        return;
    }
```

In the transform, replace `licence_number`, `status_id`, `left_at`, `category_id`, `position_id`, `other_position_ids`, `branch_ids` and `join_year` with:

```js
        // A club with no branches keeps the flat fields; otherwise each card holds them.
        ...(usesCards.value
            ? { registrations: data.registrations.map(cardPayload) }
            : {
                licence_number: data.licence_number || null,
                status_id: data.status_id || null,
                // Only a "left" member has a leave date; empty lets the server stamp today.
                left_at: isLeftStatus.value ? (data.left_at || null) : null,
                category_id: data.category_id || null,
                position_id: data.position_id || null,
                // '' keeps an emptied list present: forceFormData drops empty arrays.
                other_position_ids: data.other_position_ids.length ? data.other_position_ids : '',
                join_year: data.join_year || null,
            }),
```

In the template:
- Wrap the licence field in `<div v-if="!usesCards">`.
- In "Club details", put `v-if="!usesCards"` on the membership ID, join year, category, main position, other positions and membership status fields, and on the left-at field combine as `v-if="!usesCards && isLeftStatus"`. Student/worker, job and archived stay for everyone.
- Replace the branches block with:

```vue
                <div class="sm:col-span-2 lg:col-span-3">
                    <InputLabel :value="t('branches') + (branches.length ? ' *' : '')" />
                    <SearchableSelect v-if="branchOptions.length" v-model="branchPick" :options="branchOptions" :placeholder="t('add_branch')" class="mt-1" @update:modelValue="addBranch" />
                    <InputError :message="form.errors.registrations" class="mt-1" />
                    <p v-if="!branches.length" class="mt-1 text-xs text-slate-400">
                        {{ t('no_branches_hint') }}
                        <Link :href="route('branches.index')" class="text-primary-600 hover:underline dark:text-primary-300">{{ t('branches') }}</Link>
                    </p>
                </div>
                <div v-if="form.registrations.length" class="space-y-4 sm:col-span-2 lg:col-span-3">
                    <RegistrationCard
                        v-for="(card, index) in form.registrations"
                        :key="card.branch_id"
                        :card="card"
                        :index="index"
                        :branch="branchesById[card.branch_id] || { id: card.branch_id, name: `#${card.branch_id}` }"
                        :categories="categories"
                        :positions="positions"
                        :player-statuses="playerStatuses"
                        :sporting-statuses="sportingStatuses"
                        :errors="form.errors"
                        @remove="removeCard(index)"
                        @delete="deletingCard = card"
                    />
                </div>
```

Keep the existing `watch(() => form.position_id, ...)` and the `form.other_position_ids` state for the no-branch section. Keep `other_position_ids` etc. in `useForm`.

Add `sportingStatuses` to `defineProps` in `Create.vue` and `Edit.vue` and pass `:sporting-statuses="sportingStatuses"` to `PlayerForm`.

- [ ] **Step 6: Show registrations on the player page**

`resources/js/Pages/Players/Partials/RegistrationsSection.vue`:

```vue
<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    registrations: { type: Array, default: () => [] },
    /** The page's own date formatter, so dates read the same everywhere. */
    formatDate: { type: Function, required: true },
});

const named = (item) => item?.localized_name || item?.name || '—';
</script>

<template>
    <ul v-if="registrations.length" class="mt-5 space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
        <li v-for="registration in registrations" :key="registration.id" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                    <img v-if="registration.branch?.logo_url" :src="registration.branch.logo_url" alt="" class="h-5 w-5 rounded-sm object-contain" />
                    {{ named(registration.branch) }}
                </p>
                <span class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ registration.membership_id || '—' }}</span>
            </div>
            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                <div>
                    <dt class="inline text-slate-500 dark:text-slate-400">{{ t('join_date') }}:</dt>
                    <dd class="ms-1 inline">
                        <template v-if="registration.join_date">{{ formatDate(registration.join_date) }}</template>
                        <span v-else-if="registration.legacy_join_year" class="text-amber-600 dark:text-amber-400">{{ t('join_year_only', { year: registration.legacy_join_year }) }}</span>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('category') }}:</dt> <dd class="ms-1 inline">{{ named(registration.category) }}</dd></div>
                <div v-if="registration.position"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('main_position') }}:</dt> <dd class="ms-1 inline">{{ registration.position.name }}</dd></div>
                <div v-if="registration.licence_number"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('licence_number') }}:</dt> <dd class="ms-1 inline" dir="ltr">{{ registration.licence_number }}</dd></div>
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('membership_status') }}:</dt> <dd class="ms-1 inline">{{ named(registration.status) }}</dd></div>
                <div v-if="registration.left_at"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('left_at') }}:</dt> <dd class="ms-1 inline">{{ formatDate(registration.left_at) }}</dd></div>
                <div v-if="registration.sporting_status"><dt class="inline text-slate-500 dark:text-slate-400">{{ t('sporting_status') }}:</dt> <dd class="ms-1 inline">{{ named(registration.sporting_status) }}</dd></div>
            </dl>
        </li>
    </ul>
</template>
```

In `Show.vue`: import the section. Put `v-if="!player.registrations?.length"` on the rows for main position, category, membership status, left-at, licence number, join year and branches, so a player with registrations shows these only on their cards. Immediately after the closing `</dl>`:

```vue
                    <RegistrationsSection :registrations="player.registrations" :format-date="formatDate" />
```

- [ ] **Step 7: i18n and tests**

`keys.json`, then `node scripts/i18n-add.mjs keys.json`:

```json
{
  "join_year_only": { "ar": "السنة فقط: {year} (بحاجة إلى تحقق)", "fr": "Année seule : {year} (à vérifier)", "en": "Year only: {year} (needs checking)" },
  "registration_delete": { "ar": "حذف التسجيل (خطأ في الإدخال)", "fr": "Supprimer l'inscription (erreur de saisie)", "en": "Delete registration (added by mistake)" },
  "registration_delete_confirm": { "ar": "حذف هذا التسجيل نهائيا؟ إذا غادر اللاعب الفرع، غير حالته بدلا من ذلك.", "fr": "Supprimer définitivement cette inscription ? Si le joueur a quitté la branche, changez plutôt son statut.", "en": "Delete this registration for good? If the player left the branch, change their status instead." }
}
```

Run: `php artisan test tests/Feature/Registrations/PlayerFormDataTest.php tests/Feature/PlayerFormPropsTest.php`
Expected: PASS.

Run: `node --test tests/js/registrationCards.test.mjs`
Expected: PASS, 6 tests.

Run: `npm run i18n:check; npm run build`
Expected: both succeed.

- [ ] **Step 8: Browser check**

Using the headless Chrome CDP QA script (absolute output dir, wait for a real element) on a worktree DB copy at light and dark, 320 px and desktop, in Arabic:
- Create a player with two branches and different dates. Football shows positions; a branch with no positions shows none.
- Edit a legacy player: the year-only note shows, and saving without a date succeeds.
- Add a branch to a legacy player without a date: the browser refuses.
- A left status reveals the leave date, filled with today.
- Delete a mistaken second registration; the card goes. Deleting the last one shows the refusal and the card stays.

Expected: no console errors, no sideways scroll, and the Laravel log stays clean.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Players/Partials/RegistrationCard.vue resources/js/Pages/Players/Partials/RegistrationsSection.vue resources/js/lib/registrationCards.js tests/js/registrationCards.test.mjs resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Create.vue resources/js/Pages/Players/Edit.vue resources/js/Pages/Players/Show.vue app/Http/Controllers/PlayerController.php app/Models/Player.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/PlayerFormPropsTest.php tests/Feature/Registrations/PlayerFormDataTest.php
git commit -m "feat(registrations): one card per branch on the player form and page"
```

---

### Task 8: Join date correction queue

**Files:**
- Create: `app/Http/Controllers/JoinDateController.php`, `resources/js/Pages/Players/JoinDates.vue`
- Modify: `routes/web.php`, `config/activity.php`, `resources/js/Pages/Players/Index.vue`
- Test: `tests/Feature/Registrations/JoinDateQueueTest.php`

**Interfaces:**
- Consumes: `RegistrationService::setJoinDate`, scope `needsJoinDate`.
- Produces: `GET players.join-dates.index`, `PUT players.join-dates.update` with `{registration}`. Module `players`; the middleware derives `view` and `edit`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JoinDateQueueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function member(string $name, int $year, array $branches): Player
    {
        $player = Player::create(['firstname' => $name, 'membership_id' => $year.substr(md5($name), 0, 5), 'join_year' => $year]);
        $player->branches()->attach($branches);

        return $player;
    }

    #[Test]
    public function two_branch_players_come_first_and_exact_ones_stay_out(): void
    {
        $this->member('Single', 2017, [$this->foot->id]);
        $this->member('Double', 2019, [$this->foot->id, $this->swim->id]);
        $exact = $this->member('Exact', 2020, [$this->foot->id]);
        $exact->registrations()->update(['join_date' => '2020-05-05']);

        $this->get(route('players.join-dates.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Players/JoinDates')
                ->where('total', 3)
                ->where('registrations.data.0.player_name', fn ($name) => str_contains($name, 'Double'))
                ->where('registrations.data.1.player_name', fn ($name) => str_contains($name, 'Double'))
                ->where('registrations.data.2.player_name', fn ($name) => str_contains($name, 'Single')));
    }

    #[Test]
    public function it_filters_by_branch_and_year(): void
    {
        $this->member('A', 2017, [$this->foot->id]);
        $this->member('B', 2019, [$this->swim->id]);

        $this->get(route('players.join-dates.index', ['branch_id' => $this->swim->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 1)->etc());
        $this->get(route('players.join-dates.index', ['year' => 2017]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations.data', 1)->etc());
    }

    #[Test]
    public function the_entry_date_is_only_a_hint_when_its_year_matches(): void
    {
        $match = $this->member('Match', 2026, [$this->foot->id]);
        $other = $this->member('Other', 2017, [$this->foot->id]);
        DB::table('players')->where('id', $match->id)->update(['created_at' => '2026-10-24 10:00:00']);
        DB::table('players')->where('id', $other->id)->update(['created_at' => '2026-10-24 10:00:00']);

        $this->get(route('players.join-dates.index', ['year' => 2026]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations.data.0.hint', '2026-10-24')->etc());
        $this->get(route('players.join-dates.index', ['year' => 2017]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations.data.0.hint', null)->etc());
        $this->assertSame(2, BranchRegistration::needsJoinDate()->count());
    }

    #[Test]
    public function saving_sets_the_date_and_rejects_bad_ones(): void
    {
        $player = $this->member('A', 2017, [$this->foot->id]);
        $player->update(['birthdate' => '2010-01-01']);
        $registration = $player->registrations()->firstOrFail();

        $this->put(route('players.join-dates.update', $registration), ['join_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('join_date');
        $this->put(route('players.join-dates.update', $registration), ['join_date' => '2009-12-31'])
            ->assertSessionHasErrors('join_date');

        $this->put(route('players.join-dates.update', $registration), ['join_date' => '2017-03-12'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2017-03-12', $registration->fresh()->join_date->toDateString());
    }

    #[Test]
    public function it_needs_player_rights(): void
    {
        $viewer = User::factory()->create(['privileges' => [], 'is_active' => true, 'email_verified_at' => now()]);
        $registration = $this->member('A', 2017, [$this->foot->id])->registrations()->firstOrFail();

        $this->actingAs($viewer)->get(route('players.join-dates.index'))->assertForbidden();
        $this->actingAs($viewer)->put(route('players.join-dates.update', $registration), ['join_date' => '2017-03-12'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/JoinDateQueueTest.php`
Expected: FAIL, route `players.join-dates.index` not defined.

- [ ] **Step 3: Implement the backend**

`app/Http/Controllers/JoinDateController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Registrations with no exact join date, for an admin to complete. */
class JoinDateController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
        ]);

        $base = BranchRegistration::query()->needsJoinDate()
            ->whereHas('player', fn ($q) => $q->where('archived', false));

        $query = (clone $base)
            ->select('branch_player.*')
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('legacy_join_year', $year))
            ->with([
                'player:id,created_at,'.implode(',', Player::NAME_COLUMNS),
                'branch:id,name,name_ar,name_fr,name_en',
                'category:id,name,name_ar,name_fr,name_en',
            ])
            ->addSelect(['two_branches' => DB::table('branch_player as other')
                ->selectRaw('COUNT(*) > 1')
                ->whereColumn('other.player_id', 'branch_player.player_id')])
            // One year shared by two sports is the least certain, so it goes first.
            ->orderByDesc('two_branches')
            ->orderBy('player_id')
            ->orderBy('branch_id');

        return Inertia::render('Players/JoinDates', [
            'total' => (clone $base)->count(),
            'filters' => $filters,
            'branches' => Branch::orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'categories' => Category::orderBy('name')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']),
            'years' => (clone $base)->whereNotNull('legacy_join_year')->distinct()->orderByDesc('legacy_join_year')->pluck('legacy_join_year'),
            'registrations' => $query->paginate(50)->withQueryString()
                ->through(fn (BranchRegistration $r) => [
                    'id' => $r->id,
                    'player_id' => $r->player_id,
                    'player_name' => $r->player->fullname,
                    'branch' => $r->branch->localized_name,
                    'category' => $r->category?->localized_name,
                    'membership_id' => $r->membership_id,
                    'year' => $r->legacy_join_year,
                    'two_branches' => (bool) $r->two_branches,
                    'hint' => $this->hint($r),
                ]),
        ]);
    }

    public function update(Request $request, BranchRegistration $registration, RegistrationService $registrations): RedirectResponse
    {
        $data = $request->validate([
            'join_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $birthdate = $registration->player->birthdate?->toDateString();
        if ($birthdate !== null && $data['join_date'] < $birthdate) {
            throw ValidationException::withMessages(['join_date' => __('validation.join_date_before_birth')]);
        }

        $registrations->setJoinDate($registration, $data['join_date'], $request->user());

        return back()->with('success', 'flash.join_date_saved');
    }

    /** When the record was entered, if that year fits. A suggestion only: an admin must confirm it. */
    private function hint(BranchRegistration $registration): ?string
    {
        $entered = $registration->player->created_at;

        return $entered !== null && $entered->year === $registration->legacy_join_year
            ? $entered->toDateString()
            : null;
    }
}
```

`Branch` and `Category` use `HasLocalizedName`, whose accessor reads the columns selected above.

In `routes/web.php`, before `Route::resource('players', ...)`:

```php
    Route::get('/players/join-dates', [JoinDateController::class, 'index'])->name('players.join-dates.index');
    Route::put('/players/join-dates/{registration}', [JoinDateController::class, 'update'])->name('players.join-dates.update');
```

`{registration}` binds through `BranchRegistration` by the method's type hint. In `config/activity.php`, beside `players.update`:

```php
    'players.join-dates.update' => ActivityAction::PLAYER_UPDATED,
```

- [ ] **Step 4: Implement the page**

`resources/js/Pages/Players/JoinDates.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();

const props = defineProps({
    total: Number,
    registrations: Object,
    filters: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
});

const dates = reactive({});
const errors = reactive({});
const today = localToday();
const selectClass = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500';

const filter = ref({
    branch_id: props.filters.branch_id ?? '',
    category_id: props.filters.category_id ?? '',
    year: props.filters.year ?? '',
});

function applyFilter() {
    router.get(route('players.join-dates.index'), Object.fromEntries(Object.entries(filter.value).filter(([, value]) => value !== '')), { preserveState: true, replace: true });
}

function save(row) {
    if (!dates[row.id]) return;
    router.put(route('players.join-dates.update', row.id), { join_date: dates[row.id] }, {
        preserveScroll: true,
        onError: (e) => { errors[row.id] = e.join_date; },
        onSuccess: () => { delete errors[row.id]; delete dates[row.id]; },
    });
}
</script>

<template>
    <Head :title="t('join_dates_queue')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('join_dates_queue') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('join_dates_queue_intro', { count: total }) }}</p>

            <div class="flex flex-wrap gap-2">
                <select v-model="filter.branch_id" :class="selectClass" :aria-label="t('branch')" @change="applyFilter">
                    <option value="">{{ t('branch') }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.localized_name || branch.name }}</option>
                </select>
                <select v-model="filter.category_id" :class="selectClass" :aria-label="t('category')" @change="applyFilter">
                    <option value="">{{ t('category') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.localized_name || category.name }}</option>
                </select>
                <select v-model="filter.year" :class="selectClass" :aria-label="t('join_year')" @change="applyFilter">
                    <option value="">{{ t('join_year') }}</option>
                    <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                </select>
            </div>

            <ul class="divide-y divide-slate-100 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                <li v-for="row in registrations.data" :key="row.id" class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-2 sm:items-center">
                    <div class="min-w-0">
                        <Link :href="route('players.show', row.player_id)" class="block break-words font-medium text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300">{{ row.player_name }}</Link>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ row.branch }}<template v-if="row.category"> · {{ row.category }}</template>
                            <template v-if="row.year"> · {{ row.year }}</template>
                        </p>
                        <p v-if="row.two_branches" class="text-xs text-amber-600 dark:text-amber-400">{{ t('join_dates_shared_year') }}</p>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <TextInput v-model="dates[row.id]" type="date" class="min-w-0 flex-1" :max="today" :aria-label="t('join_date')" @keyup.enter="save(row)" />
                            <button type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50" :disabled="!dates[row.id]" @click="save(row)">{{ t('save') }}</button>
                        </div>
                        <button v-if="row.hint" type="button" class="mt-1 text-xs text-primary-600 hover:underline dark:text-primary-300" @click="dates[row.id] = row.hint">
                            {{ t('join_dates_hint', { date: row.hint }) }}
                        </button>
                        <p v-if="errors[row.id]" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ errors[row.id] }}</p>
                    </div>
                </li>
                <li v-if="!registrations.data.length" class="p-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('join_dates_none') }}</li>
            </ul>

            <div v-if="registrations.prev_page_url || registrations.next_page_url" class="flex justify-between">
                <Link v-if="registrations.prev_page_url" :href="registrations.prev_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('previous') }}</Link>
                <span v-else />
                <Link v-if="registrations.next_page_url" :href="registrations.next_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('next') }}</Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

In `Players/Index.vue`, add beside the export buttons (`can('players', 'edit')` gate):

```vue
<Link v-if="can('players', 'edit')" :href="route('players.join-dates.index')" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('join_dates_queue') }}</Link>
```

Use the page's existing `can` if present; otherwise import `useCan` as `PlayerForm.vue` does.

`keys.json`, then `node scripts/i18n-add.mjs keys.json`. Check `previous`, `next`, `branch` exist before adding (`grep`):

```json
{
  "join_dates_queue": { "ar": "استكمال تواريخ الانضمام", "fr": "Dates d'adhésion à compléter", "en": "Join dates to complete" },
  "join_dates_queue_intro": { "ar": "{count} تسجيل بدون تاريخ دقيق. لا يظهر هؤلاء في الإحصائيات الشهرية والذكريات السنوية حتى يكتمل التاريخ.", "fr": "{count} inscriptions sans date exacte. Elles restent hors des statistiques mensuelles et des anniversaires jusqu'à ce que la date soit saisie.", "en": "{count} registrations have no exact date. They stay out of monthly figures and anniversaries until the date is entered." },
  "join_dates_shared_year": { "ar": "سنة واحدة لفرعين: تحقق من كل فرع.", "fr": "Une seule année pour deux branches : à vérifier pour chacune.", "en": "One year shared by two branches: check each one." },
  "join_dates_hint": { "ar": "تاريخ الإدخال في التطبيق: {date}. استعماله؟", "fr": "Saisi dans l'application le {date}. L'utiliser ?", "en": "Entered in the app on {date}. Use it?" },
  "join_dates_none": { "ar": "لا توجد تواريخ ناقصة.", "fr": "Aucune date à compléter.", "en": "No dates to complete." },
  "flash.join_date_saved": { "ar": "تم حفظ تاريخ الانضمام.", "fr": "Date d'adhésion enregistrée.", "en": "Join date saved." }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Registrations/JoinDateQueueTest.php tests/Unit/PermissionMapTest.php`
Expected: PASS, confirming `players.join-dates.index` resolves to `['players', 'view']`.

Run: `npm run i18n:check; npm run build`
Expected: both succeed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/JoinDateController.php resources/js/Pages/Players/JoinDates.vue routes/web.php config/activity.php resources/js/Pages/Players/Index.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/JoinDateQueueTest.php
git commit -m "feat(registrations): a queue to complete missing join dates"
```

---

### Task 9: Sporting statuses settings

**Files:**
- Create: `app/Http/Controllers/SportingStatusController.php`, `resources/js/Pages/Settings/SportingStatuses.vue`
- Modify: `routes/web.php`, `config/permissions.php`, `config/activity.php`, `resources/js/Pages/Settings/PlayerStatuses.vue`
- Test: `tests/Feature/Registrations/SportingStatusSettingsTest.php`

**Interfaces:**
- Produces: resource routes `sporting-statuses.index|store|update|destroy`, under the existing `player_statuses` permission, so no new right starts empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\SportingStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SportingStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function an_admin_manages_the_list(): void
    {
        $this->post(route('sporting-statuses.store'), ['name' => 'Competing', 'name_ar' => 'منافس', 'is_active' => true])
            ->assertSessionHasNoErrors();
        $status = SportingStatus::firstOrFail();

        $this->put(route('sporting-statuses.update', $status), ['name' => 'Recreational', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertSame('Recreational', $status->fresh()->name);

        $this->delete(route('sporting-statuses.destroy', $status))->assertSessionHasNoErrors();
        $this->assertSame(0, SportingStatus::count());
    }

    #[Test]
    public function one_in_use_cannot_be_deleted(): void
    {
        $status = SportingStatus::create(['name' => 'Competing']);
        $branch = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001']);
        $player->registrations()->create(['branch_id' => $branch->id, 'category_id' => null, 'sporting_status_id' => $status->id]);

        $this->delete(route('sporting-statuses.destroy', $status))->assertSessionHas('error', 'flash.sporting_status_in_use');
        $this->assertNotNull($status->fresh());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/SportingStatusSettingsTest.php`
Expected: FAIL, route not defined.

- [ ] **Step 3: Implement**

`app/Http/Controllers/SportingStatusController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SportingStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SportingStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/SportingStatuses', [
            'sportingStatuses' => SportingStatus::withCount('registrations')
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SportingStatus::create($this->validated($request));

        return back()->with('success', 'flash.sporting_status_created');
    }

    public function update(Request $request, SportingStatus $sportingStatus): RedirectResponse
    {
        $sportingStatus->update($this->validated($request, $sportingStatus));

        return back()->with('success', 'flash.sporting_status_updated');
    }

    public function destroy(SportingStatus $sportingStatus): RedirectResponse
    {
        if ($sportingStatus->registrations()->exists()) {
            return back()->with('error', 'flash.sporting_status_in_use');
        }

        $sportingStatus->delete();

        return back()->with('success', 'flash.sporting_status_deleted');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SportingStatus $existing = null): array
    {
        $unique = 'unique:sporting_statuses,name'.($existing ? ','.$existing->id : '');

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
```

Routes, beside `player-statuses`:

```php
        Route::resource('sporting-statuses', SportingStatusController::class)->except(['show', 'create', 'edit']);
```

`config/permissions.php` `modules`, beside `'player-statuses' => 'player_statuses',`:

```php
        'sporting-statuses' => 'player_statuses',
```

`config/activity.php` `$settingLists`, beside `player-statuses`:

```php
    'sporting-statuses' => 'sporting_statuses',
```

Find how the `kind` labels `player_statuses` and add a twin: `grep -rn '"activity.kind\|kind.player_statuses\|player_statuses' resources/js/i18n/en.json resources/js/lib app/Services/Activity`.

Create the page from the existing one:

```bash
sed -e "s/playerStatuses/sportingStatuses/g" -e "s/player-statuses/sporting-statuses/g" -e "s/players_count/registrations_count/g" -e "s/t('player_statuses')/t('sporting_statuses')/g" resources/js/Pages/Settings/PlayerStatuses.vue > resources/js/Pages/Settings/SportingStatuses.vue
```

Read the result. Remove any branch on `status.code` (sporting statuses have no built-in codes), and use `t('sporting_statuses')` for every heading. Add at the top of each of the two pages, under the header:

```vue
<div class="flex gap-3 text-sm">
    <Link :href="route('player-statuses.index')" class="font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('player_statuses') }}</Link>
    <Link :href="route('sporting-statuses.index')" class="font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('sporting_statuses') }}</Link>
</div>
```

Add `Link` to their `@inertiajs/vue3` import if missing. `keys.json`:

```json
{
  "sporting_statuses": { "ar": "الحالات الرياضية", "fr": "Statuts sportifs", "en": "Sporting statuses" },
  "flash.sporting_status_created": { "ar": "تمت إضافة الحالة الرياضية.", "fr": "Statut sportif ajouté.", "en": "Sporting status added." },
  "flash.sporting_status_updated": { "ar": "تم تحديث الحالة الرياضية.", "fr": "Statut sportif mis à jour.", "en": "Sporting status updated." },
  "flash.sporting_status_deleted": { "ar": "تم حذف الحالة الرياضية.", "fr": "Statut sportif supprimé.", "en": "Sporting status deleted." },
  "flash.sporting_status_in_use": { "ar": "لا يمكن الحذف: هذه الحالة مستعملة في تسجيلات.", "fr": "Suppression impossible : des inscriptions utilisent ce statut.", "en": "Can't delete: registrations use this status." }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Registrations/SportingStatusSettingsTest.php tests/Unit/PermissionMapTest.php; php artisan test --filter=PlayerStatus`
Expected: PASS.

Run: `npm run i18n:check; npm run build`
Expected: both succeed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SportingStatusController.php resources/js/Pages/Settings/SportingStatuses.vue resources/js/Pages/Settings/PlayerStatuses.vue routes/web.php config/permissions.php config/activity.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/SportingStatusSettingsTest.php
git commit -m "feat(registrations): an editable list of sporting statuses"
```

---

### Task 10: Membership ID conversion

**Files:**
- Create: `app/Services/Player/MembershipIdConversion.php`, `app/Http/Controllers/MembershipIdController.php`, `resources/js/Pages/Settings/MembershipIds.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/BranchController.php`, `resources/js/Pages/Settings/Branches.vue`
- Test: `tests/Feature/Registrations/MembershipIdConversionTest.php`

**Interfaces:**
- Consumes: `MembershipNumber::compose`, `MembershipNumber::isLegacy`, `RegistrationService::refreshMirror`, `ActivityAction::MEMBERSHIP_IDS_CONVERTED`.
- Produces: `MembershipIdConversion::plan(): array{changes: list<array{registration_id:int, player_id:int, player_name:string, branch:string, from:?string, to:string, year_changed:bool}>, skipped: list<array{registration_id:int, player_name:string}>}`, `convert(?User $user): int`, `undo(?User $user): int`. Superadmin routes `membership-ids.index|convert|undo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipIdConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipIdConversionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private MembershipIdConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->conversion = app(MembershipIdConversion::class);
    }

    /** @param list<array{0:Branch, 1:?string}> $links */
    private function member(string $id, int $year, array $links): Player
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => $year]);
        foreach ($links as [$branch, $registrationId]) {
            $player->registrations()->create([
                'branch_id' => $branch->id, 'membership_id' => $registrationId,
                'legacy_join_year' => $year, 'category_id' => null,
            ]);
        }

        return $player;
    }

    #[Test]
    public function it_keeps_the_year_and_number(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);

        $this->conversion->convert(null);

        $row = $player->registrations()->firstOrFail();
        $this->assertSame('FB-2022-00017', $row->membership_id);
        $this->assertSame('202200017', $row->legacy_membership_id);
        $this->assertSame('FB-2022-00017', $player->fresh()->membership_id);
    }

    #[Test]
    public function an_id_whose_year_differs_follows_the_join_year_and_the_plan_lists_it(): void
    {
        $this->member('201800012', 2019, [[$this->foot, '201800012']]);

        $plan = $this->conversion->plan();

        $this->assertSame('FB-2019-00012', $plan['changes'][0]['to']);
        $this->assertTrue($plan['changes'][0]['year_changed']);
    }

    #[Test]
    public function a_second_registration_gets_a_fresh_number_and_matching_years_keep_theirs(): void
    {
        $double = $this->member('202200001', 2022, [[$this->foot, '202200001'], [$this->swim, null]]);
        $swimmer = $this->member('202200002', 2022, [[$this->swim, '202200002']]);

        $this->conversion->convert(null);

        $this->assertSame('SW-2022-00002', $swimmer->registrations()->first()->membership_id);
        $this->assertSame('FB-2022-00001', $double->registrations()->where('branch_id', $this->foot->id)->first()->membership_id);
        $this->assertSame('SW-2022-00003', $double->registrations()->where('branch_id', $this->swim->id)->first()->membership_id);
    }

    #[Test]
    public function a_taken_number_moves_to_the_next_free_one(): void
    {
        $this->member('201900005', 2019, [[$this->foot, '201900005']]);
        $moved = $this->member('201800005', 2019, [[$this->foot, '201800005']]);

        $this->conversion->convert(null);

        $this->assertSame('FB-2019-00006', $moved->registrations()->first()->membership_id);
    }

    #[Test]
    public function running_twice_changes_nothing_and_new_ids_are_left_alone(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);
        $new = $this->member('FB-2026-00001', 2026, [[$this->foot, 'FB-2026-00001']]);

        $this->assertSame(1, $this->conversion->convert(null));
        $this->assertSame(0, $this->conversion->convert(null));

        $this->assertSame('FB-2022-00017', $player->registrations()->first()->membership_id);
        $this->assertNull($new->registrations()->first()->legacy_membership_id);
    }

    #[Test]
    public function a_registration_with_no_year_is_skipped_and_reported(): void
    {
        $this->member('000000001', 0, []);
        $player = Player::firstOrFail();
        $player->registrations()->create(['branch_id' => $this->foot->id, 'membership_id' => '000000001', 'legacy_join_year' => null, 'category_id' => null]);

        $plan = $this->conversion->plan();

        $this->assertSame([], $plan['changes']);
        $this->assertCount(1, $plan['skipped']);
    }

    #[Test]
    public function undo_restores_old_ids_and_the_player_copy(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);
        $this->conversion->convert(null);

        $this->assertSame(1, $this->conversion->undo(null));

        $this->assertSame('202200017', $player->registrations()->first()->membership_id);
        $this->assertNull($player->registrations()->first()->legacy_membership_id);
        $this->assertSame('202200017', $player->fresh()->membership_id);
    }

    #[Test]
    public function only_a_superadmin_reaches_it(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->get(route('membership-ids.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('membership-ids.convert'))->assertForbidden();
        $this->assertSame(0, BranchRegistration::count());
    }
}
```

Check how `superadmin` is satisfied in an existing test (`grep -rn "finance.reset" tests`) and match its forbidden status (403 or redirect) in the last test.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/MembershipIdConversionTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Services/Player/MembershipIdConversion.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Old IDs (202200017) become FB-2022-00017. A separate reviewed action, since
 * the branch codes must be chosen first. Can run again, and can be undone from
 * legacy_membership_id.
 */
class MembershipIdConversion
{
    public function __construct(private readonly RegistrationService $registrations) {}

    /**
     * IDs whose year already matches go first, so they keep their numbers; then
     * those whose year changes; rows with no ID last, taking fresh numbers.
     *
     * @return array{changes: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function plan(): array
    {
        $rows = BranchRegistration::query()
            ->with(['branch:id,code,name,name_ar,name_fr,name_en', 'player:id,'.implode(',', Player::NAME_COLUMNS)])
            ->orderBy('id')
            ->get();

        $taken = $rows->pluck('membership_id')->filter(fn ($id) => $id !== null && ! MembershipNumber::isLegacy($id))->flip()->all();
        $changes = [];
        $skipped = [];

        $pending = $rows->filter(fn (BranchRegistration $r) => $r->membership_id === null || MembershipNumber::isLegacy($r->membership_id));

        foreach ($pending->sortBy(fn (BranchRegistration $r) => [$this->phase($r), $r->id]) as $row) {
            $year = $row->effectiveYear();
            if ($year === null) {
                $skipped[] = ['registration_id' => $row->id, 'player_name' => $row->player->fullname];

                continue;
            }

            $to = $this->newId($row, $year, $taken);
            $taken[$to] = true;
            $changes[] = [
                'registration_id' => $row->id,
                'player_id' => $row->player_id,
                'player_name' => $row->player->fullname,
                'branch' => $row->branch->localized_name,
                'from' => $row->membership_id,
                'to' => $to,
                'year_changed' => $row->membership_id !== null && substr($row->membership_id, 0, 4) !== sprintf('%04d', $year),
            ];
        }

        return ['changes' => $changes, 'skipped' => $skipped];
    }

    public function convert(?User $user): int
    {
        $changes = $this->plan()['changes'];

        DB::transaction(function () use ($changes, $user) {
            foreach ($changes as $change) {
                BranchRegistration::whereKey($change['registration_id'])->update([
                    'membership_id' => $change['to'],
                    'legacy_membership_id' => $change['from'],
                ]);
            }
            Player::whereIn('id', array_unique(array_column($changes, 'player_id')))->get()
                ->each(fn (Player $player) => $this->registrations->refreshMirror($player));

            if ($changes !== []) {
                ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_IDS_CONVERTED, null, ['count' => count($changes)]);
            }
        });

        return count($changes);
    }

    /** Rows that held no old ID keep their new one: it is still valid. */
    public function undo(?User $user): int
    {
        $rows = BranchRegistration::whereNotNull('legacy_membership_id')->get();

        DB::transaction(function () use ($rows, $user) {
            foreach ($rows as $row) {
                BranchRegistration::whereKey($row->id)->update([
                    'membership_id' => $row->legacy_membership_id,
                    'legacy_membership_id' => null,
                ]);
            }
            Player::whereIn('id', $rows->pluck('player_id')->unique())->get()
                ->each(fn (Player $player) => $this->registrations->refreshMirror($player));

            if ($rows->isNotEmpty()) {
                ActivityRecorder::record($user, ActivityAction::MEMBERSHIP_IDS_CONVERTED, null, ['count' => $rows->count(), 'undone' => true]);
            }
        });

        return $rows->count();
    }

    private function phase(BranchRegistration $row): int
    {
        if ($row->membership_id === null) {
            return 2;
        }

        return substr($row->membership_id, 0, 4) === sprintf('%04d', (int) $row->effectiveYear()) ? 0 : 1;
    }

    /** @param  array<string, bool>  $taken */
    private function newId(BranchRegistration $row, int $year, array $taken): string
    {
        $code = $row->branch->code;

        if ($row->membership_id !== null && preg_match('/^\d{9}$/', $row->membership_id) === 1) {
            $kept = MembershipNumber::compose($code, $year, (int) substr($row->membership_id, 4));
            if (! isset($taken[$kept])) {
                return $kept;
            }
        }

        $prefix = sprintf('%s-%04d-', $code, $year);
        $max = 0;
        foreach (array_keys($taken) as $id) {
            if (str_starts_with($id, $prefix)) {
                $max = max($max, (int) substr($id, strlen($prefix)));
            }
        }

        return MembershipNumber::compose($code, $year, $max + 1);
    }
}
```

`app/Http/Controllers/MembershipIdController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Services\Player\MembershipIdConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipIdController extends Controller
{
    public function index(MembershipIdConversion $conversion): Response
    {
        $plan = $conversion->plan();

        return Inertia::render('Settings/MembershipIds', [
            'branches' => Branch::orderBy('name')->get(['id', 'code', 'name', 'name_ar', 'name_fr', 'name_en']),
            'count' => count($plan['changes']),
            'samples' => array_slice($plan['changes'], 0, 10),
            'yearChanges' => array_values(array_filter($plan['changes'], fn (array $c) => $c['year_changed'])),
            'skipped' => $plan['skipped'],
            'canUndo' => BranchRegistration::whereNotNull('legacy_membership_id')->exists(),
        ]);
    }

    public function convert(Request $request, MembershipIdConversion $conversion): RedirectResponse
    {
        return back()->with('success', [
            'key' => 'flash.membership_ids_converted',
            'params' => ['count' => $conversion->convert($request->user())],
        ]);
    }

    public function undo(Request $request, MembershipIdConversion $conversion): RedirectResponse
    {
        return back()->with('success', [
            'key' => 'flash.membership_ids_restored',
            'params' => ['count' => $conversion->undo($request->user())],
        ]);
    }
}
```

In `routes/web.php`, inside the existing `superadmin` group beside `finance.reset`:

```php
        // Old membership IDs to BRANCHCODE-YEAR-NNNNN, after the codes are chosen.
        Route::get('/membership-ids', [MembershipIdController::class, 'index'])->name('membership-ids.index');
        Route::post('/membership-ids/convert', [MembershipIdController::class, 'convert'])->name('membership-ids.convert');
        Route::post('/membership-ids/undo', [MembershipIdController::class, 'undo'])->name('membership-ids.undo');
```

The controller records its own event, so nothing goes in `config/activity.php`. In `BranchController::index` add `'canConvertIds' => request()->user()?->isGodAdmin() ?? false`. In `Settings/Branches.vue` add the prop and, under the list:

```vue
<Link v-if="canConvertIds" :href="route('membership-ids.index')" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('membership_ids_convert_title') }}</Link>
```

`resources/js/Pages/Settings/MembershipIds.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    branches: { type: Array, default: () => [] },
    count: Number,
    samples: { type: Array, default: () => [] },
    yearChanges: { type: Array, default: () => [] },
    skipped: { type: Array, default: () => [] },
    canUndo: Boolean,
});

const confirming = ref(null);
function run() {
    const action = confirming.value;
    confirming.value = null;
    router.post(route(`membership-ids.${action}`), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('membership_ids_convert_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('membership_ids_convert_title') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_codes') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('membership_ids_codes_hint') }}</p>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li v-for="branch in branches" :key="branch.id" class="rounded-lg bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ branch.localized_name || branch.name }} · <span class="font-mono" dir="ltr">{{ branch.code }}</span>
                    </li>
                </ul>
                <Link :href="route('branches.index')" class="mt-3 inline-block text-sm text-primary-600 hover:underline dark:text-primary-300">{{ t('branches') }}</Link>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_preview', { count }) }}</h2>
                <ul v-if="samples.length" class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="sample in samples" :key="sample.registration_id" class="flex flex-wrap gap-2">
                        <span class="min-w-0 break-words">{{ sample.player_name }}</span>
                        <span class="font-mono" dir="ltr">{{ sample.from || '—' }} → {{ sample.to }}</span>
                    </li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-3">
                    <PrimaryButton :disabled="!count" @click="confirming = 'convert'">{{ t('membership_ids_convert') }}</PrimaryButton>
                    <SecondaryButton v-if="canUndo" @click="confirming = 'undo'">{{ t('membership_ids_undo') }}</SecondaryButton>
                </div>
            </section>

            <section v-if="yearChanges.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-amber-200 dark:bg-slate-900 dark:ring-amber-900">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_year_changes', { count: yearChanges.length }) }}</h2>
                <ul class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="change in yearChanges" :key="change.registration_id" class="flex flex-wrap gap-2">
                        <span class="min-w-0 break-words">{{ change.player_name }}</span>
                        <span class="font-mono" dir="ltr">{{ change.from }} → {{ change.to }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="skipped.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('membership_ids_skipped', { count: skipped.length }) }}</h2>
                <ul class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    <li v-for="row in skipped" :key="row.registration_id" class="break-words">{{ row.player_name }}</li>
                </ul>
            </section>
        </div>

        <ConfirmModal :show="!!confirming" :message="confirming === 'undo' ? t('membership_ids_undo_confirm') : t('membership_ids_convert_confirm', { count })" @confirm="run" @cancel="confirming = null" />
    </AuthenticatedLayout>
</template>
```

`keys.json`, then `node scripts/i18n-add.mjs keys.json`:

```json
{
  "membership_ids_convert_title": { "ar": "تحويل أرقام الانخراط", "fr": "Conversion des numéros d'adhérent", "en": "Convert membership IDs" },
  "membership_ids_codes": { "ar": "رموز الفروع", "fr": "Codes des branches", "en": "Branch codes" },
  "membership_ids_codes_hint": { "ar": "اختر الرموز قبل التحويل: لا يمكن تغيير الرمز بعد استعماله.", "fr": "Choisissez les codes avant la conversion : un code utilisé ne peut plus changer.", "en": "Choose the codes before converting: a code in use can't change." },
  "membership_ids_preview": { "ar": "سيتغير {count} رقم. أمثلة:", "fr": "{count} numéros vont changer. Exemples :", "en": "{count} IDs will change. Examples:" },
  "membership_ids_year_changes": { "ar": "{count} رقم تتغير سنته لتطابق سنة الانضمام", "fr": "L'année de {count} numéros change pour suivre l'année d'adhésion", "en": "{count} IDs change year to match the join year" },
  "membership_ids_skipped": { "ar": "{count} تسجيل بدون سنة: لن يتم تحويله", "fr": "{count} inscriptions sans année : non converties", "en": "{count} registrations have no year and won't be converted" },
  "membership_ids_convert": { "ar": "تحويل", "fr": "Convertir", "en": "Convert" },
  "membership_ids_undo": { "ar": "استرجاع الأرقام القديمة", "fr": "Rétablir les anciens numéros", "en": "Restore old IDs" },
  "membership_ids_convert_confirm": { "ar": "تحويل {count} رقم؟ تبقى الأرقام القديمة محفوظة وقابلة للبحث.", "fr": "Convertir {count} numéros ? Les anciens restent conservés et consultables.", "en": "Convert {count} IDs? Old IDs stay stored and searchable." },
  "membership_ids_undo_confirm": { "ar": "استرجاع الأرقام القديمة؟", "fr": "Rétablir les anciens numéros ?", "en": "Restore the old IDs?" },
  "flash.membership_ids_converted": { "ar": "تم تحويل {count} رقم.", "fr": "{count} numéros convertis.", "en": "{count} IDs converted." },
  "flash.membership_ids_restored": { "ar": "تم استرجاع {count} رقم.", "fr": "{count} numéros rétablis.", "en": "{count} IDs restored." }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Registrations/MembershipIdConversionTest.php`
Expected: PASS, 8 tests.

Run: `npm run i18n:check; npm run build`
Expected: both succeed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Player/MembershipIdConversion.php app/Http/Controllers/MembershipIdController.php resources/js/Pages/Settings/MembershipIds.vue routes/web.php app/Http/Controllers/BranchController.php resources/js/Pages/Settings/Branches.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Registrations/MembershipIdConversionTest.php
git commit -m "feat(registrations): preview, convert and restore membership IDs"
```

---

### Task 11: `registrations:verify` and the release checks

**Files:**
- Create: `app/Console/Commands/VerifyRegistrations.php`
- Test: `tests/Feature/Registrations/VerifyRegistrationsTest.php`

**Interfaces:**
- Consumes: `RegistrationService::primary`, `MembershipNumber::isLegacy`.
- Produces: `php artisan registrations:verify`, exit 0 when clean, 1 otherwise. The migration-only checks (no date set, per-year counts unchanged) are the before-and-after comparison in Step 7.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Player
    {
        $branch = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($branch->id, ['membership_id' => '201700001', 'category_id' => null, 'legacy_join_year' => 2017]);

        return $player;
    }

    #[Test]
    public function clean_data_passes(): void
    {
        $this->member();

        $this->artisan('registrations:verify')->assertExitCode(0);
    }

    #[Test]
    public function a_wrong_copy_fails(): void
    {
        $player = $this->member();
        DB::table('players')->where('id', $player->id)->update(['licence_number' => 'WRONG']);

        $this->artisan('registrations:verify')->assertExitCode(1);
    }

    #[Test]
    public function a_year_mismatch_fails(): void
    {
        $player = $this->member();
        DB::table('branch_player')->where('player_id', $player->id)->update(['legacy_join_year' => 1999]);
        DB::table('players')->where('id', $player->id)->update(['join_year' => 2017]);

        $this->artisan('registrations:verify')->assertExitCode(1);
    }

    #[Test]
    public function a_player_with_no_branch_is_reported_but_does_not_fail(): void
    {
        Player::create(['firstname' => 'Solo', 'membership_id' => '201700002', 'join_year' => 2017]);

        $this->artisan('registrations:verify')
            ->expectsOutputToContain('no branch: 1')
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Registrations/VerifyRegistrationsTest.php`
Expected: FAIL, the command does not exist.

- [ ] **Step 3: Implement**

`app/Console/Commands/VerifyRegistrations.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only checks on real data, before and after a release. */
class VerifyRegistrations extends Command
{
    protected $signature = 'registrations:verify';

    protected $description = 'Check that registrations and the player copy agree';

    public function handle(RegistrationService $registrations): int
    {
        $failures = 0;

        $this->line('registrations: '.BranchRegistration::count());
        $this->line('needing a join date: '.BranchRegistration::needsJoinDate()->count());
        $this->line('no branch: '.Player::whereDoesntHave('registrations')->count());
        $this->line('no ID yet: '.BranchRegistration::whereNull('membership_id')->count());
        $this->line('old-format IDs: '.BranchRegistration::whereNotNull('membership_id')->where('membership_id', 'not like', '%-%')->count());

        $duplicateIds = DB::table('branch_player')->whereNotNull('membership_id')
            ->groupBy('membership_id')->havingRaw('COUNT(*) > 1')->pluck('membership_id');
        $failures += $this->report('duplicate IDs', $duplicateIds->all());

        $earliest = fn (Player $player) => $player->registrations->map(fn ($r) => $r->effectiveYear())->filter()->min();

        $copyErrors = [];
        $yearErrors = [];
        Player::query()->whereHas('registrations')->with('registrations')->chunkById(200, function ($players) use ($registrations, $earliest, &$copyErrors, &$yearErrors) {
            foreach ($players as $player) {
                $primary = $registrations->primary($player);

                foreach (['category_id', 'position_id', 'licence_number'] as $field) {
                    if ((string) $player->{$field} !== (string) $primary->{$field}) {
                        $copyErrors[] = "player {$player->id}: {$field}";
                    }
                }
                if ($primary->membership_id !== null && $player->membership_id !== $primary->membership_id) {
                    $copyErrors[] = "player {$player->id}: membership_id";
                }

                $year = $earliest($player);
                if ($year !== null && (int) $player->join_year !== (int) $year) {
                    $yearErrors[] = "player {$player->id}: {$player->join_year} vs {$year}";
                }
            }
        });

        $failures += $this->report('player copy differs from primary', $copyErrors);
        $failures += $this->report('join year differs', $yearErrors);

        $this->line($failures === 0 ? 'OK' : "FAILED: {$failures} check(s)");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $problems */
    private function report(string $label, array $problems): int
    {
        if ($problems === []) {
            return 0;
        }

        $this->error($label.': '.count($problems));
        foreach (array_slice($problems, 0, 20) as $problem) {
            $this->line('  '.$problem);
        }

        return 1;
    }
}
```

In `a_year_mismatch_fails`, the player copy of 2017 differs from the registration's 1999, which is the case this check catches.

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Registrations/VerifyRegistrationsTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/VerifyRegistrations.php tests/Feature/Registrations/VerifyRegistrationsTest.php
git commit -m "feat(registrations): a read-only check of registrations on real data"
```

- [ ] **Step 6: Full automated gate**

Run: `php artisan config:clear; php artisan test`
Expected: only the known `ManualSuspensionsTest` failure.

Run: `node --test tests/js/*.test.mjs; npm run i18n:check; npm run build`
Expected: all pass.

- [ ] **Step 7: Dry run on real data, SQLite**

Run these on a **copy** of `D:\irnb-laravel\database\database.sqlite` placed in the worktree, with `DB_DATABASE` pointing at the copy and `APP_CONFIG_CACHE` set to a scratch path (config cache ignores `DB_DATABASE`). Never run against the owner's desktop database or the main folder's file.

```bash
php artisan migrate --force
php artisan registrations:verify
php artisan migrate --force
php artisan registrations:verify
```

Expected on today's data: 226 registrations, 226 needing a join date, 0 with no branch, 9 with no ID yet, 217 old-format IDs, `OK`, and the second run reports "Nothing to migrate" with the same figures.

Also compare before and after: `SELECT join_year, COUNT(*) FROM players GROUP BY 1` must be identical, and `SELECT id, membership_id, category_id, position_id, status_id, left_at FROM players` must be byte-identical.

- [ ] **Step 8: Dry run on MariaDB**

On a local MariaDB database restored from the same data: run the same two `migrate` and `verify` pairs, then a fresh `migrate:fresh` on an empty database, confirming every migration passes. Then load `players.index`, `players.show`, `players.edit`, `players.join-dates.index`, `branches.index`, `membership-ids.index`, the attendance calendar, and one report for 200 responses and a clean log.

- [ ] **Step 9: Upgrade paths**

Using copies only: migrate the 2.4.2 runtime data copy (102 `migrations` rows) and a restored old backup (`D:/BACKUPS/sport-club-backup-2026-11-01_195807.zip`, the only backup left: never move or change it, and extract a copy to a scratch directory). Confirm `registrations:verify` is `OK` on both, then run the conversion on one copy and confirm the preview lists the IDs whose year differs from `join_year` (26 on today's dev data), `verify` stays `OK`, and "Restore old IDs" brings every value back.

- [ ] **Step 10: Browser QA on a worktree DB copy**

Using the CDP script, in Arabic plus one LTR language, light and dark, 320 px and desktop:
- Player form: create, edit, add branch, legacy edit, left status (Task 7's list).
- Queue: filter, Enter saves, hint fills without saving, a future date shows the error.
- Bulk edit: attach with date, detach.
- Branch page: code, main branch, members with date.
- Sporting statuses: add, deactivate, delete blocked.
- Conversion: preview, confirm, restore.
- Player page: registrations listed; old ID and new ID both searchable.

Expected: no console errors, no sideways scroll, clean Laravel log. Keep a QA user only in the worktree DB.

- [ ] **Step 11: Report to the owner and stop**

Report results and ask for merge approval. Do not merge or push before approval. Repeat the deployment limit from Global Constraints: Release 1 must not reach production or a desktop build before Release 2.
