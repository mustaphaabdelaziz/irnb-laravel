# Help Center Part 2b — Attendance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `attendance` help topic (7 Arabic articles with screenshots) and the demo attendance data the screenshots need.

**Architecture:** Same Help Center engine as Parts 1 and 2a (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue). A new `HelpDemoAttendanceSeeder`, called from the guarded `HelpDemoSeeder`, builds timetables, sessions and marks through the app's own code (`TrainingSchedule`, `ClubClosure`, `SessionGenerator::forRange`, `MarkRecorder::save`). All dates are relative to the day the seeder runs, so screenshots always show recent sessions. No framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-08-help-center-part2-design.md` (phase 2b). References: `resources/help/README.md` (authoring guide), `resources/help/ar/players/*.md` and `resources/help/ar/subscriptions/*.md` (house style), `docs/superpowers/plans/2026-11-08-help-center-part2a-subscriptions.md` (previous phase, same task shapes).

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…).
- Visibility: article `module:` else topic module; topic `attendance` → module `attendance`. Every attendance route uses the single module `attendance`: view = calendar, session page, grid, stats, ranking, alerts, injuries; add = new session; **edit** = saving marks, grid save, cancel/erase/delete a session, and the whole «إعدادات الحضور» page (timetable, closures, targets, codes, rules, alerts, custom codes, letter). Articles say which right a step needs when it is not view.
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (outcomes, not extra clicks), every clicked element ringed, phone hint where a step relies on gestures/sidebar/shortcuts («في الهاتف، افتح القائمة أولًا بزر ☰», «في الهاتف، اسحب الشاشة بإصبعك إلى الأعلى»), touchpad alongside mouse wheel, exact labels from `resources/js/i18n/ar.json` in «…», no emoji standing in for app icons, tooltip-only text not quoted as visible, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- Lessons from 2a: walk each article literally against the component's watchers and defaults (e.g. choosing «غائب بعذر» presets the reason to «أخرى»); take each screenshot in the state the reader is in at that step.
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first.
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`). Never `git add -A`.
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.
- The demo club's main branch is Swimming, so attendance pages open on Swimming: every football shot URL passes `branch_id`.

## Demo facts (produced by Task 2, quoted by the articles)

Fresh demo DB ids (asserted by the seeder test, used as literals in `shots.js`):

| What | Value |
|---|---|
| Branches | Swimming = 1 (main), Football = 2 |
| Categories | Football «أقل من 17 سنة» = 7; Swimming «أصاغر» = 11 |
| Football U17 players | أمين قاسمي (3), رياض حمداني (4), عبد الرحمن بلعيد (7), أنس لعريبي (21) |
| Swimming «أصاغر» players | سارة بن يوسف (12), إيناس حداد (13), يوسف بوشامة (17), لينا قادري (18), شيماء مزياني (22) |
| Timetable | U17: Monday + Wednesday 18:00–19:30; «أصاغر»: Tuesday + Thursday 17:00–18:30; both valid from 21 days before the seed day |
| Session 1 | next U17 regular session (today or later), planned — the one the reader marks |
| Session 2 | held pre-season session, last Saturday before today, 09:00–10:30, title «جري 7.2 كم في 40 دقيقة» |
| Session 3 | next «أصاغر» regular session, cancelled: «المسبح مغلق للصيانة» |
| Held U17 marks (newest first over all held U17 sessions: 6 regular + session 2) | عبد الرحمن «غائب بدون عذر» in the 3 newest (streak 3 → alert); رياض «حاضر دون تدريب» reason «إصابة» in the 2 newest (open injury); أمين «متأخر» 15 min in the 2nd newest; أنس «غائب بعذر» reason «دراسة» in the 5th newest; everyone else «حاضر» |
| Held «أصاغر» marks (6 regular) | سارة «غادر مبكراً» 10 min in the newest; إيناس «متأخر» 5 min in the 3rd newest; everyone else «حاضر» |
| Closure | 20 → 22 days after the seed day, «عطلة مدرسية» |
| Pre-season target | U17, current season, 12 sessions (chip shows 1/12) |

Caveat (like 2a's season name): if the seed day is in the first three weeks of September, part of the 21-day window falls in the previous season; season views would show fewer sessions. Regenerate shots outside that window.

## File map

| File | Responsibility |
|---|---|
| `resources/help/topics.php` (modify) | add `attendance` topic |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | `help.topic.attendance` |
| `database/seeders/HelpDemoAttendanceSeeder.php` (create) | timetables, closure, sessions, marks, target |
| `database/seeders/HelpDemoSeeder.php` (modify) | call the new seeder last |
| `tests/Feature/Help/HelpDemoAttendanceSeederTest.php` (create) | pins the demo facts above |
| `resources/js/Pages/Attendance/*.vue`, `Partials/*.vue`, `Pages/Players/Partials/InjuryList.vue` (modify) | `data-help` attributes only |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/attendance/*.md` (create) | 7 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: `attendance` topic

**Files:**
- Modify: `resources/help/topics.php`
- Modify: `resources/js/i18n/ar.json`, `fr.json`, `en.json`
- Test: `tests/Feature/Help/HelpLibraryTest.php` (existing; no new test — the topic is data)

**Interfaces:**
- Produces: topic `attendance` (icon `calendar`, module `attendance`, article order `take-attendance, add-session, month-grid, cancel-delete-session, timetable, codes-rules, follow-up`); i18n key `help.topic.attendance`.

- [ ] **Step 1: Add the topic** — in `resources/help/topics.php`, after the `subscriptions` line:

```php
        'attendance' => ['icon' => 'calendar', 'module' => 'attendance', 'articles' => ['take-attendance', 'add-session', 'month-grid', 'cancel-delete-session', 'timetable', 'codes-rules', 'follow-up']],
```

Check that `calendar` is a name the help topic tiles render (grep the icon component used by `resources/js/Pages/Help/Index.vue`); if it is not, use the icon name the sidebar uses for «الحضور» (`resources/js/Composables/useNavigation.js:28`) and record the change.

- [ ] **Step 2: i18n** — insert right after `help.topic.subscriptions` in each file (text edit):

| key | ar | fr | en |
|---|---|---|---|
| `help.topic.attendance` | الحضور والغياب | Présences | Attendance |

- [ ] **Step 3: Run** `php artisan test --filter=Help` (all pass — `help:check` ignores topics with no articles yet) and `npm run i18n:check`.

- [ ] **Step 4: Commit**

```bash
git add resources/help/topics.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(help): attendance topic"
```

---

### Task 2: Demo attendance seeder

**Files:**
- Create: `database/seeders/HelpDemoAttendanceSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoSubscriptionsSeeder`)
- Test: `tests/Feature/Help/HelpDemoAttendanceSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`, `HelpDemoSeeder::USERNAME`; models `TrainingSchedule` (fillable `branch_id, category_id, weekday (1=Mon..7=Sun), start_time, end_time, valid_from, valid_to`), `ClubClosure` (`start_date, end_date, reason`), `TrainingSession` (`branch_id, category_id, schedule_id, date, start_time, end_time, kind, state, cancel_reason, title`), `PreseasonTarget` (`branch_id, category_id, season_start_year, target_count`); enums `App\Enums\SessionKind` (`Regular`, `Preseason`, `Extra`), `App\Enums\SessionState` (`Planned`, `Held`, `Cancelled`); `SessionGenerator::forRange(string $from, string $to): void`; `MarkRecorder::save(TrainingSession $session, array $marks, ?User $user, ?array $log = null): int` (marks = full roster; each `['player_id', 'status', 'minutes'?, 'reason'?]`); `App\Support\Season::current()->start()->year`.
- Produces: constants on `HelpDemoAttendanceSeeder`: `FOOT_CATEGORY = 'أقل من 17 سنة'`, `SWIM_CATEGORY = 'أصاغر'`, `CLOSURE_REASON = 'عطلة مدرسية'`, `CANCEL_REASON = 'المسبح مغلق للصيانة'`, `PRESEASON_TITLE = 'جري 7.2 كم في 40 دقيقة'`, `PRESEASON_TARGET = 12`; the demo facts table above.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Help/HelpDemoAttendanceSeederTest.php`:

```php
<?php

namespace Tests\Feature\Help;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSession;
use Database\Seeders\HelpDemoAttendanceSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoAttendanceSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Status keys of one player's marks over held sessions of a category, newest first. */
    private function marks(int $playerId, int $categoryId): array
    {
        return Attendance::query()
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('attendances.player_id', $playerId)
            ->where('training_sessions.category_id', $categoryId)
            ->where('training_sessions.state', SessionState::Held->value)
            ->orderByDesc('training_sessions.date')
            ->pluck('attendances.status')
            ->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : (string) $s)
            ->all();
    }

    #[Test]
    public function the_demo_club_has_timetables_sessions_and_marks(): void
    {
        // A Wednesday in mid-season: U17 trains today, so session 1 is today.
        $this->travelTo('2026-11-18 10:00:00');
        $this->seed(HelpDemoSeeder::class);

        // Ids the screenshots use as literals.
        $this->assertSame(2, Branch::where('name', 'Football')->value('id'));
        $this->assertSame(7, Category::where('name', Demo::FOOT_CATEGORY)->value('id'));
        $this->assertSame(11, Category::where('name', Demo::SWIM_CATEGORY)->value('id'));
        $this->assertSame(['أمين', 'رياض', 'عبد الرحمن', 'أنس'], Player::whereIn('id', [3, 4, 7, 21])->orderBy('id')->pluck('firstname')->all());

        $toMark = TrainingSession::findOrFail(1);
        $this->assertSame(SessionState::Planned, $toMark->state);
        $this->assertSame(SessionKind::Regular, $toMark->kind);
        $this->assertSame(7, (int) $toMark->category_id);
        $this->assertSame('2026-11-18', $toMark->date);

        $preseason = TrainingSession::findOrFail(2);
        $this->assertSame(SessionKind::Preseason, $preseason->kind);
        $this->assertSame(SessionState::Held, $preseason->state);
        $this->assertSame('2026-11-14', $preseason->date);
        $this->assertSame(Demo::PRESEASON_TITLE, $preseason->title);

        $cancelled = TrainingSession::findOrFail(3);
        $this->assertSame(SessionState::Cancelled, $cancelled->state);
        $this->assertSame(Demo::CANCEL_REASON, $cancelled->cancel_reason);
        $this->assertSame('2026-11-19', $cancelled->date);

        // Three weeks of timetable before today: 3 Mondays + 3 Wednesdays held for U17.
        $this->assertSame(6, TrainingSession::where('category_id', 7)->where('kind', SessionKind::Regular->value)->where('state', SessionState::Held->value)->count());
        $this->assertSame(6, TrainingSession::where('category_id', 11)->where('state', SessionState::Held->value)->count());
        $this->assertSame(0, TrainingSession::where('date', '<', '2026-10-28')->count());

        // Marks the follow-up article quotes (7 held U17 sessions, newest first).
        $this->assertSame(['absent_unexcused', 'absent_unexcused', 'absent_unexcused', 'present', 'present', 'present', 'present'], $this->marks(7, 7));
        $this->assertSame(['not_training', 'not_training'], array_slice($this->marks(4, 7), 0, 2));
        $this->assertSame('late', $this->marks(3, 7)[1]);
        $this->assertSame('absent_excused', $this->marks(21, 7)[4]);
        $this->assertSame(15, (int) Attendance::where('player_id', 3)->where('status', 'late')->value('minutes'));

        $this->assertSame(Demo::CLOSURE_REASON, ClubClosure::sole()->reason);
        $this->assertSame('2026-12-08', ClubClosure::sole()->start_date);
        $this->assertSame(Demo::PRESEASON_TARGET, (int) PreseasonTarget::sole()->target_count);
    }
}
```

Check before running and adapt the test to the code, not the other way round: whether `TrainingSession::date` / `ClubClosure::start_date` come back as `'Y-m-d'` strings (the map says no date casts); whether `Attendance::status` is a string (the map says plain string — then the `BackedEnum` branch is dead and may be dropped); whether `Season::current()` for 2026-11-18 is the 2026/2027 season.

- [ ] **Step 2: Run — expect FAIL** (`Class "Database\Seeders\HelpDemoAttendanceSeeder" not found`).

Run: `php artisan test --filter=HelpDemoAttendanceSeederTest`

- [ ] **Step 3: Implement** `database/seeders/HelpDemoAttendanceSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ClubClosure;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\MarkRecorder;
use App\Services\Attendance\SessionGenerator;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Attendance for the Help Center demo club, relative to the day it runs: three
 * weeks of held sessions, the next sessions planned. Called from HelpDemoSeeder.
 * Sessions the articles open by id are created first (ids 1–3 on the fresh DB).
 */
