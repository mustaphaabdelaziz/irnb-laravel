# Help Center Part 3b — Board of Directors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the nine board problems the owner chose (plus the two extensions decided while planning), then add the `board` help topic: 8 Arabic articles with screenshots on a seeded demo board.

**Architecture:** Fixes first (Tasks 1–5), each with feature tests, so the articles describe corrected behaviour. Then the same Help Center engine as Parts 1–3a (`resources/help/ar/<topic>/<slug>.md` → `HelpArticleParser` → `HelpLibrary` → Vue): a new `HelpDemoBoardSeeder` called last from the guarded `HelpDemoSeeder`, `data-help` attributes on the board pages, shots in `resources/help/shots.js`, and three article tasks. No Help framework change.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, PHPUnit-style tests (`#[Test]`), Playwright screenshot script (`npm run help:shots`).

Spec: `docs/superpowers/specs/2026-11-09-help-center-part3-design.md` (phase 3b). References: `resources/help/README.md` (authoring guide), `resources/help/ar/equipment/*.md` and `resources/help/ar/settings/*.md` (house style), `docs/superpowers/plans/2026-11-09-help-center-part3a-equipment.md` (previous phase, same task shapes), `.superpowers/archive/help-3a-sdd/` (implementer/reviewer instructions and article-review addendum to reuse).

## Owner decisions for 3b (2026-11-09)

All nine spec fixes were chosen:

1. «أعضاء المجلس» and the «الاجتماعات» list open with the «عرض» right.
2. Sidebar entry «أعضاء المجلس» in the «مجلس إدارة النادي» group.
3. «عهدة جديدة», «تعديل العهدة», «إضافة اجتماع» hidden without the right.
4. «إزالة من اللجنة» asks for confirmation.
5. «إزالة من المكتب» keeps the person's past meeting attendance under their name (meeting page and minutes PDF).
6. Overview counts, meeting attendance and task assignees use the current term only.
7. «نسخ أعضاء العهدة السابقة» on an empty term copies the previous term's active members with roles and committee seats; «سابق» members are not copied.
8. The role edit field shows the readable (translated) name; renaming a default role keeps working.
9. Picking someone already on the term changes their role with «تم تحديث عضو المكتب.».

Decided while planning (AskUserQuestion, 2026-11-09):

- **Demo board = archived adult players.** The seeder adds 9 adult players with `archived = true` and no branch, used only as board members. They stay out of every player list, so older articles and shots do not change. The member picker lists only active players, so the add-member example adds هشام بوعلام (an active adult demo player).
- **Meeting page buttons hidden without the right too** (extension of fix 3): «حفظ المحضر», «حفظ الحضور», the minutes-file upload and its trash, add-task and task trash; fields read-only for view-only users. Same rule applied to the «أدوار المكتب» and «اللجان» settings pages (they have no right checks today).

## Global Constraints

- Offline only; no CDN. Screenshots in `public/help-shots/<locale>/` (never `public/help/` — it shadows the `/help` route).
- Help UI uses app tokens (`primary-*`, `dark:`), dark text `primary-300`; markers `amber-500`.
- Articles Arabic only; UI strings in ar/fr/en, flat dotted keys, insert-only (text edits, never re-serialise the JSON), no key segment named after a JS String method (`search`, `link`, `replace`, `split`, `match`…). `flash.*` keys live in `resources/js/i18n/{ar,fr,en}.json`. Use `t()` directly, never `te()` for `flash.*`.
- Rights (`config/permissions.php` + `app/Support/PermissionMap.php`): the last route-name segment `store`/`create` → add, `destroy`/`delete` → delete, `index`/`show`/`export`/`minutes`/`calendar` → view, **anything else → edit**. Board routes are module `board`; `board-roles` → `board_roles`; `board-committees` → `board_committees`. Today `board.members` and `board.meetings` fall to edit (fix 1). `board.meetings.attachment.delete` is **delete**; `board.tasks.store` is add; `board.tasks.destroy` is delete; `board.terms.store` add, `board.terms.update` edit.
- Sidebar group «مجلس إدارة النادي» (key `nav.board_of_directors`): «نظرة عامة على المجلس», then new «أعضاء المجلس», «التقويم», «الاجتماعات», «المهام». «تهيئة التطبيق» → «أدوار المكتب», «اللجان». Check each Arabic label in `resources/js/i18n/ar.json` before quoting it.
- "Current term" everywhere = `BoardTerm::current()` (Task 4): the term with `is_current`, else the newest by id; no term → no filter.
- Writing rules: simple MSA, singular imperative, ≤ 15 words a sentence, one action per step (filling adjacent fields of one form card is one step), every clicked element ringed (sidebar clicks too), phone hint where a step relies on the sidebar («في الهاتف، افتح القائمة أولًا بزر ☰»), exact labels from `ar.json` in «…», no emoji standing in for app icons, every fact checked in code, ⚠️ warning for irreversible actions, 3–7 steps, ≤ 1 tip, ≤ 1 warning, worked example followed through.
- **Icon-only buttons** (`IconButton`: the name slides open on hover and is the tooltip/aria-label): describe the icon («زر علامة +», «زر القلم», «زر سلة المهملات») and quote the hover name the way `resources/help/ar/settings/add-branch.md` does («عندما تضع الفأرة عليه يظهر اسمه «تعديل».»). No colour words for buttons (owner rule); «الملوّن» only where colour is the point.
- Board UI quirks to state right: organigram boxes are buttons — an empty box opens the player picker and **picking a name saves at once** (no «حفظ»); a filled box opens the seat menu. The picker lists active players only. A meeting's «الحالة» select offers «مجدول» / «انعقد» only; cancelling goes through «إلغاء الاجتماع».
- Lessons from earlier phases: walk each article literally against the component's watchers and defaults; take each screenshot in the state the reader is in at that step; a step holds one action; reviewers repeatedly catch two actions in one step and unringed sidebar clicks; messages users cannot see (native confirm) are never screenshot.
- **Shots must never save anything.** The picker saves on click: ring the name, never click it. Never press «حفظ» / «حفظ المحضر» / «حفظ الحضور» / «إلغاء الاجتماع» (inside the modal) / «حذف الاجتماع» (inside the modal) / the copy confirm. If a shot must write, it goes last in `shots.js` with an `after` undo (none planned).
- Demo data only in the throwaway screenshot DB: the new seeder calls `HelpDemoSeeder::assertThrowawayDatabase()` first, writes models directly (no `ActivityRecorder` — the users articles count activity entries), and dates everything relative to the seed day. Production is PostgreSQL; seeders and sweeps never run there. Queries and the migration must work on SQLite, MySQL and PostgreSQL.
- Git: stage explicit files only (main folder has unrelated untracked `.claude/`, `ASIRAM.png`, `HANDOFF.md`, `brag-output/`, a PDF). Never `git add -A`.
- Worktree: reuse `D:\irnb-help3` (vendor + node_modules already copied, playwright installed). `git fetch` is local-only here; create branch `feat/help-3b` from local `main` (`012457e` or newer) inside it. If the worktree is gone, recreate it per `superpowers:using-git-worktrees`, copy `vendor/` and `node_modules/` (no junctions), `npm install`, restore `package-lock.json`'s `name` if npm changed it, `npx playwright install chromium`. No media copy needed (no photos in 3b).
- Tests: `php artisan test --filter=<Name>`; full suite only in the worktree without `bootstrap/cache/config.php`: `php -d memory_limit=1G vendor/bin/phpunit`.

