# Branch registrations, Release 2 (Readers and category split) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every reader of a player's branch, category, status, licence and ID use the registration, then give each branch its own categories.

**Architecture:** One shared query (`Player::scopeRegisteredWhere`) and one display helper (`RegistrationDisplay`) answer every question about a registration, and readers move to them one area at a time. The `players.*` mirror columns stay until Release 5. The category split runs last, because a reader still on the mirror would drop two-branch players from rosters once categories differ per branch.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit attribute tests, Vue 3 with Inertia, Tailwind. SQLite (desktop, dev, tests), PostgreSQL (production), MariaDB (kept working). Spec: `docs/superpowers/specs/2026-11-05-branch-registrations-r2-design.md`. Parent spec: `docs/superpowers/specs/2026-11-03-branch-registrations-design.md`.

## How this plan is laid out

The work is too large for one file. Execute the parts in this order. Each part leaves the suite passing.

| Part | Content | Where |
|---|---|---|
| 0 | Merge `main`, tooling, PostgreSQL first look, report to the owner | This file |
| 1 | Shared query and display helper | This file |
| 2 | Subscriptions and finance | `2026-11-05-branch-registrations-r2-p2-finance.md` |
| 3 | Attendance | `2026-11-05-branch-registrations-r2-p3-attendance.md` |
| 4 | Teams and matches | `2026-11-05-branch-registrations-r2-p4-teams.md` |
| 5 | Documents | `2026-11-05-branch-registrations-r2-p5-documents.md` |
| 6 | Lists, exports, imports, dashboards, reports, cards, Release 1 leftovers | `2026-11-05-branch-registrations-r2-p6-lists.md` |
| 7 | Category split, branch page, pickers | `2026-11-05-branch-registrations-r2-p7-split.md` |
| 8 | Full check, owner's browser test, merge if approved | This file |

**Stop points that need the owner:** the end of Part 0 (PostgreSQL findings), the `ImportMongoJsonData` question in Part 6, and Part 8 (browser test, then merge). Part 0's report can change later parts: a page already failing online becomes its own fix on `main`, and it does not wait for this release.

## Global Constraints

- Work in worktree `D:\irnb-branch-reg-r2`, branch `feat/branch-registrations-r2`. Stage explicit paths only. Never stage `HANDOFF.md` or `.claude/`. Do not switch branches or stage in `D:\irnb-laravel`.
- Nothing merges or pushes without a fresh approval from the owner. Before any push, run merge and push as separate commands and check the `main` tip and `origin/main..main` right before pushing.
- Never run anything against `%APPDATA%\sport-club`, `D:\irnb-laravel\database`, or the owner's test database in `D:\irnb-branch-reg`, except read-only opens of that test copy. Never stop the owner's server on port 2026, Vite on 5173, or the `postgresql-x64-18` service. `D:\BACKUPS\sport-club-backup-2026-11-01_195807.zip` is never moved or changed.
- No desktop build unless the owner asks. No new dependencies. No CDN or remote resources.
- Every new query and migration must run on SQLite, MariaDB and PostgreSQL. Date parts use existing helpers (`MonthBucket`, `EffectiveDueDate`), never driver functions. Compare `date` columns with `YYYY-MM-DD` text only through `>=`, `<=` or `whereDate`, because SQLite holds both `2026-03-15` and `2026-03-15 00:00:00`.
- **Never** `sync`, `attach` or `detach` registrations in production code. All writes go through `RegistrationService`. A guard test enforces this.
- `players.*` mirror columns stay. Nothing is dropped in this release. A player with no registration keeps their values on `players`, and every reader falls back to them.
- No lower bound on `join_date` anywhere in this release.
- Definitions: *active* registration means its status is not `left`. *Main* registration means `RegistrationService::primary()`, or `RegistrationDisplay::main()` in a loop.
- i18n: flat keys through `node scripts/i18n-add.mjs keys.json`, insert-only. Call `t()` directly. A key segment after a message must not be a String method (`link`, `search`). French says "Section", never "branche". `npm run i18n:check` must pass.
- `useForm` field names must avoid `progress`. Vue primary text on dark uses `primary-300`, not 400. A bare grid needs `grid-cols-1` at 320 px. Inertia's `onFinish` fires on cancel too.
- Player names always come from `Player::fullname`. Narrowed queries spread `Player::NAME_COLUMNS`.
- Run `php artisan config:clear` before tests. `ManualSuspensionsTest` already fails on date; it is not this work.
- Every part ends with: `php artisan test`, `npm run test:js` if JS changed, `npm run i18n:check`, `npm run build`, then `figures.php` and `diff-figures.php` from Part 0 on the real-data copy.
- Tests use `Tests\Support\RegistrationFixtures` (Task 5) and cover: both active, left football, left both, no registration at all.

---

# Part 0: Merge, tooling, PostgreSQL first look

### Task 1: Prepare the worktree and merge `main`

**Files:** none tracked except the merge.

- [ ] **Step 1: Copy dependencies, no junctions**

```bash
cd /d/irnb-branch-reg-r2
cp -r /d/irnb-branch-reg/vendor vendor
cp -r /d/irnb-branch-reg/node_modules node_modules
cp /d/irnb-branch-reg/.env .env
mkdir -p .superpowers/r2/data
```

Expected: `vendor/autoload.php` exists. `git status --short` shows nothing new, because these paths are ignored.

- [ ] **Step 2: Merge `main`**

```bash
git fetch origin
git merge --no-edit origin/main
git log --oneline -1
```

Expected: a merge with no conflicts (checked with `git merge-tree` at `9cb9661`). If `origin/main` has moved and there is a conflict, stop and report it.

- [ ] **Step 3: Install, then run the suite as the baseline**

```bash
composer install --no-interaction
npm install --no-audit --no-fund
php artisan config:clear
php artisan test 2>&1 | tail -5
npm run test:js 2>&1 | tail -3
```

Expected: only `ManualSuspensionsTest` may fail. Record the counts in `.superpowers/r2/progress.md`.

### Task 2: Real-data copy and the check scripts

These scripts stay in `.superpowers/r2/`, which is git-ignored. They are tools for this work, not product code.

**Files:**
- Create: `.superpowers/r2/guard.php`, `.superpowers/r2/copy-source.php`, `.superpowers/r2/figures.php`, `.superpowers/r2/diff-figures.php`, `.superpowers/r2/sweep.php`, `.superpowers/r2/diff-sweep.php`, `.superpowers/r2/env-sqlite.sh`

- [ ] **Step 1: Write the guard every script loads first**

`.superpowers/r2/guard.php`:

```php
<?php

// Boots Laravel and refuses any database that isn't a private copy of this work.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = config('database.default');
$target = (string) config("database.connections.$connection.database");
$normal = strtolower(str_replace('\\', '/', $target));
$safe = match ($connection) {
    'sqlite' => str_contains($normal, 'irnb-branch-reg-r2/.superpowers/r2/data/'),
    'pgsql' => (string) config('database.connections.pgsql.port') === '5544',
    'mysql', 'mariadb' => (string) config("database.connections.$connection.port") === '3399',
    default => false,
};
if (! $safe) {
    fwrite(STDERR, "Refusing to run on $connection database: $target\n");
    exit(2);
}
```

- [ ] **Step 2: Write the copy script**

