# Statistics redesign, Phase 4: KPI sweep

Date: 2026-11-02. Parent spec: `2026-11-01-stats-redesign-design.md` (section 6, phase 4).

## 1. Goal

Every KPI tile in the listed pages uses `StatTile`. `StatCard.vue` is deleted.

Frontend only. No backend, query, route, database or permission change. No figure is calculated differently. No new delta or sparkline: these pages show snapshots with no previous period, and a change is never invented.

## 2. Owner decisions (2026-11-02)

1. **Numbers**: dark number, coloured icon, as on the dashboard. A negative money balance stays red.
2. **Inventory/Session**: three tiles across at every width, with no icons, so the count sheet stays near the top of a phone screen.
3. **Icons**: Subscriptions/Show and Equipment/History gain icons.
4. **Dashboard filter**: the text and arrow overlap is fixed in this batch, in its own commit.
5. **Accepted side effect**: on Inventory/Session, Missing has no red. The number and label carry it.
6. **Accepted addition**: on Board/Index the overdue line turns grey, so the Completion rate icon turns red whenever tasks are overdue.

## 3. StatTile change

One new prop, off by default:

`flagNegative: Boolean`. When true and `format` is `money` and the value is below zero, the number uses `text-rose-600 dark:text-rose-400`.

Only Finance/Registers passes it. Dashboard and Finance tiles do not change.

## 4. Tile mapping

Colour mapping from `StatCard`: `primary` to `primary`, `slate` to `neutral`, `amber` to `warning`, `emerald` to `positive`, `rose` to `negative`.

Each value keeps its current source expression, including its `|| 0` or `?? 0` fallback.

### Board/Index

Grid unchanged (`grid-cols-2 lg:grid-cols-4`).

| Label key | Value | Icon | Tone | Other |
|---|---|---|---|---|
| `board_members` | `stats.members` | `board` | primary | |
| `meetings` | `stats.meetings_held` | `calendar` | neutral | |
| `tasks` | `stats.tasks_open` | `task` | warning | meta `t('in_progress')` |
| `completion_rate` | `completionRate` | `check` | `stats.tasks_overdue ? 'negative' : 'positive'` | `format="percent"`; meta `{n} t('overdue')` when overdue |

### Equipment/Inventory

Grid unchanged.

| Label key | Icon | Tone |
|---|---|---|
| `total` | `box` | primary |
| `available` | `check` | positive |
| `rented` | `refresh` | warning |
| `under_repair` | `wrench` | neutral |
| `lost` | `xcircle` | negative |
| `retired` | `archive` | neutral |

### Inventory/Index

Grid unchanged.

| Label key | Value | Icon | Tone | Other |
|---|---|---|---|---|
| `equipments` | `itemCount` | `equipment` | primary | meta `t('items_to_count')` |
| `last_inventory` | `lastDate ? fmtDate(lastDate) : null` | `clipboard` | neutral | `format="text"`; null shows the dash |
| `inventory` | `sessions.length` | `check` | positive | |

### Inventory/Session

Grid unchanged (`grid-cols-3`). No icons. Values keep their live expressions while the session is open.

Labels: `total_expected`, `found`, `missing`.

### Subscriptions/Show

Grid gains `grid-cols-1`: `grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6`.

| Label key | Value | Format | Icon | Tone |
|---|---|---|---|---|
| `student_pricing` | `subscription.amount_student` | money | `money` | neutral |
| `worker_pricing` | `subscription.amount_worker` | money | `money` | neutral |
| `total_players` | `stats?.total ?? 0` | number | `players` | primary |
| `paid` | `stats?.paid_count ?? 0` | number | `check` | positive |
| `unpaid` | `stats?.unpaid_count ?? 0` | number | `alert` | negative |
| `collected` | `stats?.total_collected ?? 0` | money | `wallet` | primary |

Amounts now show the club currency, through `StatTile`.

### Equipment/History

Grid becomes `grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5`. Five tiles at tablet width would be too narrow. Label first and left-aligned replaces number first and centred. The item information card above stays as it is.

| Label key | Value | Icon | Tone |
|---|---|---|---|
| `total` | `stats.events` | `clipboard` | neutral |
| `equipment.rental` | `stats.rentals` | `refresh` | warning |
| `return` | `stats.returns` | `restore` | primary |
| `send_to_repair` | `stats.repairs` | `wrench` | neutral |
| `rented_to` | `stats.people` | `players` | positive |

### Finance/Registers

Only the two tiles in the Totals section. Grid gains `grid-cols-1`. The opening-fund notice and the branch, caisse and account cards stay as they are; they are not KPI tiles.

| Label key | Value | Format | Icon | Tone | Other |
|---|---|---|---|---|---|
| `total_cash` | `totals.cash` | money | `wallet` | primary | `flagNegative` |
| `active_registers` | `totals.active` | number | `money` | neutral | |

## 5. StatCard removal

Delete `resources/js/Components/StatCard.vue` once the sweep leaves no import. The `.figure` and `.figure-fit` CSS classes stay: `.figure` is used by Finance/Index and Public/Home, and `.figure-fit` by `FitFigure`.

## 6. Dashboard filter fix

`DashboardFilterBar.vue`: both selects use `px-2`, which removes the room the forms plugin leaves for the arrow, so long text runs under it. Give them logical end padding (`ps-2 pe-8`). Check Arabic, where the arrow position must still match the padding.

## 7. Testing

- `npm run test:js`, `npm run i18n:check`, `npm run build`, `php artisan test`. No new translation key is expected.
- Run the PHP suite with no dev server in the worktree and no cached config.
- Browser, headless Chrome, on all seven pages and the dashboard: French and Arabic, light and dark, at 375, 1024 and 1366 px. Check sideways scroll, split or overflowing figures, console errors, and review screenshots.
- `flagNegative`: a negative cash total is red on Registers, and a negative dashboard tile is unchanged.
- Inventory/Session: Found and Missing update while counting.
- Board/Index: the Completion rate icon is red with overdue tasks, and green without.

## 8. Out of scope

Links on tiles, the other tile-like cards on these pages, and the tables named as out of scope in the parent spec.