## File map

| File | Responsibility |
|---|---|
| `config/permissions.php` (modify) | fix 1 overrides; copy route right |
| `resources/js/Composables/useNavigation.js` (modify) | fix 2 sidebar entry |
| `resources/js/Pages/Board/{Members,Meetings,Meeting,Calendar,Index}.vue` (modify) | fixes 3, 4, 5 display, 7 button; `data-help` |
| `resources/js/Pages/Settings/{BoardRoles,BoardCommittees}.vue` (modify) | rights; fix 8; `data-help` |
| `app/Http/Controllers/BoardMemberController.php` (modify) | fix 9; fix 5 snapshot before delete |
| `database/migrations/2026_11_10_100001_keep_board_meeting_attendance_names.php` (create) | fix 5 columns + nullable FK |
| `app/Models/MeetingAttendance.php`, `BoardMeetingController.php`, `resources/views/pdf/meeting-minutes.blade.php` (modify) | fix 5 |
| `app/Models/BoardTerm.php`, `app/Models/BoardMember.php` (modify) | `current()`, `previous()`, `scopeInCurrentTerm` |
| `app/Http/Controllers/BoardController.php` (modify) | fixes 5, 6, 7 props |
| `app/Services/LiteraryReport/Sections/BoardSection.php` (modify) | reuse `BoardTerm::current()` |
| `app/Http/Controllers/BoardTermController.php`, `routes/web.php` (modify) | fix 7 |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | new UI strings (the sidebar reuses `board_members`), `help.topic.board` |
| `resources/js/Components/Sidebar/SidebarSection.vue` (modify) | nav `data-help` |
| `resources/help/topics.php` (modify) | `board` topic |
| `database/seeders/HelpDemoBoardSeeder.php` (create), `HelpDemoSeeder.php` (modify) | demo board |
| `tests/Feature/...` (create/modify) | one test file per fix task, seeder test |
| `resources/help/shots.js` (modify) | new shots |
| `resources/help/ar/board/*.md` (create) | 8 articles |
| `public/help-shots/ar/**` (generated) | screenshots + boxes |

---

### Task 1: Rights — view opens the lists, sidebar entry, buttons hidden without the right

**Files:**
- Modify: `config/permissions.php` (overrides block, next to `'board.meetings.minutes'`)
- Modify: `resources/js/Composables/useNavigation.js:48-53`
- Modify: `resources/js/Pages/Board/Members.vue:233-236`, `resources/js/Pages/Board/Meetings.vue:77` (and the create form it toggles), `resources/js/Pages/Board/Calendar.vue:124`, `resources/js/Pages/Board/Meeting.vue`
- Modify: `resources/js/Pages/Settings/BoardRoles.vue`, `resources/js/Pages/Settings/BoardCommittees.vue`
- Test: `tests/Feature/BoardViewRightsTest.php` (create)

**Interfaces:**
- Consumes: `useCan()` from `resources/js/Composables/useCan.js` → `{ can(module, action) }` (superadmin → always true).
- Produces: `board.members` and `board.meetings` resolve to `['board', 'view']`; sidebar item `/board/members`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/BoardViewRightsTest.php` (user helper copied from `tests/Feature/BoardTasksPermissionTest.php`):

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardViewRightsTest extends TestCase
{
    use RefreshDatabase;

    private function viewOnlyBoardUser(): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['board' => ['view']]])->id]);
    }

    #[Test]
    public function the_members_page_and_the_meetings_list_are_reading(): void
    {
        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.members'));
        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.meetings'));
    }

    #[Test]
    public function a_view_only_user_opens_both_pages_but_cannot_change_a_term(): void
    {
        $user = $this->viewOnlyBoardUser();

        $this->actingAs($user)->get(route('board.members'))->assertOk();
        $this->actingAs($user)->get(route('board.meetings'))->assertOk();
        $this->actingAs($user)->post(route('board.terms.store'), ['name' => '2028–2032'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run** `php artisan test --filter=BoardViewRightsTest` — FAIL (edit is required today).

- [ ] **Step 3: Overrides** — in `config/permissions.php` after `'board.meetings.minutes' => ['board', 'view'],`:

```php
        // "members" / "meetings" are not view verbs: without these, reading the
        // organigram or the meetings list would need edit rights.
        'board.members' => ['board', 'view'],
        'board.meetings' => ['board', 'view'],
```

- [ ] **Step 4: Sidebar** — `useNavigation.js`, board group, after the overview line:

```js
                { label: t('board_members'), href: '/board/members', icon: 'members', prefix: '/board/members', module: 'board' },
```

Check `isActive` in `SidebarSection.vue`: the overview item is `exact: true`, so `/board/members` highlights only the new item. Check the Ctrl+K command list (grep `useNavigation` users, e.g. a command palette) still builds without changes.

- [ ] **Step 5: Buttons** — add `v-if` / `:disabled` as in the table (import `useCan` where missing). Keep layout classes; a hidden form must not leave an empty card.

| Page | Element | Condition |
|---|---|---|
| Members | «تعديل العهدة» (pencil) | `currentTerm && can('board','edit')` |
| Members | «عهدة جديدة» (plus) | `can('board','add')` |
| Meetings | «إضافة اجتماع» and the create form | `can('board','add')` |
| Calendar | the cell's + (`openCreate`) | `can('board','add')` |
| Meeting | both `<fieldset>`s | `:disabled="isCancelled \|\| !can('board','edit')"` |
| Meeting | «حفظ المحضر», file input + «رفع», «حفظ الحضور» | existing `!isCancelled` `&& can('board','edit')` |
| Meeting | minutes-file trash | `!isCancelled && can('board','delete')` (route segment `delete`) |
| Meeting | add-task form | `!isCancelled && can('board','add')` |
| Meeting | task trash | `!isCancelled && can('board','delete')` |
| BoardRoles | add form / pencil / trash | `can('board_roles','add'\|'edit'\|'delete')` |
| BoardCommittees | add form / pencil / trash | `can('board_committees','add'\|'edit'\|'delete')` |

«محضر الاجتماع» (print), the minutes-file link and export stay visible (view).

- [ ] **Step 6: Run** `php artisan test --filter=Board`, `npm run build` — PASS. In the dev app sign in as a user whose role has only `board: view`, `board_roles: view`, `board_committees: view`: the sidebar shows «أعضاء المجلس»; the members page, meetings list, a meeting, the calendar, «أدوار المكتب», «اللجان» open with no action button; a superadmin sees everything.

- [ ] **Step 7: Commit**

```bash
git add config/permissions.php resources/js/Composables/useNavigation.js resources/js/Pages/Board/Members.vue resources/js/Pages/Board/Meetings.vue resources/js/Pages/Board/Calendar.vue resources/js/Pages/Board/Meeting.vue resources/js/Pages/Settings/BoardRoles.vue resources/js/Pages/Settings/BoardCommittees.vue tests/Feature/BoardViewRightsTest.php
git commit -m "fix(board): members and meetings open with view; sidebar entry; actions hidden without the right"
```

---

### Task 2: Small board fixes — leave-committee confirm, readable role edit, «تم تحديث» message

**Files:**
- Modify: `resources/js/Pages/Board/Members.vue:148-151` (`leaveCommittee`), `:397`, confirm modals at the end
- Modify: `resources/js/Pages/Settings/BoardRoles.vue:30-37`, `:88-91`
- Modify: `app/Http/Controllers/BoardMemberController.php:14-24`
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/BoardMemberTest.php` (extend), `tests/Feature/BoardRoleRenameTest.php` (create, or extend an existing board-roles test: `grep -rln "board-roles" tests`)

