# Manual Debts Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the «بدون فرع» row of the Subscriptions page open a read-only page listing every obligation in that bucket, with a link to each player.

**Architecture:** A query scope on `PlayerSubscription` selects obligations that belong to no branch. `SubscriptionController::manualDebts()` loads the bucket as plain rows, computes money with `PlayerSubscription::moneyFor`, filters/sorts in PHP (the bucket is small and "remaining" depends on discounts), paginates by hand, and renders `Subscriptions/ManualDebts.vue`. The Subscriptions index links to it.

**Tech Stack:** Laravel 13, Inertia + Vue 3 (`<script setup>`), Tailwind, vue-i18n (flat dotted keys), PHPUnit feature tests (`#[Test]` attributes), SQLite locally, MariaDB-compatible SQL.

**Spec:** `docs/superpowers/specs/2026-11-19-manual-debts-page-design.md`

## Global Constraints

- Read-only page: it changes no money records. No edit/pay/delete controls.
- No-branch rule: a `player_subscriptions` row whose `subscription_id` is null, or whose subscription has no row in `branch_subscription`.
- Money figures come from `PlayerSubscription::moneyFor` / `->money()` only. `owed` is gross; `remaining` honours discounts and exemptions.
- Page totals must equal the "No branch" row of `SubscriptionController::branchStats()`.
- Permission: Subscriptions → view only. No new right, no migration.
- SQL must run on SQLite and MariaDB: no SQLite-only functions.
- Player names are always `Player::fullname`. A query that narrows player columns must select `Player::NAME_COLUMNS`.
- i18n: flat keys in `resources/js/i18n/{ar,fr,en}.json`, kept in alphabetical order, insert-only edits (do not reformat the files). No key segment after a dot may be a JS String method name (`link`, `search`, …). `npm run i18n:check` must pass.
- Offline only: no CDN or remote resource.
- Dark mode: use `primary-300` (not 400) for primary text on dark; every light hover needs a dark hover.
- Must work at 320 px width.
- French calls a branch « section ».
- Commits: stage explicit files only (the main working tree holds unrelated untracked files). End every commit message with `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Work in a git worktree on branch `feat/manual-debts` created from `main` (use superpowers:using-git-worktrees). Copy `vendor/` and `node_modules/` into the worktree (no junctions). Move the two untracked docs (`docs/superpowers/specs/2026-11-19-manual-debts-page-design.md`, `docs/superpowers/plans/2026-11-19-manual-debts-page.md`) from the main tree into the worktree and commit them first with message `docs: manual debts page spec and plan`.

## File Structure

| File | Responsibility |
|---|---|
| `app/Models/PlayerSubscription.php` (modify) | `scopeWithoutBranch` — the No-branch rule as a query scope |
| `app/Support/PermissionMap.php` (modify) | `manual-debts` → `view` |
| `routes/web.php` (modify) | route `subscriptions.manual-debts`, before the resource |
| `app/Http/Controllers/SubscriptionController.php` (modify) | `manualDebts()` action |
| `resources/js/Pages/Subscriptions/ManualDebts.vue` (create) | the page |
| `resources/js/Components/Dashboard/StatStrip.vue` (modify) | icons/tones for the four `debts_*` tiles |
| `resources/js/Pages/Subscriptions/Index.vue` (modify) | clickable branch-table rows |
| `resources/js/i18n/{ar,fr,en}.json` (modify) | new keys |
| `resources/help/{ar,fr,en}/subscriptions/previous-debt.md` (modify) | one tip |
| `tests/Feature/ManualDebtsPageTest.php` (create) | feature tests |
| `tests/Unit/PermissionMapTest.php` (modify) | one assertion |

---

### Task 1: No-branch scope and view permission

**Files:**
- Modify: `app/Models/PlayerSubscription.php`
- Modify: `app/Support/PermissionMap.php:51-57`
- Modify: `tests/Unit/PermissionMapTest.php`
- Test: `tests/Feature/ManualDebtsPageTest.php` (create)

**Interfaces:**
- Produces: `PlayerSubscription::query()->withoutBranch()` (Eloquent scope, no arguments). `PermissionMap::resolve('subscriptions.manual-debts') === ['subscriptions', 'view']`.
- Produces test helpers in `ManualDebtsPageTest` used by Task 2: `makePlayer(string $first = 'P', string $last = 'Test'): Player`, `makeSubscription(string $name, array $branchIds = []): Subscription`, `debt(Player $player, ?Subscription $sub, float $owed, float $paid, array $extra = []): PlayerSubscription`, `userWith(array $permissions): User`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ManualDebtsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualDebtsPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function makePlayer(string $first = 'P', string $last = 'Test'): Player
    {
        return Player::create([
            'membership_id' => '9'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => $first,
            'lastname' => $last,
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    private function makeSubscription(string $name, array $branchIds = []): Subscription
    {
        $sub = Subscription::create([
            'name' => $name,
            'year' => 2026,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        if ($branchIds) {
            $sub->branches()->attach($branchIds);
        }

        return $sub;
    }

    /** A manual debt when $sub is null, otherwise an obligation of that subscription. */
    private function debt(Player $player, ?Subscription $sub, float $owed, float $paid, array $extra = []): PlayerSubscription
    {
        return PlayerSubscription::create(array_merge([
            'player_id' => $player->id,
            'subscription_id' => $sub?->id,
            'label' => $sub ? null : 'Old debt',
            'year' => 2026,
            'status_at_time' => 'student',
            'is_mandatory' => true,
            'is_legacy' => $sub === null,
            'amount_owed' => $owed,
            'amount_paid' => $paid,
        ], $extra));
    }

    #[Test]
    public function the_scope_keeps_manual_debts_and_branchless_subscriptions_only(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $manual = $this->debt($this->makePlayer(), null, 1500, 0);
        $branchless = $this->debt($this->makePlayer(), $this->makeSubscription('Loose'), 2000, 0);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 2000, 0);

        $this->assertEqualsCanonicalizing(
            [$manual->id, $branchless->id],
            PlayerSubscription::query()->withoutBranch()->pluck('id')->all()
        );
    }
}
```