class HelpDemoAttendanceSeeder extends Seeder
{
    public const FOOT_CATEGORY = 'أقل من 17 سنة';

    public const SWIM_CATEGORY = 'أصاغر';

    public const CLOSURE_REASON = 'عطلة مدرسية';

    public const CANCEL_REASON = 'المسبح مغلق للصيانة';

    public const PRESEASON_TITLE = 'جري 7.2 كم في 40 دقيقة';

    public const PRESEASON_TARGET = 12;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $football = Branch::where('name', 'Football')->firstOrFail();
        $swim = Branch::where('name', 'Swimming')->firstOrFail();
        $u17 = Category::where('branch_id', $football->id)->where('name', self::FOOT_CATEGORY)->firstOrFail();
        $cadets = Category::where('branch_id', $swim->id)->where('name', self::SWIM_CATEGORY)->firstOrFail();

        $today = CarbonImmutable::today();
        $from = $today->subDays(21);

        // Weekly timetable from three weeks ago: U17 Mon + Wed, «أصاغر» Tue + Thu.
        $u17Slots = $this->schedules($football, $u17, [1, 3], '18:00', '19:30', $from);
        $swimSlots = $this->schedules($swim, $cadets, [2, 4], '17:00', '18:30', $from);

        // A closure three weeks ahead, before generation, so no session lands in it.
        ClubClosure::create([
            'start_date' => $today->addDays(20)->toDateString(),
            'end_date' => $today->addDays(22)->toDateString(),
            'reason' => self::CLOSURE_REASON,
        ]);

