# Statistics redesign Phase 4: KPI sweep Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every KPI tile on the seven listed pages uses `StatTile`, `StatCard.vue` is deleted, and the dashboard filter text no longer runs under its arrow.

**Architecture:** Template swaps only. `StatTile` gains one opt-in prop, `flagNegative`. Each value keeps its current source expression.

**Tech Stack:** Vue 3 `<script setup>`, Inertia v2, Tailwind v3, vue-i18n.

## Global Constraints

- Frontend only: no backend, query, route, database or permission change.
- No figure is calculated differently. No new delta or sparkline.
- Dashboard and Finance tiles do not change: only Finance/Registers passes `flagNegative`.
- No new translation key, no new dependency, no remote resource.
- Stage explicit paths. Merging needs the owner's explicit approval.

The repository has no Vue component test tool (`tests/js` is node:test on plain modules), so tile rendering is verified by the build and by the browser check in Task 6.

---

### Task 1: StatTile `flagNegative` and Finance/Registers

**Files:**
- Modify: `resources/js/Components/Dashboard/StatTile.vue`
- Modify: `resources/js/Pages/Finance/Registers.vue`

**Interfaces:**
- Produces: `StatTile` prop `flagNegative: Boolean`, default false.

- [ ] **Step 1: Add the prop to StatTile**

```js
    href: { type: String, default: null },
    // Money only: a value below zero shows red. Off by default, opt-in per page.
    flagNegative: { type: Boolean, default: false },
```

```js
const isEmpty = computed(() => display.value === '—');
const isFlagged = computed(() => props.flagNegative && props.format === 'money' && Number(props.value) < 0);
```

```html
:class="isEmpty ? 'text-muted-foreground/60' : isFlagged ? 'text-rose-600 dark:text-rose-400' : 'text-foreground'"
```

- [ ] **Step 2: Replace the Totals tiles in Registers**

Add `import StatTile from '@/Components/Dashboard/StatTile.vue';` after the `Icon` import.

```html
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatTile :label="t('total_cash')" :value="totals.cash" format="money" icon="wallet" flag-negative />
                <StatTile :label="t('active_registers')" :value="totals.active" icon="money" tone="neutral" />
            </section>
```

- [ ] **Step 3: Build and commit**

Run: `npm run build`. Expected: exit 0.

```bash
git add resources/js/Components/Dashboard/StatTile.vue resources/js/Pages/Finance/Registers.vue
git commit -m "feat(finance): Registers totals use StatTile, a negative total stays red"
```

### Task 2: Board/Index

**Files:**
- Modify: `resources/js/Pages/Board/Index.vue`

- [ ] **Step 1: Swap the import**

`import StatCard from '@/Components/StatCard.vue';` becomes `import StatTile from '@/Components/Dashboard/StatTile.vue';`

- [ ] **Step 2: Replace the four tiles**

```html
                <StatTile :label="t('board_members')" :value="stats.members || 0" icon="board" />
                <StatTile :label="t('meetings')" :value="stats.meetings_held || 0" icon="calendar" tone="neutral" />
                <StatTile :label="t('tasks')" :value="stats.tasks_open || 0" icon="task" tone="warning" :meta="t('in_progress')" />
                <StatTile
                    :label="t('completion_rate')"
                    :value="completionRate"
                    format="percent"
                    icon="check"
                    :tone="stats.tasks_overdue ? 'negative' : 'positive'"
                    :meta="stats.tasks_overdue ? `${stats.tasks_overdue} ${t('overdue')}` : null"
                />
```

- [ ] **Step 3: Build and commit**

Run: `npm run build`. Expected: exit 0.

```bash
git add resources/js/Pages/Board/Index.vue
git commit -m "refactor(board): the board overview uses StatTile"
```

### Task 3: Equipment/Inventory, Inventory/Index, Inventory/Session

**Files:**
- Modify: `resources/js/Pages/Equipment/Inventory.vue`
- Modify: `resources/js/Pages/Inventory/Index.vue`
- Modify: `resources/js/Pages/Inventory/Session.vue`

- [ ] **Step 1: Swap the import in all three**

`import StatCard from '@/Components/StatCard.vue';` becomes `import StatTile from '@/Components/Dashboard/StatTile.vue';`

- [ ] **Step 2: Equipment/Inventory**

```html
                <StatTile :label="t('total')" :value="summary?.total || 0" icon="box" />
                <StatTile :label="t('available')" :value="summary?.available || 0" icon="check" tone="positive" />
                <StatTile :label="t('rented')" :value="summary?.rented || 0" icon="refresh" tone="warning" />
                <StatTile :label="t('under_repair')" :value="summary?.under_repair || 0" icon="wrench" tone="neutral" />
                <StatTile :label="t('lost')" :value="summary?.lost || 0" icon="xcircle" tone="negative" />
                <StatTile :label="t('retired')" :value="summary?.retired || 0" icon="archive" tone="neutral" />
```

- [ ] **Step 3: Inventory/Index**

```html
                <StatTile :label="t('equipments')" :value="itemCount" icon="equipment" :meta="t('items_to_count')" />
                <StatTile :label="t('last_inventory')" :value="lastDate ? fmtDate(lastDate) : null" format="text" icon="clipboard" tone="neutral" />
                <StatTile :label="t('inventory')" :value="sessions.length" icon="check" tone="positive" />
```