In `tests/Unit/PermissionMapTest.php`, add this line right after the existing `PermissionMap::resolve('equipment.inventory')` assertion:

```php
        $this->assertSame(['subscriptions', 'view'], PermissionMap::resolve('subscriptions.manual-debts'));
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter="ManualDebtsPageTest|PermissionMapTest"`
Expected: FAIL — `Call to undefined method ...::withoutBranch()` and `['subscriptions', 'edit']` is not `['subscriptions', 'view']`.

- [ ] **Step 3: Implement the scope**

In `app/Models/PlayerSubscription.php`, add `use Illuminate\Database\Eloquent\Builder;` to the imports, and add this method right after `payments()`:

```php
    /**
     * Obligations that belong to no branch: a manual debt (no subscription) or
     * one whose subscription has no branch ticked. The same rule the per-branch
     * totals of the Subscriptions page use for their "No branch" row.
     *
     * One NOT EXISTS covers both cases: with a null subscription_id the inner
     * comparison is never true, so nothing "exists".
     */
    public function scopeWithoutBranch(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($branches) => $branches
            ->from('branch_subscription')
            ->whereColumn('branch_subscription.subscription_id', 'player_subscriptions.subscription_id'));
    }
```

- [ ] **Step 4: Map the route action to `view`**

In `app/Support/PermissionMap.php`, in `deriveAction()`, change

```php
            'preview-serial' => 'view',
```

to

```php
            'preview-serial', 'manual-debts' => 'view',
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter="ManualDebtsPageTest|PermissionMapTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/PlayerSubscription.php app/Support/PermissionMap.php tests/Feature/ManualDebtsPageTest.php tests/Unit/PermissionMapTest.php
git commit -m "feat(subscriptions): no-branch obligations scope, manual-debts view right

Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 2: Route, controller action, page stub

**Files:**
- Modify: `routes/web.php:252-253`
- Modify: `app/Http/Controllers/SubscriptionController.php`
- Create: `resources/js/Pages/Subscriptions/ManualDebts.vue` (stub; Task 3 completes it)
- Test: `tests/Feature/ManualDebtsPageTest.php`

**Interfaces:**
- Consumes: `PlayerSubscription::withoutBranch()` and the test helpers from Task 1.
- Produces: route `subscriptions.manual-debts` (`GET /subscriptions/manual-debts`, query params `status` = `unpaid`|`paid`, `search` = text, `page`). Inertia component `Subscriptions/ManualDebts` with props:
  - `debts`: Laravel paginator array; each item of `debts.data` is
    `{ id:int, player:{id:int, fullname:string, membership_id:string}|null, label:string|null, season:string|null, is_manual:bool, owed:float, paid:float, remaining:float, status:'paid'|'partial'|'unpaid'|'exempt', created_at:string|null }`
  - `strip`: `[{key:'debts_count', value:int, format:'number'}, {key:'debts_owed', value:float, format:'money'}, {key:'debts_collected', value:float, format:'money'}, {key:'debts_left', value:float, format:'money'}]`
  - `filters`: `{ status?: 'unpaid'|'paid', search?: string }`

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/ManualDebtsPageTest.php` (inside the class), plus `use Illuminate\Support\Carbon;` in the imports:

```php
    /** @return array<string, mixed> the page's Inertia props */
    private function page(array $query = [], ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->userWith(['subscriptions' => ['view']]))
            ->get(route('subscriptions.manual-debts', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    #[Test]
    public function the_url_reaches_the_page_not_a_subscription(): void
    {
        $response = $this->actingAs($this->userWith(['subscriptions' => ['view']]))
            ->get('/subscriptions/manual-debts')
            ->assertOk();

        $this->assertSame('Subscriptions/ManualDebts', $response->viewData('page')['component']);
    }

    #[Test]
    public function it_lists_only_obligations_without_a_branch(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $ali = $this->makePlayer('Ali', 'Benali');
        $manual = $this->debt($ali, null, 1500, 500, ['label' => 'Winter kit', 'year' => 2025]);
        $loose = $this->makeSubscription('Loose fee');
        $branchless = $this->debt($this->makePlayer(), $loose, 2000, 0);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 2000, 0);

        $rows = collect($this->page()['debts']['data'])->keyBy('id');

        $this->assertEqualsCanonicalizing([$manual->id, $branchless->id], $rows->keys()->all());

        $row = $rows[$manual->id];
        $this->assertSame($ali->id, $row['player']['id']);
        $this->assertSame($ali->fullname, $row['player']['fullname']);
        $this->assertSame($ali->membership_id, $row['player']['membership_id']);
        $this->assertSame('Winter kit', $row['label']);
        $this->assertSame('2025', $row['season']);
        $this->assertTrue($row['is_manual']);
        $this->assertEquals(1500, $row['owed']);
        $this->assertEquals(500, $row['paid']);
        $this->assertEquals(1000, $row['remaining']);
        $this->assertSame('partial', $row['status']);
        $this->assertNotNull($row['created_at']);

        $this->assertSame('Loose fee', $rows[$branchless->id]['label']);
        $this->assertSame($loose->year_label, $rows[$branchless->id]['season']);
        $this->assertFalse($rows[$branchless->id]['is_manual']);
    }

    #[Test]
    public function totals_equal_the_no_branch_row_of_the_subscriptions_page(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $this->debt($this->makePlayer(), null, 1500, 1500);
        $this->debt($this->makePlayer(), null, 2500, 2000);
        // Half price: 1000 net, nothing paid.
        $this->debt($this->makePlayer(), null, 2000, 0, ['discount_type' => 'percent', 'discount_value' => 50]);
        // Exempt: owed stays gross, nothing remains.
        $this->debt($this->makePlayer(), null, 3000, 0, ['is_exempt' => true]);
        $this->debt($this->makePlayer(), $this->makeSubscription('Season', [$branch->id]), 9000, 0);

        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $stats = $this->actingAs($admin)->get(route('subscriptions.index'))->assertOk()->viewData('page')['props']['branchStats'];
        $none = collect($stats)->firstWhere('branch_id', null);

        $strip = collect($this->page()['strip'])->pluck('value', 'key');

        $this->assertSame(4, $strip['debts_count']);
        $this->assertEquals($none['owed'], $strip['debts_owed']);
        $this->assertEquals($none['collected'], $strip['debts_collected']);
        $this->assertEquals($none['outstanding'], $strip['debts_left']);
        $this->assertEquals(9000, $strip['debts_owed']);
        $this->assertEquals(3500, $strip['debts_collected']);
        $this->assertEquals(1500, $strip['debts_left']);
    }

    #[Test]
    public function totals_ignore_the_filters(): void
    {
        $this->debt($this->makePlayer(), null, 1500, 1500);
        $this->debt($this->makePlayer(), null, 2500, 2000);

        $strip = collect($this->page(['status' => 'unpaid', 'search' => 'nobody'])['strip'])->pluck('value', 'key');

        $this->assertSame(2, $strip['debts_count']);
        $this->assertEquals(500, $strip['debts_left']);
    }

    #[Test]
    public function the_status_filter_splits_settled_from_unsettled(): void
    {
        $paid = $this->debt($this->makePlayer(), null, 1500, 1500);
        $partial = $this->debt($this->makePlayer(), null, 2500, 2000);
        $unpaid = $this->debt($this->makePlayer(), null, 1000, 0);
        $exempt = $this->debt($this->makePlayer(), null, 3000, 0, ['is_exempt' => true]);

        $ids = fn (array $query) => array_column($this->page($query)['debts']['data'], 'id');

        $this->assertEqualsCanonicalizing([$partial->id, $unpaid->id], $ids(['status' => 'unpaid']));
        $this->assertEqualsCanonicalizing([$paid->id, $exempt->id], $ids(['status' => 'paid']));
        $this->assertCount(4, $ids(['status' => 'junk']));
        $this->assertArrayNotHasKey('status', $this->page(['status' => 'junk'])['filters']);
    }

    #[Test]
    public function search_matches_player_name_and_label(): void
    {
        $ali = $this->debt($this->makePlayer('Ali', 'Benali'), null, 1000, 0, ['label' => 'Winter kit']);
        $omar = $this->debt($this->makePlayer('Omar', 'Cherif'), null, 1000, 0, ['label' => 'Season 2024']);

        $ids = fn (string $search) => array_column($this->page(['search' => $search])['debts']['data'], 'id');

        $this->assertSame([$ali->id], $ids('benali'));
        $this->assertSame([$ali->id], $ids('Benali Ali'));
        $this->assertSame([$omar->id], $ids('season'));
        $this->assertSame([], $ids('Benali Omar'));
        $this->assertSame('benali', $this->page(['search' => ' benali '])['filters']['search']);
    }

    #[Test]
    public function unsettled_debts_come_first_then_the_newest(): void
    {
        $at = function (PlayerSubscription $debt, string $when): PlayerSubscription {
            $debt->created_at = Carbon::parse($when);
            $debt->save();

            return $debt;
        };
        $paidNew = $at($this->debt($this->makePlayer(), null, 1000, 1000), '2026-11-10 10:00:00');
        $owingOld = $at($this->debt($this->makePlayer(), null, 1000, 0), '2026-01-05 10:00:00');
        $owingNew = $at($this->debt($this->makePlayer(), null, 1000, 0), '2026-11-01 10:00:00');

        $this->assertSame(
            [$owingNew->id, $owingOld->id, $paidNew->id],
            array_column($this->page()['debts']['data'], 'id')
        );
    }

    #[Test]
    public function it_paginates_twenty_five_per_page(): void
    {
        $player = $this->makePlayer();
        foreach (range(1, 27) as $i) {
            $this->debt($player, null, 100, 0, ['label' => "Debt $i"]);
        }

        $first = $this->page()['debts'];
        $this->assertCount(25, $first['data']);
        $this->assertSame(27, $first['total']);
        $this->assertCount(2, $this->page(['page' => 2])['debts']['data']);
    }

    #[Test]
    public function it_needs_subscriptions_view_only(): void
    {
        $this->actingAs($this->userWith(['subscriptions' => ['view']]))
            ->get(route('subscriptions.manual-debts'))->assertOk();
        $this->actingAs($this->userWith(['players' => ['view', 'edit']]))
            ->get(route('subscriptions.manual-debts'))->assertForbidden();
    }

    #[Test]
    public function an_empty_bucket_still_renders(): void
    {
        $props = $this->page();

        $this->assertSame([], $props['debts']['data']);
        $this->assertSame(0, collect($props['strip'])->firstWhere('key', 'debts_count')['value']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ManualDebtsPageTest`