`.superpowers/r2/copy-source.php` takes a consistent copy with a read-only open, so the owner's running server is not disturbed:

```php
<?php

$source = 'D:/irnb-branch-reg/database/database.sqlite';
$target = __DIR__.'/data/'.($argv[1] ?? 'real.sqlite');
if (file_exists($target)) {
    unlink($target);
}
$pdo = new PDO('sqlite:'.$source, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$pdo->exec('VACUUM INTO '.$pdo->quote($target));
echo 'copied to '.realpath($target)."\n";
```

`.superpowers/r2/env-sqlite.sh`, sourced before any command on the copy:

```bash
export DB_CONNECTION=sqlite
export DB_DATABASE="D:/irnb-branch-reg-r2/.superpowers/r2/data/real.sqlite"
export APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
```

- [ ] **Step 3: Write `figures.php`**

It records the spec's pinned figures per player and per subscription.

```php
<?php

require __DIR__.'/guard.php';

use App\Models\Player;
use App\Models\Subscription;
use App\Models\TrainingSession;
use App\Services\Attendance\Roster;
use Illuminate\Support\Facades\DB;

$label = $argv[1] ?? 'baseline';
$strip = fn ($rows) => collect($rows)->map(function ($row) {
    $row = (array) $row;
    unset($row['updated_at']);

    return $row;
})->values()->all();

$figures = [
    'two_branch_players' => DB::table('branch_player')->select('player_id')->groupBy('player_id')
        ->havingRaw('COUNT(*) > 1')->orderBy('player_id')->pluck('player_id')->all(),
    'player_subscriptions' => $strip(DB::table('player_subscriptions')->orderBy('id')->get()),
    'transactions' => $strip(DB::table('transactions')->orderBy('id')->get()),
    'attendances' => $strip(DB::table('attendances')->orderBy('id')->get(['id', 'training_session_id', 'player_id', 'status', 'reason'])),
    'eligible' => Subscription::with(['categories', 'branches'])->orderBy('id')->get()
        ->mapWithKeys(fn (Subscription $s) => [$s->id => Player::eligibleFor($s)->orderBy('id')->pluck('id')->all()])->all(),
    'rosters' => TrainingSession::orderBy('id')->get()
        ->mapWithKeys(fn (TrainingSession $s) => [$s->id => app(Roster::class)->forSession($s)->pluck('id')->sort()->values()->all()])->all(),
    'player_mirror' => $strip(DB::table('players')->orderBy('id')
        ->get(['id', 'category_id', 'position_id', 'licence_number', 'status_id', 'left_at', 'join_year', 'membership_id'])),
];

file_put_contents(__DIR__."/data/figures-$label.json", json_encode($figures, JSON_PRETTY_PRINT));
echo "figures-$label.json written\n";
```

Part 7 moves two-branch players' category IDs on purpose, so its `diff-figures` run lists those as expected differences.

- [ ] **Step 4: Write `diff-figures.php`**

It flags any difference on a player who isn't a two-branch player as unexpected, and exits non-zero.

```php
<?php

[$a, $b] = [json_decode(file_get_contents(__DIR__.'/data/figures-'.$argv[1].'.json'), true), json_decode(file_get_contents(__DIR__.'/data/figures-'.$argv[2].'.json'), true)];
$two = array_flip($a['two_branch_players']);
$unexpected = 0;
$say = function (string $what, bool $isTwoBranch) use (&$unexpected) {
    $unexpected += $isTwoBranch ? 0 : 1;
    echo($isTwoBranch ? '  two-branch: ' : 'UNEXPECTED: ').$what."\n";
};

foreach (['player_subscriptions', 'transactions', 'attendances', 'player_mirror'] as $table) {
    $before = array_column($a[$table], null, 'id');
    $after = array_column($b[$table], null, 'id');
    foreach ($before + $after as $id => $_) {
        if (($before[$id] ?? null) !== ($after[$id] ?? null)) {
            $player = $before[$id]['player_id'] ?? $after[$id]['player_id'] ?? ($table === 'player_mirror' ? $id : null);
            // Money never changes, whoever the player is.
            $say("$table #$id player $player: ".json_encode($before[$id] ?? null).' => '.json_encode($after[$id] ?? null),
                $table === 'player_mirror' && isset($two[$player]));
        }
    }
}
foreach (['eligible', 'rosters'] as $group) {
    foreach ($a[$group] + $b[$group] as $key => $_) {
        $gone = array_diff($a[$group][$key] ?? [], $b[$group][$key] ?? []);
        $new = array_diff($b[$group][$key] ?? [], $a[$group][$key] ?? []);
        foreach ($gone as $id) {
            $say("$group $key lost player $id", isset($two[$id]));
        }
        foreach ($new as $id) {
            $say("$group $key gained player $id", isset($two[$id]));
        }
    }
}
echo "unexpected differences: $unexpected\n";
exit($unexpected > 0 ? 1 : 0);
```

- [ ] **Step 5: Write `sweep.php`**

It requests every GET route as a full-rights user, in one process, and stores the status and Inertia props.

```php
<?php

require __DIR__.'/guard.php';

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

$label = $argv[1] ?? 'sweep';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$user = User::query()->whereJsonContains('privileges', 'god')->first() ?? User::query()->first();
auth()->setUser($user);

$bind = function (string $name): ?string {
    $table = Str::plural(Str::snake($name));
    if (! Schema::hasTable($table)) {
        return null;
    }
    $id = DB::table($table)->orderBy('id')->value('id');

    return $id === null ? null : (string) $id;
};

$results = [];
$call = function (string $uri, bool $inertia) use ($kernel, $app) {
    $request = Request::create($uri, 'GET');
    if ($inertia) {
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Version', (string) Inertia::getVersion());
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
    }

    return $kernel->handle($request);
};

$routes = collect(Route::getRoutes()->getRoutes())
    ->filter(fn ($r) => in_array('GET', $r->methods(), true))
    ->reject(fn ($r) => preg_match('#^(_|up$|storage|media|sanctum|telescope|horizon|logout|backups/.+/download)#', $r->uri()));

$call('/', false); // primes the Inertia version

foreach ($routes as $route) {
    $uri = '/'.ltrim($route->uri(), '/');
    $missing = false;
    foreach ($route->parameterNames() as $name) {
        $value = $bind($name);
        if ($value === null) {
            $missing = true;
            break;
        }
        $uri = preg_replace('/\{'.$name.'\??\}/', $value, $uri);
    }
    $key = $route->getName() ?? $route->uri();
    if ($missing) {
        $results[$key] = ['uri' => $uri, 'status' => 'skipped: no row to bind'];

        continue;
    }

    $response = $call($uri, true);
    $entry = ['uri' => $uri, 'status' => $response->getStatusCode()];
    if ($response->exception ?? null) {
        $entry['error'] = Str::limit($response->exception->getMessage(), 400);
    }
    $type = (string) $response->headers->get('Content-Type');
    if (str_contains($type, 'json')) {
        $entry['props'] = json_decode($response->getContent(), true)['props'] ?? null;
    } else {
        $entry['bytes'] = strlen((string) $response->getContent());
    }
    $results[$key] = $entry;
    echo str_pad((string) $entry['status'], 5).$uri."\n";
}

file_put_contents(__DIR__."/data/sweep-$label.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$bad = collect($results)->filter(fn ($r) => is_int($r['status']) && $r['status'] >= 500);
echo 'routes: '.count($results).', 5xx: '.$bad->count()."\n";
foreach ($bad as $name => $r) {
    echo "$name: ".($r['error'] ?? '')."\n";
}
```

