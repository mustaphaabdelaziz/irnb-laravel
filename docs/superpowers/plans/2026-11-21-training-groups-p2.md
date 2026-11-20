# Training Groups P2 (Player Card + Sheets) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A player's training group is chosen and moved (from a date) on the branch card of the player form; the month grid and the printed month sheet show one group at a time; a hand-added session can belong to a group; the paper sheets name the group; the Help Center explains training groups step by step.

**Architecture:** No schema change. Phase 1's `GroupMembership` stays the only writer of memberships: `RegistrationService::sync()` calls it for cards that carry a `group_id`. `MonthSheet` gains a group filter and a `groupSwitch()` that the month grid and the printed sheet share, so screen and paper always list the same sessions. `TrainingSessionController` accepts a `group_id` on hand-added sessions under the same lock rules as the branch. The Help Center demo club gets two groups so the new article can be illustrated.

**Tech Stack:** Laravel 13, PHPUnit class tests (`#[Test]`, `RefreshDatabase`), Inertia + Vue 3 `<script setup>`, vue-i18n flat dotted keys, `node --test` for JS helpers, mPDF Blade views, Playwright help shots, SQLite (dev/desktop) and MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-11-20-training-groups-design.md` (phase 2 of 3: "Player branch card (phase 2)", "Month grid, printed month sheet, hand-added sessions (phase 2)", and the session paper sheet named under "Calendar and session page")

## Global Constraints

- Work in the git worktree `D:/irnb-groups` on a new branch `feat/training-groups-p2`, created from local `main` after phase 1 is merged (`git switch -c feat/training-groups-p2 main`). `vendor`, `node_modules` and the Vite build already exist there; never junction them. Never touch `D:/irnb-laravel`.
- This plan lives at `docs/superpowers/plans/2026-11-21-training-groups-p2.md`. If it is still untracked when work starts, commit it first on the branch, alone: `docs(attendance): training groups P2 plan`.
- Edits are given as old → new against phase 1's head (`3fa83e8b`). Another feature (`feat/status-history`) may have reached `main` first and touches some of the same files (`RegistrationService::change()`, `ValidatesRegistrations`, `lang/*/validation.php`, `RegistrationCard.vue`, `RegistrationsSection.vue`, `Players/Show.vue`, `PlayerController::show`, `resources/help/topics.php`, `players/branch-registrations.md`). Find each anchor by its text, not by its line number; add this plan's lines next to what is there and never delete code this plan does not show. If an anchor's text is gone, stop and report.
- Stage explicit file paths only, never a directory. Do not push. The owner decides when `main` is pushed.
- Tests are PHPUnit classes in `tests/Feature`, not Pest functions. Run one class with `php artisan test --filter=<ClassName>`. Never run two test commands at the same time. A private test helper must not reuse a `TestCase` method name (`session`, `get`, `post`, `put`, `json`, `call`, `seed`, `travel`, `mock`).
- No migration in this phase: phase 1's tables and the two `group_id` columns are enough.
- `group_id` null always means "the whole category": football and every category without groups must behave exactly as before. A category without groups gets no Group field, no switch and no extra text anywhere.
- `App\Services\Attendance\GroupMembership` is the only writer of `training_group_members`. `App\Services\Player\RegistrationService` is the only production writer of registrations.
- No SQLite-only SQL: the suite also runs on MariaDB.
- Dates in `training_group_members` and `training_sessions.date` are plain `'Y-m-d'` strings (no Eloquent date cast). "Today" on the server is `now()->toDateString()`, as in phase 1.
- Player names always come from `Player::fullname` (query with `Roster::COLUMNS`).
- i18n: flat dotted keys in `resources/js/i18n/{ar,fr,en}.json`. The files are sorted by key: insert each new key at its alphabetical place without reformatting the file. Call with `t()` directly, never `te()`. No key segment named after a String method (`link`, `search`, `replace`, `at`, `sub`, `big`, `small`, `fixed`, `anchor`, `length`). Never use `att.group.<something>` or `att.grid.<something>` (they would nest under the flat keys `att.group` and `att.grid`).
- No CDN or remote resource. The desktop app runs offline.
- Interface names: Arabic «الفوج» / «الأفواج», French « Groupe », English "Group". The entry for sessions with no group is the existing `att.group_whole` («كل الفئة» / « Toute la catégorie » / "Whole category").
- Help Center: an Arabic article change needs its `fr` and `en` twins in the same commit; `php artisan help:check` and `php artisan test --filter=Help` must pass. Demo data stays Arabic in every language.
- The owner's own servers on ports 2026 and 5173 must not be touched. The help shots serve on 8765; the final browser check serves on 2027.
- Commit messages are conventional (`feat(attendance): …`) and end with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

### Rules this plan fixes where the spec is silent

1. **The card speaks only when the group changed.** The player form sends `group_id` for a branch card only when the choice differs from the group the card opened with. A card without `group_id` leaves memberships alone (imports, bulk edit and the Members dialog never send it).
2. **First assignment = from the beginning.** When the player holds no group in that branch today, the chosen group is saved with no start date, whatever `group_from` says. When they hold one, `group_from` is the day the change applies from; empty = from the beginning.
3. **The "current group" is today's.** Server-side it is read before the cards are saved (a category change closes the membership the day before, in `BranchRegistration`). On the player page and the form a membership of a group of another category is not shown.
4. **A club without branches has no branch card**, so its club-wide groups are assigned from the Groups card only.
5. **The Group field needs no attendance right**: whoever may edit the player may set it (owner decision "both places").
6. **Group switch.** `group_id` in the URL of the month grid and of the printed month sheet is a group's id, or `0` (`MonthSheet::NO_GROUP`) for the sessions with no group. An absent or unknown value falls back to the first group (by name, then id). A category without groups ignores it.
7. **The calendar's print button** (month view) carries no group: it prints the first group's sheet, named in its header. The per-group choice is on the month grid.
8. **A pre-season session never has a group** (the dialog hides the field; the server clears it). A hand-added session that changes branch loses a group that was not re-sent.
9. **A regular session's group** follows its weekly schedule line: the Edit dialog does not offer it and the server refuses it (`att.error.group_follows_schedule`).
10. **Unsaved codes on the grid**: switching group with typed, unsaved codes asks for confirmation first.

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/TrainingGroup.php` | `options()`: the groups as a picker lists them |
| `app/Services/Attendance/GroupMembership.php` | `groupsOf()`: a player's group per branch on a date |
| `resources/js/lib/groupPicker.js` | `groupsFor()`: the groups of one branch + category (pure function) |
| `app/Http/Requests/Player/ValidatesRegistrations.php`, `lang/{ar,fr,en}/validation.php` | `group_id` / `group_from` on a card; the group must fit the card's branch + category |
| `app/Services/Player/RegistrationService.php` | Applies a card's group through `GroupMembership` |
| `app/Http/Controllers/PlayerController.php` | `trainingGroups` and `playerGroups` props (create, edit, show) |
| `resources/js/lib/registrationCards.js` | Card fields `group_id`, `original_group_id`, `group_from`; what the card sends |
| `resources/js/Pages/Players/Partials/RegistrationCard.vue` | Group select and "from" date |
| `resources/js/Pages/Players/Partials/PlayerForm.vue`, `Players/Create.vue`, `Players/Edit.vue` | Pass the groups to the cards |
| `resources/js/Pages/Players/Partials/RegistrationsSection.vue`, `Players/Show.vue` | Group beside the category on the player page |
| `app/Services/Attendance/MonthSheet.php` | Group filter and `groupSwitch()` |
| `app/Http/Controllers/AttendanceGridController.php`, `resources/js/Pages/Attendance/Grid.vue` | Group switch on the month grid |
| `app/Http/Controllers/AttendanceSheetController.php`, `resources/views/pdf/attendance-month-sheet.blade.php`, `resources/views/pdf/attendance-session-sheet.blade.php` | Group on the printed month sheet and the session sheet |
| `app/Http/Controllers/TrainingSessionController.php` | `group_id` on hand-added sessions (store, update) |
| `app/Http/Controllers/AttendanceCalendarController.php`, `app/Http/Controllers/AttendanceController.php` | Groups for the add-session and Edit dialogs |
| `resources/js/Pages/Attendance/Index.vue`, `Partials/AddSessionModal.vue`, `Session.vue` | Group select in the two dialogs |
| `resources/js/i18n/{ar,fr,en}.json` | 2 new keys, 1 changed text |
| `database/seeders/HelpDemoAttendanceSeeder.php` | Two demo groups with members, lines and planned sessions |
| `resources/js/Pages/Attendance/Partials/GroupsCard.vue`, `resources/js/Pages/Attendance/Settings.vue` | Screenshot anchors; two phase 1 minors |
| `resources/help/shots.js` | Seven new shots, the «أصاغر» grid URL, one phase 1 minor |
| `resources/help/{ar,fr,en}/attendance/training-groups.md`, `resources/help/topics.php` | The new article |
| `resources/help/{ar,fr,en}/attendance/{month-grid,add-session,timetable}.md`, `resources/help/{ar,fr,en}/players/branch-registrations.md` | One paragraph or link each |

---

### Task 1: Shared group readers

Three small read-only helpers the later tasks build on. One phase 1 minor rides along in the same file: `GroupMembership::remove()` used a ternary as a statement. (The `GroupBoard` per-branch fix found while planning is already on `main`, commit `672a9f32`, with its test in `AttendanceGroupsTest`: do not touch `GroupBoard.php`.)

**Files:**
- Modify: `app/Models/TrainingGroup.php`
- Modify: `app/Services/Attendance/GroupMembership.php`
- Create: `resources/js/lib/groupPicker.js`
- Create: `tests/js/groupPicker.test.mjs`
- Test: `tests/Feature/TrainingGroupReadersTest.php`

**Interfaces:**
- Consumes: `TrainingGroup`, `TrainingGroupMember::activeOn(string $date)`, `GroupMembership::add(TrainingGroup $group, int $playerId, ?string $from): void`, `GroupMembership::currentGroupIds(array $groupIds, string $date): array` (phase 1).
- Produces:
  - `TrainingGroup::options(?int $categoryId = null): \Illuminate\Support\Collection` — rows `['id' => int, 'branch_id' => ?int, 'category_id' => int, 'name' => string]`, ordered by name then id; with `$categoryId`, only that category's groups.
  - `GroupMembership::groupsOf(int $playerId, string $date): array` — `[branch id (0 = club-wide) => TrainingGroup]` for the memberships open on `$date`.
  - JS `groupsFor(groups, branchId, categoryId): Array` in `resources/js/lib/groupPicker.js`.

- [ ] **Step 1: Write the failing PHP test**

Create `tests/Feature/TrainingGroupReadersTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingGroupReadersTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function options_list_the_groups_by_name_and_can_keep_one_category(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $swim->id]);
        $b = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'B']);
        $a = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'A']);
        $c = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C']);

        $this->assertSame([
            ['id' => $a->id, 'branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'A'],
            ['id' => $b->id, 'branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'B'],
            ['id' => $c->id, 'branch_id' => $swim->id, 'category_id' => $cadet->id, 'name' => 'C'],
        ], TrainingGroup::options()->all());
        $this->assertSame([$a->id, $b->id], TrainingGroup::options($minime->id)->pluck('id')->all());
        $this->assertSame([], TrainingGroup::options(999999)->all());
    }

    #[Test]
    public function the_groups_of_a_player_are_read_per_branch_on_a_date(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $foot = Branch::create(['name' => 'Foot']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $foot->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        $g2 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G2']);
        $f1 = TrainingGroup::create(['branch_id' => $foot->id, 'category_id' => $u15->id, 'name' => 'F1']);
        $player = $this->player($minime);
        $membership = app(GroupMembership::class);
        $membership->add($g1, $player->id, null);
        $membership->add($g2, $player->id, '2026-11-10');
        $membership->add($f1, $player->id, null);

        $ids = fn (array $groups) => array_map(fn (TrainingGroup $group) => $group->id, $groups);

        $this->assertEquals([$swim->id => $g1->id, $foot->id => $f1->id], $ids($membership->groupsOf($player->id, '2026-11-09')));
        $this->assertEquals([$swim->id => $g2->id, $foot->id => $f1->id], $ids($membership->groupsOf($player->id, '2026-11-10')));
        $this->assertSame([], $membership->groupsOf($this->player($minime)->id, '2026-11-10'));
    }

    #[Test]
    public function removing_from_the_beginning_deletes_the_membership_and_from_a_date_closes_it(): void
    {
        $swim = Branch::create(['name' => 'Swim']);
        $minime = Category::create(['name' => 'Minime', 'branch_id' => $swim->id]);
        $g1 = TrainingGroup::create(['branch_id' => $swim->id, 'category_id' => $minime->id, 'name' => 'G1']);
        [$a, $b] = [$this->player($minime), $this->player($minime)];
        $membership = app(GroupMembership::class);
        $membership->add($g1, $a->id, null);
        $membership->add($g1, $b->id, null);

        $membership->remove($g1, $a->id, null);
        $membership->remove($g1, $b->id, '2026-11-10');

        $this->assertSame([[$b->id, '2026-11-09']], $g1->members()->orderBy('id')->get()->map(fn ($m) => [$m->player_id, $m->to_date])->all());
    }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TrainingGroupReadersTest`
Expected: FAIL — `Call to undefined method App\Models\TrainingGroup::options()`, `Call to undefined method App\Services\Attendance\GroupMembership::groupsOf()`. The third test already passes (it pins `remove()` before its rewrite in Step 4).

- [ ] **Step 3: Add `TrainingGroup::options()`**

In `app/Models/TrainingGroup.php`, add the import (after `use Illuminate\Database\Eloquent\Relations\HasMany;`):

```php
use Illuminate\Support\Collection;
```

and add this method right before `isInUse()`:

```php
    /**
     * The groups as a picker lists them (the branch cards of the player form,
     * the add-session and Edit dialogs), by name. With a category, only its groups.
     *
     * @return Collection<int, array{id: int, branch_id: ?int, category_id: int, name: string}>
     */
    public static function options(?int $categoryId = null): Collection
    {
        return static::query()
            ->when($categoryId !== null, fn ($groups) => $groups->where('category_id', $categoryId))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'branch_id', 'category_id', 'name'])
            ->toBase()
            ->map(fn (TrainingGroup $group) => ['id' => $group->id, 'branch_id' => $group->branch_id, 'category_id' => $group->category_id, 'name' => $group->name])
            ->values();
    }
```

- [ ] **Step 4: Add `GroupMembership::groupsOf()` and tidy `remove()`**

In `app/Services/Attendance/GroupMembership.php`, replace the body line of `remove()`:

```php
            $from === null ? $own->delete() : $this->close($own->get(), $from);
```

with:

```php
            if ($from === null) {
                $own->delete();

                return;
            }
            $this->close($own->get(), $from);
```

and add this method right after `currentGroupIds()`:

```php
    /**
     * The group the player is a member of on $date, in each branch (the player
     * form's branch cards, the player page).
     *
     * @return array<int, TrainingGroup> by branch id (0 = the club-wide groups)
     */
    public function groupsOf(int $playerId, string $date): array
    {
        return TrainingGroup::whereIn('id', TrainingGroupMember::where('player_id', $playerId)->activeOn($date)->select('training_group_id'))
            ->orderBy('id')
            ->get()
            ->keyBy(fn (TrainingGroup $group) => (int) $group->branch_id)
            ->all();
    }
```

- [ ] **Step 5: Run the PHP tests to verify they pass**

Run: `php artisan test --filter=TrainingGroupReadersTest`
Expected: PASS (3 tests).

Run: `php artisan test --filter=GroupMembershipTest`
Expected: PASS (phase 1's tests of `remove()` still hold).

Run: `php artisan test --filter=AttendanceGroupsTest`
Expected: PASS (phase 1's tests of the settings page data).

- [ ] **Step 6: Write the failing JS test**

Create `tests/js/groupPicker.test.mjs`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { groupsFor } from '../../resources/js/lib/groupPicker.js';

const GROUPS = [
    { id: 1, branch_id: 1, category_id: 11, name: 'G1' },
    { id: 2, branch_id: 1, category_id: 11, name: 'G2' },
    { id: 3, branch_id: 1, category_id: 12, name: 'Other category' },
    { id: 4, branch_id: 2, category_id: 11, name: 'Other branch' },
    { id: 5, branch_id: null, category_id: 20, name: 'Club-wide' },
];

test('groupsFor keeps the groups of one branch + category', () => {
    assert.deepEqual(groupsFor(GROUPS, 1, 11).map((g) => g.id), [1, 2]);
});

test('groupsFor compares ids as numbers', () => {
    assert.deepEqual(groupsFor(GROUPS, '1', '12').map((g) => g.id), [3]);
});

test('groupsFor matches the club-wide groups when there is no branch', () => {
    assert.deepEqual(groupsFor(GROUPS, null, 20).map((g) => g.id), [5]);
    assert.deepEqual(groupsFor(GROUPS, undefined, 20).map((g) => g.id), [5]);
});

test('groupsFor offers nothing without a category or without groups', () => {
    assert.deepEqual(groupsFor(GROUPS, 1, ''), []);
    assert.deepEqual(groupsFor(GROUPS, 1, null), []);
    assert.deepEqual(groupsFor(undefined, 1, 11), []);
});
```

- [ ] **Step 7: Run it to verify it fails**

Run: `npm run test:js`
Expected: FAIL — `Cannot find module … resources/js/lib/groupPicker.js`.

- [ ] **Step 8: Write the helper**

Create `resources/js/lib/groupPicker.js`:

```js
/**
 * Which training groups a picker offers: those of one branch + category
 * (a group belongs to both). No category, or a category without groups, offers
 * nothing: the field is then not shown. A null branch = the club-wide groups.
 * The server checks too.
 */
export function groupsFor(groups, branchId, categoryId) {
    if (!categoryId) return [];

    return (groups ?? []).filter((group) => Number(group.category_id) === Number(categoryId)
        && Number(group.branch_id ?? 0) === Number(branchId ?? 0));
}
```

