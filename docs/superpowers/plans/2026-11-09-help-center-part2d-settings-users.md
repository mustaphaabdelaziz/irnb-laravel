# Help Center Part 2d — Settings & users Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `settings` help topic (8 Arabic articles with screenshots), the demo users/roles/activity the screenshots need, and Arabic/French names for the club colour presets.

**Architecture:** Same Help Center engine as Parts 1, 2a, 2b and 2c (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue). A new `HelpDemoUsersSeeder`, called last from the guarded `HelpDemoSeeder`, adds staff accounts, one custom role, one per-user extra right and activity entries. The screenshot script gets two small changes: each shot is taken light then dark before the next shot (so a shot that writes data can't make the dark set differ from the light set), and an `ownLogin` shot option for "log in as" shots, which would otherwise replace the shared session. No Help framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-08-help-center-part2-design.md` (phase 2d). References: `resources/help/README.md` (authoring guide), `resources/help/ar/finance/*.md` and `resources/help/ar/subscriptions/*.md` (house style), `docs/superpowers/plans/2026-11-08-help-center-part2c-finance.md` (previous phase, same task shapes).

## Owner decisions for 2d (2026-11-09)

- **No demo club logo.** The logo shows in the sidebar of every page; seeding one would make every older screenshot stale. The club-identity article shows the «الشعار» upload field empty.
- **Translate the colour presets.** Settings → «لون النادي» shows the 12 presets with English names (Emerald, Blue…) in every language. Give them ar/fr/en names (Task 1); the article quotes the Arabic name.
- **No pending-approval demo user** (controller decision, reported to the owner): nothing in the app creates unapproved accounts any more (admin-made users are approved at once; registration was removed), and one pending user puts a «1» badge on the closed «النظام» sidebar section in every older screenshot. `manage-users` mentions old waiting accounts in one sentence, without a figure.

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…).
- Visibility: topic `settings` has **no topic module** (`'module' => null`); every article sets its own `module:`: club-identity `settings`, add-branch `branches`, add-category `categories`, branch-documents `document_types`, create-user `users`, roles-rights `superadmin`, manage-users `users`, log-in-as-activity `users`. Rights per route (`config/permissions.php` + `app/Support/PermissionMap.php`: last route-name segment store/create → add, destroy → delete, index/show → view, anything else → edit): `/settings` view, its saves (`settings.update`, `settings.theme`) edit; branches list/detail view, add = branches add, edit / set main / members = branches edit, delete = branches delete; categories list view, add/edit/activate/delete by right; document types list view, add/edit/activate/delete by right; `/users` view, create = users add, edit / approve / enable-disable / log in as = users edit, delete = users delete, password reset = users edit **and superadmin**; `/users/activity` and `/users/{id}/activity` = users view; `/roles` = superadmin only; per-user extra rights and the role field on user forms = superadmin only. Articles say which right a step needs when it is not view.
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (outcomes, not extra clicks; filling adjacent fields of one form card is one step), every clicked element ringed, phone hint where a step relies on gestures/sidebar/shortcuts («في الهاتف، افتح القائمة أولًا بزر ☰», «في الهاتف، اسحب الشاشة بإصبعك إلى الأعلى»), touchpad alongside mouse wheel, exact labels from `resources/js/i18n/ar.json` in «…», no emoji standing in for app icons, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- **Icon-only buttons** (`IconButton`: the name slides open on hover and is the tooltip/aria-label): describe the icon («زر القلم», «زر سلة المهملات», «زر علامة +») and quote the hover name only the way earlier articles do (grep `resources/help/ar/finance/caisses.md` and `cancel-transaction.md` for the house wording). Users list actions, roles page buttons, branch row buttons, category row buttons, document-type row buttons and «مستخدم جديد» / «نوع وثيقة جديد» are all icon-only.
- Sidebar paths: «تهيئة التطبيق» → «الفروع» / «الفئات» / «أنواع الوثائق»; «النظام» → «المستخدمون» / «النشاط» / «الأدوار» (superadmin only) / «الإعدادات».
- Lessons from 2a–2c: walk each article literally against the component's watchers and defaults; take each screenshot in the state the reader is in at that step; a step holds one action.
- **Shots must never save anything**, except where a shot says so and undoes it in `after`. In particular: **never click a colour swatch** (Settings → «لون النادي» previews and auto-saves the theme after 350 ms through `settings.theme`; a click would turn every later shot non-emerald); never press «إضافة» / «حفظ» / «إنشاء» / «حذف» / «تفعيل» / «تعطيل» / «موافقة» / «تعيينه فرعا رئيسيا» for real.
- Never document, screenshot or run the finance reset or Backups.
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first.
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`, a PDF). Never `git add -A`.
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## Demo facts (produced by Task 2, quoted by the articles)

Existing (HelpDemoSeeder and earlier sub-seeders): user `demo` «كريم المدرب» (superadmin + admin privileges), branches «السباحة» (main, stored name `Swimming`) and «كرة القدم» (`Football`), Arabic categories per branch, the migration's document types (required ones ticked for both branches; Photo asks 4 copies), roles from `RoleSeeder`: «مدير عام» and «مدير» (system), «محاسب» (accountant), «مدرب» (coach, own teams only). Club name «نادي المثال», no logo, colour Emerald.

New (Task 2):

| Who | username | Role | State | Extra | `logged_in_at` |
|---|---|---|---|---|---|
| سمير بوعزة (firstname سمير, lastname بوعزة) | `samir` | «مدرب» | approved, active | phone `0661234567` | 1 day before the seed day, 18:40 |
| نادية خليفي | `nadia` | «محاسب» | approved, active | phone `0770987654`; **extra right** `permission_overrides.grant = {players: [view]}` | seed day, 08:15 |
| حنان سعيدي (Female) | `hanane` | «كاتب عام» (custom) | approved, active | — | null (shows «أبدًا») |
| ليلى بن صالح (Female) | `layla` | «مدرب» | approved, **disabled** (`is_active` false) | — | 40 days before |

Custom role «كاتب عام» (key `secretary`, name ar «كاتب عام» / fr «Secrétaire général» / en «General secretary», `is_system` false): `players` view/add/edit, `documents` view/add/edit, `attendance` view.

Activity (dated N days before the seed day, clamped to the season start; shots open `?period=season`):

| Actor | Action (`ActivityAction`) | Days before | Subject |
|---|---|---|---|
| samir | `USER_LOGGED_IN` | 1, 3, 6, 8 | none |
| samir | `ATTENDANCE_MARKED` | 1, 3, 6, 8 | the held U17 training sessions (newest first, from `HelpDemoAttendanceSeeder`) |
| nadia | `USER_LOGGED_IN` | 0, 2, 5, 7 | none |
| nadia | `TRANSACTION_RECORDED` | 2, 5, 7 | three current-season hand entries (oldest ids first) |
| demo | `USER_IMPERSONATED` | 2 | nadia |

Nadia's day-2 `TRANSACTION_RECORDED` carries `impersonator_id` = demo (shows the «بواسطة …» badge). Expected activity totals this season: samir 8, nadia 7, demo 1 (+ what the screenshot script itself records: its login, `fin-tx-saved`, the log-in-as shots).

Caveat: if the seed day is in the first ~8 days of the season (early September), entries cluster on the season's first day; regenerate shots later. User ids are pinned by the seeder test and reported by Task 2 for `shots.js`.

## File map

| File | Responsibility |
|---|---|
| `resources/help/topics.php` (modify) | add `settings` topic |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | `help.topic.settings`, `theme_preset.*` |
| `resources/js/Pages/Settings/General.vue` (modify) | preset names through i18n; `data-help` attributes |
| `database/seeders/HelpDemoUsersSeeder.php` (create) | users, custom role, extra right, activity |
| `database/seeders/HelpDemoSeeder.php` (modify) | call the new seeder last |
| `tests/Feature/Help/HelpDemoUsersSeederTest.php` (create) | pins the demo facts above |
| `resources/js/Pages/Settings/{Branches,BranchDetail,DocumentTypes}.vue`, `Settings/Partials/{BranchGeneralForm,MemberCategoriesPanel}.vue`, `Pages/Users/{Index,Create,Edit}.vue`, `Pages/Users/Activity/{Index,Show}.vue`, `Pages/Roles/Index.vue`, `Components/PermissionGrid.vue`, `Components/Activity/PeriodFilter.vue`, `Layouts/AppShell.vue`, `Components/Sidebar/SidebarSection.vue` (modify) | `data-help` attributes only |
| `scripts/help-shots.mjs` (modify, Task 6) | per-shot light+dark order; `ownLogin` option |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/settings/*.md` (create) | 8 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: `settings` topic and colour preset names

