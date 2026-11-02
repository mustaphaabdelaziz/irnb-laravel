# Subscription Tarification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On each subscription, show live whether its price covers coach, staff and transport costs, with the break-even price and player count.

**Architecture:** Two new tables hold the saved inputs, loaded only for users with Finance → view. One pure JS module computes every result, so the form reacts per keystroke and the Show page reuses it. The server only sends inputs: roster groups, schedule slots, saved values and money collected.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3 `<script setup>`, Tailwind v3, PHPUnit (`#[Test]`, in-memory SQLite), `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-01-subscription-tarification-design.md`

## Global Constraints

- Work only in `D:\irnb-tarification`, branch `feat/subscription-tarification`. Never touch `D:\irnb-laravel`.
- Stage explicit file paths only, never a directory.
- No junctions in the worktree: `vendor` and `node_modules` are copies.
- No CDN or remote resources: the desktop app runs offline.
- Forecast only: nothing creates a transaction or expense.
- Coach pay never goes on `subscriptions` columns, `$with` or `$appends`.
- Every new model uses `SerializesClubDates`.
- Layout uses logical properties (`ms-`, `text-start`, `text-end`); works in RTL, dark mode and phone width.
- i18n: text in `en.json`, `fr.json`, `ar.json`; insert lines only, never reformat those files; use `t()` directly.
- If tests fail en masse with "Cached config detected", run `php artisan config:clear`.

---

## File map

| File | Responsibility |
|---|---|
| `resources/js/lib/tarification.js` | All formulas (create) |
| `tests/js/tarification.test.mjs` | Formula tests (create) |
| `database/migrations/2026_11_01_300001_create_subscription_tarification_tables.php` | Two tables (create) |
| `app/Models/SubscriptionTarification.php`, `app/Models/SubscriptionCostLine.php` | Models (create) |
| `app/Models/Subscription.php` | `tarification()`, `costLines()` |
| `app/Services/Finance/SaveSubscriptionTarification.php` | Saves the block (create) |
| `app/Services/Finance/TarificationData.php` | Builds the prop (create) |
| `app/Services/Finance/FinanceResetService.php` | Also empties both tables |
| `app/Http/Requests/Subscription/StoreSubscriptionRequest.php` | Rules, gated on Finance → view |
| `app/Http/Controllers/SubscriptionController.php` | Passes the prop, calls the save |
| `tests/Feature/SubscriptionTarificationTest.php` | Save, validation, access, prop content (create) |
| `resources/js/Pages/Subscriptions/Partials/TarificationResult.vue` | Result panel, form and Show (create) |
| `resources/js/Pages/Subscriptions/Partials/TarificationCard.vue` | Inputs and cost lines (create) |
| `resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue`, `Create.vue`, `Edit.vue`, `Show.vue` | Mount the card and the summary |
| `resources/js/i18n/{en,fr,ar}.json` | `tarification.*` keys |

---

### Task 1: Worktree setup and the calculation module

**Files:**
- Create: `resources/js/lib/tarification.js`
- Test: `tests/js/tarification.test.mjs`

**Interfaces:**
- Produces: `UNITS`, `DEFAULT_WEEKS`, `DEFAULT_PAYMENT_RATE`, `lineCost(line, {sessions, players})`, `matchingGroups(roster, subscription)`, `autoPlayers(roster, subscription)`, `averagePrice(roster, subscription)`, `defaultSessionsPerWeek(schedule, subscription)`, `calculate({lines, sessions, players, paymentRate, price})`, `forecast({tarification, roster, schedule, subscription})`, `subscriptionInput(subscription)`.
- Shapes: roster group `{category_id, is_student, branch_ids, count}`. Schedule row `{branch_id, category_id, slots}`. `subscription` input `{kind, amount_student, amount_worker, category_ids, branch_ids, category_prices}`. `tarification` input `{sessions_per_week, weeks, expected_players, expected_payment_rate, lines}`. Line `{label, unit, amount, quantity}`.
- `calculate` returns `{sessions, lines, income, costs, margin, marginPerPlayer, breakEvenPrice, breakEvenPlayers, status, hasCosts, players, price, paymentRate}`, where `status` is `'win' | 'lose' | 'even' | null` and each entry of `lines` adds `total`.
- `forecast` returns that plus `{autoPlayers, autoSessionsPerWeek, sessionsPerWeek, weeks}`.

- [ ] **Step 1: Copy dependencies into the worktree**

Run in PowerShell (robocopy exit codes below 8 mean success):

```powershell
robocopy D:\irnb-laravel\vendor D:\irnb-tarification\vendor /E /NFL /NDL /NJH /NJS /NP
robocopy D:\irnb-laravel\node_modules D:\irnb-tarification\node_modules /E /NFL /NDL /NJH /NJS /NP
Copy-Item D:\irnb-laravel\.env D:\irnb-tarification\.env
```

Then in `D:\irnb-tarification`: `composer dump-autoload` and `php artisan config:clear`. `git status --short` must not list `vendor`, `node_modules` or `.env`.

- [ ] **Step 2: Write the failing tests**