- [ ] **Step 6: Write `diff-sweep.php`**

```php
<?php

$a = json_decode(file_get_contents(__DIR__.'/data/sweep-'.$argv[1].'.json'), true);
$b = json_decode(file_get_contents(__DIR__.'/data/sweep-'.$argv[2].'.json'), true);
$walk = function ($x, $y, string $path) use (&$walk) {
    if (is_array($x) && is_array($y)) {
        foreach (array_keys($x + $y) as $key) {
            $walk($x[$key] ?? null, $y[$key] ?? null, "$path.$key");
        }
    } elseif ($x !== $y) {
        echo "  $path: ".substr(json_encode($x, JSON_UNESCAPED_UNICODE), 0, 100).' => '.substr(json_encode($y, JSON_UNESCAPED_UNICODE), 0, 100)."\n";
    }
};
foreach (array_keys($a + $b) as $route) {
    if (($a[$route]['status'] ?? null) !== ($b[$route]['status'] ?? null)) {
        echo "STATUS $route: ".json_encode($a[$route]['status'] ?? null).' => '.json_encode($b[$route]['status'] ?? null).' '.($b[$route]['error'] ?? '')."\n";
    }
    if (isset($a[$route]['props'], $b[$route]['props'])) {
        ob_start();
        $walk($a[$route]['props'], $b[$route]['props'], 'props');
        $changes = ob_get_clean();
        if ($changes !== '') {
            echo "PROPS $route\n$changes";
        }
    }
}
```

- [ ] **Step 7: Take the baseline on SQLite**

```bash
cd /d/irnb-branch-reg-r2
php .superpowers/r2/copy-source.php real.sqlite
. .superpowers/r2/env-sqlite.sh
php artisan config:show database.connections.sqlite.database
php artisan migrate --force
php artisan registrations:verify
php .superpowers/r2/figures.php baseline
php .superpowers/r2/sweep.php baseline | tail -20
```

Expected: `config:show` prints the `.superpowers/r2/data/real.sqlite` path. **Stop if it prints anything else.** `registrations:verify` exits 0. Investigate any 5xx: a 5xx on SQLite is a bug to report, not a blocker for this release. Copy `real.sqlite` to `real-baseline.sqlite`, so every later part can restart from the same data.

### Task 3: Private PostgreSQL with the real-data copy

**Files:**
- Create: `.superpowers/r2/env-pgsql.sh`, `.superpowers/r2/pg-load.php`

- [ ] **Step 1: Start a private instance**

```bash
cd /d/irnb-branch-reg-r2
PG="/c/Program Files/PostgreSQL/18/bin"
"$PG/initdb" -D .superpowers/r2/pg -U postgres --auth=trust --encoding=UTF8 --locale=C
"$PG/pg_ctl" -D .superpowers/r2/pg -o "-p 5544 -h 127.0.0.1" -l .superpowers/r2/pg.log start
"$PG/createdb" -h 127.0.0.1 -p 5544 -U postgres sportclub_r2
```

Expected: `server started`. The machine's `postgresql-x64-18` service isn't touched. Port 5544 is the only one this work uses. Stop it at the end of each session with `"$PG/pg_ctl" -D .superpowers/r2/pg stop`.

`.superpowers/r2/env-pgsql.sh`:

```bash
export DB_CONNECTION=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5544
export DB_DATABASE=sportclub_r2
export DB_USERNAME=postgres
export DB_PASSWORD=
export APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
```

- [ ] **Step 2: Run the migrations, recording every failure**

```bash
( . .superpowers/r2/env-pgsql.sh; php artisan migrate --force 2>&1 | tail -30 )
```

Expected: unknown, this has never run. A failure here is a finding. Record the migration name and error in `.superpowers/r2/pg-report.md`. If a migration fails, patch it only in a throwaway local copy to keep looking (`git stash` afterwards, nothing committed), because the owner decides how production is handled.

- [ ] **Step 3: Write `pg-load.php`**

It replaces every row in PostgreSQL with the SQLite copy's rows, converting booleans and resetting sequences.

```php
<?php

require __DIR__.'/guard.php';

use Illuminate\Support\Facades\DB;

$source = new PDO('sqlite:'.__DIR__.'/data/'.($argv[1] ?? 'real.sqlite'), null, null, [
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"), 'tablename');

DB::statement('SET session_replication_role = replica');
DB::statement('TRUNCATE '.implode(', ', array_map(fn ($t) => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');

foreach ($tables as $table) {
    $exists = $source->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ".$source->quote($table))->fetchColumn();
    if (! $exists) {
        echo "no source table: $table\n";

        continue;
    }
    $columns = collect(DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?', [$table]))
        ->pluck('data_type', 'column_name');
    $rows = $source->query('SELECT * FROM "'.$table.'"')->fetchAll();
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table($table)->insert(array_map(function ($row) use ($columns) {
            foreach ($row as $column => $value) {
                if ($columns[$column] === 'boolean' && $value !== null) {
                    $row[$column] = (bool) $value;
                }
            }

            return $row;
        }, $chunk));
    }
    if (isset($columns['id']) && $rows !== []) {
        DB::statement("SELECT setval(pg_get_serial_sequence('\"$table\"', 'id'), (SELECT MAX(id) FROM \"$table\"))");
    }
    echo str_pad($table, 40).count($rows)."\n";
}
DB::statement('SET session_replication_role = DEFAULT');
```

- [ ] **Step 4: Load, then run the checks**

```bash
( . .superpowers/r2/env-pgsql.sh
  php .superpowers/r2/pg-load.php real-baseline.sqlite | tail -80
  php artisan registrations:verify
  php .superpowers/r2/figures.php pg-baseline
  php .superpowers/r2/sweep.php pg-baseline | tail -40 )
php .superpowers/r2/diff-figures.php baseline pg-baseline
php .superpowers/r2/diff-sweep.php baseline pg-baseline > .superpowers/r2/data/sweep-pg-vs-sqlite.txt
wc -l .superpowers/r2/data/sweep-pg-vs-sqlite.txt
```

Expected: unknown. Record in `pg-report.md`: every 5xx with its error; every status change; prop differences that aren't order (the private instance's `C` locale orders text differently, so a reordered list alone is not a finding).

### Task 4: Concerns found by reading, then the report

**Files:**
- Create: `.superpowers/r2/pg-report.md`

- [ ] **Step 1: Check case-sensitive search**

```bash
( . .superpowers/r2/env-pgsql.sh; php artisan tinker --execute='
$p = App\Models\Player::query()->whereNotNull("firstname")->where("firstname", "~", "[A-Za-z]")->first();
if ($p) { $t = strtolower($p->firstname); echo $p->firstname." / ".$t." finds ".App\Models\Player::search($t)->count().PHP_EOL; } else { echo "no Latin first name in the data".PHP_EOL; }' )
grep -rn "'like'" app --include=*.php | wc -l
```

Record whether a lower-case search finds a capitalised name. Arabic names have no case, so also say how much of the real data this touches: count Latin-script names with `SELECT COUNT(*) FROM players WHERE firstname ~ '[A-Za-z]'`.