Expected: the new tests FAIL with `Route [subscriptions.manual-debts] not defined` (the Task 1 scope test still passes).

- [ ] **Step 3: Add the route**

In `routes/web.php`, change

```php
    // Subscriptions
    Route::resource('subscriptions', SubscriptionController::class);
```

to

```php
    // Subscriptions
    // Before the resource: otherwise "manual-debts" is read as a {subscription} id.
    Route::get('/subscriptions/manual-debts', [SubscriptionController::class, 'manualDebts'])->name('subscriptions.manual-debts');
    Route::resource('subscriptions', SubscriptionController::class);
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/SubscriptionController.php`, add `use Illuminate\Pagination\LengthAwarePaginator;` to the imports (keep them alphabetical: after `Illuminate\Http\Request;`). Add this method right after `branchStats()`:

```php
    /**
     * Every obligation behind the "No branch" row of the index: manual debts
     * and obligations of a subscription with no branch. Read-only; paying,
     * editing and deleting stay on the player's page.
     *
     * "Remaining" depends on discounts and exemptions, so status filtering and
     * ordering happen in PHP on plain rows. The bucket is small: it only holds
     * debts typed by hand.
     */
    public function manualDebts(Request $request): Response
    {
        $status = in_array($request->query('status'), ['unpaid', 'paid'], true) ? $request->query('status') : null;
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';

        $columns = ['id', 'amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value', 'created_at'];
        $withMoney = fn ($row) => ['id' => (int) $row->id, 'created_at' => (string) $row->created_at]
            + PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, (bool) $row->is_exempt, $row->discount_type, $row->discount_value);

        $bucket = PlayerSubscription::query()->withoutBranch()->toBase()->get($columns)->map($withMoney);

        $rows = $search === '' ? $bucket : PlayerSubscription::query()->withoutBranch()
            // Every word must be found somewhere: in the label, the subscription
            // name, or one of the player's names / membership id.
            ->where(function (Builder $query) use ($search) {
                foreach (preg_split('/\s+/u', $search) as $word) {
                    $like = '%'.$word.'%';
                    $query->where(fn (Builder $q) => $q
                        ->where('label', 'like', $like)
                        ->orWhereHas('subscription', fn (Builder $s) => $s->where('name', 'like', $like))
                        ->orWhereHas('player', fn (Builder $p) => $p->where(fn (Builder $p) => $p
                            ->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('nickname', 'like', $like)
                            ->orWhere('father', 'like', $like)
                            ->orWhere('membership_id', 'like', $like))));
                }
            })
            ->toBase()->get($columns)->map($withMoney);

        if ($status !== null) {
            $rows = $rows->filter(fn (array $row) => ($row['remaining'] > 0) === ($status === 'unpaid'));
        }

        // Something left to pay first, then the newest.
        $rows = $rows->sort(fn (array $a, array $b) => [$b['remaining'] > 0, $b['created_at'], $b['id']]
            <=> [$a['remaining'] > 0, $a['created_at'], $a['id']])->values();

        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $ids = $rows->forPage($page, $perPage)->pluck('id');

        $models = PlayerSubscription::query()
            ->with(['subscription', 'player:id,membership_id,'.implode(',', Player::NAME_COLUMNS)])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = $ids->map(function (int $id) use ($models) {
            $debt = $models[$id];
            $money = $debt->money();

            return [
                'id' => $debt->id,
                'player' => $debt->player ? [
                    'id' => $debt->player->id,
                    'fullname' => $debt->player->fullname,
                    'membership_id' => $debt->player->membership_id,
                ] : null,
                'label' => $debt->subscription?->name ?? $debt->label,
                'season' => $debt->subscription ? $debt->subscription->year_label : ($debt->year ? (string) $debt->year : null),
                'is_manual' => $debt->subscription_id === null,
                'owed' => $money['owed'],
                'paid' => $money['paid'],
                'remaining' => $money['remaining'],
                'status' => $money['status'],
                // Through the model's serializer, so it is on the club's clock.
                'created_at' => $debt->attributesToArray()['created_at'] ?? null,
            ];
        })->all();

        return Inertia::render('Subscriptions/ManualDebts', [
            'debts' => new LengthAwarePaginator($items, $rows->count(), $perPage, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
            // The whole bucket, whatever the filters: these equal the index row.
            'strip' => [
                ['key' => 'debts_count', 'value' => $bucket->count(), 'format' => 'number'],
                ['key' => 'debts_owed', 'value' => round($bucket->sum('owed'), 2), 'format' => 'money'],
                ['key' => 'debts_collected', 'value' => round($bucket->sum('paid'), 2), 'format' => 'money'],
                ['key' => 'debts_left', 'value' => round($bucket->sum('remaining'), 2), 'format' => 'money'],
            ],
            'filters' => array_filter(['status' => $status, 'search' => $search], fn ($v) => $v !== null && $v !== ''),
        ]);
    }
```