- [ ] **Step 9: Run it to verify it passes**

Run: `npm run test:js`
Expected: PASS (all files, 4 new tests).

- [ ] **Step 10: Commit**

```bash
git add app/Models/TrainingGroup.php app/Services/Attendance/GroupMembership.php resources/js/lib/groupPicker.js tests/js/groupPicker.test.mjs tests/Feature/TrainingGroupReadersTest.php
git commit -m "feat(attendance): read a player's groups and list the groups of a category"
```

---

### Task 2: The branch card saves the group (server)

**Files:**
- Modify: `app/Http/Requests/Player/ValidatesRegistrations.php`
- Modify: `lang/ar/validation.php`, `lang/fr/validation.php`, `lang/en/validation.php`
- Modify: `app/Services/Player/RegistrationService.php` (`sync()`, one new private method)
- Modify: `app/Http/Controllers/PlayerController.php` (`show()`, `create()`, `edit()`, one new private method)
- Test: `tests/Feature/Registrations/PlayerGroupCardTest.php`

**Interfaces:**
- Consumes: `TrainingGroup::options()`, `GroupMembership::groupsOf(int $playerId, string $date): array` (Task 1); `GroupMembership::add(TrainingGroup $group, int $playerId, ?string $from): void` and `remove(TrainingGroup $group, int $playerId, ?string $from): void` (phase 1).
- Produces:
  - Card fields accepted by `players.store` / `players.update`: `registrations.*.group_id` (nullable id of a group of the card's branch + category) and `registrations.*.group_from` (nullable `'Y-m-d'`).
  - Inertia props: `trainingGroups` (rows of `TrainingGroup::options()`) on `Players/Create` and `Players/Edit`; `playerGroups` (object: branch id => `{ id, name }`, `{}` when empty) on `Players/Edit` and `Players/Show`.
  - `validation.group_other_category` in the three `lang/*/validation.php` files.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Registrations/PlayerGroupCardTest.php`:

```php
<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerGroupCardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private Category $cadet;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private TrainingGroup $c1;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 10:00:00');
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->c1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1']);
        $this->player = Player::create(['firstname' => 'Sara', 'lastname' => 'Test', 'membership_id' => '202400001', 'join_year' => 2024]);
        app(RegistrationService::class)->register($this->player, [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'join_date' => '2024-09-01',
        ]);
    }

    /** The player form's payload with one swimming card; $extra adds or overrides the card's fields. */
    private function formWith(array $extra = []): array
    {
        return ['firstname' => 'Sara', 'lastname' => 'Test', 'registrations' => [
            $extra + ['branch_id' => $this->swim->id, 'join_date' => '2024-09-01', 'category_id' => $this->minime->id],
        ]];
    }

    /** @return list<array{int, ?string, ?string}> [group id, from, to] of the player's memberships, oldest first */
    private function memberships(?Player $player = null): array
    {
        return TrainingGroupMember::where('player_id', ($player ?? $this->player)->id)->orderBy('id')->get()
            ->map(fn (TrainingGroupMember $m) => [$m->training_group_id, $m->from_date, $m->to_date])->all();
    }

    #[Test]
    public function a_first_assignment_has_no_start_date(): void
    {
        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g1->id, 'group_from' => '2026-11-10']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function changing_the_group_from_a_date_keeps_the_history(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g2->id, 'group_from' => '2026-11-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-14'], [$this->g2->id, '2026-11-15', null]], $this->memberships());
    }

    #[Test]
    public function changing_the_group_with_no_date_replaces_the_history(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g2->id, 'group_from' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g2->id, null, null]], $this->memberships());
    }

    #[Test]
    public function clearing_the_group_takes_the_player_out_from_the_date(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => null, 'group_from' => '2026-11-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-14']], $this->memberships());
    }

    #[Test]
    public function a_card_that_does_not_speak_of_the_group_leaves_the_membership_open(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith(['licence_number' => 'L9']))
            ->assertSessionHasNoErrors();

        $this->assertSame('L9', $this->player->registrations()->sole()->licence_number);
        $this->assertSame([[$this->g1->id, null, null]], $this->memberships());
    }

    #[Test]
    public function sending_the_group_already_held_changes_nothing(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, '2026-10-01');

        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->g1->id, 'group_from' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, '2026-10-01', null]], $this->memberships());
    }

    #[Test]
    public function a_group_of_another_category_is_refused(): void
    {
        $this->put(route('players.update', $this->player), $this->formWith(['group_id' => $this->c1->id]))
            ->assertSessionHasErrors('registrations.0.group_id');

        $this->assertSame([], $this->memberships());
    }

    #[Test]
    public function a_category_change_with_a_new_group_moves_the_player_that_day(): void
    {
        app(GroupMembership::class)->add($this->g1, $this->player->id, null);

        $this->put(route('players.update', $this->player), $this->formWith([
            'category_id' => $this->cadet->id, 'group_id' => $this->c1->id, 'group_from' => '2026-11-20',
        ]))->assertSessionHasNoErrors();

        $this->assertSame([[$this->g1->id, null, '2026-11-19'], [$this->c1->id, '2026-11-20', null]], $this->memberships());
    }

    #[Test]
    public function a_new_player_can_start_in_a_group(): void
    {
        $this->post(route('players.store'), [
            'firstname' => 'Lina', 'lastname' => 'New',
            'registrations' => [[
                'branch_id' => $this->swim->id, 'join_date' => '2026-11-01', 'category_id' => $this->minime->id, 'group_id' => $this->g2->id,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lina = Player::where('firstname', 'Lina')->sole();
        $this->assertSame([[$this->g2->id, null, null]], $this->memberships($lina));
    }

    #[Test]
    public function the_form_and_the_player_page_carry_the_groups(): void
    {
        $this->get(route('players.create'))->assertInertia(fn (Assert $page) => $page
            ->has('trainingGroups', 3)
            ->where('trainingGroups.0', ['id' => $this->c1->id, 'branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1'])
            ->etc());

        // Nothing held: an empty object, not a list.
        $this->get(route('players.edit', $this->player))->assertInertia(fn (Assert $page) => $page
            ->has('trainingGroups', 3)->where('playerGroups', [])->etc());

        app(GroupMembership::class)->add($this->g1, $this->player->id, null);
        $key = "playerGroups.{$this->swim->id}";

        $this->get(route('players.edit', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where("{$key}.id", $this->g1->id)->where("{$key}.name", 'G1')->etc());
        $this->get(route('players.show', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where("{$key}.name", 'G1')->etc());
    }

    #[Test]
    public function a_membership_left_in_another_category_is_not_shown(): void
    {
        app(GroupMembership::class)->add($this->c1, $this->player->id, null);

        $this->get(route('players.show', $this->player))->assertInertia(fn (Assert $page) => $page
            ->where('playerGroups', [])->etc());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PlayerGroupCardTest`
Expected: FAIL — the memberships stay empty (`group_id` is not validated, so it never reaches `RegistrationService`), and `trainingGroups` / `playerGroups` are missing from the pages.

- [ ] **Step 3: Validate the two card fields**

In `app/Http/Requests/Player/ValidatesRegistrations.php`:

Add the import (after `use App\Models\Position;`):

```php
use App\Models\TrainingGroup;
```

In `registrationRules()`, right after the `'registrations.*.category_id'` line, add:

```php
            // The training group of the card's branch + category, and the day a change applies from
            // (none = from the beginning). Sent only when the choice changed (see registrationCards.js).
            'registrations.*.group_id' => ['nullable', 'integer', 'exists:training_groups,id'],
            'registrations.*.group_from' => ['nullable', 'date_format:Y-m-d'],
```

In `validateRegistrations()`, right after the line `$categoryOwner = Category::query()->pluck('branch_id', 'id');`, add:

```php
            $groups = TrainingGroup::query()->get(['id', 'branch_id', 'category_id'])->keyBy('id');
```

and, inside the `foreach`, right after the block that adds `validation.category_other_branch` (it ends with `}` after `$validator->errors()->add("registrations.$i.category_id", __('validation.category_other_branch'));`), add:

```php
                // A training group belongs to one branch + category: the card's own.
                $group = empty($card['group_id']) ? null : $groups->get((int) $card['group_id']);
                if ($group !== null && ((int) $group->branch_id !== $branchId || (int) $group->category_id !== (int) $category)) {
                    $validator->errors()->add("registrations.$i.group_id", __('validation.group_other_category'));
                }
```

Add the message to the three language files, right after their `'category_not_in_branch'` line.

`lang/ar/validation.php`:

```php
    'group_other_category' => 'هذا الفوج تابع لفرع آخر أو لفئة أخرى.',
```

`lang/fr/validation.php`:

```php
    'group_other_category' => 'Ce groupe appartient à une autre section ou à une autre catégorie.',
```

`lang/en/validation.php`:

```php
    'group_other_category' => 'This group belongs to another branch or category.',
```

- [ ] **Step 4: Apply the group in `RegistrationService`**

In `app/Services/Player/RegistrationService.php`:

Add the imports (keep the list alphabetical: `TrainingGroup` after `Position`, `GroupMembership` after `ActivityRecorder`):

```php
use App\Models\TrainingGroup;
```

```php
use App\Services\Attendance\GroupMembership;
```

In `sync()`, replace:

```php
        DB::transaction(function () use ($player, $cards, $user) {
            $existing = $player->registrations()->get()->keyBy('branch_id');
```

with:

```php
        DB::transaction(function () use ($player, $cards, $user) {
            // Read before any card is saved: a category change ends the membership the day before
            // (BranchRegistration). Null when no card speaks of its group: nothing is read or written.
            $groupsBefore = collect($cards)->contains(fn (array $card) => array_key_exists('group_id', $card))
                ? app(GroupMembership::class)->groupsOf($player->id, now()->toDateString())
                : null;
            $existing = $player->registrations()->get()->keyBy('branch_id');
```

and replace (still in `sync()`):

```php
            $this->syncOtherPositions($player, $cards);
            $this->keepHeldPosition($player, $held);
            $this->refreshMirror($player);
        });
    }
```

with:

```php
            $this->syncOtherPositions($player, $cards);
            $this->keepHeldPosition($player, $held);
            $this->refreshMirror($player);
            if ($groupsBefore !== null) {
                $this->applyGroups($player, $cards, $groupsBefore);
            }
        });
    }

    /**
     * The Group field of the branch cards. A card speaks only when it carries
     * `group_id` (the form sends it when the choice changed). `group_from` is the
     * day the change applies from; none = from the beginning, which is also how a
     * first assignment is saved. Memberships are written by GroupMembership alone.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  array<int, TrainingGroup>  $before  branch id => the group held before the cards were saved
     */
    private function applyGroups(Player $player, array $cards, array $before): void
    {
        $membership = app(GroupMembership::class);

        foreach ($cards as $card) {
            if (! array_key_exists('group_id', $card)) {
                continue;
            }
            $held = $before[(int) $card['branch_id']] ?? null;
            $chosenId = empty($card['group_id']) ? null : (int) $card['group_id'];
            if ($held?->id === $chosenId) {
                continue;
            }
            $from = $held === null || empty($card['group_from']) ? null : (string) $card['group_from'];

            if ($chosenId === null) {
                $membership->remove($held, $player->id, $from);
            } else {
                $membership->add(TrainingGroup::findOrFail($chosenId), $player->id, $from);
            }
        }
    }
```

(`$held` cannot be null in the `remove()` branch: `null === null` already continued.)

- [ ] **Step 5: Send the groups to the form and the player page**

In `app/Http/Controllers/PlayerController.php`:

Add the imports (alphabetical: `TrainingGroup` between `Subscription` and `Transaction`; `GroupMembership` right after `AttendanceStatusCatalog`):

```php
use App\Models\TrainingGroup;
```

```php
use App\Services\Attendance\GroupMembership;
```

In `show()`, right after the line `'mainBranchId' => RegistrationDisplay::make()->main($player)?->branch_id,`, add:

```php
            // The training group of each branch card today, by branch id ({} when none).
            'playerGroups' => (object) $this->groupsByBranch($player),
```

In `create()`, right after its `'branches' => Branch::orderBy('name')->get(),` line, add:

```php
            // The Group field of the branch cards.
            'trainingGroups' => TrainingGroup::options(),
```

In `edit()`, right after its `'branches' => Branch::orderBy('name')->get(),` line, add:

```php
            // The Group field of the branch cards, and the group each card holds today.
            'trainingGroups' => TrainingGroup::options(),
            'playerGroups' => (object) $this->groupsByBranch($player),
```

Add this private method right before `create()`:

```php
    /**
     * The training group each of the player's registrations is in today, by branch id.
     * A membership left in a group of another category does not count: a group
     * belongs to one category.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function groupsByBranch(Player $player): array
    {
        $groups = app(GroupMembership::class)->groupsOf($player->id, now()->toDateString());
        $shown = [];
        foreach ($player->registrations as $registration) {
            $group = $groups[(int) $registration->branch_id] ?? null;
            if ($group !== null && $group->category_id === (int) $registration->category_id) {
                $shown[(int) $registration->branch_id] = ['id' => $group->id, 'name' => $group->name];
            }
        }

        return $shown;
    }
```

(`show()` and `edit()` already load `registrations`.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=PlayerGroupCardTest`
Expected: PASS (11 tests).

Run: `php artisan test --filter=Registration`
Expected: PASS. Every other writer sends cards without `group_id`, so nothing else moves.

Run: `php artisan test --filter=PlayerForm`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Player/ValidatesRegistrations.php lang/ar/validation.php lang/fr/validation.php lang/en/validation.php app/Services/Player/RegistrationService.php app/Http/Controllers/PlayerController.php tests/Feature/Registrations/PlayerGroupCardTest.php
git commit -m "feat(players): the branch card sets and moves the training group"
```

---

### Task 3: Group field on the branch card and the player page (front end)

**Files:**
- Modify: `resources/js/lib/registrationCards.js`
- Modify: `tests/js/registrationCards.test.mjs` (append)
- Modify: `resources/js/Pages/Players/Partials/RegistrationCard.vue`
- Modify: `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `resources/js/Pages/Players/Create.vue`, `resources/js/Pages/Players/Edit.vue`
- Modify: `resources/js/Pages/Players/Partials/RegistrationsSection.vue`
- Modify: `resources/js/Pages/Players/Show.vue`

**Interfaces:**
- Consumes: props `trainingGroups` and `playerGroups`, card fields `group_id` / `group_from` (Task 2); `groupsFor(groups, branchId, categoryId)` (Task 1); existing i18n keys `att.group`, `att.group_from`, `att.group_from_help`.
- Produces: card fields `group_id`, `original_group_id`, `group_from` in `registrationCards.js`; `cardFromRegistration(registration, otherPositions = [], isFirst = false, groupId = null)`; `data-help` anchors `player-group` and `player-group-from` on the branch card (Task 8 rings them).

- [ ] **Step 1: Write the failing JS tests**

Append to `tests/js/registrationCards.test.mjs`:

```js
test('a saved card remembers the group it opened with, a new card has none', () => {
    const card = cardFromRegistration({ branch_id: 1 }, [], true, 7);
    assert.equal(card.group_id, 7);
    assert.equal(card.original_group_id, 7);
    assert.equal(card.group_from, '');
    assert.equal(cardFromRegistration({ branch_id: 1 }).group_id, '');
    assert.equal(newCard(1).group_id, '');
    assert.equal(newCard(1).original_group_id, '');
});

test('an untouched group is not sent', () => {
    assert.equal('group_id' in cardPayload({ branch_id: 1, group_id: 7, original_group_id: 7, group_from: '' }), false);
    assert.equal('group_id' in cardPayload({ branch_id: 1, group_id: '', original_group_id: '' }), false);
    assert.equal('group_id' in cardPayload({ branch_id: 1 }), false);
});

test('a first group is sent with no start date', () => {
    const payload = cardPayload({ branch_id: 1, group_id: 7, original_group_id: '', group_from: '2026-11-20' });
    assert.equal(payload.group_id, 7);
    assert.equal(payload.group_from, null);
});

test('a changed group is sent with its day, or none for from the beginning', () => {
    const dated = cardPayload({ branch_id: 1, group_id: '8', original_group_id: 7, group_from: '2026-11-20' });
    assert.equal(dated.group_id, 8);
    assert.equal(dated.group_from, '2026-11-20');
    assert.equal(cardPayload({ branch_id: 1, group_id: 8, original_group_id: 7, group_from: '' }).group_from, null);
});

test('a cleared group is sent as null with its day', () => {
    const payload = cardPayload({ branch_id: 1, group_id: '', original_group_id: 7, group_from: '2026-11-20' });
    assert.equal(payload.group_id, null);
    assert.equal(payload.group_from, '2026-11-20');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npm run test:js`
Expected: FAIL — `card.group_id` is `undefined`, and `cardPayload` sends no `group_id`.

- [ ] **Step 3: Card fields and payload**

In `resources/js/lib/registrationCards.js`:

Replace the signature line:

```js
export function cardFromRegistration(registration, otherPositions = [], isFirst = false) {
```

with:

```js
export function cardFromRegistration(registration, otherPositions = [], isFirst = false, groupId = null) {
```

In `cardFromRegistration`, replace:

```js
        sporting_status_id: registration.sporting_status_id || '',
    };
```

with:

```js
        sporting_status_id: registration.sporting_status_id || '',
        // The training group today, and the one the form opened with (see groupPayload).
        group_id: groupId || '',
        original_group_id: groupId || '',
        group_from: '',
    };
```

In `newCard`, replace:

```js
        sporting_status_id: '',
    };
```

with:

```js
        sporting_status_id: '',
        group_id: '',
        original_group_id: '',
        group_from: '',
    };
```

Add this function right before the comment block of `cardPayload` (the block that starts with `What reaches the server.`):

```js
/**
 * The Group field travels only when it changed, so an untouched card never
 * rewrites a membership. The "from" day counts only when a saved group changes:
 * a first assignment has no start date. No day = from the beginning.
 */
function groupPayload(card) {
    const before = Number(card.original_group_id || 0);
    const now = Number(card.group_id || 0);
    if (before === now) return {};

    return { group_id: now || null, group_from: before ? (card.group_from || null) : null };
}
```

In `cardPayload`, replace:

```js
        sporting_status_id: card.sporting_status_id || null,
    };
```

with:

```js
        sporting_status_id: card.sporting_status_id || null,
        ...groupPayload(card),
    };
```

- [ ] **Step 4: Run them to verify they pass**

Run: `npm run test:js`
Expected: PASS (all files, 5 new tests).

- [ ] **Step 5: The Group field on the card**

In `resources/js/Pages/Players/Partials/RegistrationCard.vue`:

Add the import right after `import { categoriesForBranch } from '@/lib/categoryPicker';`:

```js
import { groupsFor } from '@/lib/groupPicker';
```

Add one prop right after `categories: { type: Array, default: () => [] },`:

```js
    groups: { type: Array, default: () => [] }, // every training group: [{ id, branch_id, category_id, name }]
```

Add right after the `branchCategories` computed:

```js
// The training groups of this card's branch + category; none = the field is not shown.
const groupOptions = computed(() => groupsFor(props.groups, props.branch.id, props.card.category_id));
// A group belongs to one category: another category drops the choice.
watch(() => props.card.category_id, () => {
    if (!groupOptions.value.some((group) => Number(group.id) === Number(props.card.group_id))) props.card.group_id = '';
});
// The "from" day shows only when a saved group changes: a first assignment has no start date.
const groupMoved = computed(() => !!props.card.original_group_id
    && Number(props.card.group_id || 0) !== Number(props.card.original_group_id));
// Today by default; it can be emptied = from the beginning.
watch(groupMoved, (moved) => { props.card.group_from = moved ? localToday() : ''; });
```

In the template, right after the category block (the `<div v-if="branchCategories.length">` … `</div>` that holds `fieldError('category_id')`), add:

```vue
            <div v-if="groupOptions.length" data-help="player-group">
                <InputLabel :value="t('att.group')" />
                <select v-model="card.group_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="group in groupOptions" :key="group.id" :value="group.id">{{ group.name }}</option>
                </select>
                <InputError :message="fieldError('group_id')" class="mt-1" />
            </div>
            <div v-if="groupOptions.length && groupMoved" data-help="player-group-from">
                <InputLabel :value="t('att.group_from')" />
                <TextInput v-model="card.group_from" type="date" class="mt-1 w-full" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_from_help') }}</p>
                <InputError :message="fieldError('group_from')" class="mt-1" />
            </div>
```

- [ ] **Step 6: Pass the groups down from the form**

In `resources/js/Pages/Players/Partials/PlayerForm.vue`:

Add two props right after `branches: { type: Array, default: () => [] },`:

```js
    trainingGroups: { type: Array, default: () => [] }, // every training group, for the cards' Group field
    playerGroups: { type: [Object, Array], default: () => ({}) }, // branch id => { id, name } held today
```

Replace:

```js
    registrations: (p.registrations || []).map((registration, index) => cardFromRegistration(registration, p.other_positions || [], index === 0)),
```

with:

```js
    registrations: (p.registrations || []).map((registration, index) => cardFromRegistration(registration, p.other_positions || [], index === 0, props.playerGroups?.[registration.branch_id]?.id ?? null)),
```

In the `<RegistrationCard` tag, right after `:categories="categories"`, add:

```vue
                        :groups="trainingGroups"
```

In `resources/js/Pages/Players/Create.vue`, add one prop right after `branches: { type: Array, default: () => [] },`:

```js
    trainingGroups: { type: Array, default: () => [] },
```

and in its `<PlayerForm` tag replace `:branches="branches" :wilayas="wilayas"` with:

```vue
:branches="branches" :training-groups="trainingGroups" :wilayas="wilayas"
```

In `resources/js/Pages/Players/Edit.vue`, add two props right after `branches: { type: Array, default: () => [] },`:

```js
    trainingGroups: { type: Array, default: () => [] },
    playerGroups: { type: [Object, Array], default: () => ({}) },
```

and in its `<PlayerForm` tag replace `:branches="branches" :wilayas="wilayas"` with:

```vue
:branches="branches" :training-groups="trainingGroups" :player-groups="playerGroups" :wilayas="wilayas"
```

- [ ] **Step 7: Show the group on the player page**

In `resources/js/Pages/Players/Partials/RegistrationsSection.vue`, add one prop right after the `registrations` prop:

```js
    /** The training group each registration is in today: branch id => { id, name }. */
    groups: { type: [Object, Array], default: () => ({}) },
```

and replace the category line:

```vue
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('category') }}:</dt> <dd class="ms-1 inline">{{ named(registration.category) }}</dd></div>
```

with:

```vue
                <div><dt class="inline text-slate-500 dark:text-slate-400">{{ t('category') }}:</dt> <dd class="ms-1 inline">{{ named(registration.category) }}<template v-if="groups[registration.branch_id]"> · {{ groups[registration.branch_id].name }}</template></dd></div>