- [ ] **Step 2: Check backups**

Read `app/Services/.../BackupService.php` (`grep -rl "class BackupService" app`) and try the page's create action through tinker on the PostgreSQL environment, catching the error:

```bash
( . .superpowers/r2/env-pgsql.sh; php artisan tinker --execute='
try { $s = app(App\Services\Backup\BackupService::class); echo get_class($s).PHP_EOL; echo json_encode(get_class_methods($s)).PHP_EOL; } catch (Throwable $e) { echo $e->getMessage().PHP_EOL; }' )
```

If the namespace differs, use what `grep` found. Do not call a method until you have read it: a restore must never run. Record what a backup does when the database isn't a SQLite file.

- [ ] **Step 3: Check the boolean comparisons**

SQL comparing a boolean to `1` fails on PostgreSQL. Part 5 planning found `dt.is_active = 1` in `DocumentChecklist::expiringSoonSql`. List the others, and record which pages each one breaks:

```bash
grep -rnE "(is_[a-z_]+|archived|required_[a-z_]+)s*=s*[01]" app --include=*.php
```

- [ ] **Step 4: Write the report and stop for the owner**

`pg-report.md` has four headings: *Fails today on PostgreSQL* (migrations and pages, each with its error); *Works but differs* (search, ordering, numbers); *Backups*; *What I could not test* (production's real data). Tell the owner in plain language what fails, and ask them to decide per finding: fix now on its own branch from `main`, or include in this release, or accept. **Do not start Part 1's code until the owner has answered**, because a page failing online today can't wait for this release. Update `HANDOFF.md` with the state. Do not fix anything in Part 0.

---

# Part 1: Shared query

Spec section 4. Later parts rely on these exact names.

**The rules every later part follows:**

| The reader needs | Use |
|---|---|
| Filter players by branch, category or status in one query | `Player::query()->registeredWhere(branches: ..., categories: ..., statuses: ..., activeOn: ..., active: ...)` |
| Show one player's values, in a loop | `$display = RegistrationDisplay::make();` once, then `$display->values($player, $branchId)` with `registrations` eager-loaded |
| Count or group rows in SQL, one per registration | `BranchRegistration::everyRow()`, as a subquery. It includes players with no registration, with a blank branch |
| Count or group in SQL with no branch selected | `players.*`: the mirror is the main registration, and its status is the overall status |
| Which branch a filter means | `RegistrationDisplay::singleBranch($ids)`: exactly one selected gives that ID, none or several gives null |

### Task 5: Scopes, the shared query and the fixtures

**Files:**
- Create: `tests/Support/RegistrationFixtures.php`
- Create: `tests/Feature/Registrations/SharedQueryTest.php`
- Modify: `app/Models/BranchRegistration.php`
- Modify: `app/Models/Player.php`

**Interfaces:**
- Produces: `BranchRegistration` scopes `activeOn(CarbonInterface|string $date)`, `inBranches(iterable|int $ids)`, `inCategories(iterable|int $ids)`, `withStatuses(iterable|int $ids)`; `BranchRegistration::constrainActiveOn($query, string $statusColumn, string $leftAtColumn, CarbonInterface|string $date)`; `BranchRegistration::everyRow(): Illuminate\Database\Query\Builder` with columns `player_id, branch_id, category_id, position_id, licence_number, status_id, left_at, membership_id`.
- Produces: `Player::scopeRegisteredWhere(iterable|int|null $branches = null, iterable|int|null $categories = null, iterable|int|null $statuses = null, CarbonInterface|string|null $activeOn = null, bool $active = false)`. `null` means no condition. **An empty list matches nothing**, so a caller with an optional filter passes `$ids ?: null`. `active: true` means not left today.
- Produces: `Player::registrationIn(?int $branchId): ?BranchRegistration`.
- Produces for tests: `RegistrationFixtures` properties `$foot`, `$swim`, `$senior`, `$u15`, `$leaveDate` and methods `twoSportClub()`, `bothActive()`, `leftFootball()`, `leftBoth()`, `noRegistration()`.

- [ ] **Step 1: Write the fixtures**

`tests/Support/RegistrationFixtures.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Services\Player\RegistrationService;

/**
 * The spec's four cases: a football Senior and swimming U15 player, both active;
 * left football; left both; no registration at all. Football is the main branch.
 */
trait RegistrationFixtures
{
    private Branch $foot;

    private Branch $swim;

    private Category $senior;

    private Category $u15;

    private string $leaveDate = '2026-03-15';

    private function twoSportClub(): void
    {
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->senior = Category::create(['name' => 'Senior']);
        $this->u15 = Category::create(['name' => 'U15']);
    }

    private function bothActive(string $name = 'Both'): Player
    {
        $player = Player::create(['firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020]);
        $service = app(RegistrationService::class);
        $service->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $service->register($player, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);

        return $player->refresh();
    }

    private function leftFootball(string $name = 'LeftFoot'): Player
    {
        $player = $this->bothActive($name);
        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        return $player->refresh();
    }

    private function leftBoth(string $name = 'LeftBoth'): Player
    {
        $player = $this->bothActive($name);
        app(RegistrationService::class)->leave($player, [$this->foot->id, $this->swim->id], $this->leaveDate);

        return $player->refresh();
    }

    /** Values only on players, as in a club with no branches. */
    private function noRegistration(string $name = 'Flat', ?int $categoryId = null): Player
    {
        return Player::create(['firstname' => $name, 'lastname' => 'Test', 'join_year' => 2020, 'category_id' => $categoryId ?? $this->senior->id]);
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Registrations/SharedQueryTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class SharedQueryTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function ids(...$args): array
    {
        return Player::query()->registeredWhere(...$args)->orderBy('id')->pluck('id')->all();
    }

    #[Test]
    public function branch_and_category_must_hold_on_the_same_registration(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], categories: [$this->senior->id]));
        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], categories: [$this->u15->id]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], categories: [$this->senior->id]));
    }

    #[Test]
    public function active_ignores_a_branch_the_player_left(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([], $this->ids(branches: [$this->foot->id], active: true));
        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id]));
        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], active: true));
        $this->assertSame([$player->id], $this->ids(active: true));
    }

    #[Test]
    public function left_everywhere_is_not_active_anywhere(): void
    {
        $this->leftBoth();

        $this->assertSame([], $this->ids(active: true));
    }

    #[Test]
    public function active_on_includes_the_leave_date_and_nothing_after(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: '2026-03-14'));
        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: $this->leaveDate));
        $this->assertSame([], $this->ids(branches: [$this->foot->id], activeOn: '2026-03-16'));
    }

    #[Test]
    public function active_on_reads_a_session_date_held_with_a_time(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: $this->leaveDate.' 00:00:00'));
    }

    #[Test]
    public function a_legacy_left_row_with_no_date_is_never_active_on_a_date(): void
    {
        $player = $this->leftFootball();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)->update(['left_at' => null]);

        $this->assertSame([], $this->ids(branches: [$this->foot->id], activeOn: '2020-01-01'));
    }

    #[Test]
    public function a_status_is_matched_on_the_registration(): void
    {
        $player = $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], statuses: [$left]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], statuses: [$left]));
    }

    #[Test]
    public function a_player_with_no_registration_falls_back_to_their_own_columns(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->ids(categories: [$this->senior->id]));
        $this->assertSame([], $this->ids(categories: [$this->u15->id]));
        $this->assertSame([$flat->id], $this->ids(active: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id]));

        $flat->update(['status_id' => PlayerStatus::where('code', 'left')->value('id'), 'left_at' => $this->leaveDate]);
        $this->assertSame([], $this->ids(active: true));
        $this->assertSame([$flat->id], $this->ids(activeOn: $this->leaveDate));
        $this->assertSame([], $this->ids(activeOn: '2026-03-16'));
    }

    #[Test]
    public function a_registered_player_never_falls_back_to_the_mirror(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['category_id' => null]);

        $this->assertSame([$player->id], $this->ids(categories: [$this->senior->id]));
        DB::table('players')->where('id', $player->id)->update(['category_id' => $this->u15->id]);
        $this->assertSame([$player->id], $this->ids(categories: [$this->u15->id]));
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);
        $this->assertSame([], $this->ids(categories: [$this->u15->id]));
    }

    #[Test]
    public function an_empty_list_matches_nothing_and_null_matches_everyone(): void
    {
        $player = $this->bothActive();
        $flat = $this->noRegistration();

        $this->assertSame([], $this->ids(categories: []));
        $this->assertSame([], $this->ids(branches: []));
        $this->assertSame([$player->id, $flat->id], $this->ids());
    }

    #[Test]
    public function it_takes_a_single_id_or_a_collection(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: $this->foot->id));
        $this->assertSame([$player->id], $this->ids(branches: collect([$this->foot->id])));
    }

    #[Test]
    public function registration_in_reads_the_loaded_rows_without_a_query(): void
    {
        $this->bothActive();
        $player = Player::with('registrations')->first();

        DB::enableQueryLog();
        $this->assertSame($this->u15->id, $player->registrationIn($this->swim->id)->category_id);
        $this->assertNull($player->registrationIn(999));
        $this->assertNull($player->registrationIn(null));
        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function every_row_gives_one_row_per_registration_and_one_per_unregistered_player(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration();

        $rows = DB::query()->fromSub(BranchRegistration::everyRow(), 'reg')->orderBy('player_id')->orderBy('branch_id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(2, $rows->where('player_id', $both->id)->count());
        $this->assertNull($rows->firstWhere('player_id', $flat->id)->branch_id);
        $this->assertEquals($this->senior->id, $rows->firstWhere('player_id', $flat->id)->category_id);
    }

    #[Test]
    public function the_query_does_not_widen_an_outer_or(): void
    {
        $this->bothActive();
        $flat = $this->noRegistration('Other');

        $found = Player::query()->where('firstname', 'Other')->registeredWhere(categories: [$this->senior->id])->pluck('id')->all();

        $this->assertSame([$flat->id], $found);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/Registrations/SharedQueryTest.php`
Expected: FAIL, `Call to undefined method ... registeredWhere`.

- [ ] **Step 4: Add the scopes to `BranchRegistration`**

In `app/Models/BranchRegistration.php`, add these imports:

```php
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
```

Add after `constrainActive`:

```php
    /** Still a member on that day: not left, or left that day or later. A left row with no date never counts. */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): void
    {
        static::constrainActiveOn($query, $query->getModel()->qualifyColumn('status_id'), $query->getModel()->qualifyColumn('left_at'), $date);
    }

    /** The same rule for a query on another model, where the columns must be named in full. */
    public static function constrainActiveOn(mixed $query, string $statusColumn, string $leftAtColumn, CarbonInterface|string $date): mixed
    {
        $left = Player::leftStatusId();
        // SQLite may hold a date with a time: compare on the day alone.
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);

        return $left === null ? $query : $query->where(fn ($w) => $w->whereNull($statusColumn)
            ->orWhere($statusColumn, '!=', $left)
            ->orWhere($leftAtColumn, '>=', $day));
    }

    public function scopeInBranches(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('branch_id'), collect($ids)->all());
    }

    public function scopeInCategories(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('category_id'), collect($ids)->all());
    }

    public function scopeWithStatuses(Builder $query, iterable|int $ids): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('status_id'), collect($ids)->all());
    }

    /**
     * One row per registration, plus one with a blank branch for each player with
     * none, whose values live on players. For counts and groups that stay in SQL.
     */
    public static function everyRow(): QueryBuilder
    {
        $columns = ['category_id', 'position_id', 'licence_number', 'status_id', 'left_at', 'membership_id'];

        return DB::table('branch_player')
            ->select(['player_id', 'branch_id', ...$columns])
            ->unionAll(DB::table('players')
                ->select(['id as player_id', DB::raw('NULL as branch_id'), ...$columns])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('branch_player')->whereColumn('branch_player.player_id', 'players.id')));
    }
```

- [ ] **Step 5: Add the shared query to `Player`**

In `app/Models/Player.php`, add the import `use Carbon\CarbonInterface;` and add after `scopeNotLeft`:

```php
    /**
     * The one answer to "is this player in this branch, with this category and
     * status, on this date": all conditions hold on the same registration. A
     * player with no registration at all is judged on their own columns, and has
     * no branch. Null means no condition; an empty list matches nothing, so a
     * computed list can never silently widen to everyone.
     */
    public function scopeRegisteredWhere(
        Builder $query,
        iterable|int|null $branches = null,
        iterable|int|null $categories = null,
        iterable|int|null $statuses = null,
        CarbonInterface|string|null $activeOn = null,
        bool $active = false,
    ): void {
        $playerKey = $query->getModel()->qualifyColumn('id');

        $rows = BranchRegistration::query()
            ->selectRaw('1')
            ->whereColumn('branch_player.player_id', $playerKey)
            ->when($branches !== null, fn ($q) => $q->inBranches($branches))
            ->when($categories !== null, fn ($q) => $q->inCategories($categories))
            ->when($statuses !== null, fn ($q) => $q->withStatuses($statuses))
            ->when($activeOn !== null, fn ($q) => $q->activeOn($activeOn))
            ->when($active, fn ($q) => $q->active());

        $query->where(function (Builder $either) use ($rows, $playerKey, $branches, $categories, $statuses, $activeOn, $active) {
            $either->whereExists($rows);

            if ($branches !== null) {
                return;
            }

            $either->orWhere(function (Builder $flat) use ($playerKey, $categories, $statuses, $activeOn, $active) {
                $column = $flat->getModel()->qualifyColumn(...);
                $flat->whereNotExists(fn ($q) => $q->selectRaw('1')->from('branch_player')->whereColumn('branch_player.player_id', $playerKey))
                    ->when($categories !== null, fn ($q) => $q->whereIn($column('category_id'), collect($categories)->all()))
                    ->when($statuses !== null, fn ($q) => $q->whereIn($column('status_id'), collect($statuses)->all()))
                    ->when($activeOn !== null, fn ($q) => BranchRegistration::constrainActiveOn($q, $column('status_id'), $column('left_at'), $activeOn))
                    ->when($active, fn ($q) => $q->notLeft());
            });
        });
    }

    /** This player's registration in a branch, from the loaded rows: no query per row when eager-loaded. */
    public function registrationIn(?int $branchId): ?BranchRegistration
    {
        return $branchId === null ? null : $this->registrations->firstWhere('branch_id', $branchId);
    }
```

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Registrations/SharedQueryTest.php`
Expected: all PASS. If `a_registered_player_never_falls_back_to_the_mirror` fails, the fallback is missing its `whereNotExists`.

- [ ] **Step 7: Commit**

```bash
git add app/Models/BranchRegistration.php app/Models/Player.php tests/Support/RegistrationFixtures.php tests/Feature/Registrations/SharedQueryTest.php
git commit -m "feat(registrations): one shared query for branch, category and status

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 8: Make player search ignore letter case on every database**

Owner's decision 2026-11-05. PostgreSQL's `LIKE` is case-sensitive, and this release makes every membership ID Latin, so `fb-2010-00001` must find `FB-2010-00001`. The suite runs on SQLite, whose `LIKE` already ignores case, so one of the two tests checks the SQL PostgreSQL would receive.

Add to `tests/Feature/PlayerSearchTest.php`, with `use Illuminate\Database\Query\Grammars\PostgresGrammar;` and `use Illuminate\Support\Facades\DB;`:

```php
    #[Test]
    public function a_lower_case_membership_id_finds_the_player(): void
    {
        $player = Player::create([
            'membership_id' => 'FB-2010-00001',
            'firstname' => 'Lina',
            'lastname' => 'Saidi',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);

        $this->assertEquals([$player->id], Player::query()->search('fb-2010-00001')->pluck('id')->all());
    }

    #[Test]
    public function search_asks_postgresql_to_ignore_letter_case(): void
    {
        $query = Player::query()->search('fb-2010');
        $query->getQuery()->grammar = new PostgresGrammar(DB::connection());

        $this->assertStringContainsString(' ilike ', $query->toSql());
        $this->assertStringNotContainsString(' like ', $query->toSql());
    }
```

Run: `php artisan test tests/Feature/PlayerSearchTest.php`. Expected: the second test FAILS.

In `Player::scopeSearch` (`app/Models/Player.php`), use Laravel's `whereLike`, which sends `ilike` to PostgreSQL and `like` elsewhere. Replace the column loop's body and the ID conditions:

```php
                    foreach ($columns as $column) {
                        $inner->orWhereLike($column, '%'.$token.'%');
                    }

                    // Every branch's ID, and the old one from before conversion.
                    $inner->orWhereExists(fn ($q) => $q->from('branch_player')
                        ->whereColumn('branch_player.player_id', 'players.id')
                        ->where(fn ($ids) => $ids
                            ->whereLike('branch_player.membership_id', '%'.$token.'%')
                            ->orWhereLike('branch_player.legacy_membership_id', '%'.$token.'%')));
```

Run: `php artisan test tests/Feature/PlayerSearchTest.php`. Expected: all PASS. The other searches stay as they are; they are a separate job.

```bash
git add app/Models/Player.php tests/Feature/PlayerSearchTest.php
git commit -m "fix(players): search ignores letter case on PostgreSQL too

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: `RegistrationDisplay` and a `primary()` that doesn't query per row

**Files:**
- Create: `app/Services/Player/RegistrationDisplay.php`
- Create: `tests/Feature/Registrations/RegistrationDisplayTest.php`
- Modify: `app/Services/Player/RegistrationService.php` (`primary`, `overallStatus`)

**Interfaces:**
- Consumes: `Player::registrationIn`, `RegistrationFixtures`.
- Produces: `RegistrationService::pickPrimary(Collection $registrations, ?int $mainBranchId, ?int $leftStatusId): ?BranchRegistration` (static, no query) and `RegistrationService::overallOf(BranchRegistration $primary, Collection $registrations, ?int $leftStatusId): array{0: ?int, 1: ?string}` (static, no query).
- Produces: `RegistrationDisplay::make(): self`; `->main(Player): ?BranchRegistration`; `->shown(Player, ?int $branchId): ?BranchRegistration`; `->isActiveIn(Player, int $branchId): bool`; `->values(Player, ?int $branchId = null): array{registration_id: ?int, branch_id: ?int, category_id: ?int, position_id: ?int, licence_number: ?string, status_id: ?int, left_at: ?string, membership_id: ?string, in_branch: bool, left_branch: bool}`; static `RegistrationDisplay::singleBranch(iterable|int|null $ids): ?int`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Registrations/RegistrationDisplayTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Player\RegistrationDisplay;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class RegistrationDisplayTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function loaded(Player $player): Player
    {
        return Player::with('registrations')->findOrFail($player->id);
    }

    #[Test]
    public function no_branch_shows_the_main_registration(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->bothActive()));

        $this->assertSame($this->foot->id, $values['branch_id']);
        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
    }

    #[Test]
    public function one_branch_shows_that_registration(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->bothActive()), $this->swim->id);

        $this->assertSame($this->swim->id, $values['branch_id']);
        $this->assertSame($this->u15->id, $values['category_id']);
        $this->assertTrue($values['in_branch']);
        $this->assertFalse($values['left_branch']);
    }

    #[Test]
    public function a_branch_the_player_left_is_flagged_and_keeps_its_date(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->leftFootball()), $this->foot->id);

        $this->assertTrue($values['left_branch']);
        $this->assertSame($this->leaveDate, $values['left_at']);
        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $values['status_id']);
    }

    #[Test]
    public function no_branch_after_leaving_one_shows_the_one_still_active(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->leftFootball()));

        $this->assertSame($this->swim->id, $values['branch_id']);
        $this->assertNull($values['left_at']);
    }

    #[Test]
    public function left_everywhere_shows_left_with_the_latest_date(): void
    {
        $player = $this->leftBoth();
        app(RegistrationService::class)->refreshMirror($player);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['left_at' => '2026-04-01']);

        $values = RegistrationDisplay::make()->values($this->loaded($player));

        $this->assertSame(PlayerStatus::where('code', 'left')->value('id'), $values['status_id']);
        $this->assertSame('2026-04-01', $values['left_at']);
    }

    #[Test]
    public function a_branch_the_player_never_joined_shows_main_values_not_in_branch(): void
    {
        $player = Player::create(['firstname' => 'Solo', 'join_year' => 2020]);
        app(RegistrationService::class)->register($player, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);

        $values = RegistrationDisplay::make()->values($this->loaded($player), $this->swim->id);

        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
        $this->assertFalse($values['left_branch']);
    }

    #[Test]
    public function no_registration_falls_back_to_the_players_columns(): void
    {
        $values = RegistrationDisplay::make()->values($this->loaded($this->noRegistration()), $this->foot->id);

        $this->assertNull($values['registration_id']);
        $this->assertNull($values['branch_id']);
        $this->assertSame($this->senior->id, $values['category_id']);
        $this->assertFalse($values['in_branch']);
    }

    #[Test]
    public function active_in_means_a_registration_there_not_left(): void
    {
        $display = RegistrationDisplay::make();
        $player = $this->loaded($this->leftFootball());

        $this->assertFalse($display->isActiveIn($player, $this->foot->id));
        $this->assertTrue($display->isActiveIn($player, $this->swim->id));
        $this->assertFalse($display->isActiveIn($this->loaded($this->noRegistration()), $this->foot->id));
    }

    #[Test]
    public function values_cost_no_query_per_row(): void
    {
        foreach (range(1, 5) as $n) {
            $this->bothActive("P$n");
        }
        $players = Player::with('registrations')->get();
        $display = RegistrationDisplay::make();

        DB::enableQueryLog();
        $players->each(fn (Player $p) => $display->values($p, $this->swim->id));
        $players->each(fn (Player $p) => $display->values($p));
        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function the_main_row_matches_the_service(): void
    {
        $service = app(RegistrationService::class);
        foreach ([$this->bothActive(), $this->leftFootball(), $this->leftBoth()] as $player) {
            $this->assertSame($service->primary($player)->id, RegistrationDisplay::make()->main($this->loaded($player))->id);
        }
    }

    #[Test]
    public function single_branch_needs_exactly_one(): void
    {
        $this->assertSame(4, RegistrationDisplay::singleBranch([4]));
        $this->assertSame(4, RegistrationDisplay::singleBranch(4));
        $this->assertSame(4, RegistrationDisplay::singleBranch(['4']));
        $this->assertNull(RegistrationDisplay::singleBranch([4, 5]));
        $this->assertNull(RegistrationDisplay::singleBranch([]));
        $this->assertNull(RegistrationDisplay::singleBranch(null));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Registrations/RegistrationDisplayTest.php`
Expected: FAIL, `Class "App\Services\Player\RegistrationDisplay" not found`.

- [ ] **Step 3: Split the choice from the query in `RegistrationService`**

Replace `primary()` with:

```php
    /**
     * The main branch's registration if not left, else the earliest joined not
     * left, else the lowest id. Reads fresh rows, because writers call it straight
     * after changing them. A loop over many players uses RegistrationDisplay.
     */
    public function primary(Player $player): ?BranchRegistration
    {
        return self::pickPrimary($player->registrations()->orderBy('id')->get(), Branch::mainId(), Player::leftStatusId());
    }

    /**
     * The same choice from rows already in hand, with no query.
     *
     * @param  Collection<int, BranchRegistration>  $registrations
     */
    public static function pickPrimary(Collection $registrations, ?int $mainBranchId, ?int $leftStatusId): ?BranchRegistration
    {
        if ($registrations->isEmpty()) {
            return null;
        }

        $ordered = $registrations->sortBy('id')->values();
        $active = $ordered->reject(fn (BranchRegistration $r) => $leftStatusId !== null && (int) $r->status_id === $leftStatusId);

        return ($mainBranchId === null ? null : $active->firstWhere('branch_id', $mainBranchId))
            ?? $active->sortBy(fn (BranchRegistration $r) => [self::joinSortKey($r), $r->id])->first()
            ?? $ordered->first();
    }
```

Replace `overallStatus()` with:

```php
    /**
     * The status and leave date the player copy holds: left only when left
     * everywhere (with the latest leave date), else the primary's status and no date.
     *
     * @param  Collection<int, BranchRegistration>  $registrations
     * @return array{0: int|null, 1: string|null}
     */
    public function overallStatus(BranchRegistration $primary, Collection $registrations): array
    {
        return self::overallOf($primary, $registrations, Player::leftStatusId());
    }

    /**
     * The same answer with no query.
     *
     * @param  Collection<int, BranchRegistration>  $registrations
     * @return array{0: int|null, 1: string|null}
     */
    public static function overallOf(BranchRegistration $primary, Collection $registrations, ?int $leftStatusId): array
    {
        if ($leftStatusId !== null && $registrations->every(fn (BranchRegistration $r) => (int) $r->status_id === $leftStatusId)) {
            return [$leftStatusId, $registrations->max(fn (BranchRegistration $r) => $r->left_at?->toDateString())];
        }

        return [$primary->status_id, null];
    }
```

Change the signature `private function joinSortKey` to `private static function joinSortKey`. Its body is unchanged.

- [ ] **Step 4: Write `RegistrationDisplay`**

`app/Services/Player/RegistrationDisplay.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;

/**
 * Which registration's values a list, page or report shows for a player. Build
 * one per request with make(), and eager-load `registrations`: then it costs no
 * query per row. A player with no registration shows their own columns.
 */
final class RegistrationDisplay
{
    public function __construct(
        private readonly ?int $mainBranchId,
        private readonly ?int $leftStatusId,
    ) {}

    public static function make(): self
    {
        return new self(Branch::mainId(), Player::leftStatusId());
    }

    /** The branch a filter means: exactly one selected, otherwise none. */
    public static function singleBranch(iterable|int|null $ids): ?int
    {
        $list = collect($ids ?? [])->filter()->values();

        return $list->count() === 1 ? (int) $list->first() : null;
    }

    public function main(Player $player): ?BranchRegistration
    {
        return RegistrationService::pickPrimary($player->registrations, $this->mainBranchId, $this->leftStatusId);
    }

    /** That branch's registration when the player has one, otherwise the main one. */
    public function shown(Player $player, ?int $branchId): ?BranchRegistration
    {
        return $player->registrationIn($branchId) ?? $this->main($player);
    }

    public function isActiveIn(Player $player, int $branchId): bool
    {
        $row = $player->registrationIn($branchId);

        return $row !== null && ! $this->isLeft($row);
    }

    /**
     * @return array{registration_id: int|null, branch_id: int|null, category_id: int|null, position_id: int|null, licence_number: string|null, status_id: int|null, left_at: string|null, membership_id: string|null, in_branch: bool, left_branch: bool}
     */
    public function values(Player $player, ?int $branchId = null): array
    {
        $row = $this->shown($player, $branchId);

        if ($row === null) {
            return [
                'registration_id' => null,
                'branch_id' => null,
                'category_id' => $player->category_id,
                'position_id' => $player->position_id,
                'licence_number' => $player->licence_number,
                'status_id' => $player->status_id,
                'left_at' => $player->left_at?->toDateString(),
                'membership_id' => $player->membership_id,
                'in_branch' => false,
                'left_branch' => false,
            ];
        }

        $inBranch = $branchId !== null && (int) $row->branch_id === $branchId;
        [$status, $leftAt] = $inBranch
            ? [$row->status_id, $row->left_at?->toDateString()]
            : RegistrationService::overallOf($row, $player->registrations, $this->leftStatusId);

        return [
            'registration_id' => $row->id,
            'branch_id' => $row->branch_id,
            'category_id' => $row->category_id,
            'position_id' => $row->position_id,
            'licence_number' => $row->licence_number,
            'status_id' => $status,
            'left_at' => $leftAt,
            'membership_id' => $row->membership_id ?? $player->membership_id,
            'in_branch' => $inBranch,
            'left_branch' => $inBranch && $this->isLeft($row),
        ];
    }

    private function isLeft(BranchRegistration $row): bool
    {
        return $this->leftStatusId !== null && (int) $row->status_id === $this->leftStatusId;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Registrations`
Expected: all PASS, including the untouched `RegistrationServiceTest`, which pins `primary()`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Player/RegistrationService.php app/Services/Player/RegistrationDisplay.php tests/Feature/Registrations/RegistrationDisplayTest.php
git commit -m "feat(registrations): show a registration's values with no query per row

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Check Part 1 on all three databases

Nothing reads the new code yet, so this proves the SQL itself.

**Files:** none.

- [ ] **Step 1: Run the new tests on PostgreSQL and MariaDB**

PostgreSQL, on a separate empty database:

```bash
"/c/Program Files/PostgreSQL/18/bin/dropdb" --if-exists -h 127.0.0.1 -p 5544 -U postgres sportclub_r2_test
"/c/Program Files/PostgreSQL/18/bin/createdb" -h 127.0.0.1 -p 5544 -U postgres sportclub_r2_test
# Three old migrations fail on a new PostgreSQL database (pg-report.md, finding A, decision pending).
# Patch them for this run only, and never commit the patch.
git apply .superpowers/r2/pg-throwaway-migration-patches.diff
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=
  php artisan test tests/Feature/Registrations/SharedQueryTest.php tests/Feature/Registrations/RegistrationDisplayTest.php tests/Feature/PlayerSearchTest.php )
git checkout -- database/migrations
```

MariaDB, on a private instance:

```bash
M=/c/xampp/mysql/bin
"$M/mysql_install_db.exe" --datadir="D:/irnb-branch-reg-r2/.superpowers/r2/maria"
"$M/mysqld.exe" --no-defaults --datadir="D:/irnb-branch-reg-r2/.superpowers/r2/maria" --port=3399 --bind-address=127.0.0.1 &
"$M/mysql.exe" --no-defaults -h 127.0.0.1 -P 3399 -u root -e "CREATE DATABASE sportclub_r2_test CHARACTER SET utf8mb4"
( export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=sportclub_r2_test DB_USERNAME=root DB_PASSWORD= DB_URL= APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
  php artisan test tests/Feature/Registrations/SharedQueryTest.php tests/Feature/Registrations/RegistrationDisplayTest.php )
```

Expected: all PASS on both. If migrations fail on PostgreSQL, Part 0 will already have reported it; use the owner's decision. A failure in the new SQL itself must be fixed here, before any reader uses it.

- [ ] **Step 2: Run the part's closing check on SQLite**

```bash
php artisan config:clear && php artisan test 2>&1 | tail -5
( . .superpowers/r2/env-sqlite.sh; php .superpowers/r2/figures.php p1 )
php .superpowers/r2/diff-figures.php baseline p1
```

Expected: suite as at the baseline, `unexpected differences: 0`, and no two-branch differences either, because no reader has moved.

---

# Part 8: Full check, owner's browser test, merge if approved

Runs after Part 7. Spec sections 10 and 14.

### Task 8: Dry runs of the whole migration

**Files:**
- Modify: `.superpowers/r2/pg-report.md`

- [ ] **Step 1: Prepare the four sources**

```bash
cd /d/irnb-branch-reg-r2/.superpowers/r2/data
cp real-baseline.sqlite dry-test-copy.sqlite
# The 2026-11-01 backup: extract a copy, leave the zip alone.
mkdir -p backup && unzip -o -q "D:/BACKUPS/sport-club-backup-2026-11-01_195807.zip" -d backup
find backup -name "*.sqlite" | head -1 | xargs -I{} cp {} dry-backup.sqlite
```

**The desktop 2.4.2 database:** `%APPDATA%\sport-club` is off limits, even for reading. Ask the owner to place a copy of it at `.superpowers/r2/data/dry-desktop.sqlite`, or to allow one read-only copy. Without either, say this dry run was skipped; don't work around it.

- [ ] **Step 2: Migrate each source twice, with checks**

For each of `dry-test-copy`, `dry-desktop`, `dry-backup`:

```bash
export DB_CONNECTION=sqlite DB_DATABASE="D:/irnb-branch-reg-r2/.superpowers/r2/data/dry-test-copy.sqlite" APP_CONFIG_CACHE="D:/irnb-branch-reg-r2/.superpowers/r2/no-config-cache.php"
php artisan config:show database.connections.sqlite.database
php artisan migrate --force
php artisan registrations:verify
php artisan migrate:rollback --force --step=1 && php artisan migrate --force
php artisan registrations:verify
php .superpowers/r2/figures.php final
php .superpowers/r2/diff-figures.php baseline final
php .superpowers/r2/sweep.php final | tail -5
php .superpowers/r2/diff-sweep.php baseline final > .superpowers/r2/data/sweep-final.txt
```

Expected: both `registrations:verify` runs exit 0. Money tables identical. `diff-figures` flags the one-branch differences allowed by spec section 14 as `UNEXPECTED`, and Part 7's moved category IDs too (compare those with `diff-split.php`). Every line must fall under a spec decision or that allowed list; anything else is a bug. List them all in `.superpowers/r2/differences.md` with the rule each follows. No 5xx.

- [ ] **Step 3: Repeat on PostgreSQL and MariaDB**

Load the pre-split rows into each with `pg-load.php`. For MariaDB, adapt `pg-load.php` to `SET FOREIGN_KEY_CHECKS = 0`, with no sequence reset. Migrate to the head twice, then run the same verify, figures, sweep and diff.

Then run a fresh install on all three drivers: `php artisan migrate:fresh --seed --force` on empty private databases only, and the guard's safe-target rule applies.

Then run the full suite on PostgreSQL and MariaDB, one at a time, never two suites in one worktree (backup tests swap real files):

```bash
( . .superpowers/r2/env-pgsql.sh; export DB_DATABASE=sportclub_r2_test DB_URL=; php artisan test 2>&1 | tail -40 )
```

Expected: no 500 anywhere. Failures that come from a test's own assumption (ordering, a hard-coded ID) are listed and traced, as in the MariaDB run at `1ca7362`. Run a class alone before believing a duplicate-key failure, because `SessionGeneratorRetryTest`'s fake deadlock leaks.

### Task 9: Query budget, browser check, report, owner's test

**Files:**
- Modify: `HANDOFF.md`, the memory files, on approval only as stated below.

- [ ] **Step 1: Confirm the budget tests**

Part 6 adds the query budget test (spec section 4). Run: `php artisan test --filter=QueryBudget`. Expected: PASS at 1,920 players.

- [ ] **Step 2: Browser check of the real forms**

Serve the finished work on the real-data copy on its own port. Never 2026.

```bash
( . .superpowers/r2/env-sqlite.sh; php artisan serve --port=2027 --no-reload )
```

Check in Arabic, French and English; light and dark; 320 px and 1280: Players list (no filter, one branch, two branches), player page tabs (registrations, documents, attendance), member card's branch question, session roster with a left player, team roster with the left flag, call-up refusal, match unpaid badge, subscription form, branch page with Categories tab, `/categories` redirect, Excel import error for a name the branch lacks. Look at the member card, receipt, and attendance PDFs visually (mPDF RTL). IDM may capture a same-named PDF, so confirm the file is fresh.

- [ ] **Step 3: Report to the owner**

Tell the owner, in plain language:
- The list from `.superpowers/r2/differences.md`.
- Each decision the parts made where the spec was silent, gathered in each part's closing task.
- **Those 9 players' swimming documents now start as missing**, as they chose.
- `TarificationData`'s left-player question (from Part 2).
- Remaining PostgreSQL findings.
- What couldn't be tested: production's real data. `registrations:verify` must rerun on a production copy before deploy, with a fresh backup first, using `pg_dump` if `BackupService` can't back up PostgreSQL.

Ask them to test on their own, with the server pointed at this worktree's copy. Fixes from their test go on this branch.

- [ ] **Step 4: Merge only on fresh approval**

Use `superpowers:finishing-a-development-branch`. Run merge and push as separate commands. Right before pushing: check `git log -1 main`, `git log origin/main..main`, and the suite on the merged tip. Tell the owner the deploy steps: `php artisan migrate` with the fresh backup first, then `registrations:verify`. Release 1, Release 2 and the split reach production together. No desktop build unless they ask.