- [ ] **Step 5: Create the page stub**

Create `resources/js/Pages/Subscriptions/ManualDebts.vue` (Task 3 replaces it; it exists now so Inertia can resolve the component):

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    debts: Object,
    strip: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AuthenticatedLayout />
</template>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=ManualDebtsPageTest`
Expected: PASS (11 tests).

If `$props['debts']['data']` errors because `debts` is still a paginator object rather than an array, change the last line of the `page()` test helper to `return json_decode(json_encode($response->viewData('page')['props']), true);` (assigning the response to `$response` first). Do not change the controller for this.

If `totals_equal_the_no_branch_row…` fails on `debts_left`: `branchStats()` and this action must both read `moneyFor(...)['remaining']`; do not change `branchStats()`, fix the action.

- [ ] **Step 7: Run the neighbouring suites**

Run: `php artisan test --filter="Subscription|PermissionMap|Route"`
Expected: PASS. If a test that lists every route (activity mapping or permission coverage) fails because it does not know `subscriptions.manual-debts`, register the route there the same way `subscriptions.export` is registered, and include that file in the commit.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/SubscriptionController.php resources/js/Pages/Subscriptions/ManualDebts.vue tests/Feature/ManualDebtsPageTest.php
git commit -m "feat(subscriptions): manual debts page backend

Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 3: The page

**Files:**
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json`
- Modify: `resources/js/Components/Dashboard/StatStrip.vue:20-41`
- Modify (replace whole file): `resources/js/Pages/Subscriptions/ManualDebts.vue`

**Interfaces:**
- Consumes: props `debts`, `strip`, `filters` exactly as produced by Task 2; route `subscriptions.manual-debts`.
- Produces: i18n keys `date_added`, `manual_debts`, `manual_debts_note`, `manual_debts_search`, `strip.debts_collected`, `strip.debts_count`, `strip.debts_left`, `strip.debts_owed` (Task 4 uses `manual_debts`).

- [ ] **Step 1: Add the i18n keys**

Insert each key at its alphabetical place in all three files (find the neighbour with Grep, insert one line; do not reformat). Existing keys reused as they are: `subscriptions`, `player`, `membership_id`, `label`, `season`, `owed`, `paid`, `remaining`, `status`, `all`, `unpaid`, `partial`, `exempt`, `no_results`, `previous_debt`.