Create `tests/js/tarification.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    UNITS, lineCost, matchingGroups, autoPlayers, averagePrice,
    defaultSessionsPerWeek, calculate, forecast, subscriptionInput,
} from '../../resources/js/lib/tarification.js';

const line = (unit, amount, quantity = 1) => ({ label: unit, unit, amount, quantity });

const sub = (over = {}) => ({
    kind: 'annual', amount_student: 15000, amount_worker: 15000,
    category_ids: [], branch_ids: [], category_prices: {}, ...over,
});

test('UNITS lists the five units', () => {
    assert.deepEqual(UNITS, ['per_session', 'per_trip', 'per_month', 'per_season', 'per_player']);
});

test('lineCost: each unit', () => {
    const ctx = { sessions: 120, players: 20 };
    assert.equal(lineCost(line('per_session', 700, 2), ctx), 168000);
    assert.equal(lineCost(line('per_trip', 15000, 10), ctx), 150000);
    assert.equal(lineCost(line('per_month', 30000, 10), ctx), 300000);
    assert.equal(lineCost(line('per_season', 5000, 3), ctx), 15000);
    assert.equal(lineCost(line('per_player', 1500), ctx), 30000);
});

test('lineCost: strings from inputs and blanks do not break it', () => {
    assert.equal(lineCost({ unit: 'per_trip', amount: '800.00', quantity: '2' }, { sessions: 0, players: 0 }), 1600);
    assert.equal(lineCost({ unit: 'per_trip', amount: '', quantity: '' }, { sessions: 0, players: 0 }), 0);
});

test('calculate: the worked example from the spec', () => {
    const r = calculate({
        lines: [line('per_session', 800), line('per_session', 700, 2), line('per_trip', 15000, 10)],
        sessions: 120, players: 20, paymentRate: 85, price: 15000,
    });
    assert.equal(r.income, 255000);
    assert.equal(r.costs, 414000);
    assert.equal(r.margin, -159000);
    assert.equal(r.marginPerPlayer, -7950);
    assert.equal(r.status, 'lose');
    assert.equal(r.breakEvenPrice, 24353);
    assert.equal(r.breakEvenPlayers, 33);
    assert.deepEqual(r.lines.map((l) => l.total), [96000, 168000, 150000]);
});

test('calculate: win and exact even', () => {
    const base = { lines: [line('per_season', 100000)], sessions: 0, players: 10, paymentRate: 100 };
    assert.equal(calculate({ ...base, price: 15000 }).status, 'win');
    assert.equal(calculate({ ...base, price: 10000 }).status, 'even');
});

test('calculate: an exact break-even price is not pushed up by float noise', () => {
    const r = calculate({ lines: [line('per_season', 255000)], sessions: 0, players: 20, paymentRate: 85, price: 15000 });
    assert.equal(r.breakEvenPrice, 15000);
    assert.equal(r.breakEvenPlayers, 20);
    assert.equal(r.status, 'even');
});

test('calculate: per-player costs grow with players', () => {
    const r = calculate({
        lines: [line('per_season', 90000), line('per_player', 1000)],
        sessions: 0, players: 10, paymentRate: 100, price: 10000,
    });
    assert.equal(r.costs, 100000);
    assert.equal(r.breakEvenPrice, 10000);
    assert.equal(r.breakEvenPlayers, 10);
});

test('calculate: no players leaves per-player figures undefined, players break-even stays', () => {
    const r = calculate({ lines: [line('per_season', 100000)], sessions: 0, players: 0, paymentRate: 100, price: 10000 });
    assert.equal(r.income, 0);
    assert.equal(r.status, 'lose');
    assert.equal(r.marginPerPlayer, null);
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, 10);
});

test('calculate: 0% payment has no break-even at all', () => {
    const r = calculate({ lines: [line('per_season', 1000)], sessions: 0, players: 10, paymentRate: 0, price: 10000 });
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, null);
});

test('calculate: a price under the per-player cost can never break even by adding players', () => {
    const r = calculate({ lines: [line('per_player', 2000)], sessions: 0, players: 10, paymentRate: 100, price: 1500 });
    assert.equal(r.breakEvenPlayers, null);
    assert.equal(r.breakEvenPrice, 2000);
});

test('calculate: a free subscription with costs has no players break-even', () => {
    const r = calculate({ lines: [line('per_season', 1000)], sessions: 0, players: 10, paymentRate: 100, price: 0 });
    assert.equal(r.breakEvenPlayers, null);
});

test('calculate: no costs means no verdict, not a win', () => {
    const r = calculate({ lines: [], sessions: 0, players: 10, paymentRate: 100, price: 10000 });
    assert.equal(r.hasCosts, false);
    assert.equal(r.status, null);
    assert.equal(r.breakEvenPrice, null);
    assert.equal(r.breakEvenPlayers, null);
});

const roster = [
    { category_id: 1, is_student: true, branch_ids: [10], count: 5 },
    { category_id: 1, is_student: false, branch_ids: [10, 20], count: 3 },
    { category_id: 2, is_student: true, branch_ids: [20], count: 4 },
    { category_id: null, is_student: true, branch_ids: [], count: 2 },
];

test('matchingGroups: no category and no branch is open to everyone', () => {
    assert.equal(matchingGroups(roster, sub()).length, 4);
    assert.equal(autoPlayers(roster, sub()), 14);
});

test('matchingGroups: category filter leaves out players with no category', () => {
    assert.equal(autoPlayers(roster, sub({ category_ids: [1] })), 8);
});

test('matchingGroups: branch filter counts a two-branch player once', () => {
    assert.equal(autoPlayers(roster, sub({ branch_ids: [10, 20] })), 12);
    assert.equal(autoPlayers(roster, sub({ branch_ids: [10] })), 8);
});

test('averagePrice: weighted by count, student and worker rates', () => {
    const s = sub({ amount_student: 1000, amount_worker: 2000, category_ids: [1] });
    assert.equal(averagePrice(roster, s), (5 * 1000 + 3 * 2000) / 8);
});

test('averagePrice: category override wins, a blank override falls back', () => {
    const s = sub({
        amount_student: 1000, amount_worker: 1000, category_ids: [1, 2],
        category_prices: { 1: { amount_student: 500, amount_worker: '' }, 2: { amount_student: null, amount_worker: null } },
    });
    assert.equal(averagePrice(roster, s), (5 * 500 + 3 * 1000 + 4 * 1000) / 12);
});

test('averagePrice: no matching players takes the mean of the default prices', () => {
    assert.equal(averagePrice([], sub({ amount_student: 1000, amount_worker: 2000 })), 1500);
});

const schedule = [
    { branch_id: 10, category_id: 1, slots: 3 },
    { branch_id: 10, category_id: 2, slots: 2 },
    { branch_id: 20, category_id: 1, slots: 5 },
    { branch_id: null, category_id: 3, slots: 4 },
];

test('defaultSessionsPerWeek: the highest slot count, never a sum', () => {
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [1, 2], branch_ids: [10] })), 3);
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [1], branch_ids: [10, 20] })), 5);
});

test('defaultSessionsPerWeek: a club-wide slot applies to any branch', () => {
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [3], branch_ids: [10] })), 4);
});

test('defaultSessionsPerWeek: no matching slots is 0', () => {
    assert.equal(defaultSessionsPerWeek([], sub()), 0);
    assert.equal(defaultSessionsPerWeek(schedule, sub({ category_ids: [99] })), 0);
});

test('forecast: blank inputs take the automatic values and the defaults', () => {
    const r = forecast({
        tarification: { sessions_per_week: null, weeks: '', expected_players: '', expected_payment_rate: '', lines: [line('per_session', 800)] },
        roster,
        schedule,
        subscription: sub({ category_ids: [1], branch_ids: [10] }),
    });
    assert.equal(r.autoPlayers, 8);
    assert.equal(r.players, 8);
    assert.equal(r.autoSessionsPerWeek, 3);
    assert.equal(r.sessionsPerWeek, 3);
    assert.equal(r.weeks, 40);
    assert.equal(r.sessions, 120);
    assert.equal(r.paymentRate, 100);
    assert.equal(r.costs, 96000);
});

test('forecast: typed values win, 0 is a real value', () => {
    const r = forecast({
        tarification: { sessions_per_week: 0, weeks: 30, expected_players: 25, expected_payment_rate: 0, lines: [line('per_session', 800)] },
        roster,
        schedule,
        subscription: sub({ category_ids: [1], branch_ids: [10] }),
    });
    assert.equal(r.players, 25);
    assert.equal(r.sessions, 0);
    assert.equal(r.paymentRate, 0);
    assert.equal(r.income, 0);
});

test('forecast: an exceptional subscription has no sessions', () => {
    const r = forecast({
        tarification: { sessions_per_week: 3, weeks: 40, expected_players: 10, expected_payment_rate: 100, lines: [line('per_player', 500)] },
        roster,
        schedule,
        subscription: sub({ kind: 'exceptional' }),
    });
    assert.equal(r.sessions, 0);
    assert.equal(r.costs, 5000);
});

test('subscriptionInput: reads the shape the Show page receives', () => {
    const input = subscriptionInput({
        kind: 'annual', amount_student: '1000.00', amount_worker: '2000.00',
        categories: [{ id: 1, pivot: { amount_student: '500.00', amount_worker: null } }],
        branches: [{ id: 10 }],
    });
    assert.deepEqual(input.category_ids, [1]);
    assert.deepEqual(input.branch_ids, [10]);
    assert.deepEqual(input.category_prices, { 1: { amount_student: '500.00', amount_worker: null } });
});
```

- [ ] **Step 3: Run tests, confirm they fail**

Run: `node --test tests/js/tarification.test.mjs`
Expected: FAIL, cannot find module `tarification.js`.

- [ ] **Step 4: Write the module**

Create `resources/js/lib/tarification.js`:

```js
// The tarification forecast: does a subscription's price cover its costs?
// The only copy of these formulas. The server sends inputs and never computes
// a result, because the form has to react on each keystroke.

export const UNITS = ['per_session', 'per_trip', 'per_month', 'per_season', 'per_player'];
export const DEFAULT_WEEKS = 40;
export const DEFAULT_PAYMENT_RATE = 100;

const isBlank = (value) => value === null || value === undefined || value === '';

const number = (value) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
};

const orDefault = (value, fallback) => (isBlank(value) ? fallback : number(value));

const money = (value) => Math.round(value * 100) / 100;

// Rounds up to a whole number, without letting float noise add one.
const roundUp = (value) => Math.ceil(Number(value.toFixed(6)));

export function lineCost(line, { sessions, players }) {
    const base = number(line.amount) * number(line.quantity);

    if (line.unit === 'per_session') return base * sessions;
    if (line.unit === 'per_player') return base * players;

    return base;
}

const includesId = (ids, id) => ids.some((other) => Number(other) === Number(id));

// Same rule as Subscription::scopeOpenTo: no category or no branch means open.
export function matchingGroups(roster, subscription) {
    const categoryIds = subscription.category_ids ?? [];
    const branchIds = subscription.branch_ids ?? [];

    return roster.filter((group) => {
        const inCategory = !categoryIds.length || (group.category_id !== null && includesId(categoryIds, group.category_id));
        const inBranch = !branchIds.length || group.branch_ids.some((id) => includesId(branchIds, id));

        return inCategory && inBranch;
    });
}

export function autoPlayers(roster, subscription) {
    return matchingGroups(roster, subscription).reduce((sum, group) => sum + group.count, 0);
}

// Same result as Subscription::amountFor, from values that may still be unsaved.
function priceFor(group, subscription) {
    const column = group.is_student ? 'amount_student' : 'amount_worker';
    const override = subscription.category_prices?.[group.category_id]?.[column];

    return number(isBlank(override) ? subscription[column] : override);
}

export function averagePrice(roster, subscription) {
    const groups = matchingGroups(roster, subscription);
    const players = groups.reduce((sum, group) => sum + group.count, 0);

    if (!players) {
        return (number(subscription.amount_student) + number(subscription.amount_worker)) / 2;
    }

    return groups.reduce((sum, group) => sum + priceFor(group, subscription) * group.count, 0) / players;
}

// The highest slot count of one group, not a sum: each cost line is for one group.
export function defaultSessionsPerWeek(schedule, subscription) {
    const categoryIds = subscription.category_ids ?? [];
    const branchIds = subscription.branch_ids ?? [];

    return schedule
        .filter((row) => !categoryIds.length || includesId(categoryIds, row.category_id))
        .filter((row) => !branchIds.length || row.branch_id === null || includesId(branchIds, row.branch_id))
        .reduce((highest, row) => Math.max(highest, row.slots), 0);
}

export function calculate({ lines, sessions, players, paymentRate, price }) {
    const context = { sessions, players };
    const costed = lines.map((line) => ({ ...line, total: money(lineCost(line, context)) }));

    const isPerPlayer = (line) => line.unit === 'per_player';
    const fixed = lines.filter((line) => !isPerPlayer(line)).reduce((sum, line) => sum + lineCost(line, context), 0);
    const perPlayer = lines.filter(isPerPlayer).reduce((sum, line) => sum + number(line.amount) * number(line.quantity), 0);

    const costs = money(fixed + perPlayer * players);
    const income = money((players * price * paymentRate) / 100);
    const margin = money(income - costs);
    const hasCosts = fixed > 0 || perPlayer > 0;

    // In percent so 85% stays exact: 20 × 0.85 is not quite 17 in floats.
    const netPrice = (price * paymentRate) / 100;

    const breakEvenPrice = hasCosts && players > 0 && paymentRate > 0
        ? roundUp((costs * 100) / (players * paymentRate))
        : null;

    const breakEvenPlayers = hasCosts && netPrice > perPlayer
        ? roundUp(fixed / (netPrice - perPlayer))
        : null;

    let status = null;
    if (hasCosts) {
        status = margin > 0 ? 'win' : margin < 0 ? 'lose' : 'even';
    }

    return {
        sessions,
        lines: costed,
        income,
        costs,
        margin,
        marginPerPlayer: players > 0 ? money(margin / players) : null,
        breakEvenPrice,
        breakEvenPlayers,
        status,
        hasCosts,
        players,
        price,
        paymentRate,
    };
}

export function forecast({ tarification, roster, schedule, subscription }) {
    const exceptional = subscription.kind === 'exceptional';
    const auto = autoPlayers(roster, subscription);
    const autoSessionsPerWeek = defaultSessionsPerWeek(schedule, subscription);

    const players = orDefault(tarification.expected_players, auto);
    const sessionsPerWeek = orDefault(tarification.sessions_per_week, autoSessionsPerWeek);
    const weeks = orDefault(tarification.weeks, DEFAULT_WEEKS);

    return {
        ...calculate({
            lines: tarification.lines ?? [],
            sessions: exceptional ? 0 : sessionsPerWeek * weeks,
            players,
            paymentRate: orDefault(tarification.expected_payment_rate, DEFAULT_PAYMENT_RATE),
            price: averagePrice(roster, subscription),
        }),
        autoPlayers: auto,
        autoSessionsPerWeek,
        sessionsPerWeek,
        weeks,
    };
}

// The saved subscription, as the Show page receives it, in the form's shape.
export function subscriptionInput(subscription) {
    const categories = subscription.categories ?? [];

    return {
        kind: subscription.kind,
        amount_student: subscription.amount_student,
        amount_worker: subscription.amount_worker,
        category_ids: categories.map((category) => category.id),
        branch_ids: (subscription.branches ?? []).map((branch) => branch.id),
        category_prices: Object.fromEntries(categories.map((category) => [category.id, {
            amount_student: category.pivot?.amount_student ?? null,
            amount_worker: category.pivot?.amount_worker ?? null,
        }])),
    };
}
```

- [ ] **Step 5: Run tests, confirm they pass**

Run: `node --test tests/js/tarification.test.mjs`
Expected: all pass. A failing arithmetic assertion means the module is wrong; the expected values come from the spec's worked example.

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/tarification.js tests/js/tarification.test.mjs
git commit -m "feat(tarification): forecast calculation"
```

---

### Task 2: Tables, models, saving and validation

**Files:**
- Create: `database/migrations/2026_11_01_300001_create_subscription_tarification_tables.php`
- Create: `app/Models/SubscriptionTarification.php`, `app/Models/SubscriptionCostLine.php`
- Create: `app/Services/Finance/SaveSubscriptionTarification.php`
- Modify: `app/Models/Subscription.php`, `app/Http/Requests/Subscription/StoreSubscriptionRequest.php`, `app/Http/Controllers/SubscriptionController.php` (`store`, `update`), `app/Services/Finance/FinanceResetService.php`
- Test: `tests/Feature/SubscriptionTarificationTest.php`, `tests/Feature/FinanceResetTest.php`

**Interfaces:**
- Produces: `Subscription::tarification(): HasOne`, `Subscription::costLines(): HasMany` (ordered by `sort_order`), `SubscriptionCostLine::UNITS`, `SubscriptionTarification::DEFAULT_WEEKS`, `DEFAULT_PAYMENT_RATE`, `SaveSubscriptionTarification::save(Subscription $subscription, array $data): void`.
- Request field: `tarification` `{sessions_per_week, weeks, expected_players, expected_payment_rate, lines: [{label, unit, amount, quantity}]}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SubscriptionTarificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionCostLine;
use App\Models\SubscriptionTarification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The costs a subscription's price must cover: saved with it, and private to Finance → view. */
class SubscriptionTarificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** A user whose role grants every module except finance. */
    private function userWithoutFinance(): User
    {
        $permissions = collect(Role::MODULES)
            ->reject(fn (string $module): bool => $module === 'finance')
            ->mapWithKeys(fn (string $module): array => [$module => Role::ACTIONS])
            ->all();

        $role = Role::create(['key' => 'no_finance', 'name' => ['en' => 'No finance'], 'permissions' => $permissions]);

        return User::factory()->create([
            'privileges' => [],
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create([
            'name' => 'Annual', 'kind' => 'annual', 'year' => Subscription::currentSeasonEndYear(),
            'amount_student' => 15000, 'amount_worker' => 15000,
            'is_mandatory' => true, 'is_active' => true,
            ...$attributes,
        ]);
    }

    private function payload(array $over = []): array
    {
        return [
            'kind' => 'annual', 'name' => 'Annual', 'year' => Subscription::currentSeasonEndYear(),
            'amount_student' => 15000, 'amount_worker' => 15000, 'is_mandatory' => true,
            'category_ids' => [], 'category_prices' => [], 'branch_ids' => [],
            ...$over,
        ];
    }

    private function tarification(array $over = []): array
    {
        return [
            'sessions_per_week' => 3, 'weeks' => 40, 'expected_players' => 20, 'expected_payment_rate' => 85,
            'lines' => [
                ['label' => 'Head coach', 'unit' => 'per_session', 'amount' => 800, 'quantity' => 1],
                ['label' => 'Assistants', 'unit' => 'per_session', 'amount' => 700, 'quantity' => 2],
                ['label' => 'Bus', 'unit' => 'per_trip', 'amount' => 15000, 'quantity' => 10],
            ],
            ...$over,
        ];
    }

    #[Test]
    public function creating_a_subscription_saves_its_costs_in_order(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification()]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $subscription = Subscription::firstOrFail();

        $this->assertSame(85, $subscription->tarification->expected_payment_rate);
        $this->assertSame(3, $subscription->tarification->sessions_per_week);
        $this->assertSame(['Head coach', 'Assistants', 'Bus'], $subscription->costLines->pluck('label')->all());
    }

    #[Test]
    public function updating_replaces_the_cost_lines(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification([
            'lines' => [['label' => 'Bus', 'unit' => 'per_trip', 'amount' => 9000, 'quantity' => 4]],
        ])]))->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame(1, $subscription->costLines()->count());
        $this->assertSame(1, SubscriptionTarification::where('subscription_id', $subscription->id)->count());
        $this->assertEquals(9000, $subscription->costLines->first()->amount);
    }

    #[Test]
    public function an_untouched_section_writes_no_rows(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), $this->payload(['tarification' => [
            'sessions_per_week' => null, 'weeks' => 40, 'expected_players' => null, 'expected_payment_rate' => 100, 'lines' => [],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame(0, SubscriptionTarification::count());
    }

    #[Test]
    public function blank_weeks_and_rate_take_the_defaults(): void
    {
        $this->actingAs($this->admin())->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification([
            'weeks' => null, 'expected_payment_rate' => null,
        ])]))->assertSessionHasNoErrors();

        $saved = SubscriptionTarification::firstOrFail();
        $this->assertSame(40, $saved->weeks);
        $this->assertSame(100, $saved->expected_payment_rate);
    }

    #[Test]
    public function deleting_the_subscription_deletes_its_costs(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->delete(route('subscriptions.destroy', $subscription));

        $this->assertSame(0, SubscriptionTarification::count());
        $this->assertSame(0, SubscriptionCostLine::count());
    }

    #[Test]
    public function bad_values_are_refused(): void
    {
        $admin = $this->admin();
        $line = ['label' => 'Coach', 'unit' => 'per_trip', 'amount' => 800, 'quantity' => 1];

        foreach ([
            'tarification.lines.0.amount' => [['amount' => -1] + $line],
            'tarification.lines.0.label' => [['label' => ''] + $line],
            'tarification.lines.0.unit' => [['unit' => 'per_year'] + $line],
            'tarification.lines.0.quantity' => [['quantity' => 0] + $line],
        ] as $field => $lines) {
            $this->actingAs($admin)
                ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification(['lines' => $lines])]))
                ->assertSessionHasErrors($field);
        }

        $this->actingAs($admin)
            ->post(route('subscriptions.store'), $this->payload(['tarification' => $this->tarification(['expected_payment_rate' => 101])]))
            ->assertSessionHasErrors('tarification.expected_payment_rate');
    }

    #[Test]
    public function an_exceptional_subscription_refuses_per_session_costs(): void
    {
        $this->actingAs($this->admin())
            ->post(route('subscriptions.store'), $this->payload([
                'kind' => 'exceptional', 'year' => null,
                'tarification' => $this->tarification(['lines' => [
                    ['label' => 'Coach', 'unit' => 'per_session', 'amount' => 800, 'quantity' => 1],
                ]]),
            ]))
            ->assertSessionHasErrors('tarification.lines.0.unit');
    }

    #[Test]
    public function saving_without_finance_rights_leaves_the_costs_alone(): void
    {
        $subscription = $this->subscription();
        $this->actingAs($this->admin())->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($this->userWithoutFinance())
            ->put(route('subscriptions.update', $subscription), $this->payload(['amount_student' => 16000, 'tarification' => [
                'weeks' => 1, 'lines' => [],
            ]]))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertEquals(16000, $subscription->amount_student);
        $this->assertSame(40, $subscription->tarification->weeks);
        $this->assertSame(3, $subscription->costLines()->count());
    }
}
```

In `tests/Feature/FinanceResetTest.php`, inside `seedFinance()` right after the `player_subscriptions` insert, add:

```php
        DB::table('subscription_tarifications')->insert(['subscription_id' => $sub, 'weeks' => 40, 'expected_payment_rate' => 100]);
        DB::table('subscription_cost_lines')->insert([
            'subscription_id' => $sub, 'label' => 'Coach', 'unit' => 'per_session', 'amount' => 800, 'quantity' => 1, 'sort_order' => 0,
        ]);