```

In `resources/js/Pages/Players/Show.vue`, add one prop right after `mainBranchId: { type: Number, default: null },`:

```js
    playerGroups: { type: [Object, Array], default: () => ({}) }, // branch id => { id, name }: the training group today
```

and in the `<RegistrationsSection` tag, right after `:registrations="player.registrations"`, add ` :groups="playerGroups"`:

```vue
<RegistrationsSection data-help="player-registrations" :registrations="player.registrations" :groups="playerGroups" :other-positions="player.other_positions || []" :format-date="formatPlainDate" />
```

(If the tag has gained other attributes since, keep them and only add `:groups="playerGroups"`.)

- [ ] **Step 8: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run test:js`
Expected: PASS.

Run: `php artisan test --filter=PlayerGroupCardTest`
Expected: PASS.

Run: `php artisan test --filter=BranchFeatureTest`
Expected: PASS (it reads `PlayerForm.vue`'s source).

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/registrationCards.js tests/js/registrationCards.test.mjs resources/js/Pages/Players/Partials/RegistrationCard.vue resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Create.vue resources/js/Pages/Players/Edit.vue resources/js/Pages/Players/Partials/RegistrationsSection.vue resources/js/Pages/Players/Show.vue
git commit -m "feat(players): group field on the branch card and group on the player page"
```

---

### Task 4: Month grid per group

**Files:**
- Modify: `app/Services/Attendance/MonthSheet.php` (whole file below)
- Modify: `app/Http/Controllers/AttendanceGridController.php` (`show()`)
- Modify: `resources/js/Pages/Attendance/Grid.vue`
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json` (1 key)
- Test: `tests/Feature/AttendanceGroupGridTest.php`

**Interfaces:**
- Consumes: `TrainingGroup`, `TrainingSession.group_id`, `Roster::expected(..., ?int $groupId)` (phase 1).
- Produces:
  - `MonthSheet::NO_GROUP = 0`.
  - `MonthSheet::build(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $groupId = null): array` — same return shape as before; `$groupId` null = every session (as before), `NO_GROUP` = only the sessions with no group, an id = only that group's sessions.
  - `MonthSheet::groupSwitch(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $requested = null): array` — `['options' => list<['id' => int, 'name' => ?string]>, 'selected' => ?int]`. No groups in that branch + category: `['options' => [], 'selected' => null]`. Otherwise one option per group (by name, then id), plus `['id' => 0, 'name' => null]` when the month has a non-cancelled session with no group; `selected` = `$requested` when it is one of the options, else the first option.
  - Query parameter `group_id` on `attendance.grid`; Inertia props `groups` (the options) and `groupId` (the selected id or null) on `Attendance/Grid`.
  - `data-help="grid-groups"` on the switch (Task 8 rings it); i18n key `att.grid_unsaved_confirm`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupGridTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\MonthSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupGridTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private Player $a;

    private Player $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $this->a->id, null);
        $membership->add($this->g2, $this->b->id, null);
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function sessionOf(?TrainingGroup $group, string $date, string $start, array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    private function gridUrl(array $query = []): string
    {
        return route('attendance.grid', $query + ['category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]);
    }

    #[Test]
    public function the_month_sheet_keeps_only_the_chosen_groups_sessions(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $second = $this->sessionOf($this->g2, '2026-11-21', '11:00');
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $sheet = app(MonthSheet::class);
        $ids = fn (?int $groupId) => $sheet->build($this->minime->id, 2026, 11, $this->swim->id, $groupId)['sessions']->modelKeys();

        $this->assertSame([$first->id], $ids($this->g1->id));
        $this->assertSame([$second->id], $ids($this->g2->id));
        $this->assertSame([$plain->id], $ids(MonthSheet::NO_GROUP));
        $this->assertSame([$first->id, $second->id, $plain->id], $ids(null));

        $one = $sheet->build($this->minime->id, 2026, 11, $this->swim->id, $this->g1->id);
        $this->assertSame([$this->a->id], $one['players']->modelKeys());
        $whole = $sheet->build($this->minime->id, 2026, 11, $this->swim->id, MonthSheet::NO_GROUP);
        $this->assertSame([$this->a->id, $this->b->id], $whole['players']->modelKeys());
    }

    #[Test]
    public function the_switch_lists_the_groups_and_the_whole_category_only_when_the_month_has_plain_sessions(): void
    {
        $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $this->sessionOf(null, '2026-11-24', '17:00', ['state' => SessionState::Cancelled, 'cancel_reason' => 'Rain']);
        $sheet = app(MonthSheet::class);
        $switch = fn (?int $requested = null) => $sheet->groupSwitch($this->minime->id, 2026, 11, $this->swim->id, $requested);
        $groups = [['id' => $this->g1->id, 'name' => 'G1'], ['id' => $this->g2->id, 'name' => 'G2']];

        // A cancelled plain session is not on the sheet, so it brings no «whole category» entry.
        $this->assertSame(['options' => $groups, 'selected' => $this->g1->id], $switch());
        $this->assertSame($this->g2->id, $switch($this->g2->id)['selected']);
        $this->assertSame($this->g1->id, $switch(999999)['selected']);
        $this->assertSame($this->g1->id, $switch(MonthSheet::NO_GROUP)['selected']);

        $this->sessionOf(null, '2026-11-26', '17:00');

        $this->assertSame([...$groups, ['id' => MonthSheet::NO_GROUP, 'name' => null]], $switch()['options']);
        $this->assertSame($this->g1->id, $switch()['selected']);
        $this->assertSame(MonthSheet::NO_GROUP, $switch(MonthSheet::NO_GROUP)['selected']);
    }

    #[Test]
    public function a_category_without_groups_has_no_switch_and_shows_every_session(): void
    {
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
        TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $u15->id, 'date' => '2026-11-21', 'start_time' => '09:30',
            'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $this->assertSame(['options' => [], 'selected' => null], app(MonthSheet::class)->groupSwitch($u15->id, 2026, 11, $this->swim->id, 5));

        $this->actingAs($this->admin())
            ->get(route('attendance.grid', ['category_id' => $u15->id, 'month' => '2026-11', 'branch_id' => $this->swim->id, 'group_id' => 5]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('groups', [])->where('groupId', null)->has('sessions', 1));
    }

    #[Test]
    public function the_grid_opens_on_the_first_group_and_switches_by_the_url(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $second = $this->sessionOf($this->g2, '2026-11-21', '11:00');
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->gridUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendance/Grid')
                ->where('groupId', $this->g1->id)
                ->where('groups', [
                    ['id' => $this->g1->id, 'name' => 'G1'],
                    ['id' => $this->g2->id, 'name' => 'G2'],
                    ['id' => 0, 'name' => null],
                ])
                ->has('sessions', 1)->where('sessions.0.id', $first->id)
                ->has('rows', 1)->where('rows.0.id', $this->a->id));

        $this->actingAs($admin)->get($this->gridUrl(['group_id' => $this->g2->id]))
            ->assertInertia(fn (Assert $page) => $page->where('groupId', $this->g2->id)
                ->has('sessions', 1)->where('sessions.0.id', $second->id)
                ->has('rows', 1)->where('rows.0.id', $this->b->id));

        $this->actingAs($admin)->get($this->gridUrl(['group_id' => 0]))
            ->assertInertia(fn (Assert $page) => $page->where('groupId', 0)
                ->has('sessions', 1)->where('sessions.0.id', $plain->id)
                ->has('rows', 2));
    }

    #[Test]
    public function saving_a_group_column_takes_its_members_and_refuses_the_others(): void
    {
        $first = $this->sessionOf($this->g1, '2026-11-21', '09:30');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $first->id, 'codes' => [$this->a->id => 'P', $this->b->id => 'P']]],
        ])->assertSessionHasErrors(["columns.0.codes.{$this->b->id}"]);
        $this->assertSame(0, Attendance::count());

        $this->actingAs($admin)->post(route('attendance.grid.save'), [
            'branch_id' => $this->swim->id,
            'columns' => [['session_id' => $first->id, 'codes' => [$this->a->id => 'P']]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.attendance_saved');
        $this->assertSame([$this->a->id], Attendance::where('training_session_id', $first->id)->pluck('player_id')->map(fn ($id) => (int) $id)->all());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupGridTest`
Expected: FAIL — `Undefined constant App\Services\Attendance\MonthSheet::NO_GROUP` (the last test already passes: the grid save has checked the session's group since phase 1).

- [ ] **Step 3: Rewrite `MonthSheet`**

Replace the whole content of `app/Services/Attendance/MonthSheet.php` with:

```php
<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One category's month (of one branch, when given) as players × sessions, shared by the month grid and
 * the printed month sheet so paper and screen always list the same people.
 * A cell exists only where the player is on that session's list: the frozen
 * marks once saved, else the expected roster on the session's date. A joint
 * pre-season session brings its whole roster, since saving its column
 * replaces all of its marks.
 *
 * A category split into training groups is shown one group at a time
 * (groupSwitch() lists the choices, build() keeps the chosen one's sessions).
 */
final class MonthSheet
{
    // Roster::COLUMNS: what Player::fullname and the sheets need.
    private const PLAYER_COLUMNS = Roster::COLUMNS;

    /** The switch entry for the sessions with no group, in a category that has groups («whole category»). */
    public const NO_GROUP = 0;

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly Roster $roster,
    ) {}

    /**
     * The group switch of the month grid and the printed month sheet: one entry
     * per training group of the branch + category (by name), plus NO_GROUP when
     * the month has sessions with no group. `selected` is $requested when it is
     * one of the entries, else the first group. A category without groups has
     * no switch: no options, nothing selected, and build() shows every session.
     *
     * @return array{options: list<array{id: int, name: ?string}>, selected: ?int}
     */
    public function groupSwitch(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $requested = null): array
    {
        $groups = TrainingGroup::where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $categoryId)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
        if ($groups->isEmpty()) {
            return ['options' => [], 'selected' => null];
        }

        // Generated first, like build(): a month nobody opened yet has no session to look at.
        $this->generator->forMonth($categoryId, $year, $month);

        $options = $groups->toBase()->map(fn (TrainingGroup $group) => ['id' => $group->id, 'name' => $group->name])->values()->all();
        if ($this->monthSessions($categoryId, $year, $month, $branchId)->whereNull('group_id')->exists()) {
            $options[] = ['id' => self::NO_GROUP, 'name' => null];
        }
        $ids = array_column($options, 'id');

        return ['options' => $options, 'selected' => in_array($requested, $ids, true) ? $requested : $ids[0]];
    }

    /**
     * Generates the month first (idempotent), like opening it on the calendar.
     * $branchId null = every branch's sessions (a club without branches).
     * $groupId null = every session; NO_GROUP = only the sessions with no group;
     * a training group's id = only its sessions.
     *
     * @return array{sessions: Collection<int, TrainingSession>, cells: array<int, array<int, string>>, players: Collection<int, Player>}
     */
    public function build(int $categoryId, int $year, int $month, ?int $branchId = null, ?int $groupId = null): array
    {
        $this->generator->forMonth($categoryId, $year, $month);

        $sessions = $this->monthSessions($categoryId, $year, $month, $branchId)
            ->when($groupId === self::NO_GROUP, fn ($q) => $q->whereNull('group_id'))
            ->when($groupId !== null && $groupId !== self::NO_GROUP, fn ($q) => $q->where('group_id', $groupId))
            ->with(['attendances', 'categories'])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $codes = AttendanceCode::fromSettings();
        $cells = [];
        $expected = [];
        foreach ($sessions as $session) {
            if ($session->attendances->isNotEmpty()) {
                foreach ($session->attendances as $mark) {
                    $cells[$mark->player_id][$session->id] = $codes->format($mark->status, $mark->minutes);
                }

                continue;
            }
            $categoryIds = $session->categoryIds();
            $statusIds = $this->roster->statusIdsFor($session);
            $key = $session->branch_id.'|'.$session->group_id.'|'.$session->date.'|'.implode(',', $categoryIds).'|'.implode(',', $statusIds);
            $expected[$key] ??= $this->roster->expected($categoryIds, $session->date, $statusIds, $session->branch_id, $session->group_id)->modelKeys();
            foreach ($expected[$key] as $playerId) {
                $cells[$playerId][$session->id] = '';
            }
        }

        $players = Player::whereIn('id', array_keys($cells))
            ->orderBy('lastname')->orderBy('firstname')
            ->get(self::PLAYER_COLUMNS);

        return ['sessions' => $sessions, 'cells' => $cells, 'players' => $players];
    }

    /** The category's sessions of the month that are not cancelled (of one branch, when given). */
    private function monthSessions(int $categoryId, int $year, int $month, ?int $branchId): Builder
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return TrainingSession::includingCategory($categoryId)
            ->where('state', '!=', SessionState::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
    }
}
```

- [ ] **Step 4: Send the switch to the grid page**

In `app/Http/Controllers/AttendanceGridController.php`, in `show()`:

Replace:

```php
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month, $branch?->id);
```

with:

```php
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // A training group of the category, or 0 = its sessions with no group (MonthSheet::NO_GROUP).
            // An unknown value falls back to the first group; a category without groups ignores it.
            'group_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $switch = $sheet->groupSwitch($category->id, $anchor->year, $anchor->month, $branch?->id, isset($data['group_id']) ? (int) $data['group_id'] : null);
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month, $branch?->id, $switch['selected']);
```

and, in the props, right after the `'branch' => …` line, add:

```php
            // The group switch ([] = the category has no groups) and the entry shown.
            'groups' => $switch['options'],
            'groupId' => $switch['selected'],