**Interfaces:**
- Produces: i18n `board.leave_committee_confirm` (named params `{name}`, `{committee}`).

- [ ] **Step 1: Failing tests**

`BoardMemberTest` (reuse its helpers for an admin, a term, a player, roles):

```php
    #[Test]
    public function picking_someone_already_on_the_term_changes_their_role_and_says_updated(): void
    {
        // a term, a player already member with role 'member' in that term
        $this->actingAs($admin)->post(route('board.members.store'), [
            'player_id' => $player->id, 'board_term_id' => $term->id, 'role' => 'treasurer', 'status' => 'active',
        ])->assertSessionHas('success', 'flash.board_member_updated');

        $this->assertSame(1, BoardMember::where('player_id', $player->id)->where('board_term_id', $term->id)->count());
        $this->assertSame('treasurer', BoardMember::where('player_id', $player->id)->value('role'));
    }

    #[Test]
    public function a_new_person_is_added_with_the_added_message(): void
    {
        // same post for a player not on the term → 'flash.board_member_added'
    }
```

Role rename test:

```php
    #[Test]
    public function giving_a_default_role_a_readable_name_keeps_its_members(): void
    {
        // admin; a BoardMember with role 'president'
        $role = BoardRole::where('name', 'president')->firstOrFail();
        $this->actingAs($admin)->put(route('board-roles.update', $role), [
            'name' => 'president', 'label' => 'رئيس النادي', 'level' => 'top', 'sort_order' => 1,
        ])->assertSessionHas('success', 'flash.role_updated');

        $this->assertSame('رئيس النادي', $role->fresh()->label);
        $this->assertSame('president', $member->fresh()->role);
    }
```

- [ ] **Step 2: Run** both files — the "updated" test FAILS (says added today); the role test passes already (it pins the server contract the page now relies on).

- [ ] **Step 3: Controller** — `BoardMemberController::store()`:

```php
        if ($member) {
            $member->update($data);

            return back()->with('success', 'flash.board_member_updated');
        }

        BoardMember::create($data);

        return back()->with('success', 'flash.board_member_added');
```

- [ ] **Step 4: Role edit field** — `BoardRoles.vue`: the edit input binds `editForm.label`, prefilled with the readable name; the stored `name` (the key members reference) never changes from this page:

```js
function startEdit(r) {
    editingId.value = r.id;
    editForm.name = r.name;
    // Edit the name people read; the stored key stays, so members keep their role.
    editForm.label = display(r);
    editForm.level = r.level || 'member';
    editForm.sort_order = r.sort_order || 0;
}
function saveEdit(r) {
    editForm
        .transform((d) => {
            const label = (d.label || '').trim();
            // Typing back the built-in translation means "no custom name".
            return { ...d, name: r.name, label: label && label !== t(r.name) ? label : null };
        })
        .put(route('board-roles.update', r.id), { onSuccess: () => { editingId.value = null; } });
}
```

Template: `<TextInput v-model="editForm.label" ... />`, `@click="saveEdit(r)"`, and show `<InputError :message="editForm.errors.label || editForm.errors.name" />` under it. Check the add form's name: unchanged (a new role's typed text is its key).

- [ ] **Step 5: Leave confirm** — `Members.vue`:

```js
const leaveTarget = ref(null); // { member, committee }
function leaveCommittee() {
    const { member, committee } = leaveTarget.value;
    leaveTarget.value = null;
    router.delete(route('board.committees.members.destroy', [committee.id, member.id]), seatOpts);
}
```

The seat-menu button: `@click="leaveTarget = { member: selected.member, committee: selected.committee }"`. Add after the remove `ConfirmModal`:

```vue
        <ConfirmModal :show="!!leaveTarget" :title="t('board.leave_committee')"
            :message="leaveTarget ? t('board.leave_committee_confirm', { name: leaveTarget.member.name, committee: leaveTarget.committee.name }) : ''"
            :confirm-label="t('board.leave_committee')" @confirm="leaveCommittee" @cancel="leaveTarget = null" />
```

Check `ConfirmModal` shows above the seat menu (the existing remove confirm opens from the same menu: same stacking). i18n `board.leave_committee_confirm`: ar «سيُزال {name} من «{committee}» ويبقى عضوًا في المكتب.», fr «{name} quittera « {committee} » et reste membre du bureau.», en «{name} will leave “{committee}” and stays on the board.».

- [ ] **Step 6: Run** `php artisan test --filter=Board`, `npm run i18n:check`, `npm run build` — PASS. In the dev app: edit «الرئيس» → the field reads «الرئيس»; save «رئيس النادي» → organigram shows it; «إزالة من اللجنة» asks first.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/BoardMemberController.php resources/js/Pages/Board/Members.vue resources/js/Pages/Settings/BoardRoles.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/BoardMemberTest.php tests/Feature/BoardRoleRenameTest.php
git commit -m "fix(board): confirm leaving a committee, readable role names, updated message for a role change"
```

---

### Task 3: Removing a member keeps their meeting attendance

**Files:**
- Create: `database/migrations/2026_11_10_100001_keep_board_meeting_attendance_names.php`
- Modify: `app/Models/MeetingAttendance.php`, `app/Http/Controllers/BoardMeetingController.php:45-65`, `app/Http/Controllers/BoardMemberController.php` (`destroy`), `app/Http/Controllers/BoardController.php` (`meeting`), `resources/js/Pages/Board/Meeting.vue` (attendance card), `resources/views/pdf/meeting-minutes.blade.php:45-48`
- Test: `tests/Feature/BoardMemberRemovalKeepsAttendanceTest.php` (create)

**Interfaces:**
- Produces: `meeting_attendances.member_name` (string, nullable), `meeting_attendances.member_role` (string, nullable: the role **key**), `board_member_id` nullable with `nullOnDelete`; `Board/Meeting` prop `removedAttendances: [{ id, name, role, status }]`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMemberRemovalKeepsAttendanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function removing_a_member_keeps_their_attendance_under_their_name(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $term = BoardTerm::firstOrFail(); // the migration's term
        $player = Player::factory()->create(); // check the factory exists; else create like BoardMemberTest
        $member = BoardMember::create(['player_id' => $player->id, 'board_term_id' => $term->id, 'name' => $player->fullname, 'role' => 'treasurer', 'status' => 'active']);
        $meeting = BoardMeeting::create(['title' => 'اجتماع', 'type' => 'ordinary', 'meeting_date' => now()->subWeek(), 'status' => 'held']);

        $this->actingAs($admin)->put(route('board.meetings.attendance', $meeting), [
            'attendances' => [['board_member_id' => $member->id, 'status' => 'present']],
        ]);
        $this->actingAs($admin)->delete(route('board.members.destroy', $member));

        $row = MeetingAttendance::where('board_meeting_id', $meeting->id)->sole();
        $this->assertNull($row->board_member_id);
        $this->assertSame($player->fullname, $row->member_name);
        $this->assertSame('treasurer', $row->member_role);
        $this->assertSame('present', $row->status);

        $this->actingAs($admin)->get(route('board.meetings.show', $meeting))
            ->assertInertia(fn ($page) => $page
                ->where('removedAttendances.0.name', $player->fullname)
                ->where('removedAttendances.0.status', 'present'));

        $this->actingAs($admin)->get(route('board.meetings.minutes', $meeting))->assertOk();
    }
}
```