        // 1 — the next U17 session, left planned for the take-attendance article.
        $this->regular($football, $u17, $u17Slots, $today, '18:00', '19:30');

        // 2 — a pre-season session last Saturday (held below with the regular ones).
        TrainingSession::create([
            'branch_id' => $football->id,
            'category_id' => $u17->id,
            'date' => $today->previous(CarbonInterface::SATURDAY)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:30',
            'kind' => SessionKind::Preseason,
            'state' => SessionState::Planned,
            'title' => self::PRESEASON_TITLE,
        ]);

        // 3 — the next «أصاغر» session, cancelled.
        $this->regular($swim, $cadets, $swimSlots, $today, '17:00', '18:30')
            ->update(['state' => SessionState::Cancelled, 'cancel_reason' => self::CANCEL_REASON]);

        // Every other timetable session, as opening the calendar would generate them.
        app(SessionGenerator::class)->forRange($from->toDateString(), $today->addDays(35)->toDateString());

        PreseasonTarget::create([
            'branch_id' => $football->id,
            'category_id' => $u17->id,
            'season_start_year' => Season::current()->start()->year,
            'target_count' => self::PRESEASON_TARGET,
        ]);

        $players = Player::orderBy('id')->get()->values();
        $recorder = app(MarkRecorder::class);
        $log = ['coach' => 'كريم المدرب', 'title' => null, 'notes' => null];