```

and extend the table list in `assertWiped()`:

```php
        foreach (['subscriptions', 'subscription_tarifications', 'subscription_cost_lines', 'player_subscriptions', 'transactions', 'branch_subscription', 'finance_transfers', 'budgets'] as $table) {
```

- [ ] **Step 2: Run tests, confirm they fail**

Run: `php artisan test --filter="SubscriptionTarificationTest|FinanceResetTest"`
Expected: FAIL, `Class "App\Models\SubscriptionCostLine" not found` and missing tables.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_11_01_300001_create_subscription_tarification_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_tarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sessions_per_week')->nullable(); // Blank = the schedule's count.
            $table->unsignedTinyInteger('weeks')->default(40);
            $table->unsignedInteger('expected_players')->nullable(); // Blank = the auto count.
            $table->unsignedTinyInteger('expected_payment_rate')->default(100); // Percent.
            $table->timestamps();
        });

        Schema::create('subscription_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('unit', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_cost_lines');
        Schema::dropIfExists('subscription_tarifications');
    }
};
```

- [ ] **Step 4: Write the models and relations**

Create `app/Models/SubscriptionTarification.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The forecast settings of a subscription (see resources/js/lib/tarification.js).
 * Apart from `subscriptions` so coach pay is never serialised by accident.
 */
class SubscriptionTarification extends Model
{
    use SerializesClubDates;

    public const DEFAULT_WEEKS = 40;

    public const DEFAULT_PAYMENT_RATE = 100;

    protected $fillable = ['subscription_id', 'sessions_per_week', 'weeks', 'expected_players', 'expected_payment_rate'];

    protected function casts(): array
    {
        return [
            'sessions_per_week' => 'integer',
            'weeks' => 'integer',
            'expected_players' => 'integer',
            'expected_payment_rate' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
```

Create `app/Models/SubscriptionCostLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One forecast cost of a subscription: coach pay, a bus. Never a real expense. */
class SubscriptionCostLine extends Model
{
    use SerializesClubDates;

    public const UNIT_PER_SESSION = 'per_session';

    public const UNITS = [self::UNIT_PER_SESSION, 'per_trip', 'per_month', 'per_season', 'per_player'];

    protected $fillable = ['subscription_id', 'label', 'unit', 'amount', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
```

In `app/Models/Subscription.php` add `use Illuminate\Database\Eloquent\Relations\HasOne;` to the imports, and after `playerSubscriptions()`:

```php
    /** Forecast settings; coach pay stays out of every prop unless loaded on purpose. */
    public function tarification(): HasOne
    {
        return $this->hasOne(SubscriptionTarification::class);
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(SubscriptionCostLine::class)->orderBy('sort_order');
    }
```

- [ ] **Step 5: Write the save service**

Create `app/Services/Finance/SaveSubscriptionTarification.php`:

```php
<?php

namespace App\Services\Finance;

use App\Models\Subscription;
use App\Models\SubscriptionTarification;

/** Saves a subscription's forecast: its settings, and its cost lines in the order typed. */
class SaveSubscriptionTarification
{
    /** @param  array<string, mixed>  $data  the validated `tarification` block */
    public function save(Subscription $subscription, array $data): void
    {
        $settings = [
            'sessions_per_week' => $data['sessions_per_week'] ?? null,
            'weeks' => $data['weeks'] ?? SubscriptionTarification::DEFAULT_WEEKS,
            'expected_players' => $data['expected_players'] ?? null,
            'expected_payment_rate' => $data['expected_payment_rate'] ?? SubscriptionTarification::DEFAULT_PAYMENT_RATE,
        ];
        $lines = $data['lines'] ?? [];

        if ($lines === [] && $this->isUntouched($settings) && ! $subscription->tarification()->exists()) {
            return;
        }

        $subscription->tarification()->updateOrCreate([], $settings);

        $subscription->costLines()->reorder()->delete();
        $subscription->costLines()->createMany(
            collect($lines)->values()->map(fn (array $line, int $position): array => [
                'label' => $line['label'],
                'unit' => $line['unit'],
                'amount' => $line['amount'],
                'quantity' => $line['quantity'],
                'sort_order' => $position,
            ])->all(),
        );
    }

    /** @param  array<string, mixed>  $settings */
    private function isUntouched(array $settings): bool
    {
        return $settings['sessions_per_week'] === null
            && $settings['expected_players'] === null
            && (int) $settings['weeks'] === SubscriptionTarification::DEFAULT_WEEKS
            && (int) $settings['expected_payment_rate'] === SubscriptionTarification::DEFAULT_PAYMENT_RATE;
    }
}
```

- [ ] **Step 6: Add the rules**

In `app/Http/Requests/Subscription/StoreSubscriptionRequest.php`, add `use App\Models\SubscriptionCostLine;` to the imports. Change the closing of `rules()` so the array is merged:

```php
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            ...$this->tarificationRules($exceptional),
        ];
    }

    /**
     * Only for Finance → view: without these rules the block is not in validated(),
     * so a save by anyone else leaves the costs as they were.
     *
     * @return array<string, mixed>
     */
    private function tarificationRules(bool $exceptional): array
    {
        if (! $this->user()?->hasPermission('finance', 'view')) {
            return [];
        }

        $units = $exceptional
            ? array_values(array_diff(SubscriptionCostLine::UNITS, [SubscriptionCostLine::UNIT_PER_SESSION]))
            : SubscriptionCostLine::UNITS;

        return [
            'tarification' => ['nullable', 'array'],
            'tarification.sessions_per_week' => ['nullable', 'integer', 'min:0', 'max:21'],
            'tarification.weeks' => ['nullable', 'integer', 'min:0', 'max:53'],
            'tarification.expected_players' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'tarification.expected_payment_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tarification.lines' => ['nullable', 'array', 'max:100'],
            'tarification.lines.*.label' => ['required', 'string', 'max:100'],
            'tarification.lines.*.unit' => ['required', Rule::in($units)],
            'tarification.lines.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'tarification.lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
```

Change the `except` in `subscriptionAttributes()`:

```php
            ->except(['category_ids', 'category_prices', 'branch_ids', 'tarification'])
```

- [ ] **Step 7: Save from the controller, and reset**

In `app/Http/Controllers/SubscriptionController.php`, add `use App\Services\Finance\SaveSubscriptionTarification;` to the imports. In `store()`, inside the transaction after `branches()->attach`:

```php
            $subscription->branches()->attach($branchIds);
            $this->saveTarification($request, $subscription);
```

In `update()`, inside the transaction after `branches()->sync`:

```php
            $subscription->branches()->sync($request->validated('branch_ids') ?? []);
            $this->saveTarification($request, $subscription);
```

Add after `update()`:

```php
    private function saveTarification(StoreSubscriptionRequest $request, Subscription $subscription): void
    {
        if (is_array($data = $request->validated('tarification'))) {
            app(SaveSubscriptionTarification::class)->save($subscription, $data);
        }
    }
```

In `app/Services/Finance/FinanceResetService.php`, right before `DB::table('branch_subscription')->delete();`:

```php
            DB::table('subscription_cost_lines')->delete();
            DB::table('subscription_tarifications')->delete();
```

- [ ] **Step 8: Run tests, confirm they pass**

Run: `php artisan test --filter="SubscriptionTarificationTest|FinanceResetTest|ModelsSerializeClubDatesTest|SubscriptionKindsTest"`
Expected: all pass.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_11_01_300001_create_subscription_tarification_tables.php app/Models/SubscriptionTarification.php app/Models/SubscriptionCostLine.php app/Models/Subscription.php app/Services/Finance/SaveSubscriptionTarification.php app/Services/Finance/FinanceResetService.php app/Http/Requests/Subscription/StoreSubscriptionRequest.php app/Http/Controllers/SubscriptionController.php tests/Feature/SubscriptionTarificationTest.php tests/Feature/FinanceResetTest.php
git commit -m "feat(tarification): save a subscription's costs behind Finance view"
```

---

### Task 3: The page prop

**Files:**
- Create: `app/Services/Finance/TarificationData.php`
- Modify: `app/Http/Controllers/SubscriptionController.php` (`create`, `edit`, `show`)
- Test: `tests/Feature/SubscriptionTarificationTest.php`

**Interfaces:**
- Consumes: `Subscription::tarification()`, `costLines()`, `SubscriptionTarification::DEFAULT_*` (Task 2). Roster and schedule shapes (Task 1).
- Produces: `TarificationData::for(?Subscription $subscription): array` returning `{roster, schedule, saved, collected, realRate}`. `saved` is `{sessions_per_week, weeks, expected_players, expected_payment_rate, lines}`. `collected` is a float (0 for a new subscription). `realRate` is a float percent or `null` when nothing is owed. Inertia prop name: `tarification`, absent without Finance → view.

- [ ] **Step 1: Write the failing tests**

Add to the imports of `tests/Feature/SubscriptionTarificationTest.php`:

```php
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\TrainingSchedule;
use Inertia\Testing\AssertableInertia;
```

Add to the class:

```php
    private int $seq = 0;

    private function player(array $attributes = []): Player
    {
        return Player::create([
            'membership_id' => '84'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Ali', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
            ...$attributes,
        ]);
    }

    #[Test]
    public function coach_pay_never_reaches_a_user_without_finance_rights(): void
    {
        $subscription = $this->subscription();
        $this->actingAs($this->admin())->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $user = $this->userWithoutFinance();

        foreach ([route('subscriptions.create'), route('subscriptions.edit', $subscription), route('subscriptions.show', $subscription)] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->missing('tarification'))
                ->assertDontSee('Head coach');
        }
    }

    #[Test]
    public function the_subscription_prop_itself_never_carries_the_costs(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('subscription.tarification')
                ->missing('subscription.cost_lines'));
    }

    #[Test]
    public function the_prop_carries_saved_values_for_finance_users(): void
    {
        $subscription = $this->subscription();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('subscriptions.update', $subscription), $this->payload(['tarification' => $this->tarification()]));

        $this->actingAs($admin)->get(route('subscriptions.edit', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.saved.sessions_per_week', 3)
                ->where('tarification.saved.expected_payment_rate', 85)
                ->where('tarification.saved.lines.0.label', 'Head coach')
                ->where('tarification.saved.lines.2.unit', 'per_trip'));
    }

    #[Test]
    public function a_new_subscription_gets_defaults_and_the_roster(): void
    {
        $this->player();

        $this->actingAs($this->admin())->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.saved.weeks', 40)
                ->where('tarification.saved.expected_payment_rate', 100)
                ->where('tarification.saved.lines', [])
                ->where('tarification.collected', 0.0)
                ->where('tarification.realRate', null)
                ->has('tarification.roster', 1));
    }

    #[Test]
    public function the_roster_groups_players_once_and_leaves_out_archived_and_exempt(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $football = Branch::create(['name' => 'Football']);
        $swimming = Branch::create(['name' => 'Swimming']);
        $subscription = $this->subscription();

        $twoBranches = [$this->player(['category_id' => $category->id]), $this->player(['category_id' => $category->id])];
        foreach ($twoBranches as $player) {
            $player->branches()->attach([$swimming->id, $football->id]);
        }
        $this->player(['category_id' => $category->id, 'is_student' => false]);
        $this->player(['archived' => true]);

        $exempt = $this->player(['category_id' => $category->id]);
        PlayerSubscription::create([
            'player_id' => $exempt->id, 'subscription_id' => $subscription->id, 'year' => $subscription->year,
            'status_at_time' => 'student', 'is_mandatory' => true, 'amount_owed' => 15000, 'amount_paid' => 0, 'is_exempt' => true,
        ]);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tarification.roster', 2)
                ->where('tarification.roster', function ($roster) use ($category, $football, $swimming) {
                    $groups = collect($roster)->keyBy('is_student');

                    return $groups[1]['count'] === 2
                        && $groups[1]['category_id'] === $category->id
                        && $groups[1]['branch_ids'] === collect([$football->id, $swimming->id])->sort()->values()->all()
                        && $groups[0]['count'] === 1
                        && $groups[0]['branch_ids'] === [];
                }));
    }

    #[Test]
    public function the_schedule_counts_slots_still_valid(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $slot = ['category_id' => $category->id, 'start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2020-01-01'];
        TrainingSchedule::create(['weekday' => 1, ...$slot]);
        TrainingSchedule::create(['weekday' => 3, 'valid_to' => now()->addYear()->toDateString(), ...$slot]);
        TrainingSchedule::create(['weekday' => 5, 'valid_to' => '2021-01-01', ...$slot]);

        $this->actingAs($this->admin())->get(route('subscriptions.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tarification.schedule', 1)
                ->where('tarification.schedule.0.slots', 2)
                ->where('tarification.schedule.0.category_id', $category->id)
                ->where('tarification.schedule.0.branch_id', null));
    }

    #[Test]
    public function the_real_rate_is_collected_over_the_net_owed_by_non_exempt_players(): void
    {
        $subscription = $this->subscription();
        $row = ['subscription_id' => $subscription->id, 'year' => $subscription->year, 'status_at_time' => 'student', 'is_mandatory' => true];

        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 5000, ...$row]);
        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 0, 'discount_type' => 'amount', 'discount_value' => 5000, ...$row]);
        PlayerSubscription::create(['player_id' => $this->player()->id, 'amount_owed' => 10000, 'amount_paid' => 0, 'is_exempt' => true, ...$row]);

        $this->actingAs($this->admin())->get(route('subscriptions.show', $subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tarification.collected', 5000.0)
                ->where('tarification.realRate', 33.3));
    }
