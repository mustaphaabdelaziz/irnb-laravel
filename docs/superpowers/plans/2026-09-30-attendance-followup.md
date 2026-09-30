# Player Attendance — Follow-up (at risk, parent letter, ranking certificates, injuries) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Act on the attendance data. List the players at risk (a page with an export, a dashboard card and a warning banner on the profile). Print a formal letter to the parents, with text the club can edit. Rank a category for a month or a season and print certificates for the top 3 or for any listed player. Follow injury spells that are built from the marks, with details the staff can add, on the profile and in a club-wide list.

**Architecture:** Every number still comes from `AttendanceStats`. It gains `unexcusedStreaks()` (one ordered scan per request), `ranked()` (the full ranking, with the statistics page's tie rules, which `ranking()` now reuses) and a public `settings()`. Three small sibling services build on it:
- `PlayerNames` attaches names and current categories in two queries. It is extracted from `AttendanceStatsController::withNames()`.
- `AtRisk` holds the alert rules, the flagged list and one player's flags.
- `InjurySpells` builds spells from each player's whole mark history in one query and attaches `injury_notes` details.

The controllers are all gated on the `attendance` module by route name:
- `AttendanceAlertsController`: the page and the XLSX/CSV export.
- `AttendanceRankingController`: the page and the certificates PDF.
- `AttendanceInjuryController`: the club list and the detail writes.
- `AttendancePlayerController`: its profile JSON gains `risk` and `injuries`, and a new `letter()` returns the PDF.
- `AttendanceSettingsController::updateLetter()` saves the letter text.

The PDFs use `PdfService::stream()` (RTL in Arabic; the certificates are landscape), `ClubHeader`, `AttendanceSettings::labels()` and `UiLang`. The letter text lives in `AttendanceSettings` under `letter`, merged over null defaults that fall back to `att.letter.default_*`. A new, guarded `injury_notes` table holds the details.

**Tech Stack:** Laravel 13, Inertia v2 + Vue 3 (`<script setup>`, JS), Tailwind 3.4, vue-i18n (flat dotted keys), mPDF through `App\Services\Pdf\PdfService`, PHPUnit feature tests on sqlite with `#[Test]`.

Spec: `docs/superpowers/specs/2026-09-30-attendance-followup-design.md` (sections A–D, Permissions, Testing).

## Global Constraints

- Work in the existing worktree `D:/irnb-attendance` on branch `feat/attendance-followup`. It starts at main `1c8b3ae` with all attendance work merged, plus the spec commit. It already has `vendor/`, `node_modules/` and `.env`; do not create it. Use Git Bash and start every shell with `cd /d/irnb-attendance`.
- Stage explicit file paths only (`git add <file>...`). Never `git add -A` or `git add .`. Never commit `.superpowers/` (or `.claude/`).
- Commit with exactly: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"`.
- Dates travel as `'Y-m-d'` strings and times as `'H:i'` strings (`training_sessions.date`, `injury_notes.start_date`/`returned_on` are string columns). Never add `date`/`datetime` casts. PDFs print dates as `dd/mm/yyyy`.
- Offline only: no CDN, remote fonts, remote images or remote scripts. This applies to the PDFs too: mPDF uses its bundled fonts, and the club logo is a local file from `ClubHeader`. The certificate's decorative border is plain CSS borders.
- Blade views print with `{{ }}` only, never `{!! !!}`. The letter keeps line breaks by splitting the text into lines and printing `{{ $line }}<br>`, so the text stays escaped.
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. In Vue use `t()` only, never `te()`. Every new key is appended in Task 3 with the temporary `att-i18n.mjs` script (run it, then delete it). Do **not** use `scripts/i18n-add.mjs`: it re-sorts the files.
  - The files must round-trip exactly through `JSON.stringify(o, null, 4) + '\n'`.
  - The diff must be additions only: the previous last line of each file gains a comma, and nothing else changes.
  - No string may contain `|` or `@` (vue-i18n syntax).
  - This plan emits no new `flash.*` key: the letter save reuses `flash.attendance_settings_saved`.
- Templates never call globals: no `@click="window.x()"`. PDFs open from plain `<a :href="..." target="_blank">` links. (`window.axios`/`window.confirm` inside `<script setup>` functions are fine, as elsewhere in the app.)
- A Vue mustache must not contain `}}` inside it. Build placeholder tokens like `{player}` in the script (`'{' + p + '}'`) and print them with `{{ ph.token }}`.
- Never name a test helper `session()`: it collides with Laravel's TestCase. The helpers in this plan are `training()`, `held()`, `heldSession()`, `mark()`, `marks()`, `october()`, `seed()`, `seedClub()`, `seedPlayer()`, `userIn()`, `userWith()`, `spyPdf()`, `pages()`, `risk()`, `streaks()`, `payload()`.
- Test fixtures let overrides win: `array_merge($defaults, $extra)`, never `$defaults + $extra`. The shared `Tests\Support\AttendanceFixtures::player()` predates this rule (`+ $extra`). Never pass it a key it already sets (`membership_id`, `firstname`, `lastname`, `category_id`, `outstanding_debt`). To change one, create the player and `update()` it.
- Never call a mocked or spied route twice in one test method: Laravel caches the resolved controller, so the second call hits the real service. Each PDF assertion that needs the `PdfService` spy is its own test method. One spied request plus one request that stops at the permission middleware (403) is fine.
- JSON round-trips turn whole floats into ints: Laravel encodes without `JSON_PRESERVE_ZERO_FRACTION`. In `assertJsonPath`, Inertia `where()` and decoded dashboard props, assert `100`, `95`, `0`, not `100.0`. Direct PHP calls to the services keep floats (`0.0`, `55.0`).
- Run `npm run build` before running tests that make Inertia page assertions after a `.vue` file changed or a page was added. The root view loads the page through the Vite manifest.
- Migration `injury_notes` (Task 10): `Schema::hasTable` guard, a new table only, no column added to an existing table. It is idempotent, because the desktop app runs `migrate` on every boot. Never rebuild a sqlite table that other tables reference with cascading foreign keys: `->change()` or dropping a column rebuilds it and cascade-deletes child rows.
- `Player::booted()` clears `left_at` unless the status is "left". Tests that need a departed player call `update(['status_id' => Player::leftStatusId(), 'left_at' => '...'])`.
- Tests: PHPUnit classes with `#[Test]` and `use RefreshDatabase;` (plus `Tests\Support\AttendanceFixtures`), in `tests/Feature`; the pure rules go in `tests/Unit` (plain `PHPUnit\Framework\TestCase`). Run with `php artisan test --filter=<Class>`.
  - PDF tests swap `PdfService` for a spy that records `stream($html, $filename, $rtl, $landscape)` and assert the real HTML.
  - Each new PDF also has at least one real mPDF render.
- Query budgets (Rosters can be about 300 players):
  - Every new aggregate runs a fixed number of queries, whatever the roster size, with no per-player query loop.
  - `unexcusedStreaks()` and `InjurySpells` use one ordered `cursor()` scan each.
  - `PlayerNames::attach()` runs 2 queries, or 0 for no rows.
  - `AtRisk::list()` runs 5 queries, or 3 when nobody is flagged.
  - `InjurySpells::club()` runs 4 queries.
  - The members-tab budget in `DashboardPageTest` rises from 26 to 32. Task 5 names the 6 queries in the test's comment.
- Permissions come from route names via `config/permissions.php` and `App\Support\PermissionMap::deriveAction()`. The view verbs are `index`, `show`, `export`, `template`, `card`, `history`, `report`, `receipt`, `minutes`, `calendar`, `inventory` and `preview-serial`. `store`/`create` fall to add, `destroy`/`delete` to delete, and anything else to edit. New routes:

  | Route | Method | Derived | Override | Resolves to |
  |---|---|---|---|---|
  | `attendance.alerts` | GET page | edit | yes | view |
  | `attendance.alerts.export` | GET | view | — | view |
  | `attendance.settings.letter` | PUT | edit | — | edit |
  | `attendance.players.letter` | GET PDF | edit | yes | view |
  | `attendance.ranking` | GET page | edit | yes | view |
  | `attendance.certificates` | GET PDF | edit | yes | view |
  | `attendance.injuries` | GET page | edit | yes | view |
  | `attendance.injury-notes.store` | POST | add | yes | edit |
  | `attendance.injury-notes.update` | PUT | edit | — | edit |
  | `attendance.injury-notes.destroy` | DELETE | delete | yes | edit |

  Every route is asserted in `AttendancePermissionTest` and by at least one 200/403 request test.
- Ambiguities in the spec resolved by this plan (report them to the owner when done):
  1. **Streak rule.** A player is flagged when the **longest** unexcused streak in the period reaches `alerts.unexcused_streak`. The current streak (the run that ends at the player's last held mark in the period) is reported beside it, so an old streak still flags the season, as the spec's "over the period" says.
     - Only real `absent_unexcused` marks count. A late turned into an unexcused absence by the scoring rules does not.
     - Any other status breaks the streak.
     - Cancelled and planned sessions are ignored, as everywhere in the stats.
  2. **Score rule.** Flagged when the score is strictly below `alerts.min_score_pct` (equal is not at risk), and only when the player is expected at 5 or more sessions (`RANKING_MIN_EXPECTED`). The streak rule has no minimum.
  3. **Who is listed.** The at-risk page and the dashboard card list active players only: not archived, and no `left_at`. The profile banner is computed for anyone.
  4. **Category filter.**
     - At risk and injuries filter on the player's **current** category, and the numbers still cover all of the player's marks: the risk and the injury are the person's.
     - The ranking uses the statistics page's mark attribution (`players($from, $to, $categoryId)`), because a certificate rewards attendance in that category.
  5. **Order of the at-risk list.**
     - Lowest score % first; a player with no score % sorts last.
     - Then the longest streak, highest first.
     - Then by name.
     - "Last session" is the date of the player's last held mark in the period.
  6. **Letter content.**
     - Placeholders:
       - `{period}` = `dd/mm/yyyy – dd/mm/yyyy`
       - `{absences}` = excused + unexcused marks
       - `{lates}` = late marks (left-early marks are not counted)
       - `{category}` = the player's current category (`—` when none)
       - `{club}` = the club name
     - The details table lists late, left-early, excused and unexcused marks in date order. "Present, not training" is not listed.
     - The recipient is the first emergency contact (lowest id).
     - The letter is dated today and printed in the reader's language.
  7. **Letter text settings.**
     - They have their own card, route and save button (`attendance.settings.letter`), separate from the scoring form.
     - Texts are trimmed, `\r\n` becomes `\n`, and an empty text becomes null (the built-in text is used).
     - Maximum length: 150 for the subject, 3000 for the body.
  8. **Ranking defaults.**
     - Defaults: the first category by id, and the current month.
     - The season picker offers the current and the two previous seasons.
     - Ranks run 1, 2, 3… with no shared rank, because the tie rules break every tie (last by player id).
     - Players under 5 sessions are shown as a count only.
  9. **Certificates.**
     - Only ranks 1–3 print a place.
     - A chosen player outside the podium, even an unranked one, gets a certificate without a place. The score is `—` when there is none.
     - "Print top 3" with nobody ranked answers 404, and the page hides the button.
  10. **Spells.**
      - Spells are always built from the player's **whole** history, so a spell's start (the key of its details) never depends on the period on screen.
      - A spell is shown when it overlaps the period: it starts by the period's end and ends from its start on. An open spell counts as running until today.
      - "Sessions missed" is the number of injury marks in the spell, "present, not training" included. Per the spec, "not training" counts whatever its reason.
      - If two spells of one player start on the same date (two sessions that day, a normal mark between them), both show the same detail.
  11. **Injury details.**
      - One detail per (player, start date). The create call is an upsert and needs a date on which one of the player's spells starts (422 `att.injury.error.no_spell` otherwise).
      - Unmatched details are always listed on the profile, whatever the period, and can be edited or deleted there.
      - Deleting a detail needs edit, not delete: it is part of "editing injury details".
  12. **Club injuries list.**
      - "Current injuries" are the open spells today, whatever the period.
      - "Spells in the period" follow the period picker (default: the current season).
      - Archived and departed players are left out.
  13. **Dashboard card.** It covers the current season, gives the number of players at risk and the 5 worst, and links to the page. It rides with the members tab (players/view) and needs attendance/view like the existing card.

---

## File map

```
app/Services/Attendance/AttendanceStats.php            unexcusedStreaks(), ranked() (ranking() reuses it), public settings()
app/Services/Attendance/PlayerNames.php                (new) names + current category + active flag, 2 queries
app/Services/Attendance/AtRisk.php                     (new) alert rules, flagged list, one player's flags
app/Services/Attendance/InjurySpells.php               (new) spells from marks, details, club list
app/Services/Activity/ActivityPeriod.php               fromRequestOrSeason()
app/Services/Dashboard/AttendanceCard.php              + risk (season count, 5 worst); shares AtRisk's AttendanceStats
app/Support/AttendanceSettings.php                     DEFAULTS['letter'], LETTER_PLACEHOLDERS, letter()
app/Models/InjuryNote.php                              (new)
database/migrations/2026_09_30_200001_create_injury_notes_table.php   (new, guarded)
app/Http/Controllers/AttendanceStatsController.php     withNames() uses PlayerNames (behaviour unchanged)
app/Http/Controllers/AttendanceAlertsController.php    (new) index(), export()
app/Http/Controllers/AttendancePlayerController.php    show() + risk + injuries; letter() PDF
app/Http/Controllers/AttendanceSettingsController.php  updateLetter()
app/Http/Controllers/AttendanceRankingController.php   (new) index(), certificates()
app/Http/Controllers/AttendanceInjuryController.php    (new) index(), store(), update(), destroy()
routes/web.php                                         routes in the attendance block
config/permissions.php                                 overrides (see the table above)
resources/views/pdf/attendance-letter.blade.php        (new) A4 portrait
resources/views/pdf/attendance-certificates.blade.php  (new) A4 landscape, one page per certificate
resources/js/Pages/Attendance/Alerts.vue               (new)
resources/js/Pages/Attendance/Ranking.vue              (new)
resources/js/Pages/Attendance/Injuries.vue             (new)
resources/js/Pages/Attendance/Stats.vue                links to the three pages; passes period to the player table
resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue   letter link per row
resources/js/Pages/Attendance/Settings.vue             parent-letter card
resources/js/Pages/Dashboard/Partials/AttendanceCard.vue      at-risk section
resources/js/Pages/Players/Partials/AttendanceSection.vue     risk banner, letter link, injuries
resources/js/Pages/Players/Partials/InjuryList.vue     (new) spells timeline + details modal
resources/js/i18n/{ar,fr,en}.json                      att.risk.*, att.letter.*, att.ranking.*, att.cert.*, att.injury.* (additions only)
tests (new):  Feature/AttendanceStreaksTest, Feature/AttendanceAtRiskTest, Unit/AttendanceRiskRulesTest,
              Feature/AttendanceFollowupTranslationsTest, Feature/AttendanceAlertsPageTest, Feature/AttendanceFollowupLinksTest,
              Feature/AttendanceLetterSettingsTest, Feature/AttendanceLetterPdfTest, Feature/AttendanceRankingPageTest,
              Feature/AttendanceCertificatesPdfTest, Feature/AttendanceInjurySpellsTest, Feature/AttendanceInjuryNotesTest,
              Feature/AttendanceInjuriesPageTest
tests (updated): Unit/AttendanceScoreTest, Feature/AttendancePermissionTest, Feature/AttendancePlayerCardTest,
              Feature/Dashboard/DashboardAttendanceCardTest, Feature/Dashboard/DashboardPageTest
unchanged but must stay green: Feature/AttendanceStatsTest, Feature/AttendanceStatsPageTest, Feature/AttendanceStatsExportTest,
              Feature/AttendanceSettingsTest, Feature/AttendanceSettingsPageTest, Feature/AttendancePlayerReportTest
```

---

### Task 1: Streaks and the full ranking in `AttendanceStats`

**Files:**
- Modify: `app/Services/Attendance/AttendanceStats.php`
- Test: `tests/Feature/AttendanceStreaksTest.php` (new), `tests/Unit/AttendanceScoreTest.php` (one test added); `tests/Feature/AttendanceStatsTest.php` must stay green unchanged

**Interfaces:**
- Consumes: the private `AttendanceStats::marks($from, $to, $categoryId, $playerId): Illuminate\Database\Query\Builder` (held sessions of the period); `AttendanceStatus::AbsentUnexcused`.
- Produces:
  - `AttendanceStats::unexcusedStreaks(string $from, string $to, ?int $playerId = null): array<int, array{current: int, longest: int, last_date: string}>`
    - It is keyed by player id, in player-id order, and holds only players with at least one mark in the period.
    - It runs one query (`cursor()`), ordered by player, date, start time and session id.
  - `AttendanceStats::ranked(array $rows): list<array>` (static)
    - It holds the `players()` rows with `expected >= RANKING_MIN_EXPECTED` and a score %.
    - They are sorted best first: score % desc, then fewer unexcused, then fewer lates, then player id.
    - Each row gains `rank` (int, from 1).
  - `AttendanceStats::ranking()` keeps its output. Its `top` rows now also carry `rank`.
  - `AttendanceStats::settings(): array` becomes **public** (the settings, read once per instance).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceStreaksTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AttendanceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceStreaksTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    private function streaks(?int $playerId = null): array
    {
        return app(AttendanceStats::class)->unexcusedStreaks('2026-10-01', '2026-10-31', $playerId);
    }

    #[Test]
    public function streaks_follow_the_session_order_and_any_other_status_breaks_them(): void
    {
        $u15 = $this->category();
        $a = $this->player($u15);
        $b = $this->player($u15);
        $c = $this->player($u15);
        $e = $this->player($u15);
        $this->player($u15);   // no mark at all: not in the result
        // Created out of order on purpose: a streak follows date, start time, id.
        $s5late = $this->training($u15, '2026-10-05', ['start_time' => '20:00', 'end_time' => '21:00']);
        $s1 = $this->training($u15, '2026-10-01');
        $s7 = $this->training($u15, '2026-10-07');
        $s3 = $this->training($u15, '2026-10-03');
        $s5 = $this->training($u15, '2026-10-05');
        $s9 = $this->training($u15, '2026-10-09');
        $cancelled = $this->training($u15, '2026-10-06', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        $november = $this->training($u15, '2026-11-02');
        $unexcused = AttendanceStatus::AbsentUnexcused;

        // A: AN AN P AN AN AN (5th at 18:00 present, 5th at 20:00 unexcused), then November (outside).
        foreach ([$s1, $s3] as $training) {
            $this->mark($training, $a, $unexcused);
        }
        $this->mark($s5, $a, AttendanceStatus::Present);
        foreach ([$s5late, $s7, $s9, $november] as $training) {
            $this->mark($training, $a, $unexcused);
        }
        // B: AN AN AN, late (breaks), a cancelled AN (ignored), AN.
        foreach ([$s1, $s3, $s5] as $training) {
            $this->mark($training, $b, $unexcused);
        }
        $this->mark($s5late, $b, AttendanceStatus::Late, ['minutes' => 5]);
        $this->mark($cancelled, $b, $unexcused);
        $this->mark($s7, $b, $unexcused);
        // C: always present.
        foreach ([$s1, $s3] as $training) {
            $this->mark($training, $c, AttendanceStatus::Present);
        }
        // E: an excused absence breaks a streak too.
        $this->mark($s1, $e, $unexcused);
        $this->mark($s3, $e, AttendanceStatus::AbsentExcused, ['reason' => 'illness']);
        $this->mark($s5, $e, $unexcused);

        $streaks = $this->streaks();

        $this->assertSame([$a->id, $b->id, $c->id, $e->id], array_keys($streaks));
        $this->assertSame(['current' => 3, 'longest' => 3, 'last_date' => '2026-10-09'], $streaks[$a->id]);
        $this->assertSame(['current' => 1, 'longest' => 3, 'last_date' => '2026-10-07'], $streaks[$b->id]);
        $this->assertSame(['current' => 0, 'longest' => 0, 'last_date' => '2026-10-03'], $streaks[$c->id]);
        $this->assertSame(['current' => 1, 'longest' => 1, 'last_date' => '2026-10-05'], $streaks[$e->id]);
        $this->assertSame([$b->id], array_keys($this->streaks($b->id)));
    }

    #[Test]
    public function one_query_whatever_the_roster(): void
    {
        $u15 = $this->category();
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $add = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($trainings as $training) {
                    $this->mark($training, $player, AttendanceStatus::AbsentUnexcused);
                }
            }
        };
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->streaks();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(3);
        $this->assertSame(1, $measure());
        $add(30);
        $this->assertSame(1, $measure());
    }
}
```

In `tests/Unit/AttendanceScoreTest.php`, add this test after `the_ranking_keeps_players_with_enough_sessions_and_breaks_ties()` (before the class's closing brace):
```php

    #[Test]
    public function the_full_ranking_numbers_every_eligible_player_with_the_same_tie_rules(): void
    {
        $rows = [
            self::row(1, 10, 100.0),
            self::row(2, 10, 80.0),
            self::row(3, 5, 80.0, late: 2),     // same % as 2 but more lates: after 2
            self::row(6, 8, 10.0, unexcused: 3),
            self::row(7, 8, 10.0, unexcused: 5),
            self::row(8, 4, 100.0),             // below the minimum
            self::row(9, 10, null),             // no score %
        ];

        $ranked = AttendanceStats::ranked($rows);

        $this->assertSame([1, 2, 3, 6, 7], array_column($ranked, 'player_id'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($ranked, 'rank'));
        $this->assertSame([], AttendanceStats::ranked([self::row(1, 4, 90.0)]));
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceStreaksTest|AttendanceScoreTest"`
Expected: FAIL. The output shows `Call to undefined method App\Services\Attendance\AttendanceStats::unexcusedStreaks()` and `Call to undefined method ...::ranked()`.

- [ ] **Step 3: Implement**

In `app/Services/Attendance/AttendanceStats.php`:

(a) Add this method right after `playerSessions()` (before the `score()` docblock):
```php
    /**
     * Unexcused-absence streaks per player over the period: each player's
     * marks in held sessions in session order (date, start time, id); a run
     * of absent_unexcused marks is a streak and any other status ends it.
     * `current` is the run that ends at the player's last mark of the period,
     * `longest` the longest run, `last_date` that last mark's date. Only real
     * unexcused marks count (a long late scored as unexcused does not). One
     * query, streamed, whatever the roster size.
     *
     * @return array<int, array{current: int, longest: int, last_date: string}> keyed by player id
     */
    public function unexcusedStreaks(string $from, string $to, ?int $playerId = null): array
    {
        $marks = $this->marks($from, $to, null, $playerId)
            ->orderBy('attendances.player_id')
            ->orderBy('training_sessions.date')
            ->orderBy('training_sessions.start_time')
            ->orderBy('training_sessions.id')
            ->select('attendances.player_id', 'attendances.status', 'training_sessions.date');

        $streaks = [];
        foreach ($marks->cursor() as $mark) {
            $id = (int) $mark->player_id;
            $row = $streaks[$id] ?? ['current' => 0, 'longest' => 0, 'last_date' => null];
            $row['current'] = $mark->status === AttendanceStatus::AbsentUnexcused->value ? $row['current'] + 1 : 0;
            $row['longest'] = max($row['longest'], $row['current']);
            $row['last_date'] = $mark->date;
            $streaks[$id] = $row;
        }

        return $streaks;
    }
```

(b) Replace the whole `ranking()` method, with its docblock, by:
```php
    /**
     * Every player with at least RANKING_MIN_EXPECTED expected sessions and a
     * score %, best first: score % desc, then fewer unexcused, then fewer
     * lates, then id. Each row gains its 1-based `rank`; the tie rules break
     * every tie, so no two rows share a rank.
     *
     * @return list<array<string, mixed>>
     */
    public static function ranked(array $rows): array
    {
        $eligible = array_values(array_filter(
            $rows,
            fn (array $row) => $row['expected'] >= self::RANKING_MIN_EXPECTED && $row['score_pct'] !== null,
        ));
        usort($eligible, fn (array $a, array $b) => [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $a['player_id']]
            <=> [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $b['player_id']]);

        foreach ($eligible as $i => $row) {
            $eligible[$i]['rank'] = $i + 1;
        }

        return $eligible;
    }

    /**
     * Top and bottom RANKING_SIZE by score %, among players with at least
     * RANKING_MIN_EXPECTED expected sessions. Ties: top = ranked()'s rules;
     * bottom = more unexcused, then more lates; then id. The bottom list
     * never repeats a player from the top list.
     */
    public static function ranking(array $rows): array
    {
        $ranked = self::ranked($rows);
        $top = array_slice($ranked, 0, self::RANKING_SIZE);
        $topIds = array_column($top, 'player_id');

        $worst = array_values(array_filter($ranked, fn (array $row) => ! in_array($row['player_id'], $topIds, true)));
        usort($worst, fn (array $a, array $b) => [$a['score_pct'], $b['counts']['absent_unexcused'], $b['counts']['late'], $a['player_id']]
            <=> [$b['score_pct'], $a['counts']['absent_unexcused'], $a['counts']['late'], $b['player_id']]);

        return [
            'min_expected' => self::RANKING_MIN_EXPECTED,
            'top' => $top,
            'bottom' => array_slice($worst, 0, self::RANKING_SIZE),
        ];
    }
```

(c) Replace
```php
    private function settings(): array
    {
```
with
```php
    /** The attendance settings, read once per instance (AtRisk reads the alert thresholds here). */
    public function settings(): array
    {
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceStreaksTest|AttendanceScoreTest|AttendanceStatsTest"`
Expected: PASS (2 new feature tests, 1 new unit test, every existing stats test unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceStats.php tests/Feature/AttendanceStreaksTest.php tests/Unit/AttendanceScoreTest.php
git commit -m "feat(attendance): unexcused streaks and the full ranking in AttendanceStats" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `PlayerNames` and the `AtRisk` rules

**Files:**
- Create: `app/Services/Attendance/PlayerNames.php`, `app/Services/Attendance/AtRisk.php`
- Modify: `app/Http/Controllers/AttendanceStatsController.php`
- Test: `tests/Unit/AttendanceRiskRulesTest.php` (new), `tests/Feature/AttendanceAtRiskTest.php` (new); `AttendanceStatsPageTest` and `AttendanceStatsExportTest` must stay green unchanged

**Interfaces:**
- Consumes: `AttendanceStats::players()`, `::unexcusedStreaks()`, `::settings()`, `::RANKING_MIN_EXPECTED` (Task 1); `Player::fullname`; `Category::localized_name`.
- Produces:
  - `App\Services\Attendance\PlayerNames::attach(array $rows): list<array>` (container-resolved, no constructor arguments).
    - Each row needs `player_id`. The method adds `name` (string, `Player::fullname`, or `#id` when the player is missing), `membership_id` (?string), `category_id` (?int, current), `category` (?string, current, localised) and `active` (bool: not archived and `left_at` null).
    - Rows keep their order. It runs 2 queries (players with `category`), or 0 for `[]`.
  - `App\Services\Attendance\AtRisk` (constructor `public readonly AttendanceStats $stats, PlayerNames $names`) with:
    - `thresholds(): array{min_score_pct: int, unexcused_streak: int, min_expected: int}`
    - `static evaluate(array $row, array $streak, array $thresholds): array{at_risk: bool, low_score: bool, streak: bool}`
    - `list(string $from, string $to, ?int $categoryId = null): list<array{player_id: int, expected: int, score_pct: ?float, unexcused: int, current_streak: int, longest_streak: int, last_date: ?string, low_score: bool, streak: bool, name: string, membership_id: ?string, category_id: ?int, category: ?string, active: bool}>`
      - Only active players, filtered on the current category.
      - Worst first: score % asc (null last), then longest streak desc, then name.
      - 5 queries, or 3 when nobody is flagged.
    - `forPlayer(int $playerId, string $from, string $to, array $row): array{at_risk: bool, low_score: bool, streak: bool, score_pct: ?float, current_streak: int, longest_streak: int, min_score_pct: int, unexcused_streak: int, min_expected: int}`
  - `AttendanceStatsController` gets `PlayerNames` injected; the stats page and export output are unchanged apart from the extra `category_id`/`active` keys per player row.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AttendanceRiskRulesTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Services\Attendance\AtRisk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceRiskRulesTest extends TestCase
{
    private const ON = ['min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5];

    private static function row(int $expected, ?float $pct): array
    {
        return ['expected' => $expected, 'score_pct' => $pct];
    }

    private static function streak(int $longest, int $current = 0): array
    {
        return ['current' => $current, 'longest' => $longest, 'last_date' => '2026-10-01'];
    }

    #[Test]
    public function the_score_rule_is_strictly_below_the_threshold_with_enough_sessions(): void
    {
        $this->assertFalse(AtRisk::evaluate(self::row(5, 60.0), self::streak(0), self::ON)['low_score'], 'equal is not below');
        $this->assertTrue(AtRisk::evaluate(self::row(5, 59.9), self::streak(0), self::ON)['low_score']);
        $this->assertFalse(AtRisk::evaluate(self::row(4, 0.0), self::streak(0), self::ON)['low_score'], 'fewer than 5 expected sessions');
        $this->assertFalse(AtRisk::evaluate(self::row(0, null), self::streak(0), self::ON)['low_score']);
        $this->assertFalse(AtRisk::evaluate(self::row(10, 0.0), self::streak(0), array_merge(self::ON, ['min_score_pct' => 0]))['low_score'], '0 turns it off');
    }

    #[Test]
    public function the_streak_rule_uses_the_longest_streak_and_zero_turns_it_off(): void
    {
        $this->assertTrue(AtRisk::evaluate(self::row(10, 100.0), self::streak(3, 3), self::ON)['streak'], 'equal to the threshold');
        $this->assertTrue(AtRisk::evaluate(self::row(10, 100.0), self::streak(3, 0), self::ON)['at_risk'], 'an earlier streak in the period still counts');
        $this->assertFalse(AtRisk::evaluate(self::row(10, 100.0), self::streak(2, 2), self::ON)['streak']);
        $this->assertTrue(AtRisk::evaluate(self::row(2, 100.0), self::streak(3), self::ON)['streak'], 'no minimum of sessions for the streak rule');
        $this->assertFalse(AtRisk::evaluate(self::row(10, 100.0), self::streak(9), array_merge(self::ON, ['unexcused_streak' => 0]))['at_risk'], '0 turns it off');
    }

    #[Test]
    public function either_rule_puts_the_player_at_risk(): void
    {
        $this->assertSame(['at_risk' => true, 'low_score' => true, 'streak' => true], AtRisk::evaluate(self::row(6, 0.0), self::streak(3, 3), self::ON));
        $this->assertSame(['at_risk' => true, 'low_score' => true, 'streak' => false], AtRisk::evaluate(self::row(6, 55.0), self::streak(1), self::ON));
        $this->assertSame(['at_risk' => false, 'low_score' => false, 'streak' => false], AtRisk::evaluate(self::row(6, 100.0), self::streak(0), self::ON));
    }
}
```

`tests/Feature/AttendanceAtRiskTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\AtRisk;
use App\Services\Attendance\AttendanceStats;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAtRiskTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    /** Six held sessions of $category in October, every other day from the 1st. */
    private function october(Category $category): array
    {
        return array_map(fn (string $date) => TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused, illness), AN; null = no mark. */
    private function marks(Player $player, array $trainings, array $codes): void
    {
        foreach ($codes as $i => $code) {
            if ($code === null) {
                continue;
            }
            [$status, $extra] = match ($code) {
                'P' => [AttendanceStatus::Present, []],
                'R' => [AttendanceStatus::Late, ['minutes' => 10]],
                'AE' => [AttendanceStatus::AbsentExcused, ['reason' => 'illness']],
                'AN' => [AttendanceStatus::AbsentUnexcused, []],
            };
            Attendance::create(array_merge([
                'training_session_id' => $trainings[$i]->id, 'player_id' => $player->id,
                'category_id' => $player->category_id, 'status' => $status,
            ], $extra));
        }
    }

    private function risk(): AtRisk
    {
        return app(AtRisk::class);
    }

    /** @return array<string, Player> */
    private function seedClub(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = $this->october($u15);
        $p = [
            'good' => $this->player($u15),        // Test001 P1
            'low' => $this->player($u15),         // Test002 P2
            'streakOnly' => $this->player($u15),  // Test003 P3
            'both' => $this->player($u15),        // Test004 P4
            'archived' => $this->player($u15),    // Test005 P5
            'left' => $this->player($u15),        // Test006 P6
            'moved' => $this->player($u17),       // Test007 P7: now in U17, marked in U15's sessions
        ];
        $this->marks($p['good'], $s, ['P', 'P', 'P', 'P', 'P', 'P']);          // 100 %
        $this->marks($p['low'], $s, ['P', 'P', 'R', 'AE', 'AE', null]);         // 2.75 / 5 = 55 %
        $this->marks($p['streakOnly'], $s, ['AN', 'AN', 'AN', 'P', null, null]); // 4 sessions: no score rule
        $this->marks($p['both'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);       // 0 % and 3 in a row, still running
        $this->marks($p['archived'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);
        $this->marks($p['left'], $s, ['P', 'P', 'P', 'AN', 'AN', 'AN']);
        $this->marks($p['moved'], $s, ['AN', 'AN', 'AN', null, null, null]);   // 3 sessions: streak only
        $p['archived']->update(['archived' => true]);
        $p['left']->update(['status_id' => Player::leftStatusId(), 'left_at' => '2026-10-15']);

        return $p;
    }

    #[Test]
    public function the_thresholds_come_from_the_settings(): void
    {
        $this->assertSame(['min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5], $this->risk()->thresholds());
    }

    #[Test]
    public function the_list_holds_active_players_flagged_by_either_rule_worst_first(): void
    {
        $p = $this->seedClub();

        $rows = $this->risk()->list(self::FROM, self::TO);

        // Three at 0 % (by name), then 55 %. Good, archived and left players are not listed.
        $this->assertSame([$p['streakOnly']->id, $p['both']->id, $p['moved']->id, $p['low']->id], array_column($rows, 'player_id'));

        $both = $rows[1];
        $this->assertSame($p['both']->fullname, $both['name']);
        $this->assertSame('U15', $both['category']);
        $this->assertSame(6, $both['expected']);
        $this->assertSame(0.0, $both['score_pct']);
        $this->assertSame(3, $both['unexcused']);
        $this->assertSame(3, $both['current_streak']);
        $this->assertSame(3, $both['longest_streak']);
        $this->assertSame('2026-10-11', $both['last_date']);
        $this->assertTrue($both['low_score']);
        $this->assertTrue($both['streak']);

        $streakOnly = $rows[0];
        $this->assertFalse($streakOnly['low_score']);
        $this->assertTrue($streakOnly['streak']);
        $this->assertSame(0, $streakOnly['current_streak']);
        $this->assertSame(3, $streakOnly['longest_streak']);

        $low = $rows[3];
        $this->assertTrue($low['low_score']);
        $this->assertFalse($low['streak']);
        $this->assertSame(55.0, $low['score_pct']);
        $this->assertSame(0, $low['unexcused']);
        $this->assertSame('2026-10-09', $low['last_date']);
    }

    #[Test]
    public function the_category_filter_uses_the_players_current_category(): void
    {
        $p = $this->seedClub();

        $this->assertSame([$p['moved']->id], array_column($this->risk()->list(self::FROM, self::TO, $p['moved']->category_id), 'player_id'));
        $this->assertSame(
            [$p['streakOnly']->id, $p['both']->id, $p['low']->id],
            array_column($this->risk()->list(self::FROM, self::TO, $p['both']->category_id), 'player_id'),
        );
    }

    #[Test]
    public function a_rule_set_to_zero_is_off(): void
    {
        $p = $this->seedClub();

        AttendanceSettings::save(['alerts' => ['min_score_pct' => 0]]);
        $this->assertSame([$p['streakOnly']->id, $p['both']->id, $p['moved']->id], array_column($this->risk()->list(self::FROM, self::TO), 'player_id'));

        AttendanceSettings::save(['alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 0]]);
        $this->assertSame([$p['both']->id, $p['low']->id], array_column($this->risk()->list(self::FROM, self::TO), 'player_id'));
    }

    #[Test]
    public function one_players_flags_for_the_profile(): void
    {
        $p = $this->seedClub();
        $stats = app(AttendanceStats::class);
        $rowOf = fn (Player $player) => $stats->players(self::FROM, self::TO, null, $player->id)[$player->id];

        $both = $this->risk()->forPlayer($p['both']->id, self::FROM, self::TO, $rowOf($p['both']));
        $good = $this->risk()->forPlayer($p['good']->id, self::FROM, self::TO, $rowOf($p['good']));

        $this->assertSame([
            'at_risk' => true, 'low_score' => true, 'streak' => true, 'score_pct' => 0.0,
            'current_streak' => 3, 'longest_streak' => 3,
            'min_score_pct' => 60, 'unexcused_streak' => 3, 'min_expected' => 5,
        ], $both);
        $this->assertFalse($good['at_risk']);
        $this->assertSame(0, $good['longest_streak']);
    }

    #[Test]
    public function the_list_costs_the_same_queries_for_3_or_30_players_at_risk(): void
    {
        $u15 = $this->category('U15');
        $s = $this->october($u15);
        $add = function (int $n) use ($u15, $s): void {
            foreach (range(1, $n) as $i) {
                $this->marks($this->player($u15), $s, ['AN', 'AN', 'AN', null, null, null]);
            }
        };
        $measure = function (): int {
            $risk = $this->risk();
            $risk->thresholds();   // loads the settings once
            DB::flushQueryLog();
            DB::enableQueryLog();
            $risk->list(self::FROM, self::TO);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(3);
        $few = $measure();
        $add(27);

        $this->assertSame($few, $measure());
        // players(): 2, the streak scan: 1, the flagged players and their categories: 2.
        $this->assertSame(5, $few);
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceRiskRulesTest|AttendanceAtRiskTest"`
Expected: FAIL. The output shows `Class "App\Services\Attendance\AtRisk" not found`.

- [ ] **Step 3: Write `PlayerNames`**

`app/Services/Attendance/PlayerNames.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Models\Player;

/**
 * Who each attendance row is about: the player's name (Player::fullname),
 * membership id, current category and whether they are still active (not
 * archived, not left). Two queries for any number of rows (the players, then
 * their categories), none for no rows. Shared by the statistics page, the
 * at-risk list, the ranking and the injuries list.
 */
final class PlayerNames
{
    /**
     * @param  array<array-key, array{player_id: int}>  $rows
     * @return list<array<string, mixed>> the rows, in their order, with name, membership_id, category_id, category, active
     */
    public function attach(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $players = Player::with('category')
            ->whereIn('id', array_unique(array_column($rows, 'player_id')))
            ->get(['id', 'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'membership_id', 'category_id', 'archived', 'left_at'])
            ->keyBy('id');

        return array_values(array_map(function (array $row) use ($players): array {
            $player = $players->get($row['player_id']);

            return [
                ...$row,
                'name' => $player?->fullname ?? '#'.$row['player_id'],
                'membership_id' => $player?->membership_id,
                'category_id' => $player?->category_id === null ? null : (int) $player->category_id,
                'category' => $player?->category?->localized_name,
                'active' => $player !== null && ! $player->archived && $player->left_at === null,
            ];
        }, $rows));
    }
}
```

- [ ] **Step 4: Write `AtRisk`**

`app/Services/Attendance/AtRisk.php`:
```php
<?php

namespace App\Services\Attendance;

/**
 * Players at risk over a period, from the attendance settings' alerts:
 *  - a low score: score % strictly below `alerts.min_score_pct`, only for
 *    players expected at RANKING_MIN_EXPECTED sessions or more (the ranking's
 *    minimum, so one early absence does not raise an alert);
 *  - an unexcused streak: `alerts.unexcused_streak` or more unexcused
 *    absences in a row at any time in the period (the longest streak; the
 *    current one is reported beside it).
 * A threshold of 0 turns its rule off. Every number comes from AttendanceStats.
 */
final class AtRisk
{
    private const NO_STREAK = ['current' => 0, 'longest' => 0, 'last_date' => null];

    /** $stats is public so the dashboard card shares this instance and the settings are read once. */
    public function __construct(
        public readonly AttendanceStats $stats,
        private readonly PlayerNames $names,
    ) {}

    /** @return array{min_score_pct: int, unexcused_streak: int, min_expected: int} */
    public function thresholds(): array
    {
        $alerts = $this->stats->settings()['alerts'];

        return [
            'min_score_pct' => (int) $alerts['min_score_pct'],
            'unexcused_streak' => (int) $alerts['unexcused_streak'],
            'min_expected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ];
    }

    /**
     * @param  array{expected: int, score_pct: ?float}  $row  a players() row
     * @param  array{longest: int}  $streak  an unexcusedStreaks() row
     * @param  array{min_score_pct: int, unexcused_streak: int, min_expected: int}  $thresholds
     * @return array{at_risk: bool, low_score: bool, streak: bool}
     */
    public static function evaluate(array $row, array $streak, array $thresholds): array
    {
        $min = $thresholds['min_score_pct'];
        $lowScore = $min > 0
            && $row['expected'] >= $thresholds['min_expected']
            && $row['score_pct'] !== null
            && $row['score_pct'] < $min;
        $inARow = $thresholds['unexcused_streak'];
        $longStreak = $inARow > 0 && $streak['longest'] >= $inARow;

        return ['at_risk' => $lowScore || $longStreak, 'low_score' => $lowScore, 'streak' => $longStreak];
    }

    /**
     * Every active player (not archived, not left) at risk over the period,
     * worst first: lowest score % (none last), then longest streak, then
     * name. $categoryId keeps the players now in that category; their numbers
     * still cover all their marks, since the risk is the person's. Five
     * queries whatever the roster (players(): 2, the streak scan: 1, names:
     * 2), three when nobody is at risk.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $from, string $to, ?int $categoryId = null): array
    {
        $thresholds = $this->thresholds();
        $streaks = $this->stats->unexcusedStreaks($from, $to);

        $flagged = [];
        foreach ($this->stats->players($from, $to) as $playerId => $row) {
            $streak = $streaks[$playerId] ?? self::NO_STREAK;
            $flags = self::evaluate($row, $streak, $thresholds);
            if (! $flags['at_risk']) {
                continue;
            }
            $flagged[] = [
                'player_id' => $playerId,
                'expected' => $row['expected'],
                'score_pct' => $row['score_pct'],
                'unexcused' => $row['counts']['absent_unexcused'],
                'current_streak' => $streak['current'],
                'longest_streak' => $streak['longest'],
                'last_date' => $streak['last_date'],
                'low_score' => $flags['low_score'],
                'streak' => $flags['streak'],
            ];
        }

        $rows = array_values(array_filter(
            $this->names->attach($flagged),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));
        usort($rows, fn (array $a, array $b) => [$a['score_pct'] ?? 101, $b['longest_streak'], $a['name']]
            <=> [$b['score_pct'] ?? 101, $a['longest_streak'], $b['name']]);

        return $rows;
    }

    /**
     * One player's flags for the profile card, over the card's period.
     * $row is the card's own players() row (or emptyRow()).
     *
     * @return array{at_risk: bool, low_score: bool, streak: bool, score_pct: ?float, current_streak: int, longest_streak: int, min_score_pct: int, unexcused_streak: int, min_expected: int}
     */
    public function forPlayer(int $playerId, string $from, string $to, array $row): array
    {
        $streak = $this->stats->unexcusedStreaks($from, $to, $playerId)[$playerId] ?? self::NO_STREAK;
        $thresholds = $this->thresholds();

        return [
            ...self::evaluate($row, $streak, $thresholds),
            'score_pct' => $row['score_pct'],
            'current_streak' => $streak['current'],
            'longest_streak' => $streak['longest'],
            ...$thresholds,
        ];
    }
}
```

- [ ] **Step 5: The statistics page uses `PlayerNames`**

In `app/Http/Controllers/AttendanceStatsController.php`:
- replace the constructor with
```php
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PdfService $pdf,
        private readonly PlayerNames $names,
    ) {}
```
- replace the whole `withNames()` method (with its docblock) with
```php
    /** Adds each player's name, membership id and current category (PlayerNames); sorted by name. */
    private function withNames(array $rows): array
    {
        return collect($this->names->attach($rows))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
```
- in the imports, replace `use App\Models\Player;` with `use App\Services\Attendance\PlayerNames;`. `Player` is no longer used there.

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test --filter="AttendanceRiskRulesTest|AttendanceAtRiskTest|AttendanceStatsPageTest|AttendanceStatsExportTest|AttendanceStatsTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/PlayerNames.php app/Services/Attendance/AtRisk.php app/Http/Controllers/AttendanceStatsController.php tests/Unit/AttendanceRiskRulesTest.php tests/Feature/AttendanceAtRiskTest.php
git commit -m "feat(attendance): at-risk rules and list; names attached by PlayerNames" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Follow-up translations

**Files:**
- Modify: `resources/js/i18n/en.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/ar.json` (additions only)
- Test: `tests/Feature/AttendanceFollowupTranslationsTest.php` (new)

**Interfaces:**
- Consumes: nothing.
- Produces: every key below in all three catalogs. The Vue pages read them through `t()`; the PDFs, the export and the letter defaults read them through `UiLang::get()`. Placeholders:
  - `att.risk.rule_score` `{pct}`, `{n}`; `att.risk.rule_streak` `{streak}`; `att.risk.banner_score` `{score}`, `{min}`; `att.risk.banner_streak` `{longest}`, `{current}`
  - `att.letter.default_subject`/`default_body` `{player}`, `{category}`, `{period}`, `{absences}`, `{lates}`, `{club}`; `att.letter.guardian_of` `{player}`
  - `att.ranking.help` `{n}`; `att.ranking.unranked` `{count}`, `{n}`; `att.ranking.season_label` `{season}`
  - `att.cert.line` `{category}`, `{period}`; `att.cert.score` `{score}`; `att.cert.date` `{date}`
  - `att.injury.modal_title` `{date}`

- [ ] **Step 1: Write the failing test**

`tests/Feature/AttendanceFollowupTranslationsTest.php`:
```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceFollowupTranslationsTest extends TestCase
{
    public const KEYS = [
        // Players at risk
        'att.risk.title', 'att.risk.rule_score', 'att.risk.rule_streak', 'att.risk.rule_off', 'att.risk.none',
        'att.risk.col.unexcused', 'att.risk.col.current_streak', 'att.risk.col.longest_streak', 'att.risk.col.last_session',
        'att.risk.col.reasons', 'att.risk.flag.low_score', 'att.risk.flag.streak', 'att.risk.open_profile',
        'att.risk.dash_title', 'att.risk.see_all', 'att.risk.banner', 'att.risk.banner_score', 'att.risk.banner_streak',
        // Parent letter
        'att.letter.print', 'att.letter.default_subject', 'att.letter.default_body', 'att.letter.to', 'att.letter.guardian_of',
        'att.letter.subject_label', 'att.letter.date_label', 'att.letter.details', 'att.letter.no_details', 'att.letter.totals',
        'att.letter.coach', 'att.letter.president', 'att.letter.settings_title', 'att.letter.settings_help',
        'att.letter.subject', 'att.letter.body', 'att.letter.in_ar', 'att.letter.in_fr', 'att.letter.in_en',
        'att.letter.ph.player', 'att.letter.ph.category', 'att.letter.ph.period', 'att.letter.ph.absences',
        'att.letter.ph.lates', 'att.letter.ph.club',
        // Ranking and certificates
        'att.ranking.title', 'att.ranking.type.month', 'att.ranking.type.season', 'att.ranking.help', 'att.ranking.col.rank',
        'att.ranking.col.present', 'att.ranking.unranked', 'att.ranking.none', 'att.ranking.print_top3',
        'att.ranking.certificate', 'att.ranking.season_label',
        'att.cert.title', 'att.cert.awarded_to', 'att.cert.line', 'att.cert.rank_1', 'att.cert.rank_2', 'att.cert.rank_3',
        'att.cert.score', 'att.cert.date',
        // Injuries
        'att.injury.title', 'att.injury.help', 'att.injury.current', 'att.injury.none_current', 'att.injury.in_period',
        'att.injury.none', 'att.injury.col.start', 'att.injury.col.end', 'att.injury.col.sessions', 'att.injury.col.body_part',
        'att.injury.col.description', 'att.injury.col.returned_on', 'att.injury.col.state', 'att.injury.open',
        'att.injury.closed', 'att.injury.add_details', 'att.injury.edit_details', 'att.injury.modal_title',
        'att.injury.unmatched', 'att.injury.unmatched_help', 'att.injury.error.no_spell', 'att.injury.save_error',
    ];

    #[Test]
    public function every_follow_up_label_exists_in_the_three_catalogs(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            foreach (self::KEYS as $key) {
                $this->assertNotEmpty($catalog[$key] ?? null, "{$locale}: {$key} is missing");
                $this->assertDoesNotMatchRegularExpression('/[|@]/', $catalog[$key], "{$locale}: {$key} uses vue-i18n syntax");
            }
        }
    }

    #[Test]
    public function the_default_letter_uses_every_placeholder(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            $this->assertStringContainsString('{player}', $catalog['att.letter.default_subject'], $locale);
            foreach (['{player}', '{category}', '{period}', '{absences}', '{lates}', '{club}'] as $placeholder) {
                $this->assertStringContainsString($placeholder, $catalog['att.letter.default_body'], "{$locale}: {$placeholder}");
            }
        }
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=AttendanceFollowupTranslationsTest`
Expected: FAIL. The output shows `ar: att.risk.title is missing`.

- [ ] **Step 3: Append the translations**

Create `att-i18n.mjs` in the repo root:
```js
import { readFileSync, writeFileSync } from 'node:fs';

// [en, fr, ar]
const keys = {
    // ---- Players at risk ----
    'att.risk.title': ['Players at risk', 'Joueurs à risque', 'اللاعبون المعرّضون للخطر'],
    'att.risk.rule_score': [
        'Low score: below {pct}% (players expected at {n} sessions or more)',
        'Score faible : sous {pct} % (joueurs attendus à au moins {n} séances)',
        'نسبة ضعيفة: أقل من {pct}% (للاعبين المنتظرين في {n} حصص على الأقل)',
    ],
    'att.risk.rule_streak': [
        'Unexcused streak: {streak} or more unexcused absences in a row',
        "Absences d'affilée : {streak} absences non justifiées consécutives ou plus",
        'غيابات متتالية: {streak} غيابات بدون عذر متتالية أو أكثر',
    ],
    'att.risk.rule_off': ['off', 'désactivé', 'معطّل'],
    'att.risk.none': ['No player at risk for this period.', 'Aucun joueur à risque sur cette période.', 'لا يوجد لاعب معرّض للخطر في هذه الفترة.'],
    'att.risk.col.unexcused': ['Unexcused', 'Non justifiées', 'بدون عذر'],
    'att.risk.col.current_streak': ['Current streak', 'Série en cours', 'السلسلة الحالية'],
    'att.risk.col.longest_streak': ['Longest streak', 'Plus longue série', 'أطول سلسلة'],
    'att.risk.col.last_session': ['Last session', 'Dernière séance', 'آخر حصة'],
    'att.risk.col.reasons': ['Reasons', 'Motifs', 'الأسباب'],
    'att.risk.flag.low_score': ['Low score', 'Score faible', 'نسبة ضعيفة'],
    'att.risk.flag.streak': ['Unexcused streak', "Absences d'affilée", 'غيابات متتالية'],
    'att.risk.open_profile': ['Profile', 'Fiche', 'الملف'],
    'att.risk.dash_title': ['At risk this season', 'À risque cette saison', 'في خطر هذا الموسم'],
    'att.risk.see_all': ['See all', 'Tout voir', 'عرض الكل'],
    'att.risk.banner': ['This player is at risk for this period.', 'Ce joueur est à risque sur cette période.', 'هذا اللاعب معرّض للخطر في هذه الفترة.'],
    'att.risk.banner_score': ['Score {score}, below the {min}% threshold.', 'Score de {score}, sous le seuil de {min} %.', 'نسبة {score}، أقل من العتبة {min}%.'],
    'att.risk.banner_streak': [
        '{longest} unexcused absences in a row (current streak: {current}).',
        "{longest} absences non justifiées d'affilée (série en cours : {current}).",
        '{longest} غيابات بدون عذر متتالية (السلسلة الحالية: {current}).',
    ],
    // ---- Parent letter ----
    'att.letter.print': ['Parent letter', 'Lettre aux parents', 'رسالة إلى الولي'],
    'att.letter.default_subject': ['Attendance of {player} at training', 'Assiduité de {player} aux entraînements', 'مواظبة {player} على التدريبات'],
    'att.letter.default_body': [
        'We would like to inform you that {player} ({category}) missed {absences} training session(s) and arrived late {lates} time(s) during the period {period}. Regular attendance is essential to his progress and to the life of the team. Please talk with him about it and contact the club if there is any difficulty. The {club} staff',
        "Nous vous informons que {player} ({category}) a manqué {absences} séance(s) d'entraînement et est arrivé en retard {lates} fois durant la période {period}. Une présence régulière est indispensable à sa progression et à la vie de l'équipe. Nous vous prions d'en parler avec lui et de contacter le club en cas de difficulté. L'encadrement de {club}",
        'نحيطكم علمًا بأن {player} ({category}) تغيّب عن {absences} حصة تدريبية وتأخر {lates} مرة خلال الفترة {period}. إن المواظبة على التدريبات ضرورية لتقدّمه ولحياة الفريق. نرجو منكم التحدث معه في الأمر والاتصال بالنادي عند وجود أي صعوبة. الطاقم الفني لنادي {club}',
    ],
    'att.letter.to': ['To:', "À l'attention de :", 'إلى:'],
    'att.letter.guardian_of': ['Parent / guardian of {player}', 'Parent / tuteur de {player}', 'وليّ أمر {player}'],
    'att.letter.subject_label': ['Subject:', 'Objet :', 'الموضوع:'],
    'att.letter.date_label': ['Date:', 'Date :', 'التاريخ:'],
    'att.letter.details': ['Details for the period', 'Détail de la période', 'تفاصيل الفترة'],
    'att.letter.no_details': [
        'No absence, late arrival or early departure in this period.',
        'Aucune absence, aucun retard ni départ anticipé sur cette période.',
        'لا غياب ولا تأخر ولا مغادرة مبكرة في هذه الفترة.',
    ],
    'att.letter.totals': ['Totals', 'Totaux', 'المجاميع'],
    'att.letter.coach': ['The coach', "L'entraîneur", 'المدرب'],
    'att.letter.president': ['The president', 'Le président', 'الرئيس'],
    'att.letter.settings_title': ['Parent letter', 'Lettre aux parents', 'رسالة إلى الولي'],
    'att.letter.settings_help': [
        'Leave a field empty to use the built-in text (shown in grey). Placeholders:',
        'Laissez un champ vide pour utiliser le texte par défaut (affiché en gris). Champs remplacés :',
        'اترك الحقل فارغًا لاستعمال النص الافتراضي (المعروض بالرمادي). الحقول التي تُعوَّض:',
    ],
    'att.letter.subject': ['Subject', 'Objet', 'الموضوع'],
    'att.letter.body': ['Text', 'Texte', 'النص'],
    'att.letter.in_ar': ['Arabic', 'Arabe', 'العربية'],
    'att.letter.in_fr': ['French', 'Français', 'الفرنسية'],
    'att.letter.in_en': ['English', 'Anglais', 'الإنجليزية'],
    'att.letter.ph.player': ["player's name", 'nom du joueur', 'اسم اللاعب'],
    'att.letter.ph.category': ['category', 'catégorie', 'الفئة'],
    'att.letter.ph.period': ['period dates', 'dates de la période', 'تواريخ الفترة'],
    'att.letter.ph.absences': ['number of absences', "nombre d'absences", 'عدد الغيابات'],
    'att.letter.ph.lates': ['number of late arrivals', 'nombre de retards', 'عدد مرات التأخر'],
    'att.letter.ph.club': ['club name', 'nom du club', 'اسم النادي'],
    // ---- Ranking and certificates ----
    'att.ranking.title': ['Attendance ranking', "Classement d'assiduité", 'ترتيب المواظبة'],
    'att.ranking.type.month': ['Month', 'Mois', 'شهر'],
    'att.ranking.type.season': ['Season', 'Saison', 'موسم'],
    'att.ranking.help': [
        'Players expected at {n} sessions or more, ranked by score %; ties go to fewer unexcused absences, then fewer lates.',
        'Joueurs attendus à au moins {n} séances, classés par score % ; à égalité, le moins d’absences non justifiées puis le moins de retards.',
        'اللاعبون المنتظرون في {n} حصص على الأقل، مرتبون حسب نسبة النقاط؛ وعند التساوي الأقل غيابًا بدون عذر ثم الأقل تأخرًا.',
    ],
    'att.ranking.col.rank': ['Rank', 'Rang', 'الرتبة'],
    'att.ranking.col.present': ['Present', 'Présences', 'الحضور'],
    'att.ranking.unranked': [
        '{count} player(s) with fewer than {n} sessions are not ranked.',
        '{count} joueur(s) avec moins de {n} séances ne sont pas classés.',
        '{count} لاعب(ين) لديهم أقل من {n} حصص غير مصنّفين.',
    ],
    'att.ranking.none': ['No player is ranked for this period.', 'Aucun joueur classé sur cette période.', 'لا يوجد لاعب مصنّف في هذه الفترة.'],
    'att.ranking.print_top3': ['Print top 3 certificates', 'Imprimer les certificats du podium', 'طباعة شهادات الثلاثة الأوائل'],
    'att.ranking.certificate': ['Certificate', 'Certificat', 'شهادة'],
    'att.ranking.season_label': ['Season {season}', 'Saison {season}', 'موسم {season}'],
    'att.cert.title': ['Certificate of assiduity', "Certificat d'assiduité", 'شهادة مواظبة'],
    'att.cert.awarded_to': ['is awarded to', 'est décerné à', 'تُمنح إلى'],
    'att.cert.line': [
        'in recognition of regular attendance at training — {category}, {period}',
        'en reconnaissance de son assiduité aux entraînements — {category}, {period}',
        'تقديرًا لمواظبته على التدريبات — {category}، {period}',
    ],
    'att.cert.rank_1': ['1st place', '1re place', 'المرتبة الأولى'],
    'att.cert.rank_2': ['2nd place', '2e place', 'المرتبة الثانية'],
    'att.cert.rank_3': ['3rd place', '3e place', 'المرتبة الثالثة'],
    'att.cert.score': ['Attendance score: {score}', "Score d'assiduité : {score}", 'نسبة المواظبة: {score}'],
    'att.cert.date': ['Given on {date}', 'Fait le {date}', 'حُرّر بتاريخ {date}'],
    // ---- Injuries ----
    'att.injury.title': ['Injuries', 'Blessures', 'الإصابات'],
    'att.injury.help': [
        'A spell is a run of consecutive “present, not training” marks or excused absences for injury.',
        'Une blessure regroupe les marques consécutives « présent, sans entraînement » ou absence justifiée pour blessure.',
        'الإصابة سلسلة علامات متتالية «حاضر دون تدريب» أو غياب بعذر بسبب إصابة.',
    ],
    'att.injury.current': ['Currently injured', 'Blessés actuellement', 'المصابون حاليًا'],
    'att.injury.none_current': ['No player is injured at the moment.', 'Aucun joueur blessé actuellement.', 'لا يوجد لاعب مصاب حاليًا.'],
    'att.injury.in_period': ['Injuries in the period', 'Blessures de la période', 'إصابات الفترة'],
    'att.injury.none': ['No injury in this period.', 'Aucune blessure sur cette période.', 'لا توجد إصابة في هذه الفترة.'],
    'att.injury.col.start': ['From', 'Du', 'من'],
    'att.injury.col.end': ['To', 'Au', 'إلى'],
    'att.injury.col.sessions': ['Sessions missed', 'Séances manquées', 'الحصص الفائتة'],
    'att.injury.col.body_part': ['Body part', 'Partie du corps', 'موضع الإصابة'],
    'att.injury.col.description': ['Details', 'Détails', 'التفاصيل'],
    'att.injury.col.returned_on': ['Back on', 'Retour le', 'العودة بتاريخ'],
    'att.injury.col.state': ['State', 'État', 'الحالة'],
    'att.injury.open': ['Ongoing', 'En cours', 'مستمرة'],
    'att.injury.closed': ['Over', 'Terminée', 'انتهت'],
    'att.injury.add_details': ['Add details', 'Ajouter des détails', 'إضافة تفاصيل'],
    'att.injury.edit_details': ['Edit details', 'Modifier les détails', 'تعديل التفاصيل'],
    'att.injury.modal_title': ['Injury from {date}', 'Blessure du {date}', 'إصابة بتاريخ {date}'],
    'att.injury.unmatched': ['Details without a matching spell', 'Détails sans blessure correspondante', 'تفاصيل بلا إصابة مطابقة'],
    'att.injury.unmatched_help': [
        'The marks changed and no spell starts on this date any more. Edit or delete these details.',
        'Les présences ont changé et aucune blessure ne commence plus à cette date. Modifiez ou supprimez ces détails.',
        'تغيّرت العلامات ولم تعد أي إصابة تبدأ في هذا التاريخ. عدّل هذه التفاصيل أو احذفها.',
    ],
    'att.injury.error.no_spell': ['No injury spell starts on this date.', 'Aucune blessure ne commence à cette date.', 'لا تبدأ أي إصابة في هذا التاريخ.'],
    'att.injury.save_error': ['Could not save. Try again.', 'Enregistrement impossible. Réessayez.', 'تعذّر الحفظ. أعد المحاولة.'],
};

['en', 'fr', 'ar'].forEach((locale, i) => {
    const file = `resources/js/i18n/${locale}.json`;
    const text = readFileSync(file, 'utf8');
    const data = JSON.parse(text);
    if (JSON.stringify(data, null, 4) + '\n' !== text) throw new Error(`${locale}: file does not round-trip, stop`);
    for (const [key, values] of Object.entries(keys)) {
        if (key in data) throw new Error(`${locale}: ${key} already exists`);
        if (/[|@]/.test(values[i])) throw new Error(`${locale}: ${key} uses vue-i18n syntax`);
        data[key] = values[i];
    }
    writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
});
```
Run and clean up:
```bash
node att-i18n.mjs && rm att-i18n.mjs
git diff -U0 resources/js/i18n | grep '^-[^-]'
```
Expected: exactly three removed lines, one per file (the previous last key gaining a comma).

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --filter=AttendanceFollowupTranslationsTest && npm run i18n:check`
Expected: PASS, and the check prints no `does not resolve` line.

- [ ] **Step 5: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json tests/Feature/AttendanceFollowupTranslationsTest.php
git commit -m "feat(attendance): translations for the follow-up (at risk, letter, ranking, injuries)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The at-risk page and its export

**Files:**
- Create: `app/Http/Controllers/AttendanceAlertsController.php`, `resources/js/Pages/Attendance/Alerts.vue`
- Modify: `app/Services/Activity/ActivityPeriod.php`, `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Attendance/Stats.vue`, `tests/Feature/AttendancePermissionTest.php`
- Test: `tests/Feature/AttendanceAlertsPageTest.php` (new), `tests/Feature/AttendanceFollowupLinksTest.php` (new)

**Interfaces:**
- Consumes: `AtRisk::list()`, `::thresholds()` (Task 2); `Export::download(string $format, string $filename, array $headers, iterable $rows, ?string $title)`, `Export::format(Request)`; the `att.risk.*` keys (Task 3).
- Produces:
  - `ActivityPeriod::fromRequestOrSeason(Request $request): ActivityPeriod`. It returns the current season when the request has no `period`, and `fromRequest()` otherwise.
  - Route `GET /attendance/alerts?[period=month|season|custom&from&to][&category_id]`, name `attendance.alerts`, permission `['attendance', 'view']` (override).
  - Route `GET /attendance/alerts/export?...&format=xlsx|csv`, name `attendance.alerts.export`, permission view (derived).
  - Inertia page `Attendance/Alerts` with props:
    - `period` (`ActivityPeriod::toArray()`), `categoryId` (?int), `categories` (`list<{id, name}>`)
    - `thresholds` (`AtRisk::thresholds()`)
    - `rows` (`AtRisk::list()`)
  - Export filename `attendance-at-risk-{from}-{to}[-{categoryId}].{xlsx|csv}`, with the title line `att.risk.title — {period label} — {category or all}`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceAlertsPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceAlertsPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27: 2026-09-01 → 2027-08-31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function held(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status): void
    {
        Attendance::create(['training_session_id' => $training->id, 'player_id' => $player->id, 'category_id' => $player->category_id, 'status' => $status]);
    }

    /**
     * U15 "risky": 3 unexcused in a row, then 2 present (0 %, longest streak 3).
     * U15 "fine": always present this season. U17 "weak": 3 excused, then 2 present (40 %).
     *
     * @return array{0: Player, 1: Player, 2: Player}
     */
    private function seed(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $risky = $this->player($u15);
        $fine = $this->player($u15);
        $weak = $this->player($u17);
        foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-10-05', '2026-10-12'] as $i => $date) {
            $training = $this->held($u15, $date);
            $this->mark($training, $risky, $i < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            $this->mark($training, $fine, AttendanceStatus::Present);
            $this->mark($this->held($u17, $date), $weak, $i < 3 ? AttendanceStatus::AbsentExcused : AttendanceStatus::Present);
        }
        // Last season: outside the default period.
        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $date) {
            $this->mark($this->held($u15, $date), $fine, AttendanceStatus::AbsentUnexcused);
        }

        return [$risky, $fine, $weak];
    }

    #[Test]
    public function the_page_lists_this_seasons_players_at_risk_by_default(): void
    {
        [$risky, , $weak] = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.alerts'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Alerts')
                ->where('period.period', 'season')
                ->where('period.from', '2026-09-01')
                ->where('period.to', '2027-08-31')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->where('thresholds.min_score_pct', 60)
                ->where('thresholds.unexcused_streak', 3)
                ->where('thresholds.min_expected', 5)
                ->has('rows', 2)
                ->where('rows.0.player_id', $risky->id)
                ->where('rows.0.name', $risky->fullname)
                ->where('rows.0.category', 'U15')
                ->where('rows.0.expected', 5)
                ->where('rows.0.score_pct', 0)          // JSON: 0.0 comes back as 0
                ->where('rows.0.unexcused', 3)
                ->where('rows.0.current_streak', 0)
                ->where('rows.0.longest_streak', 3)
                ->where('rows.0.last_date', '2026-10-12')
                ->where('rows.0.low_score', true)
                ->where('rows.0.streak', true)
                ->where('rows.1.player_id', $weak->id)
                ->where('rows.1.score_pct', 40)
                ->where('rows.1.low_score', true)
                ->where('rows.1.streak', false));
    }

    #[Test]
    public function a_category_and_a_period_narrow_the_list(): void
    {
        [, , $weak] = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.alerts', ['category_id' => $weak->category_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $weak->category_id)
                ->has('rows', 1)
                ->where('rows.0.player_id', $weak->id));

        // October alone: both played every session.
        $this->actingAs($this->admin())->get(route('attendance.alerts', ['period' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.period', 'month')
                ->where('period.from', '2026-10-01')
                ->has('rows', 0));
    }

    #[Test]
    public function the_export_lists_the_same_rows(): void
    {
        [$risky] = $this->seed();

        $response = $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.alerts.export', ['format' => 'csv']))
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('attendance-at-risk-2026-09-01-2027-08-31.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Joueurs à risque — 2026/27', $csv);
        $this->assertStringContainsString('Série en cours', $csv);
        $this->assertStringContainsString('Plus longue série', $csv);
        $this->assertStringContainsString($risky->fullname, $csv);
        $this->assertStringContainsString(',U15,5,0,3,0,3,2026-10-12,', $csv);   // expected, score %, unexcused, current, longest, last
        $this->assertStringContainsString('Score faible', $csv);
    }

    #[Test]
    public function the_page_and_the_export_need_attendance_view_only(): void
    {
        $this->seed();
        $viewer = $this->userWith(['attendance' => ['view']]);
        $playersOnly = $this->userWith(['players' => ['view']]);

        $this->actingAs($viewer)->get(route('attendance.alerts'))->assertOk();
        $this->actingAs($viewer)->get(route('attendance.alerts.export', ['format' => 'csv']))->assertOk();
        $this->actingAs($playersOnly)->get(route('attendance.alerts'))->assertForbidden();
        $this->actingAs($playersOnly)->get(route('attendance.alerts.export'))->assertForbidden();
    }
}
```

`tests/Feature/AttendanceFollowupLinksTest.php`:
```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The follow-up pages are reachable from the pages people already use, and
 * never through a global called from a template.
 */
class AttendanceFollowupLinksTest extends TestCase
{
    public static function pageLinks(): array
    {
        return [
            'stats → at risk' => ['Attendance/Stats.vue', "route('attendance.alerts')"],
        ];
    }

    #[Test]
    #[DataProvider('pageLinks')]
    public function the_page_links_to_the_follow_up_page(string $file, string $needle): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($needle, $source);
        $this->assertStringNotContainsString('@click="window', $source);
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, inside `attendance_is_a_module_and_its_routes_map_to_it()`, after the line `$this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.sheets.session'));` add:
```php

        // Follow-up: every page and PDF only reads. "alerts", "letter", "ranking", "certificates" and
        // "injuries" are not view verbs, so those routes have overrides; the export is a view verb.
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.alerts'));
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.alerts.export'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceAlertsPageTest|AttendanceFollowupLinksTest|AttendancePermissionTest"`
Expected: FAIL.
- The page tests fail with `Route [attendance.alerts] not defined.`
- The links test fails because the needle is missing from `Stats.vue`.
- The permission test fails: it gets `['attendance', 'edit']` for `attendance.alerts`.

- [ ] **Step 3: `ActivityPeriod::fromRequestOrSeason()`**

In `app/Services/Activity/ActivityPeriod.php`, add after `fromRequest()`:
```php
    /** The current season unless the request picks a period (the profile card, the at-risk list, the injuries). */
    public static function fromRequestOrSeason(Request $request): self
    {
        return $request->query('period') === null ? self::season() : self::fromRequest($request);
    }
```

- [ ] **Step 4: Routes and the permission override**

In `routes/web.php`, add the import next to the other attendance controllers:
```php
use App\Http\Controllers\AttendanceAlertsController;
```
and in the attendance block, right after the `attendance.stats.export` route:
```php
        // Follow-up: players at risk (page + XLSX/CSV); both only read.
        Route::get('/attendance/alerts', [AttendanceAlertsController::class, 'index'])->name('attendance.alerts');
        Route::get('/attendance/alerts/export', [AttendanceAlertsController::class, 'export'])->name('attendance.alerts.export');
```
In `config/permissions.php`, after the `'attendance.sheets.session' => ['attendance', 'view'],` line:
```php
        // Follow-up pages and PDFs only read; none of these last segments is a view verb.
        'attendance.alerts' => ['attendance', 'view'],
```

- [ ] **Step 5: Write the controller**

`app/Http/Controllers/AttendanceAlertsController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use App\Support\Export;
use App\Support\UiLang;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Players at risk (`attendance.alerts`, view): a period, the current season
 * by default, and an optional category (the players' current one). The
 * rules and every number come from AtRisk / AttendanceStats.
 */
class AttendanceAlertsController extends Controller
{
    public function __construct(private readonly AtRisk $risk) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Attendance/Alerts', $this->data($request));
    }

    /** The list as XLSX (default) or CSV, for the same period and category as the page. */
    public function export(Request $request): FileResponse
    {
        $data = $this->data($request);
        ['from' => $from, 'to' => $to] = $data['period'];
        $categoryName = $data['categoryId'] !== null
            ? collect($data['categories'])->firstWhere('id', $data['categoryId'])['name']
            : UiLang::get('att.all_categories');

        $headers = [
            UiLang::get('col.member'), UiLang::get('col.membership_id'), UiLang::get('att.category'),
            UiLang::get('att.col.expected'), UiLang::get('att.col.score_pct'), UiLang::get('att.risk.col.unexcused'),
            UiLang::get('att.risk.col.current_streak'), UiLang::get('att.risk.col.longest_streak'),
            UiLang::get('att.risk.col.last_session'), UiLang::get('att.risk.col.reasons'),
        ];
        $rows = array_map(fn (array $row): array => [
            $row['name'], (string) $row['membership_id'], $row['category'] ?? '', $row['expected'], $row['score_pct'],
            $row['unexcused'], $row['current_streak'], $row['longest_streak'], $row['last_date'] ?? '',
            implode(' · ', array_filter([
                $row['low_score'] ? UiLang::get('att.risk.flag.low_score') : null,
                $row['streak'] ? UiLang::get('att.risk.flag.streak') : null,
            ])),
        ], $data['rows']);

        return Export::download(
            Export::format($request),
            'attendance-at-risk-'.$from.'-'.$to.($data['categoryId'] !== null ? '-'.$data['categoryId'] : ''),
            $headers,
            $rows,
            UiLang::get('att.risk.title').' — '.$data['period']['label'].' — '.$categoryName,
        );
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            'thresholds' => $this->risk->thresholds(),
            'rows' => $this->risk->list($from, $to, $categoryId),
        ];
    }
}
```

- [ ] **Step 6: Write the page**

`resources/js/Pages/Attendance/Alerts.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { pct, periodQuery } from '@/lib/attendanceStats';

/**
 * Players at risk (AtRisk on the server) for a period, the current season by
 * default, and an optional category (the players' current one). A player is
 * listed for a low score, an unexcused streak, or both.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    thresholds: { type: Object, required: true }, // { min_score_pct, unexcused_streak, min_expected }
    rows: { type: Array, default: () => [] },
});
const { t } = useI18n();
const { can } = useCan();

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
const exportHref = computed(() => route('attendance.alerts.export', { ...periodQuery(props.period), ...keep.value }));
function pickCategory(value) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}

// The two rules as configured; a rule set to 0 reads "off".
const rules = computed(() => [
    props.thresholds.min_score_pct > 0
        ? t('att.risk.rule_score', { pct: props.thresholds.min_score_pct, n: props.thresholds.min_expected })
        : `${t('att.risk.flag.low_score')}: ${t('att.risk.rule_off')}`,
    props.thresholds.unexcused_streak > 0
        ? t('att.risk.rule_streak', { streak: props.thresholds.unexcused_streak })
        : `${t('att.risk.flag.streak')}: ${t('att.risk.rule_off')}`,
]);

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const rowAction = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.risk.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.risk.title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.stats')" :class="linkButton">{{ t('att.statistics') }}</Link>
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv']">
                        <template #icon><Icon name="download" /></template>
                    </ExportMenu>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.alerts')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <ul class="space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                <li v-for="(rule, i) in rules" :key="i" class="flex items-center gap-2"><Icon name="alert" class="size-4 text-slate-400" />{{ rule }}</li>
            </ul>

            <section :class="[card, 'overflow-x-auto']">
                <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.risk.none') }}</p>
                <table v-else class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.category') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.expected') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.score_pct') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.unexcused') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.current_streak') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.longest_streak') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.risk.col.last_session') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.risk.col.reasons') }}</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.player_id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td class="p-2 text-end font-semibold tabular-nums" :class="{ 'text-rose-600 dark:text-rose-400': row.low_score }"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                            <td class="p-2 text-end tabular-nums">{{ row.unexcused }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.current_streak }}</td>
                            <td class="p-2 text-end tabular-nums" :class="{ 'font-semibold text-rose-600 dark:text-rose-400': row.streak }">{{ row.longest_streak }}</td>
                            <td class="whitespace-nowrap p-2"><bdi dir="ltr">{{ row.last_date ?? '—' }}</bdi></td>
                            <td class="p-2">
                                <span class="flex flex-wrap gap-1">
                                    <span v-if="row.low_score" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">{{ t('att.risk.flag.low_score') }}</span>
                                    <span v-if="row.streak" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ t('att.risk.flag.streak') }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap p-2 text-end">
                                <span class="inline-flex gap-1">
                                    <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" :class="rowAction"><Icon name="user" />{{ t('att.risk.open_profile') }}</Link>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Link from the statistics page**

In `resources/js/Pages/Attendance/Stats.vue`, replace
```vue
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
```
with
```vue
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
                    <Link :href="route('attendance.alerts')" :class="linkButton">{{ t('att.risk.title') }}</Link>
```

- [ ] **Step 8: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceAlertsPageTest|AttendanceFollowupLinksTest|AttendancePermissionTest|AttendanceStatsPageTest"`
Expected: the build succeeds; PASS.
- The CSV assertions follow the style of the stats export: plain values, and quotes only where the writer adds them.
- If a header assertion fails on quoting, compare with `AttendanceStatsExportTest` and adjust the **test string only**. Do not change the writer.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Activity/ActivityPeriod.php app/Http/Controllers/AttendanceAlertsController.php routes/web.php config/permissions.php resources/js/Pages/Attendance/Alerts.vue resources/js/Pages/Attendance/Stats.vue tests/Feature/AttendanceAlertsPageTest.php tests/Feature/AttendanceFollowupLinksTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): players-at-risk page with XLSX/CSV export" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The dashboard card and the profile banner

**Files:**
- Modify: `app/Services/Dashboard/AttendanceCard.php`, `app/Http/Controllers/AttendancePlayerController.php`, `resources/js/Pages/Dashboard/Partials/AttendanceCard.vue`, `resources/js/Pages/Players/Partials/AttendanceSection.vue`
- Test: `tests/Feature/Dashboard/DashboardAttendanceCardTest.php` (2 tests added), `tests/Feature/Dashboard/DashboardPageTest.php` (budget), `tests/Feature/AttendancePlayerCardTest.php` (1 test added), `tests/Feature/AttendanceFollowupLinksTest.php` (1 entry)

**Interfaces:**
- Consumes: `AtRisk::list()`, `::forPlayer()`, `AtRisk::$stats` (Task 2); `ActivityPeriod::season()`, `::fromRequestOrSeason()` (Task 4).
- Produces:
  - The members tab's `attendance` payload (`AttendanceCard::get()`) gains `risk: {period: ActivityPeriod::toArray() (current season), count: int, worst: list<{player_id, name, category, score_pct, longest_streak, low_score, streak}>}`. `worst` holds at most `AttendanceCard::RISK_SHOWN = 5` rows, in `AtRisk::list()` order.
  - `AttendanceCard`'s constructor takes `AtRisk $risk` instead of `AttendanceStats $stats`. The last-30-days totals use `$this->risk->stats`, so the settings are read once for both.
  - The `attendance.players.show` JSON gains `risk` (`AtRisk::forPlayer()` over the card's period). `AttendancePlayerController` gets `AtRisk $risk` injected, and `data()` uses `ActivityPeriod::fromRequestOrSeason()`.
  - `DashboardPageTest` members budget: 26 → 32.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Dashboard/DashboardAttendanceCardTest.php`, add these imports:
```php
use App\Services\Dashboard\AttendanceCard;
use Illuminate\Support\Facades\DB;
```
and these two tests before the class's closing brace:
```php

    #[Test]
    public function the_card_counts_this_seasons_players_at_risk_and_lists_the_five_worst(): void
    {
        $u15 = $this->category('U15');
        $sessions = array_map(fn (string $date) => $this->heldSession($u15, $date), ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05']);
        $atRisk = [];
        foreach (range(1, 7) as $i) {
            $player = $this->player($u15);
            foreach ($sessions as $n => $training) {
                $this->mark($training, $player, $n < 3 ? AttendanceStatus::AbsentUnexcused : AttendanceStatus::Present);
            }
            $atRisk[] = $player->id;
        }
        $fine = $this->player($u15);
        foreach ($sessions as $training) {
            $this->mark($training, $fine, AttendanceStatus::Present);
        }
        // Last season: not counted.
        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $date) {
            $this->mark($this->heldSession($u15, $date), $fine, AttendanceStatus::AbsentUnexcused);
        }

        $risk = $this->membersTab($this->admin())['attendance']['risk'];

        $this->assertSame('season', $risk['period']['period']);
        $this->assertSame('2026-09-01', $risk['period']['from']);
        $this->assertSame(7, $risk['count']);
        $this->assertSame(array_slice($atRisk, 0, 5), array_column($risk['worst'], 'player_id'));   // all at 0 %: by name
        $this->assertSame(0, $risk['worst'][0]['score_pct']);   // JSON: 0.0 comes back as 0
        $this->assertSame(3, $risk['worst'][0]['longest_streak']);
        $this->assertTrue($risk['worst'][0]['streak']);
        $this->assertTrue($risk['worst'][0]['low_score']);
        $this->assertSame('U15', $risk['worst'][0]['category']);
    }

    #[Test]
    public function the_risk_card_costs_the_same_queries_for_3_or_30_players_at_risk(): void
    {
        $u15 = $this->category('U15');
        $sessions = array_map(fn (string $date) => $this->heldSession($u15, $date), ['2026-09-07', '2026-09-14', '2026-09-21']);
        $flag = function (int $n) use ($u15, $sessions): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                foreach ($sessions as $training) {
                    $this->mark($training, $player, AttendanceStatus::AbsentUnexcused);
                }
            }
        };
        $measure = function (): int {
            $card = app(AttendanceCard::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $card->get();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $flag(3);
        $few = $measure();
        $flag(27);

        $this->assertSame($few, $measure());
    }
```

In `tests/Feature/Dashboard/DashboardPageTest.php`, replace
```php
        // members: +5 for the attendance card — today's schedules, today's
        // sessions, the attendance settings, and the last 30 days' marks
        // grouped by status and by session length.
        foreach (['members' => 26, 'operations' => 12] as $tab => $budget) {
```
with
```php
        // members: +5 for the attendance card — today's schedules, today's
        // sessions, the attendance settings, and the last 30 days' marks
        // grouped by status and by session length.
        // members: +6 for the card's players at risk this season — the
        // season's start month (Season::current()'s website_configs read), the
        // season's marks grouped by status and by session length
        // (AttendanceStats::players()), the ordered unexcused-streak scan, and
        // the flagged players with their categories (2; skipped when nobody is
        // at risk). The settings are not read again: AtRisk shares the card's
        // AttendanceStats. DashboardAttendanceCardTest proves the count does
        // not grow with the number of players at risk.
        foreach (['members' => 32, 'operations' => 12] as $tab => $budget) {
```

In `tests/Feature/AttendancePlayerCardTest.php`, add this test before the class's closing brace:
```php

    #[Test]
    public function the_card_flags_a_player_at_risk_for_its_period(): void
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        foreach (['2026-10-01', '2026-10-03', '2026-10-05'] as $date) {
            $this->mark($this->heldSession($u15, $date), $player, AttendanceStatus::AbsentUnexcused);
        }
        $this->mark($this->heldSession($u15, '2026-10-07'), $player, AttendanceStatus::Present);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonPath('risk.at_risk', true)
            ->assertJsonPath('risk.streak', true)
            ->assertJsonPath('risk.low_score', false)      // 4 sessions: below the score rule's minimum of 5
            ->assertJsonPath('risk.current_streak', 0)
            ->assertJsonPath('risk.longest_streak', 3)
            ->assertJsonPath('risk.unexcused_streak', 3)
            ->assertJsonPath('risk.min_score_pct', 60);

        // September holds none of it.
        $this->actingAs($this->admin())
            ->getJson(route('attendance.players.show', ['player' => $player, 'period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertJsonPath('risk.at_risk', false)
            ->assertJsonPath('risk.longest_streak', 0);
    }
```

In `tests/Feature/AttendanceFollowupLinksTest.php`, add to the `pageLinks()` array:
```php
            'dashboard card → at risk' => ['Dashboard/Partials/AttendanceCard.vue', "route('attendance.alerts')"],
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="DashboardAttendanceCardTest|DashboardPageTest|AttendancePlayerCardTest|AttendanceFollowupLinksTest"`
Expected: FAIL.
- The card test fails with `Undefined array key "risk"`.
- The profile test fails because the path `risk.at_risk` is missing.
- The links test fails for the dashboard card.

- [ ] **Step 3: The dashboard card service**

In `app/Services/Dashboard/AttendanceCard.php`:
- replace the imports block's `use App\Services\Attendance\AttendanceStats;` with
```php
use App\Services\Activity\ActivityPeriod;
use App\Services\Attendance\AtRisk;
use Illuminate\Support\Arr;
```
- replace the class docblock and everything up to and including the end of `get()` with:
```php
/**
 * The members tab's attendance card: today's sessions, the last 30 days by
 * status, and the players at risk this season (count and the 5 worst).
 * Fixed windows, so the dashboard's range and branch filters do not apply
 * (attendance has no branch).
 */
final class AttendanceCard
{
    public const DAYS = 30;

    /** Players at risk listed on the card; the page lists them all. */
    public const RISK_SHOWN = 5;

    public function __construct(
        private readonly CalendarFeed $feed,
        private readonly SessionGenerator $generator,
        // Its AttendanceStats is the card's own too, so the settings are read once.
        private readonly AtRisk $risk,
    ) {}

    /** @return array{date: string, today: list<array<string, mixed>>, last30: array<string, mixed>, risk: array{period: array<string, string>, count: int, worst: list<array<string, mixed>>}} */
    public function get(): array
    {
        $today = CarbonImmutable::today();
        $date = $today->toDateString();
        $this->generateToday($today);

        $from = $today->subDays(self::DAYS - 1)->toDateString();
        $stats = $this->risk->stats;
        $totals = $stats->summarize($stats->players($from, $date));

        $season = ActivityPeriod::season()->toArray();
        $atRisk = $this->risk->list($season['from'], $season['to']);

        return [
            'date' => $date,
            'today' => $this->feed->sessions($date, $date)->all(),
            'last30' => [
                'from' => $from,
                'to' => $date,
                'expected' => $totals['expected'],
                'counts' => $totals['counts'],
                'pct' => $totals['pct'],
            ],
            'risk' => [
                'period' => $season,
                'count' => count($atRisk),
                'worst' => array_map(
                    fn (array $row) => Arr::only($row, ['player_id', 'name', 'category', 'score_pct', 'longest_streak', 'low_score', 'streak']),
                    array_slice($atRisk, 0, self::RISK_SHOWN),
                ),
            ],
        ];
    }
```
Keep `generateToday()` unchanged.

- [ ] **Step 4: The profile JSON**

In `app/Http/Controllers/AttendancePlayerController.php`:
- add `use App\Services\Attendance\AtRisk;` to the imports;
- replace the constructor with
```php
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PreseasonProgress $preseason,
        private readonly PdfService $pdf,
        private readonly AtRisk $risk,
    ) {}
```
- replace `show()` with
```php
    public function show(Request $request, Player $player): JsonResponse
    {
        $data = $this->data($request, $player);
        ['from' => $from, 'to' => $to] = $data['period'];

        return response()->json([
            ...$data,
            // The card's warning banner, for the card's own period.
            'risk' => $this->risk->forPlayer($player->id, $from, $to, $data['summary']),
        ]);
    }
```
- in `data()`, replace
```php
        // The current season unless the request picks a period.
        $period = $request->query('period') === null ? ActivityPeriod::season() : ActivityPeriod::fromRequest($request);
```
with
```php
        $period = ActivityPeriod::fromRequestOrSeason($request);
```

- [ ] **Step 5: The dashboard card's at-risk section**

In `resources/js/Pages/Dashboard/Partials/AttendanceCard.vue`:
- add `import { pct } from '@/lib/attendanceStats';` after the `KIND_DOT` import;
- change the props comment to `// { date, today: [], last30: {...}, risk: { period, count, worst: [] } }`;
- replace `<div class="grid gap-5 px-5 py-4 lg:grid-cols-2">` with `<div class="grid gap-5 px-5 py-4 lg:grid-cols-3">`;
- add this section after the "last 30 days" `</section>` (before the grid's closing `</div>`):
```vue
            <section>
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-muted-foreground">{{ t('att.risk.dash_title') }}</h3>
                    <Link :href="route('attendance.alerts')" class="text-xs font-medium text-primary-700 hover:underline dark:text-primary-300">{{ t('att.risk.see_all') }}</Link>
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums" :class="data.risk.count ? 'text-rose-600 dark:text-rose-400' : 'text-foreground'">{{ data.risk.count }}</p>
                <p v-if="!data.risk.count" class="text-sm text-muted-foreground">{{ t('att.risk.none') }}</p>
                <ul v-else class="mt-1 divide-y divide-border/70">
                    <li v-for="row in data.risk.worst" :key="row.player_id" class="flex items-center gap-2 py-1.5 text-sm">
                        <Link :href="route('players.show', row.player_id)" class="min-w-0 flex-1 truncate font-medium hover:underline">{{ row.name }}</Link>
                        <span v-if="row.streak" class="shrink-0 text-xs text-rose-600 dark:text-rose-400" :title="t('att.risk.col.longest_streak')">{{ t('att.risk.flag.streak') }} · {{ row.longest_streak }}</span>
                        <bdi dir="ltr" class="shrink-0 text-xs font-semibold tabular-nums">{{ pct(row.score_pct) }}</bdi>
                    </li>
                </ul>
            </section>
```
The members tab already needs players/view, so the profile link is always allowed there.

- [ ] **Step 6: The profile banner**

In `resources/js/Pages/Players/Partials/AttendanceSection.vue`:
- after `const summary = computed(() => data.value?.summary ?? null);` add
```js
// AtRisk::forPlayer() for the card's period: { at_risk, low_score, streak, score_pct, current_streak, longest_streak, min_score_pct, ... }
const risk = computed(() => data.value?.risk ?? null);
```
- right after the four tiles' closing `</div>` (the `grid gap-3 sm:grid-cols-4` block), before `<p v-if="preseasonText" ...>`, add
```vue
            <div v-if="risk?.at_risk" role="alert" class="flex gap-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-900">
                <Icon name="alert" class="mt-0.5 size-4 shrink-0" />
                <div class="space-y-0.5">
                    <p class="font-semibold">{{ t('att.risk.banner') }}</p>
                    <p v-if="risk.low_score">{{ t('att.risk.banner_score', { score: pct(risk.score_pct), min: risk.min_score_pct }) }}</p>
                    <p v-if="risk.streak">{{ t('att.risk.banner_streak', { longest: risk.longest_streak, current: risk.current_streak }) }}</p>
                </div>
            </div>
```

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="DashboardAttendanceCardTest|DashboardPageTest|AttendancePlayerCardTest|AttendancePlayerReportTest|AttendanceFollowupLinksTest"`
Expected: the build succeeds; PASS.
- The members tab stays within 32 queries.
- If the new count comes out over 32, print the query list the assertion shows. Find the extra query and fix its cause; do not raise the budget without naming the query in the comment.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Dashboard/AttendanceCard.php app/Http/Controllers/AttendancePlayerController.php resources/js/Pages/Dashboard/Partials/AttendanceCard.vue resources/js/Pages/Players/Partials/AttendanceSection.vue tests/Feature/Dashboard/DashboardAttendanceCardTest.php tests/Feature/Dashboard/DashboardPageTest.php tests/Feature/AttendancePlayerCardTest.php tests/Feature/AttendanceFollowupLinksTest.php
git commit -m "feat(attendance): players at risk on the dashboard card and the profile banner" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: The letter text in the attendance settings

**Files:**
- Modify: `app/Support/AttendanceSettings.php`, `app/Http/Controllers/AttendanceSettingsController.php`, `routes/web.php`, `resources/js/Pages/Attendance/Settings.vue`, `tests/Feature/AttendancePermissionTest.php`
- Test: `tests/Feature/AttendanceLetterSettingsTest.php` (new); `AttendanceSettingsTest` and `AttendanceSettingsPageTest` must stay green unchanged

**Interfaces:**
- Consumes: `UiLang::get($key, null, $locale)`; the `att.letter.*` keys (Task 3).
- Produces:
  - `AttendanceSettings::DEFAULTS['letter'] = ['subject' => ['ar' => null, 'fr' => null, 'en' => null], 'body' => ['ar' => null, 'fr' => null, 'en' => null]]`. It is merged over the defaults like `codes`.
  - `AttendanceSettings::LETTER_PLACEHOLDERS = ['player', 'category', 'period', 'absences', 'lates', 'club']`.
  - `AttendanceSettings::letter(array $values, ?string $locale = null): array{subject: string, body: string}`:
    - It takes the configured text in `$locale` (default: the app locale), else `att.letter.default_subject` / `att.letter.default_body` from the UI catalogs.
    - Every `{name}` is replaced by `(string) $values[name]` (missing → `''`).
    - It does not escape: the Blade view does that.
  - Route `PUT /attendance/settings/letter`, name `attendance.settings.letter`, permission `['attendance', 'edit']` (derived: `letter` is not a view verb).
    - Body: `letter.subject.{ar,fr,en}` (nullable, ≤150) and `letter.body.{ar,fr,en}` (nullable, ≤3000).
    - Each text is trimmed and `\r\n` becomes `\n`; an empty text is stored as null.
    - Redirects back with `flash.attendance_settings_saved`.
  - `AttendanceSettingsController::updateLetter(Request): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceLetterSettingsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceLetterSettingsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const VALUES = [
        'player' => 'Ali Ben', 'category' => 'U15', 'period' => '01/10/2026 – 31/10/2026',
        'absences' => 4, 'lates' => 2, 'club' => 'IRNB',
    ];

    private const EMPTY = ['ar' => null, 'fr' => null, 'en' => null];

    private function payload(array $subject = [], array $body = []): array
    {
        return ['letter' => [
            'subject' => array_merge(self::EMPTY, $subject),
            'body' => array_merge(self::EMPTY, $body),
        ]];
    }

    #[Test]
    public function the_built_in_text_is_used_until_a_text_is_saved(): void
    {
        $this->assertSame(['subject' => self::EMPTY, 'body' => self::EMPTY], AttendanceSettings::get()['letter']);

        $fr = AttendanceSettings::letter(self::VALUES, 'fr');

        $this->assertSame('Assiduité de Ali Ben aux entraînements', $fr['subject']);
        $this->assertStringContainsString('Ali Ben (U15) a manqué 4 séance(s)', $fr['body']);
        $this->assertStringContainsString('en retard 2 fois durant la période 01/10/2026 – 31/10/2026.', $fr['body']);
        $this->assertStringEndsWith('IRNB', $fr['body']);
        $this->assertStringNotContainsString('{', $fr['body']);
        $this->assertSame('مواظبة Ali Ben على التدريبات', AttendanceSettings::letter(self::VALUES, 'ar')['subject']);
    }

    #[Test]
    public function a_saved_text_replaces_the_built_in_one_in_its_language_only(): void
    {
        $this->actingAs($this->admin())
            ->put(route('attendance.settings.letter'), $this->payload(
                ['fr' => 'Absences de {player}'],
                ['fr' => "Bonjour,\r\n{player} : {absences} absences, {lates} retards.", 'en' => ''],
            ))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.attendance_settings_saved');

        $letter = AttendanceSettings::get()['letter'];
        $this->assertSame('Absences de {player}', $letter['subject']['fr']);
        $this->assertSame("Bonjour,\n{player} : {absences} absences, {lates} retards.", $letter['body']['fr']);
        $this->assertNull($letter['body']['en']);
        $this->assertNull($letter['subject']['ar']);

        $this->assertSame('Absences de Ali Ben', AttendanceSettings::letter(self::VALUES, 'fr')['subject']);
        $this->assertSame("Bonjour,\nAli Ben : 4 absences, 2 retards.", AttendanceSettings::letter(self::VALUES, 'fr')['body']);
        $this->assertSame('Attendance of Ali Ben at training', AttendanceSettings::letter(self::VALUES, 'en')['subject']);
        // The other settings survive.
        $this->assertSame(60, AttendanceSettings::get()['alerts']['min_score_pct']);
        $this->assertSame('P', AttendanceSettings::get()['codes']['present']['code']);
    }

    #[Test]
    public function texts_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->put(route('attendance.settings.letter'), $this->payload(['ar' => str_repeat('x', 151)], ['fr' => str_repeat('y', 3001)]))
            ->assertSessionHasErrors(['letter.subject.ar', 'letter.body.fr']);

        $this->assertSame(['subject' => self::EMPTY, 'body' => self::EMPTY], AttendanceSettings::get()['letter']);
    }

    #[Test]
    public function the_settings_page_sends_the_letter_and_saving_needs_edit(): void
    {
        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.letter.subject.fr', null)
                ->where('settings.letter.body.ar', null));

        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view']]])->id]);
        $this->actingAs($viewer)->put(route('attendance.settings.letter'), $this->payload(['fr' => 'X']))->assertForbidden();
        $this->assertNull(AttendanceSettings::get()['letter']['subject']['fr']);
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.alerts.export` assertion added in Task 4, add:
```php
        // The letter text is a setting: saving it needs edit ("letter" falls to edit).
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.settings.letter'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceLetterSettingsTest|AttendancePermissionTest"`
Expected: FAIL. The output shows `Undefined array key "letter"` and `Route [attendance.settings.letter] not defined.`

- [ ] **Step 3: The setting**

In `app/Support/AttendanceSettings.php`:
- in `DEFAULTS`, after the `'codes' => [...]` entry (before the closing `];`), add
```php
        // The parent letter, per language. A null subject or body falls back to
        // the built-in att.letter.default_subject / att.letter.default_body.
        'letter' => [
            'subject' => ['ar' => null, 'fr' => null, 'en' => null],
            'body' => ['ar' => null, 'fr' => null, 'en' => null],
        ],
```
- after `public const LOCALES = ['ar', 'fr', 'en'];` add
```php

    /** What the letter text can contain, each written {name}. */
    public const LETTER_PLACEHOLDERS = ['player', 'category', 'period', 'absences', 'lates', 'club'];
```
- add this method after `labels()`:
```php
    /**
     * The parent letter's subject and body in $locale (default: the app
     * locale): the configured text, else the built-in text from the UI
     * catalogs, with every {placeholder} replaced by its value. Not escaped:
     * the letter view prints it with {{ }}.
     *
     * @param  array<string, string|int>  $values  placeholder name (without braces) => value
     * @return array{subject: string, body: string}
     */
    public static function letter(array $values, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $letter = self::get()['letter'];

        $replace = [];
        foreach (self::LETTER_PLACEHOLDERS as $name) {
            $replace['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }
        $text = fn (string $part): string => strtr(
            ($letter[$part][$locale] ?? null) ?: UiLang::get("att.letter.default_{$part}", null, $locale),
            $replace,
        );

        return ['subject' => $text('subject'), 'body' => $text('body')];
    }
```

- [ ] **Step 4: The save route**

In `app/Http/Controllers/AttendanceSettingsController.php`, add after `update()`:
```php
    /**
     * The parent letter's subject and body per language, saved on their own
     * (a card of their own on the page). Trimmed, with Windows line breaks
     * made plain; an empty text is stored as null, so the built-in one is used.
     */
    public function updateLetter(Request $request): RedirectResponse
    {
        $rules = ['letter' => ['required', 'array']];
        foreach (AttendanceSettings::LOCALES as $locale) {
            $rules["letter.subject.$locale"] = ['nullable', 'string', 'max:150'];
            $rules["letter.body.$locale"] = ['nullable', 'string', 'max:3000'];
        }
        $data = $request->validate($rules);

        $letter = [];
        foreach (['subject', 'body'] as $part) {
            foreach (AttendanceSettings::LOCALES as $locale) {
                $text = trim(str_replace("\r\n", "\n", (string) ($data['letter'][$part][$locale] ?? '')));
                $letter[$part][$locale] = $text === '' ? null : $text;
            }
        }
        AttendanceSettings::save(['letter' => $letter]);

        return back()->with('success', 'flash.attendance_settings_saved');
    }
```
In `routes/web.php`, right after the `attendance.settings.update` route:
```php
        Route::put('/attendance/settings/letter', [AttendanceSettingsController::class, 'updateLetter'])->name('attendance.settings.letter');
```

- [ ] **Step 5: The settings card**

In `resources/js/Pages/Attendance/Settings.vue`:
- after the line `const submitSettings = () => settingsForm.put(route('attendance.settings.update'), { preserveScroll: true });` add
```js

// ---- Parent letter: its own form and save ----
const PLACEHOLDERS = ['player', 'category', 'period', 'absences', 'lates', 'club'];
// Built in the script: a mustache may not contain "}}".
const placeholderTokens = PLACEHOLDERS.map((p) => ({ key: p, token: '{' + p + '}' }));
// Each placeholder standing for itself, so the built-in text shows them as they are typed.
const literal = Object.fromEntries(placeholderTokens.map((p) => [p.key, p.token]));
const letterForm = useForm({ letter: JSON.parse(JSON.stringify(props.settings.letter)) });
const submitLetter = () => letterForm.put(route('attendance.settings.letter'), { preserveScroll: true });
const builtinLetter = (part, loc) => t(`att.letter.default_${part}`, literal, { locale: loc });
const letterErrors = computed(() => Object.values(letterForm.errors).map(tr));
```
- right before `<!-- Codes and points, rules, alerts -->`, add
```vue
            <!-- Parent letter -->
            <form :class="[card, 'lg:col-span-2']" @submit.prevent="submitLetter">
                <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.letter.settings_title') }}</h2>
                <p class="text-xs text-slate-500">{{ t('att.letter.settings_help') }}</p>
                <ul class="mb-3 mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <li v-for="ph in placeholderTokens" :key="ph.key">
                        <code dir="ltr" class="rounded bg-slate-100 px-1 font-mono text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ ph.token }}</code>
                        {{ t(`att.letter.ph.${ph.key}`) }}
                    </li>
                </ul>
                <div class="grid gap-4 lg:grid-cols-3">
                    <div v-for="l in LOCALES" :key="l" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t(`att.letter.in_${l}`) }}</p>
                        <label class="block text-xs text-slate-500">{{ t('att.letter.subject') }}
                            <input v-model="letterForm.letter.subject[l]" type="text" maxlength="150" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtinLetter('subject', l)" :class="[input, 'mt-1 block w-full']" />
                        </label>
                        <label class="block text-xs text-slate-500">{{ t('att.letter.body') }}
                            <textarea v-model="letterForm.letter.body[l]" rows="8" maxlength="3000" :dir="l === 'ar' ? 'rtl' : 'ltr'" :placeholder="builtinLetter('body', l)" :class="[input, 'mt-1 block w-full']"></textarea>
                        </label>
                    </div>
                </div>
                <InputError v-for="(e, i) in letterErrors" :key="i" :message="e" />
                <button type="submit" :disabled="letterForm.processing" class="mt-3 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
            </form>

```
`LOCALES`, `input`, `card`, `tr`, `computed` and `InputError` already exist in the file.

- [ ] **Step 6: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceLetterSettingsTest|AttendancePermissionTest|AttendanceSettingsTest|AttendanceSettingsPageTest|AttendanceCodeSettingsTest"`
Expected: the build succeeds; PASS. The existing settings tests are unchanged: `DEFAULTS` gained a key, and `get()` still equals `DEFAULTS` until something is saved.

- [ ] **Step 7: Commit**

```bash
git add app/Support/AttendanceSettings.php app/Http/Controllers/AttendanceSettingsController.php routes/web.php resources/js/Pages/Attendance/Settings.vue tests/Feature/AttendanceLetterSettingsTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): editable parent-letter text in the attendance settings" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: The parent letter PDF and its links

**Files:**
- Create: `resources/views/pdf/attendance-letter.blade.php`
- Modify: `app/Http/Controllers/AttendancePlayerController.php`, `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Players/Partials/AttendanceSection.vue`, `resources/js/Pages/Attendance/Alerts.vue`, `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue`, `resources/js/Pages/Attendance/Stats.vue`, `tests/Feature/AttendancePermissionTest.php`, `tests/Feature/AttendanceFollowupLinksTest.php`
- Test: `tests/Feature/AttendanceLetterPdfTest.php` (new)

**Interfaces:**
- Consumes:
  - `AttendanceStats::players()`, `::emptyRow()`, `::playerSessions()` (newest first; each row has `date`, `kind`, `status`, `minutes`, `reason`)
  - `AttendanceSettings::letter()` (Task 6), `::labels()`
  - `ActivityPeriod::fromRequestOrSeason()` (Task 4)
  - `Player::emergencyContacts()`, `Player::fullname`, `Category::localized_name`
  - `ClubHeader::data()`, `PdfService::stream()`, `UiLang::get()`
- Produces:
  - Route `GET /attendance/players/{player}/letter?[period=…]`, name `attendance.players.letter`, permission `['attendance', 'view']` (override).
  - `AttendancePlayerController::letter(Request, Player): Symfony Response` with `public const LETTER_STATUSES = ['late', 'left_early', 'absent_excused', 'absent_unexcused']` and a private static `day(string $date): string` (`Y-m-d` → `dd/mm/yyyy`).
  - The PDF is A4 portrait (`$landscape = false`) and `$rtl = locale === 'ar'`. Its filename is `attendance-letter-{membership_id}-{from}-{to}.pdf`.
  - View `pdf.attendance-letter` receives:
    - `club`, `date` (today, `dd/mm/yyyy`), `recipient` (string), `subject`, `body` (strings, unescaped)
    - `periodText` (`dd/mm/yyyy – dd/mm/yyyy`)
    - `rows` (the `LETTER_STATUSES` marks, oldest first), `summary` (the `players()` row), `labels`
  - HTML markers the tests rely on:
    - each body line is printed as `{{ $line }}<br>`;
    - minute and total cells are `<td class="num">…</td>`;
    - the date is `dd/mm/yyyy`.
  - Vue: `letterHref` on the profile card; `letterHref(row)` on the at-risk rows and the statistics player table (`StatsPlayerTable` gains a `period` prop).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceLetterPdfTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerEmergencyContact;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceLetterPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    /** U15; October: present, late 12, excused (injury), unexcused, left early 20, not training; September: unexcused. */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($this->training($u15, '2026-10-01'), $player, AttendanceStatus::Present);
        $this->mark($this->training($u15, '2026-10-03', ['kind' => SessionKind::Preseason]), $player, AttendanceStatus::Late, ['minutes' => 12]);
        $this->mark($this->training($u15, '2026-10-05'), $player, AttendanceStatus::AbsentExcused, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-10-07'), $player, AttendanceStatus::AbsentUnexcused);
        $this->mark($this->training($u15, '2026-10-09'), $player, AttendanceStatus::LeftEarly, ['minutes' => 20]);
        $this->mark($this->training($u15, '2026-10-12'), $player, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u15, '2026-09-15'), $player, AttendanceStatus::AbsentUnexcused);

        return $player;
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;
                $seen['landscape'] = $landscape;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_letter_renders_as_a_real_pdf_in_arabic(): void
    {
        $player = $this->seedPlayer();

        $response = $this->actingAs($this->userIn('ar'))->get(route('attendance.players.letter', $player))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_french_letter_has_the_recipient_subject_text_details_totals_and_signatures(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertFalse($seen['landscape']);
        $this->assertSame("attendance-letter-{$player->membership_id}-2026-10-01-2026-10-31.pdf", $seen['filename']);
        $html = $seen['html'];
        foreach ([
            '20/10/2026',                                               // dated today
            'Parent / tuteur de '.$player->fullname,                    // no emergency contact
            'Objet :',
            'Assiduité de '.$player->fullname.' aux entraînements',
            $player->fullname.' (U15) a manqué 2 séance(s)',            // excused + unexcused
            'en retard 1 fois durant la période 01/10/2026 – 31/10/2026.',
            'Détail de la période',
            '03/10/2026', '05/10/2026', '07/10/2026', '09/10/2026',
            'En retard', 'Parti tôt', 'Absent (justifié)', 'Absent (non justifié)', 'Blessure',
            '<td class="num">12</td>', '<td class="num">20</td>',
            'Totaux', 'entraîneur', 'Le président',
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Present and "not training" are not listed; September is outside the period.
        $this->assertStringNotContainsString('12/10/2026', $html);
        $this->assertStringNotContainsString('15/09/2026', $html);
        // Oldest first.
        $this->assertLessThan(strpos($html, '09/10/2026'), strpos($html, '03/10/2026'));
    }

    #[Test]
    public function the_first_emergency_contact_is_the_recipient(): void
    {
        $player = $this->seedPlayer();
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim Benali', 'relationship' => 'father']);
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Salma Benali', 'relationship' => 'mother']);
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertStringContainsString('Karim Benali', $seen['html']);
        $this->assertStringNotContainsString('Salma Benali', $seen['html']);
        $this->assertStringNotContainsString('Parent / tuteur de', $seen['html']);
    }

    #[Test]
    public function a_saved_text_is_filled_in_escaped_and_keeps_its_line_breaks(): void
    {
        AttendanceSettings::save(['letter' => [
            'subject' => ['fr' => 'Absences de {player}'],
            'body' => ['fr' => "Madame, Monsieur,\n{player} : {absences} absences et {lates} retard(s) <b>à revoir</b> — {club}"],
        ]]);
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $html = $seen['html'];
        $this->assertStringContainsString('Absences de '.$player->fullname, $html);
        $this->assertStringContainsString('Madame, Monsieur,<br>', $html);
        $this->assertStringContainsString(': 2 absences et 1 retard(s) &lt;b&gt;à revoir&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>à revoir</b>', $html);
        $this->assertStringNotContainsString('{club}', $html);
    }

    #[Test]
    public function the_arabic_letter_is_right_to_left(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('الموضوع:', $seen['html']);
        $this->assertStringContainsString('وليّ أمر '.$player->fullname, $seen['html']);
        $this->assertStringContainsString('غائب بعذر', $seen['html']);
    }

    #[Test]
    public function the_default_period_is_the_current_season(): void
    {
        $player = $this->seedPlayer();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.players.letter', $player))->assertOk();

        $this->assertSame("attendance-letter-{$player->membership_id}-2026-09-01-2027-08-31.pdf", $seen['filename']);
        $this->assertStringContainsString('15/09/2026', $seen['html']);
        $this->assertStringContainsString('(U15) a manqué 3 séance(s)', $seen['html']);
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $player = $this->seedPlayer();
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.players.letter', $player))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.players.letter', $player))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.settings.letter` assertion added in Task 6, add:
```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.players.letter'));
```

In `tests/Feature/AttendanceFollowupLinksTest.php`, add this provider and test after `the_page_links_to_the_follow_up_page()`:
```php

    public static function pdfLinks(): array
    {
        return [
            'profile → letter' => ['Players/Partials/AttendanceSection.vue', "route('attendance.players.letter'", ':href="letterHref" target="_blank"'],
            'at risk → letter' => ['Attendance/Alerts.vue', "route('attendance.players.letter'", ':href="letterHref(row)" target="_blank"'],
            'stats table → letter' => ['Attendance/Partials/StatsPlayerTable.vue', "route('attendance.players.letter'", ':href="letterHref(row)" target="_blank"'],
        ];
    }

    #[Test]
    #[DataProvider('pdfLinks')]
    public function the_pdf_opens_in_a_new_tab_from_a_plain_link(string $file, string $route, string $link): void
    {
        $source = (string) file_get_contents(resource_path("js/Pages/{$file}"));

        $this->assertStringContainsString($route, $source);
        $this->assertStringContainsString($link, $source);
        $this->assertStringNotContainsString('@click="window', $source);
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceLetterPdfTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: FAIL.
- The letter tests fail with `Route [attendance.players.letter] not defined.`
- The permission test gets `edit`.
- The three PDF-link cases fail.

- [ ] **Step 3: Route and permission override**

In `routes/web.php`, right after the `attendance.players.report` route:
```php
        Route::get('/attendance/players/{player}/letter', [AttendancePlayerController::class, 'letter'])->name('attendance.players.letter');
```
In `config/permissions.php`, after `'attendance.alerts' => ['attendance', 'view'],`:
```php
        'attendance.players.letter' => ['attendance', 'view'],
```

- [ ] **Step 4: The controller action**

In `app/Http/Controllers/AttendancePlayerController.php`:
- add `use App\Support\UiLang;` to the imports;
- add after the class's opening brace (before the constructor):
```php
    /** The marks a parent letter lists: absences, lates and early departures. */
    public const LETTER_STATUSES = ['late', 'left_early', 'absent_excused', 'absent_unexcused'];

```
- add after `report()`:
```php
    /**
     * A formal letter to the player's parents about the period (the card's,
     * or the current season): club header, date, recipient (the first
     * emergency contact, else "parent / guardian of"), the configured subject
     * and text with their placeholders filled in, the absences, lates and
     * early departures, totals, and signature lines. A4 portrait,
     * right-to-left in Arabic.
     */
    public function letter(Request $request, Player $player): Response
    {
        $player->loadMissing('category');
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();
        $summary = $this->stats->players($from, $to, null, $player->id)[$player->id] ?? $this->stats->emptyRow($player->id);
        $rows = array_reverse(array_values(array_filter(
            $this->stats->playerSessions($player->id, $from, $to),
            fn (array $row) => in_array($row['status'], self::LETTER_STATUSES, true),
        )));
        $club = ClubHeader::data();
        $periodText = self::day($from).' – '.self::day($to);
        $counts = $summary['counts'];
        $letter = AttendanceSettings::letter([
            'player' => $player->fullname,
            'category' => $player->category?->localized_name ?? '—',
            'period' => $periodText,
            'absences' => $counts['absent_excused'] + $counts['absent_unexcused'],
            'lates' => $counts['late'],
            'club' => $club['name'] ?? '',
        ]);
        $contact = $player->emergencyContacts()->orderBy('id')->value('name');

        $html = view('pdf.attendance-letter', [
            'club' => $club,
            'date' => now()->format('d/m/Y'),
            'recipient' => $contact ?: strtr(UiLang::get('att.letter.guardian_of'), ['{player}' => $player->fullname]),
            'subject' => $letter['subject'],
            'body' => $letter['body'],
            'periodText' => $periodText,
            'rows' => $rows,
            'summary' => $summary,
            'labels' => AttendanceSettings::labels(),
        ])->render();

        return $this->pdf->stream($html, "attendance-letter-{$player->membership_id}-{$from}-{$to}.pdf", app()->getLocale() === 'ar');
    }

    /** 'Y-m-d' → 'dd/mm/yyyy', as printed on letters. */
    private static function day(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }
```

- [ ] **Step 5: The view**

`resources/views/pdf/attendance-letter.blade.php`:
```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $day = fn (string $date) => substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    $shown = ['absent_unexcused', 'absent_excused', 'late', 'left_early'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1e293b; line-height: 1.5; }
        table.meta { margin: 2px 0 12px; }
        table.meta td { padding: 2px 6px 2px 0; vertical-align: top; }
        .label { color: #64748b; }
        .subject { font-weight: bold; }
        .body { margin: 10px 0 14px; }
        h2 { font-size: 12px; color: #0f172a; margin: 14px 0 4px; }
        table.rows { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.rows th { background: #f1f5f9; color: #334155; padding: 4px; border: 1px solid #e2e8f0; }
        table.rows td { padding: 4px; border: 1px solid #e2e8f0; }
        .num { text-align: center; }
        .empty { color: #64748b; padding: 6px 0; }
        table.sign { width: 100%; margin-top: 16mm; }
        table.sign td { width: 50%; vertical-align: top; }
        .line { border-top: 1px solid #94a3b8; width: 60mm; margin-top: 16mm; }
    </style>
</head>
<body>
    @include('pdf.partials.header')

    <table class="meta">
        <tr><td class="label">{{ $L('att.letter.date_label') }}</td><td><bdi dir="ltr">{{ $date }}</bdi></td></tr>
        <tr><td class="label">{{ $L('att.letter.to') }}</td><td>{{ $recipient }}</td></tr>
        <tr><td class="label">{{ $L('att.letter.subject_label') }}</td><td class="subject">{{ $subject }}</td></tr>
    </table>

    <div class="body">
        @foreach (preg_split('/\R/u', $body) as $line)
            {{ $line }}<br>
        @endforeach
    </div>

    <h2>{{ $L('att.letter.details') }} — <bdi dir="ltr">{{ $periodText }}</bdi></h2>
    @if (empty($rows))
        <div class="empty">{{ $L('att.letter.no_details') }}</div>
    @else
        <table class="rows">
            <tr>
                <th>{{ $L('att.date') }}</th>
                <th>{{ $L('att.col.kind') }}</th>
                <th>{{ $L('att.col.status') }}</th>
                <th>{{ $L('att.minutes') }}</th>
                <th>{{ $L('att.reason') }}</th>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="num"><bdi dir="ltr">{{ $day($row['date']) }}</bdi></td>
                    <td>{{ $L('att.kind.'.$row['kind']) }}</td>
                    <td>{{ $labels[$row['status']] ?? $row['status'] }}</td>
                    <td class="num">{{ $row['minutes'] }}</td>
                    <td>{{ $row['reason'] ? $L('att.reason.'.$row['reason']) : '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ $L('att.letter.totals') }}</h2>
    <table class="rows">
        <tr>
            @foreach ($shown as $status)
                <th>{{ $labels[$status] }}</th>
            @endforeach
            <th>{{ $L('att.col.late_minutes') }}</th>
        </tr>
        <tr>
            @foreach ($shown as $status)
                <td class="num">{{ $summary['counts'][$status] }}</td>
            @endforeach
            <td class="num">{{ $summary['late_minutes'] }}</td>
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td>{{ $L('att.letter.coach') }}<div class="line"></div></td>
            <td>{{ $L('att.letter.president') }}<div class="line"></div></td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 6: The links**

In `resources/js/Pages/Players/Partials/AttendanceSection.vue`:
- after the `reportHref` computed add
```js
// The parent letter, for the same period.
const letterHref = computed(() => (data.value ? route('attendance.players.letter', { player: props.player.id, ...periodQuery(data.value.period) }) : null));
```
- after the "print report" `</a>` in the header, add
```vue
                <a :href="letterHref" target="_blank" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <Icon name="mail" /> {{ t('att.letter.print') }}
                </a>
```

In `resources/js/Pages/Attendance/Alerts.vue`:
- after `const exportHref = ...;` add
```js
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period) });
```
- in the last cell, inside `<span class="inline-flex gap-1">`, before the profile `<Link …>`, add
```vue
                                    <a :href="letterHref(row)" target="_blank" :class="rowAction"><Icon name="mail" />{{ t('att.letter.print') }}</a>
```

In `resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue`:
- add the imports `import Icon from '@/Components/Icon.vue';` and replace `import { hours, pct } from '@/lib/attendanceStats';` with `import { hours, pct, periodQuery } from '@/lib/attendanceStats';`;
- add the prop `period: { type: Object, required: true },` after `rows`;
- after `const ariaSort = ...;` add
```js
// The parent letter, for the page's period.
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period) });
```
- in `<thead>`'s row, after the `<th v-for="col in columns" …>…</th>` element, add `<th class="p-2"></th>`;
- in `<tbody>`'s row, after the score-% `<td>`, add
```vue
                    <td class="whitespace-nowrap p-2 text-end">
                        <a :href="letterHref(row)" target="_blank" :title="t('att.letter.print')" class="inline-flex items-center rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"><Icon name="mail" /><span class="sr-only">{{ t('att.letter.print') }}</span></a>
                    </td>
```

In `resources/js/Pages/Attendance/Stats.vue`, replace `<StatsPlayerTable :rows="players" />` with `<StatsPlayerTable :rows="players" :period="period" />`.

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceLetterPdfTest|AttendancePermissionTest|AttendanceFollowupLinksTest|AttendancePlayerCardTest|AttendancePlayerReportTest|AttendanceStatsPageTest"`
Expected: the build succeeds; PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendancePlayerController.php resources/views/pdf/attendance-letter.blade.php routes/web.php config/permissions.php resources/js/Pages/Players/Partials/AttendanceSection.vue resources/js/Pages/Attendance/Alerts.vue resources/js/Pages/Attendance/Partials/StatsPlayerTable.vue resources/js/Pages/Attendance/Stats.vue tests/Feature/AttendanceLetterPdfTest.php tests/Feature/AttendancePermissionTest.php tests/Feature/AttendanceFollowupLinksTest.php
git commit -m "feat(attendance): parent letter PDF, printable from the profile, the at-risk list and the stats table" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: The ranking page

**Files:**
- Create: `app/Http/Controllers/AttendanceRankingController.php`, `resources/js/Pages/Attendance/Ranking.vue`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Attendance/Stats.vue`, `tests/Feature/AttendancePermissionTest.php`, `tests/Feature/AttendanceFollowupLinksTest.php`
- Test: `tests/Feature/AttendanceRankingPageTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceStats::players($from, $to, $categoryId)`, `AttendanceStats::ranked()`, `::RANKING_MIN_EXPECTED` (Task 1); `PlayerNames::attach()` (Task 2); `Season::current()`, `Season::forStartYear(int)`, `->start()`, `->end()`, `->label()`, `->startYear`; `UiLang::get()`.
- Produces:
  - Route `GET /attendance/ranking?[category_id][&type=month|season][&month=Y-m][&season=YYYY]`, name `attendance.ranking`, permission `['attendance', 'view']` (override).
  - `AttendanceRankingController` (constructor `AttendanceStats $stats, PlayerNames $names, PdfService $pdf`) with `index(Request): Inertia\Response` and two private helpers that Task 9 reuses:
    - `period(Request $request): array{type: 'month'|'season', month: string, season: int, from: string, to: string, label: string, key: string}`
      - Defaults: `month`, the current month, and the current season's start year.
      - `label`: the month name in the app locale (e.g. `octobre 2026`), or `att.ranking.season_label` (e.g. `Saison 2026/27`).
      - `key`: `2026-10` or `season-2026`, used in filenames.
    - `ranking(int $categoryId, array $period): array{rows: list<array>, unranked: int}`. `rows` are the `ranked()` rows with `PlayerNames` attached; `unranked` counts the players with marks but under the minimum.
  - Inertia page `Attendance/Ranking` with props:
    - `categories` (`list<{id, name}>`), `categoryId` (?int; default: the first category by id)
    - `period` (as above), `seasons` (`list<{start_year, label}>`: the current and the two previous seasons)
    - `rows` (`list<{player_id, rank, name, category, expected, present, score_pct}>`)
    - `unranked` (int), `minExpected` (int)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceRankingPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRankingPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // October 2026, season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused), AN; null = no mark. */
    private function marks(Player $player, array $trainings, array $codes): void
    {
        foreach ($codes as $i => $code) {
            if ($code === null) {
                continue;
            }
            [$status, $extra] = match ($code) {
                'P' => [AttendanceStatus::Present, []],
                'R' => [AttendanceStatus::Late, ['minutes' => 10]],
                'AE' => [AttendanceStatus::AbsentExcused, ['reason' => 'illness']],
                'AN' => [AttendanceStatus::AbsentUnexcused, []],
            };
            Attendance::create(array_merge([
                'training_session_id' => $trainings[$i]->id, 'player_id' => $player->id,
                'category_id' => $player->category_id, 'status' => $status,
            ], $extra));
        }
    }

    /**
     * U15, five October sessions: A 100 %, B and G 95 % (one late each; B first by id),
     * C 80 %, D 60 %, E only 4 sessions (not ranked). C was also unexcused in September.
     *
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = array_map(fn (string $date) => $this->training($u15, $date), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09']);
        $p = [
            'a' => $this->player($u15), 'b' => $this->player($u15), 'c' => $this->player($u15),
            'd' => $this->player($u15), 'e' => $this->player($u15), 'g' => $this->player($u15),
        ];
        $this->marks($p['a'], $s, ['P', 'P', 'P', 'P', 'P']);
        $this->marks($p['b'], $s, ['P', 'P', 'P', 'P', 'R']);
        $this->marks($p['c'], $s, ['P', 'P', 'P', 'P', 'AE']);
        $this->marks($p['d'], $s, ['P', 'P', 'P', 'P', 'AN']);
        $this->marks($p['e'], $s, ['P', 'P', 'P', 'AE', null]);
        $this->marks($p['g'], $s, ['R', 'P', 'P', 'P', 'P']);
        $this->marks($p['c'], [$this->training($u15, '2026-09-15')], ['AN']);
        $this->player($u17);

        return ['u15' => $u15, 'u17' => $u17, ...$p];
    }

    #[Test]
    public function the_first_category_and_the_current_month_are_ranked_by_default(): void
    {
        $x = $this->seed();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.ranking'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Ranking')
                ->where('categoryId', $x['u15']->id)
                ->has('categories', 2)
                ->where('period.type', 'month')
                ->where('period.month', '2026-10')
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->where('period.label', 'octobre 2026')
                ->has('seasons', 3)
                ->where('seasons.0.start_year', 2026)
                ->where('seasons.0.label', '2026/27')
                ->where('minExpected', 5)
                ->where('unranked', 1)
                ->has('rows', 5)
                ->where('rows.0.player_id', $x['a']->id)
                ->where('rows.0.rank', 1)
                ->where('rows.0.name', $x['a']->fullname)
                ->where('rows.0.expected', 5)
                ->where('rows.0.present', 5)
                ->where('rows.0.score_pct', 100)
                ->where('rows.1.player_id', $x['b']->id)     // tie at 95 %: same unexcused and lates, lower id first
                ->where('rows.1.score_pct', 95)
                ->where('rows.2.player_id', $x['g']->id)
                ->where('rows.2.rank', 3)
                ->where('rows.3.player_id', $x['c']->id)
                ->where('rows.3.present', 4)
                ->where('rows.4.player_id', $x['d']->id)
                ->where('rows.4.score_pct', 60));
    }

    #[Test]
    public function a_season_can_be_ranked(): void
    {
        $x = $this->seed();

        $this->actingAs($this->userIn('fr'))
            ->get(route('attendance.ranking', ['category_id' => $x['u15']->id, 'type' => 'season', 'season' => 2026]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.type', 'season')
                ->where('period.season', 2026)
                ->where('period.from', '2026-09-01')
                ->where('period.to', '2027-08-31')
                ->where('period.label', 'Saison 2026/27')
                // C now has 6 sessions with a September unexcused: 3/6 = 50 %, after D.
                ->where('rows.3.player_id', $x['d']->id)
                ->where('rows.4.player_id', $x['c']->id)
                ->where('rows.4.score_pct', 50));
    }

    #[Test]
    public function a_category_without_ranked_players_shows_an_empty_list(): void
    {
        $x = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.ranking', ['category_id' => $x['u17']->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categoryId', $x['u17']->id)
                ->has('rows', 0)
                ->where('unranked', 0));
    }

    #[Test]
    public function the_page_needs_attendance_view_only(): void
    {
        $this->seed();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.ranking'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.ranking'))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.players.letter` assertion added in Task 7, add:
```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.ranking'));
```

In `tests/Feature/AttendanceFollowupLinksTest.php`, add to `pageLinks()`:
```php
            'stats → ranking' => ['Attendance/Stats.vue', "route('attendance.ranking')"],
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceRankingPageTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: FAIL. The output shows `Route [attendance.ranking] not defined.`, the permission test gets `edit`, and the link needle is missing.

- [ ] **Step 3: Route and permission override**

In `routes/web.php`, add the import `use App\Http\Controllers\AttendanceRankingController;` next to the other attendance controllers. In the attendance block, right after the `attendance.alerts.export` route, add:
```php
        // Follow-up: a category's ranking for a month or a season.
        Route::get('/attendance/ranking', [AttendanceRankingController::class, 'index'])->name('attendance.ranking');
```
In `config/permissions.php`, after `'attendance.players.letter' => ['attendance', 'view'],`:
```php
        'attendance.ranking' => ['attendance', 'view'],
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/AttendanceRankingController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Attendance\AttendanceStats;
use App\Services\Attendance\PlayerNames;
use App\Services\Pdf\PdfService;
use App\Support\Season;
use App\Support\UiLang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One category's attendance ranking for a month or a season, and (Task 9)
 * the certificates printed from it. Players are ranked by
 * AttendanceStats::ranked() — the statistics page's rules — over the marks
 * attributed to the category, as the statistics page does when filtered on
 * it. Only reads (attendance/view, see config/permissions.php).
 */
class AttendanceRankingController extends Controller
{
    public function __construct(
        private readonly AttendanceStats $stats,
        private readonly PlayerNames $names,
        private readonly PdfService $pdf,
    ) {}

    public function index(Request $request): Response
    {
        $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categories = Category::orderBy('id')->get()
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
            ->values()->all();
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : ($categories[0]['id'] ?? null);
        $period = $this->period($request);
        ['rows' => $rows, 'unranked' => $unranked] = $categoryId === null
            ? ['rows' => [], 'unranked' => 0]
            : $this->ranking($categoryId, $period);
        $current = Season::current();

        return Inertia::render('Attendance/Ranking', [
            'categories' => $categories,
            'categoryId' => $categoryId,
            'period' => $period,
            'seasons' => array_map(function (int $back) use ($current): array {
                $season = Season::forStartYear($current->startYear - $back);

                return ['start_year' => $season->startYear, 'label' => $season->label()];
            }, [0, 1, 2]),
            'rows' => array_map(fn (array $row): array => [
                'player_id' => $row['player_id'],
                'rank' => $row['rank'],
                'name' => $row['name'],
                'category' => $row['category'],
                'expected' => $row['expected'],
                'present' => $row['counts']['present'],
                'score_pct' => $row['score_pct'],
            ], $rows),
            'unranked' => $unranked,
            'minExpected' => AttendanceStats::RANKING_MIN_EXPECTED,
        ]);
    }

    /**
     * The ranked period: a month (default: this one) or a season (default:
     * the current one), with its label in the reader's language and a
     * filename key.
     *
     * @return array{type: string, month: string, season: int, from: string, to: string, label: string, key: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:month,season'],
            'month' => ['nullable', 'date_format:Y-m'],
            'season' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $seasonYear = isset($data['season']) ? (int) $data['season'] : Season::current()->startYear;

        if (($data['type'] ?? 'month') === 'season') {
            $season = Season::forStartYear($seasonYear);

            return [
                'type' => 'season', 'month' => $month, 'season' => $seasonYear,
                'from' => $season->start()->toDateString(), 'to' => $season->end()->toDateString(),
                'label' => strtr(UiLang::get('att.ranking.season_label'), ['{season}' => $season->label()]),
                'key' => 'season-'.$seasonYear,
            ];
        }

        $anchor = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [
            'type' => 'month', 'month' => $month, 'season' => $seasonYear,
            'from' => $anchor->startOfMonth()->toDateString(), 'to' => $anchor->endOfMonth()->toDateString(),
            'label' => $anchor->locale(app()->getLocale())->translatedFormat('F Y'),
            'key' => $month,
        ];
    }

    /**
     * The category's ranked players, with names, and how many players with
     * marks had too few sessions to be ranked.
     *
     * @return array{rows: list<array<string, mixed>>, unranked: int}
     */
    private function ranking(int $categoryId, array $period): array
    {
        $rows = $this->stats->players($period['from'], $period['to'], $categoryId);
        $ranked = AttendanceStats::ranked($rows);

        return ['rows' => $this->names->attach($ranked), 'unranked' => count($rows) - count($ranked)];
    }
}
```
`$pdf` is used by `certificates()` in Task 9.

- [ ] **Step 5: Write the page**

`resources/js/Pages/Attendance/Ranking.vue`:
```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useCan } from '@/Composables/useCan';
import { pct } from '@/lib/attendanceStats';

/**
 * One category's attendance ranking for a month or a season
 * (AttendanceStats::ranked on the server: score %, then fewer unexcused,
 * then fewer lates). Players under the minimum are counted, not ranked.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    period: { type: Object, required: true }, // { type, month, season, from, to, label }
    seasons: { type: Array, default: () => [] }, // [{ start_year, label }]
    rows: { type: Array, default: () => [] },
    unranked: { type: Number, default: 0 },
    minExpected: { type: Number, required: true },
});
const { t } = useI18n();
const { can } = useCan();

// The page's filters as they travel in a URL; `changes` overrides them.
function query(changes = {}) {
    const q = { category_id: props.categoryId, type: props.period.type, ...changes };
    if (q.type === 'season') q.season = changes.season ?? props.period.season;
    else q.month = changes.month ?? props.period.month;
    return q;
}
const visit = (changes) => router.get(route('attendance.ranking'), query(changes), { preserveScroll: true, replace: true });

// Gold, silver and bronze for the podium.
const MEDAL = {
    1: 'bg-amber-400 text-amber-950',
    2: 'bg-slate-300 text-slate-800',
    3: 'bg-orange-300 text-orange-950',
};

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.ranking.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.ranking.title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.stats')" :class="linkButton">{{ t('att.statistics') }}</Link>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select :value="period.type" :class="input" :aria-label="t('activity.period_label')" @change="visit({ type: $event.target.value })">
                    <option value="month">{{ t('att.ranking.type.month') }}</option>
                    <option value="season">{{ t('att.ranking.type.season') }}</option>
                </select>
                <input v-if="period.type === 'month'" type="month" :value="period.month" :class="input" :aria-label="t('att.ranking.type.month')" @change="$event.target.value && visit({ month: $event.target.value })" />
                <select v-else :value="period.season" :class="input" :aria-label="t('att.ranking.type.season')" @change="visit({ season: Number($event.target.value) })">
                    <option v-for="s in seasons" :key="s.start_year" :value="s.start_year">{{ s.label }}</option>
                </select>
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ period.label }}</span>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.ranking.help', { n: minExpected }) }}</p>

            <section :class="[card, 'overflow-x-auto']">
                <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.ranking.none') }}</p>
                <table v-else class="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'w-16 text-center']">{{ t('att.ranking.col.rank') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.expected') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.ranking.col.present') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.score_pct') }}</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.player_id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="p-2 text-center">
                                <span v-if="MEDAL[row.rank]" class="inline-flex size-7 items-center justify-center rounded-full text-xs font-bold" :class="MEDAL[row.rank]">{{ row.rank }}</span>
                                <span v-else class="tabular-nums text-slate-500">{{ row.rank }}</span>
                            </td>
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.present }}</td>
                            <td class="p-2 text-end font-semibold tabular-nums"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                            <td class="whitespace-nowrap p-2 text-end"></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="unranked" class="px-4 pb-3 pt-2 text-xs text-slate-500">{{ t('att.ranking.unranked', { count: unranked, n: minExpected }) }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
```
The empty last column receives the per-row certificate link in Task 9.

- [ ] **Step 6: Link from the statistics page**

In `resources/js/Pages/Attendance/Stats.vue`, replace
```vue
                    <Link :href="route('attendance.alerts')" :class="linkButton">{{ t('att.risk.title') }}</Link>
```
with
```vue
                    <Link :href="route('attendance.alerts')" :class="linkButton">{{ t('att.risk.title') }}</Link>
                    <Link :href="route('attendance.ranking')" :class="linkButton">{{ t('att.ranking.title') }}</Link>
```

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceRankingPageTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: the build succeeds; PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceRankingController.php resources/js/Pages/Attendance/Ranking.vue resources/js/Pages/Attendance/Stats.vue routes/web.php config/permissions.php tests/Feature/AttendanceRankingPageTest.php tests/Feature/AttendancePermissionTest.php tests/Feature/AttendanceFollowupLinksTest.php
git commit -m "feat(attendance): ranking page per category for a month or a season" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: The certificates PDF

**Files:**
- Create: `resources/views/pdf/attendance-certificates.blade.php`
- Modify: `app/Http/Controllers/AttendanceRankingController.php`, `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Attendance/Ranking.vue`, `tests/Feature/AttendancePermissionTest.php`, `tests/Feature/AttendanceFollowupLinksTest.php`
- Test: `tests/Feature/AttendanceCertificatesPdfTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceRankingController::period()` and `::ranking()` (Task 8); `AttendanceStats::players()`, `::emptyRow()`; `PlayerNames::attach()`; `ClubHeader::data()`; `PdfService::stream()`; the `att.cert.*` keys.
- Produces:
  - Route `GET /attendance/certificates?category_id=<int>[&type&month&season][&player_id=<int>]`, name `attendance.certificates`, permission `['attendance', 'view']` (override).
  - `AttendanceRankingController::certificates(Request): Symfony Response` and `public const PODIUM = 3`.
    - Without `player_id` it prints one page per podium player (at most 3), with the filename `certificates-{categoryId}-{key}.pdf`. It answers 404 when nobody is ranked.
    - With `player_id` it prints that player's single certificate, with the filename `certificate-{membership_id}-{key}.pdf`. The rank is printed only when it is 1–3. A player who is not in the ranked list gets their own `players()` row, or `emptyRow()`.
    - The PDF is A4 landscape (`$landscape = true`) and `$rtl = locale === 'ar'`.
  - View `pdf.attendance-certificates` receives `club`, `category` (string: the ranking's category), `periodLabel`, `date` (`dd/mm/yyyy`) and `certificates` (`list<{name: string, rank: ?int, score_pct: ?float}>`).
  - HTML markers:
    - one `<table class="frame">` per certificate, with `<pagebreak />` between them;
    - `class="rank"` only when a place is printed.
  - `Ranking.vue`: `podiumHref` (header button, only when there are rows) and `certificateHref(row)` (per row), both opening in a new tab.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceCertificatesPdfTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCertificatesPdfTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userIn(string $locale): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function training(Category $category, string $date): TrainingSession
    {
        return TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
    }

    /** Marks $player at $trainings[i] with $codes[i]: P, R (late 10 min), AE (excused), AN; null = no mark. */
    private function marks(Player $player, array $trainings, array $codes): void
    {
        foreach ($codes as $i => $code) {
            if ($code === null) {
                continue;
            }
            [$status, $extra] = match ($code) {
                'P' => [AttendanceStatus::Present, []],
                'R' => [AttendanceStatus::Late, ['minutes' => 10]],
                'AE' => [AttendanceStatus::AbsentExcused, ['reason' => 'illness']],
                'AN' => [AttendanceStatus::AbsentUnexcused, []],
            };
            Attendance::create(array_merge([
                'training_session_id' => $trainings[$i]->id, 'player_id' => $player->id,
                'category_id' => $player->category_id, 'status' => $status,
            ], $extra));
        }
    }

    /** Same as the ranking page: A 100 %, B 95 %, G 95 %, C 80 %, D 60 %; E not ranked. */
    private function seed(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $s = array_map(fn (string $date) => $this->training($u15, $date), ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07', '2026-10-09']);
        $p = [
            'a' => $this->player($u15), 'b' => $this->player($u15), 'c' => $this->player($u15),
            'd' => $this->player($u15), 'e' => $this->player($u15), 'g' => $this->player($u15),
        ];
        $this->marks($p['a'], $s, ['P', 'P', 'P', 'P', 'P']);
        $this->marks($p['b'], $s, ['P', 'P', 'P', 'P', 'R']);
        $this->marks($p['c'], $s, ['P', 'P', 'P', 'P', 'AE']);
        $this->marks($p['d'], $s, ['P', 'P', 'P', 'P', 'AN']);
        $this->marks($p['e'], $s, ['P', 'P', 'P', 'AE', null]);
        $this->marks($p['g'], $s, ['R', 'P', 'P', 'P', 'P']);

        return ['u15' => $u15, 'u17' => $u17, ...$p];
    }

    /** Swaps mPDF for a spy; returns a reference filled with what stream() received. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true, bool $landscape = false) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;
                $seen['landscape'] = $landscape;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    /** Pages in a real mPDF document (page objects, not the /Pages tree). */
    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    #[Test]
    public function the_podium_prints_as_a_real_three_page_pdf(): void
    {
        $x = $this->seed();

        $response = $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertSame(3, self::pages((string) $response->getContent()));
    }

    #[Test]
    public function the_podium_certificates_carry_name_place_score_category_and_period(): void
    {
        $x = $this->seed();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();

        $this->assertTrue($seen['landscape']);
        $this->assertFalse($seen['rtl']);
        $this->assertSame("certificates-{$x['u15']->id}-2026-10.pdf", $seen['filename']);
        $html = $seen['html'];
        $this->assertSame(3, substr_count($html, 'class="frame"'));
        $this->assertSame(2, substr_count($html, '<pagebreak'));
        $this->assertSame(3, substr_count($html, 'class="rank"'));
        $this->assertLessThan(strpos($html, $x['b']->fullname), strpos($html, $x['a']->fullname));
        $this->assertLessThan(strpos($html, $x['g']->fullname), strpos($html, $x['b']->fullname));
        $this->assertStringNotContainsString($x['c']->fullname, $html);
        foreach (['assiduité', '1re place', '2e place', '3e place', '100.0%', '95.0%', 'U15', 'octobre 2026', 'Fait le 20/10/2026', 'entraîneur', 'Le président'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    #[Test]
    public function a_chosen_player_outside_the_podium_gets_a_certificate_without_a_place(): void
    {
        $x = $this->seed();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['c']->id]))->assertOk();

        $this->assertSame("certificate-{$x['c']->membership_id}-2026-10.pdf", $seen['filename']);
        $this->assertStringContainsString($x['c']->fullname, $seen['html']);
        $this->assertStringContainsString('80.0%', $seen['html']);
        $this->assertStringNotContainsString('class="rank"', $seen['html']);
        $this->assertSame(0, substr_count($seen['html'], '<pagebreak'));
    }

    #[Test]
    public function a_chosen_player_on_the_podium_keeps_the_place(): void
    {
        $x = $this->seed();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['b']->id]))->assertOk();

        $this->assertStringContainsString('2e place', $seen['html']);
        $this->assertSame(1, substr_count($seen['html'], 'class="frame"'));
    }

    #[Test]
    public function an_unranked_player_can_still_be_chosen(): void
    {
        $x = $this->seed();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('fr'))->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'player_id' => $x['e']->id]))->assertOk();

        $this->assertStringContainsString($x['e']->fullname, $seen['html']);
        $this->assertStringContainsString('75.0%', $seen['html']);   // 3 / 4
        $this->assertStringNotContainsString('class="rank"', $seen['html']);
    }

    #[Test]
    public function the_arabic_certificates_are_right_to_left_for_a_season(): void
    {
        $x = $this->seed();
        $seen = $this->spyPdf();

        $this->actingAs($this->userIn('ar'))
            ->get(route('attendance.certificates', ['category_id' => $x['u15']->id, 'type' => 'season', 'season' => 2026]))
            ->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertTrue($seen['landscape']);
        $this->assertSame("certificates-{$x['u15']->id}-season-2026.pdf", $seen['filename']);
        $this->assertStringContainsString('شهادة مواظبة', $seen['html']);
        $this->assertStringContainsString('المرتبة الأولى', $seen['html']);
        $this->assertStringContainsString('موسم 2026/27', $seen['html']);
    }

    #[Test]
    public function nobody_ranked_means_no_podium(): void
    {
        $x = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.certificates', ['category_id' => $x['u17']->id]))->assertNotFound();
    }

    #[Test]
    public function printing_needs_attendance_view_only(): void
    {
        $x = $this->seed();
        $this->spyPdf();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.certificates', ['category_id' => $x['u15']->id]))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.ranking` assertion added in Task 8, add:
```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.certificates'));
```

In `tests/Feature/AttendanceFollowupLinksTest.php`, add to `pdfLinks()`:
```php
            'ranking → podium' => ['Attendance/Ranking.vue', "route('attendance.certificates'", ':href="podiumHref" target="_blank"'],
            'ranking → one certificate' => ['Attendance/Ranking.vue', "route('attendance.certificates'", ':href="certificateHref(row)" target="_blank"'],
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceCertificatesPdfTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: FAIL. The output shows `Route [attendance.certificates] not defined.`, the permission test gets `edit`, and the two ranking links are missing.

- [ ] **Step 3: Route and permission override**

In `routes/web.php`, right after the `attendance.ranking` route:
```php
        Route::get('/attendance/certificates', [AttendanceRankingController::class, 'certificates'])->name('attendance.certificates');
```
In `config/permissions.php`, after `'attendance.ranking' => ['attendance', 'view'],`:
```php
        'attendance.certificates' => ['attendance', 'view'],
```

- [ ] **Step 4: The controller action**

In `app/Http/Controllers/AttendanceRankingController.php`:
- add `use App\Services\Pdf\ClubHeader;` and `use Symfony\Component\HttpFoundation\Response as FileResponse;` to the imports;
- add after the class's opening brace:
```php
    /** Certificates "Print top 3" prints; only these places are printed on a certificate. */
    public const PODIUM = 3;

```
- add after `index()`:
```php
    /**
     * Certificates of assiduity, one A4 landscape page each: the podium of
     * the category's ranking for the period, or one chosen player (with a
     * place only when on the podium). No podium without a ranked player.
     */
    public function certificates(Request $request): FileResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'player_id' => ['nullable', 'integer', 'exists:players,id'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $period = $this->period($request);
        ['rows' => $rows] = $this->ranking($category->id, $period);

        if (isset($data['player_id'])) {
            $playerId = (int) $data['player_id'];
            $row = collect($rows)->firstWhere('player_id', $playerId)
                ?? $this->names->attach([
                    $this->stats->players($period['from'], $period['to'], $category->id, $playerId)[$playerId]
                        ?? $this->stats->emptyRow($playerId),
                ])[0];
            $chosen = [$row];
            $filename = "certificate-{$row['membership_id']}-{$period['key']}.pdf";
        } else {
            $chosen = array_slice($rows, 0, self::PODIUM);
            abort_if($chosen === [], 404);
            $filename = "certificates-{$category->id}-{$period['key']}.pdf";
        }

        $html = view('pdf.attendance-certificates', [
            'club' => ClubHeader::data(),
            'category' => $category->localized_name,
            'periodLabel' => $period['label'],
            'date' => now()->format('d/m/Y'),
            'certificates' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'rank' => isset($row['rank']) && $row['rank'] <= self::PODIUM ? $row['rank'] : null,
                'score_pct' => $row['score_pct'],
            ], $chosen),
        ])->render();

        return $this->pdf->stream($html, $filename, app()->getLocale() === 'ar', true);
    }
```

- [ ] **Step 5: The view**

`resources/views/pdf/attendance-certificates.blade.php`:
```blade
@php
    $L = fn (string $key) => \App\Support\UiLang::get($key);
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #1e293b; }
        /* The decorative border: two plain CSS frames, green and gold (offline, no image). */
        table.frame { width: 100%; border: 2.5mm solid #02a85c; border-collapse: collapse; page-break-inside: avoid; }
        td.inner { border: 0.8mm solid #d4a017; height: 150mm; padding: 6mm 14mm; text-align: center; vertical-align: middle; }
        .club { font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 2mm; }
        .title { font-size: 32px; font-weight: bold; color: #02a85c; margin: 6mm 0 3mm; }
        .awarded { font-size: 14px; color: #64748b; }
        .name { font-size: 28px; font-weight: bold; color: #0f172a; margin: 3mm 0; }
        .line { font-size: 14px; margin: 2mm 0; }
        .rank { font-size: 20px; font-weight: bold; color: #b45309; margin: 4mm 0 1mm; }
        .score { font-size: 14px; margin-top: 2mm; }
        table.sign { width: 100%; margin-top: 10mm; }
        table.sign td { width: 33%; text-align: center; font-size: 12px; vertical-align: top; }
        .sigline { border-top: 1px solid #94a3b8; width: 55mm; margin: 14mm auto 0; }
    </style>
</head>
<body>
    @foreach ($certificates as $i => $certificate)
        @if ($i > 0)
            <pagebreak />
        @endif
        <table class="frame">
            <tr>
                <td class="inner">
                    @if (!empty($club['logo']))
                        <img src="{{ $club['logo'] }}" style="width:22mm; height:22mm;">
                    @endif
                    <div class="club">{{ $club['name'] }}</div>
                    <div class="title">{{ $L('att.cert.title') }}</div>
                    <div class="awarded">{{ $L('att.cert.awarded_to') }}</div>
                    <div class="name">{{ $certificate['name'] }}</div>
                    <div class="line">{{ strtr($L('att.cert.line'), ['{category}' => $category, '{period}' => $periodLabel]) }}</div>
                    @if ($certificate['rank'] !== null)
                        <div class="rank">{{ $L('att.cert.rank_'.$certificate['rank']) }}</div>
                    @endif
                    <div class="score">{{ strtr($L('att.cert.score'), ['{score}' => $pct($certificate['score_pct'])]) }}</div>
                    <table class="sign">
                        <tr>
                            <td>{{ $L('att.letter.coach') }}<div class="sigline"></div></td>
                            <td>{{ strtr($L('att.cert.date'), ['{date}' => $date]) }}</td>
                            <td>{{ $L('att.letter.president') }}<div class="sigline"></div></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @endforeach
</body>
</html>
```

- [ ] **Step 6: The buttons on the ranking page**

In `resources/js/Pages/Attendance/Ranking.vue`:
- replace `import { Head, Link, router } from '@inertiajs/vue3';` with
```js
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
```
and add `import Icon from '@/Components/Icon.vue';` after the `AuthenticatedLayout` import;
- after `const visit = ...;` add
```js
// Certificates (PDF, new tab): the podium, or one listed player.
const podiumHref = computed(() => route('attendance.certificates', query()));
const certificateHref = (row) => route('attendance.certificates', { ...query(), player_id: row.player_id });
```
- in the header, before the statistics `<Link …>`, add
```vue
                    <a v-if="rows.length" :href="podiumHref" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700"><Icon name="print" />{{ t('att.ranking.print_top3') }}</a>
```
- replace the empty last cell `<td class="whitespace-nowrap p-2 text-end"></td>` with
```vue
                            <td class="whitespace-nowrap p-2 text-end">
                                <a :href="certificateHref(row)" target="_blank" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.ranking.certificate') }}</a>
                            </td>
```

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceCertificatesPdfTest|AttendanceRankingPageTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: the build succeeds; PASS.
- If the real render gives more than 3 pages, a certificate overflowed its page. Lower `td.inner`'s `height` (or the paddings) until each certificate fits one A4 landscape page. Keep the 3-page assertion as it is.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceRankingController.php resources/views/pdf/attendance-certificates.blade.php resources/js/Pages/Attendance/Ranking.vue routes/web.php config/permissions.php tests/Feature/AttendanceCertificatesPdfTest.php tests/Feature/AttendancePermissionTest.php tests/Feature/AttendanceFollowupLinksTest.php
git commit -m "feat(attendance): certificates of assiduity for the podium or a chosen player" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Injury spells, the `injury_notes` table and its model

**Files:**
- Create: `database/migrations/2026_09_30_200001_create_injury_notes_table.php`, `app/Models/InjuryNote.php`, `app/Services/Attendance/InjurySpells.php`
- Test: `tests/Feature/AttendanceInjurySpellsTest.php` (new)

**Interfaces:**
- Consumes: `AttendanceStatus::NotTraining`, `::AbsentExcused`; `AbsenceReason::Injury`; `SessionState::Held`; `PlayerNames::attach()` (Task 2).
- Produces:
  - Table `injury_notes`:
    - columns: `id`, `player_id` (FK players, cascade on delete), `start_date` string(10), `body_part` string(60) nullable, `description` text nullable, `returned_on` string(10) nullable, `created_by` (FK users, nullable, null on delete), timestamps
    - `unique(player_id, start_date)`
    - The migration is guarded by `Schema::hasTable` and adds nothing to an existing table.
  - `App\Models\InjuryNote`:
    - `$fillable = ['player_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by']`, with no date casts;
    - `player(): BelongsTo`;
    - `toDetail(): array{id: int, start_date: string, body_part: ?string, description: ?string, returned_on: ?string}`.
  - `App\Services\Attendance\InjurySpells` (constructor `PlayerNames $names`). A spell is `array{start: string, end: string, sessions: int, open: bool}`.
    - `static isInjury(string $status, ?string $reason): bool`
    - `static fromMarks(iterable $marks): array<int, list<spell>>`:
      - The marks are objects with `player_id`, `date`, `status` and `reason`, ordered by player, date, start time and id.
      - The result is keyed by player id, oldest spell first; players without a spell are left out.
    - `all(int $playerId): list<spell>`: the whole history, oldest first, in one query.
    - `forPlayer(int $playerId, string $from, string $to): array{spells: list<spell + {note: ?array}>, unmatched: list<array>}`:
      - `spells` are the spells overlapping the period, newest first.
      - `unmatched` are all of the player's details whose `start_date` opens no spell.
      - It runs 2 queries.
    - `club(string $from, string $to, ?int $categoryId = null): array{current: list<row>, spells: list<row>}`, where a row is a spell + `{player_id, note, name, membership_id, category_id, category, active}`:
      - `current` holds the open spells, oldest start first.
      - `spells` holds the spells overlapping the period, newest start first.
      - Only active players are kept, filtered on the current category.
      - It runs 4 queries.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceInjurySpellsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Attendance\InjurySpells;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjurySpellsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function training(Category $category, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ], $extra)); // overrides win
    }

    private function mark(TrainingSession $training, Player $player, AttendanceStatus $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status,
        ], $extra));
    }

    private function spells(): InjurySpells
    {
        return app(InjurySpells::class);
    }

    /**
     * U15 X: P, B(injury), AE(injury) | AE(illness) | B(illness), B(injury) | P | AE(injury, the latest mark).
     * Y is always present, Z has no mark. A cancelled session between two injury marks never counts.
     *
     * @return array{0: Player, 1: Player, 2: Player}
     */
    private function seed(): array
    {
        $u15 = $this->category('U15');
        $x = $this->player($u15);
        $y = $this->player($u15);
        $z = $this->player($u15);
        $marks = [
            ['2026-10-01', AttendanceStatus::Present, null],
            ['2026-10-02', AttendanceStatus::NotTraining, 'injury'],
            ['2026-10-03', AttendanceStatus::AbsentExcused, 'injury'],
            ['2026-10-05', AttendanceStatus::AbsentExcused, 'illness'],   // not an injury: ends the first spell
            ['2026-10-06', AttendanceStatus::NotTraining, 'illness'],     // "not training" counts whatever the reason
            ['2026-10-07', AttendanceStatus::NotTraining, 'injury'],
            ['2026-10-09', AttendanceStatus::Present, null],
            ['2026-10-12', AttendanceStatus::AbsentExcused, 'injury'],    // the latest mark: an open spell
        ];
        foreach ($marks as [$date, $status, $reason]) {
            $training = $this->training($u15, $date);
            $this->mark($training, $x, $status, ['reason' => $reason]);
            $this->mark($training, $y, AttendanceStatus::Present);
        }
        $this->mark($this->training($u15, '2026-10-06', ['start_time' => '20:00', 'end_time' => '21:00', 'state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']), $x, AttendanceStatus::Present);

        return [$x, $y, $z];
    }

    #[Test]
    public function spells_join_consecutive_injury_marks_and_the_latest_one_is_open(): void
    {
        [$x, $y, $z] = $this->seed();

        $this->assertSame([
            ['start' => '2026-10-02', 'end' => '2026-10-03', 'sessions' => 2, 'open' => false],
            ['start' => '2026-10-06', 'end' => '2026-10-07', 'sessions' => 2, 'open' => false],
            ['start' => '2026-10-12', 'end' => '2026-10-12', 'sessions' => 1, 'open' => true],
        ], $this->spells()->all($x->id));
        $this->assertSame([], $this->spells()->all($y->id));
        $this->assertSame([], $this->spells()->all($z->id));
    }

    #[Test]
    public function the_profile_gets_the_periods_spells_newest_first_with_details_and_unmatched_ones(): void
    {
        [$x, $y] = $this->seed();
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-06', 'body_part' => 'Cheville', 'description' => 'Entorse']);
        $stray = InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);   // no spell starts on the 4th

        $all = $this->spells()->forPlayer($x->id, '2026-10-01', '2026-10-31');

        $this->assertSame(['2026-10-12', '2026-10-06', '2026-10-02'], array_column($all['spells'], 'start'));
        $this->assertNull($all['spells'][0]['note']);
        $this->assertSame('Cheville', $all['spells'][1]['note']['body_part']);
        $this->assertSame('Entorse', $all['spells'][1]['note']['description']);
        $this->assertSame([$stray->id], array_column($all['unmatched'], 'id'));
        $this->assertSame('2026-10-04', $all['unmatched'][0]['start_date']);

        // Only the spells overlapping the period; an open spell runs until today.
        $this->assertSame(['2026-10-12', '2026-10-06'], array_column($this->spells()->forPlayer($x->id, '2026-10-07', '2026-10-31')['spells'], 'start'));
        $this->assertSame(['2026-10-12'], array_column($this->spells()->forPlayer($x->id, '2026-10-15', '2026-10-31')['spells'], 'start'));
        $this->assertSame([], $this->spells()->forPlayer($x->id, '2026-10-04', '2026-10-05')['spells']);
        $this->assertSame(['spells' => [], 'unmatched' => []], $this->spells()->forPlayer($y->id, '2026-10-01', '2026-10-31'));
    }

    #[Test]
    public function editing_an_earlier_mark_moves_the_start_and_the_detail_becomes_unmatched_not_lost(): void
    {
        [$x] = $this->seed();
        $note = InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-06', 'body_part' => 'Cheville']);
        // The illness on the 5th was an injury after all: the first two spells join.
        Attendance::where('player_id', $x->id)
            ->where('training_session_id', TrainingSession::where('date', '2026-10-05')->value('id'))
            ->update(['status' => AttendanceStatus::NotTraining->value, 'reason' => 'injury']);

        $profile = $this->spells()->forPlayer($x->id, '2026-10-01', '2026-10-31');

        $this->assertSame(['2026-10-12', '2026-10-02'], array_column($profile['spells'], 'start'));
        $this->assertSame(5, $profile['spells'][1]['sessions']);
        $this->assertSame('2026-10-07', $profile['spells'][1]['end']);
        $this->assertSame([$note->id], array_column($profile['unmatched'], 'id'));
        $this->assertSame(1, InjuryNote::count());
    }

    #[Test]
    public function the_club_list_has_current_injuries_and_the_periods_spells(): void
    {
        [$x] = $this->seed();
        $u17 = $this->category('U17');
        $w = $this->player($u17);
        $this->mark($this->training($u17, '2026-09-10'), $w, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $this->mark($this->training($u17, '2026-09-12'), $w, AttendanceStatus::Present);
        $archived = $this->player($u17);
        $this->mark($this->training($u17, '2026-10-10'), $archived, AttendanceStatus::NotTraining, ['reason' => 'injury']);
        $archived->update(['archived' => true]);
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-12', 'body_part' => 'Genou']);

        $october = $this->spells()->club('2026-10-01', '2026-10-31');

        $this->assertSame([$x->id], array_column($october['current'], 'player_id'));
        $this->assertSame('2026-10-12', $october['current'][0]['start']);
        $this->assertSame($x->fullname, $october['current'][0]['name']);
        $this->assertSame('U15', $october['current'][0]['category']);
        $this->assertSame('Genou', $october['current'][0]['note']['body_part']);
        $this->assertSame(['2026-10-12', '2026-10-06', '2026-10-02'], array_column($october['spells'], 'start'));

        $september = $this->spells()->club('2026-09-01', '2026-09-30');
        $this->assertSame([$x->id], array_column($september['current'], 'player_id'));   // current = today, whatever the period
        $this->assertSame([$w->id], array_column($september['spells'], 'player_id'));

        $this->assertSame(['current' => [], 'spells' => []], $this->spells()->club('2026-10-01', '2026-10-31', $u17->id));
    }

    #[Test]
    public function the_club_list_runs_a_fixed_number_of_queries(): void
    {
        $u15 = $this->category('U15');
        $trainings = [$this->training($u15, '2026-10-05'), $this->training($u15, '2026-10-07')];
        $add = function (int $n) use ($u15, $trainings): void {
            foreach (range(1, $n) as $i) {
                $player = $this->player($u15);
                $this->mark($trainings[0], $player, AttendanceStatus::NotTraining, ['reason' => 'injury']);
                $this->mark($trainings[1], $player, AttendanceStatus::Present);
            }
        };
        $measure = function (): int {
            $spells = $this->spells();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $spells->club('2026-10-01', '2026-10-31');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $add(3);
        $few = $measure();
        $add(27);

        $this->assertSame($few, $measure());
        // The marks of injured players, their details, the players, their categories.
        $this->assertSame(4, $few);
    }

    #[Test]
    public function the_table_holds_one_detail_per_spell_start_and_its_migration_runs_again(): void
    {
        [$x] = $this->seed();
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-02']);

        try {
            InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-02']);
            $this->fail('A second detail for the same spell start must be refused.');
        } catch (QueryException) {
            $this->assertSame(1, InjuryNote::count());
        }

        // The desktop app runs `migrate` on every boot: running it on an existing table is harmless.
        (require database_path('migrations/2026_09_30_200001_create_injury_notes_table.php'))->up();

        $this->assertTrue(Schema::hasColumns('injury_notes', ['player_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by']));
        $this->assertSame(1, InjuryNote::count());
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter=AttendanceInjurySpellsTest`
Expected: FAIL. The output shows `Class "App\Models\InjuryNote" not found`.

- [ ] **Step 3: The migration**

`database/migrations/2026_09_30_200001_create_injury_notes_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Details staff add to an injury spell. Spells themselves are built from
     * the marks (InjurySpells); a detail is keyed by its player and the
     * spell's start date ('Y-m-d', like every attendance date). A new table
     * only, guarded, so a second run (every desktop boot) does nothing and
     * no existing table is touched.
     */
    public function up(): void
    {
        if (Schema::hasTable('injury_notes')) {
            return;
        }

        Schema::create('injury_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('start_date', 10);
            $table->string('body_part', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('returned_on', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['player_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('injury_notes');
    }
};
```

- [ ] **Step 4: The model**

`app/Models/InjuryNote.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Details of one injury spell (see InjurySpells), keyed by the player and
 * the spell's start date. Dates are 'Y-m-d' strings; no date casts.
 */
class InjuryNote extends Model
{
    protected $fillable = ['player_id', 'start_date', 'body_part', 'description', 'returned_on', 'created_by'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return array{id: int, start_date: string, body_part: ?string, description: ?string, returned_on: ?string} */
    public function toDetail(): array
    {
        return [
            'id' => $this->id,
            'start_date' => $this->start_date,
            'body_part' => $this->body_part,
            'description' => $this->description,
            'returned_on' => $this->returned_on,
        ];
    }
}
```

- [ ] **Step 5: The service**

`app/Services/Attendance/InjurySpells.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use App\Enums\SessionState;
use App\Models\InjuryNote;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Injury spells, built from the marks. An injury mark is "present, not
 * training" (whatever the reason) or an excused absence for injury. A
 * player's consecutive injury marks in held sessions (date, start time, id),
 * with no other mark of theirs in between, form one spell. A spell is open
 * while it holds the player's latest held mark.
 *
 * Spells always come from the player's whole history, so a spell's start
 * never depends on the period on screen and an InjuryNote keyed by it stays
 * attached. A detail whose date no longer opens a spell (an earlier mark was
 * edited) is reported as unmatched, never dropped.
 */
final class InjurySpells
{
    public function __construct(private readonly PlayerNames $names) {}

    public static function isInjury(string $status, ?string $reason): bool
    {
        return $status === AttendanceStatus::NotTraining->value
            || ($status === AttendanceStatus::AbsentExcused->value && $reason === AbsenceReason::Injury->value);
    }

    /**
     * @param  iterable<object>  $marks  rows with player_id, date, status, reason, ordered by player, date, start time, id
     * @return array<int, list<array{start: string, end: string, sessions: int, open: bool}>> by player id, oldest first
     */
    public static function fromMarks(iterable $marks): array
    {
        $spells = [];
        $running = []; // player id => index of the spell the next injury mark extends
        foreach ($marks as $mark) {
            $id = (int) $mark->player_id;
            if (! self::isInjury($mark->status, $mark->reason)) {
                unset($running[$id]);

                continue;
            }
            if (! isset($running[$id])) {
                $spells[$id][] = ['start' => $mark->date, 'end' => $mark->date, 'sessions' => 0, 'open' => false];
                $running[$id] = array_key_last($spells[$id]);
            }
            $spells[$id][$running[$id]]['end'] = $mark->date;
            $spells[$id][$running[$id]]['sessions']++;
        }
        // A spell still running after the player's last mark holds that mark: it is open.
        foreach ($running as $id => $index) {
            $spells[$id][$index]['open'] = true;
        }

        return $spells;
    }

    /** @return list<array{start: string, end: string, sessions: int, open: bool}> the player's whole history, oldest first */
    public function all(int $playerId): array
    {
        return self::fromMarks($this->marks()->where('attendances.player_id', $playerId)->cursor())[$playerId] ?? [];
    }

    /**
     * The profile's injuries: the spells overlapping [from, to], newest first,
     * each with its detail (or null), and every detail of the player that
     * opens no spell. Two queries.
     *
     * @return array{spells: list<array<string, mixed>>, unmatched: list<array<string, mixed>>}
     */
    public function forPlayer(int $playerId, string $from, string $to): array
    {
        $spells = $this->all($playerId);
        $notes = InjuryNote::where('player_id', $playerId)->orderBy('start_date')->get();
        $byStart = $notes->keyBy('start_date');
        $starts = array_column($spells, 'start');

        $shown = [];
        foreach (array_reverse($spells) as $spell) {
            if (self::overlaps($spell, $from, $to)) {
                $shown[] = [...$spell, 'note' => $byStart->get($spell['start'])?->toDetail()];
            }
        }

        return [
            'spells' => $shown,
            'unmatched' => $notes->reject(fn (InjuryNote $note) => in_array($note->start_date, $starts, true))
                ->map(fn (InjuryNote $note) => $note->toDetail())->values()->all(),
        ];
    }

    /**
     * The club list. `current`: every open spell, oldest start first (today,
     * whatever the period). `spells`: the spells overlapping [from, to],
     * newest start first. Active players only (not archived, not left);
     * $categoryId keeps those now in that category. Four queries whatever
     * the roster: the marks of players with an injury mark, their details,
     * the players, their categories.
     *
     * @return array{current: list<array<string, mixed>>, spells: list<array<string, mixed>>}
     */
    public function club(string $from, string $to, ?int $categoryId = null): array
    {
        $byPlayer = self::fromMarks($this->marks()->whereIn('attendances.player_id', $this->injuredPlayers())->cursor());
        if ($byPlayer === []) {
            return ['current' => [], 'spells' => []];
        }
        $notes = InjuryNote::whereIn('player_id', array_keys($byPlayer))->get()
            ->keyBy(fn (InjuryNote $note) => $note->player_id.'|'.$note->start_date);

        $rows = [];
        foreach ($byPlayer as $playerId => $spells) {
            foreach ($spells as $spell) {
                if ($spell['open'] || self::overlaps($spell, $from, $to)) {
                    $rows[] = ['player_id' => $playerId, ...$spell, 'note' => $notes->get($playerId.'|'.$spell['start'])?->toDetail()];
                }
            }
        }
        $rows = array_values(array_filter(
            $this->names->attach($rows),
            fn (array $row) => $row['active'] && ($categoryId === null || $row['category_id'] === $categoryId),
        ));

        $current = array_values(array_filter($rows, fn (array $row) => $row['open']));
        usort($current, fn (array $a, array $b) => [$a['start'], $a['name']] <=> [$b['start'], $b['name']]);
        $inPeriod = array_values(array_filter($rows, fn (array $row) => self::overlaps($row, $from, $to)));
        usort($inPeriod, fn (array $a, array $b) => [$b['start'], $a['name']] <=> [$a['start'], $b['name']]);

        return ['current' => $current, 'spells' => $inPeriod];
    }

    /** A spell overlaps [from, to] when it starts by $to and ends on $from or later; an open spell runs until today. */
    private static function overlaps(array $spell, string $from, string $to): bool
    {
        return $spell['start'] <= $to && ($spell['open'] || $spell['end'] >= $from);
    }

    /** Held-session marks in spell order: player, date, start time, session id. */
    private function marks(): Builder
    {
        return DB::table('attendances')
            ->join('training_sessions', 'training_sessions.id', '=', 'attendances.training_session_id')
            ->where('training_sessions.state', SessionState::Held->value)
            ->orderBy('attendances.player_id')
            ->orderBy('training_sessions.date')
            ->orderBy('training_sessions.start_time')
            ->orderBy('training_sessions.id')
            ->select('attendances.player_id', 'attendances.status', 'attendances.reason', 'training_sessions.date');
    }

    /** Players with at least one injury mark in a held session (a subquery). */
    private function injuredPlayers(): Builder
    {
        return DB::table('attendances as a')
            ->join('training_sessions as s', 's.id', '=', 'a.training_session_id')
            ->where('s.state', SessionState::Held->value)
            ->where(fn (Builder $q) => $q->where('a.status', AttendanceStatus::NotTraining->value)
                ->orWhere(fn (Builder $q) => $q->where('a.status', AttendanceStatus::AbsentExcused->value)
                    ->where('a.reason', AbsenceReason::Injury->value)))
            ->select('a.player_id');
    }
}
```

- [ ] **Step 6: Migrate and run the tests to see them pass**

Run: `php artisan migrate && php artisan migrate && php artisan test --filter=AttendanceInjurySpellsTest`
Expected: the first `migrate` creates `injury_notes`, and the second prints `Nothing to migrate.`; PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_30_200001_create_injury_notes_table.php app/Models/InjuryNote.php app/Services/Attendance/InjurySpells.php tests/Feature/AttendanceInjurySpellsTest.php
git commit -m "feat(attendance): injury spells from the marks, with an injury_notes table for details" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Injuries on the profile, with the details modal

**Files:**
- Create: `app/Http/Controllers/AttendanceInjuryController.php`, `resources/js/Pages/Players/Partials/InjuryList.vue`
- Modify: `app/Http/Controllers/AttendancePlayerController.php`, `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Players/Partials/AttendanceSection.vue`, `tests/Feature/AttendancePermissionTest.php`
- Test: `tests/Feature/AttendanceInjuryNotesTest.php` (new)

**Interfaces:**
- Consumes: `InjurySpells::all()`, `::forPlayer()` (Task 10); `InjuryNote::toDetail()`; the `att.injury.*` keys.
- Produces:
  - The `attendance.players.show` JSON gains `injuries: InjurySpells::forPlayer($player->id, $from, $to)` for the card's period. It is one fetch with the rest of the card: no second request. `AttendancePlayerController` gets `InjurySpells $injuries` injected.
  - `AttendanceInjuryController` (constructor `InjurySpells $spells`) with the detail rules `body_part` nullable|string|max:60, `description` nullable|string|max:2000, and `returned_on` nullable, `Y-m-d`, on or after the start. Its actions:
    - `store(Request, Player): JsonResponse` — `POST /attendance/players/{player}/injury-notes`, name `attendance.injury-notes.store`, permission `['attendance', 'edit']` (override; derived would be add).
      - Body: `start_date` (required, `Y-m-d`, must open one of the player's spells, otherwise 422 `start_date: att.injury.error.no_spell`) plus the detail fields.
      - It creates or updates the detail for that (player, start). A missing field is stored as null.
      - It answers `201 {note}` when created and `200 {note}` when updated. `created_by` is set on create only.
    - `update(Request, InjuryNote $note): JsonResponse` — `PUT /attendance/injury-notes/{note}`, name `attendance.injury-notes.update`, permission edit (derived). It works for matched and unmatched details and answers `200 {note}`.
    - `destroy(InjuryNote $note)` — `DELETE /attendance/injury-notes/{note}`, name `attendance.injury-notes.destroy`, permission `['attendance', 'edit']` (override; derived would be delete). It answers 204.
  - `resources/js/Pages/Players/Partials/InjuryList.vue` (props `playerId: Number`, `injuries: {spells, unmatched}`; emits `saved`).
    - It shows the timeline and the unmatched details.
    - With `attendance/edit` it has an add/edit details modal (axios POST/PUT) and deletes unmatched details (axios DELETE).
    - `AttendanceSection` renders it and reloads the card (same period) on `saved`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceInjuryNotesTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjuryNotesTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function mark(Category $category, string $date, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        $training = TrainingSession::create([
            'category_id' => $category->id, 'date' => $date, 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status, 'reason' => $reason,
        ]);
    }

    /** Spells: 2026-10-02 → 03 (closed), 2026-10-12 (open). */
    private function seedPlayer(): Player
    {
        $u15 = $this->category('U15');
        $player = $this->player($u15);
        $this->mark($u15, '2026-10-02', $player, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-10-03', $player, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u15, '2026-10-05', $player, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-12', $player, AttendanceStatus::NotTraining, 'injury');

        return $player;
    }

    #[Test]
    public function a_detail_is_added_to_a_spell_and_saving_again_updates_it(): void
    {
        $player = $this->seedPlayer();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('attendance.injury-notes.store', $player), [
                'start_date' => '2026-10-02', 'body_part' => 'Cheville', 'description' => "Entorse\nrepos 2 semaines", 'returned_on' => '2026-10-05',
            ])
            ->assertCreated()
            ->assertJsonPath('note.start_date', '2026-10-02')
            ->assertJsonPath('note.body_part', 'Cheville')
            ->assertJsonPath('note.returned_on', '2026-10-05');
        $this->assertSame($admin->id, InjuryNote::sole()->created_by);

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-02', 'body_part' => 'Genou'])
            ->assertOk()
            ->assertJsonPath('note.body_part', 'Genou');
        $note = InjuryNote::sole();
        $this->assertNull($note->description);
        $this->assertNull($note->returned_on);
        $this->assertSame($admin->id, $note->created_by);   // kept from the creation
    }

    #[Test]
    public function a_detail_needs_a_spell_start_and_a_return_after_it(): void
    {
        $player = $this->seedPlayer();

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-03', 'body_part' => 'Cheville'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => 'att.injury.error.no_spell']);

        $this->actingAs($this->admin())
            ->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12', 'returned_on' => '2026-10-01', 'body_part' => str_repeat('x', 61)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['returned_on', 'body_part']);

        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function an_unmatched_detail_can_be_edited_and_deleted(): void
    {
        $player = $this->seedPlayer();
        $note = InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);   // opens no spell
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('attendance.injury-notes.update', $note), ['body_part' => 'Dos', 'description' => 'Contracture', 'returned_on' => '2026-10-10'])
            ->assertOk()
            ->assertJsonPath('note.body_part', 'Dos')
            ->assertJsonPath('note.returned_on', '2026-10-10');

        $this->actingAs($admin)
            ->putJson(route('attendance.injury-notes.update', $note), ['returned_on' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['returned_on']);

        $this->actingAs($admin)->deleteJson(route('attendance.injury-notes.destroy', $note))->assertNoContent();
        $this->assertSame(0, InjuryNote::count());
    }

    #[Test]
    public function the_profile_card_carries_the_injuries_of_its_period(): void
    {
        $player = $this->seedPlayer();
        InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-02', 'body_part' => 'Cheville']);
        InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-04', 'body_part' => 'Genou']);

        $this->actingAs($this->admin())->getJson(route('attendance.players.show', $player))
            ->assertOk()
            ->assertJsonCount(2, 'injuries.spells')
            ->assertJsonPath('injuries.spells.0.start', '2026-10-12')
            ->assertJsonPath('injuries.spells.0.open', true)
            ->assertJsonPath('injuries.spells.0.note', null)
            ->assertJsonPath('injuries.spells.1.start', '2026-10-02')
            ->assertJsonPath('injuries.spells.1.end', '2026-10-03')
            ->assertJsonPath('injuries.spells.1.sessions', 2)
            ->assertJsonPath('injuries.spells.1.note.body_part', 'Cheville')
            ->assertJsonCount(1, 'injuries.unmatched')
            ->assertJsonPath('injuries.unmatched.0.start_date', '2026-10-04');
    }

    #[Test]
    public function view_only_users_see_the_injuries_but_cannot_change_details(): void
    {
        $player = $this->seedPlayer();
        $note = InjuryNote::create(['player_id' => $player->id, 'start_date' => '2026-10-02', 'body_part' => 'Cheville']);
        $viewer = $this->userWith(['attendance' => ['view']]);
        $adder = $this->userWith(['attendance' => ['view', 'add', 'delete']]);   // add/delete are not enough: details need edit

        $this->actingAs($viewer)->getJson(route('attendance.players.show', $player))->assertOk()->assertJsonPath('injuries.spells.1.note.body_part', 'Cheville');
        $this->actingAs($viewer)->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12'])->assertForbidden();
        $this->actingAs($adder)->postJson(route('attendance.injury-notes.store', $player), ['start_date' => '2026-10-12'])->assertForbidden();
        $this->actingAs($viewer)->putJson(route('attendance.injury-notes.update', $note), ['body_part' => 'Dos'])->assertForbidden();
        $this->actingAs($adder)->deleteJson(route('attendance.injury-notes.destroy', $note))->assertForbidden();
        $this->assertSame('Cheville', $note->fresh()->body_part);
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the `attendance.certificates` assertion added in Task 9, add:
```php
        // Injury details are edited, never just added or deleted: all three writes need edit.
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.store'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.update'));
        $this->assertSame(['attendance', 'edit'], PermissionMap::resolve('attendance.injury-notes.destroy'));
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceInjuryNotesTest|AttendancePermissionTest"`
Expected: FAIL. The output shows `Route [attendance.injury-notes.store] not defined.`, the missing `injuries` path in the card, and `add`/`delete` for the store/destroy names.

- [ ] **Step 3: Routes and permission overrides**

In `routes/web.php`, add the import `use App\Http\Controllers\AttendanceInjuryController;` next to the other attendance controllers. In the attendance block, right after the `attendance.players.letter` route, add:
```php
        // Details of an injury spell (spells are built from the marks); all writes need attendance/edit.
        Route::post('/attendance/players/{player}/injury-notes', [AttendanceInjuryController::class, 'store'])->name('attendance.injury-notes.store');
        Route::put('/attendance/injury-notes/{note}', [AttendanceInjuryController::class, 'update'])->name('attendance.injury-notes.update');
        Route::delete('/attendance/injury-notes/{note}', [AttendanceInjuryController::class, 'destroy'])->name('attendance.injury-notes.destroy');
```
In `config/permissions.php`, after `'attendance.certificates' => ['attendance', 'view'],`:
```php
        // Injury details are edited, never just added or deleted: every write needs edit.
        'attendance.injury-notes.store' => ['attendance', 'edit'],
        'attendance.injury-notes.destroy' => ['attendance', 'edit'],
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/AttendanceInjuryController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\InjuryNote;
use App\Models\Player;
use App\Services\Attendance\InjurySpells;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injury details (injury_notes) for the spells InjurySpells builds from the
 * marks, and (Task 12) the club's injuries list. The detail writes answer
 * JSON: the profile's attendance card calls them and reloads itself.
 */
class AttendanceInjuryController extends Controller
{
    private const DETAIL_RULES = [
        'body_part' => ['nullable', 'string', 'max:60'],
        'description' => ['nullable', 'string', 'max:2000'],
    ];

    public function __construct(private readonly InjurySpells $spells) {}

    /** Adds the details of the spell starting on `start_date`, or replaces them if it has some. */
    public function store(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            ...self::DETAIL_RULES,
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        if (! in_array($data['start_date'], array_column($this->spells->all($player->id), 'start'), true)) {
            throw ValidationException::withMessages(['start_date' => 'att.injury.error.no_spell']);
        }

        $note = InjuryNote::firstOrNew(['player_id' => $player->id, 'start_date' => $data['start_date']]);
        $note->fill(self::details($data));
        if (! $note->exists) {
            $note->created_by = $request->user()?->id;
        }
        $note->save();

        return response()->json(['note' => $note->toDetail()], $note->wasRecentlyCreated ? 201 : 200);
    }

    /** Edits a detail in place, matched to a spell or not (its start date never changes). */
    public function update(Request $request, InjuryNote $note): JsonResponse
    {
        $data = $request->validate([
            ...self::DETAIL_RULES,
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$note->start_date],
        ]);
        $note->update(self::details($data));

        return response()->json(['note' => $note->toDetail()]);
    }

    public function destroy(InjuryNote $note): Response
    {
        $note->delete();

        return response()->noContent();
    }

    /** The three detail fields; a field left out is cleared, as the modal always sends all three. */
    private static function details(array $data): array
    {
        return [
            'body_part' => $data['body_part'] ?? null,
            'description' => $data['description'] ?? null,
            'returned_on' => $data['returned_on'] ?? null,
        ];
    }
}
```

- [ ] **Step 5: The profile JSON**

In `app/Http/Controllers/AttendancePlayerController.php`:
- add `use App\Services\Attendance\InjurySpells;` to the imports;
- add `private readonly InjurySpells $injuries,` as the constructor's last parameter;
- in `show()`, after the `'risk' => ...` line, add
```php
            // The card's Injuries section: spells overlapping the period, with details, and unmatched details.
            'injuries' => $this->injuries->forPlayer($player->id, $from, $to),
```

- [ ] **Step 6: The Injuries section**

`resources/js/Pages/Players/Partials/InjuryList.vue`:
```vue
<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useCan } from '@/Composables/useCan';
import { parseDay } from '@/lib/attendanceCalendar';

/**
 * The profile's injury spells (InjurySpells on the server), built from the
 * marks, with the details staff added. attendance/edit can add or edit a
 * spell's details in a small modal; details whose spell no longer exists
 * are listed apart, to edit or delete, never lost.
 */
const props = defineProps({
    playerId: { type: Number, required: true },
    injuries: { type: Object, required: true }, // { spells: [{ start, end, sessions, open, note }], unmatched: [note] }
});
const emit = defineEmits(['saved']);
const { t, locale } = useI18n();
const { can } = useCan();
const canEdit = computed(() => can('attendance', 'edit'));

// The spell dates with the year: a spell can reach back into a previous season.
const day = (key) => parseDay(key).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });

const editing = ref(null); // { id, start_date, body_part, description, returned_on }
const errors = ref({});
const saving = ref(false);

function open(startDate, note) {
    errors.value = {};
    editing.value = {
        id: note?.id ?? null,
        start_date: startDate,
        body_part: note?.body_part ?? '',
        description: note?.description ?? '',
        returned_on: note?.returned_on ?? '',
    };
}
const close = () => { editing.value = null; };
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const errorOf = (key) => tr(errors.value[key]?.[0]);

async function save() {
    const e = editing.value;
    const details = { body_part: e.body_part || null, description: e.description || null, returned_on: e.returned_on || null };
    saving.value = true;
    errors.value = {};
    try {
        if (e.id) await window.axios.put(route('attendance.injury-notes.update', e.id), details);
        else await window.axios.post(route('attendance.injury-notes.store', props.playerId), { start_date: e.start_date, ...details });
        close();
        emit('saved');
    } catch (error) {
        errors.value = error.response?.status === 422 ? error.response.data.errors : { form: [t('att.injury.save_error')] };
    } finally {
        saving.value = false;
    }
}

async function remove(note) {
    if (!window.confirm(t('att.confirm_delete'))) return;
    await window.axios.delete(route('attendance.injury-notes.destroy', note.id));
    emit('saved');
}

const input = 'mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const smallButton = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-200 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-800 dark:hover:bg-primary-500/10';
</script>

<template>
    <div>
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.injury.title') }}</h4>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('att.injury.help') }}</p>

        <p v-if="!injuries.spells.length" class="py-3 text-sm text-slate-500">{{ t('att.injury.none') }}</p>
        <ol v-else class="mt-2 space-y-2">
            <li v-for="spell in injuries.spells" :key="spell.start" class="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ day(spell.start) }}</span>
                    <span class="text-slate-400 rtl:rotate-180">→</span>
                    <span>{{ spell.open ? '…' : day(spell.end) }}</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="spell.open ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                    >{{ spell.open ? t('att.injury.open') : t('att.injury.closed') }}</span>
                    <span class="text-xs text-slate-500">{{ t('att.injury.col.sessions') }}: {{ spell.sessions }}</span>
                    <button v-if="canEdit" type="button" :class="[smallButton, 'ms-auto']" @click="open(spell.start, spell.note)">
                        <Icon name="pencil" />{{ spell.note ? t('att.injury.edit_details') : t('att.injury.add_details') }}
                    </button>
                </div>
                <div v-if="spell.note" class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    <p><b v-if="spell.note.body_part">{{ spell.note.body_part }}</b></p>
                    <p v-if="spell.note.description" class="whitespace-pre-line">{{ spell.note.description }}</p>
                    <p v-if="spell.note.returned_on" class="text-xs text-slate-500">{{ t('att.injury.col.returned_on') }}: {{ day(spell.note.returned_on) }}</p>
                </div>
            </li>
        </ol>

        <div v-if="injuries.unmatched.length" class="mt-3 rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-900">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ t('att.injury.unmatched') }}</p>
            <p class="text-xs text-amber-800 dark:text-amber-300">{{ t('att.injury.unmatched_help') }}</p>
            <ul class="mt-2 space-y-1">
                <li v-for="note in injuries.unmatched" :key="note.id" class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ day(note.start_date) }}</span>
                    <span v-if="note.body_part">— {{ note.body_part }}</span>
                    <span v-if="canEdit" class="ms-auto inline-flex gap-1">
                        <button type="button" :class="smallButton" @click="open(note.start_date, note)"><Icon name="pencil" />{{ t('att.edit') }}</button>
                        <button type="button" class="inline-flex items-center rounded-md p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="remove(note)"><Icon name="trash" /></button>
                    </span>
                </li>
            </ul>
        </div>

        <Modal :show="editing !== null" max-width="md" @close="close">
            <form v-if="editing" class="space-y-3 p-5" @submit.prevent="save">
                <h3 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.injury.modal_title', { date: day(editing.start_date) }) }}</h3>
                <label class="block text-sm">{{ t('att.injury.col.body_part') }}
                    <input v-model="editing.body_part" type="text" maxlength="60" :class="input" />
                </label>
                <InputError :message="errorOf('body_part')" />
                <label class="block text-sm">{{ t('att.injury.col.description') }}
                    <textarea v-model="editing.description" rows="3" maxlength="2000" :class="input"></textarea>
                </label>
                <InputError :message="errorOf('description')" />
                <label class="block text-sm">{{ t('att.injury.col.returned_on') }}
                    <input v-model="editing.returned_on" type="date" :min="editing.start_date" :class="input" />
                </label>
                <InputError :message="errorOf('returned_on')" />
                <InputError :message="errorOf('start_date')" />
                <InputError :message="errorOf('form')" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="close">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
```

In `resources/js/Pages/Players/Partials/AttendanceSection.vue`:
- add `import InjuryList from './InjuryList.vue';` after the `Icon` import;
- inside `<div v-else-if="data" …>`, after the closing `</template>` of `<template v-else>` (so it shows even in a period without marks: an unmatched detail or an open spell can still be there), add
```vue

            <InjuryList v-if="data.injuries" :player-id="player.id" :injuries="data.injuries" @saved="retry" />
```
`retry()` reloads the card with its current period (`load(lastQuery.value)`).

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceInjuryNotesTest|AttendanceInjurySpellsTest|AttendancePermissionTest|AttendancePlayerCardTest"`
Expected: the build succeeds; PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceInjuryController.php app/Http/Controllers/AttendancePlayerController.php routes/web.php config/permissions.php resources/js/Pages/Players/Partials/InjuryList.vue resources/js/Pages/Players/Partials/AttendanceSection.vue tests/Feature/AttendanceInjuryNotesTest.php tests/Feature/AttendancePermissionTest.php
git commit -m "feat(attendance): injury spells on the profile with editable details" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: The club injuries list

**Files:**
- Create: `resources/js/Pages/Attendance/Injuries.vue`
- Modify: `app/Http/Controllers/AttendanceInjuryController.php`, `routes/web.php`, `config/permissions.php`, `resources/js/Pages/Attendance/Stats.vue`, `tests/Feature/AttendancePermissionTest.php`, `tests/Feature/AttendanceFollowupLinksTest.php`
- Test: `tests/Feature/AttendanceInjuriesPageTest.php` (new)

**Interfaces:**
- Consumes: `InjurySpells::club()` (Task 10); `ActivityPeriod::fromRequestOrSeason()` (Task 4).
- Produces:
  - Route `GET /attendance/injuries?[period…][&category_id]`, name `attendance.injuries`, permission `['attendance', 'view']` (override).
  - `AttendanceInjuryController::index(Request): Inertia\Response` renders the page `Attendance/Injuries` with props:
    - `period` (default: the current season), `categoryId` (?int), `categories` (`list<{id, name}>`)
    - `current` and `spells`: `InjurySpells::club()` rows, each `{player_id, name, category, start, end, sessions, open, note}`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceInjuriesPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\InjuryNote;
use App\Models\Player;
use App\Models\Role;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceInjuriesPageTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');   // season 2026/27
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    private function mark(Category $category, string $date, Player $player, AttendanceStatus $status, ?string $reason = null): void
    {
        $training = TrainingSession::firstOrCreate(
            ['category_id' => $category->id, 'date' => $date, 'start_time' => '18:00'],
            ['end_time' => '19:30', 'kind' => SessionKind::Regular, 'state' => SessionState::Held],
        );
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $player->id,
            'category_id' => $player->category_id, 'status' => $status, 'reason' => $reason,
        ]);
    }

    /**
     * U15 X: a spell 2026-10-02 → 03, then one open since 2026-10-12 (with a detail).
     * U17 W: a spell on 2026-09-10, over. Last season, X: a spell in May 2026.
     *
     * @return array{0: Player, 1: Player}
     */
    private function seed(): array
    {
        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $x = $this->player($u15);
        $w = $this->player($u17);
        $this->mark($u15, '2026-05-04', $x, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-05-06', $x, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-02', $x, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u15, '2026-10-03', $x, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u15, '2026-10-05', $x, AttendanceStatus::Present);
        $this->mark($u15, '2026-10-12', $x, AttendanceStatus::AbsentExcused, 'injury');
        $this->mark($u17, '2026-09-10', $w, AttendanceStatus::NotTraining, 'injury');
        $this->mark($u17, '2026-09-12', $w, AttendanceStatus::Present);
        InjuryNote::create(['player_id' => $x->id, 'start_date' => '2026-10-12', 'body_part' => 'Genou']);

        return [$x, $w];
    }

    #[Test]
    public function the_page_lists_current_injuries_and_this_seasons_spells(): void
    {
        [$x, $w] = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.injuries'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Injuries')
                ->where('period.period', 'season')
                ->where('categoryId', null)
                ->has('categories', 2)
                ->has('current', 1)
                ->where('current.0.player_id', $x->id)
                ->where('current.0.name', $x->fullname)
                ->where('current.0.category', 'U15')
                ->where('current.0.start', '2026-10-12')
                ->where('current.0.open', true)
                ->where('current.0.note.body_part', 'Genou')
                ->has('spells', 3)
                ->where('spells.0.start', '2026-10-12')
                ->where('spells.1.start', '2026-10-02')
                ->where('spells.1.sessions', 2)
                ->where('spells.2.player_id', $w->id)
                ->where('spells.2.start', '2026-09-10'));
    }

    #[Test]
    public function a_category_and_a_period_narrow_the_lists(): void
    {
        [$x, $w] = $this->seed();

        $this->actingAs($this->admin())->get(route('attendance.injuries', ['category_id' => $w->category_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('current', 0)
                ->has('spells', 1)
                ->where('spells.0.player_id', $w->id));

        $this->actingAs($this->admin())->get(route('attendance.injuries', ['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('current', 1)            // current injuries do not depend on the period
                ->has('spells', 1)
                ->where('spells.0.player_id', $x->id)
                ->where('spells.0.start', '2026-05-04'));
    }

    #[Test]
    public function the_page_needs_attendance_view_only(): void
    {
        $this->seed();

        $this->actingAs($this->userWith(['attendance' => ['view']]))->get(route('attendance.injuries'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('attendance.injuries'))->assertForbidden();
    }
}
```

In `tests/Feature/AttendancePermissionTest.php`, after the three `attendance.injury-notes.*` assertions added in Task 11, add:
```php
        $this->assertSame(['attendance', 'view'], PermissionMap::resolve('attendance.injuries'));
```

In `tests/Feature/AttendanceFollowupLinksTest.php`, add to `pageLinks()`:
```php
            'stats → injuries' => ['Attendance/Stats.vue', "route('attendance.injuries')"],
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --filter="AttendanceInjuriesPageTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: FAIL. The output shows `Route [attendance.injuries] not defined.`, the permission test gets `edit`, and the link is missing.

- [ ] **Step 3: Route and permission override**

In `routes/web.php`, right after the `attendance.certificates` route:
```php
        // Follow-up: the club's injuries (current and in the period).
        Route::get('/attendance/injuries', [AttendanceInjuryController::class, 'index'])->name('attendance.injuries');
```
In `config/permissions.php`, after `'attendance.certificates' => ['attendance', 'view'],`:
```php
        'attendance.injuries' => ['attendance', 'view'],
```

- [ ] **Step 4: The controller action**

In `app/Http/Controllers/AttendanceInjuryController.php`:
- add to the imports:
```php
use App\Models\Category;
use App\Services\Activity\ActivityPeriod;
use Inertia\Inertia;
use Inertia\Response as PageResponse;
```
- add before `store()`:
```php
    /**
     * The club's injuries (`attendance.injuries`, view): who is injured now,
     * and every spell in the period (default: the current season), for
     * active players, optionally those now in one category.
     */
    public function index(Request $request): PageResponse
    {
        $validated = $request->validate(['category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $period = ActivityPeriod::fromRequestOrSeason($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return Inertia::render('Attendance/Injuries', [
            'period' => $period->toArray(),
            'categoryId' => $categoryId,
            'categories' => Category::orderBy('id')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->localized_name])
                ->values()->all(),
            ...$this->spells->club($from, $to, $categoryId),
        ]);
    }

```

- [ ] **Step 5: The page**

`resources/js/Pages/Attendance/Injuries.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import { useCan } from '@/Composables/useCan';
import { parseDay } from '@/lib/attendanceCalendar';
import { periodQuery } from '@/lib/attendanceStats';

/**
 * The club's injuries (InjurySpells on the server): who is injured now,
 * whatever the period, and every spell in the period (the current season
 * by default), optionally for one category (the players' current one).
 * Details are added on each player's profile.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    current: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
function pickCategory(value) {
    router.get(route('attendance.injuries'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
const day = (key) => parseDay(key).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });
const details = (row) => [row.note?.body_part, row.note?.description].filter(Boolean).join(' — ');

const sections = computed(() => [
    { key: 'current', title: t('att.injury.current'), rows: props.current, empty: t('att.injury.none_current') },
    { key: 'spells', title: t('att.injury.in_period'), rows: props.spells, empty: t('att.injury.none') },
]);

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.injury.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.injury.title') }}</h1>
                <Link :href="route('attendance.stats')" :class="[linkButton, 'print:hidden']">{{ t('att.statistics') }}</Link>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.injuries')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.injury.help') }}</p>

            <section v-for="section in sections" :key="section.key" :class="[card, 'overflow-x-auto']">
                <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ section.title }}</h2>
                <p v-if="!section.rows.length" class="p-6 text-center text-sm text-slate-500">{{ section.empty }}</p>
                <table v-else class="mt-2 w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.category') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.start') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.end') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.injury.col.sessions') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.state') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in section.rows" :key="`${row.player_id}-${row.start}`" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                            <td class="whitespace-nowrap p-2">{{ day(row.start) }}</td>
                            <td class="whitespace-nowrap p-2">{{ row.open ? '…' : day(row.end) }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.sessions }}</td>
                            <td class="p-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="row.open ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                                >{{ row.open ? t('att.injury.open') : t('att.injury.closed') }}</span>
                            </td>
                            <td class="max-w-[20rem] truncate p-2 text-slate-600 dark:text-slate-300" :title="details(row)">{{ details(row) || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Link from the statistics page**

In `resources/js/Pages/Attendance/Stats.vue`, replace
```vue
                    <Link :href="route('attendance.ranking')" :class="linkButton">{{ t('att.ranking.title') }}</Link>
```
with
```vue
                    <Link :href="route('attendance.ranking')" :class="linkButton">{{ t('att.ranking.title') }}</Link>
                    <Link :href="route('attendance.injuries')" :class="linkButton">{{ t('att.injury.title') }}</Link>
```

- [ ] **Step 7: Build and run the tests to see them pass**

Run: `npm run build && php artisan test --filter="AttendanceInjuriesPageTest|AttendancePermissionTest|AttendanceFollowupLinksTest"`
Expected: the build succeeds; PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AttendanceInjuryController.php resources/js/Pages/Attendance/Injuries.vue resources/js/Pages/Attendance/Stats.vue routes/web.php config/permissions.php tests/Feature/AttendanceInjuriesPageTest.php tests/Feature/AttendancePermissionTest.php tests/Feature/AttendanceFollowupLinksTest.php
git commit -m "feat(attendance): club injuries list (current and in the period)" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Full verification

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all green. Fix any failure in the task that caused it, and commit the fix with the explicit paths it touched.

- [ ] **Step 2: Style**

Run: `vendor/bin/pint --dirty`, then `git status --short`.
If pint changed files, stage exactly those paths and commit:
```bash
git add <the files pint changed>
git commit -m "style(attendance): pint" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 3: Frontend build and translations**

Run: `npm run build && npm run i18n:check`
Expected: the build succeeds with no missing-import warnings; the check prints no `does not resolve` line. Then:
```bash
git diff main -U0 -- resources/js/i18n | grep '^-[^-]' | wc -l
```
Expected: `3` (in each file only the previous last line changed, by gaining a comma). `git status --short` shows no leftover `att-i18n.mjs`.

- [ ] **Step 4: The migration runs twice**

Run:
```bash
php artisan migrate && php artisan migrate
git diff main --stat -- database/migrations
```
Expected:
- the second `migrate` prints `Nothing to migrate.`;
- the diff lists only `2026_09_30_200001_create_injury_notes_table.php`.

Then prove the guard on a fresh desktop-like database, on which the table exists but the migration's row is missing:
```bash
php artisan tinker --execute="DB::table('migrations')->where('migration', '2026_09_30_200001_create_injury_notes_table')->delete();"
php artisan migrate
```
Expected: the migration runs again without error (the `hasTable` guard) and records itself.

- [ ] **Step 5: Nothing stray**

Run: `git status --short`
Expected: only untracked files that were there before (e.g. `.superpowers/`, `.claude/`). Nothing from this plan is left unstaged.

- [ ] **Step 6: Manual check, in Arabic and in French**

Serve the worktree (`php artisan serve --port=2027`). Prepare:
- two categories, one with about 30 players and five or more held sessions this season;
- one player with 3 unexcused absences in a row, one with a low score, one archived player who would be at risk, one player who left;
- an emergency contact on one of the at-risk players;
- one player with "present, not training" and "excused (injury)" marks in a row, then present, then injured again (an open spell);
- a user with only attendance/view (and players/view).

Go through the list twice, with the interface in **ar**, then in **fr**:
1. **Statistics page.** The header links to "Players at risk", "Attendance ranking" and "Injuries". The player table has a letter icon per row that opens a PDF in a new tab for the same period.
2. **Players at risk.**
   - The default period is the season. The two rules show with their thresholds (set one to 0 in Settings and see "off").
   - The archived and departed players are absent. The category filter uses the current category.
   - Columns: score % red when low, the longest streak red when flagged, the last session date, reason chips.
   - The letter and profile buttons work.
   - XLSX and CSV exports open with the same rows and translated headers.
3. **Dashboard, members tab.** The attendance card shows "At risk this season", the count, the 5 worst with a link to each profile, and "See all" opens the page. A user without attendance/view sees no card.
4. **Profile, attendance card.**
   - The red banner shows for the at-risk player, with the score and/or streak lines. It disappears when the period is changed to one without the problem.
   - "Parent letter" opens the PDF for the card's period.
5. **Parent letter PDF.**
   - It shows the club header, today's date, the recipient (the emergency contact, or "parent / guardian of…" without one), the subject, the paragraph with the placeholders filled in, the details table (dates `dd/mm/yyyy`, kind, status name, minutes, reason) oldest first, the totals, and the coach and president signature lines.
   - In Arabic it reads right to left and the dates stay legible.
   - Print it on A4 at 100 %: nothing is clipped.
6. **Settings → Parent letter.**
   - The placeholders are listed, and the grey placeholder text shows each language's built-in text.
   - Save a French text with two lines and a `<b>` tag. The letter shows both lines and prints the tag as text.
   - Empty the field again: the built-in text comes back.
7. **Ranking.**
   - The default is the first category and this month. Switch to a season and to another month.
   - Medals on 1–3, the rank numbers, present counts, score %, and the "not ranked" count line.
   - "Print top 3 certificates" gives one landscape page each with the frame, logo, club, title, name, category, period, place, score, date and signatures.
   - "Certificate" on the 4th player prints no place.
   - With nobody ranked the button is hidden.
8. **Profile → Injuries.**
   - The spells timeline shows dates with the year, "Ongoing" for the open spell, and the sessions missed.
   - With edit rights, "Add details" opens the modal. Save body part, description and return date; they show under the spell.
   - Change an earlier mark so the spell's start moves: the detail appears under "Details without a matching spell" and can be edited or deleted.
   - The view-only user sees everything but has no buttons.
9. **Injuries page.** It shows the current injuries (whatever the period) and the spells in the period, with the category filter and links to the profiles.
10. **Permissions.** The view-only user opens every page and PDF above. Their attempts to save the letter text or injury details are refused: the buttons are hidden, and a direct request answers 403.
11. **Desktop app** (offline, no network): the letter and the certificates print with the club logo, and the app starts cleanly twice in a row (`migrate` on boot).

- [ ] **Step 7: Hand-off**

Report to the owner:
- **What shipped:** players at risk (page, export, dashboard card, profile banner), the parent letter with editable text, the ranking with certificates, and injuries (spells, details, club list).
- **The ambiguities resolved** in Global Constraints, especially:
  - the streak rule uses the longest streak of the period;
  - the category filters use the current category for risk and injuries, and mark attribution for the ranking;
  - active players only in the lists;
  - the letter's placeholder values;
  - no shared ranks;
  - spells come from the whole history;
  - a detail delete needs edit.
- **Deploy notes:**
  - One new table (`injury_notes`); run `php artisan migrate` (the desktop app does it on boot).
  - One new setting key (`attendance.letter`, empty by default).
  - `npm run build` ships the new pages.