        // U17, newest first: a 3-session unexcused streak, an open injury, one late, one excused.
        [$amine, $riad, $abderrahmane, $anes] = [$players[2], $players[3], $players[6], $players[20]];
        foreach ($this->past($u17, $today) as $rank => $session) {
            $recorder->save($session, [
                ['player_id' => $amine->id, 'status' => $rank === 1 ? 'late' : 'present', 'minutes' => $rank === 1 ? 15 : null],
                ['player_id' => $riad->id] + ($rank < 2 ? ['status' => 'not_training', 'reason' => 'injury'] : ['status' => 'present']),
                ['player_id' => $abderrahmane->id, 'status' => $rank < 3 ? 'absent_unexcused' : 'present'],
                ['player_id' => $anes->id] + ($rank === 4 ? ['status' => 'absent_excused', 'reason' => 'school'] : ['status' => 'present']),
            ], $demo, $log);
        }

        // «أصاغر»: one left early, one late, the rest present.
        [$sara, $inas] = [$players[11], $players[12]];
        $swimmers = [$players[11], $players[12], $players[16], $players[17], $players[21]];
        foreach ($this->past($cadets, $today) as $rank => $session) {
            $recorder->save($session, array_map(fn (Player $p) => match (true) {
                $p->is($sara) && $rank === 0 => ['player_id' => $p->id, 'status' => 'left_early', 'minutes' => 10],
                $p->is($inas) && $rank === 2 => ['player_id' => $p->id, 'status' => 'late', 'minutes' => 5],
                default => ['player_id' => $p->id, 'status' => 'present'],
            }, $swimmers), $demo, $log);
        }
    }

    /** @return array<int, TrainingSchedule> keyed by ISO weekday */
    private function schedules(Branch $branch, Category $category, array $weekdays, string $start, string $end, CarbonImmutable $from): array
    {
        $slots = [];
        foreach ($weekdays as $weekday) {
            $slots[$weekday] = TrainingSchedule::create([
                'branch_id' => $branch->id,
                'category_id' => $category->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'valid_from' => $from->toDateString(),
                'valid_to' => null,
            ]);
        }

        return $slots;
    }

    /** The category's first timetable session on or after $day, created by hand so its id is known. */
    private function regular(Branch $branch, Category $category, array $slots, CarbonImmutable $day, string $start, string $end): TrainingSession
    {
        while (! isset($slots[$day->dayOfWeekIso])) {
            $day = $day->addDay();
        }

        return TrainingSession::create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'schedule_id' => $slots[$day->dayOfWeekIso]->id,
            'date' => $day->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'kind' => SessionKind::Regular,
            'state' => SessionState::Planned,
        ]);
    }

    /** @return Collection<int, TrainingSession> the category's sessions before today, newest first */
    private function past(Category $category, CarbonImmutable $today): Collection
    {
        return TrainingSession::where('category_id', $category->id)
            ->where('date', '<', $today->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->get()
            ->values();
    }
}
```

Verify against the code and adapt (record each adaptation in the report):
- `MarkRecorder::save` takes `minutes => null` for statuses without minutes (it clears them anyway) and accepts `reason` as the enum value string (`'injury'`, `'school'`).
- The generator honours `valid_from` (no sessions before `$from`) and skips slots already taken by sessions 1 and 3 (unique `(branch_key, category_id, date, start_time)`); if `forRange` throws on the duplicate instead, generate first and then look the two sessions up by slot — but then sessions 1–3 lose their fixed ids, so keep this order and fix the conflict another way.
- `Season::current()->start()->year` is the season's start year (2026 for 2026-11-18).
- `RegistrationDisplay::values($player, $branchId)` gives رياض (football + swimming) his football category on U17 marks.

Then in `HelpDemoSeeder::run()`, after `$this->call(HelpDemoSubscriptionsSeeder::class);`, add `$this->call(HelpDemoAttendanceSeeder::class);`.

- [ ] **Step 4: Run** `php artisan test --filter="HelpDemoAttendanceSeederTest|HelpDemoSeederTest|HelpDemoSubscriptionsSeederTest"` — all pass. `vendor/bin/pint database/seeders/HelpDemoAttendanceSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoAttendanceSeederTest.php`.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/HelpDemoAttendanceSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoAttendanceSeederTest.php
git commit -m "feat(help): demo attendance timetables, sessions and marks"
```