```

- [ ] **Step 2: Run tests, confirm they fail**

Run: `php artisan test --filter=SubscriptionTarificationTest`
Expected: the new tests fail on the missing `tarification` prop; `coach_pay_never_reaches...` and `the_subscription_prop_itself...` may already pass.

- [ ] **Step 3: Write the data service**

Create `app/Services/Finance/TarificationData.php`:

```php
<?php

namespace App\Services\Finance;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionTarification;
use App\Models\TrainingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The inputs of a subscription's forecast. Results are computed in the browser
 * (resources/js/lib/tarification.js), so they follow unsaved edits in the form.
 */
class TarificationData
{
    /** @return array{roster: list<array<string, mixed>>, schedule: list<array<string, mixed>>, saved: array<string, mixed>, collected: float, realRate: float|null} */
    public function for(?Subscription $subscription): array
    {
        return [
            'roster' => $this->roster($subscription),
            'schedule' => $this->schedule(),
            'saved' => $this->saved($subscription),
            ...$this->paid($subscription),
        ];
    }

    /**
     * Active players grouped by category, status and exact set of branches: a
     * small prop however big the club, and a player in two branches counts once.
     *
     * @return list<array{category_id: int|null, is_student: bool, branch_ids: list<int>, count: int}>
     */
    private function roster(?Subscription $subscription): array
    {
        $exempt = $subscription === null
            ? collect()
            : PlayerSubscription::query()
                ->where('subscription_id', $subscription->id)
                ->where('is_exempt', true)
                ->pluck('player_id');

        $branches = DB::table('branch_player')->orderBy('branch_id')->get(['player_id', 'branch_id'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows): array => $rows->pluck('branch_id')->map(fn ($id) => (int) $id)->all());

        return Player::query()
            ->where('archived', false)
            ->whereNotIn('id', $exempt)
            ->toBase()
            ->get(['id', 'category_id', 'is_student'])
            ->groupBy(fn ($player): string => implode('|', [
                $player->category_id ?? '',
                (int) $player->is_student,
                implode(',', $branches[$player->id] ?? []),
            ]))
            ->map(fn (Collection $players): array => [
                'category_id' => $players->first()->category_id === null ? null : (int) $players->first()->category_id,
                'is_student' => (bool) $players->first()->is_student,
                'branch_ids' => $branches[$players->first()->id] ?? [],
                'count' => $players->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Weekly slots still valid, per branch and category.
     *
     * @return list<array{branch_id: int|null, category_id: int, slots: int}>
     */
    private function schedule(): array
    {
        return TrainingSchedule::query()
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))
            ->toBase()
            ->selectRaw('branch_id, category_id, count(*) as slots')
            ->groupBy('branch_id', 'category_id')
            ->get()
            ->map(fn ($row): array => [
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'category_id' => (int) $row->category_id,
                'slots' => (int) $row->slots,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function saved(?Subscription $subscription): array
    {
        // Queried, not read as relations: a loaded relation would be serialised
        // into the page's `subscription` prop, for every viewer of that prop.
        $settings = $subscription?->tarification()->first();

        return [
            'sessions_per_week' => $settings?->sessions_per_week,
            'weeks' => $settings?->weeks ?? SubscriptionTarification::DEFAULT_WEEKS,
            'expected_players' => $settings?->expected_players,
            'expected_payment_rate' => $settings?->expected_payment_rate ?? SubscriptionTarification::DEFAULT_PAYMENT_RATE,
            'lines' => $subscription === null ? [] : $subscription->costLines()->get()
                ->map(fn ($line): array => [
                    'label' => $line->label,
                    'unit' => $line->unit,
                    'amount' => $line->amount,
                    'quantity' => $line->quantity,
                ])
                ->all(),
        ];
    }

    /**
     * Collected counts every payment, as the page's own tile does. The rate is
     * over what non-exempt players owe after discounts.
     *
     * @return array{collected: float, realRate: float|null}
     */
    private function paid(?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['collected' => 0.0, 'realRate' => null];
        }

        $collected = 0.0;
        $paid = 0.0;
        $owed = 0.0;

        $rows = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->toBase()
            ->get(['amount_owed', 'amount_paid', 'is_exempt', 'discount_type', 'discount_value']);

        foreach ($rows as $row) {
            $exempt = (bool) $row->is_exempt;
            $money = PlayerSubscription::moneyFor($row->amount_owed, $row->amount_paid, $exempt, $row->discount_type, $row->discount_value);
            $collected += $money['paid'];

            if (! $exempt) {
                $paid += $money['paid'];
                $owed += $money['net_owed'];
            }
        }

        return [
            'collected' => round($collected, 2),
            'realRate' => $owed > 0 ? min(100.0, round($paid / $owed * 100, 1)) : null,
        ];
    }
}
```

Note the expected rate in the test: 5,000 paid over 15,000 net owed (10,000 + 5,000) is 33.3.

- [ ] **Step 4: Pass the prop from the controller**

In `app/Http/Controllers/SubscriptionController.php` add `use App\Services\Finance\TarificationData;` to the imports and this method after `saveTarification`:

```php
    /**
     * Coach pay: without Finance → view the prop does not exist at all.
     *
     * @return array<string, mixed>
     */
    private function tarificationProp(Request $request, ?Subscription $subscription = null): array
    {
        return $request->user()?->hasPermission('finance', 'view')
            ? ['tarification' => fn (): array => app(TarificationData::class)->for($subscription)]
            : [];
    }
```

Change the three actions:

```php
    public function show(Request $request, Subscription $subscription): Response
```
and in its `Inertia::render` array, after the `'stats' => [...]` entry:
```php
            ...$this->tarificationProp($request, $subscription),
```

```php
    public function create(Request $request): Response
    {
        return Inertia::render('Subscriptions/Create', [
            'seasons' => $this->seasonOptions(),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            ...$this->tarificationProp($request),
        ]);
    }
```

```php
    public function edit(Request $request, Subscription $subscription): Response
    {
        $subscription->load(['categories', 'branches']);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'seasons' => $this->seasonOptions([$subscription->year]),
            'currentSeason' => Subscription::currentSeasonEndYear(),
            'categories' => Category::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            ...$this->tarificationProp($request, $subscription),
        ]);
    }
```

`the_subscription_prop_itself_never_carries_the_costs` guards the relation leak noted in `saved()`.

- [ ] **Step 5: Run tests, confirm they pass**

Run: `php artisan test --filter="SubscriptionTarificationTest|SubscriptionShowPayloadTest|SubscriptionKindsTest"`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/TarificationData.php app/Http/Controllers/SubscriptionController.php tests/Feature/SubscriptionTarificationTest.php
git commit -m "feat(tarification): send the forecast inputs to Finance users only"
```

---

### Task 4: Translations, form section and subscription page

**Files:**
- Create: `resources/js/Pages/Subscriptions/Partials/TarificationResult.vue`, `resources/js/Pages/Subscriptions/Partials/TarificationCard.vue`
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`
- Modify: `resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue`, `Create.vue`, `Edit.vue`, `Show.vue`

**Interfaces:**
- Consumes: `forecast`, `subscriptionInput`, `UNITS` (Task 1). The `tarification` prop (Task 3). `useCan().can(module, action)`, `useCurrency`, `formatNumber`.
- Produces: `TarificationResult` props `{result: Object, collected: Number|null}`. `TarificationCard` props `{form: Object, data: Object}`, where `form.tarification` holds the inputs.

- [ ] **Step 1: Add translations**

Run this once from `D:\irnb-tarification`. It inserts lines above `"task":` and touches nothing else:

```bash
node - <<'EOF'
const fs = require('fs');
const keys = {
  'tarification.add_line': ['Add a cost', 'Ajouter un coût', 'إضافة تكلفة'],
  'tarification.amount': ['Amount', 'Montant', 'المبلغ'],
  'tarification.average_price': ['Average across players, students and workers', 'Moyenne par joueur, étudiants et salariés', 'متوسط لكل لاعب، طلبة وعمال'],
  'tarification.break_even_players': ['Players needed to break even', "Joueurs nécessaires pour l'équilibre", 'عدد اللاعبين اللازم للتوازن'],
  'tarification.break_even_price': ['Price needed to break even', "Prix nécessaire pour l'équilibre", 'السعر اللازم للتوازن'],
  'tarification.costs': ['Costs', 'Coûts', 'التكاليف'],
  'tarification.edit_costs': ['Edit costs', 'Modifier les coûts', 'تعديل التكاليف'],
  'tarification.empty': ['Add the coach, staff and transport costs to see if this price is enough.', "Ajoutez les coûts de l'entraîneur, du staff et du transport pour savoir si ce prix suffit.", 'أضف تكاليف المدرب والطاقم والنقل لمعرفة هل هذا السعر كاف.'],
  'tarification.expected_players': ['Expected players', 'Joueurs prévus', 'عدد اللاعبين المتوقع'],
  'tarification.hint': ['A forecast: nothing here is recorded as an expense.', "Une prévision : rien ici n'est enregistré comme dépense.", 'هذا تقدير فقط: لا يسجل أي شيء هنا كمصروف.'],
  'tarification.income': ['Expected income', 'Recettes prévues', 'المداخيل المتوقعة'],
  'tarification.income_detail': ['{players} players × {price} × {rate}%', '{players} joueurs × {price} × {rate}%', '{players} لاعب × {price} × {rate}%'],
  'tarification.line_label': ['Name', 'Nom', 'الاسم'],
  'tarification.line_placeholder': ['Head coach, assistant, bus…', 'Entraîneur, adjoint, bus…', 'مدرب، مساعد، حافلة…'],
  'tarification.line_total': ['Total', 'Total', 'المجموع'],
  'tarification.matching_players': ['Blank = the {n} matching players', 'Vide = les {n} joueurs concernés', 'فارغ = {n} لاعب معني'],
  'tarification.no_break_even': ['At this price, no number of players can cover the costs.', 'À ce prix, aucun nombre de joueurs ne couvre les coûts.', 'بهذا السعر، لا يغطي أي عدد من اللاعبين التكاليف.'],
  'tarification.no_payments': ['No payments yet', 'Aucun paiement pour le moment', 'لا توجد مدفوعات بعد'],
  'tarification.no_schedule': ['No training schedule for this selection: enter the sessions per week.', "Aucun planning pour cette sélection : saisissez les séances par semaine.", 'لا يوجد برنامج تدريب لهذا الاختيار: أدخل عدد الحصص في الأسبوع.'],
  'tarification.paid_so_far': ['Paid so far', 'Payé à ce jour', 'المدفوع حتى الآن'],
  'tarification.payment_rate': ['Expected payment %', 'Paiement prévu %', 'نسبة الدفع المتوقعة %'],
  'tarification.per_player': ['{amount} per player', '{amount} par joueur', '{amount} لكل لاعب'],
  'tarification.quantity.per_month': ['Months', 'Mois', 'الأشهر'],
  'tarification.quantity.per_season': ['Count', 'Nombre', 'العدد'],
  'tarification.quantity.per_session': ['People', 'Personnes', 'الأشخاص'],
  'tarification.quantity.per_trip': ['Trips', 'Déplacements', 'التنقلات'],
  'tarification.real_rate': ['Real rate so far: {rate}%', 'Taux réel à ce jour : {rate}%', 'النسبة الفعلية حتى الآن: {rate}%'],
  'tarification.remove_line': ['Remove this cost', 'Retirer ce coût', 'حذف هذه التكلفة'],
  'tarification.schedule_sessions': ['Blank = {n} from the training schedule', 'Vide = {n} selon le planning', 'فارغ = {n} حسب برنامج التدريب'],
  'tarification.sessions_per_week': ['Sessions per week', 'Séances par semaine', 'الحصص في الأسبوع'],
  'tarification.sessions_total': ['{n} sessions in the season', '{n} séances dans la saison', '{n} حصة في الموسم'],
  'tarification.status.even': ['Break even', 'Équilibre', 'توازن'],
  'tarification.status.lose': ['Loss', 'Perte', 'خسارة'],
  'tarification.status.win': ['Profit', 'Gain', 'ربح'],
  'tarification.title': ['Pricing', 'Tarification', 'التسعيرة'],
  'tarification.total_costs': ['Total costs', 'Total des coûts', 'مجموع التكاليف'],
  'tarification.unit.per_month': ['Per month', 'Par mois', 'في الشهر'],
  'tarification.unit.per_player': ['Per player', 'Par joueur', 'لكل لاعب'],
  'tarification.unit.per_season': ['Per season', 'Par saison', 'في الموسم'],
  'tarification.unit.per_session': ['Per session', 'Par séance', 'في الحصة'],
  'tarification.unit.per_trip': ['Per trip', 'Par déplacement', 'لكل تنقل'],
  'tarification.unit_label': ['Unit', 'Unité', 'الوحدة'],
  'tarification.weeks': ['Weeks per season', 'Semaines par saison', 'الأسابيع في الموسم'],
};
['en', 'fr', 'ar'].forEach((locale, index) => {
  const file = `resources/js/i18n/${locale}.json`;
  const text = fs.readFileSync(file, 'utf8');
  const anchor = '    "task":';
  if (text.split(anchor).length !== 2) throw new Error(`anchor not unique in ${file}`);
  const lines = Object.entries(keys).map(([key, values]) => `    ${JSON.stringify(key)}: ${JSON.stringify(values[index])},\n`).join('');
  fs.writeFileSync(file, text.replace(anchor, lines + anchor));
  JSON.parse(fs.readFileSync(file, 'utf8'));
});
EOF
```

Check: `git diff --stat -- resources/js/i18n` shows 43 insertions per file and 0 deletions, and `npm run i18n:check` passes.

- [ ] **Step 2: Write the result panel**

Create `resources/js/Pages/Subscriptions/Partials/TarificationResult.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { formatNumber } from '@/lib/numberFormat';

const props = defineProps({
    result: { type: Object, required: true },
    collected: { type: Number, default: null },
    mixedPrices: { type: Boolean, default: false },
});

const { t } = useI18n();
const { currencyLabel } = useCurrency();
const page = usePage();

const number = (value) => formatNumber(value, page.props.locale || 'fr');
const money = (value) => `${number(value)} ${currencyLabel.value}`;

const lineDetail = (line) => {
    const parts = [number(line.amount), number(line.quantity)];
    if (line.unit === 'per_session') parts.push(number(props.result.sessions));
    if (line.unit === 'per_player') parts.push(number(props.result.players));

    return parts.join(' × ');
};

const statusClass = computed(() => ({
    win: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-800',
    lose: 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-800',
    even: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-800',
}[props.result.status]));
</script>

<template>
    <div class="space-y-4">
        <p v-if="!result.hasCosts" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('tarification.empty') }}</p>

        <template v-else>
            <div class="rounded-xl p-4 ring-1" :class="statusClass">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-sm font-semibold">{{ t(`tarification.status.${result.status}`) }}</span>
                    <span class="text-2xl font-bold" dir="ltr">{{ money(result.margin) }}</span>
                </div>
                <p v-if="result.marginPerPlayer !== null" class="mt-1 text-sm">
                    {{ t('tarification.per_player', { amount: money(result.marginPerPlayer) }) }}
                </p>
            </div>

            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2">
                    <dt>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ t('tarification.income') }}</span>
                        <span class="ms-2 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.income_detail', { players: number(result.players), price: number(result.price), rate: number(result.paymentRate) }) }}</span>
                    </dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-100">{{ money(result.income) }}</dd>
                </div>
                <div v-for="(line, index) in result.lines" :key="index" class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2">
                    <dt>
                        <span class="text-slate-700 dark:text-slate-200">{{ line.label }}</span>
                        <span class="ms-2 text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ lineDetail(line) }}</span>
                    </dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ money(line.total) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2 font-semibold text-slate-900 dark:text-slate-100">
                    <dt>{{ t('tarification.total_costs') }}</dt>
                    <dd>{{ money(result.costs) }}</dd>
                </div>
            </dl>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.break_even_price') }}</p>
                    <p class="mt-1 font-bold text-slate-900 dark:text-slate-100">{{ result.breakEvenPrice === null ? '—' : money(result.breakEvenPrice) }}</p>
                    <p v-if="mixedPrices" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.average_price') }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.break_even_players') }}</p>
                    <p v-if="result.breakEvenPlayers !== null" class="mt-1 font-bold text-slate-900 dark:text-slate-100">{{ number(result.breakEvenPlayers) }}</p>
                    <p v-else class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ t('tarification.no_break_even') }}</p>
                </div>
            </div>
        </template>

        <p v-if="collected !== null" class="flex justify-between gap-4 text-sm text-slate-700 dark:text-slate-200">
            <span>{{ t('tarification.paid_so_far') }}</span>
            <span class="font-semibold">{{ money(collected) }}</span>
        </p>
    </div>
