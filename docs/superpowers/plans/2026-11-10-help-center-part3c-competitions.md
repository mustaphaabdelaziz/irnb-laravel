# Help Center Part 3c — Competitions & matches Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the four competitions problems the owner chose (plus the game-formats extension decided while planning), let the screenshot run use a throwaway storage folder with generated opponent logos, then add the `competitions` help topic: 11 Arabic articles with screenshots on a seeded demo team.

**Architecture:** Fixes first (Tasks 1–2), each with feature tests. Task 3 points the whole Laravel storage folder of the screenshot run at `storage/help-demo-storage` (Laravel's `LARAVEL_STORAGE_PATH`), so generated images never touch real media. Task 4 adds `HelpDemoCompetitionsSeeder` (called last from the guarded `HelpDemoSeeder`), the topic and nav targets. Then four article tasks on the same Help Center engine as Parts 1–3b. No Help framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), GD (installed), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-09-help-center-part3-design.md` (phase 3c). References: `resources/help/README.md` (authoring guide), `resources/help/ar/board/*.md` and `resources/help/ar/equipment/*.md` (house style), `docs/superpowers/plans/2026-11-09-help-center-part3b-board.md` (previous phase, same task shapes), `.superpowers/archive/help-3b-sdd/` (implementer/reviewer instructions and the article-review addendum to reuse).

## Owner decisions for 3c

All four spec fixes were chosen (2026-11-09):

1. The «الإعدادات» tab hint of a competition says bans are computed from match reports (drop «في تحديث قادم»).
2. «المنافسون» / «الملاعب والقاعات»: add/edit/delete hidden without the right.
3. Teams «حذف» hidden for a coach limited to own teams.
4. Changing a played match to a status other than «لُعبت» asks for confirmation that the score will be erased.

Decided while planning (AskUserQuestion, 2026-11-10):

- **Demo team = U17 in «كرة القدم 5», no new players.** The demo club has only 4 U17 football players, so the team is those 4 plus U14 players playing up. Older articles and shots keep their players. Substitutions are entered as report events (Football 5 has no rolling substitutions; Futsal would type minutes instead). *Planning note:* رياض حمداني has an open injury in the attendance demo, so a third U14 player (أحمد بن علي) joins the roster; without him the upcoming match would have exactly five fit players and no bench for the line-up article. Still no new player.
- **Fix 2 extends to «أنظمة اللعب»** (game formats): «نظام جديد», pencil and trash follow the `game_formats` rights.

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON; `scripts/i18n-add.mjs` adds keys), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…). `flash.*` keys live in `resources/js/i18n/{ar,fr,en}.json`. Use `t()` directly, never `te()` for `flash.*`.
- Rights (`config/permissions.php` + `app/Support/PermissionMap.php`): last route-name segment `store`/`create` → add, `destroy`/`delete` → delete, `index`/`show`/`export` → view, **anything else → edit**. Modules: `teams`, `competitions`, `games`, `game-formats` → `game_formats`, `opponents`, `venues`. Overrides already there: `competitions.suspensions.store|destroy` → competitions edit; `games.stats`, `games.convocation`, `games.lineup-sheet`, `games.report-sheet` → games view.
- **Coaches:** a role with `own_teams_only` (the seeded «مدرب» role has it) is "scoped" (`TeamAccess::isScoped`): it manages only teams it coaches in that season (`TeamAccess::canManage`), never creates/edits/deletes teams, and views everything. Say so where an article step depends on it.
- Sidebar group «المنافسات والمباريات» (`nav.competitions`): «المباريات» (/games), «الإحصائيات» (/games/stats), «الفرق» (/teams), «المنافسات» (/competitions). «تهيئة التطبيق» holds «أنظمة اللعب», «المنافسون», «الملاعب والقاعات». Check each Arabic label in `resources/js/i18n/ar.json` before quoting it.
- Match statuses: «مبرمجة» (scheduled), «لُعبت» (played), «مؤجلة» (postponed), «ملغاة» (cancelled). The match page tabs (`comp.tab_info|callup|lineup|report`) and the competition page tabs (`squad`, `games`, `record`, `stats`, `suspensions`, `rules` = «الإعدادات») are local state, not URLs: shots click the tab in `before`.
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (filling adjacent fields of one form card is one step), every clicked element ringed (sidebar clicks too), phone hint where a step relies on the sidebar («في الهاتف، افتح القائمة أولًا بزر ☰»), exact labels from `ar.json` in «…», no emoji standing in for app icons, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- **Icon-only buttons** (`IconButton`): describe the icon («زر القلم», «زر سلة المهملات») and quote the hover name the way `resources/help/ar/settings/add-branch.md` does. No colour words for buttons; «الملوّن» only where colour is the point.
- Lessons from earlier phases: walk each article literally against the component's watchers and defaults; take each screenshot in the state the reader is in at that step; a step holds one action; reviewers repeatedly catch two actions in one step and unringed sidebar clicks; messages users cannot see (native confirm) are never screenshot; the Ctrl+K palette shot must wait for help results; a full `help:shots` run flakes once in a while — rerun the failed shot alone before suspecting the branch; older shots change daily (date-relative demo data) → restore them, keep only real changes.
- **Shots must never save anything.** Never press «حفظ» on any tab, «إضافة» on the settings lists, the delete/confirm buttons, or the copy-roster button. Line-up shots place players with the page's controls in `before` and do not save. If a shot must write, it goes last in `shots.js` with an `after` undo (none planned).
- **Real media is never written.** Only the screenshot run (Task 3) writes images, into `storage/help-demo-storage/app/public`. The seeder writes no image unless the public disk is that throwaway folder (unit tests get logo-less opponents).
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first, writes models directly (no `ActivityRecorder`; the users articles count activity entries) plus the services named in Task 4, and dates everything relative to the seed day. Production is PostgreSQL; seeders and sweeps never run there. No migration in this phase.
- Git: stage explicit files only (the main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`, a PDF). Never `git add -A`.
- Worktree: reuse `D:\irnb-help3` (vendor + node_modules copied, playwright installed). Create branch `feat/help-3c` from local `main` (`c089e13` or newer) inside it. If the worktree is gone, recreate it per `superpowers:using-git-worktrees`, copy `vendor/` and `node_modules/` (no junctions), `npm install`, restore `package-lock.json`'s `name` if npm changed it, `npx playwright install chromium`. No media copy needed: the shot run now uses its own storage (Task 3).
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## File map

| File | Responsibility |
|---|---|
| `resources/js/i18n/{ar,fr,en}.json` (modify) | fix 1 text; fix 4 confirm; `help.topic.competitions` |
| `resources/js/Pages/Settings/{Opponents,Venues,GameFormats}.vue` (modify) | fix 2; `data-help` |
| `app/Http/Controllers/TeamController.php`, `resources/js/Pages/Teams/Show.vue` (modify) | fix 3 (`canDeleteTeam`) |
| `resources/js/Pages/Games/Show.vue` (modify) | fix 4 confirm; `data-help` |
| `scripts/help-shots.mjs`, `resources/help/README.md`, `.gitignore` (modify) | throwaway storage + real-media guard |
| `database/seeders/HelpDemoImages.php` (create) | generated neutral logos (GD), stored through `FileStorageService` |
| `database/seeders/HelpDemoSeeder.php` (modify) | `onThrowawayStorage()`; call the new seeder |
| `database/seeders/HelpDemoCompetitionsSeeder.php` (create) | demo opponents, venues, teams, competition, matches |
| `resources/js/Components/Sidebar/SidebarSection.vue` (modify) | nav `data-help` |
| `resources/help/topics.php` (modify) | `competitions` topic |
| `tests/Feature/Competitions/*` (create/modify), `tests/Feature/Help/*` (create) | fix tests, image test, seeder test |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/competitions/*.md` (create) | 11 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: Rules hint, settings lists follow their rights, team «حذف» hidden for scoped coaches

**Files:**
- Modify: `resources/js/i18n/{ar,fr,en}.json` (line ~1160, `comp.suspension_rules_help`)
- Modify: `resources/js/Pages/Settings/Opponents.vue`, `resources/js/Pages/Settings/Venues.vue`, `resources/js/Pages/Settings/GameFormats.vue`
- Modify: `app/Http/Controllers/TeamController.php:131-139` (`show` props), `resources/js/Pages/Teams/Show.vue:27`, `:255`
- Test: `tests/Feature/Competitions/OpponentVenueSettingsTest.php` (extend), `tests/Feature/Competitions/TeamsTest.php` (extend)

**Interfaces:**
- Consumes: `useCan()` from `resources/js/Composables/useCan.js` → `{ can(module, action) }` (superadmin → always true); `CompetitionFixtures` (`admin()`, `coach(array $permissions)`, `team()`, `assignCoach()`).
- Produces: `Teams/Show` prop `canDeleteTeam: bool`.

- [ ] **Step 1: Failing tests**

`TeamsTest`:

```php
    #[Test]
    public function the_delete_button_is_offered_only_to_unscoped_users_with_the_delete_right(): void
    {
        $team = $this->team();
        $season = Season::current()->startYear;
        $coach = $this->coach(['teams' => ['view', 'edit', 'delete']]);
        $this->assignCoach($team, $coach, $season);

        $this->actingAs($coach)->get(route('teams.show', $team))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canDeleteTeam', false));
        $this->actingAs($this->admin())->get(route('teams.show', $team))
            ->assertInertia(fn (Assert $page) => $page->where('canDeleteTeam', true));
        $this->actingAs($coach)->delete(route('teams.destroy', $team))->assertForbidden();
    }
```

`OpponentVenueSettingsTest` (pins the server contract the hidden buttons rely on; add `use App\Models\Role;` and `use App\Models\User;`):

```php
    #[Test]
    public function view_only_users_open_the_lists_but_cannot_change_them(): void
    {
        $user = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create([
            'permissions' => ['opponents' => ['view'], 'venues' => ['view'], 'game_formats' => ['view']],
        ])->id]);

        $this->actingAs($user)->get(route('opponents.index'))->assertOk();
        $this->actingAs($user)->get(route('venues.index'))->assertOk();
        $this->actingAs($user)->get(route('game-formats.index'))->assertOk();
        $this->actingAs($user)->post(route('opponents.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->post(route('venues.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->post(route('game-formats.store'), [])->assertForbidden();
    }
```

- [ ] **Step 2: Run** `php artisan test --filter="TeamsTest|OpponentVenueSettingsTest"` — the team test FAILS (prop missing); the settings test passes already (it pins the contract).

- [ ] **Step 3: Server prop** — `TeamController::show()`, next to `canEditTeam`:

```php
            // A coach limited to own teams never deletes one (destroy() refuses it).
            'canDeleteTeam' => ! TeamAccess::isScoped($request->user()) && $request->user()->hasPermission('teams', 'delete'),
```

`Teams/Show.vue`: add `canDeleteTeam: Boolean` to `defineProps`; line 255 `<SecondaryButton v-if="canDeleteTeam" …>`. Drop the `useCan` import if nothing else uses it.

- [ ] **Step 4: Settings lists** — import `useCan` (`const { can } = useCan();`) and gate:

| Page | Element | Condition |
|---|---|---|
| Opponents | add `<form>` | `can('opponents','add')` |
| Opponents | pencil / trash | `can('opponents','edit')` / `can('opponents','delete')` |
| Venues | add `<form>` / pencil / trash | `can('venues','add'\|'edit'\|'delete')` |
| GameFormats | «نظام جديد» (`openNew`) / pencil / trash | `can('game_formats','add'\|'edit'\|'delete')` |

Keep layout classes; an empty actions cell stays empty (no placeholder). In `GameFormats.vue` the sanction-row trash inside the open form is part of editing: leave it.

- [ ] **Step 5: Rules hint** — text edits only (no re-serialise), key `comp.suspension_rules_help`:
  - ar «تُحسب الإيقافات تلقائيًا من أوراق المباريات حسب هذه القواعد.»
  - fr «Les suspensions sont calculées automatiquement depuis les feuilles de match selon ces règles.»
  - en «Suspensions are worked out automatically from match reports using these rules.»

- [ ] **Step 6: Run** `php artisan test --filter=Competitions`, `npm run i18n:check`, `npm run build` — PASS. In the dev app sign in as a user whose role has only `opponents/venues/game_formats: view`: the three lists open with no add form, pencil or trash; a superadmin sees everything; a scoped coach on a team page sees no «حذف».

- [ ] **Step 7: Commit**

```bash
git add resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/js/Pages/Settings/Opponents.vue resources/js/Pages/Settings/Venues.vue resources/js/Pages/Settings/GameFormats.vue app/Http/Controllers/TeamController.php resources/js/Pages/Teams/Show.vue tests/Feature/Competitions/OpponentVenueSettingsTest.php tests/Feature/Competitions/TeamsTest.php
git commit -m "fix(competitions): rules hint, settings lists follow their rights, no team delete for scoped coaches"
```

---

### Task 2: Leaving «لُعبت» asks before erasing the score

**Files:**
- Modify: `resources/js/Pages/Games/Show.vue` (`save()`, the Info section, modals at the end)
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/Competitions/GamesTest.php` (extend)

**Interfaces:**
- Produces: i18n `comp.score_erase_title`, `comp.score_erase_confirm` (named params `{score}`, `{status}`).

- [ ] **Step 1: Test** (pins the server behaviour the message announces; reuse `GamesTest` helpers):

```php
    #[Test]
    public function a_played_match_moved_to_another_status_loses_its_score(): void
    {
        $game = $this->game($this->team(), ['status' => GameStatus::Played, 'our_score' => 3, 'their_score' => 1]);

        $this->actingAs($this->admin())->put(route('games.update', $game), $this->payload([
            'status' => 'postponed', 'our_score' => 3, 'their_score' => 1,
        ]))->assertSessionHas('success', 'flash.game_updated');

        $this->assertNull($game->fresh()->our_score);
        $this->assertNull($game->fresh()->their_score);
    }
```

Check `payload()` builds a valid update body for an existing game (opponent, side, date); adapt the call if it targets `store`.

- [ ] **Step 2: Run** `php artisan test --filter=GamesTest` — PASS (the server already clears the score; the fix is the warning).

- [ ] **Step 3: Confirm before saving** — `Games/Show.vue`:

```js
const confirmErase = ref(false);
// Leaving «لُعبت» clears the score on the server: say so before saving.
// `saved` is a plain `let` (not reactive), so this is a function, not a computed.
const erasesScore = () => saved.status === 'played' && form.status !== 'played';

function save() {
    if (erasesScore() && !confirmErase.value) {
        confirmErase.value = true;
        return;
    }
    confirmErase.value = false;
    form.transform(/* unchanged */).put(/* unchanged */);
}
```

Add after the delete `ConfirmModal`:

```vue
        <ConfirmModal :show="confirmErase" :title="t('comp.score_erase_title')"
            :message="t('comp.score_erase_confirm', { score: `${game.our_score} – ${game.their_score}`, status: t(`comp.status_${form.status}`) })"
            :confirm-label="t('save')" data-help="game-erase-confirm" @confirm="save" @cancel="confirmErase = false" />