| Key | ar | fr | en |
|---|---|---|---|
| `date_added` | تاريخ الإضافة | Ajoutée le | Date added |
| `manual_debts` | الديون اليدوية | Dettes manuelles | Manual debts |
| `manual_debts_note` | ديون أُضيفت باليد من صفحة اللاعب بزر «إضافة دين سابق». لا تتبع أي اشتراك، ولذلك لا تتبع أي فرع. للدفع أو التعديل أو الحذف، افتح صفحة اللاعب. | Dettes ajoutées à la main depuis la fiche du joueur, avec le bouton « Ajouter une dette antérieure ». Elles ne dépendent d'aucun abonnement, donc d'aucune section. Pour payer, modifier ou supprimer, ouvrez la fiche du joueur. | Debts added by hand on a player's page with the "Add previous debt" button. They belong to no subscription, so to no branch. To pay, edit or delete one, open the player's page. |
| `manual_debts_search` | ابحث باسم اللاعب أو التسمية | Rechercher par joueur ou libellé | Search by player or label |
| `strip.debts_collected` | المحصّل | Encaissé | Collected |
| `strip.debts_count` | عدد الديون | Dettes | Debts |
| `strip.debts_left` | المتبقي | Reste dû | Still owed |
| `strip.debts_owed` | المستحق | Dû | Owed |

Before writing the fr/en text of `manual_debts_note`, read the value of `add_previous_debt` in each file and quote exactly that value as the button name.

- [ ] **Step 2: Check the keys**

Run: `npm run i18n:check`
Expected: passes with no missing or colliding key.

- [ ] **Step 3: Style the four tiles**

In `resources/js/Components/Dashboard/StatStrip.vue`, add to the `STYLE` object, after the `subs_rate` line:

```js

    debts_count: { icon: 'subscriptions', tone: 'primary' },
    debts_owed: { icon: 'money', tone: 'neutral' },
    debts_collected: { icon: 'check', tone: 'positive' },
    debts_left: { icon: 'alert', tone: 'warning' },
```

- [ ] **Step 4: Write the page**

Replace the whole of `resources/js/Pages/Subscriptions/ManualDebts.vue` with:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import Pagination from '@/Components/Pagination.vue';
import Badge from '@/Components/Badge.vue';
import SearchInput from '@/Components/SearchInput.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useListFilters } from '@/Composables/useListFilters';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();