</template>
```

- [ ] **Step 3: Write the form card**

Create `resources/js/Pages/Subscriptions/Partials/TarificationCard.vue`:

```vue
<script setup>
import { computed, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import IconButton from '@/Components/IconButton.vue';
import TarificationResult from './TarificationResult.vue';
import { DEFAULT_PAYMENT_RATE, DEFAULT_WEEKS, UNITS, forecast } from '@/lib/tarification';
import { formatNumber } from '@/lib/numberFormat';

const props = defineProps({
    form: { type: Object, required: true },
    data: { type: Object, required: true },
    exists: { type: Boolean, default: false },
});

const { t } = useI18n();
const page = usePage();

const inputs = computed(() => props.form.tarification);
const isAnnual = computed(() => props.form.kind === 'annual');

// Exceptional charges have no sessions; a per-session line already there keeps
// its option so the request's error can point at it.
const unitsFor = (line) => UNITS.filter((unit) => isAnnual.value || unit !== 'per_session' || line.unit === unit);

const result = computed(() => forecast({
    tarification: inputs.value,
    roster: props.data.roster,
    schedule: props.data.schedule,
    subscription: {
        kind: props.form.kind,
        amount_student: props.form.amount_student,
        amount_worker: props.form.amount_worker,
        category_ids: props.form.category_ids,
        branch_ids: props.form.branch_ids,
        category_prices: props.form.category_prices,
    },
}));

const mixedPrices = computed(() => Number(props.form.amount_student) !== Number(props.form.amount_worker)
    || Object.values(props.form.category_prices).some((prices) => prices.amount_student || prices.amount_worker));

const addLine = () => inputs.value.lines.push({ label: '', unit: isAnnual.value ? 'per_session' : 'per_trip', amount: '', quantity: 1 });
const removeLine = (index) => inputs.value.lines.splice(index, 1);

// The quantity input is hidden per player, and the formula still multiplies by it.
watchEffect(() => {
    for (const line of inputs.value.lines) {
        if (line.unit === 'per_player' && line.quantity !== 1) line.quantity = 1;
    }
});

const lineTotal = (index) => formatNumber(result.value.lines[index]?.total ?? 0, page.props.locale || 'fr');
const errorFor = (index, field) => props.form.errors[`tarification.lines.${index}.${field}`];
</script>

<template>
    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tarification.title') }}</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.hint') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <template v-if="isAnnual">
                <div>
                    <InputLabel :value="t('tarification.sessions_per_week')" />
                    <TextInput v-model="inputs.sessions_per_week" type="number" min="0" max="21" class="mt-1 w-full" :placeholder="String(result.autoSessionsPerWeek)" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ result.autoSessionsPerWeek ? t('tarification.schedule_sessions', { n: result.autoSessionsPerWeek }) : t('tarification.no_schedule') }}
                    </p>
                    <InputError :message="form.errors['tarification.sessions_per_week']" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('tarification.weeks')" />
                    <TextInput v-model="inputs.weeks" type="number" min="0" max="53" class="mt-1 w-full" :placeholder="String(DEFAULT_WEEKS)" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.sessions_total', { n: result.sessions }) }}</p>
                    <InputError :message="form.errors['tarification.weeks']" class="mt-1" />
                </div>
            </template>
            <div>
                <InputLabel :value="t('tarification.expected_players')" />
                <TextInput v-model="inputs.expected_players" type="number" min="0" class="mt-1 w-full" :placeholder="String(result.autoPlayers)" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.matching_players', { n: result.autoPlayers }) }}</p>
                <InputError :message="form.errors['tarification.expected_players']" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('tarification.payment_rate')" />
                <TextInput v-model="inputs.expected_payment_rate" type="number" min="0" max="100" class="mt-1 w-full" :placeholder="String(DEFAULT_PAYMENT_RATE)" />
                <p v-if="exists" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ data.realRate === null || data.realRate === 0 ? t('tarification.no_payments') : t('tarification.real_rate', { rate: data.realRate }) }}
                </p>
                <InputError :message="form.errors['tarification.expected_payment_rate']" class="mt-1" />
            </div>
        </div>

        <div class="mt-6">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('tarification.costs') }}</p>

            <div class="mt-2 space-y-3">
                <div v-for="(line, index) in inputs.lines" :key="index" class="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-800">
                    <div class="grid gap-3 sm:grid-cols-6">
                        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">
                            {{ t('tarification.line_label') }}
                            <TextInput v-model="line.label" class="mt-0.5 w-full py-1.5 text-sm" maxlength="100" :placeholder="t('tarification.line_placeholder')" />
                            <InputError :message="errorFor(index, 'label')" class="mt-1" />
                        </label>
                        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">
                            {{ t('tarification.unit_label') }}
                            <select v-model="line.unit" class="mt-0.5 w-full rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">
                                <option v-for="unit in unitsFor(line)" :key="unit" :value="unit">{{ t(`tarification.unit.${unit}`) }}</option>
                            </select>
                            <InputError :message="errorFor(index, 'unit')" class="mt-1" />
                        </label>
                        <label class="text-xs text-slate-500 dark:text-slate-400">
                            {{ t('tarification.amount') }}
                            <TextInput v-model="line.amount" type="number" step="0.01" min="0" class="mt-0.5 w-full py-1.5 text-sm" />
                            <InputError :message="errorFor(index, 'amount')" class="mt-1" />
                        </label>
                        <label v-if="line.unit !== 'per_player'" class="text-xs text-slate-500 dark:text-slate-400">
                            {{ t(`tarification.quantity.${line.unit}`) }}
                            <TextInput v-model="line.quantity" type="number" min="1" class="mt-0.5 w-full py-1.5 text-sm" />
                            <InputError :message="errorFor(index, 'quantity')" class="mt-1" />
                        </label>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ t('tarification.line_total') }}: <span class="font-semibold text-slate-900 dark:text-slate-100">{{ lineTotal(index) }}</span></span>
                        <IconButton icon="trash" :label="t('tarification.remove_line')" variant="danger" @click="removeLine(index)" />
                    </div>
                </div>
            </div>

            <SecondaryButton type="button" class="mt-3" @click="addLine">{{ t('tarification.add_line') }}</SecondaryButton>
            <InputError :message="form.errors['tarification.lines']" class="mt-1" />
        </div>

        <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
            <TarificationResult :result="result" :collected="exists ? data.collected : null" :mixed-prices="mixedPrices" />
        </div>
    </div>