- [ ] **Step 4: Inventory/Session**

No icons, by owner decision: three across on a phone leave no room for one.

```html
                <StatTile :label="t('total_expected')" :value="session.total_expected" />
                <StatTile :label="t('found')" :value="isOpen ? foundCount : session.total_found" />
                <StatTile :label="t('missing')" :value="isOpen ? (countedCount - foundCount) : session.total_missing" />
```

- [ ] **Step 5: Build and commit**

Run: `npm run build`. Expected: exit 0.

```bash
git add resources/js/Pages/Equipment/Inventory.vue resources/js/Pages/Inventory/Index.vue resources/js/Pages/Inventory/Session.vue
git commit -m "refactor(equipment): inventory and stock-take pages use StatTile"
```

### Task 4: Subscriptions/Show and Equipment/History

**Files:**
- Modify: `resources/js/Pages/Subscriptions/Show.vue`
- Modify: `resources/js/Pages/Equipment/History.vue`

- [ ] **Step 1: Subscriptions/Show**

Add `import StatTile from '@/Components/Dashboard/StatTile.vue';` after the `IconButton` import. Keep the file's BOM. Replace the six hand-written tiles and their grid:

```html
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <StatTile :label="t('student_pricing')" :value="subscription.amount_student" format="money" icon="money" tone="neutral" />
                <StatTile :label="t('worker_pricing')" :value="subscription.amount_worker" format="money" icon="money" tone="neutral" />
                <StatTile :label="t('total_players')" :value="stats?.total ?? 0" icon="players" />
                <StatTile :label="t('paid')" :value="stats?.paid_count ?? 0" icon="check" tone="positive" />
                <StatTile :label="t('unpaid')" :value="stats?.unpaid_count ?? 0" icon="alert" tone="negative" />
                <StatTile :label="t('collected')" :value="stats?.total_collected ?? 0" format="money" icon="wallet" />
            </div>
```

- [ ] **Step 2: Equipment/History**

Add `import StatTile from '@/Components/Dashboard/StatTile.vue';` after the `RentalTypeBadge` import. Replace the Statistics block:

```html
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <StatTile :label="t('total')" :value="stats.events" icon="clipboard" tone="neutral" />
                <StatTile :label="t('equipment.rental')" :value="stats.rentals" icon="refresh" tone="warning" />
                <StatTile :label="t('return')" :value="stats.returns" icon="restore" />
                <StatTile :label="t('send_to_repair')" :value="stats.repairs" icon="wrench" tone="neutral" />
                <StatTile :label="t('rented_to')" :value="stats.people" icon="players" tone="positive" />
            </div>
```

- [ ] **Step 3: Build and commit**

Run: `npm run build`. Expected: exit 0.

```bash
git add resources/js/Pages/Subscriptions/Show.vue resources/js/Pages/Equipment/History.vue
git commit -m "refactor(stats): subscription and equipment history tiles use StatTile"
```

### Task 5: Delete StatCard, fix the dashboard filter

**Files:**
- Delete: `resources/js/Components/StatCard.vue`
- Modify: `resources/js/Components/Dashboard/DashboardFilterBar.vue:83,97`

- [ ] **Step 1: Confirm nothing imports StatCard**

Run: `git grep -n "StatCard" -- resources tests`. Expected: only the file itself. Then `git rm resources/js/Components/StatCard.vue`.

- [ ] **Step 2: Build and commit the removal**

Run: `npm run build`. Expected: exit 0.

```bash
git commit -m "refactor(stats): remove StatCard, every KPI tile is a StatTile now"
```

- [ ] **Step 3: Give both selects room for the arrow**

The forms plugin draws the arrow at the right whatever the direction, and `px-2` removes the room it leaves. In both selects replace `px-2` with:

`ps-2 pe-8 rtl:[background-position:left_0.5rem_center]`

- [ ] **Step 4: Build and commit**

Run: `npm run build`. Expected: exit 0.

```bash
git add resources/js/Components/Dashboard/DashboardFilterBar.vue
git commit -m "fix(dashboard): filter text no longer runs under the dropdown arrow"
```

### Task 6: Verification

No code change unless a check fails.

- [ ] **Step 1: Automated checks**

Run: `npm run test:js`, `npm run i18n:check`, `php artisan test` with no dev server in the worktree and no `bootstrap/cache/config.php`. Expected: all pass, no missing key.

- [ ] **Step 2: Browser, on a copy of the database in the worktree**

Pages: `board`, `equipment/inventory`, `equipment/stocktake`, one `equipment/stocktake` session, one `subscriptions/{id}`, one equipment item history, `finance/registers`, `dashboard`. French and Arabic, light and dark, at 375, 1024 and 1366 px. Expected: login confirmed before measuring, each page measured and none skipped, no sideways scroll, no overflowing or split figure, clean console. Review screenshots.

- [ ] **Step 3: Behaviour**

- Registers: with a negative cash total on the worktree copy, the number is red.
- Dashboard: a negative tile keeps its normal colour.
- Session: marking an item changes Found and Missing without a reload.
- Board: the Completion rate icon is red with overdue tasks and green without.
- Filter: text clear of the arrow in French and Arabic.