- [ ] **Step 2: Run** — FAIL (the row is cascaded away).

- [ ] **Step 3: Migration** (portable: no raw SQL; Laravel 13 rebuilds the SQLite table for `dropForeign`/`change`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removing someone from the board kept nothing of their meeting attendance
 * (cascade delete). Each row now carries the name and role it was taken under,
 * and the member link is cleared instead of deleting the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->string('member_name')->nullable()->after('board_member_id');
            $table->string('member_role', 100)->nullable()->after('member_name');
        });

        DB::table('board_members')->orderBy('id')->select(['id', 'name', 'role'])->each(function ($m) {
            DB::table('meeting_attendances')->where('board_member_id', $m->id)
                ->update(['member_name' => $m->name, 'member_role' => $m->role]);
        });

        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->dropForeign(['board_member_id']);
        });
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('board_member_id')->nullable()->change();
            $table->foreign('board_member_id')->references('id')->on('board_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('meeting_attendances')->whereNull('board_member_id')->delete();

        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->dropForeign(['board_member_id']);
        });
        Schema::table('meeting_attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('board_member_id')->nullable(false)->change();
            $table->foreign('board_member_id')->references('id')->on('board_members')->cascadeOnDelete();
            $table->dropColumn(['member_name', 'member_role']);
        });
    }
};
```

The `board_members.name` column already holds the player's full name (kept in step by `Player`). The unique `(board_meeting_id, board_member_id)` stays: NULLs are distinct on all three databases. Run `php artisan migrate` on a copy of the dev DB and `php artisan migrate:rollback --step=1` then migrate again: both clean.

- [ ] **Step 4: Snapshot on save and before delete** — `MeetingAttendance::$fillable` += `'member_name', 'member_role'`. In `BoardMeetingController::attendance()` load the members once and write the snapshot:

```php
        $members = BoardMember::withPlayer()->whereIn('id', collect($data['attendances'])->pluck('board_member_id'))->get()->keyBy('id');

        foreach ($data['attendances'] as $row) {
            $member = $members[$row['board_member_id']];
            MeetingAttendance::updateOrCreate(
                ['board_meeting_id' => $meeting->id, 'board_member_id' => $member->id],
                ['status' => $row['status'], 'member_name' => $member->name, 'member_role' => $member->role]
            );
        }
```

`BoardMemberController::destroy()`, before `$boardMember->delete()`:

```php
        // Their attendance stays in the minutes, under the name they had then.
        $boardMember->loadMissing(['player' => fn ($q) => $q->select(BoardMember::PLAYER_COLUMNS)]);
        MeetingAttendance::where('board_member_id', $boardMember->id)
            ->update(['member_name' => $boardMember->name, 'member_role' => $boardMember->role]);
```

- [ ] **Step 5: Display** — `BoardController::meeting()` adds:

```php
            'removedAttendances' => $meeting->attendances->whereNull('board_member_id')->values()
                ->map(fn (MeetingAttendance $a) => ['id' => $a->id, 'name' => $a->member_name, 'role' => $a->member_role, 'status' => $a->status]),
```

`Meeting.vue`: prop `removedAttendances: { type: Array, default: () => [] }`; under the attendance `<ul>` a read-only list (same row look, the status as a small label `t(a.status)`, no buttons), headed `t('board.removed_members')`; count them in `presentCount` only through the server rows (keep `presentCount` for the editable list, show removed ones separately). i18n `board.removed_members`: ar «أعضاء أُزيلوا من المكتب», fr «Membres retirés du bureau», en «Removed from the board». Minutes PDF lines 47–48:

```blade
                        <td>{{ $a->member?->name ?? $a->member_name }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $a->member?->role ?? $a->member_role ?? ''))) }}</td>
```

Also `BoardController::meeting()` builds `$present` keyed by `board_member_id`: filter out null keys first (`$meeting->attendances->whereNotNull('board_member_id')->keyBy(...)`).

- [ ] **Step 6: Run** the new test, `php artisan test --filter=Board`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_10_100001_keep_board_meeting_attendance_names.php app/Models/MeetingAttendance.php app/Http/Controllers/BoardMeetingController.php app/Http/Controllers/BoardMemberController.php app/Http/Controllers/BoardController.php resources/js/Pages/Board/Meeting.vue resources/views/pdf/meeting-minutes.blade.php resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/BoardMemberRemovalKeepsAttendanceTest.php
git commit -m "fix(board): removing a member keeps their meeting attendance under their name"
```

---

### Task 4: Current term only — overview, meeting attendance, task assignees

**Files:**
- Modify: `app/Models/BoardTerm.php`, `app/Models/BoardMember.php`
- Modify: `app/Http/Controllers/BoardController.php` (`index`, `meeting`, `tasks`)
- Modify: `app/Services/LiteraryReport/Sections/BoardSection.php:18`
- Test: `tests/Feature/BoardCurrentTermTest.php` (create)

**Interfaces:**
- Produces: `BoardTerm::current(): ?BoardTerm`; `BoardMember::scopeInCurrentTerm(Builder)` (no-op without a term).

- [ ] **Step 1: Failing test** — two terms: old (not current) with an active member «قديم» (role president), current with active members «أ» (president) and «ب» (member), plus «سابق» (former) in the current term. One held meeting with a recorded `present` row for «قديم». Assert:
  - `get(route('board.index'))` → `stats.members` = 2; `membersByRole` names = [«أ», «ب»];
  - a **new** scheduled meeting's page → `members` names = [«أ», «ب»]; `allMembers` names = [«أ», «ب»];
  - the held meeting's page → `members` contains «أ», «ب» **and** «قديم» (recorded there, attendance `present`);
  - a task assigned to «قديم» → `get(route('board.tasks'))` `members` names = [«أ», «ب», «قديم»] (current term + people already assigned), a term with no tasks for others → only [«أ», «ب»].

Create members directly (`BoardMember::create`, names set, no players needed: `player_id` is nullable).

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Models**

`BoardTerm`:

```php
    /** The term marked current, else the newest; null when the club has none. */
    public static function current(): ?self
    {
        return self::where('is_current', true)->first() ?? self::latest('id')->first();
    }
```

`BoardMember`:

```php
    /** Members of the current term; every member while the club has no term. */
    public function scopeInCurrentTerm(Builder $query): void
    {
        $termId = BoardTerm::current()?->id;
        $query->when($termId, fn ($q) => $q->where('board_term_id', $termId));
    }
```

- [ ] **Step 4: Controller**

- `index()`: `$currentTerm = BoardTerm::current();`; `'members' => BoardMember::inCurrentTerm()->where('status', 'active')->count()`; `membersByRole` query gets `->inCurrentTerm()`.
- `meeting()`:

```php
        $recorded = $meeting->attendances->pluck('board_member_id')->filter()->all();
        $assigned = $meeting->tasks->pluck('board_member_id')->filter()->all();
        $current = fn ($q) => $q->inCurrentTerm()->where('status', 'active');
        ...
            'members' => BoardMember::where($current)->orWhereIn('id', $recorded)->orderBy('sort_order')->get()->map(...same map...),
            'allMembers' => BoardMember::where($current)->orWhereIn('id', $assigned)->orderBy('sort_order')->get(['id', 'name', 'role']),
```

(`where(Closure)` nests the scope in parentheses; check the generated SQL with `->toRawSql()` once.)
- `tasks()`: `'members' => BoardMember::where(fn ($q) => $q->inCurrentTerm()->where('status', 'active'))->orWhereIn('id', $all->pluck('board_member_id')->filter()->unique())->orderBy('sort_order')->get(['id', 'name', 'role'])`.
- `BoardSection.php:18`: `$term = BoardTerm::current();`.

- [ ] **Step 5: Run** the new test, `php artisan test --filter=Board`, `--filter=LiteraryReport` — PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/BoardTerm.php app/Models/BoardMember.php app/Http/Controllers/BoardController.php app/Services/LiteraryReport/Sections/BoardSection.php tests/Feature/BoardCurrentTermTest.php
git commit -m "fix(board): overview, attendance and assignees use the current term"
```

---

### Task 5: «نسخ أعضاء العهدة السابقة»

**Files:**
- Modify: `app/Models/BoardTerm.php` (`previous()`), `app/Http/Controllers/BoardTermController.php`, `routes/web.php` (board terms block), `config/permissions.php`
- Modify: `app/Http/Controllers/BoardController.php` (`members`), `resources/js/Pages/Board/Members.vue`
- Modify: `resources/js/i18n/{ar,fr,en}.json`
- Test: `tests/Feature/BoardTermCopyTest.php` (create)

**Interfaces:**
- Consumes: `BoardTerm::current()` (Task 4).
- Produces: route `board.terms.copy-previous` (POST `/board/terms/{boardTerm}/copy-previous`, right `board` add); `BoardTerm::previous(): ?BoardTerm`; `Board/Members` prop `previousTerm: { id, name, count } | null`; flash keys `flash.term_members_copied`, `flash.term_not_empty`, `flash.term_nothing_to_copy`; i18n `board.copy_previous`, `board.copy_previous_confirm` (`{count}`, `{name}`).

- [ ] **Step 1: Failing tests** — `tests/Feature/BoardTermCopyTest.php`:

```php
    #[Test]
    public function an_empty_term_copies_the_previous_terms_active_members_roles_and_seats(): void
    {
        // previous term (start 2021-03-01): A president, B member seated as head of committee C, F former
        // new term (start 2025-03-01, empty)
        $this->actingAs($admin)->post(route('board.terms.copy-previous', $new))
            ->assertSessionHas('success', 'flash.term_members_copied');

        $copied = BoardMember::where('board_term_id', $new->id)->get();
        $this->assertEqualsCanonicalizing(['A', 'B'], $copied->pluck('name')->all());
        $this->assertSame('president', $copied->firstWhere('name', 'A')->role);
        $this->assertSame('head', $copied->firstWhere('name', 'B')->committees()->first()->pivot->position);
        $this->assertSame(3, BoardMember::where('board_term_id', $previous->id)->count()); // source untouched
    }

    #[Test]
    public function a_term_with_members_is_refused(): void { /* → 'flash.term_not_empty', nothing created */ }

    #[Test]
    public function nothing_to_copy_is_refused(): void { /* previous term has only former members → 'flash.term_nothing_to_copy' */ }

    #[Test]
    public function previous_follows_start_dates_then_ids(): void
    {
        // terms: X (2017), Y (2021), Z (2025), W (no dates, newest id)
        // Z->previous() is Y; Y->previous() is X; X->previous() is null; W->previous() is Z (no date → by id)
    }

    #[Test]
    public function the_members_page_offers_the_copy_only_on_an_empty_term(): void
    {
        // get(route('board.members', ['term' => $new->id])) → previousTerm.name = previous name, previousTerm.count = 2
        // get(route('board.members', ['term' => $previous->id])) → previousTerm null
    }
```

Write the bodies with `BoardTerm::create`, `BoardMember::create`, `BoardCommittee::create(['name' => 'C'])` and `$committee->members()->attach($b->id, ['position' => 'head'])`.

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: `BoardTerm::previous()`**

```php
    /**
     * The term before this one: by start date when both have one, else by id.
     * Computed in PHP (a club has a handful of terms) so NULL dates sort the same on every database.
     */
    public function previous(): ?self
    {
        return self::whereKeyNot($this->id)->get()
            ->filter(fn (self $t) => $this->start_date && $t->start_date
                ? $t->start_date->lt($this->start_date)
                : $t->id < $this->id)
            ->sortByDesc(fn (self $t) => sprintf('%s-%010d', $t->start_date?->format('Y-m-d') ?? '0000-00-00', $t->id))
            ->first();
    }
```

- [ ] **Step 4: Route, right, controller** — `routes/web.php` after `board.terms.update`:

```php
        Route::post('/board/terms/{boardTerm}/copy-previous', [BoardTermController::class, 'copyPrevious'])->name('board.terms.copy-previous');
```

`config/permissions.php` overrides: `'board.terms.copy-previous' => ['board', 'add'],` (it adds members). Controller:

```php
    /**
     * Start a new term from the last one: its active members, their roles and
     * committee seats. Former members stay behind. Only into an empty term.
     */
    public function copyPrevious(BoardTerm $boardTerm): RedirectResponse
    {
        if ($boardTerm->members()->exists()) {
            return back()->with('error', 'flash.term_not_empty');
        }

        $source = $boardTerm->previous()?->members()->where('status', 'active')->with('committees:id')->get() ?? collect();
        if ($source->isEmpty()) {
            return back()->with('error', 'flash.term_nothing_to_copy');
        }

        DB::transaction(function () use ($source, $boardTerm) {
            foreach ($source as $member) {
                $copy = BoardMember::create([
                    'player_id' => $member->player_id,
                    'user_id' => $member->user_id,
                    'board_term_id' => $boardTerm->id,
                    'name' => $member->getRawOriginal('name'),
                    'role' => $member->role,
                    'status' => 'active',
                    'sort_order' => $member->sort_order,
                    // Members from before the player link keep their own contact; photos are not shared.
                    'email' => $member->getRawOriginal('email'),
                    'phone' => $member->getRawOriginal('phone'),
                ]);
                $copy->committees()->attach(
                    $member->committees->mapWithKeys(fn ($c) => [$c->id => ['position' => $c->pivot->position]])->all()
                );
            }
        });

        return back()->with('success', 'flash.term_members_copied');
    }
```

Imports: `App\Models\BoardMember`, `Illuminate\Support\Facades\DB`.

- [ ] **Step 5: Page** — `BoardController::members()` adds:

```php
        $selectedTerm = $terms->firstWhere('id', $selectedTermId);
        $previous = $selectedTerm && $members->isEmpty() ? $selectedTerm->previous() : null;
        $previousCount = $previous?->members()->where('status', 'active')->count() ?? 0;
        ...
            'previousTerm' => $previousCount ? ['id' => $previous->id, 'name' => $previous->name, 'count' => $previousCount] : null,