```

Check `ConfirmModal` accepts `title`/`confirm-label` and passes `data-help` through (3b used `confirm-panel`/`confirm-ok` inside it; if attributes don't fall through, rely on those). The score in the message is LTR digits inside Arabic: wrap with `\u2066…\u2069` (isolate) if it renders reversed; check in the browser.

i18n: `comp.score_erase_title` ar «حذف النتيجة؟», fr «Effacer le score ?», en «Erase the score?»; `comp.score_erase_confirm` ar «ستُحذف نتيجة المباراة ({score}) عند حفظها بالحالة «{status}».», fr «Le score du match ({score}) sera effacé en l’enregistrant avec le statut « {status} ».», en «The match score ({score}) will be erased when you save it as “{status}”.».

- [ ] **Step 4: Run** `npm run i18n:check`, `npm run build`. Dev app: open a played match → «الحالة» = «مؤجلة» → «حفظ» → the confirm shows the score; cancel keeps everything; confirm saves (score gone); a scheduled match saves without asking.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Games/Show.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/Competitions/GamesTest.php
git commit -m "fix(games): confirm before a status change erases a played match's score"
```

---

### Task 3: Throwaway storage for the screenshot run, generated logos

**Files:**
- Modify: `scripts/help-shots.mjs` (env block ~line 34, before the `migrate:fresh` call ~line 60)
- Modify: `.gitignore` (next to `/storage/help-demo.sqlite`)
- Modify: `resources/help/README.md` (screenshots section)
- Modify: `database/seeders/HelpDemoSeeder.php` (add `onThrowawayStorage()`)
- Create: `database/seeders/HelpDemoImages.php`
- Test: `tests/Feature/Help/HelpDemoImagesTest.php`