**Files:**
- Modify: `resources/help/topics.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json`
- Modify: `resources/js/Pages/Settings/General.vue:516`

**Interfaces:**
- Produces: topic `settings` (icon `settings`, module `null`, article order `club-identity, add-branch, add-category, branch-documents, create-user, roles-rights, manage-users, log-in-as-activity`); i18n `help.topic.settings`; i18n `theme_preset.<preset key>` for the 12 keys of `config/themes.php`.

- [ ] **Step 1: Add the topic** — in `resources/help/topics.php`, after the `finance` line:

```php
        'settings' => ['icon' => 'settings', 'module' => null, 'articles' => ['club-identity', 'add-branch', 'add-category', 'branch-documents', 'create-user', 'roles-rights', 'manage-users', 'log-in-as-activity']],
```

`settings` exists in `resources/js/Components/Icon.vue:29`. Check in `app/Services/Help/HelpLibrary.php` how a topic with `module => null` behaves when the user can see **none** of its articles (e.g. a coach with no settings/branches/categories/document_types/users rights): the tile must not show an empty topic. If `HelpLibrary` would list an empty topic tile, stop and report (no framework change in this task without the controller's go-ahead).

- [ ] **Step 2: i18n** — text edits, insert-only.

`help.topic.settings` right after `help.topic.finance` in each file:

| key | ar | fr | en |
|---|---|---|---|
| `help.topic.settings` | الإعدادات والمستخدمون | Paramètres et utilisateurs | Settings & users |

The 12 preset names, as a block of keys in each file (insert in key order where the file keeps keys sorted; grep around `"theme` first):

| key | ar | fr | en |
|---|---|---|---|
| `theme_preset.emerald` | زمردي | Émeraude | Emerald |
| `theme_preset.green` | أخضر | Vert | Green |
| `theme_preset.teal` | فيروزي | Sarcelle | Teal |
| `theme_preset.blue` | أزرق | Bleu | Blue |
| `theme_preset.sky` | سماوي | Ciel | Sky |
| `theme_preset.indigo` | نيلي | Indigo | Indigo |
| `theme_preset.violet` | بنفسجي | Violet | Violet |
| `theme_preset.rose` | وردي | Rose | Rose |
| `theme_preset.red` | أحمر | Rouge | Red |
| `theme_preset.orange` | برتقالي | Orange | Orange |
| `theme_preset.amber` | كهرماني | Ambre | Amber |
| `theme_preset.slate` | رمادي | Ardoise | Slate |

Check first that no message key `theme_preset` exists on its own (a flat key that is both a message and a prefix breaks vue-i18n).

- [ ] **Step 3: Use them** — in `General.vue:516` replace `{{ preset.name }}` with:

```vue
<span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t(`theme_preset.${preset.key}`) }}</span>
```

Grep the rest of `resources/js` for other places that print a preset's `name` (e.g. a preview label, the custom-colour state) and switch them the same way. Leave `config/themes.php` `name` as it is (English fallback for PHP callers, if any).

- [ ] **Step 4: Run** `php artisan test --filter=Help` (all pass), `npm run i18n:check`, `npm run build`. Open `/settings` → «العلامة التجارية والصور» in ar and fr in the dev app (or a throwaway Playwright script) and confirm the Arabic/French names show.

- [ ] **Step 5: Commit**

```bash
git add resources/help/topics.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/js/Pages/Settings/General.vue
git commit -m "feat(help): settings topic; colour presets named in every language"
```

---

### Task 2: Demo users seeder

**Files:**
- Create: `database/seeders/HelpDemoUsersSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoFinanceSeeder`)
- Test: `tests/Feature/Help/HelpDemoUsersSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`, `HelpDemoSeeder::USERNAME`, `HelpDemoSeeder::PASSWORD`; `User` (fillable incl. `username, email, password, firstname, lastname, gender, phones, is_user, is_active, approved, privileges, role_id, permission_overrides, preferred_lng, logged_in_at`; the `saving` hook computes `name` from firstname + lastname — still pass `name` if the column is NOT NULL and the hook runs after validation… check); `Role` (`key`, `name` JSON, `permissions` JSON, `is_system`, `own_teams_only`); `ActivityRecorder::record(?User $user, string $action, ?Model $subject = null, array $properties = []): ActivityLog`; `ActivityLog` (`impersonator_id`, `occurred_at`); `ActivityAction::USER_LOGGED_IN`, `ATTENDANCE_MARKED`, `TRANSACTION_RECORDED`, `USER_IMPERSONATED`; `TrainingSession` (held U17 sessions from `HelpDemoAttendanceSeeder` — find the model and the "held" condition there); `Transaction`; `App\Support\Season::current()->start()`.
- Produces: constants `HelpDemoUsersSeeder::COACH = 'samir'`, `ACCOUNTANT = 'nadia'`, `SECRETARY = 'hanane'`, `DISABLED = 'layla'`, `ROLE_KEY = 'secretary'`, `ROLE_NAME = 'كاتب عام'`; the demo facts table above; the user ids (demo, samir, nadia, hanane, layla), the custom role id, the Football and Swimming branch ids, and the Football category list URL written into the task report for later shots.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Help/HelpDemoUsersSeederTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoUsersSeeder as Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-season seed day so no activity entry is clamped to the season start.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    #[Test]
    public function staff_accounts_have_the_expected_roles_and_states(): void
    {
        $this->assertSame(5, User::count());

        $coach = $this->user(Demo::COACH);
        $this->assertSame('coach', $coach->role->key);
        $this->assertTrue((bool) $coach->is_active);
        $this->assertSame('سمير بوعزة', $coach->name);

        $this->assertSame('accountant', $this->user(Demo::ACCOUNTANT)->role->key);
        $this->assertSame(Demo::ROLE_KEY, $this->user(Demo::SECRETARY)->role->key);
        $this->assertNull($this->user(Demo::SECRETARY)->logged_in_at);

        $disabled = $this->user(Demo::DISABLED);
        $this->assertFalse((bool) $disabled->is_active);
        $this->assertTrue((bool) $disabled->approved);

        // Nobody waits for approval (see the plan's owner decisions).
        $this->assertSame(0, User::where('approved', false)->count());
    }

    #[Test]
    public function the_custom_role_and_the_extra_right_exist(): void
    {
        $role = Role::where('key', Demo::ROLE_KEY)->firstOrFail();
        $this->assertFalse((bool) $role->is_system);
        $this->assertSame(Demo::ROLE_NAME, $role->name['ar']);
        $this->assertSame(['view', 'add', 'edit'], $role->permissions['players']);
        $this->assertSame(['view'], $role->permissions['attendance']);

        $nadia = $this->user(Demo::ACCOUNTANT);
        $this->assertSame(['view'], $nadia->permission_overrides['grant']['players']);
        $this->assertTrue($nadia->hasPermission('players', 'view'));
        $this->assertFalse($nadia->hasPermission('players', 'add'));
    }

    #[Test]
    public function activity_entries_are_spread_over_the_season(): void
    {
        $count = fn (string $u, ?string $action = null) => ActivityLog::where('user_id', $this->user($u)->id)
            ->when($action, fn ($q) => $q->where('action', $action))->count();

        $this->assertSame(8, $count(Demo::COACH));
        $this->assertSame(4, $count(Demo::COACH, ActivityAction::ATTENDANCE_MARKED));
        $this->assertSame(7, $count(Demo::ACCOUNTANT));
        $this->assertSame(1, $count(HelpDemoSeeder::USERNAME, ActivityAction::USER_IMPERSONATED));

        $byAdmin = ActivityLog::where('user_id', $this->user(Demo::ACCOUNTANT)->id)
            ->where('impersonator_id', $this->user(HelpDemoSeeder::USERNAME)->id)->count();
        $this->assertSame(1, $byAdmin);

        $this->assertSame(0, ActivityLog::where('occurred_at', '>', now())->count());
        $this->assertSame(0, ActivityLog::where('occurred_at', '<', \App\Support\Season::current()->start())->count());
    }
}
```

Adjust relation/column names to what the code really has (`User::role()`, `permission_overrides` cast, `Role::name` cast). If `User::count()` is not 5 because `DatabaseSeeder` creates its own user, find out and pin the real number with the reason in the report.

- [ ] **Step 2: Run it** — `php artisan test --filter=HelpDemoUsersSeederTest` → FAIL (class `HelpDemoUsersSeeder` not found).

- [ ] **Step 3: Write the seeder** — `database/seeders/HelpDemoUsersSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts, a custom role and some activity for the Help Center demo club.
 * Called only from the guarded HelpDemoSeeder, last. ActivityRecorder says it is
 * never called from seeders: this throwaway screenshot database is the one
 * exception, so the activity pages have something real to show.
 */