```

`Members.vue`: prop `previousTerm: { type: Object, default: null }`; in the hint row before «إضافة عضو»:

```vue
<IconButton v-if="previousTerm && can('board', 'add')" icon="copy" :label="t('board.copy_previous')" data-help="board-copy-previous" @click="showCopy = true" />
```

and a `ConfirmModal` (`showCopy`, `copying`): message `t('board.copy_previous_confirm', { count: previousTerm.count, name: previousTerm.name })`, confirm label `t('board.copy_previous')`, confirm → `router.post(route('board.terms.copy-previous', selectedTermId), {}, { preserveScroll: true, onStart: …, onFinish: () => { copying.value = false; showCopy.value = false; } })`.

i18n — ar: `board.copy_previous` «نسخ أعضاء العهدة السابقة», `board.copy_previous_confirm` «سيُنسخ {count} عضوًا حاليًا من «{name}» بأدوارهم ومقاعدهم في اللجان. لا يُنسخ الأعضاء السابقون.», `flash.term_members_copied` «تم نسخ أعضاء العهدة السابقة.», `flash.term_not_empty` «هذه العهدة تضم أعضاء بالفعل.», `flash.term_nothing_to_copy` «لا يوجد أعضاء حاليون في العهدة السابقة.»; fr: «Copier les membres du mandat précédent», «{count} membres actifs de « {name} » seront copiés avec leurs rôles et sièges en commission. Les anciens membres ne sont pas copiés.», «Membres du mandat précédent copiés.», «Ce mandat a déjà des membres.», «Aucun membre actif dans le mandat précédent.»; en: «Copy the previous term’s members», «{count} active members of “{name}” will be copied with their roles and committee seats. Former members are not copied.», «Previous term’s members copied.», «This term already has members.», «No active members in the previous term.».

- [ ] **Step 6: Run** the new test, `php artisan test --filter=Board`, `npm run i18n:check`, `npm run build` — PASS. Dev app: create an empty term, select it, the button shows; copy; the organigram fills; the button disappears.

- [ ] **Step 7: Commit**

```bash
git add app/Models/BoardTerm.php app/Http/Controllers/BoardTermController.php routes/web.php config/permissions.php app/Http/Controllers/BoardController.php resources/js/Pages/Board/Members.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json tests/Feature/BoardTermCopyTest.php
git commit -m "feat(board): copy the previous term's members into an empty term"
```

---

### Task 6: `board` topic, nav targets and demo board

**Files:**
- Modify: `resources/help/topics.php` (only if `help:check` accepts listed slugs before the articles exist — see Step 4), `resources/js/i18n/{ar,fr,en}.json` (`help.topic.board`)
- Modify: `resources/js/Components/Sidebar/SidebarSection.vue` (the `data-help` map)
- Create: `database/seeders/HelpDemoBoardSeeder.php`
- Modify: `database/seeders/HelpDemoSeeder.php` (call it after `HelpDemoEquipmentSeeder`)
- Test: `tests/Feature/Help/HelpDemoBoardSeederTest.php`

**Interfaces:**
- Consumes: `HelpDemoSeeder::assertThrowawayDatabase()`; the migration's current term (rename it, keep its id); `BoardRole` defaults (president top; vice_president, secretary, treasurer bureau; member); `BoardTerm::current()`, `previous()`.
- Produces: topic `board`; nav `data-help`: `nav-board` (/board), `nav-board-members` (/board/members), `nav-board-calendar` (/board/calendar), `nav-board-meetings` (/board/meetings), `nav-board-roles` (/board-roles), `nav-board-committees` (/board-committees); seeder constants for names; the term ids, meeting ids and member ids written into the task report for the shot tasks.

**Demo facts** (dates relative to the seed day `D` = `CarbonImmutable::today()`; `Y` = the year of `D − 20 months`):

Players (all `archived = true`, adult, no branch, no category, created like `HelpDemoSeeder` creates players but without branch registration; one phone each `06612345NN`): «عبد المجيد بوخالفة» (M, 1968), «سليم حمادي» (M, 1975), «فاطمة الزهراء بن صالح» (F, 1980), «نبيل عثماني» (M, 1972), «رشيد مقران» (M, 1978), «ليلى بوعزة» (F, 1985), «كمال درارجة» (M, 1983), «مراد زيتوني» (M, 1970), «مصطفى قريشي» (M, 1960). Check `Player` saving hooks (membership id, `fullname`) behave with no branch; if a branch is required, register them in Football with `archived` still true and say so in the report.

Terms:
- previous «العهدة (Y−4)–Y», start `(Y−4)-03-01`, end `Y-02-28`, not current;
- current = the migration's term renamed «العهدة Y–(Y+4)», start `Y-03-01`, end `(Y+4)-02-28`, current;
- next «العهدة (Y+4)–(Y+8)», start `(Y+4)-03-01`, no end, not current, **empty** (the copy button shows on it).

Committees (create): «اللجنة المالية», «اللجنة التقنية», «لجنة الإعلام» (sort 1–3).

| Person | Previous term | Current term |
|---|---|---|
| عبد المجيد بوخالفة | president | president |
| مصطفى قريشي | vice_president | — |
| سليم حمادي | member | vice_president; head of «اللجنة التقنية» |
| فاطمة الزهراء بن صالح | secretary | secretary |
| نبيل عثماني | treasurer; head of «اللجنة المالية» | treasurer; head of «اللجنة المالية» |
| رشيد مقران | member | member; deputy of «اللجنة المالية» |
| ليلى بوعزة | — | member (no committee: «أعضاء آخرون») |
| كمال درارجة | — | member; member of «اللجنة التقنية» |
| مراد زيتوني | member, status former | member, status **former** («سابق») |

So the current term has 7 active + 1 former; «لجنة الإعلام» has every seat empty; «اللجنة المالية» one deputy seat empty. Copying into the next term would copy 7.

Meetings (all `created_by_user_id` = demo; no `ActivityRecorder`):
1. «اجتماع المكتب العادي», ordinary, `D−21` 18:00, «مقر النادي», held; agenda «مراجعة ميزانية الموسم», «تحضير البطولة الشتوية», «متفرقات»; minutes «ناقش المكتب ميزانية الموسم وصادق عليها. تقرر تحضير ملف منحة البلدية.»; decisions «المصادقة على ميزانية الموسم», «تكليف أمين المال بملف منحة البلدية»; attendance (with snapshots): 6 present, رشيد مقران excused; task «تحضير ملف منحة البلدية» → نبيل عثماني, due `D+10`, in_progress 40 %, high.
2. «اجتماع تحضير الجمعية العامة», general_assembly, `D+7` 18:00, «قاعة البلدية», scheduled; agenda «التقرير الأدبي», «التقرير المالي».
3. «لقاء مع الممولين», extraordinary, `D−7` 17:00, cancelled (`cancelled_at` `D−9`, by demo, reason «تعذّر حضور أغلب الأعضاء»).
4. An old meeting in the previous term: «اجتماع نهاية العهدة», ordinary, `Y-02-10` 18:00, held, attendance of the previous term's active members (snapshots) — shows the history kept.

Tasks: besides meeting 1's, «تجديد عقد كراء القاعة» → سليم حمادي, due `D−3`, not_started (overdue on the overview); «إعداد التقرير الأدبي» → فاطمة الزهراء بن صالح, due `D+20`, in_progress 20 %.

- [ ] **Step 1: Failing test** — `tests/Feature/Help/HelpDemoBoardSeederTest.php`, same shape as `HelpDemoEquipmentSeederTest` (`travelTo('2026-11-18 10:00:00')`, `seed(HelpDemoSeeder::class)`). Assert: 9 archived players with the names; 3 terms, the current one renamed and still the migration's id, the next one empty; current term 7 active + 1 former; seats as in the table («لجنة الإعلام» empty); `BoardTerm::current()->previous()` is the previous term; next term's `previous()` is the current one; 4 meetings with the statuses above; meeting 1 attendance 6 present + 1 excused, all rows with `member_name`; meeting 3 has a reason; 3 tasks, one overdue; `Player::where('archived', false)->count()` still 22; `ActivityLog` (check the model name) count unchanged by this seeder (compare with a run of `HelpDemoSeeder` minus the board seeder, or assert no entry of the board actions exists). Add a second test method that travels to `2027-03-02 09:00:00` (just after a term boundary) and re-seeds: terms still ordered, current term still the one containing `D`.

- [ ] **Step 2: Run** `php artisan test --filter=HelpDemoBoardSeederTest` — FAIL (class missing).

- [ ] **Step 3: Seeder** — `HelpDemoBoardSeeder::run()`: `HelpDemoSeeder::assertThrowawayDatabase();` then build the facts above with models (`Player::create`, `BoardTerm`, `BoardCommittee`, `BoardMember`, `->committees()->attach()`, `BoardMeeting`, `MeetingAttendance` with snapshots, `BoardTask`). Public constants for every name the articles quote. Call it from `HelpDemoSeeder::run()` after `HelpDemoEquipmentSeeder`.

- [ ] **Step 4: Run** the seeder test and `php artisan test --filter=Help` — PASS (older seeder tests too: player counts, activity counts unchanged).

- [ ] **Step 5: Topic and nav** — `topics.php` after `equipment`:

```php
        'board' => ['icon' => 'board', 'module' => 'board', 'articles' => ['roles-committees', 'terms', 'add-member', 'committee-seats', 'former-remove-member', 'schedule-meeting', 'meeting-minutes', 'cancel-delete-meeting']],