</template>
```

- [ ] **Step 4: Mount the card in the form**

In `resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue`:

Add the import:

```js
import TarificationCard from './TarificationCard.vue';
```

Add to `defineProps`:

```js
    tarification: { type: Object, default: null },
```

Add to `useForm({...})`, after `branch_ids`. Absent for users without Finance → view, so their save sends no block:

```js
    ...(props.tarification ? { tarification: JSON.parse(JSON.stringify(props.tarification.saved)) } : {}),
```

In the template, between the closing `</div>` of the first card and the buttons row:

```vue
        <TarificationCard v-if="tarification" :form="form" :data="tarification" :exists="isEdit" />
```

In `Create.vue` add to `defineProps`:

```js
    tarification: { type: Object, default: null },
```

and to the `<SubscriptionForm>` tag: `:tarification="tarification"`. Make the same two changes in `Edit.vue`.

- [ ] **Step 5: Add the summary to the subscription page**

In `resources/js/Pages/Subscriptions/Show.vue`:

Add the imports:

```js
import TarificationResult from './Partials/TarificationResult.vue';
import { useCan } from '@/Composables/useCan';
import { forecast, subscriptionInput } from '@/lib/tarification';
```

Add to `defineProps`: `tarification: { type: Object, default: null },`

After `categoryPrices`:

```js
const { can } = useCan();