```

Also extend the class doc comment: after its last sentence `Like the calendar, the grid shows one branch (BranchChoice).` add ` A category split into training groups shows one group at a time (MonthSheet::groupSwitch()).`

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupGridTest`
Expected: PASS (5 tests).

Run: `php artisan test --filter=AttendanceGrid`
Expected: PASS.

Run: `php artisan test --filter=AttendanceRosterGroupTest`
Expected: PASS (phase 1's month-sheet test calls `build()` with no group and still gets every session).

Run: `php artisan test --filter=AttendanceMonthSheet`
Expected: PASS.

- [ ] **Step 6: Add the i18n key**

Insert at its alphabetical place in the three files (right after `att.grid_help_codes`, before `att.group`).

`resources/js/i18n/ar.json`:

```json
    "att.grid_unsaved_confirm": "في الجدول رموز لم تُحفظ بعد وستضيع. هل تتابع؟",
```

`resources/js/i18n/fr.json`:

```json
    "att.grid_unsaved_confirm": "La grille contient des codes non enregistrés : ils seront perdus. Continuer ?",
```

`resources/js/i18n/en.json`:

```json
    "att.grid_unsaved_confirm": "The grid has unsaved codes: they will be lost. Continue?",
```

Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 7: The switch on the grid page**

In `resources/js/Pages/Attendance/Grid.vue`:

Replace:

```js
    cells: { type: Object, default: () => ({}) }, // { playerId: { sessionId: code } }
});
```

with:

```js
    cells: { type: Object, default: () => ({}) }, // { playerId: { sessionId: code } }
    groups: { type: Array, default: () => [] }, // the group switch: [{ id, name }]; id 0 (name null) = the sessions with no group
    groupId: { type: Number, default: null }, // the entry shown; null = the category has no groups
});
```

Replace:

```js
const sheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month, ...branchParam.value }));
const filledSheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month, filled: 1, ...branchParam.value }));
```

with:

```js
// A category split into training groups shows (and prints) one group at a time. 0 is an entry too.
const groupParam = computed(() => (props.groupId === null ? {} : { group_id: props.groupId }));
const groupName = (g) => g.name ?? t('att.group_whole');
const shownGroup = computed(() => props.groups.find((g) => g.id === props.groupId) ?? null);
const sheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month, ...branchParam.value, ...groupParam.value }));
const filledSheetHref = computed(() => route('attendance.sheets.month', { category_id: props.category.id, month: props.month, filled: 1, ...branchParam.value, ...groupParam.value }));
// A full visit: the page is rebuilt for the other group's sessions, so typed codes would be lost.
function switchGroup(id) {
    if (id === props.groupId) return;
    if (dirty.size && !window.confirm(t('att.grid_unsaved_confirm'))) return;
    router.get(route('attendance.grid', { category_id: props.category.id, month: props.month, ...branchParam.value, group_id: id }));
}
```

In the template, replace the heading:

```vue
                    <h1 class="text-lg font-bold capitalize text-slate-900 dark:text-slate-100">{{ t('att.grid') }}<template v-if="branch"> · {{ branch.name }}</template> · {{ category.name }} · {{ monthLabel }}</h1>
```

with:

```vue
                    <h1 class="text-lg font-bold capitalize text-slate-900 dark:text-slate-100">{{ t('att.grid') }}<template v-if="branch"> · {{ branch.name }}</template> · {{ category.name }}<template v-if="shownGroup"> · {{ groupName(shownGroup) }}</template> · {{ monthLabel }}</h1>
```

and add, right before the line `<div data-help="grid-legend" class="mb-2 flex flex-wrap gap-2 text-xs">`:

```vue
        <div v-if="groups.length" class="mb-3 print:hidden">
            <div data-help="grid-groups" role="tablist" :aria-label="t('att.group')" class="inline-flex flex-wrap rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                <button
                    v-for="g in groups"
                    :key="g.id"
                    type="button"
                    role="tab"
                    :aria-selected="groupId === g.id"
                    class="rounded-md px-3 py-1 text-sm font-semibold"
                    :class="groupId === g.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                    @click="switchGroup(g.id)"
                >{{ groupName(g) }}</button>
            </div>
        </div>
```

The two print links keep their `:href="sheetHref"` / `:href="filledSheetHref"` attributes exactly as they are (`AttendanceSheetLinksTest` reads them).

- [ ] **Step 8: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `php artisan test --filter=AttendanceSheetLinksTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceGroupGridTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/MonthSheet.php app/Http/Controllers/AttendanceGridController.php resources/js/Pages/Attendance/Grid.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/AttendanceGroupGridTest.php
git commit -m "feat(attendance): month grid shows one training group at a time"
```

---

### Task 5: Printed sheets name the group

**Files:**
- Modify: `app/Http/Controllers/AttendanceSheetController.php` (`month()`, `session()`)
- Modify: `resources/views/pdf/attendance-month-sheet.blade.php`
- Modify: `resources/views/pdf/attendance-session-sheet.blade.php`
- Test: `tests/Feature/AttendanceGroupSheetsPdfTest.php`

**Interfaces:**
- Consumes: `MonthSheet::groupSwitch()`, `MonthSheet::build(..., ?int $groupId)`, `MonthSheet::NO_GROUP` (Task 4); `TrainingSession::group()` (phase 1); `App\Support\UiLang::get(string $key)`; existing keys `att.group`, `att.group_whole`.
- Produces: query parameter `group_id` on `attendance.sheets.month` (same meaning and fallback as on the grid); view variable `$trainingGroup` (the name printed, or null) in `pdf.attendance-month-sheet` (`$groups` is already the view's column chunks); `$session['group']` in `pdf.attendance-session-sheet`; file name `attendance-sheet-{category}-{Y-m}-g{id}.pdf` when a switch entry is selected (unchanged for a category without groups).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceGroupSheetsPdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupSheetsPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $dolphins;

    private TrainingGroup $sharks;

    private Player $a;

    private Player $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->dolphins = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'Dauphins']);
        $this->sharks = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'Requins']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->dolphins, $this->a->id, null);
        $membership->add($this->sharks, $this->b->id, null);
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function sessionOf(?TrainingGroup $group, string $date, string $start): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    /** @return \ArrayObject<string, string> what the month sheet sent to the PDF service, in French */
    private function monthSheet(array $query = []): \ArrayObject
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', $query + ['category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]))
            ->assertOk();

        return $seen;
    }

    #[Test]
    public function the_month_sheet_prints_the_first_group_by_default_and_names_it(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf($this->sharks, '2026-11-21', '11:00');

        $seen = $this->monthSheet();

        $this->assertStringContainsString('Groupe: <b>Dauphins</b>', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->b->fullname, $seen['html']);
        $this->assertStringNotContainsString('11:00', $seen['html']);
        $this->assertSame("attendance-sheet-{$this->minime->id}-2026-11-g{$this->dolphins->id}.pdf", $seen['filename']);
    }

    #[Test]
    public function the_month_sheet_prints_the_group_asked_for(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf($this->sharks, '2026-11-21', '11:00');

        $seen = $this->monthSheet(['group_id' => $this->sharks->id]);

        $this->assertStringContainsString('Groupe: <b>Requins</b>', $seen['html']);
        $this->assertStringContainsString($this->b->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString('Dauphins', $seen['html']);
    }

    #[Test]
    public function the_whole_category_entry_prints_the_sessions_with_no_group(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf(null, '2026-11-24', '17:00');

        $seen = $this->monthSheet(['group_id' => 0]);

        $this->assertStringContainsString('Groupe: <b>Toute la catégorie</b>', $seen['html']);
        $this->assertStringContainsString('17:00', $seen['html']);
        $this->assertStringNotContainsString('09:30', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringContainsString($this->b->fullname, $seen['html']);
        $this->assertSame("attendance-sheet-{$this->minime->id}-2026-11-g0.pdf", $seen['filename']);
    }

    #[Test]
    public function a_category_without_groups_prints_as_before(): void
    {
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
        TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $u15->id, 'date' => '2026-11-21', 'start_time' => '09:30',
            'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', ['category_id' => $u15->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]))
            ->assertOk();

        $this->assertStringNotContainsString('Groupe:', $seen['html']);
        $this->assertSame("attendance-sheet-{$u15->id}-2026-11.pdf", $seen['filename']);
    }

    #[Test]
    public function the_session_sheet_names_the_group_beside_the_category(): void
    {
        $grouped = $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', $grouped))->assertOk();

        $this->assertStringContainsString('<b>Minime · Dauphins</b>', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->b->fullname, $seen['html']);
    }

    #[Test]
    public function a_session_with_no_group_prints_its_category_alone(): void
    {
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', $plain))->assertOk();

        $this->assertStringContainsString('<b>Minime</b>', $seen['html']);
        $this->assertStringNotContainsString('Dauphins', $seen['html']);
    }
}
```

(`spyPdf()` comes from `BranchAttendanceFixtures`: it replaces mPDF and keeps the HTML and the file name.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AttendanceGroupSheetsPdfTest`
Expected: FAIL — no `Groupe:` in the month sheet (both groups' players are printed), and the session sheet prints `<b>Minime</b>` without the group. `a_category_without_groups_prints_as_before` and `a_session_with_no_group_prints_its_category_alone` already pass.

- [ ] **Step 3: The month sheet takes a group**

In `app/Http/Controllers/AttendanceSheetController.php`:

Add the import (after `use App\Support\Season;`):

```php
use App\Support\UiLang;
```

In `month()`, replace:

```php
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $locale = app()->getLocale();
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month, $branch?->id);
```

with:

```php
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // As on the month grid: a training group, or 0 = the sessions with no group; none = the first group.
            'group_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $category = Category::findOrFail($data['category_id']);
        $branch = Branch::find(BranchChoice::resolve($request, $data['branch_id'] ?? null));
        $anchor = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $locale = app()->getLocale();
        $switch = $sheet->groupSwitch($category->id, $anchor->year, $anchor->month, $branch?->id, isset($data['group_id']) ? (int) $data['group_id'] : null);
        ['sessions' => $sessions, 'cells' => $cells, 'players' => $players] = $sheet->build($category->id, $anchor->year, $anchor->month, $branch?->id, $switch['selected']);
        // What the header prints: the group's name, «whole category» for the sessions with no group,
        // nothing for a category without groups.
        $shown = collect($switch['options'])->firstWhere('id', $switch['selected']);
        $trainingGroup = $shown === null ? null : ($shown['name'] ?? UiLang::get('att.group_whole'));
```

In the `view('pdf.attendance-month-sheet', [...])` array, right after `'branch' => $branch?->localized_name,`, add:

```php
            'trainingGroup' => $trainingGroup,
```

Replace the last line of `month()`:

```php
        return $this->pdf->stream($html, "attendance-sheet-{$category->id}-{$anchor->format('Y-m')}.pdf", $locale === 'ar', true);
```

with:

```php
        // One file name per group: a downloaded sheet of another group is never mistaken for this one.
        $suffix = $switch['selected'] === null ? '' : "-g{$switch['selected']}";

        return $this->pdf->stream($html, "attendance-sheet-{$category->id}-{$anchor->format('Y-m')}{$suffix}.pdf", $locale === 'ar', true);
```

In `session()`, replace:

```php
        $session->loadMissing('categories', 'branch');
```

with:

```php
        $session->loadMissing('categories', 'branch', 'group');
```

and, in the `'session' => [...]` array, right after `'categories' => $categories->values()->all(),`, add:

```php
                // The training group whose members make the list; null = the whole category.
                'group' => $session->group?->name,
```

- [ ] **Step 4: Print it**

In `resources/views/pdf/attendance-month-sheet.blade.php`:

Replace (in the `@php` block):

```php
    $branch ??= null; // the sport, when the club has branches
    $footer = ($branch ? $branch.' · ' : '').$category['name'].' · '.$monthLabel.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
```

with:

```php
    $branch ??= null; // the sport, when the club has branches
    $trainingGroup ??= null; // the training group printed, when the category has groups ($groups = the column chunks)
    $footer = ($branch ? $branch.' · ' : '').$category['name'].($trainingGroup ? ' · '.$trainingGroup : '').' · '.$monthLabel.' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
```

The header line appears twice (once in the `@if ($groups === [])` block, once inside `@foreach ($groups as $group)`). In **both**, replace:

```blade
{{ $L('att.category') }}: <b>{{ $category['name'] }}</b> &middot; {{ $L('att.sheet.month') }}:
```

with:

```blade
{{ $L('att.category') }}: <b>{{ $category['name'] }}</b>@if ($trainingGroup) &middot; {{ $L('att.group') }}: <b>{{ $trainingGroup }}</b>@endif &middot; {{ $L('att.sheet.month') }}:
```

In `resources/views/pdf/attendance-session-sheet.blade.php`:

Replace (in the `@php` block):

```php
    $footer = (!empty($session['branch']) ? $session['branch'].' · ' : '').implode(' · ', $session['categories']).' · '.$session['date'].' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
```

with:

```php
    // The categories, then the training group when the session has one (`Minime · الفوج 1`).
    $named = implode(' · ', array_filter([...$session['categories'], $session['group'] ?? null]));
    $footer = (!empty($session['branch']) ? $session['branch'].' · ' : '').$named.' · '.$session['date'].' — '.strtr($L('att.sheet.page'), ['{page}' => '{PAGENO}', '{pages}' => '{nbpg}']);
```

and, in the `<div class="sub">` line under the title, replace:

```blade
<b>{{ implode(' · ', $session['categories']) }}</b>
```

with:

```blade
<b>{{ $named }}</b>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=AttendanceGroupSheetsPdfTest`
Expected: PASS (6 tests).

Run: `php artisan test --filter=AttendanceMonthSheet`
Expected: PASS (`AttendanceMonthSheetPdfTest` renders a real PDF: the Blade change must not break mPDF).

Run: `php artisan test --filter=AttendanceSessionSheetPdfTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceSheetController.php resources/views/pdf/attendance-month-sheet.blade.php resources/views/pdf/attendance-session-sheet.blade.php tests/Feature/AttendanceGroupSheetsPdfTest.php
git commit -m "feat(attendance): paper sheets per training group"
```

---

### Task 6: Hand-added sessions can belong to a group

**Files:**
- Modify: `app/Http/Controllers/TrainingSessionController.php` (`store()`, `update()`, one new private method)
- Modify: `app/Http/Controllers/AttendanceCalendarController.php` (`index()`, one optional prop)
- Modify: `app/Http/Controllers/AttendanceController.php` (`show()`)
- Modify: `resources/js/Pages/Attendance/Index.vue`
- Modify: `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`
- Modify: `resources/js/Pages/Attendance/Session.vue`
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json` (1 new key, 1 changed text)
- Test: `tests/Feature/TrainingSessionGroupTest.php`

**Interfaces:**
- Consumes: `TrainingGroup::options(?int $categoryId = null)`, JS `groupsFor(groups, branchId, categoryId)` (Task 1); `Roster::forSession()` reading `group_id` (phase 1); existing keys `att.group`, `att.group_whole`, `att.error.group_other_category`, `att.error.already_marked`.
- Produces:
  - `attendance.sessions.store` accepts `group_id` (nullable; dropped for `kind = preseason`).
  - `attendance.sessions.update` accepts `group_id` (optional, nullable) under the branch's rules: hand-added sessions only, until marks are saved.
  - Inertia: optional prop `groups` on `Attendance/Index` (rows of `TrainingGroup::options()`, loaded with `playerStatuses`); on `Attendance/Session`, `session.group_id` and prop `groups` (the groups of the session's primary category).
  - i18n key `att.error.group_follows_schedule`; `data-help="add-group"` on the add-session dialog's Group field (Task 8 rings it).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TrainingSessionGroupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Attendance\GroupMembership;
use App\Services\Attendance\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class TrainingSessionGroupTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private Category $cadet;

    private TrainingGroup $g1;

    private TrainingGroup $g2;

    private TrainingGroup $c1;

    private Player $a;

    private Player $b;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);
        $this->g1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1']);
        $this->g2 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2']);
        $this->c1 = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->cadet->id, 'name' => 'C1']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->g1, $this->a->id, null);
        $membership->add($this->g2, $this->b->id, null);
        $this->staff = $this->admin();
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function training(array $extra = []): TrainingSession
    {
        return TrainingSession::create($extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'date' => '2026-11-25', 'start_time' => '18:00',
            'end_time' => '19:30', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned,
        ]);
    }

    private function newSession(array $extra = [])
    {
        return $this->actingAs($this->staff)->post(route('attendance.sessions.store'), $extra + [
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'kind' => 'extra',
            'date' => '2026-11-25', 'start_time' => '18:00', 'end_time' => '19:30',
        ]);
    }

    private function edit(TrainingSession $training, array $data)
    {
        return $this->actingAs($this->staff)->put(route('attendance.sessions.update', $training), $data);
    }

    #[Test]
    public function an_extra_session_can_be_added_for_one_group(): void
    {
        $this->newSession(['group_id' => $this->g1->id])->assertSessionHasNoErrors();

        $created = TrainingSession::sole();
        $this->assertSame($this->g1->id, $created->group_id);
        $this->assertSame([$this->a->id], app(Roster::class)->forSession($created)->modelKeys());
    }

    #[Test]
    public function an_extra_session_without_a_group_lists_the_whole_category(): void
    {
        $this->newSession()->assertSessionHasNoErrors();

        $created = TrainingSession::sole();
        $this->assertNull($created->group_id);
        $this->assertSame([$this->a->id, $this->b->id], app(Roster::class)->forSession($created)->modelKeys());
    }

    #[Test]
    public function a_group_of_another_category_is_refused_on_a_new_session(): void
    {
        $this->newSession(['group_id' => $this->c1->id])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->newSession(['group_id' => 999999])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);

        $this->assertSame(0, TrainingSession::count());
    }

    #[Test]
    public function a_pre_season_session_never_takes_a_group(): void
    {
        $this->newSession(['kind' => 'preseason', 'group_id' => $this->g1->id])->assertSessionHasNoErrors();

        $this->assertNull(TrainingSession::sole()->group_id);
    }

    #[Test]
    public function the_group_of_a_hand_added_session_can_change_until_marks_are_saved(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasNoErrors();
        $this->assertSame($this->g2->id, $training->fresh()->group_id);
        $this->assertContains('group_id', ActivityLog::where('action', ActivityAction::TRAINING_SESSION_UPDATED)->sole()->properties['fields']);

        $this->edit($training, ['group_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($training->fresh()->group_id);

        $this->edit($training, ['group_id' => $this->c1->id])->assertSessionHasErrors(['group_id' => 'att.error.group_other_category']);
        $this->assertNull($training->fresh()->group_id);
    }

    #[Test]
    public function the_group_is_locked_once_marks_are_saved_but_details_still_change(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);
        Attendance::create([
            'training_session_id' => $training->id, 'player_id' => $this->a->id, 'category_id' => $this->minime->id,
            'status' => AttendanceStatus::Present->value,
        ]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasErrors(['group_id' => 'att.error.already_marked']);
        $this->edit($training, ['group_id' => null])->assertSessionHasErrors(['group_id' => 'att.error.already_marked']);
        $this->edit($training, ['title' => 'Relay'])->assertSessionHasNoErrors();

        $fresh = $training->fresh();
        $this->assertSame([$this->g1->id, 'Relay'], [$fresh->group_id, $fresh->title]);
    }

    #[Test]
    public function a_regular_session_keeps_the_group_of_its_schedule_line(): void
    {
        $training = $this->training(['kind' => SessionKind::Regular, 'group_id' => $this->g1->id]);

        $this->edit($training, ['group_id' => $this->g2->id])->assertSessionHasErrors(['group_id' => 'att.error.group_follows_schedule']);
        $this->edit($training, ['title' => 'Relay'])->assertSessionHasNoErrors();

        $this->assertSame($this->g1->id, $training->fresh()->group_id);
    }

    #[Test]
    public function turning_the_session_into_pre_season_or_moving_its_branch_drops_the_group(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);
        $this->edit($training, ['kind' => 'preseason'])->assertSessionHasNoErrors();
        $this->assertNull($training->fresh()->group_id);

        // A category with no owner can follow the session to another branch; a group cannot.
        $open = Category::create(['name' => 'Open']);
        $group = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $open->id, 'name' => 'O1']);
        $other = Branch::create(['name' => 'Polo']);
        $moving = $this->training(['category_id' => $open->id, 'group_id' => $group->id, 'start_time' => '20:00']);

        $this->edit($moving, ['branch_id' => $other->id])->assertSessionHasNoErrors();

        $fresh = $moving->fresh();
        $this->assertSame([$other->id, null], [(int) $fresh->branch_id, $fresh->group_id]);
    }

    #[Test]
    public function the_session_page_carries_its_group_and_the_groups_of_its_category(): void
    {
        $training = $this->training(['group_id' => $this->g1->id]);

        $this->actingAs($this->staff)->get(route('attendance.sessions.show', $training))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.group_id', $this->g1->id)
                ->where('session.group', 'G1')
                ->where('groups', [
                    ['id' => $this->g1->id, 'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G1'],
                    ['id' => $this->g2->id, 'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'G2'],
                ]));
    }

    #[Test]
    public function the_calendar_sends_the_groups_only_when_the_add_dialog_asks_for_them(): void
    {
        $params = ['view' => 'month', 'category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id];
        $first = $this->actingAs($this->staff)->get(route('attendance.index', $params));
        $first->assertInertia(fn (Assert $page) => $page->missing('groups'));

        $props = $this->actingAs($this->staff)->get(route('attendance.index', $params), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $first->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Attendance/Index',
            'X-Inertia-Partial-Data' => 'playerStatuses,rosterStatusIds,groups',
        ])->assertOk()->json('props');

        $this->assertSame(['C1', 'G1', 'G2'], array_column($props['groups'], 'name'));
        $this->assertArrayHasKey('playerStatuses', $props);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TrainingSessionGroupTest`
Expected: FAIL — `group_id` is not validated on store or update (the created session has no group; an update leaves it unchanged), and the two pages send no `groups`. `an_extra_session_without_a_group_lists_the_whole_category` already passes.

- [ ] **Step 3: Accept the group on store and update**

In `app/Http/Controllers/TrainingSessionController.php`:

Add the import (after `use App\Models\Branch;`):

```php
use App\Models\TrainingGroup;
```

In `store()`, replace:

```php
            'title' => ['nullable', 'string', 'max:150'],
            // The player statuses its roster is drawn from (the dialog pre-checks the settings' default).
```

with:

```php
            'title' => ['nullable', 'string', 'max:150'],
            // The training group whose members make the list; a pre-season session never has one.
            'group_id' => ['exclude_if:kind,preseason', 'nullable', 'integer'],
            // The player statuses its roster is drawn from (the dialog pre-checks the settings' default).
```

and replace:

```php
        CategoryOwnership::assertIn('category_id', $categoryIds, $branchId);
        $this->assertSlotFree($branchId, $categoryIds, $data['date'], $data['start_time']);
```

with:

```php
        CategoryOwnership::assertIn('category_id', $categoryIds, $branchId);
        // Always present, so the session is created with a group or with none.
        $data['group_id'] = isset($data['group_id']) ? (int) $data['group_id'] : null;
        if ($data['group_id'] !== null && ! $this->groupFits($data['group_id'], $branchId, (int) $data['category_id'])) {
            throw ValidationException::withMessages(['group_id' => 'att.error.group_other_category']);
        }
        $this->assertSlotFree($branchId, $categoryIds, $data['date'], $data['start_time']);
```

Replace the doc comment of `update()`:

```php
    /**
     * The session page's Edit dialog. Title, coach, notes, date and times can
     * always change (a new date or start keeps the first original date in
     * `moved_from`, so the generator never recreates the old slot). Branch,
     * roster statuses, categories and kind shape the roster, which is frozen
     * once marks are saved, so they change only before that; branch and kind
     * only on a session added by hand (a regular one follows its weekly
     * schedule). Fields left out of the request stay as they are.
     */
```

with:

```php
    /**
     * The session page's Edit dialog. Title, coach, notes, date and times can
     * always change (a new date or start keeps the first original date in
     * `moved_from`, so the generator never recreates the old slot). Branch,
     * training group, roster statuses, categories and kind shape the roster,
     * which is frozen once marks are saved, so they change only before that;
     * branch, group and kind only on a session added by hand (a regular one
     * follows its weekly schedule line). Fields left out of the request stay
     * as they are, except the group: a pre-season session never has one, and
     * a group that was not re-sent does not follow the session to another branch.
     */
```

In `update()`, replace:

```php
            'branch_id' => ['sometimes', Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'roster_status_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
```

with:

```php
            'branch_id' => ['sometimes', Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'roster_status_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
```

Replace:

```php
        $branchId = array_key_exists('branch_id', $data) ? ($data['branch_id'] === null ? null : (int) $data['branch_id']) : $session->branch_id;
        $storedIds = $this->sortedIds($session->roster_status_ids);
```

with:

```php
        $branchId = array_key_exists('branch_id', $data) ? ($data['branch_id'] === null ? null : (int) $data['branch_id']) : $session->branch_id;
        $groupSent = array_key_exists('group_id', $data);
        $groupId = $groupSent ? ($data['group_id'] === null ? null : (int) $data['group_id']) : $session->group_id;
        if ($kind === SessionKind::Preseason || (! $groupSent && $branchId !== $session->branch_id)) {
            $groupId = null;
        }
        $storedIds = $this->sortedIds($session->roster_status_ids);
```

Replace:

```php
        if (! $handAdded && $branchId !== $session->branch_id) {
            $errors['branch_id'] = 'att.error.not_hand_added';
        }
```

with:

```php
        if (! $handAdded && $branchId !== $session->branch_id) {
            $errors['branch_id'] = 'att.error.not_hand_added';
        }
        // The primary category the session ends with (see `category_id` below): a group belongs to it.
        $primaryId = in_array($session->category_id, $categoryIds, true) ? $session->category_id : $categoryIds[0];
        if ($groupId !== $session->group_id) {
            if (! $handAdded) {
                $errors['group_id'] = 'att.error.group_follows_schedule';
            } elseif ($groupId !== null && ! $this->groupFits($groupId, $branchId, (int) $primaryId)) {
                $errors['group_id'] = 'att.error.group_other_category';
            }
        }
```

Replace:

```php
            $changed = [
                'branch_id' => $branchId !== $session->branch_id,
                'roster_status_ids' => $statusIds !== $storedIds,
```

with:

```php
            $changed = [
                'branch_id' => $branchId !== $session->branch_id,
                'group_id' => $groupId !== $session->group_id,
                'roster_status_ids' => $statusIds !== $storedIds,
```

Replace:

```php
        $attributes = Arr::only($data, ['title', 'coach', 'notes', 'date', 'start_time', 'end_time']) + [
            'branch_id' => $branchId,
            'kind' => $kind,
```

with:

```php
        $attributes = Arr::only($data, ['title', 'coach', 'notes', 'date', 'start_time', 'end_time']) + [
            'branch_id' => $branchId,
            'group_id' => $groupId,
            'kind' => $kind,
```

Add this private method right before `slotRules()`:

```php
    /** A training group belongs to one branch + category. */
    private function groupFits(int $groupId, ?int $branchId, int $categoryId): bool
    {
        return TrainingGroup::whereKey($groupId)
            ->where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $categoryId)
            ->exists();
    }
```

How the lock reads: once marks are saved, the existing loop gives every changed roster field `att.error.already_marked` unless the field already has an error (`??=`). So a regular session answers `att.error.group_follows_schedule`, a group of another category answers `att.error.group_other_category`, and any other group change on a marked session answers `att.error.already_marked`. All three block the save.

- [ ] **Step 4: Send the groups to the two pages**

In `app/Http/Controllers/AttendanceCalendarController.php`:

Add the import (after `use App\Models\PlayerStatus;`):

```php
use App\Models\TrainingGroup;
```

In `index()`, right after the line `'rosterStatusIds' => Inertia::optional(fn () => AttendanceSettings::rosterStatusIds()),`, add:

```php
            // The add-session dialog's Group select: optional too, fetched with the two above.
            'groups' => Inertia::optional(fn () => TrainingGroup::options()),
```

In `app/Http/Controllers/AttendanceController.php`:

Add the import (after `use App\Models\PlayerStatus;`):

```php
use App\Models\TrainingGroup;
```

In `show()`, replace:

```php
                'group' => $session->group?->name,
                'category_id' => $session->category_id,
```

with:

```php
                'group' => $session->group?->name,
                'group_id' => $session->group_id,
                'category_id' => $session->category_id,
```

and, right after the line `'playerStatuses' => PlayerStatus::options(),`, add:

```php
            // The Edit dialog's Group select: the groups of the session's primary category (any branch,
            // since a hand-added session can change branch).
            'groups' => TrainingGroup::options((int) $session->category_id),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=TrainingSessionGroupTest`
Expected: PASS (10 tests).

Run: `php artisan test --filter=TrainingSessionUpdateTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceCalendar`
Expected: PASS (this includes `AttendanceCalendarPerformanceTest`: an optional prop costs nothing until asked for).

Run: `php artisan test --filter=SessionRosterQueryBudgetTest`
Expected: PASS (the session page costs one more query, the same at any club size).

- [ ] **Step 6: i18n**

Insert the new key at its alphabetical place in the three files (right after `att.error.duplicate`, before `att.error.group_name_taken`), and change the text of the existing key `att.edit_locked_hint`.

`resources/js/i18n/ar.json`:

```json
    "att.error.group_follows_schedule": "فوج حصة التوقيت الأسبوعي يتبع سطرها: غيّره في إعدادات الحضور.",
```

```json
    "att.edit_locked_hint": "الفرع والفوج وحالات اللاعبين والفئات والنوع ثابتة بعد حفظ الحضور.",
```

`resources/js/i18n/fr.json`:

```json
    "att.error.group_follows_schedule": "Le groupe d'une séance de l'horaire hebdomadaire suit sa ligne : changez-le dans les réglages des présences.",
```

```json
    "att.edit_locked_hint": "La section, le groupe, les statuts des joueurs, les catégories et le type sont figés une fois les présences enregistrées.",
```

`resources/js/i18n/en.json`:

```json
    "att.error.group_follows_schedule": "The group of a weekly-schedule session follows its line: change it in the attendance settings.",
```

```json
    "att.edit_locked_hint": "Branch, group, player statuses, categories and type are fixed once attendance is saved.",
```

Run: `npm run i18n:check`
Expected: PASS.

- [ ] **Step 7: Group select in the add-session dialog**

In `resources/js/Pages/Attendance/Index.vue`:

Replace:

```js
    rosterStatusIds: { type: Array, default: null },
});
```

with:

```js
    rosterStatusIds: { type: Array, default: null },
    groups: { type: Array, default: null }, // add-session dialog: every training group (optional prop, loaded with playerStatuses)
});
```

Replace:

```js
        router.reload({ only: ['playerStatuses', 'rosterStatusIds'], onSuccess: () => (showCreate.value = true) });
```

with:

```js
        router.reload({ only: ['playerStatuses', 'rosterStatusIds', 'groups'], onSuccess: () => (showCreate.value = true) });
```

In the `<AddSessionModal` tag, right after `:branch-id="branchId"`, add ` :groups="groups ?? []"`.

In `resources/js/Pages/Attendance/Partials/AddSessionModal.vue`:

Add the import right after `import { categoriesForBranch } from '@/lib/categoryPicker';`:

```js
import { groupsFor } from '@/lib/groupPicker';
```

Add one prop right after `branchId: { type: Number, default: null }, // pre-selected (the calendar's switch)`:

```js
    groups: { type: Array, default: () => [] }, // every training group: [{ id, branch_id, category_id, name }]
```

Replace:

```js
const form = useForm({ branch_id: null, category_id: null, category_ids: [], kind: 'extra', date: '', start_time: '18:00', end_time: '19:30', title: '', roster_status_ids: [] });
const offered = computed(() => categoriesForBranch(props.categories, form.branch_id));
```

with:

```js
const form = useForm({ branch_id: null, category_id: null, group_id: null, category_ids: [], kind: 'extra', date: '', start_time: '18:00', end_time: '19:30', title: '', roster_status_ids: [] });
const offered = computed(() => categoriesForBranch(props.categories, form.branch_id));
// The training groups of the chosen branch + category; none = the field is not shown.
const groupOptions = computed(() => groupsFor(props.groups, form.branch_id, form.category_id));
// A group belongs to one branch + category: another choice drops it.
watch(() => [form.branch_id, form.category_id], () => {
    if (!groupOptions.value.some((g) => g.id === form.group_id)) form.group_id = null;
});
```

In `submit()`, replace:

```js
            title: d.title || null,
```

with:

```js
            title: d.title || null,
            // A pre-season session never has a group.
            group_id: d.kind === 'preseason' ? null : d.group_id,
```

In the template, right after the category `<label data-help="add-category" …>` … `</label>` block, add:

```vue
            <label v-if="form.kind !== 'preseason' && groupOptions.length" data-help="add-group" class="block text-sm">{{ t('att.group') }}
                <select v-model="form.group_id" :class="[input, 'mt-1 block w-full']">
                    <option :value="null">{{ t('att.group_whole') }}</option>
                    <option v-for="g in groupOptions" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </label>
```

(`form.reset()` in the `show` watch already puts `group_id` back to null each time the dialog opens; a `group_id` error shows through the existing `otherErrors` list.)

- [ ] **Step 8: Group select in the Edit dialog**

In `resources/js/Pages/Attendance/Session.vue`:

Add the import right after `import { categoriesForBranch } from '@/lib/categoryPicker';`:

```js
import { groupsFor } from '@/lib/groupPicker';
```

Add one prop right after `playerStatuses: { type: Array, default: () => [] },`:

```js
    groups: { type: Array, default: () => [] }, // the training groups of the session's category: [{ id, branch_id, category_id, name }]
```

Replace the comment line and the form:

```js
// ---- Edit: details any time; branch, roster, categories and kind until marks are first saved ----
```

```js
const editForm = useForm({ title: '', date: '', start_time: '', end_time: '', coach: '', notes: '', branch_id: null, roster_status_ids: [], kind: 'extra', category_ids: [] });
```

with:

```js
// ---- Edit: details any time; branch, group, roster, categories and kind until marks are first saved ----
```

```js
const editForm = useForm({ title: '', date: '', start_time: '', end_time: '', coach: '', notes: '', branch_id: null, group_id: null, roster_status_ids: [], kind: 'extra', category_ids: [] });
// The training groups of the chosen branch and the session's category; none = the field is not shown.
const editGroups = computed(() => groupsFor(props.groups, editForm.branch_id, props.session.category_id));
```

In the `watch(() => editForm.branch_id, …)` callback, right after the line `editForm.category_ids = editForm.category_ids.filter((id) => fits.some((c) => c.id === id));`, add:

```js
    // A group belongs to one branch: it does not follow the session to another.
    if (!editGroups.value.some((g) => g.id === editForm.group_id)) editForm.group_id = null;
```

In `openEdit()`, replace:

```js
        branch_id: s.branch_id, roster_status_ids: [...(s.roster_status_ids ?? [])], kind: s.kind, category_ids: s.categories.map((c) => c.id),
```

with:

```js
        branch_id: s.branch_id, group_id: s.group_id ?? null, roster_status_ids: [...(s.roster_status_ids ?? [])], kind: s.kind, category_ids: s.categories.map((c) => c.id),
```

In `editPayload()`, right after the line `if (handAdded.value && props.branches.length && d.branch_id !== s.branch_id) out.branch_id = d.branch_id;`, add:

```js
        // A pre-season session never has a group: the server clears it with the kind.
        if (handAdded.value && d.kind !== 'preseason' && (d.group_id ?? null) !== (s.group_id ?? null)) out.group_id = d.group_id ?? null;
```

In `submitEdit()`, replace:

```js
        || ['branch_id', 'kind', 'category_ids', 'roster_status_ids'].some((f) => f in payload);
```

with:

```js
        || ['branch_id', 'group_id', 'kind', 'category_ids', 'roster_status_ids'].some((f) => f in payload);
```

In the Edit dialog's template, right after the branch `<label v-if="handAdded && branches.length" …>` … `</label>` block, add:

```vue
                    <label v-if="handAdded && editForm.kind !== 'preseason' && editGroups.length" class="block text-sm">{{ t('att.group') }}
                        <select v-model="editForm.group_id" :class="[input, 'mt-1 block w-full']">
                            <option :value="null">{{ t('att.group_whole') }}</option>
                            <option v-for="g in editGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                    </label>
```

- [ ] **Step 9: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run test:js`
Expected: PASS.

Run: `php artisan test --filter=TrainingSessionGroupTest`
Expected: PASS.

Run: `php artisan test --filter=AttendanceSheetLinksTest`
Expected: PASS (it reads `Index.vue` and `Session.vue`).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/TrainingSessionController.php app/Http/Controllers/AttendanceCalendarController.php app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/Index.vue resources/js/Pages/Attendance/Partials/AddSessionModal.vue resources/js/Pages/Attendance/Session.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/TrainingSessionGroupTest.php
git commit -m "feat(attendance): a hand-added session can belong to a training group"
```

---

### Task 7: Demo club groups and screenshot anchors

The Help Center screenshots are taken on a throwaway demo club (`HelpDemoSeeder`). The new article needs that club to have groups, and the shots need `data-help` anchors on the Groups card and on the schedule form's Group field. Two phase 1 minors of `GroupsCard.vue` are fixed here, since the file is edited anyway.

**Files:**
- Modify: `database/seeders/HelpDemoAttendanceSeeder.php`
- Modify: `tests/Feature/Help/HelpDemoAttendanceSeederTest.php`
- Modify: `resources/js/Pages/Attendance/Partials/GroupsCard.vue`
- Modify: `resources/js/Pages/Attendance/Settings.vue` (one attribute)

**Interfaces:**
- Consumes: `TrainingGroup`, `GroupMembership::add(TrainingGroup $group, int $playerId, ?string $from): void`, `SessionGenerator::forRange(string $from, string $to): void` (phase 1 and before).
- Produces:
  - Demo data: in Swimming · «أصاغر» (branch 1, category 11), `HelpDemoAttendanceSeeder::GROUP_ONE` = «الفوج 1» with players 12, 13, 17 (سارة، إيناس، يوسف) and `GROUP_TWO` = «الفوج 2» with players 18, 22 (لينا، شيماء); two Saturday schedule lines from the seed day (09:30–11:00 for the first, 11:00–12:30 for the second) and their planned sessions. The Tuesday and Thursday lines keep no group.
  - `data-help` anchors: `set-group-list` (the first branch + category block of the Groups card), `group-players-from`, `group-players-list`, `group-players-save` (players dialog), `set-schedule-group` (the schedule form's Group field).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Help/HelpDemoAttendanceSeederTest.php`, add two imports (keep the list alphabetical):

```php
use App\Models\TrainingGroup;
use App\Models\TrainingSchedule;
```

and add these lines at the end of `the_demo_club_has_timetables_sessions_and_marks()`, after the `PreseasonTarget` assertion:

```php

        // Training groups (the training-groups article): «أصاغر» also trains on Saturday in two
        // groups from the seed day on. Their sessions stay planned, so no statistic above moves.
        $swimId = Branch::where('name', 'Swimming')->value('id');
        $groups = TrainingGroup::orderBy('id')->get();
        $this->assertSame([Demo::GROUP_ONE, Demo::GROUP_TWO], $groups->pluck('name')->all());
        $this->assertSame([[$swimId, 11], [$swimId, 11]], $groups->map(fn (TrainingGroup $g) => [$g->branch_id, $g->category_id])->all());
        $members = fn (TrainingGroup $group) => $group->members()->orderBy('player_id')->pluck('player_id')->all();
        $this->assertSame([12, 13, 17], $members($groups[0]));
        $this->assertSame([18, 22], $members($groups[1]));
        $this->assertSame(['سارة', 'إيناس', 'يوسف', 'لينا', 'شيماء'], Player::whereIn('id', [12, 13, 17, 18, 22])->orderBy('id')->pluck('firstname')->all());

        $lines = TrainingSchedule::whereNotNull('group_id')->orderBy('start_time')->get()
            ->map(fn (TrainingSchedule $s) => [$s->group_id, $s->weekday, $s->start_time, $s->end_time, $s->valid_from])->all();
        $this->assertSame([
            [$groups[0]->id, 6, '09:30', '11:00', '2026-11-18'],
            [$groups[1]->id, 6, '11:00', '12:30', '2026-11-18'],
        ], $lines);

        $grouped = TrainingSession::whereNotNull('group_id')->orderBy('date')->orderBy('start_time')->get();
        $this->assertSame([['2026-11-21', '09:30', $groups[0]->id], ['2026-11-21', '11:00', $groups[1]->id]],
            $grouped->take(2)->map(fn (TrainingSession $s) => [$s->date, $s->start_time, $s->group_id])->values()->all());
        $this->assertTrue($grouped->every(fn (TrainingSession $s) => $s->state === SessionState::Planned && $s->date >= '2026-11-18'));
        $this->assertSame(0, Attendance::whereIn('training_session_id', $grouped->modelKeys())->count());
        // The Tuesday and Thursday sessions keep no group: the month grid lists them under «كل الفئة».
        $this->assertSame(0, TrainingSession::where('category_id', 11)->whereNotNull('group_id')->whereIn('start_time', ['17:00'])->count());
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=HelpDemoAttendanceSeederTest`
Expected: FAIL — `Undefined constant Database\Seeders\HelpDemoAttendanceSeeder::GROUP_ONE`.

- [ ] **Step 3: Seed the groups**

In `database/seeders/HelpDemoAttendanceSeeder.php`:

Add two imports (keep the list alphabetical: `TrainingGroup` after `PreseasonTarget`, `GroupMembership` before `MarkRecorder`):

```php
use App\Models\TrainingGroup;
```

```php
use App\Services\Attendance\GroupMembership;
```

Add two constants right after `public const PRESEASON_TARGET = 12;`:

```php

    public const GROUP_ONE = 'الفوج 1';

    public const GROUP_TWO = 'الفوج 2';
```

Extend the class doc comment: after `Sessions the articles open by id are created first (ids 1–3 on the fresh DB).` add ` The training groups come last, so nothing before them changes.`

At the very end of `run()`, after the `foreach ($this->past($cadets, $today) …)` loop, add:

```php

        // Last, so no id and no mark above changes: «أصاغر» also trains on Saturday in two groups.
        $this->groups($swim, $cadets, $today, [
            [self::GROUP_ONE, [$players[11], $players[12], $players[16]], '09:30', '11:00'],
            [self::GROUP_TWO, [$players[17], $players[21]], '11:00', '12:30'],
        ]);
```

and add this method right after `run()`:

```php
    /**
     * Training groups of one category, each with its members (from the beginning) and a
     * Saturday line that starts today: only planned sessions, so no statistic moves. The
     * category's other lines keep no group.
     *
     * @param  list<array{0: string, 1: array<int, Player>, 2: string, 3: string}>  $groups  name, members, start, end
     */
    private function groups(Branch $branch, Category $category, CarbonImmutable $today, array $groups): void
    {
        $membership = app(GroupMembership::class);

        foreach ($groups as [$name, $members, $start, $end]) {
            $group = TrainingGroup::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => $name]);
            foreach ($members as $player) {
                $membership->add($group, $player->id, null);
            }
            TrainingSchedule::create([
                'branch_id' => $branch->id,
                'category_id' => $category->id,
                'group_id' => $group->id,
                'weekday' => 6,
                'start_time' => $start,
                'end_time' => $end,
                'valid_from' => $today->toDateString(),
                'valid_to' => null,
            ]);
        }

        // The new lines' sessions, as opening the calendar would generate them (idempotent).
        app(SessionGenerator::class)->forRange($today->toDateString(), $today->addDays(35)->toDateString());
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=HelpDemo`
Expected: PASS (every demo seeder test: the other seeders run after this one and must not notice).

- [ ] **Step 5: Anchors and two phase 1 minors in the Groups card**

In `resources/js/Pages/Attendance/Partials/GroupsCard.vue`:

(Phase 1 minor: the players dialog compared the ticks with the live props; the baseline is now a snapshot taken when the dialog opens.) Replace:

```js
const membersBefore = () => roster.value.filter((p) => p.group_id === open.value.id).map((p) => p.id);
function openPlayers(group) {
    open.value = group;
    query.value = '';
    memberError.value = '';
    ticked.value = (props.rosters[group.key] ?? []).filter((p) => p.group_id === group.id).map((p) => p.id);
```

with:

```js
const before = ref([]); // the group's members when the dialog opened: what the ticks are compared with
function openPlayers(group) {
    open.value = group;
    query.value = '';
    memberError.value = '';
    ticked.value = (props.rosters[group.key] ?? []).filter((p) => p.group_id === group.id).map((p) => p.id);
    before.value = [...ticked.value];
```

and replace:

```js
    const { add, remove } = memberChanges(membersBefore(), ticked.value);
```

with:

```js
    const { add, remove } = memberChanges(before.value, ticked.value);
```

(Phase 1 minor: `IconButton` takes no pointer events while disabled, so the tooltip that says why a group cannot be deleted never showed.) Replace:

```vue
                            <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" :disabled="g.in_use" :title="g.in_use ? t('flash.training_group_in_use') : t('att.delete')" @click="destroy(g)" />
```

with:

```vue
                            <!-- A disabled IconButton takes no pointer events, so its own tooltip never shows: the span carries the reason. -->
                            <span v-if="g.in_use" class="inline-flex cursor-not-allowed" :title="t('flash.training_group_in_use')">
                                <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" disabled />
                            </span>
                            <IconButton v-else icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" @click="destroy(g)" />
```

Anchors. Replace:

```vue
        <div v-for="s in sections" :key="s.key" class="border-t border-slate-100 py-2 dark:border-slate-800">
```

with:

```vue
        <div v-for="(s, i) in sections" :key="s.key" :data-help="i === 0 ? 'set-group-list' : null" class="border-t border-slate-100 py-2 dark:border-slate-800">
```

Replace (in the players dialog):

```vue
                <label class="block text-sm">{{ t('att.group_from') }}
```

with:

```vue
                <label data-help="group-players-from" class="block text-sm">{{ t('att.group_from') }}
```

Replace:

```vue
                <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
```

with:

```vue
                <ul data-help="group-players-list" class="max-h-80 space-y-1 overflow-y-auto text-sm">
```

Replace (the dialog's submit button, the one with `:disabled="saving"`):

```vue
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
```

with:

```vue
                    <button data-help="group-players-save" type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
```

In `resources/js/Pages/Attendance/Settings.vue`, replace:

```vue
                    <label v-if="scheduleGroups.length" class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.group') }}
```

with:

```vue
                    <label v-if="scheduleGroups.length" data-help="set-schedule-group" class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.group') }}
```

- [ ] **Step 6: Build and run the checks**

Run: `npm run build`
Expected: builds with no error.

Run: `npm run test:js`
Expected: PASS.

Run: `php artisan test --filter=AttendanceSettings`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/seeders/HelpDemoAttendanceSeeder.php tests/Feature/Help/HelpDemoAttendanceSeederTest.php resources/js/Pages/Attendance/Partials/GroupsCard.vue resources/js/Pages/Attendance/Settings.vue
git commit -m "chore(help): training groups in the demo club and anchors for their shots"
```

---

### Task 8: Help Center

A new illustrated article about training groups in the three languages, one paragraph in each article whose screen changed (month grid, add session, branch card), and the shots: seven new ones, the ones whose screen changed, and the ones of the edited articles.

**Files:**
- Modify: `resources/help/shots.js`
- Create: `resources/help/ar/attendance/training-groups.md`, `resources/help/fr/attendance/training-groups.md`, `resources/help/en/attendance/training-groups.md`
- Modify: `resources/help/topics.php`
- Modify: `resources/help/{ar,fr,en}/attendance/month-grid.md`, `resources/help/{ar,fr,en}/attendance/add-session.md`, `resources/help/{ar,fr,en}/attendance/timetable.md`, `resources/help/{ar,fr,en}/players/branch-registrations.md`
- Create / replace: images and JSON under `public/help-shots/{ar,fr,en}/` for the shots listed in Step 7

**Interfaces:**
- Consumes: the demo groups and the anchors of Task 7; `data-help` anchors `player-group`, `player-group-from` (Task 3), `grid-groups` (Task 4), `add-group` (Task 6); existing anchors `set-groups`, `set-group-form`, `set-group-add`, `set-group-players`, `set-schedules`, `set-schedule-form`, `set-schedule-add`, `grid-print`, `add-category`, `player-branch-card`, `att-add-extra`, `att-settings`; existing shot `att-calendar`.
- Produces: shots `set-group-typed`, `set-group-list`, `set-group-dialog`, `set-schedule-group`, `att-grid-groups`, `att-add-extra-group`, `player-edit-group`; article `attendance/training-groups`.

- [ ] **Step 1: Read the authoring rules**

Read `resources/help/README.md` in full (sections "Rules", "Screenshots", "Translations (French, English)", "Check before committing"). Follow it wherever it is stricter than this task. Open `resources/help/fr/attendance/timetable.md` and `resources/help/en/attendance/timetable.md` to see the quote style of each language: French uses « … » with no-break spaces inside the quotes and before `?`, `:` and `;`; English uses curly quotes “ … ”. The French texts below are typed with plain spaces: put the no-break spaces in when you save them.

- [ ] **Step 2: Shots**

In `resources/help/shots.js`:

Replace:

```js
// Swimming «أصاغر» (branch 1, category 11): its next sessions after the cancelled session 3.
const cadetsGrid = (month) => `/attendance/grid?branch_id=1&category_id=11&month=${month}`;
```

with:

```js
// Swimming «أصاغر» (branch 1, category 11): its next sessions after the cancelled session 3.
// The category has two training groups (HelpDemoAttendanceSeeder): group_id=0 keeps the grid on
// its Tuesday and Thursday sessions, which have no group, as before the groups existed.
const CADETS = 'branch_id=1&category_id=11';
const cadetsGrid = (month) => `/attendance/grid?${CADETS}&group_id=0&month=${month}`;
// The two groups train on Saturday from the seed day on: the month of the first Saturday from today.
const groupMonth = ym(nextDay([6]));
```

Add this shot right after the `player-edit-new-branch` shot (before the comment `// The list is longer than the screen: the first row carries the ring.`):

```js
    {
        // The training-groups example: سارة بن يوسف (player 12, «الفوج 1») moved to «الفوج 2» on her
        // swimming card. The «تُطبَّق التغييرات من» date appears, with today. Never saved.
        name: 'player-edit-group',
        url: '/players/12/edit',
        targets: ['player-branch-card', 'player-group', 'player-group-from'],
        before: async (page) => {
            await page.locator('[data-help="player-group"] select').selectOption({ label: 'الفوج 2' });
            await page.locator('[data-help="player-group-from"]').waitFor();
            await blur(page);
            await centre(page, 'player-branch-card');
        },
    },
```

Add these two shots right after the `att-add-preseason-modal` shot (before the comment `// The month-grid example: the coach's sheet has أمين «R15» in one column.`):

```js
    {
        // The Group field of «حصة إضافية»: Swimming · «أصاغر» has two groups; «الفوج 1» is chosen.
        // The dialog is only filled in, never sent.
        name: 'att-add-extra-group',
        url: `/attendance?view=month&${CADETS}&month=${groupMonth}`,
        targets: ['add-category', 'add-group'],
        before: async (page) => {
            await page.locator('[data-help="att-add-extra"]').click();
            const group = page.locator('[data-help="add-group"] select');
            await group.waitFor();
            await group.selectOption({ label: 'الفوج 1' });
            await blur(page);
        },
    },
    // The month grid of a category split into groups: it opens on the first group («الفوج 1»),
    // with its Saturday sessions and its three swimmers only.
    { name: 'att-grid-groups', url: `/attendance/grid?${CADETS}&month=${groupMonth}`, targets: ['grid-groups', 'grid-print'] },
```

In the `set-schedule` shot, replace its last line `await centre(page, 'set-schedules');` with (phase 1 minor: the card above showed cut under the sticky header):

```js
            await underHeader(page, 'set-schedules');
```

Add these four shots right after the `set-custom` shot (before the comment that starts with `// The stats-ranking and alerts-injuries examples.`):

```js
    // The training-groups example: Swimming · «أصاغر» in two Saturday groups. The demo already
    // holds both groups, their players and their two lines (HelpDemoAttendanceSeeder); each shot
    // puts the page back in the reader's state at that step. Forms are only filled, never sent.
    {
        // «الفوج 1» typed in the Groups card. The list of groups is taken off (DOM only): the
        // reader has not pressed «إضافة» yet.
        name: 'set-group-typed',
        url: '/attendance/settings',
        targets: ['set-groups', 'set-group-form', 'set-group-add'],
        before: async (page) => {
            const form = page.locator('[data-help="set-group-form"]');
            await choose(form.locator('select').nth(0), 'السباحة');
            await choose(form.locator('select').nth(1), 'أصاغر');
            await form.locator('input[type="text"]').fill('الفوج 1');
            await page.locator('[data-help="set-group-list"]').evaluate((el) => el.remove());
            await blur(page);
            await underHeader(page, 'set-groups');
        },
    },
    // After both were added: the two groups listed, each with its «لاعبو الفوج» button.
    { name: 'set-group-list', url: '/attendance/settings', targets: ['set-groups', 'set-group-list', 'set-group-players'], before: (page) => underHeader(page, 'set-groups') },
    {
        // «لاعبو الفوج» of «الفوج 1»: its three swimmers ticked. The date is emptied, as it is
        // the first time (a group that has members opens on today).
        name: 'set-group-dialog',
        url: '/attendance/settings',
        targets: ['group-players-from', 'group-players-list', 'group-players-save'],
        before: async (page) => {
            await page.locator('[data-help="set-group-players"]').first().click();
            await page.locator('[data-help="group-players-save"]').waitFor();
            await page.locator('[data-help="group-players-from"] input').fill('');
            await blur(page);
        },
    },
    {
        // The Saturday 09:30–11:00 line of «الفوج 1» typed in the weekly schedule. The demo's own
        // two Saturday lines are taken off the list (DOM only): the reader has not added them yet.
        name: 'set-schedule-group',
        url: '/attendance/settings',
        targets: ['set-schedules', 'set-schedule-form', 'set-schedule-group', 'set-schedule-add'],
        before: async (page) => {
            const form = page.locator('[data-help="set-schedule-form"]');
            await choose(form.locator('select').nth(0), 'السباحة');
            await choose(form.locator('select').nth(1), 'أصاغر');
            const group = page.locator('[data-help="set-schedule-group"] select');
            await group.waitFor();
            await group.selectOption({ label: 'الفوج 1' });
            // The Group field sits between the category and the day: the day is the fourth select.
            await form.locator('select').nth(3).selectOption('6');
            await form.locator('input[type="time"]').nth(0).fill('09:30');
            await form.locator('input[type="time"]').nth(1).fill('11:00');
            await page.locator('[data-help="set-schedules"] tbody tr', { hasText: 'الفوج' }).evaluateAll((rows) => rows.forEach((row) => row.remove()));
            await blur(page);
            await underHeader(page, 'set-schedules');
        },
    },
```

Add this helper right after the `centre()` function (before the comment of `settle()`):

```js
/**
 * Brings the element's top just under whatever sticks to the top of the page (the top
 * bar), so the card above it is out of the shot instead of cut in half. Falls back to
 * centre() when the element is then too tall for the screen.
 */
async function underHeader(page, name) {
    const el = page.locator(`[data-help="${name}"]`).first();
    await settle(page);
    const fits = await el.evaluate((node) => {
        node.scrollIntoView({ block: 'start', behavior: 'instant' });
        let bar = document.elementFromPoint(window.innerWidth / 2, 1);
        while (bar && !['sticky', 'fixed'].includes(getComputedStyle(bar).position)) bar = bar.parentElement;
        const covered = bar ? bar.getBoundingClientRect().bottom : 0;
        window.scrollBy({ top: -(covered + 12), behavior: 'instant' });
        const box = node.getBoundingClientRect();
        return box.top >= covered && box.bottom <= window.innerHeight;
    });
    await settle(page);
    if (!fits) await centre(page, name);
}
```

- [ ] **Step 3: The Arabic article**

Create `resources/help/ar/attendance/training-groups.md`:

```markdown
---
title: كيف أقسّم فئة إلى أفواج
summary: قسّم لاعبي الفئة إلى أفواج، لكل فوج حصصه وقائمة لاعبيه وورقته.
pages: [attendance.settings, attendance.grid]
keywords: [الأفواج, فوج, أفواج التدريب, تقسيم الفئة, مجموعة, مجموعات, لاعبو الفوج, نقل لاعب, تغيير الفوج, ورقة الفوج]
related: [attendance/timetable, attendance/month-grid, players/branch-registrations]
---

فئة واحدة تتدرّب في وقتين مختلفين؟ قسّمها إلى أفواج. حصة كل فوج تعرض لاعبيه فقط.

:::example
فئة «أصاغر» في «السباحة» تتدرّب يوم السبت في فوجين. «الفوج 1» من 09:30 إلى 11:00، و«الفوج 2» من 11:00 إلى 12:30. بعد مدة، تنتقل **سارة بن يوسف** من «الفوج 1» إلى «الفوج 2».
:::

:::step افتح «إعدادات الحضور»
في صفحة «الحضور»، اضغط على الزر الذي عليه ثلاثة خطوط، على كل خط دائرة صغيرة. عندما تضع الفأرة عليه يظهر اسمه «إعدادات الحضور». لا تراه؟ تحتاج إلى صلاحية «تعديل» في «الحضور».
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step أضف الفوجين
في بطاقة «الأفواج»، اختر «السباحة» في «فرع»، و«أصاغر» في «الفئة». اكتب «الفوج 1» في «اسم الفوج». اضغط «إضافة». تظهر عبارة «تم حفظ الفوج.». أضف «الفوج 2» بالطريقة نفسها.
::figure set-group-typed ring=set-group-form,set-group-add
:::

:::step ضع علامة على لاعبي كل فوج
في سطر «الفوج 1»، اضغط على الزر الذي عليه رسم شخصين. عندما تضع الفأرة عليه يظهر اسمه «لاعبو الفوج». تظهر قائمة لاعبي الفئة. ضع علامة أمام سارة وإيناس ويوسف. في أول مرة، خانة التاريخ فارغة: اتركها كذلك. اضغط «حفظ». تظهر عبارة «تم حفظ لاعبي الفوج.». افعل الشيء نفسه مع «الفوج 2».
::figure set-group-list ring=set-group-players zoom=set-group-players
::figure set-group-dialog ring=group-players-list,group-players-save
:::

:::step اختر الفوج في سطر التوقيت
في بطاقة «التوقيت الأسبوعي»، اختر «السباحة» ثم «أصاغر». تظهر خانة «الفوج»: اختر «الفوج 1». في «اليوم»، اختر «السبت». اكتب 09:30 في «البداية»، و11:00 في «النهاية». اضغط «إضافة». أضف سطر «الفوج 2» من 11:00 إلى 12:30.
::figure set-schedule-group ring=set-schedule-group,set-schedule-add
:::

:::step افتح ورقة كل فوج
في صفحة «الحضور»، اختر «السباحة» ثم «أصاغر»، وافتح «جدول الشهر». فوق الجدول شريط فيه اسم كل فوج. اضغط «الفوج 1»: يعرض الجدول حصصه ولاعبيه فقط. زر الطابعة «طباعة الورقة» يطبع ورقة هذا الفوج، واسمه في أعلاها.
::figure att-grid-groups ring=grid-groups,grid-print
:::

:::step انقل لاعبًا إلى فوج آخر
افتح صفحة سارة، واضغط زر «تعديل اللاعب». في بطاقة «السباحة»، اختر «الفوج 2» في «الفوج». تظهر خانة «تُطبَّق التغييرات من» وفيها تاريخ اليوم: اختر يوم الانتقال. اضغط «حفظ التغييرات».
::figure player-edit-group ring=player-group,player-group-from
:::

قبل يوم الانتقال، تبقى سارة في قائمة «الفوج 1» في الحصص التي لم يُسجَّل حضورها. ومن ذلك اليوم تظهر مع «الفوج 2». الحصة التي سُجّل حضورها لا تتغيّر أبدًا.

أخطأت في الفوج من البداية؟ امسح تاريخ «تُطبَّق التغييرات من» قبل الحفظ: يُطبَّق التغيير من البداية.

لاعب في الفئة وليس في أي فوج؟ لا يظهر في حصص الأفواج. في بطاقة «الأفواج» تظهر شارة «بدون فوج» وعددهم: اضغط عليها لترى الأسماء. يمكنك أيضًا اختيار «الفوج» في بطاقة الفرع عند تسجيل اللاعب.

سطر توقيت تركته على «كل الفئة» تعرض حصصه كل لاعبي الفئة. في «جدول الشهر»، تجد هذه الحصص تحت «كل الفئة».

:::tip
جاء لاعب مع الفوج الآخر ليعوّض حصة؟ في صفحة الحصة، ابحث عنه في خانة البحث تحت القائمة وأضفه. يبقى في فوجه.
:::

:::warning
لا يُحذف فوج يستعمله سطر في «التوقيت الأسبوعي» أو حصة: زر الحذف يبقى باهتًا. لتغيير اسمه، اضغط زر القلم.
:::
```

- [ ] **Step 4: The French and English twins**

Create `resources/help/fr/attendance/training-groups.md` (same blocks and `::figure` lines; put the no-break spaces in, see Step 1):

```markdown
---
title: Comment répartir une catégorie en groupes
summary: Répartissez les joueurs d'une catégorie en groupes : chaque groupe a ses séances, sa liste de joueurs et sa feuille.
pages: [attendance.settings, attendance.grid]
keywords: [groupes, groupe, groupes d'entraînement, répartir la catégorie, joueurs du groupe, changer de groupe, déplacer un joueur, feuille du groupe]
related: [attendance/timetable, attendance/month-grid, players/branch-registrations]
---

Une catégorie s'entraîne à deux horaires différents ? Répartissez-la en groupes. La séance d'un groupe n'affiche que ses joueurs.

:::example
La catégorie « أصاغر » en « Natation » s'entraîne le samedi en deux groupes. « الفوج 1 » de 09:30 à 11:00, et « الفوج 2 » de 11:00 à 12:30. Plus tard, **سارة بن يوسف** passe de « الفوج 1 » à « الفوج 2 ».
:::

:::step Ouvrez les « Réglages des présences »
Sur la page « Présence », cliquez sur le bouton avec trois lignes portant chacune un petit rond. Son nom « Réglages des présences » s'affiche quand vous passez la souris dessus. Vous ne le voyez pas ? Il vous faut le droit « Modifier » dans « Présence ».
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Ajoutez les deux groupes
Dans la carte « Groupes », choisissez « Natation » dans « Section », et « أصاغر » dans « Catégorie ». Tapez « الفوج 1 » dans « Nom du groupe ». Cliquez sur « Ajouter ». Le message « Groupe enregistré. » s'affiche. Ajoutez « الفوج 2 » de la même façon.
::figure set-group-typed ring=set-group-form,set-group-add
:::

:::step Cochez les joueurs de chaque groupe
Sur la ligne de « الفوج 1 », cliquez sur le bouton avec le dessin de deux personnes. Son nom « Joueurs du groupe » s'affiche quand vous passez la souris dessus. La liste des joueurs de la catégorie s'affiche. Cochez سارة, إيناس et يوسف. La première fois, le champ de date est vide : laissez-le ainsi. Cliquez sur « Enregistrer ». Le message « Joueurs du groupe enregistrés. » s'affiche. Faites de même pour « الفوج 2 ».
::figure set-group-list ring=set-group-players zoom=set-group-players
::figure set-group-dialog ring=group-players-list,group-players-save
:::

:::step Choisissez le groupe sur la ligne de l'horaire
Dans la carte « Horaire hebdomadaire », choisissez « Natation » puis « أصاغر ». Le champ « Groupe » apparaît : choisissez « الفوج 1 ». Dans « Jour », choisissez « samedi ». Tapez 09:30 dans « Début », et 11:00 dans « Fin ». Cliquez sur « Ajouter ». Ajoutez la ligne de « الفوج 2 », de 11:00 à 12:30.
::figure set-schedule-group ring=set-schedule-group,set-schedule-add
:::

:::step Ouvrez la feuille de chaque groupe
Sur la page « Présence », choisissez « Natation » puis « أصاغر », et ouvrez la « Grille du mois ». Au-dessus de la grille, une barre porte le nom de chaque groupe. Cliquez sur « الفوج 1 » : la grille n'affiche que ses séances et ses joueurs. Le bouton imprimante « Imprimer la feuille » imprime la feuille de ce groupe, avec son nom en haut.
::figure att-grid-groups ring=grid-groups,grid-print
:::

:::step Changez un joueur de groupe
Ouvrez la page de سارة, et cliquez sur le bouton « Modifier le joueur ». Dans la carte « Natation », choisissez « الفوج 2 » dans « Groupe ». Le champ « Changements appliqués à partir du » apparaît avec la date du jour : choisissez le jour du changement. Cliquez sur « Enregistrer les modifications ».
::figure player-edit-group ring=player-group,player-group-from
:::

Avant le jour du changement, سارة reste sur la liste de « الفوج 1 » dans les séances dont les présences ne sont pas enregistrées. À partir de ce jour, elle apparaît avec « الفوج 2 ». Une séance dont les présences sont enregistrées ne change jamais.

Vous vous êtes trompé de groupe dès le début ? Effacez la date de « Changements appliqués à partir du » avant d'enregistrer : le changement s'applique depuis le début.

Un joueur de la catégorie n'est dans aucun groupe ? Il n'apparaît pas dans les séances des groupes. La carte « Groupes » affiche un badge « Sans groupe » avec leur nombre : cliquez dessus pour voir les noms. Vous pouvez aussi choisir le « Groupe » dans la carte de la section, à l'inscription du joueur.

Une ligne de l'horaire laissée sur « Toute la catégorie » affiche tous les joueurs de la catégorie dans ses séances. Dans la « Grille du mois », ces séances sont sous « Toute la catégorie ».

:::tip
Un joueur vient avec l'autre groupe pour rattraper une séance ? Sur la page de la séance, cherchez-le dans le champ de recherche sous la liste et ajoutez-le. Il reste dans son groupe.
:::

:::warning
Un groupe utilisé par une ligne de l'« Horaire hebdomadaire » ou par une séance ne peut pas être supprimé : le bouton de suppression reste grisé. Pour changer son nom, cliquez sur le bouton crayon.
:::
```

Create `resources/help/en/attendance/training-groups.md`:

```markdown
---
title: How do I split a category into training groups
summary: Split a category's players into groups. Each group has its own sessions, its own list of players and its own sheet.
pages: [attendance.settings, attendance.grid]
keywords: [groups, group, training groups, split a category, group players, change group, move a player, group sheet]
related: [attendance/timetable, attendance/month-grid, players/branch-registrations]
---

Does one category train at two different times? Split it into groups. A group's session lists only its players.

:::example
The “أصاغر” category in “Swimming” trains on Saturday in two groups. “الفوج 1” from 09:30 to 11:00, and “الفوج 2” from 11:00 to 12:30. Later, **سارة بن يوسف** moves from “الفوج 1” to “الفوج 2”.
:::

:::step Open “Attendance settings”
On the “Attendance” page, click the button with three lines, each with a small circle on it. Its name, “Attendance settings”, shows when you hover the mouse over it. You don't see it? You need the “Edit” permission for “Attendance”.
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Add the two groups
In the “Groups” card, choose “Swimming” in “Branch”, and “أصاغر” in “Category”. Type “الفوج 1” in “Group name”. Click “Add”. The message “Group saved.” appears. Add “الفوج 2” the same way.
::figure set-group-typed ring=set-group-form,set-group-add
:::

:::step Tick the players of each group
On the “الفوج 1” row, click the button with a drawing of two people. Its name, “Group players”, shows when you hover the mouse over it. The list of the category's players appears. Tick سارة, إيناس and يوسف. The first time, the date field is empty: leave it empty. Click “Save”. The message “Group players saved.” appears. Do the same for “الفوج 2”.
::figure set-group-list ring=set-group-players zoom=set-group-players
::figure set-group-dialog ring=group-players-list,group-players-save
:::

:::step Choose the group on the schedule line
In the “Weekly schedule” card, choose “Swimming”, then “أصاغر”. The “Group” field appears: choose “الفوج 1”. In “Day”, choose “Saturday”. Type 09:30 in “Start”, and 11:00 in “End”. Click “Add”. Add the “الفوج 2” line, from 11:00 to 12:30.
::figure set-schedule-group ring=set-schedule-group,set-schedule-add
:::

:::step Open each group's sheet
On the “Attendance” page, choose “Swimming”, then “أصاغر”, and open the “Month grid”. Above the grid, a bar shows the name of each group. Click “الفوج 1”: the grid shows only its sessions and its players. The printer button, “Print sheet”, prints this group's sheet, with its name at the top.
::figure att-grid-groups ring=grid-groups,grid-print
:::

:::step Move a player to another group
Open سارة's page, and click the “Edit Player” button. In the “Swimming” card, choose “الفوج 2” in “Group”. The “Changes apply from” field appears with today's date: choose the day of the move. Click “Save Changes”.
::figure player-edit-group ring=player-group,player-group-from
:::

Before the day of the move, سارة stays on the “الفوج 1” list in the sessions whose attendance is not saved yet. From that day on, she appears with “الفوج 2”. A session whose attendance is saved never changes.

Was the group wrong from the start? Clear the “Changes apply from” date before saving: the change applies from the beginning.

Is a player of the category in no group? They do not appear in group sessions. The “Groups” card shows a “Without group” badge with how many they are: click it to see the names. You can also choose the “Group” in the branch card when you register the player.

A schedule line left on “Whole category” lists every player of the category in its sessions. In the “Month grid”, these sessions are under “Whole category”.

:::tip
A player comes with the other group to make up a session? On the session's page, find them in the search field under the list and add them. They stay in their own group.
:::

:::warning
A group used by a “Weekly schedule” line or by a session cannot be deleted: the delete button stays greyed out. To change its name, click the pencil button.
:::
```

- [ ] **Step 5: List the article**

In `resources/help/topics.php`, in the `'attendance'` line, replace:

```php
'cancel-delete-session', 'timetable', 'codes-rules',
```

with:

```php
'cancel-delete-session', 'timetable', 'training-groups', 'codes-rules',
```

- [ ] **Step 6: One paragraph in each article whose screen changed**

**Month grid.** In the three `attendance/month-grid.md` files, add `attendance/training-groups` at the end of the `related:` list, add the two keywords before the last keyword (ar: `الأفواج, فوج`; fr: `groupes, groupe`; en: `groups, group`), and insert the paragraph and its figure, with one blank line before and after, right before the paragraph that starts with «الخانة «—» تعني» (fr: `La case « — » signifie`; en: `The cell “—” means`).

`ar`:

```markdown
فئة مقسّمة إلى أفواج؟ فوق الجدول شريط فيه اسم كل فوج. اضغط على الفوج لترى حصصه ولاعبيه فقط. «كل الفئة» تعرض الحصص التي بلا فوج. زر الطابعة يطبع ورقة الفوج المعروض، واسمه في أعلاها.
::figure att-grid-groups ring=grid-groups
```

`fr`:

```markdown
Une catégorie répartie en groupes ? Au-dessus de la grille, une barre porte le nom de chaque groupe. Cliquez sur un groupe pour voir seulement ses séances et ses joueurs. « Toute la catégorie » affiche les séances sans groupe. Le bouton imprimante imprime la feuille du groupe affiché, avec son nom en haut.
::figure att-grid-groups ring=grid-groups
```

`en`:

```markdown
Is the category split into groups? Above the grid, a bar shows the name of each group. Click a group to see only its sessions and its players. “Whole category” shows the sessions with no group. The printer button prints the sheet of the group on screen, with its name at the top.
::figure att-grid-groups ring=grid-groups
```

**Add session.** In the three `attendance/add-session.md` files, add `attendance/training-groups` at the end of the `related:` list, and insert, with one blank line before and after, right before the paragraph that starts with «لا ترى الزرين؟» (fr: `Vous ne voyez pas les deux boutons ?`; en: `You don't see the two buttons?`).

`ar`:

```markdown
فئة مقسّمة إلى أفواج؟ في نافذة «حصة إضافية» تظهر خانة «الفوج». اختر الفوج لتعرض الحصة لاعبيه فقط، أو اتركها على «كل الفئة». حصة التحضير البدني لا فوج لها.
::figure att-add-extra-group ring=add-group
```

`fr`:

```markdown
Une catégorie répartie en groupes ? La fenêtre « Séance supplémentaire » affiche le champ « Groupe ». Choisissez le groupe pour que la séance n'affiche que ses joueurs, ou laissez « Toute la catégorie ». Une séance de préparation n'a pas de groupe.
::figure att-add-extra-group ring=add-group
```

`en`:

```markdown
Is the category split into groups? The “Extra session” window shows a “Group” field. Choose the group so the session lists only its players, or leave “Whole category”. A pre-season session has no group.
::figure att-add-extra-group ring=add-group
```

**Timetable.** In the three `attendance/timetable.md` files, add `attendance/training-groups` at the end of the `related:` list, and add one sentence at the end of the paragraph phase 1 added (it starts with «فئة تتدرّب في أكثر من فوج؟»; fr: `Une catégorie s'entraîne en plusieurs groupes ?`; en: `Does a category train in more than one group?`):

- `ar`: ` الشرح خطوة بخطوة في «كيف أقسّم فئة إلى أفواج».`
- `fr`: ` Le pas-à-pas est dans « Comment répartir une catégorie en groupes ».`
- `en`: ` The step-by-step guide is “How do I split a category into training groups”.`

**Branch card.** In the three `players/branch-registrations.md` files, add `attendance/training-groups` at the end of the `related:` list, and insert, with one blank line before and after, right before the `:::warning` block.

`ar`:

```markdown
فئة الفرع مقسّمة إلى أفواج؟ تظهر خانة «الفوج» في بطاقة الفرع. اختر فيها فوج اللاعب. لتغيير الفوج لاحقًا، اقرأ «كيف أقسّم فئة إلى أفواج».
```

`fr`:

```markdown
La catégorie de la section est répartie en groupes ? Le champ « Groupe » apparaît dans la carte de la section. Choisissez-y le groupe du joueur. Pour le changer plus tard, lisez « Comment répartir une catégorie en groupes ».
```

`en`:

```markdown
Is the branch's category split into groups? A “Group” field appears in the branch card. Choose the player's group there. To change it later, read “How do I split a category into training groups”.
```

(If another feature has changed these files meanwhile, keep its text and only add these lines.)

- [ ] **Step 7: Take the shots in the three languages**

The script seeds its own database (`storage/help-demo.sqlite`), serves it on port 8765 and stops its server itself; it needs the fresh build. The owner's servers on 2026 and 5173 are not used: leave them alone. Do not run anything else in the worktree while it runs.

```bash
npm run build
npm run help:shots -- set-group-typed set-group-list set-group-dialog set-schedule-group att-grid-groups att-add-extra-group player-edit-group set-schedule set-closure-filled set-closure set-codes set-rules set-custom player-edit-new-branch att-grid att-grid-typed att-grid-moved att-add-preseason-modal att-session-actions att-cancel-modal
npm run help:shots -- --locale=fr set-group-typed set-group-list set-group-dialog set-schedule-group att-grid-groups att-add-extra-group player-edit-group set-schedule set-closure-filled set-closure set-codes set-rules set-custom player-edit-new-branch att-grid att-grid-typed att-grid-moved att-add-preseason-modal att-session-actions att-cancel-modal
npm run help:shots -- --locale=en set-group-typed set-group-list set-group-dialog set-schedule-group att-grid-groups att-add-extra-group player-edit-group set-schedule set-closure-filled set-closure set-codes set-rules set-custom player-edit-new-branch att-grid att-grid-typed att-grid-moved att-add-preseason-modal att-session-actions att-cancel-modal
```

Why these twenty: the first seven are new; the six `set-*` ones are the settings page, whose Groups card is now filled; `player-edit-new-branch` is the swimming card, which gains the «الفوج» field; `att-grid*` and `att-add-preseason-modal` belong to the two edited attendance articles; `att-session-actions` and `att-cancel-modal` open the «أصاغر» grid, whose URL changed.

Expected: each run ends with a `✓ light …` and a `✓ dark …` line per shot and no `✗`. If a run stops on "no element with data-help", the anchor of that name is missing on the page: fix the page or the shot, never the check.

Then look at the Arabic light image of each new shot (`public/help-shots/ar/light/<name>.webp`) and at one French and one English image:

- `set-group-typed`: the Groups card with «الفوج 1» typed, no list under the form.
- `set-group-list`: both groups listed with «اللاعبون: 3» and «اللاعبون: 2».
- `set-group-dialog`: three ticked names, two names tagged «في الفوج 2», an empty date.
- `set-schedule-group`: the «الفوج» field between the category and the day, «الفوج 1» chosen, Saturday, 09:30–11:00; no Saturday line in the list.
- `att-grid-groups`: a switch «الفوج 1 | الفوج 2 | كل الفئة» with «الفوج 1» lit, Saturday columns only, three rows.
- `att-add-extra-group`: the dialog with «الفوج» set to «الفوج 1».
- `player-edit-group`: سارة's swimming card with «الفوج 2» and the date field holding today.
- `set-schedule`: the weekly schedule card starts right under the top bar; no card is cut above it.

- [ ] **Step 8: Check**

Run: `php artisan help:check`
Expected: `Help content OK — 75 article(s).` (74 before, when no other feature added one).

Run: `php artisan help:check --locale=fr` and `php artisan help:check --locale=en`
Expected: OK for both.

Run: `php artisan test --filter=Help`
Expected: PASS.

- [ ] **Step 9: Commit**

Stage the article files, `shots.js`, `topics.php`, and each image and JSON the runs wrote (`git status --short public/help-shots` lists them), by path:

```bash
git add resources/help/shots.js resources/help/topics.php
git add resources/help/ar/attendance/training-groups.md resources/help/fr/attendance/training-groups.md resources/help/en/attendance/training-groups.md
git add resources/help/ar/attendance/month-grid.md resources/help/fr/attendance/month-grid.md resources/help/en/attendance/month-grid.md
git add resources/help/ar/attendance/add-session.md resources/help/fr/attendance/add-session.md resources/help/en/attendance/add-session.md
git add resources/help/ar/attendance/timetable.md resources/help/fr/attendance/timetable.md resources/help/en/attendance/timetable.md
git add resources/help/ar/players/branch-registrations.md resources/help/fr/players/branch-registrations.md resources/help/en/players/branch-registrations.md
git add <each public/help-shots/{ar,fr,en}/ file listed by git status>
git commit -m "docs(help): training groups step by step"
```

---

### Task 9: Whole-branch verification

**Files:** none (verification only; a fix goes into the task it belongs to, as a new commit).

- [ ] **Step 1: Run the whole PHP suite in the background**

Run (background, one run at a time): `php -d memory_limit=-1 vendor/phpunit/phpunit/phpunit`
Expected: all tests pass. A failure in a test this plan did not write means a shared rule changed: read it, fix the cause, run the whole suite again.

- [ ] **Step 2: Run the front-end checks**

Run: `npm run build` — Expected: no error.
Run: `npm run i18n:check` — Expected: PASS.
Run: `npm run test:js` — Expected: PASS.
Run: `php artisan help:check` — Expected: no error (also with `--locale=fr` and `--locale=en`).

- [ ] **Step 3: Prepare a copy of the dev database**

This phase has no migration; the copy only has to be up to date with `main`. Copy `D:/irnb-laravel/database/database.sqlite` to the worktree as `database/groups-p2-check.sqlite` (read the live file once to copy it; never open it from the worktree). Then:

```bash
DB_DATABASE="$(pwd)/database/groups-p2-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p2-check.php" php artisan migrate --force
DB_DATABASE="$(pwd)/database/groups-p2-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p2-check.php" php artisan migrate --force
```

Expected: the first run applies what the copy lacks, if anything (never a migration of this branch: it has none); the second says "Nothing to migrate".

Create a throwaway account on the copy to sign in with:

```bash
DB_DATABASE="$(pwd)/database/groups-p2-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p2-check.php" php artisan tinker --execute="App\Models\User::create(['name' => 'QA P2', 'username' => 'qa-p2', 'email' => 'qa-p2@example.invalid', 'password' => Illuminate\Support\Facades\Hash::make('qa-p2-pass'), 'firstname' => 'QA', 'lastname' => 'P2', 'gender' => 'Male', 'phones' => [], 'is_user' => true, 'is_active' => true, 'approved' => true, 'privileges' => ['superadmin', 'admin'], 'preferred_lng' => 'ar', 'email_verified_at' => now()]);"
```

- [ ] **Step 4: Browser check on that copy, driven by Playwright**

Serve the copy on port 2027 (ports 2026 and 5173 belong to the owner: do not touch them):

```bash
DB_DATABASE="$(pwd)/database/groups-p2-check.sqlite" APP_CONFIG_CACHE="$(pwd)/bootstrap/cache/config-groups-p2-check.php" php artisan serve --port=2027 --no-reload
```

Drive a real browser with the repository's Playwright (`import { chromium } from 'playwright'`, `chromium.launch({ channel: 'chromium' })`, as `scripts/help-shots.mjs` does) from a throwaway script kept in `.superpowers/sdd/qa/p2/` (git-ignored), signing in at `http://127.0.0.1:2027/login` as `qa-p2` / `qa-p2-pass`, and saving one screenshot per check there. Work on a swimming category that has at least four players on its list.

Setup (phase 1 screens), only if that category has no groups yet on the copy: in Attendance → settings, add two groups, tick half of the players in each, and give each group one weekly line on the same day at two different times.

1. **Card, first look.** Open a player of the first group → «تعديل اللاعب». The swimming card has a «الفوج» field holding the first group, and no date field. A football card of any player has no «الفوج» field.
2. **Move from a date.** Choose the second group: «تُطبَّق التغييرات من» appears with today. Save. The player page shows «الفئة: … · <second group>» on the swimming card. In the settings, the two counts moved by one.
3. **From the beginning.** Edit again, choose the first group, empty the date, save. The player is back in the first group; open a past unsaved session of the first group: the player is listed.
4. **Category change.** In the form, change the swimmer's category to one without groups: the «الفوج» field disappears. Save: the settings count of the group dropped by one. Put the category and the group back.
5. **New player.** «لاعب جديد», add the swimming branch, pick the category: the «الفوج» field appears with no date field; pick a category without groups: it disappears. Do not save.
6. **Month grid.** Open the category's month grid: a switch lists the groups (and «كل الفئة» when the month has sessions with no group); it opens on the first group; the columns are that group's sessions and the rows its players. Click the second group: columns and rows change, the title names the group.
7. **Unsaved codes.** Type a code in a cell, click the other group: a confirmation is asked; cancel keeps the code. Save the column: «تم حفظ الحضور.» and the page stays on the same group.
8. **Printed sheet.** With the second group shown, open «طباعة الورقة» (request the link's `href` and save the PDF in the qa folder): it is a PDF, its header names the second group, and it lists only that group's players and sessions. The calendar's print button gives the first group's sheet.
9. **Add session.** On the calendar of that category, «حصة إضافية»: a «الفوج» field is there; choosing «تحضير بدني» hides it. Add an extra session for the first group on a free date: the session page names the group and lists only its players.
10. **Edit dialog.** On that new session, «تعديل»: the «الفوج» field is there; change it to the second group and save: the list changes. Save the marks, open «تعديل» again: no «الفوج» field, and the hint names the group among the fixed fields. On a weekly-schedule session of a group, «تعديل» has no «الفوج» field.
11. **Session sheet.** «طباعة ورقة الحصة» of a group session: the PDF names the category and the group together.
12. **No groups, no change.** A football category: the month grid has no switch, «حصة إضافية» has no «الفوج» field, the printed month sheet has no group in its header.
13. **Languages and sizes.** French, then English, then dark mode, then a 320 px wide window: the grid's switch, the card's two fields and the two dialogs stay readable and nothing overflows sideways.
14. **Help Center.** Open the article «كيف أقسّم فئة إلى أفواج» from the Help Center in Arabic, French and English: every image shows, in light and dark.

Stop the server, then delete `database/groups-p2-check.sqlite` and `bootstrap/cache/config-groups-p2-check.php`. The qa folder stays (it is git-ignored).

- [ ] **Step 5: Report to the controller**

Do not merge, do not push, do not delete the branch: the controller and the owner decide. Report the suite totals, the front-end checks, the two migrate runs, each of the fourteen browser checks as passed or failed (with the screenshot's name), and `git log --oneline main..HEAD`.

---

## Self-review notes

- Spec coverage (phase 2 only): Group select on each branch card, hidden without groups, "from" date only when a saved group changes, saved through `GroupMembership` by `RegistrationService` → Tasks 2–3; current group beside the category on the player page → Tasks 2–3; `MonthSheet::build(…, ?int $groupId)` and the switch (one entry per group, «whole category» when the month has plain sessions, first group by default) → Task 4; printed month sheet per group with the group in its header, session paper sheet with the group beside the category → Task 5; add-session dialog's optional Group select hidden for pre-season, Edit dialog's group until marks are saved on hand-added sessions only → Task 6; Help Center article with twins, demo data and shots, edited articles → Tasks 7–8; spec tests ("the branch card assigns and moves through the same rules; grid and sheet show one group's sessions and players; grid save accepts only the group session's roster; the Edit dialog locks the group after marks") → `PlayerGroupCardTest`, `AttendanceGroupGridTest`, `AttendanceGroupSheetsPdfTest`, `TrainingSessionGroupTest`. Phase 3 (group filter on statistics, ranking, certificates, alerts and their exports) has its own plan; nothing here adds a group filter to the calendar, a group history screen or a bulk group change.
- Phase 1 minors handled: `GroupMembership::remove()` ternary (Task 1); "a registration saved without a category change keeps the membership open" now has a test (`a_card_that_does_not_speak_of_the_group_leaves_the_membership_open`, Task 2); players dialog baseline snapshot and the delete tooltip of a group in use (Task 7); the cut card above the weekly schedule in the `set-schedule` shot (Task 8). Left alone, their files are not edited by this plan: `BranchController` import order, `TrainingGroupSchemaTest` branch-delete members, `BranchRegistration` `(int)` cast, `updateSchedule` retag on every edit and its update-path test, `AttendanceGroupController` same id in add and remove / unique index / `from` format tests, `groupMembers.test.mjs` cases, `MonthView` chip at 320 px.
- Names used across tasks: `TrainingGroup::options(?int $categoryId = null)`, `GroupMembership::groupsOf(int $playerId, string $date)`, JS `groupsFor(groups, branchId, categoryId)`; card fields `group_id` / `original_group_id` / `group_from`, request fields `registrations.*.group_id` / `registrations.*.group_from`, props `trainingGroups` / `playerGroups`; `MonthSheet::NO_GROUP`, `MonthSheet::groupSwitch(…)` returning `options` / `selected`, `MonthSheet::build(…, ?int $groupId)`, query parameter `group_id`, grid props `groups` / `groupId`, view variable `$trainingGroup`, `$session['group']`; calendar and session page prop `groups`, `session.group_id`; i18n `att.grid_unsaved_confirm`, `att.error.group_follows_schedule`, `validation.group_other_category`; anchors `player-group`, `player-group-from`, `grid-groups`, `add-group`, `set-group-list`, `group-players-from`, `group-players-list`, `group-players-save`, `set-schedule-group`; demo constants `HelpDemoAttendanceSeeder::GROUP_ONE` / `GROUP_TWO`.