---

### Task 3: Articles 1–2 — taking attendance and adding a session

**Files:**
- Create: `resources/help/ar/attendance/take-attendance.md`, `add-session.md`
- Modify: `data-help` attributes in `resources/js/Pages/Attendance/Index.vue`, `Partials/MonthView.vue`, `Partials/AddSessionModal.vue`, `Session.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify in code before writing: `SaveAttendanceMarksRequest` (minutes 1–600 required for «متأخر»/«غادر مبكراً», reason required for «غائب بعذر»), `Session.vue` defaults (unsaved session = everyone «حاضر» until saved, banner «لم يسجل بعد: الجميع حاضر حتى الحفظ.»; choosing «غائب بعذر» presets «أخرى»; coach pre-filled from the last session), guests search (any active player; other-branch players show their branches), `TrainingSessionController::store` (kind extra/preseason, duplicate slot error «لهذه الفئة حصة في نفس التاريخ والوقت.», default times 18:00–19:30, preseason may add «فئات أخرى مشاركة»), pre-season chip «التحضير البدني {season}: {done}/{target}».

**Outlines** (topic module `attendance`):
1. `take-attendance.md` «كيف أسجّل حضور حصة» — pages `[attendance.index, attendance.sessions.show]`; example: Wednesday's U17 session (session 1): أمين arrives 10 minutes late, عبد الرحمن is absent with an excuse (illness), وليد from «أكابر» joins as a guest. Steps: sidebar «إدارة اللاعبين» → «الحضور» (phone hint), branch «كرة القدم», category «أقل من 17 سنة», click the session in the month → on أمين's row press «متأخر» and type 10 in «الدقائق» → on عبد الرحمن's row press «غائب بعذر», pick «مرض» in «السبب» → add a guest via «ابحث عن لاعب بالاسم لإضافته…» → check «المدرب» → «حفظ» (flash «تم حفظ الحضور.», state «منجزة»). Tip: until the first save, everyone counts as «حاضر» — press «حفظ» even when all came. Warning: none (nothing irreversible).
2. `add-session.md` «كيف أضيف حصة إضافية أو حصة تحضير بدني» — pages `[attendance.index]`; example: a pre-season run for U17 on Saturday, 09:00–10:30, title «جري 7.2 كم في 40 دقيقة» (the demo already holds one; the chip reads 1/12). Steps: «الحضور» → «حصة تحضير بدني» (or click a day: «إضافة حصة في هذا اليوم») → category, date, «البداية»/«النهاية» → «العنوان / الهدف» → optional «فئات أخرى مشاركة» → «حفظ» (opens the session page) → the chip counts it once held. Tip: «حصة إضافية» works the same for one extra regular session. Mention: needs the add right.

**data-help targets (attribute only; grep each, confirm it lands on a real DOM element, not a component root that drops attrs):**
- `Index.vue`: `att-add-extra`, `att-add-preseason`, `att-settings`, `att-grid-link`, `att-stats-link`, `att-branch` (the `BranchSwitch` wrapper).
- `MonthView.vue`: `att-category` (select), `att-preseason-chip`, `att-session-first` (`:data-help` on the first session chip of the month, `i === 0 ? … : null` pattern as `Subscriptions/Index.vue:163`), `att-session-today` on the chip whose session id is the next planned one if simpler — pick one and document.
- `AddSessionModal.vue`: `add-kind`, `add-category`, `add-other-categories`, `add-date`, `add-times` (wrapper of start + end), `add-title`, `add-save`.
- `Session.vue`: `att-state` (header state badge), `att-not-saved` (banner), `att-row-status` (status buttons of the first row), `att-row-late` (first row's «متأخر» button), `att-minutes` (first minutes input shown), `att-reason` (first reason select shown), `att-guest` (search input), `att-coach`, `att-save`.

**Shots** (append to `resources/help/shots.js` under `// --- Attendance ---`; ids from the demo facts table). Month param for the calendar: compute once at the top of the attendance block:

```js
// Attendance data is dated relative to the seed day (HelpDemoAttendanceSeeder): shots
// run right after seeding, so "this month" here is the seeded month.
const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const thisMonth = ym(new Date());
const U17 = 'branch_id=2&category_id=7';
```

```js
    { name: 'att-calendar', url: `/attendance?view=month&${U17}&month=${thisMonth}`, targets: ['att-branch', 'att-category', 'att-session-first', 'att-add-extra', 'att-add-preseason', 'att-preseason-chip'] },
    {
        name: 'att-session',
        url: '/attendance/sessions/1',
        targets: ['att-not-saved', 'att-row-status', 'att-guest', 'att-coach', 'att-save'],
    },
    {
        // The reader's state at the minutes step: «متأخر» pressed on the first row (أمين).
        name: 'att-session-late',
        url: '/attendance/sessions/1',
        targets: ['att-row-late', 'att-minutes'],
        before: async (page) => {
            await page.click('[data-help="att-row-late"]');
            await page.fill('[data-help="att-minutes"]', '10');
        },
    },
    {
        name: 'att-add-preseason-modal',
        url: `/attendance?view=month&${U17}&month=${thisMonth}`,
        targets: ['add-kind', 'add-category', 'add-date', 'add-times', 'add-title', 'add-save'],
        before: async (page) => {
            await page.click('[data-help="att-add-preseason"]');
            await page.waitForSelector('[data-help="add-title"]');
            await page.fill('[data-help="add-title"] input, input[data-help="add-title"]', 'جري 7.2 كم في 40 دقيقة');
        },
    },
```