const props = defineProps({
    debts: Object,
    strip: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const status = ref(props.filters?.status ?? '');
const search = ref(props.filters?.search ?? '');

const { loading: filtering } = useListFilters('subscriptions.manual-debts', () => ({
    status: status.value,
    search: search.value,
}), { only: ['debts', 'filters'] });

const statusOptions = computed(() => [
    { value: '', label: t('all') },
    { value: 'unpaid', label: t('unpaid') },
    { value: 'paid', label: t('paid') },
]);

const STATUS_COLOR = { paid: 'emerald', partial: 'amber', unpaid: 'rose', exempt: 'slate' };

const th = 'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
const playerLink = 'font-medium text-slate-900 hover:text-primary-600 hover:underline dark:text-slate-100 dark:hover:text-primary-300';
const leftClass = (debt) => debt.remaining > 0
    ? 'font-semibold text-rose-700 dark:text-rose-400'
    : 'text-slate-500 dark:text-slate-400';
</script>

<template>
    <Head :title="t('manual_debts')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('subscriptions.index')" :aria-label="t('subscriptions')" :title="t('subscriptions')" class="text-slate-400 hover:text-slate-600 dark:text-slate-400 dark:hover:text-slate-300">
                    <svg class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('manual_debts') }}</h1>
            </div>
        </template>

        <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">{{ t('manual_debts_note') }}</p>

        <StatStrip :tiles="strip || []" class="mb-4" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-xl bg-white p-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700" role="group" :aria-label="t('status')">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        :aria-pressed="status === option.value"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="status === option.value
                            ? 'bg-primary-600 text-white'
                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                        @click="status = option.value"
                    >{{ option.label }}</button>
                </div>
                <SearchInput v-model="search" :loading="filtering" :placeholder="t('manual_debts_search')" class="min-w-0 flex-1 sm:max-w-sm" />
            </div>

            <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                <!-- Phones: one card per debt -->
                <ul class="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                    <li v-for="debt in debts.data" :key="debt.id" class="space-y-1 px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <Link v-if="debt.player" :href="route('players.show', debt.player.id)" class="min-w-0 break-words text-sm" :class="playerLink">{{ debt.player.fullname }}</Link>
                            <span v-else class="text-sm text-slate-400">-</span>
                            <Badge :label="t(debt.status)" :color="STATUS_COLOR[debt.status]" class="shrink-0" />
                        </div>
                        <p class="break-words text-xs text-slate-500 dark:text-slate-400">
                            {{ debt.label || t('previous_debt') }}<template v-if="debt.season"> · {{ debt.season }}</template>
                        </p>
                        <p class="text-xs text-slate-400 dark:text-slate-400">
                            <span dir="ltr">{{ debt.player?.membership_id }}</span> · {{ formatDate(debt.created_at) }}
                        </p>
                        <dl class="grid grid-cols-3 gap-2 pt-1 text-xs">
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('owed') }}</dt>
                                <dd class="tabular-nums text-slate-900 dark:text-slate-100">{{ formatMoney(debt.owed) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('paid') }}</dt>
                                <dd class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatMoney(debt.paid) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ t('remaining') }}</dt>
                                <dd class="tabular-nums" :class="leftClass(debt)">{{ formatMoney(debt.remaining) }}</dd>
                            </div>
                        </dl>
                    </li>
                    <li v-if="!debts.data?.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</li>
                </ul>

                <!-- Wider screens: a table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th :class="[th, 'text-start']">{{ t('player') }}</th>
                                <th :class="[th, 'text-start']">{{ t('label') }}</th>
                                <th :class="[th, 'text-start']">{{ t('season') }}</th>
                                <th :class="[th, 'text-end']">{{ t('owed') }}</th>
                                <th :class="[th, 'text-end']">{{ t('paid') }}</th>
                                <th :class="[th, 'text-end']">{{ t('remaining') }}</th>
                                <th :class="[th, 'text-start']">{{ t('date_added') }}</th>
                                <th :class="[th, 'text-start']">{{ t('status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="debt in debts.data" :key="debt.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="px-4 py-3 text-sm">
                                    <Link v-if="debt.player" :href="route('players.show', debt.player.id)" :class="playerLink">{{ debt.player.fullname }}</Link>
                                    <span v-else class="text-slate-400">-</span>
                                    <p class="text-xs text-slate-400 dark:text-slate-400" dir="ltr">{{ debt.player?.membership_id }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ debt.label || t('previous_debt') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ debt.season || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums">{{ formatMoney(debt.owed) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatMoney(debt.paid) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums" :class="leftClass(debt)">{{ formatMoney(debt.remaining) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(debt.created_at) }}</td>
                                <td class="px-4 py-3"><Badge :label="t(debt.status)" :color="STATUS_COLOR[debt.status]" /></td>
                            </tr>
                            <tr v-if="!debts.data?.length">
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="px-4">
                    <Pagination :links="debts" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: build succeeds with no error.

- [ ] **Step 6: Look at the page**

Start a test server on a free port against the worktree (copy `database/database.sqlite` and the `storage/app` media folders for players into the worktree first, or photos 404): `php artisan serve --port=2027 --no-reload`. Add one manual debt from any player's page («إضافة دين سابق»), then open `http://127.0.0.1:2027/subscriptions/manual-debts`.

Check, in Arabic then in English:
- the four tiles show numbers; the debt row shows the player's full name, label, amounts, date, status badge;
- clicking the player name opens the player page; the back arrow returns to Subscriptions and points the right way in RTL and LTR;
- the three status buttons and the search box filter without a full reload;
- at 320 px wide (browser dev tools) cards replace the table and nothing scrolls sideways;
- dark mode: text readable, hovers visible.

Fix anything wrong before committing. Stop the server afterwards.

- [ ] **Step 7: Commit**

```bash
git add resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json resources/js/Components/Dashboard/StatStrip.vue resources/js/Pages/Subscriptions/ManualDebts.vue
git commit -m "feat(subscriptions): manual debts page

Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 4: Entry points on the Subscriptions page

**Files:**
- Modify: `resources/js/Pages/Subscriptions/Index.vue` (script imports, lines 97-98, line 111)
- Modify: `resources/js/i18n/ar.json`, `resources/js/i18n/fr.json`, `resources/js/i18n/en.json`

**Interfaces:**
- Consumes: route `subscriptions.manual-debts`; the existing `branchFilter` ref (a list of string ids) and `branchName(row)` in `Index.vue`.

- [ ] **Step 1: Add the i18n key**

Insert at its alphabetical place in the three files:

| Key | ar | fr | en |
|---|---|---|---|
| `show_branch_subscriptions` | عرض اشتراكات هذا الفرع | Voir les abonnements de cette section | Show this branch's subscriptions |

- [ ] **Step 2: Import what the links need**

In `resources/js/Pages/Subscriptions/Index.vue`, change

```js
import { Head, router } from '@inertiajs/vue3';
```

to

```js
import Icon from '@/Components/Icon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
```

and change

```js
import { computed, ref, watch } from 'vue';
```

to

```js
import { computed, nextTick, ref, watch } from 'vue';
```

Then add, right after the `branchName` line:

```js

// A branch row narrows the list below to that branch and brings it into view.
const list = ref(null);
function showBranch(branchId) {
    branchFilter.value = [String(branchId)];
    nextTick(() => list.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
```

- [ ] **Step 3: Make the rows clickable**

Replace the branch-name cell

```vue
                                <td class="px-4 py-3 text-sm font-medium" :class="row.branch_id === null ? 'text-slate-400 dark:text-slate-400 italic' : 'text-slate-900 dark:text-slate-100'">{{ branchName(row) }}</td>
```

with

```vue
                                <td class="px-4 py-3 text-sm font-medium">
                                    <Link v-if="row.branch_id === null" :href="route('subscriptions.manual-debts')" :title="t('manual_debts')" class="inline-flex items-center gap-1 italic text-primary-600 hover:text-primary-800 hover:underline dark:text-primary-300 dark:hover:text-primary-200">
                                        {{ branchName(row) }}
                                        <Icon name="arrow" class="h-4 w-4 rtl:rotate-180" aria-hidden="true" />
                                    </Link>
                                    <button v-else type="button" :title="t('show_branch_subscriptions')" class="text-start text-slate-900 hover:text-primary-600 hover:underline dark:text-slate-100 dark:hover:text-primary-300" @click="showBranch(row.branch_id)">{{ branchName(row) }}</button>
                                </td>
```

and give the filters block a ref — change

```vue
            <div class="flex flex-wrap items-end gap-3">
```

(the one under the `<!-- Filters -->` comment) to

```vue
            <div ref="list" class="flex scroll-mt-24 flex-wrap items-end gap-3">
```

- [ ] **Step 4: Check and build**

Run: `npm run i18n:check && npm run build`
Expected: both succeed.

- [ ] **Step 5: Look at it**

With the test server from Task 3 Step 6: open Subscriptions. «بدون فرع» is a link with an arrow and opens the manual debts page. Clicking a branch name ticks that branch in the branch filter, the list below shows only its subscriptions, and the page scrolls to the filters. The arrow points left in Arabic and right in English. Check dark mode and 320 px.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Subscriptions/Index.vue resources/js/i18n/ar.json resources/js/i18n/fr.json resources/js/i18n/en.json
git commit -m "feat(subscriptions): branch table rows open their details

Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

---

### Task 5: Help Center tip and full verification

**Files:**
- Modify: `resources/help/ar/subscriptions/previous-debt.md`
- Modify: `resources/help/fr/subscriptions/previous-debt.md`
- Modify: `resources/help/en/subscriptions/previous-debt.md`

**Interfaces:**
- Consumes: i18n values of `subscriptions`, `branch_financial_state`, `no_branch`, `manual_debts` (Task 3) — the quoted screen names in the tip must equal them exactly.

- [ ] **Step 1: Add the tip in Arabic**

Append to the end of `resources/help/ar/subscriptions/previous-debt.md` (after the last `:::`, with one blank line before):

```markdown

:::tip
أين أرى كل الديون المضافة باليد؟ افتح «الاشتراكات». في جدول «الحالة المالية حسب الفرع»، اضغط على «بدون فرع». تظهر صفحة «الديون اليدوية»، وفيها كل دين واسم صاحبه. اضغط على اسم اللاعب لتفتح صفحته.
:::
```

- [ ] **Step 2: Add the twins**

Read the values of `subscriptions`, `branch_financial_state`, `no_branch` and `manual_debts` in `resources/js/i18n/fr.json` and `en.json`, and use exactly those between the quotes.

Append to `resources/help/fr/subscriptions/previous-debt.md`:

```markdown

:::tip
Où voir toutes les dettes ajoutées à la main ? Ouvrez « Abonnements ». Dans le tableau « État financier par section », cliquez sur « Sans section ». La page « Dettes manuelles » s'ouvre : chaque dette y figure avec le nom du joueur. Cliquez sur le nom pour ouvrir sa fiche.
:::
```

Append to `resources/help/en/subscriptions/previous-debt.md`:

```markdown

:::tip
Where can I see every debt added by hand? Open "Subscriptions". In the "Financial state by branch" table, click "No branch". The "Manual debts" page opens, with each debt and the player's name. Click the name to open the player's page.
:::
```

If the fr value of `subscriptions` is not « Abonnements », replace it in the French tip with the real value. Match the quote style (« » or " ") the rest of each file already uses.

- [ ] **Step 3: Run the help tests**

Run: `php artisan test --filter=Help`
Expected: PASS.

- [ ] **Step 4: Run everything**

Run: `php artisan test`
Expected: PASS, no failure.

Run: `npm run i18n:check && npm run build`
Expected: both succeed.

- [ ] **Step 5: Run the new tests on MariaDB**

If the private MariaDB test instance is available (see the `mysql-dashboard-pipes` memory for the env variables), run `php artisan test --filter="ManualDebtsPageTest|Subscription"` against it.
Expected: PASS. If it is not available, say so in the final report instead of skipping silently.

- [ ] **Step 6: Commit**

```bash
git add resources/help/ar/subscriptions/previous-debt.md resources/help/fr/subscriptions/previous-debt.md resources/help/en/subscriptions/previous-debt.md
git commit -m "docs(help): where to see every manual debt

Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>"
```

- [ ] **Step 7: Hand over**

Use superpowers:finishing-a-development-branch. Do not merge or push without the owner's go-ahead. Report: tests run and their result, MariaDB run or not, what was checked in the browser. Remind the owner that the desktop app needs a new build to get the page.