class HelpDemoUsersSeeder extends Seeder
{
    public const COACH = 'samir';

    public const ACCOUNTANT = 'nadia';

    public const SECRETARY = 'hanane';

    public const DISABLED = 'layla';

    public const ROLE_KEY = 'secretary';

    public const ROLE_NAME = 'كاتب عام';

    private CarbonImmutable $today;

    private CarbonImmutable $seasonStart;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $this->seasonStart = Season::current()->start();

        $secretary = Role::create([
            'key' => self::ROLE_KEY,
            'name' => ['ar' => self::ROLE_NAME, 'fr' => 'Secrétaire général', 'en' => 'General secretary'],
            'permissions' => [
                'players' => ['view', 'add', 'edit'],
                'documents' => ['view', 'add', 'edit'],
                'attendance' => ['view'],
            ],
            'is_system' => false,
        ]);
        $coachRole = Role::where('key', 'coach')->firstOrFail();
        $accountantRole = Role::where('key', 'accountant')->firstOrFail();

        $samir = $this->user(self::COACH, 'سمير', 'بوعزة', 'Male', $coachRole, ['0661234567'], $this->today->subDay()->setTime(18, 40));
        $nadia = $this->user(self::ACCOUNTANT, 'نادية', 'خليفي', 'Female', $accountantRole, ['0770987654'], $this->today->setTime(8, 15));
        $nadia->update(['permission_overrides' => ['grant' => ['players' => ['view']], 'revoke' => []]]);
        $this->user(self::SECRETARY, 'حنان', 'سعيدي', 'Female', $secretary, [], null);
        $this->user(self::DISABLED, 'ليلى', 'بن صالح', 'Female', $coachRole, [], $this->today->subDays(40)->setTime(17, 0), active: false);

        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();

        foreach ([1, 3, 6, 8] as $days) {
            $this->log($samir, ActivityAction::USER_LOGGED_IN, null, $days, 17);
        }
        // Held U17 sessions, newest first (HelpDemoAttendanceSeeder): adapt the query to its model and "held" rule.
        $sessions = \App\Models\TrainingSession::query()
            ->where('status', 'held')->orderByDesc('date')->limit(4)->get();
        foreach ([1, 3, 6, 8] as $i => $days) {
            $this->log($samir, ActivityAction::ATTENDANCE_MARKED, $sessions[$i] ?? null, $days, 19);
        }

        foreach ([0, 2, 5, 7] as $days) {
            $this->log($nadia, ActivityAction::USER_LOGGED_IN, null, $days, 8);
        }
        $entries = Transaction::query()->whereNull('player_id')->where('archived', false)
            ->orderBy('id')->limit(3)->get();
        $this->log($demo, ActivityAction::USER_IMPERSONATED, $nadia, 2, 9);
        foreach ([2, 5, 7] as $i => $days) {
            $log = $this->log($nadia, ActivityAction::TRANSACTION_RECORDED, $entries[$i] ?? null, $days, 10);
            if ($days === 2) {
                // Done while the admin was logged in as her: the «بواسطة …» badge.
                $log->update(['impersonator_id' => $demo->id]);
            }
        }
    }

    private function user(string $username, string $first, string $last, string $gender, Role $role, array $phones, ?CarbonImmutable $loggedIn, bool $active = true): User
    {
        return User::create([
            'username' => $username,
            'email' => $username.'@example.invalid',
            'password' => Hash::make(HelpDemoSeeder::PASSWORD),
            'firstname' => $first,
            'lastname' => $last,
            'gender' => $gender,
            'phones' => $phones,
            'is_user' => true,
            'is_active' => $active,
            'approved' => true,
            'privileges' => ['user'],
            'role_id' => $role->id,
            'preferred_lng' => 'ar',
            'email_verified_at' => now(),
            'logged_in_at' => $loggedIn,
        ]);
    }

    private function log(User $user, string $action, ?Model $subject, int $daysBefore, int $hour): ActivityLog
    {
        $log = ActivityRecorder::record($user, $action, $subject);
        $log->update(['occurred_at' => $this->today->subDays($daysBefore)->setTime($hour, 5)->max($this->seasonStart)]);

        return $log;
    }
}
```

Verify before running: the training-session model name, its date column and the held condition (read `HelpDemoAttendanceSeeder`); the transaction columns used to pick hand entries (`player_id`, `archived`); whether `ActivityLog` allows `update()` (fillable, no `updated_at`: set `const UPDATED_AT = null` is already there? If `update()` fails, use `ActivityLog::whereKey($log->id)->update([...])`); `CarbonImmutable::max()` returns the later date (used in 2c). `TRANSACTION_RECORDED` may expect properties (amount, category code) — read where the controller records it and pass the same small properties so the entry renders like a real one; same for `ATTENDANCE_MARKED`.

- [ ] **Step 4: Call it** — in `HelpDemoSeeder::run()`, after `$this->call(HelpDemoFinanceSeeder::class);`:

```php
        $this->call(HelpDemoUsersSeeder::class);