Add shots for the excused-absence reason (first row set to «غائب بعذر» on another row — find the row by player name with `page.locator('tr', { hasText: 'عبد الرحمن' })`), the guest dropdown (type «وليد» and wait for the result list) and the saved state (`/attendance/sessions/2`, already held) as the articles need. Confirm the first row is أمين (roster order); if not, target rows by name instead of «first». Run `npm run build && npm run help:shots`; view every image; rings on target.

- [ ] **Step 1:** data-help attributes; `npm run build`.
- [ ] **Step 2:** shots; `npm run help:shots`; view images (light and dark).
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `php artisan help:check` (OK), `php artisan test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/attendance/take-attendance.md resources/help/ar/attendance/add-session.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Partials/MonthView.vue resources/js/Pages/Attendance/Partials/AddSessionModal.vue resources/js/Pages/Attendance/Session.vue
git commit -m "docs(help): take-attendance and add-session articles"
```

(Stage only the Vue files you changed; if `BranchSwitch` needed a change, list it too.)

---

### Task 4: Articles 3–4 — month grid; cancel, erase, delete

**Files:**
- Create: `resources/help/ar/attendance/month-grid.md`, `cancel-delete-session.md`
- Modify: `data-help` in `resources/js/Pages/Attendance/Grid.vue`, `Session.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `AttendanceCode::parse` (whitespace removed, Arabic-Indic digits accepted, upper-cased; empty = «حاضر»; `R15` = late 15 min, `D10` = left early 10 min; codes come from settings, so a club that changed «R» types its own letter), `AttendanceGridController::save` (only dirty columns, off-roster cells «—», a cancelled column refused, «غائب بعذر» gets reason «أخرى» unless one was saved), keyboard (arrows + Enter, mirrored in RTL), print buttons «طباعة الورقة» / «طباعة مع الحضور المسجَّل». Cancel: reason required, marks kept but ignored, «ملغاة: {reason}» banner. Delete modal: «حذف الحصة نهائيًا» vs «الإبقاء على الحصة ومسح الحضور فقط», regular-only hint «لن يعيد البرنامج الأسبوعي إنشاءها.», count «سيتم حذف {count} تسجيل حضور نهائيًا.». Rights: grid save, cancel, erase and delete need edit.

**Outlines:**
3. `month-grid.md` «كيف أدخل حضور شهر كامل من الورقة» — pages `[attendance.grid]`; example: the coach's paper sheet for U17 — in one column أمين is «R15», عبد الرحمن «AN». Steps: «الحضور» → «جدول الشهر» (U17, the month) → read the legend → type a code in a cell (empty = present; «R15» late 15 min) → move with the arrow keys or Enter → «حفظ». Tip: print the empty sheet first with «طباعة الورقة». Warning: none (a saved column can be re-edited).
4. `cancel-delete-session.md` «كيف ألغي حصة أو أمسح حضورها أو أحذفها» — pages `[attendance.sessions.show]`; example: the pool is closed on Thursday — cancel «أصاغر» (session 3, already cancelled in the demo: use it for the result shot and session 1 for the button/dialog shots); then a session saved by mistake — erase its marks. Steps: open the session → «إلغاء الحصة» → type «سبب الإلغاء» → confirm (banner «ملغاة: …») → for a wrong save: «حذف الحصة» → choose «الإبقاء على الحصة ومسح الحضور فقط» → «مسح الحضور». ⚠️ Warning: «حذف الحصة نهائيًا» removes the session and all its marks from every statistic; a timetable session is not recreated.

**data-help targets:** `Grid.vue`: `grid-legend`, `grid-help`, `grid-cell-first` (first editable cell input), `grid-column-first` (first column header), `grid-save`, `grid-print`. `Session.vue`: `att-cancel`, `att-delete`, `att-cancel-reason` (modal textarea/input), `att-cancel-confirm`, `att-cancelled-banner`, `att-delete-choices` (radio group wrapper), `att-delete-reset` (the reset radio), `att-delete-confirm`.

**Shots** (grid month = the month of 7 days ago, so the column set holds the held sessions even at the start of a month):

```js
const gridMonth = ym(new Date(Date.now() - 7 * 86400000));
```

```js
    { name: 'att-grid', url: `/attendance/grid?${U17}&month=${gridMonth}`, targets: ['grid-legend', 'grid-help', 'grid-cell-first', 'grid-save'] },
    {
        name: 'att-grid-typed',
        url: `/attendance/grid?${U17}&month=${gridMonth}`,
        targets: ['grid-cell-first', 'grid-save'],
        before: async (page) => { await page.fill('[data-help="grid-cell-first"]', 'R15'); },
    },
    { name: 'att-session-actions', url: '/attendance/sessions/1', targets: ['att-cancel', 'att-delete'] },
    {
        name: 'att-cancel-modal',
        url: '/attendance/sessions/1',
        targets: ['att-cancel-reason', 'att-cancel-confirm'],
        before: async (page) => {
            await page.click('[data-help="att-cancel"]');
            await page.fill('[data-help="att-cancel-reason"]', 'المسبح مغلق للصيانة');
        },
    },
    { name: 'att-cancelled', url: '/attendance/sessions/3', targets: ['att-cancelled-banner'] },
    {
        // A held session (the pre-season one) so both delete choices show.
        name: 'att-delete-modal',
        url: '/attendance/sessions/2',
        targets: ['att-delete-choices', 'att-delete-reset', 'att-delete-confirm'],
        before: async (page) => {
            await page.click('[data-help="att-delete"]');
            await page.click('[data-help="att-delete-reset"]');
        },
    },