```

i18n `help.topic.board` after `help.topic.equipment`: ar «مجلس الإدارة», fr «Conseil d'administration», en «Board of directors». `SidebarSection.vue`: add `'/board': 'nav-board', '/board/members': 'nav-board-members', '/board/calendar': 'nav-board-calendar', '/board/meetings': 'nav-board-meetings', '/board-roles': 'nav-board-roles', '/board-committees': 'nav-board-committees'` to the map. If `help:check` fails on listed-but-missing slugs, add the topic line in Task 7 instead (as 3a did) and keep only nav, i18n and seeder here. Check that `roles-committees` (module `board_roles`) shows for a user with only that right (article module overrides the topic's).

- [ ] **Step 6: Run** `php artisan help:check`, `php artisan test --filter=Help`, `npm run i18n:check`, `npm run build` — PASS.

- [ ] **Step 7: Commit**

```bash
git add database/seeders/HelpDemoBoardSeeder.php database/seeders/HelpDemoSeeder.php tests/Feature/Help/HelpDemoBoardSeederTest.php resources/js/Components/Sidebar/SidebarSection.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/help/topics.php
git commit -m "feat(help): board demo, topic and nav targets"
```

---

## Article tasks (7–9): common procedure

For each article: (1) read the page component(s) and controller for every step and quote labels from `ar.json`; (2) add `data-help` attributes (attribute only) to what the steps ring — organigram boxes get `board-box-<role name>` / `board-seat-<committee id>-<position>-<n>` on the `OrgNode` root (pass through `$attrs` or a prop; check `OrgNode.vue`); (3) add shots to `resources/help/shots.js` (reader's state at that step: `before` opens menus, types, selects; never saves; `navIntoView(page, '<nav name>')` for sidebar targets); (4) `npm run build && npm run help:shots -- <names>`; (5) open every new light and dark image and check rings land on the right elements; (6) write the article with the house front matter (`title` «كيف…», `summary`, `module` when not `board`, `pages`, `keywords`, `related`), an `:::example` that follows the demo data, `:::step` cards with `::figure`; (7) `php artisan help:check` + `php artisan test --filter=Help`; (8) commit the articles, shots, `shots.js` and the touched Vue files explicitly.

Name shots `board-<slug>-<state>`; targets `board-…` (members/terms/organigram), `meet-…` (meetings), `brole-…`, `bcom-…` (settings lists). Say which right a step needs when it is not view.

### Task 7: Articles — `roles-committees`, `terms`, `add-member`

**Files:** Create `resources/help/ar/board/{roles-committees,terms,add-member}.md`; modify `shots.js`, `Settings/BoardRoles.vue`, `Settings/BoardCommittees.vue`, `Board/Members.vue`, `Components/Board/OrgNode.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `roles-committees` — module `board_roles`, pages `[board-roles.index, board-committees.index]`. Example: add the role «مستشار» at level «المكتب», then the committee «لجنة الشباب». Steps: open «أدوار المكتب» (nav ring, under «تهيئة التطبيق») → type «الاسم», choose «المستوى في الهيكل» (what each level does in the organigram: «القمة (الرئيس)» top row, «المكتب» second row, «عضو» committees / «أعضاء آخرون»), «الترتيب» → «إضافة» (needs add) → pencil to rename (shows the readable name — Task 2; members keep the role) → open «اللجان» (nav ring) → type the name (placeholder «مثال: اللجنة المالية») → «إضافة». Facts: a role or committee in use can't be deleted (quote the hover names / flash texts); the committees part needs the «اللجان» right — say so in that step.
2. `terms` — pages `[board.members]`. Example: the club prepares the next term «العهدة (Y+4)–(Y+8)» and starts it from the current members. Steps: open «أعضاء المجلس» (nav ring) → the «العهدة» select (★ = current) and its dates → «عهدة جديدة» (plus; needs add) form: «الاسم», «بداية العهدة», «نهاية العهدة», «تعيين كعهدة حالية» (only one current; the overview and attendance follow it — Task 4) — shot with the form filled, not saved → choose the empty next term in the select → «نسخ أعضاء العهدة السابقة» (needs add) and its confirm text (shot of the modal, not confirmed): who is copied (active members, roles, committee seats), who isn't («سابق») → «تعديل العهدة» (pencil; needs edit) to fix dates or make it current. Fact: a term with members can't be deleted (no delete button on this page — check; if none, don't mention deleting).
3. `add-member` — pages `[board.members]`. Example: هشام بوعلام joins the current term as «عضو». Steps: on «أعضاء المجلس» the organigram: click an empty box to fill a role directly (picker opens; picking saves at once — say so) → or «إضافة عضو» (plus; needs add) → «اللاعب»: type «بوعلام», pick him (the list shows players, not archived ones; the name, phone and photo come from the player's file and update with it) → «الدور», «العهدة», «الحالة» → «حفظ» (message «تمت إضافة عضو المكتب.»; someone already on the term → role changed, «تم تحديث عضو المكتب.» — Task 2) → a filled box opens the seat menu (phone with WhatsApp, committees). Shots: organigram with an empty box ringed (use «لجنة الإعلام» head or a role box the demo leaves empty — check which role box is empty; if none, ring the add button only), form filled with هشام بوعلام (not saved), seat menu of «عبد المجيد بوخالفة».

### Task 8: Articles — `committee-seats`, `former-remove-member`

**Files:** Create `resources/help/ar/board/{committee-seats,former-remove-member}.md`; modify `shots.js`, `Board/Members.vue`, `OrgNode.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `committee-seats` — pages `[board.members]`. Example: ليلى بوعزة becomes head of «لجنة الإعلام»; كمال درارجة moves from member to deputy of «اللجنة التقنية». Steps: the committee cards (one «رئيس اللجنة», two «نائب الرئيس», «الأعضاء» any number) → click the empty «رئيس اللجنة» box of «لجنة الإعلام» (needs add) → picker: type «بوعزة», the «في المكتب» badge; picking saves at once (shot: name typed, ringed, not clicked) → someone not yet on the board is added to the term with the first member-level role → a seated member's box → seat menu: «رئيس اللجنة» / «نائب الرئيس» / «عضو» buttons change the seat (needs edit; full seat → «هذا المنصب مشغول: رئيس واحد ونائبان لكل لجنة.») → «إزالة من اللجنة» (needs delete) now asks first (Task 2; shot of the confirm, not confirmed); the person stays on the board. Count-check «لجنة الإعلام» and «اللجنة التقنية» seats against the seeder before writing.
2. `former-remove-member` — pages `[board.members, board.meetings.show]`. Example: مراد زيتوني left the board mid-term → «سابق»; a person added by mistake → «إزالة من المكتب». Steps: open the member's box → pencil «تعديل» (needs edit) → «الحالة» = «سابق» → «حفظ» (he leaves the organigram, listed under «الأعضاء السابقون (1)»; click to show; his history stays) → for a mistake: seat menu trash «إزالة من المكتب» (needs delete) → confirm text (shot, not confirmed). Warning: removing deletes the member from this term; their past meeting attendance stays under their name in meetings and minutes (Task 3), tasks assigned to them become «غير مُسند» — check `unassigned` in `ar.json` and the tasks FK (`nullOnDelete`). Tip: prefer «سابق» for someone who served. Optional figure: meeting 4's attendance showing a removed member — only if the demo has one; otherwise state it in text.

### Task 9: Articles — `schedule-meeting`, `meeting-minutes`, `cancel-delete-meeting`

**Files:** Create `resources/help/ar/board/{schedule-meeting,meeting-minutes,cancel-delete-meeting}.md`; modify `shots.js`, `Board/Meetings.vue`, `Board/Meeting.vue`, `Board/Calendar.vue` (`data-help` only); `public/help-shots/ar/**`.

1. `schedule-meeting` — pages `[board.meetings, board.calendar]`. Example: schedule «اجتماع تحضير الموسم» next Tuesday 18:00 at «مقر النادي». Steps: open «الاجتماعات» (nav ring; the status filter) → «إضافة اجتماع» (plus; needs add) → «العنوان», «النوع» («عادي» / «استثنائي» / «جمعية عامة»), «التاريخ» (date + time), «المكان» → «جدول الأعمال» items (+ «إضافة بند») → «حفظ» (opens the meeting page, message «تم إنشاء الاجتماع.») → calendar shortcut: «التقويم» (nav ring), hover a day, its + (needs add) opens the same form with the date filled. Check every label in `Meetings.vue` / `Calendar.vue`; shots never save.
2. `meeting-minutes` — pages `[board.meetings.show]`. Example: meeting 1 «اجتماع المكتب العادي» after it was held. Steps: open it from «الاجتماعات» → «الحالة» = «انعقد» and the fields → «الحضور» card: «ح/غ/م»-style buttons (read the first letters `t(s).charAt(0)` in Arabic for present/absent/excused and quote them), current-term members only (Task 4) → «حفظ الحضور» (needs edit) → «محضر الاجتماع» text, «القرارات» (+ «إضافة بند») → «حفظ المحضر» (needs edit; saves fields, agenda, minutes and decisions together) → «ملف المحضر»: choose a file + «رفع» (PDF, Word or photo, up to 10 MB; private: only users with the board right can open it) → «بنود العمل»: task title, assignee (current-term members), due date, + «إضافة مهمة» (needs add; appears in «المهام») → print «محضر الاجتماع» (PDF). Split into ≤ 7 steps (attendance; minutes + decisions + save; file; tasks; PDF — choose).
3. `cancel-delete-meeting` — pages `[board.meetings.show, board.meetings]`. Example: meeting 2 «اجتماع تحضير الجمعية العامة» is postponed → cancel; a duplicate entered by mistake → delete. Steps: open meeting 2 → «إلغاء الاجتماع» (x icon; needs edit; only scheduled meetings) → modal: «سبب الإلغاء» typed (shot, not confirmed), «الإبقاء على الاجتماع» to back out → what a cancelled meeting looks like (meeting 3: banner with date, who, reason; read-only; struck-through title) → «حذف الاجتماع» (trash; needs delete) → its confirm text: meeting, attendance and minutes file erased, tasks kept (shot of the modal, not confirmed). Warning: delete cannot be undone; cancel cannot be undone either (quote `cancel_meeting_confirm`).

---

### Task 10: Phase checks and merge

- [ ] **Step 1:** In the worktree: `php artisan help:check` (56 articles), `php artisan test --filter=Help`, full suite `php -d memory_limit=1G vendor/bin/phpunit`, `node --test tests/js/*.mjs`, `npm run i18n:check`, `npm run build`.
- [ ] **Step 2:** Migration check: on a copy of the dev DB `php artisan migrate` then `migrate:rollback --step=1` then `migrate` — clean; meeting attendance rows keep their names. If a private MariaDB/PostgreSQL instance is configured (see memories `mysql-dashboard-pipes`, `production-postgresql`), run the board tests there too; never on production.
- [ ] **Step 3:** Full `npm run help:shots` run; `git diff --stat public/help-shots`: older shots must not change (the board players are archived; the new sidebar item sits in the collapsed board group). Restore any older image that changed only by encoding noise; investigate any real change.
- [ ] **Step 4:** Manual Playwright pass on the demo DB: the 8 articles light/dark at 1280 and 320 px; the `?` panel on «أعضاء المجلس», «الاجتماعات», a meeting, «التقويم», «أدوار المكتب», «اللجان»; Ctrl+K «اجتماع» and «عهدة»; a user with only `board: view` sees articles 2–8 but not 1, opens members and meetings, and sees no action button (Task 1).
- [ ] **Step 5:** Whole-phase review (spec 3b section + this plan), fix findings, then merge `main` into the branch, rerun Step 1, merge into local `main` (`push-only-verified-tip`: nothing is pushed without the owner's word).
- [ ] **Step 6:** Update `HANDOFF.md` (untracked) and the `help-center-part3` memory: what merged, owner nits found while documenting, deploy notes (**migration** `2026_11_10_100001_keep_board_meeting_attendance_names` → online `php artisan migrate`; `npm ci && npm run build`).