**Interfaces:**
- Consumes: `App\Services\Storage\FileStorageService::storeImage(UploadedFile $file, string $directory, int $maxWidth = 1024): array{path, filename, url}`.
- Produces: `HelpDemoSeeder::onThrowawayStorage(): bool` (true only when the `public` disk root ends in `/help-demo-storage/app/public`); `HelpDemoImages::logo(string $primaryHex, string $secondaryHex, string $shape): ?array{url: string, filename: string}` (`shape` ∈ `shield`, `circle`, `diamond`; returns null without throwaway storage).

**Mechanism (verify in Step 5):** Laravel reads `LARAVEL_STORAGE_PATH` from `$_ENV`/`$_SERVER` in `Application::storagePath()` (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:623`). The script already passes its env to `migrate:fresh --seed` and to `php artisan serve --no-reload` (with `--no-reload` every variable reaches the server process, which is how `DB_DATABASE` works today). So the whole storage folder — public disk, sessions, file cache, compiled views, logs — moves to `storage/help-demo-storage` for the run; `DB_DATABASE` and `APP_CONFIG_CACHE` are absolute paths and stay where they are. If the built-in server does not see the variable (Step 5 fails), fall back to `'root' => env('HELP_DEMO_PUBLIC_ROOT', storage_path('app/public'))` on the `public` disk in `config/filesystems.php`, set only by the script, and say so in the report.

- [ ] **Step 1: Failing test** — `tests/Feature/Help/HelpDemoImagesTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use Database\Seeders\HelpDemoImages;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoImagesTest extends TestCase
{
    #[Test]
    public function no_image_is_written_outside_the_throwaway_storage(): void
    {
        $this->assertFalse(HelpDemoSeeder::onThrowawayStorage());
        $this->assertNull(HelpDemoImages::logo('#1d4ed8', '#facc15', 'shield'));
    }

    #[Test]
    public function a_logo_is_a_png_in_the_throwaway_public_disk(): void
    {
        $root = sys_get_temp_dir().'/'.uniqid('help', true).'/help-demo-storage/app/public';
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.public.root' => $root]);
        Storage::forgetDisk('public');

        try {
            $this->assertTrue(HelpDemoSeeder::onThrowawayStorage());
            $logo = HelpDemoImages::logo('#1d4ed8', '#facc15', 'shield');

            $this->assertNotNull($logo);
            $this->assertStringStartsWith('opponents/', $logo['filename']);
            $this->assertFileExists($root.'/'.$logo['filename']);
            $this->assertSame(256, getimagesize($root.'/'.$logo['filename'])[0]);
        } finally {
            File::deleteDirectory(dirname($root, 3));
        }
    }
}
```

- [ ] **Step 2: Run** `php artisan test --filter=HelpDemoImagesTest` — FAIL (class missing).

- [ ] **Step 3: Guard + generator**

`HelpDemoSeeder`:

```php
    /** True only inside the screenshot run, whose storage is storage/help-demo-storage. */
    public static function onThrowawayStorage(): bool
    {
        $root = str_replace('\\', '/', rtrim((string) config('filesystems.disks.public.root'), '/\\'));

        return str_ends_with($root, '/help-demo-storage/app/public');
    }
```

`database/seeders/HelpDemoImages.php`:

```php
<?php

namespace Database\Seeders;

use App\Services\Storage\FileStorageService;
use Illuminate\Http\UploadedFile;

/**
 * Neutral generated badges for the help screenshots: plain shapes in two colours,
 * no text (GD cannot shape Arabic) and no real club's emblem. Written only to the
 * screenshot run's throwaway storage, through the same service as an upload.
 */
final class HelpDemoImages
{
    /** @return array{url: string, filename: string}|null */
    public static function logo(string $primaryHex, string $secondaryHex, string $shape): ?array
    {
        if (! HelpDemoSeeder::onThrowawayStorage()) {
            return null;
        }

        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $primary = self::colour($img, $primaryHex);
        $secondary = self::colour($img, $secondaryHex);
        $white = imagecolorallocate($img, 255, 255, 255);

        match ($shape) {
            'circle' => self::circle($img, $primary, $secondary, $white),
            'diamond' => self::diamond($img, $primary, $secondary, $white),
            default => self::shield($img, $primary, $secondary, $white),
        };

        $tmp = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($img, $tmp);
        imagedestroy($img);

        try {
            $stored = app(FileStorageService::class)->storeImage(new UploadedFile($tmp, 'logo.png', 'image/png', null, true), 'opponents', 256);
        } finally {
            @unlink($tmp);
        }

        return ['url' => $stored['url'], 'filename' => $stored['filename']];
    }

    private static function colour(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocate($img, $r, $g, $b);
    }

    private static function shield(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        $outline = [40, 30, 216, 30, 216, 130, 128, 230, 40, 130];
        imagefilledpolygon($img, $outline, $primary);
        imagefilledpolygon($img, [128, 30, 216, 30, 216, 130, 128, 230], $secondary);
        imagesetthickness($img, 8);
        imagepolygon($img, $outline, $white);
        imagefilledellipse($img, 128, 110, 70, 70, $white);
    }

    private static function circle(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        imagefilledellipse($img, 128, 128, 220, 220, $primary);
        imagefilledrectangle($img, 18, 108, 238, 148, $secondary);
        imagesetthickness($img, 8);
        imageellipse($img, 128, 128, 220, 220, $white);
        imagefilledpolygon($img, [128, 80, 140, 116, 178, 116, 147, 138, 158, 176, 128, 153, 98, 176, 109, 138, 78, 116, 116, 116], $white);
    }

    private static function diamond(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        $outline = [128, 18, 238, 128, 128, 238, 18, 128];
        imagefilledpolygon($img, $outline, $primary);
        imagefilledpolygon($img, [128, 70, 186, 128, 128, 186, 70, 128], $secondary);
        imagesetthickness($img, 8);
        imagepolygon($img, $outline, $white);
    }
}
```

Check the PHP version's `imagefilledpolygon` signature (PHP 8: points array + colour, no count). Keep the shapes plain and the colours far from any real local club's identity.

- [ ] **Step 4: Script** — `scripts/help-shots.mjs`:

```js
// The run's own Laravel storage: uploads (generated logos), sessions, caches, views
// and logs land here, never in storage/app. Emptied at the start of every run.
const demoStorage = join(root, 'storage', 'help-demo-storage');
rmSync(demoStorage, { recursive: true, force: true });
for (const dir of ['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(join(demoStorage, dir), { recursive: true });
}
```

and `LARAVEL_STORAGE_PATH: demoStorage,` in `env`. Guard real media: before `migrate:fresh`, list every file under `storage/app/public` (recursive `readdirSync(…, { recursive: true })`, sorted) and after the run (in the `finally` that stops the server) list it again; throw `Real storage/app/public changed during the run` if the lists differ. Import `rmSync`, `mkdirSync`, `readdirSync` from `node:fs` (check existing imports). `.gitignore`: `/storage/help-demo-storage/`. README: one paragraph — the run uses `storage/help-demo-storage` (emptied each run), why, and that seeders generate images only there (`HelpDemoSeeder::onThrowawayStorage()`).

- [ ] **Step 5: Verify the mechanism** — temporarily add one line to `HelpDemoSeeder::run()` writing a logo (`HelpDemoImages::logo('#1d4ed8', '#facc15', 'shield')`, dump its url to the console), run `npm run help:shots -- app-dashboard`, then: the file exists under `storage/help-demo-storage/app/public/opponents/`; `curl -s -o NUL -w "%{http_code}" http://127.0.0.1:8765/media/<filename>` during a run returns 200 (or add a `before` that fetches it in a one-off shot); real `storage/app/public` unchanged (the guard did not throw); real `storage/framework/sessions` gained no file. Remove the temporary line. If the variable did not reach the server, apply the fallback above and re-verify.

- [ ] **Step 6: Run** the image test, `php artisan test --filter=Help` — PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/help-shots.mjs .gitignore resources/help/README.md database/seeders/HelpDemoSeeder.php database/seeders/HelpDemoImages.php tests/Feature/Help/HelpDemoImagesTest.php
git commit -m "feat(help): screenshot run uses throwaway storage; generated demo logos"
```

---

### Task 4: `competitions` topic, nav targets and demo team

**Files:**
- Create: `database/seeders/HelpDemoCompetitionsSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoBoardSeeder`)
- Modify: `resources/js/Components/Sidebar/SidebarSection.vue` (the `data-help` map, line ~70)
- Modify: `resources/js/i18n/{ar,fr,en}.json` (`help.topic.competitions`)
- Modify: `resources/help/topics.php` (only if `help:check` accepts listed slugs before the articles exist; else in Task 5, as 3a/3b did)
- Test: `tests/Feature/Help/HelpDemoCompetitionsSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`, `HelpDemoImages::logo()`, `App\Support\Season::current()->startYear`, `App\Services\Competitions\TeamRoster::replace(Team, int $season, list<array{player_id, shirt_number}>)`, `GameMinutes::refresh(Game)`, `SuspensionEngine::recompute(Competition)`, `HelpDemoUsersSeeder::COACH` (`samir`, role «مدرب», `own_teams_only`).
- Produces: topic `competitions` (icon `flag` — there is no `trophy` in `Components/Icon.vue`; the sidebar uses `flag` for this group), nav `data-help`: `nav-games` (/games), `nav-games-stats` (/games/stats), `nav-teams` (/teams), `nav-competitions` (/competitions), `nav-game-formats` (/game-formats), `nav-opponents` (/opponents), `nav-venues` (/venues); public constants for every name the articles quote; team, competition and match ids written into the task report for the shot tasks.

**Demo facts** (`D` = `CarbonImmutable::today()`; `S` = `Season::current()->startYear`; `F(n)` = the Friday `n` weeks from `D`'s week: `F(-1)` is the last Friday before `D`, `F(1)` the first Friday after `D`; matches are on Fridays, so they never touch the U17 football training days (Mon/Wed) or the swimming days of the attendance demo):

Setup:
- Link the football and futsal presets (`game_formats.sport` in `football`, `futsal`, `branch_id` null) and all positions with no branch to the Football branch (the migration ran before the branches existed).
- Opponents: «اتحاد الشباب» (البليدة, logo shield `#1d4ed8`/`#facc15`), «نجم الساحل» (تيبازة, logo circle `#b91c1c`/`#f8fafc`), «أمل المدينة» (المدية, logo diamond `#15803d`/`#f8fafc`), «شباب الحي» (no city, no logo — the article says the logo is optional). Logos via `HelpDemoImages::logo()`; null in unit tests.
- Venues: «ملعب النادي» (address «حي النصر»), «ملعب البلدية», «القاعة متعددة الرياضات». **Check** how `is_home` is used (`Venue` casts, game form defaults); give «ملعب النادي» `is_home = true`.

Team «أقل من 17 سنة» (Football branch, category «أقل من 17 سنة», default format «كرة القدم 5»), season `S` roster through `TeamRoster::replace` (shirt numbers):

| Player (demo id) | Category | Shirt | Notes |
|---|---|---|---|
| عبد الرحمن بلعيد (7) | U17 | 1 | goalkeeper |
| رياض حمداني (4) | U17 | 4 | open injury (attendance demo, 2 newest U17 sessions) |
| أنس لعريبي (21) | U17 | 7 | |
| أمين قاسمي (3) | U17 | 10 | captain |
| ياسين بوزيد (2) | U14, playing up | 8 | |
| زكرياء عمراني (8) | U14, playing up | 11 | |
| أحمد بن علي (1) | U14, playing up | 14 | |

Coaches for `S`: سمير (samir) «head» (`team_coaches` row, role `head`); demo (superadmin) not listed.

Team «أقل من 14 سنة» (Football, category «أقل من 14 سنة», default format «كرة القدم 8»): season `S−1` roster أحمد بن علي 9, ياسين بوزيد 7, زكرياء عمراني 5; season `S` **empty**, so the copy-last-season button shows (`Teams/Show.vue:123`; the article quotes its label from `ar.json`).

Competition «البطولة الولائية لأقل من 17 سنة» (team U17, season `S`, type `league`, format «كرة القدم 5», `red_ban_matches` 1, `yellow_threshold` 3, `yellow_ban_matches` 1); squad = all 7 roster players (`competition_players`).

Matches (all in that competition, format «كرة القدم 5» (2 × 20 min), `rules` filled by `Game::creating`, kickoff 15:00, meeting 14:00 at «مقر النادي»; write `GamePlayer` rows as the call-up/line-up controllers do: called-up rows with `role` `starter`/`bench`, `slot` (formation `2-2`, slots 0–4: GK, DF, DF, ST, ST), `shirt_number`, `is_captain`, `is_goalkeeper`; left-out players with a reason get a row with `called_up = false`; events as `GameEvent`; then `GameMinutes::refresh($game)`; after all matches `SuspensionEngine::recompute($competition)`):

1. **M1** `F(-3)`, home, «ملعب النادي», vs «اتحاد الشباب», round «الجولة 1», played **3–1** (period scores `[[2,1],[1,0]]`). Starters: عبد الرحمن (GK, slot 0), أنس (1), ياسين (2), أمين (3, captain), زكرياء (4); bench رياض, أحمد. Events: goal أمين p1 12' (assist أنس), yellow أنس p1 18', goal أنس p1 25' (assist أمين), goal أمين p2 8' penalty (`subtype` `penalty`), substitution p2 10' زكرياء off → رياض on (check which of `player_id`/`related_player_id` is the player coming on in `ReportValidator`/`MatchReport.vue`). Ratings (1–10): أمين 9 (MVP), أنس 8, others 6–7; one coach note «تمركز جيد في الدفاع» on ياسين.
2. **M2** `F(-2)`, away, «ملعب البلدية», vs «نجم الساحل», «الجولة 2», played **1–1** (`[[1,0],[0,1]]`). Same starters; bench رياض, أحمد. Events: goal ياسين p1 15' (assist أمين), yellow أنس p2 3', red عبد الرحمن p2 12', substitution p2 13' ياسين off → أحمد on. → automatic ban: عبد الرحمن, 1 match, cause red (served in M3).
3. **M3** `F(-1)`, home, «ملعب النادي», vs «أمل المدينة», «الجولة 3», played **2–0** (`[[1,0],[1,0]]`). Not called with reason: عبد الرحمن «موقوف», رياض «مصاب». Starters: زكرياء (GK, slot 0, `is_goalkeeper`), أنس (1), ياسين (2), أمين (3, captain), أحمد (4); no bench. Events: goal أمين p1 9', goal أحمد p2 14' (assist أنس). أنس now has 2 yellows (one short of a ban).
4. **M4** `F(1)`, away, «القاعة متعددة الرياضات», vs «اتحاد الشباب», «الجولة 4», **scheduled**. Call-up saved: 6 called (all but رياض, row with reason «مصاب»); **no line-up saved** (the line-up article places players without saving). Round number and venue are the call-up/line-up/convocation example.
5. **M5** `F(-4)`, away, «ملعب البلدية», vs «شباب الحي», no round label, status **postponed**, no players. The edit/cancel article's example of a postponed match.

So the stats show 3 played (2 W, 1 D), goals for 6, against 2; top scorer أمين قاسمي 3; the suspensions tab shows one served automatic ban.

- [ ] **Step 1: Failing test** — `tests/Feature/Help/HelpDemoCompetitionsSeederTest.php`, same shape as `HelpDemoBoardSeederTest` (`seedOn('2026-11-18 10:00:00')` then `seed(HelpDemoSeeder::class)`). Assert: football presets and positions belong to Football; 4 opponents, 3 venues, logos null in tests; team U17 roster of 7 with the shirt numbers, samir head coach for `S`; team U14 has 3 in `S−1` and none in `S`; competition rules 1/3/1, squad 7; 5 games with the statuses; M1 3–1, M2 1–1, M3 2–0; M1 events count 5 incl. one substitution; one automatic suspension (عبد الرحمن, red, 1 match, `game_id` M2); `GamePlayer` minutes set for M1 starters (refresh ran); M4 called 6 + one reason row; `Player::where('archived', false)->count()` still 22; no `TrainingSession` cancelled by this seeder (compare cancelled count with the attendance seeder's one cancelled session); no `ActivityLog` entry for games. A second method seeds on `2027-09-03 09:00:00` (first days of a season): every game still in season `S` (competition games keep the competition's season), `F(-3)` may fall before the season start — assert the seeder still succeeds and the stats page (`get(route('games.stats'))`) for `S` lists 3 played games. If a date before the season start breaks a page, clamp past matches to the season start + n days and say so.

- [ ] **Step 2: Run** `php artisan test --filter=HelpDemoCompetitionsSeederTest` — FAIL (class missing).

- [ ] **Step 3: Seeder** — `HelpDemoCompetitionsSeeder::run()`: `HelpDemoSeeder::assertThrowawayDatabase();` then the facts above. Call it from `HelpDemoSeeder::run()` after `HelpDemoBoardSeeder`.

- [ ] **Step 4: Run** the seeder test and `php artisan test --filter=Help` — PASS (older seeder tests too: player, user, activity and attendance counts unchanged).

- [ ] **Step 5: Topic and nav** — `topics.php` after `board`:

```php
        'competitions' => ['icon' => 'flag', 'module' => 'games', 'articles' => ['opponents-venues', 'create-team', 'roster-coaches', 'create-competition', 'schedule-match', 'call-up', 'lineup', 'match-report', 'edit-cancel-match', 'suspensions', 'statistics']],
```

i18n `help.topic.competitions` after `help.topic.board`: ar «المنافسات والمباريات», fr «Compétitions et matchs», en «Competitions & matches». `SidebarSection.vue`: add `'/games': 'nav-games', '/games/stats': 'nav-games-stats', '/teams': 'nav-teams', '/competitions': 'nav-competitions', '/game-formats': 'nav-game-formats', '/opponents': 'nav-opponents', '/venues': 'nav-venues'` to the map.

- [ ] **Step 6: Older shots** — the dashboard now has a matches card (`MatchesCard`, needs games view; demo is superadmin). Run `npm run build && npm run help:shots -- app-dashboard app-palette find-your-way-…` (every shot on `/dashboard`: `grep -n "'/dashboard'" resources/help/shots.js`), open them, and check `resources/help/ar/getting-started/find-your-way.md` still describes what the shot shows; adjust a sentence only if it is now wrong. Report the changed shots.

- [ ] **Step 7: Run** `php artisan help:check`, `php artisan test --filter=Help`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 8: Commit**

```bash
git add database/seeders/HelpDemoCompetitionsSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoCompetitionsSeederTest.php resources/js/Components/Sidebar/SidebarSection.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/help/topics.php public/help-shots/ar
git commit -m "feat(help): competitions demo, topic and nav targets"
```

(Stage `public/help-shots/ar` only for the dashboard shots that really changed; restore the rest with `git checkout -- <file>`.)

---

## Article tasks (5–8): common procedure

For each article: (1) read the page component(s) and controller for every step and quote labels from `ar.json`; (2) add `data-help` attributes (attribute only) to what the steps ring — components under `resources/js/Components/Competitions/` (`LineupBoard.vue`, `MatchReport.vue`, `PlayerBadges.vue`) pass them through `$attrs` or a prop (check); (3) add shots to `resources/help/shots.js` (reader's state at that step: `before` clicks the tab, opens forms, types, selects, places players; never saves; `navIntoView(page, '<nav name>')` for sidebar targets); (4) `npm run build && npm run help:shots -- <names>`; (5) open every new light and dark image and check rings land on the right elements, the opponent logos show, and nothing reads as a real club; (6) write the article with the house front matter (`title` «كيف…», `summary`, `module` when not `games`, `pages`, `keywords`, `related`), an `:::example` that follows the demo data, `:::step` cards with `::figure`; (7) `php artisan help:check` + `php artisan test --filter=Help`; (8) commit the articles, shots, `shots.js` and the touched Vue files explicitly.

Name shots `comp-<slug>-<state>`; targets `opp-…`, `venue-…`, `fmt-…` (settings lists), `team-…`, `compn-…` (competition page), `game-…` (match page and list), `lineup-…`, `report-…`, `stats-…`. Say which right a step needs when it is not view, and what a scoped coach can do.

### Task 5: Articles — `opponents-venues`, `create-team`, `roster-coaches`

**Files:** Create `resources/help/ar/competitions/{opponents-venues,create-team,roster-coaches}.md`; modify `shots.js`, `Settings/Opponents.vue`, `Settings/Venues.vue`, `Teams/Index.vue`, `Teams/Show.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `opponents-venues` — module `opponents`, pages `[opponents.index, venues.index]`. Example: add the opponent «وفاق الجبل» (city «البويرة») with a logo, then the venue «ملعب الحي الجديد». Steps: open «المنافسون» (nav ring, under «تهيئة التطبيق») → «الاسم», «المدينة», «الشعار» (an image, up to 2 MB, optional; the list shows it next to the name and on match pages) → «إضافة» (needs add) → pencil to rename or change the logo (needs edit) → open «الملاعب والقاعات» (nav ring; needs the venues right — say so) → «الاسم», «العنوان», the home-ground box (`comp.home_ground`; check what it changes) → «إضافة». Facts: an opponent or venue with matches can't be deleted (quote `flash.opponent_in_use` and the venues equivalent); the «المباريات» column counts them. Shot: list with the three demo logos, form filled (file input shows a chosen name? Native file inputs read French text in the shots — `--lang=fr-DZ`; don't ring the file input's text, ring the field).
2. `create-team` — module `teams`, pages `[teams.index, teams.show]`. Example: create «أقل من 12 سنة» in «كرة القدم» with category «أقل من 12 سنة», format «كرة القدم 5». Steps: open «الفرق» (nav ring) → «فريق جديد» (needs add; not for coaches limited to their teams) → «الفرع» (team sports; the format list follows the sport), «الفئة», «الاسم», default format (check the label) → save (opens the team page) → the team page tabs/cards and the season selector. Fact: a team is permanent; one with competitions or matches is archived, not deleted (`comp.delete_team_confirm`); «أنظمة اللعب» holds the formats (one sentence, link the setting by name). Shot of the filled form, not saved.
3. `roster-coaches` — module `teams`, pages `[teams.show]`. Example: the U14 team starts season `S` from last season's roster; then the U17 roster and its coach سمير. Steps: open «أقل من 14 سنة» → the copy-last-season button (only when this season's roster is empty and last season had players; check the label and what it skips: archived, left the club or the branch — `TeamRoster::copyFromPreviousSeason`) → open «أقل من 17 سنة» → add a player (candidates; players of other categories are allowed — playing up; the category shows beside the name) → shirt numbers (unique per team and season) → save → coaches card: user + «مدرب رئيسي»/«مساعد» (check labels), admin-only (`canEditTeam`): a coach limited to own teams edits the roster of teams they coach but not the coaches. Badges on roster rows (injured, left the branch…): quote what shows for رياض. Shots: copy button ringed (not clicked), roster with numbers, coaches card.

### Task 6: Articles — `create-competition`, `schedule-match`, `call-up`

**Files:** Create `resources/help/ar/competitions/{create-competition,schedule-match,call-up}.md`; modify `shots.js`, `Competitions/Index.vue`, `Competitions/Show.vue`, `Games/Index.vue`, `Games/Show.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `create-competition` — module `competitions`, pages `[competitions.index, competitions.show]`. Example: create «كأس الولاية لأقل من 17 سنة» (type cup) for the U17 team, then its squad. Steps: open «المنافسات» (nav ring) → «منافسة جديدة» (needs add) → team, «النوع» (league/cup/tournament/friendly — quote the four labels), format, season → save → «squad» tab: tick roster players (squad ⊂ roster; leaving the roster leaves the squad) → save the squad (needs edit or managing the team) → the «الإعدادات» tab (rules; details in `suspensions`). Shot of the filled form, the squad tab of the demo competition.
2. `schedule-match` — pages `[games.index, games.show]`. Example: a friendly «أقل من 17 سنة» vs «نجم الساحل» on next Wednesday 18:00 at «ملعب النادي» — a U17 training day, so «إلغاء حصة تدريب الفريق في ذلك اليوم» is ticked by default and would cancel that session. Steps: open «المباريات» (nav ring) → «مباراة جديدة» (needs add; teams you may manage) → team, competition (empty = friendly), opponent, side («داخل الديار»/«خارج الديار»/«أرض محايدة» — check `comp.side_*`), date, kickoff, venue, meeting time/place, round → the training box (ticked; untick to keep the session; the session is cancelled in «الحضور والغياب» with the match as reason — check `TrainingCanceller` for the reason text) → save (opens the match page, `flash.game_created_training_cancelled` vs `flash.game_created`). Mention the list's calendar/month view if `Games/Index.vue` has one (check). Shot of the filled form with the box ringed, not saved.
3. `call-up` — pages `[games.show]`. Example: M4 vs «اتحاد الشباب». Steps: open M4 from «المباريات» → «الاستدعاء» tab → tick players (competition match: the squad; friendly: the roster); the counter «n / max» (Football 5: 10) turns red over the limit and saving is refused (`comp.error.too_many_called`) → badges: quote the ones the demo shows (رياض «مصاب»; licence/documents/unpaid badges as they appear — check `PlayerBadges.vue` texts; the unpaid amount only with finance view) — warnings only, never block → «سبب عدم الاستدعاء» for a left-out player (kept only with a reason) → save (needs managing the team) → «الاستدعاء PDF» link (check label `comp.convocation_pdf`). Optional figure: M3's call-up shows عبد الرحمن with the suspension badge (check that past matches show it; if not, describe it in text).

### Task 7: Articles — `lineup`, `match-report`, `edit-cancel-match`

**Files:** Create `resources/help/ar/competitions/{lineup,match-report,edit-cancel-match}.md`; modify `shots.js`, `Games/Show.vue`, `Components/Competitions/LineupBoard.vue`, `Components/Competitions/MatchReport.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `lineup` — pages `[games.show]`. Example: M4, formation «2-2»; عبد الرحمن is back from his ban: عبد الرحمن goalkeeper, أنس and ياسين in defence, أمين (captain) and أحمد up front, زكرياء on the bench. Steps: «التشكيلة» tab (needs a saved call-up; otherwise the «الاستدعاء» link) → formation select (shapes of the format; without one the automatic shape) → place players: drag onto a spot, or the non-drag fallback (read `LineupBoard.vue`; the shot uses the fallback in `before`) → captain and goalkeeper marks → bench = every called-up player not placed; too few starters is a warning, a spot or number used twice is refused → save → «ورقة التشكيلة» PDF (check label). Shots place players in `before`; never save.
2. `match-report` — pages `[games.show]`. Example: M1 3–1 vs «اتحاد الشباب» (already saved: shots show the saved report; the article walks it as if entering it). Steps: «تقرير المباراة» tab → score per half (the total follows) → timeline: add a goal (scorer, assist, kind: normal/penalty/free kick/opponent own goal), a card, a substitution (minute required for substitutions and send-offs) → per-player grid: ratings and coach notes are staff-only and never printed (check what «staff-only» means in `MatchReport.vue`/`MatchPage` — which right sees them), MVP → save: the match becomes «لُعبت», minutes are worked out, bans recomputed → «تقرير المباراة PDF» (check label; shown when played). Quote validation messages a reader may meet (`comp.error.score_required`, and any `ReportValidator` key that fits the example).
3. `edit-cancel-match` — pages `[games.show, games.index]`. Example: M5 vs «شباب الحي» was postponed; it is now cancelled; and M1's status changed by mistake shows the new warning (Task 2). Steps: open M5 → «المعلومات» tab: «الحالة» «مؤجلة» → «ملغاة», save (needs managing the team) → change date/kickoff for a new date instead (status back to «مبرمجة») → a played match: picking another status asks first (shot of the Task 2 confirm on M1, not confirmed; the score is erased) → «حذف» (needs delete; shot of the confirm, not confirmed). Warning: delete erases the call-up, line-up and report; bans are recomputed. Check `games.destroy` cascades (`game_players`, `game_events` FKs) before writing that sentence.

### Task 8: Articles — `suspensions`, `statistics`

**Files:** Create `resources/help/ar/competitions/{suspensions,statistics}.md`; modify `shots.js`, `Competitions/Show.vue`, `Games/Stats.vue`, the player stats page component (`games.players.show` → check its Vue page/partial) (`data-help` only); `public/help-shots/ar/**`.

1. `suspensions` — module `competitions`, pages `[competitions.show]`. Example: the league's rules (red = 1 match; 3 yellows = 1 match); عبد الرحمن's red card in M2 banned him for M3; أنس has 2 yellows. Steps: open the competition → «الإعدادات» tab: the three rule fields and the new hint (Task 1) → save (needs edit) → «الإيقافات» tab: automatic bans from match reports (served/remaining — quote the columns) → manual ban: player, kind (matches/dates), count or dates, reason → add (needs competitions edit; shot of the filled form, not saved) → delete a manual ban (automatic ones are never touched by hand). Facts from the owner rules (memory `competitions-matches`): unserved bans carry over to the same team's next official matches in later seasons; several bans are served one after another; a manual matches ban counts from the first match after its start date; date bans inclusive. Verify each in `SuspensionStatus`/`SuspensionEngine` before writing it.
2. `statistics` — pages `[games.stats, teams.show, competitions.show]`. Example: season `S`, team U17: 3 played, 2 W 1 D, 6–2; top scorer أمين قاسمي (3). Steps: open «الإحصائيات» (nav ring) → filters (season, team, competition — check) → the tables (appearances = called up and played in a played match; quote column labels) → export (check format and that ratings are never exported) → the team page and competition page stats tabs (one step each or one step with two figures — keep one action per step) → a player's card (`games.players.show`, from a name link; also on the player page section «المباريات»). Mention that stats need the games view right.

---

### Task 9: Phase checks and merge

- [ ] **Step 1:** In the worktree: `php artisan help:check` (67 articles), `php artisan test --filter=Help`, full suite `php -d memory_limit=1G vendor/bin/phpunit`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`.
- [ ] **Step 2:** Full `npm run help:shots` run (real-media guard silent; `storage/help-demo-storage` populated, real `storage/app/public` unchanged); `git diff --stat public/help-shots`: only the dashboard shots (Task 4) and the new ones may change. Restore older images that changed only by date churn; investigate any other real change (player pages of players 1–4 and 8 have a matches section at the bottom — check it is out of frame).
- [ ] **Step 3:** Manual Playwright pass on the demo DB: the 11 articles light/dark at 1280 and 320 px; the `?` panel on «المباريات», a match, «الفرق», a team, «المنافسات», a competition, «الإحصائيات», «المنافسون», «الملاعب والقاعات»; Ctrl+K «مباراة» and «استدعاء»; a user with only `opponents/venues/game_formats: view` sees the three lists without action buttons; samir (scoped coach) sees no team «حذف»; the score-erase confirm on a played match.
- [ ] **Step 4:** Whole-phase review (spec 3c section + this plan), fix findings, collect the owner nits found while documenting into one AskUserQuestion round (the owner chose every fix so far; fix the chosen ones before merging), then merge `main` into the branch, rerun Step 1, merge into local `main` (`push-only-verified-tip`: nothing is pushed without the owner's word).
- [ ] **Step 5:** Update `HANDOFF.md` (untracked) and the `help-center-part3` memory: what merged, owner nits, deploy notes (no migration; online `npm ci && npm run build`).