```

The first grid cell may be a held column whose first player is not the article's example: choose the cell for أمين in a column by row name if needed, and say in a shots.js comment which cell it is. Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/attendance/month-grid.md resources/help/ar/attendance/cancel-delete-session.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Attendance/Grid.vue resources/js/Pages/Attendance/Session.vue
git commit -m "docs(help): month-grid and cancel/delete articles"
```

---

### Task 5: Articles 5–6 — timetable & closures; codes, rules & alerts

**Files:**
- Create: `resources/help/ar/attendance/timetable.md`, `codes-rules.md`
- Modify: `data-help` in `resources/js/Pages/Attendance/Settings.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `AttendanceSettingsController` — timetable rows need branch, category, weekday, start < end, «من» (valid_from), optional «إلى»; editing or deleting a timetable row removes future unmarked planned sessions of it; a closure removes unmarked planned regular sessions in its range and regenerates nothing inside it; targets 0–200 for the current or next season. Codes 1–3 letters (Arabic accepted), unique; points −5..5 step 0.25; rules `lates_per_unexcused` (0 = off), `late_minutes_as_absent` (0 = off); alerts `min_score_pct`, `unexcused_streak` (0 = off); the single «حفظ» saves codes, points, rules, alerts and roster statuses together; custom codes: behaviour «حاضر» / «غائب بعذر» / «غائب بدون عذر» / «لا يُحتسب», a used code can only be hidden. The page needs the edit right («إعدادات الحضور» button shows only with it).

**Outlines:**
5. `timetable.md` «كيف أضبط التوقيت الأسبوعي وفترات الإغلاق» — pages `[attendance.settings]`; example: U17 trains Monday and Wednesday 18:00–19:30 (already in the demo — show adding Friday 18:00–19:30), and the club closes for «عطلة مدرسية». Steps: «الحضور» → «إعدادات الحضور» → in «التوقيت الأسبوعي» choose branch, «الفئة», «اليوم», «البداية»/«النهاية», «من» → «إضافة» → in «فترات إغلاق النادي» «تاريخ البداية», «تاريخ النهاية», «السبب» → «إضافة» → (optional) «أهداف التحضير البدني»: season + «الحصص المبرمجة» → «حفظ». Tip: sessions appear by themselves in the calendar; no need to add them one by one.
6. `codes-rules.md` «كيف أغيّر رموز الحضور وقواعد الانضباط والتنبيهات» — pages `[attendance.settings]`; example: write «ت» instead of «R» for late, alert after 3 unexcused absences in a row, and add a code «س» «مسافر» counted «غائب بعذر». Steps: «إعدادات الحضور» → «الرموز والألوان»: change a code / colour / points → «قواعد الانضباط» → «التنبيهات» → «حفظ» (flash «تم حفظ إعدادات الحضور.») → «رموز إضافية»: code, colour, name, «يُحتسب كـ» → «إضافة رمز». Warning: a used extra code can't be deleted, only hidden («إخفاء»).

**data-help targets (Settings.vue):** `set-schedules` (card), `set-schedule-form` (the add row/form), `set-schedule-add`, `set-closures`, `set-closure-form`, `set-closure-add`, `set-targets`, `set-target-save`, `set-codes` (card/table), `set-code-late` (the late row's code input), `set-rules`, `set-alerts`, `set-save` (the single save button), `set-custom` (card), `set-custom-form`, `set-custom-add`.

**Shots:** `/attendance/settings?branch_id=2` (check whether the page takes `branch_id`; if not, the branch select in the form is chosen in `before`). One shot per card, each `before` scrolls its card to the centre with the existing `centre(page, name)` helper; the form shots fill the example values first (`page.selectOption`, `page.fill`) so the reader sees the state after typing, before pressing the button. Generate, view every image.

- [ ] **Step 1:** data-help; build.
- [ ] **Step 2:** shots; generate; view.
- [ ] **Step 3:** write the 2 articles.
- [ ] **Step 4:** `help:check`, `test --filter=Help`.
- [ ] **Step 5: Commit**

```bash
git add resources/help/ar/attendance/timetable.md resources/help/ar/attendance/codes-rules.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Attendance/Settings.vue
git commit -m "docs(help): timetable and codes/rules articles"
```

---

### Task 6: Article 7 — follow-up, then phase verification

**Files:**
- Create: `resources/help/ar/attendance/follow-up.md`
- Modify: `data-help` in `resources/js/Pages/Attendance/Stats.vue`, `Ranking.vue`, `Alerts.vue`, `Injuries.vue`, `resources/js/Pages/Players/Partials/InjuryList.vue`; `resources/help/shots.js`
- Generated: `public/help-shots/ar/...`

Facts to verify: `AttendanceStats` (only held sessions count; «نسبة النقاط» = points with discipline rules ÷ all-present points), ranking needs ≥ 5 expected sessions (`RANKING_MIN_EXPECTED`), ties broken by fewer unexcused then fewer lates, «طباعة شهادات الثلاثة الأوائل» / per-row «شهادة» (PDF); `AtRisk` (score below `min_score_pct` with ≥ 5 expected, or longest unexcused streak ≥ `unexcused_streak`); «رسالة إلى الولي» PDF per row (text from the settings letter); injuries = consecutive «حاضر دون تدريب» or «غائب بعذر» with reason «إصابة»; details («إضافة تفاصيل») are added on the player page.

**Outline:**
7. `follow-up.md` «كيف أتابع المواظبة والتنبيهات والإصابات» — pages `[attendance.stats, attendance.ranking, attendance.alerts, attendance.injuries]`; example: U17 this season — عبد الرحمن missed three sessions in a row without excuse (alert, letter to his parent), رياض is injured (open), the best attendance gets a certificate. Steps: «الحضور» → «الإحصائيات» (tiles, «نسبة النقاط») → «ترتيب المواظبة» with «موسم» → «طباعة شهادات الثلاثة الأوائل» → «اللاعبون المعرّضون للخطر» → «رسالة إلى الولي» on عبد الرحمن's row → «الإصابات» → «المصابون حاليًا» → on رياض's page «إضافة تفاصيل». Tip: thresholds come from «التنبيهات» in «إعدادات الحضور» (link the codes-rules article in `related`).

**data-help targets:** `Stats.vue`: `stats-tiles`, `stats-ranking-link`, `stats-alerts-link`, `stats-injuries-link`. `Ranking.vue`: `ranking-type`, `ranking-print-top3`, `ranking-certificate-first`. `Alerts.vue`: `alerts-rules`, `alerts-letter-first`. `Injuries.vue`: `injuries-current`. `InjuryList.vue`: `injury-add-details` (first open spell's button).

**Shots** (all with `${U17}`; season views so the 21-day window is complete; check each page's query names in its controller — e.g. stats `period`, ranking `type=season`):

```js
    { name: 'att-stats', url: `/attendance/stats?${U17}&period=season`, targets: ['stats-tiles', 'stats-ranking-link', 'stats-alerts-link', 'stats-injuries-link'] },
    { name: 'att-ranking', url: `/attendance/ranking?${U17}&type=season`, targets: ['ranking-type', 'ranking-print-top3', 'ranking-certificate-first'] },
    { name: 'att-alerts', url: `/attendance/alerts?${U17}`, targets: ['alerts-rules', 'alerts-letter-first'] },
    { name: 'att-injuries', url: `/attendance/injuries?${U17}`, targets: ['injuries-current'] },
    {
        name: 'att-injury-details',
        url: '/players/4',
        targets: ['injury-add-details'],
        before: async (page) => { await centre(page, 'injury-add-details'); },
    },
```

Generate, view every image (عبد الرحمن on the alerts page, رياض open on injuries).

- [ ] **Step 1:** data-help; build; shots; view images.
- [ ] **Step 2:** write the article.
- [ ] **Step 3: Phase verification** — all must pass: `php artisan help:check` (`Help content OK — 23 article(s).`), `php artisan test --filter=Help`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`, and the full suite in the worktree (`php -d memory_limit=1G vendor/bin/phpunit`, no `bootstrap/cache/config.php`). Manual pass with a throwaway Playwright script (not committed): the 7 articles light/dark at 1280 and 320 px, `?` panel on `/attendance`, `/attendance/sessions/1`, `/attendance/settings`, Ctrl+K «حضور» finds take-attendance; a user with only Attendance view sees the topic.
- [ ] **Step 4: Commit**

```bash
git add resources/help/ar/attendance/follow-up.md resources/help/shots.js public/help-shots/ar resources/js/Pages/Attendance/Stats.vue resources/js/Pages/Attendance/Ranking.vue resources/js/Pages/Attendance/Alerts.vue resources/js/Pages/Attendance/Injuries.vue resources/js/Pages/Players/Partials/InjuryList.vue
git commit -m "docs(help): attendance follow-up article; attendance topic complete"
```