```

- [ ] **Step 5: Run** `php artisan test --filter=HelpDemo` → all pass (the subscriptions, attendance and finance seeder tests too).

- [ ] **Step 6: Seed the screenshot DB once** (`npm run help:shots -- login` reseeds it; or seed `storage/help-demo.sqlite` the way the script does and serve it) and open `/users`, `/users/activity?period=season`, `/users/<samir>/activity?period=season`, `/roles` as demo to check the list, statuses, «آخر دخول», «أبدًا», the role user counts and the charts look right. Confirm the «النظام» section of the sidebar shows **no** badge. Write the ids listed under "Produces" into the report.

- [ ] **Step 7: Commit**

```bash
git add database/seeders/HelpDemoUsersSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoUsersSeederTest.php
git commit -m "feat(help): demo staff accounts, custom role and activity"
```

---

### Task 3: Articles 1–2 — club identity; add a branch

**Files:**
- Create: `resources/help/ar/settings/club-identity.md`, `add-branch.md`
- Modify: `data-help` attributes in `resources/js/Pages/Settings/General.vue`, `Settings/Branches.vue`, `Settings/BranchDetail.vue`, `Settings/Partials/BranchGeneralForm.vue`, `resources/js/Components/Sidebar/SidebarSection.vue` (nav map); `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify in code before writing:
- **Settings** (`WebsiteConfigController`, `UpdateWebsiteConfigRequest`, `General.vue`): tabs are side buttons; «المعلومات الأساسية» holds the three club-name fields (labels hard-coded with flag emoji — quote only the Arabic words «اسم النادي (عربي)», never the emoji), «الاختصار» (max 20), «العبارة التعريفية», «الوصف», «تاريخ التأسيس», «الشعار النصي», button «حفظ» → «تم تحديث الإعدادات بنجاح.». «العلامة التجارية والصور»: card «لون النادي» (hint «اختر اللون الأساسي ليطابق ألوان ناديك.»), the 12 presets (now with Arabic names), «لون مخصص» (HEX + «استخدام»), hint «تظهر المعاينة فورًا. اضغط حفظ لتطبيقها على الجميع.»; «الشعار» (any image, ≤ 2 MB) and «أيقونة الموقع» (≤ 512 KB). **Check what really saves the colour**: the code auto-saves the preset through `settings.theme` 350 ms after the click (no flash), while the hint says to press «حفظ». Write only what is true; if the hint contradicts the code, say what the code does and report the mismatch to the controller as an owner nit (do not change the hint). Where the logo shows: sidebar, login page, browser tab when no «أيقونة الموقع», PDF headers.
- **Branches** (`BranchController`, `Branches.vue`, `BranchDetail.vue`, `BranchGeneralForm.vue`): add form fields «الاسم» (required, unique), «رمز الفرع» (2–6 Latin capitals, optional; suggested from the Latin letters of the name — an Arabic-only name suggests nothing; locked once used in membership IDs: «لا يمكن تغيير الرمز بعد استخدامه في أرقام الانخراط.»), «الوصف», language names, «الشعار» (≤ 2 MB), button «إضافة» → «تم إنشاء الفرع بنجاح.». A new branch gets its coffre automatically (`CashRegisterProvisioner`). The first branch becomes main by itself; «تعيينه فرعا رئيسيا» (icon button, hidden on the main branch) → «تم تعيين الفرع الرئيسي.»; the main branch «grants no privileges» — check what it changes for users (players' primary registration; default for new categories) and say only that. Row icon buttons: set main, «الأعضاء» (players registered in the branch; modal adds players with «تاريخ الانضمام»; removing marks them as left), «تعديل» (opens the branch page: tabs «عام» / «الفئات»), «حذف» (refusals: main branch, teams, registrations, sessions, used categories).

**Outlines:**
1. `club-identity.md` «كيف أغيّر اسم النادي ولونه وشعاره» — module `settings`; pages `[settings.show]`; example: the club writes its names, picks «أزرق» and uploads its logo. Steps: sidebar «النظام» → «الإعدادات» (phone hint) → «المعلومات الأساسية»: type the names + «الاختصار» → «حفظ» → «العلامة التجارية والصور» → in «لون النادي» press «أزرق» (say what happens: preview + saving per the code) → «الشعار»: choose the file, then «حفظ». Tip: where the logo appears (sidebar, login page, printed PDFs). Mention: changing needs the settings edit right.
2. `add-branch.md` «كيف أضيف فرعًا رياضيًا» — module `branches`; pages `[branches.index, branches.show]`; example: the club opens «كرة اليد» (code `HB`). Steps: «تهيئة التطبيق» → «الفروع» (phone hint) → type «الاسم» and «رمز الفرع» → «إضافة» → the new row (its coffre «خزينة …» is created by itself; link `finance/caisses` in `related`) → add its categories: the row's edit button → tab «الفئات» (link `settings/add-category`) → main branch: «تعيينه فرعا رئيسيا» on another row. Tip: «الأعضاء» lists the players registered in the branch; players are usually added from their own page (link `players/branch-registrations`). Mention rights (add / edit). No ⚠️ unless delete is described (it is refused while the branch is used — one sentence).

**data-help targets (attribute only; confirm each lands on a real DOM element, not a component root that drops attrs):**
- `SidebarSection.vue` nav map: add `'/settings': 'nav-settings'`, `'/branches': 'nav-branches'`, `'/categories': 'nav-categories'`, `'/document-types': 'nav-document-types'`, `'/users': 'nav-users'`, `'/users/activity': 'nav-activity'`, `'/roles': 'nav-roles'`. The «تهيئة التطبيق» and «النظام» sections are closed on other pages: shots that ring a nav item must be taken on a page inside that section (the section opens on its own pages) — check.
- `General.vue`: `settings-tab-basic`, `settings-tab-branding`, `settings-club-names` (wrapper of the three name fields), `settings-short-name`, `settings-save` (basic tab button), `settings-colors` (preset grid), `settings-color-blue` (the `blue` preset button: `:data-help="preset.key === 'blue' ? 'settings-color-blue' : null"`), `settings-logo` (logo field), `settings-branding-save`.
- `Branches.vue`: `branch-add-form`, `branch-name`, `branch-code`, `branch-add`, `branch-row-football` and `branch-row-swimming` (by stored name, not index), `branch-set-main` (on the Football row's button), `branch-members` (Football row), `branch-edit` (Football row).
- `BranchDetail.vue`: `branch-tab-general`, `branch-tab-categories`.

**Shots** (insert in `resources/help/shots.js` under `// --- Settings & users --- (HelpDemoUsersSeeder)`, placed **before** `fin-tx-saved`, which must stay last):

```js
    { name: 'set-identity-basic', url: '/settings', targets: ['nav-settings', 'settings-tab-basic', 'settings-club-names', 'settings-short-name', 'settings-save'] },
    {
        // Never click a swatch: it saves the theme for every later shot.
        name: 'set-identity-branding',
        url: '/settings',
        targets: ['settings-tab-branding', 'settings-colors', 'settings-color-blue', 'settings-logo', 'settings-branding-save'],
        before: async (page) => { await page.locator('[data-help="settings-tab-branding"]').click(); },
    },
    {
        // The reader's state before «إضافة»: name and code typed.
        name: 'set-branch-add',
        url: '/branches',
        targets: ['nav-branches', 'branch-name', 'branch-code', 'branch-add'],
        before: async (page) => {
            await page.fill('[data-help="branch-name"] input, input[data-help="branch-name"]', 'كرة اليد');
            await page.fill('[data-help="branch-code"] input, input[data-help="branch-code"]', 'HB');
        },
    },
    { name: 'set-branch-rows', url: '/branches', targets: ['branch-row-football', 'branch-set-main', 'branch-members', 'branch-edit'] },
    { name: 'set-branch-detail', url: '/branches/<football id>', targets: ['branch-tab-general', 'branch-tab-categories'] },
```

Use the real selectors for the inputs (whatever element carries the attribute); ids from the Task 2 report. If the logo field sits below the fold, scroll with `centre(page, 'settings-logo')` (helper at the bottom of `shots.js`). Run `npm run build && npm run help:shots -- set-identity-basic set-identity-branding set-branch-add set-branch-rows set-branch-detail`; view every image light + dark; rings on target; the app stays emerald in every image.

- [ ] **Step 1:** data-help attributes; `npm run build`.
- [ ] **Step 2:** shots; generate; view images (light and dark).
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `php artisan help:check` (OK), `php artisan test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/settings/club-identity.md resources/help/ar/settings/add-branch.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Settings/General.vue resources/js/Pages/Settings/Branches.vue resources/js/Pages/Settings/BranchDetail.vue resources/js/Components/Sidebar/SidebarSection.vue
git commit -m "docs(help): club-identity and add-branch articles"
```

(Stage only the files you changed; add `BranchGeneralForm.vue` if touched.)

---

### Task 4: Articles 3–4 — add a category; branch documents

**Files:**
- Create: `resources/help/ar/settings/add-category.md`, `branch-documents.md`
- Modify: `data-help` in `resources/js/Pages/Settings/Partials/MemberCategoriesPanel.vue`, `resources/js/Pages/Settings/DocumentTypes.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify:
- **Categories** (`CategoryController`, `MemberCategoriesPanel.vue`): `/categories` redirects to the main branch's «الفئات» tab (`/branches/{id}/categories`); other branches through the tab of that branch. Add form (add right): «الاسم» (required, unique **inside the branch**), «الوصف», language names, «إضافة» → «تم إنشاء الفئة بنجاح.». List is A→Z by name (no manual order). Inline edit with «حفظ» / «إلغاء» → «تم تحديث الفئة.». Icon buttons «إيقاف» / «تفعيل» → badge «موقوفة»; footer hint «الفئة الموقوفة لا تظهر عند الاختيار الجديد، وتبقى حيث هي محفوظة وفي التقارير.». Delete refused when used («لا يمكن الحذف: هذه الفئة مستعملة في تسجيلات.», teams).
- **Document types** (`DocumentTypeController`, `DocumentTypes.vue`, `DocumentType`, `DocumentChecklist`): types are club-wide and **ticked per branch** in «الفروع» (hint «يجب على اللاعبين المسجلين في الفروع المحددة تقديم هذه الوثيقة.»). A new type starts with every branch ticked. Fields «الاسم», language names, «الصلاحية» («بدون انتهاء» / «موسم واحد» / «إلى تاريخ محدد»), «حد السن» (+ hint), «عدد النسخ» (+ hint), «الترتيب», checkboxes «إلزامي», «مطلوب للمشاركة في المباريات», «نشط»; «حفظ» → «تم إنشاء نوع الوثيقة.». Unticking a branch that already has records asks a second confirmation («توجد {count} سجلات في {branch} لهذا النوع…»). Amber banner «هذه الفروع لا تشترط أي وثيقة: …». Delete (trash) only with 0 records, else deactivate («تعطيل»). What a player owes: active + required + age-applicable types ticked for each of his active registrations' branches; missing while copies received < «عدد النسخ». Check the exact list header button: icon-only «نوع وثيقة جديد».

**Outlines:**
3. `add-category.md` «كيف أضيف فئة داخل فرع» — module `categories`; pages `[categories.index, categories.show]`; example: Football opens «أقل من 19 سنة»; later the old «أكابر» group is paused for a season. Steps: «تهيئة التطبيق» → «الفئات» (phone hint; it opens the main branch: switch to «كرة القدم» through «الفروع» → edit → «الفئات», or whatever the page really offers — check) → type «الاسم» → «إضافة» → the new row (A→Z) → «إيقاف» on a category not used this season. Tip: a paused category keeps its players and reports. Mention: names repeat freely between branches (unique inside one branch).
4. `branch-documents.md` «كيف أحدد الوثائق المطلوبة في كل فرع» — module `document_types`; pages `[document-types.index]`; example: a new «رخصة الاتحاد» is required each season for Football only. Steps: «تهيئة التطبيق» → «أنواع الوثائق» (phone hint) → the «نوع وثيقة جديد» button → «الاسم» + «الصلاحية» «موسم واحد» → in «الفروع» leave only «كرة القدم» ticked → «إلزامي» ticked → «حفظ». Tip: «عدد النسخ» asks several copies (e.g. 4 photos); the player page then counts copies received (link `players/documents`). ⚠️ none needed (unticking asks its own confirmation; say so in one sentence).

**data-help targets:** `MemberCategoriesPanel.vue`: `cat-add-form`, `cat-name`, `cat-add`, `cat-row-first`, `cat-toggle-first` (first row's «إيقاف»/«تفعيل» button), `cat-edit-first`. `DocumentTypes.vue`: `doc-add` (header icon button), `doc-row-photo` (the Photo row, by code), `doc-modal`, `doc-name`, `doc-validity`, `doc-copies`, `doc-branches`, `doc-branch-swimming` (Swimming's checkbox, by branch name/id), `doc-required`, `doc-save`.

**Shots:**

```js
    { name: 'set-categories', url: '/branches/<football id>/categories', targets: ['nav-categories', 'cat-name', 'cat-add', 'cat-toggle-first'] },
    {
        name: 'set-category-typed',
        url: '/branches/<football id>/categories',
        targets: ['cat-name', 'cat-add'],
        before: async (page) => { /* type «أقل من 19 سنة» into the name input */ },
    },
    { name: 'set-doc-types', url: '/document-types', targets: ['nav-document-types', 'doc-add', 'doc-row-photo'] },
    {
        // The reader's state before «حفظ»: name typed, one season, Swimming unticked, required ticked.
        name: 'set-doc-type-form',
        url: '/document-types',
        targets: ['doc-name', 'doc-validity', 'doc-branches', 'doc-required', 'doc-save'],
        before: async (page) => {
            await page.locator('[data-help="doc-add"]').click();
            /* fill «رخصة الاتحاد», choose «موسم واحد», untick Swimming, tick «إلزامي» */
        },
    },
```

Never press «إضافة» / «حفظ». Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/settings/add-category.md resources/help/ar/settings/branch-documents.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Settings/Partials/MemberCategoriesPanel.vue resources/js/Pages/Settings/DocumentTypes.vue
git commit -m "docs(help): add-category and branch-documents articles"
```

---

### Task 5: Articles 5 and 7 — create a user; manage users

**Files:**
- Create: `resources/help/ar/settings/create-user.md`, `manage-users.md`
- Modify: `data-help` in `resources/js/Pages/Users/Index.vue`, `Users/Create.vue`, `Users/Edit.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify:
- **Create** (`UserController@store`, `StoreUserRequest`, `Users/Create.vue`): needs users add. Sections «بيانات الدخول» («اسم المستخدم» + hint, «كلمة المرور», «تأكيد كلمة المرور» — each with the eye button), «المعلومات الأساسية» («الاسم», «اللقب», «البريد الإلكتروني» optional, «الهاتف», «الجنس»), «الحساب والصلاحيات» («الدور» — **shown only to a superadmin**; others create users with no role, which a superadmin sets later; «اللغة»). Buttons «إلغاء» / «إنشاء» → back to the list with «تم إنشاء المستخدم بنجاح.». The account is approved and active at once; username lower-cased, letters/digits/`. _ - @ +`, 3–100; password rules from `Password::defaults()` (check `AppServiceProvider` for the real rule and quote it plainly). The new user signs in with the username and sees only what the role allows (link `getting-started/login`).
- **Manage** (`UserController`, `Users/Index.vue`, `Users/Edit.vue`): list filters (search, «الموافقة», «النشاط», «الدور»), columns (name + username, phone, role badge, status «مُوافَق عليه» / «قيد الانتظار» / «غير نشط», «آخر دخول» or «أبدًا»), 25 a page. Icon buttons per row: «موافقة» (only for an account waiting — old accounts only; one sentence, no figure), «الدخول بحساب…» (Task 6), «تعديل», «إعادة تعيين كلمة المرور» (superadmin only, never yourself; modal with «كلمة المرور الجديدة», «تأكيد كلمة المرور», note «سيتم تسجيل خروج المستخدم من جلسات «تذكرني»…», «حفظ»), «تعطيل» / «تفعيل» (not yourself; check whether it asks a confirmation) → «تم تعطيل المستخدم.» / «تم تفعيل المستخدم.», «حذف» (ConfirmModal «هل أنت متأكد؟»; not yourself, not the last superadmin; a non-superadmin cannot touch a superadmin). A disabled user cannot sign in (check the login refusal text) and keeps all history. Edit page: same fields + «مُوافَق عليه» / «نشط» checkboxes + photo; role and «تجاوزات متقدمة» only for a superadmin (Task 6).

**Outlines:**
5. `create-user.md` «كيف أنشئ حسابًا لعضو في الطاقم» — module `users`; pages `[users.index, users.create]`; example: the new U17 coach مراد زيتوني gets the account `mourad` with the role «مدرب». Steps: «النظام» → «المستخدمون» (phone hint) → the «مستخدم جديد» button (icon +) → «اسم المستخدم» + «كلمة المرور» twice → «الاسم» + «اللقب» (+ «الهاتف») → «الدور» «مدرب» (only a superadmin sees it) → «إنشاء» → the new row; give the coach the username and password. Tip: the person changes the password from «الملف الشخصي» (link `getting-started/profile-password`). Mention: needs users add.
6. `manage-users.md` «كيف أعطّل حسابًا أو أحذفه أو أعيد كلمة مروره» — module `users`; pages `[users.index, users.edit]`; example: the coach ليلى بن صالح left the club — her account is already disabled; the accountant نادية forgot her password. Steps: «المستخدمون» → find with the search or the «النشاط» filter «غير نشط» → «تعطيل» on a row (what it does; re-enable with «تفعيل») → «إعادة تعيين كلمة المرور» on نادية (superadmin only) → type the new password twice → «حفظ» → (delete) «حذف» + confirm. ⚠️ Warning: deleting cannot be undone; prefer «تعطيل», which keeps the person's history. One sentence: an old account waiting for approval shows «قيد الانتظار» and a «موافقة» button. Mention rights (edit; delete; reset = superadmin).

**data-help targets:** `Index.vue`: `users-new` («مستخدم جديد»), `users-search`, `users-filters`, `users-filter-activity`, `users-row-<username>` on each row (`:data-help="'users-row-' + user.username"`), and on the row buttons `users-impersonate-<username>`, `users-edit-<username>`, `users-reset-<username>`, `users-toggle-<username>`, `users-delete-<username>` (same pattern; confirm `IconButton` passes attributes to its `<button>`/`<a>`); `users-reset-modal`, `users-reset-new`, `users-reset-confirm`, `users-reset-save`. `Create.vue`: `uc-username`, `uc-password`, `uc-password-confirm`, `uc-firstname`, `uc-lastname`, `uc-phone`, `uc-role`, `uc-create`. `Edit.vue`: `ue-role`, `ue-overrides-toggle`, `ue-overrides-grid`, `ue-save` (Task 6 uses them; add now while the file is open).

**Shots:**

```js
    { name: 'set-users', url: '/users', targets: ['nav-users', 'users-new', 'users-search', 'users-filters'] },
    {
        // The reader's state before «إنشاء» (never pressed).
        name: 'set-user-create',
        url: '/users/create',
        targets: ['uc-username', 'uc-password', 'uc-password-confirm', 'uc-firstname', 'uc-lastname', 'uc-role', 'uc-create'],
        before: async (page) => { /* mourad, a password twice, مراد / زيتوني, role «مدرب» */ },
    },
    { name: 'set-users-rows', url: '/users', targets: ['users-row-layla', 'users-toggle-layla', 'users-reset-nadia', 'users-delete-layla'] },
    {
        name: 'set-user-reset',
        url: '/users',
        targets: ['users-reset-new', 'users-reset-confirm', 'users-reset-save'],
        before: async (page) => { await page.locator('[data-help="users-reset-nadia"]').click(); /* type a password twice */ },
    },
    {
        name: 'set-user-delete-confirm',
        url: '/users',
        targets: ['confirm-panel', 'confirm-ok'],
        before: async (page) => { await page.locator('[data-help="users-delete-layla"]').click(); },
    },
```

If the create form is taller than the screen, split it into two shots (`set-user-create-login`, `set-user-create-role`) scrolled with `centre()`. Never press «إنشاء» / «حفظ» / «حذف» / «تعطيل». Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/settings/create-user.md resources/help/ar/settings/manage-users.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Users/Index.vue resources/js/Pages/Users/Create.vue resources/js/Pages/Users/Edit.vue
git commit -m "docs(help): create-user and manage-users articles"
```

---

### Task 6: Articles 6 and 8 — roles and rights; log in as + activity (with the script changes)

**Files:**
- Create: `resources/help/ar/settings/roles-rights.md`, `log-in-as-activity.md`
- Modify: `scripts/help-shots.mjs` (per-shot light+dark order; `ownLogin`); `resources/help/README.md` (document `ownLogin` in "Screenshots"); `resources/help/shots.js` (header comment + new shots); `data-help` in `resources/js/Pages/Roles/Index.vue`, `resources/js/Components/PermissionGrid.vue`, `resources/js/Pages/Users/Activity/Index.vue`, `Users/Activity/Show.vue`, `resources/js/Components/Activity/PeriodFilter.vue`, `resources/js/Layouts/AppShell.vue` (impersonation banner)
- Generated: `public/help-shots/ar/...`

**Interfaces:**
- Consumes: `data-help` `ue-role`, `ue-overrides-toggle`, `ue-overrides-grid`, `ue-save`, `users-impersonate-<username>` (Task 5); user ids from the Task 2 report.
- Produces: shot option `ownLogin: true` — the shot gets a fresh browser context that signs in as `demo` itself instead of reusing the shared session.

**Script changes (why):** "Log in as" calls `Auth::login()`, which migrates the session: the cookie every other shot reuses would stop working, and impersonation lives in that shared server session. And the script currently takes all light shots, then all dark ones, on one database: a shot that writes (impersonation records `USER_IMPERSONATED`, the fresh login records `USER_LOGGED_IN`) would make the dark activity shots show different counts from the light ones.

- [ ] **Step 1: Per-shot order** — in `scripts/help-shots.mjs` swap the two loops so each shot is taken light, then dark:

```js
    for (const shot of list) {
        for (const theme of ['light', 'dark']) {
            const context = await browser.newContext({
                viewport,
                locale: browserLocale,
                colorScheme: theme,
                storageState: shot.guest || shot.ownLogin ? undefined : signedIn,
            });
            await context.addInitScript((t) => localStorage.setItem('theme', t), theme);
            if (shot.ownLogin) await signIn(context);
            await capture(context, shot, theme);
            await context.close();
            console.log(`✓ ${theme} ${shot.name}`);
        }
    }
```

and move the existing login steps into a helper used both for the shared state and for `ownLogin`:

```js
async function signIn(context) {
    const page = await context.newPage();
    await page.goto(`${base}/login`);
    await page.fill('#username', 'demo');
    await page.fill('#password', 'demo-help-2026');
    // Enter submits the form (PrimaryButton may not carry type="submit").
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login')),
        page.press('#password', 'Enter'),
    ]);
    await page.goto(`${base}/lang/${locale}`);
    await page.close();
}
```

(`loginContext` becomes `const loginContext = await browser.newContext({ viewport, locale: browserLocale }); await signIn(loginContext); const signedIn = await loginContext.storageState(); await loginContext.close();`.) Update the comment in `shots.js` that says the light and dark passes share one database ("the light and dark passes share one seeded database") to describe the new order, and note `ownLogin`. Check that no existing shot relied on the old order (grep `after:` — only `fin-tx-saved`, which undoes itself). Add `ownLogin` to the README's Screenshots section in one line.

- [ ] **Step 2: Prove the order change is neutral** — `npm run build && npm run help:shots -- login profile fin-tx-saved` passes; `git status public/help-shots` shows only expected byte changes (webp re-encodes may differ slightly; JSON boxes must be identical: `git diff --stat public/help-shots/ar/*.json` empty for these three).

Facts to verify:
- **Roles** (`RoleController`, `Roles/Index.vue`, `PermissionGrid.vue`, `Role::MODULE_GROUPS`, `EnsureUserIsSuperadmin`): page «الأدوار» (superadmin only; the sidebar hides it from others). Cards per role with the «النظام» badge for system roles and «N المستخدمون». Icon buttons «إضافة» (+), «تعديل» (pencil), «حذف» (trash; not on system roles; browser confirm «هل أنت متأكد من رغبتك في الحذف؟»; its users are left with no role). Editor: names English / Français / العربية (at least one), the grid «الوحدة» × «عرض / إضافة / تعديل / حذف» grouped by sidebar sections, «الفرق التي يدرّبها فقط» (+ help text), «حفظ التغييرات» → «تم إنشاء الدور بنجاح.». The superadmin role always has everything; system roles cannot be deleted. **New rights start empty** for custom roles (new modules added by later versions are unticked; system roles get them) — say it in plain words. A user has **one role**. Per-user extra rights: user's edit page → «تجاوزات متقدمة» (superadmin only) → the same grid; ticked boxes are granted on top of the role («الخانات المحددة تُمنح إضافةً إلى الدور.»); there is no "remove a right" in the UI. A superadmin user's page says «للمدير العام صلاحية كاملة دائماً؛ لا أثر للدور.».
- **Log in as + activity** (`ImpersonationController`, `Impersonation`, `AppShell.vue:288-292`, `UserActivityController`, `ActivityPeriod`, `Users/Activity/*.vue`, `PeriodFilter.vue`, `ActivityCharts.vue`): allowed for a superadmin or users edit; refusals (yourself, a superadmin «لا يمكن الدخول بحساب مدير عام.», a disabled account, someone with rights you lack, while already logged in as someone «عد إلى حسابك أولاً.»). Start → dashboard + «أنت الآن داخل بحساب هذا المستخدم.»; amber banner «أنت تتصفح التطبيق بصفة {user} (الحساب الأصلي: {admin}).» with «العودة إلى حسابي» → «تمت العودة إلى حسابك.». Everything done meanwhile is recorded as the user, with «بواسطة {admin}». Activity: «النظام» → «النشاط» (users view); period «هذا الشهر» (default) / «هذا الموسم» / «فترة مخصصة» (من / إلى + «تطبيق»); tiles «المجموع», «أيام النشاط», «أكثر يوم نشاطاً»; charts; table of users by area; a user's page → click a count to list the entries. Each user sees his own under «نشاطي» in the user menu (`profile.activity`). Check which actions show as «تسجيلات الدخول».

**Outlines:**
7. `roles-rights.md` «كيف أنشئ دورًا وأحدد صلاحياته» — module `superadmin`; pages `[roles.index, users.edit]`; example: a role «أمين المخزن» with «عرض / إضافة / تعديل» on the equipment and inventory rows; then the coach سمير also needs to see subscriptions → an extra right on his account. Steps: «النظام» → «الأدوار» (phone hint) → «إضافة» (+) → «العربية»: «أمين المخزن» → tick the boxes in the equipment rows → «حفظ التغييرات» → extra right: «المستخدمون» → سمير's edit button → «تجاوزات متقدمة» → tick «عرض» on «الاشتراكات» → save. Tip: a new custom role starts with no rights at all; new parts of the app added later start unticked too. Mention: give each person one role; use the extra right only for exceptions. (Seven steps max — merge the extra-right steps if needed, or move the extra right into its own short section of 2–3 steps inside the 7.)
8. `log-in-as-activity.md` «كيف أرى التطبيق بعين مستخدم آخر وأتابع نشاطه» — module `users`; pages `[users.index, users.activity.index, users.activity.show]`; example: سمير says he can't find the subscriptions page; the admin logs in as him to check, comes back, then looks at his activity this season. Steps: «المستخدمون» → «الدخول بحساب…» on سمير's row → the amber banner (you now see exactly what he sees) → «العودة إلى حسابي» → «النظام» → «النشاط» → «هذا الموسم» → سمير's row/page → click a count to see the entries. Tip: what you do while logged in as someone is recorded with «بواسطة» + your name. Mention: needs users edit to log in as someone, users view for activity; superadmins cannot be "logged in as".

**data-help targets:** `Roles/Index.vue`: `roles-add`, `roles-card-coach` (by role key), `roles-edit-coach`, `role-modal`, `role-name-ar`, `role-grid` (wrapper), `role-own-teams`, `role-save`. `PermissionGrid.vue`: `perm-row-<module>` on each row (`:data-help="'perm-row-' + module"`) so shots can ring `perm-row-equipment`, `perm-row-subscriptions`. `AppShell.vue`: `impersonate-banner`, `impersonate-leave`. `PeriodFilter.vue`: `activity-period` (wrapper), `activity-period-season` (the season option). `Activity/Index.vue`: `activity-tiles`, `activity-table`, `activity-row-<username>` (or the link to the user's page). `Activity/Show.vue`: `activity-user-tiles`, `activity-count-first` (the first clickable count), `activity-entries`.

**Shots** (still before `fin-tx-saved`; activity shots **before** the log-in-as shots):

```js
    { name: 'set-roles', url: '/roles', targets: ['nav-roles', 'roles-add', 'roles-card-coach'] },
    {
        // The reader's state before «حفظ التغييرات» (never pressed).
        name: 'set-role-form',
        url: '/roles',
        targets: ['role-name-ar', 'perm-row-equipment', 'perm-row-inventory', 'role-save'],
        before: async (page) => { await page.locator('[data-help="roles-add"]').click(); /* type «أمين المخزن», tick view/add/edit on equipment + inventory, centre() the rows */ },
    },
    {
        name: 'set-user-overrides',
        url: '/users/<samir id>/edit',
        targets: ['ue-overrides-toggle', 'perm-row-subscriptions', 'ue-save'],
        before: async (page) => { await page.locator('[data-help="ue-overrides-toggle"]').click(); /* tick «عرض» on subscriptions; centre() */ },
    },
    { name: 'set-activity', url: '/users/activity?period=season', targets: ['nav-activity', 'activity-period', 'activity-tiles', 'activity-table'] },
    {
        name: 'set-activity-user',
        url: '/users/<samir id>/activity?period=season',
        targets: ['activity-user-tiles', 'activity-count-first'],
    },
    { name: 'set-activity-entries', url: '/users/<nadia id>/activity?period=season&action=transaction_recorded', targets: ['activity-entries'] },
    { name: 'set-impersonate-button', url: '/users', targets: ['users-impersonate-samir'] },
    {
        // Own session: «الدخول بحساب» replaces the logged-in user. Leaves through the banner afterwards.
        name: 'set-impersonating',
        url: '/users',
        ownLogin: true,
        targets: ['impersonate-banner', 'impersonate-leave'],
        before: async (page) => {
            await Promise.all([
                page.waitForURL(/\/dashboard/),
                page.locator('[data-help="users-impersonate-samir"]').click(),
            ]);
            await page.waitForLoadState('networkidle');
        },
        after: async (page) => {
            await Promise.all([
                page.waitForURL(/\/users/),
                page.locator('[data-help="impersonate-leave"]').click(),
            ]);
        },
    },
```

Check the real query parameter that opens a user's entries for one action (`Show.vue` / `UserActivityController@show`), and whether «الدخول بحساب» asks a confirmation first (then click it in `before`). Generate these shots plus the earlier 2d ones in one run, view every image: light and dark show the same counts.

- [ ] **Step 3:** data-help; build; shots; view images.
- [ ] **Step 4:** write the 2 articles.
- [ ] **Step 5:** `help:check`, `test --filter=Help`.
- [ ] **Step 6: Commit**

```bash
git add scripts/help-shots.mjs resources/help/README.md resources/help/ar/settings/roles-rights.md resources/help/ar/settings/log-in-as-activity.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Roles/Index.vue resources/js/Components/PermissionGrid.vue resources/js/Pages/Users/Activity/Index.vue resources/js/Pages/Users/Activity/Show.vue resources/js/Components/Activity/PeriodFilter.vue resources/js/Layouts/AppShell.vue
git commit -m "docs(help): roles-rights and log-in-as/activity articles; shots taken light+dark per shot"
```

---

### Task 7: Phase verification

**Files:** none new (fix only what the checks find, in the task's files).

- [ ] **Step 1: Full shot run** — `npm run build && npm run help:shots` (all shots, new order). Every shot passes. `git status public/help-shots` — older images may re-encode; JSON boxes of older shots must be unchanged except where a reason is known (report any changed box with its cause; restore unchanged older images if the run only re-encoded them, as in earlier phases).
- [ ] **Step 2: Checks** — all must pass: `php artisan help:check` (`Help content OK — 39 article(s).`), `php artisan test --filter=Help`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`, and the full suite in the worktree (`php -d memory_limit=1G vendor/bin/phpunit`, no `bootstrap/cache/config.php`).
- [ ] **Step 3: Manual pass** with a throwaway Playwright script (not committed) on the demo DB: the 8 articles light/dark at 1280 and 320 px; `?` panel on `/settings`, `/branches`, `/branches/<id>/categories`, `/document-types`, `/users`, `/users/create`, `/roles`, `/users/activity`; Ctrl+K «مستخدم» finds create-user; visibility: the coach `samir` (no settings rights) sees **no** «الإعدادات والمستخدمون» tile; a user with only users view sees create-user/manage-users/log-in-as-activity but not roles-rights; the demo superadmin sees all 8; Settings colour presets show Arabic names in ar and French names in fr.
- [ ] **Step 4: Commit** any fixes (explicit files), message `fix(help): settings & users final checks`.