// Absent without Finance → view: the card is not rendered at all.
const tarificationResult = computed(() => props.tarification && forecast({
    tarification: props.tarification.saved,
    roster: props.tarification.roster,
    schedule: props.tarification.schedule,
    subscription: subscriptionInput(props.subscription),
}));

const mixedPrices = computed(() => Number(props.subscription.amount_student) !== Number(props.subscription.amount_worker)
    || categoryPrices.value.length > 0);
```

In the template, after the "Category prices" block and before "Payment percentage bar":

```vue
            <!-- Tarification forecast (Finance → view only) -->
            <div v-if="tarificationResult" class="no-print rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('tarification.title') }}</h2>
                    <Link v-if="can('subscriptions', 'edit')" :href="route('subscriptions.edit', subscription.id)" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ t('tarification.edit_costs') }}</Link>
                </div>
                <TarificationResult :result="tarificationResult" :collected="tarification.collected" :mixed-prices="mixedPrices" />
            </div>
```

- [ ] **Step 6: Build and run all checks**

```bash
npm run build
npm run i18n:check
npm run test:js
php artisan test --filter=Subscription
```

Expected: build succeeds; i18n check exits 0; all JS and PHP tests pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json resources/js/Pages/Subscriptions/Partials/TarificationResult.vue resources/js/Pages/Subscriptions/Partials/TarificationCard.vue resources/js/Pages/Subscriptions/Partials/SubscriptionForm.vue resources/js/Pages/Subscriptions/Create.vue resources/js/Pages/Subscriptions/Edit.vue resources/js/Pages/Subscriptions/Show.vue
git commit -m "feat(tarification): pricing section on the subscription form and page"
```

Check `git status --short` first: `public/build` must not be staged if it is ignored; if tracked, follow how recent commits treat it (`git log --stat -3 -- public/build`).

---

### Task 5: Browser check and full suite

**Files:** none unless a bug is found.

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test` and `npm run test:js`
Expected: all pass. Report any failure with its output; compare with `origin/main` to tell new failures from existing ones.

- [ ] **Step 2: Serve a migrated copy**

Copy `D:\irnb-laravel\database\database.sqlite` to the session scratchpad, then in `D:\irnb-tarification`, all in one shell call because env vars do not persist between calls:

```powershell
$env:DB_DATABASE = '<scratchpad>\tarification-qa.sqlite'
php artisan db:show
php artisan migrate --force
```

Confirm `db:show` names the scratchpad copy before migrating. Run `php artisan serve --port=2027` in the background with the same variable set. The migration must add only the two tables. Never migrate the real database from here.

- [ ] **Step 3: Drive the page with playwright-core**

Use playwright-core in the scratchpad with `%LOCALAPPDATA%\ms-playwright\chromium-1243\chrome-win64\chrome.exe`, and wait for `networkidle` before login. Check, with screenshots in Arabic and in French:

1. Create form: the card appears; add the spec's worked example (20 expected players, 85%, 3 sessions × 40 weeks, coach 800 × 1, assistants 700 × 2, bus 15,000 × 10 trips, price 15,000). The panel shows loss 159,000, per player 7,950, break-even 24,353 and 33.
2. Change the price to 30,000: the panel flips to profit without a reload.
3. Save, confirm the Show page displays the same figures plus paid so far.
4. Reopen Edit: inputs come back as saved.
5. Switch a subscription to exceptional in the form: sessions fields disappear and per-session is gone from new lines.
6. Log in as a user without Finance → view: no card on Create, Edit or Show, and saving keeps the costs.
7. Phone width (390px) and dark mode: no horizontal scroll, status readable.

Fix any bug found with a failing test first when it is in the module or the server, then commit it with explicit paths.

- [ ] **Step 4: Report**

Hand the branch to `superpowers:finishing-a-development-branch`. Before any push: check `origin/main..main` and the main tip, and never chain `merge | tail && push`. Deploy needs `php artisan migrate` and `npm run build`.
