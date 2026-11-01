# Stats Redesign P1 — Foundation + Finance Pilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the shared statistics building blocks (StatTable, CSV export, evolved ChartCard, StatsToolbar, live chart theme) and prove them on Finance/Index, including new vs-previous-season deltas.

**Architecture:** Evolve existing pieces (`ChartCard`, `StatTile`, `chartTheme`) instead of adding a parallel system. Pure logic lives in `resources/js/lib/statTable.js` (unit-tested with `node:test`); Vue components stay thin. The only backend change is an additive `detail.deltas` key computed with the existing `DeltaCalculator`.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3 `<script setup>`, Tailwind v3 (shadcn tokens), chart.js 4 + vue-chartjs 5, vue-i18n flat dotted keys, PHPUnit (`#[Test]`), `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-01-stats-redesign-design.md`

## Global Constraints

- Work only in worktree `D:\irnb-stats` on branch `feat/stats-redesign`. Never switch branches or stage files in `D:\irnb-laravel` (another session works there).
- No existing calculation changes; new numbers are new keys; existing prop keys keep shape and meaning.
- No CDN / remote resource; no new npm or composer dependency.
- i18n: flat dotted keys, added to ar/fr/en together with `node scripts/i18n-add.mjs <file>`; call `t()` directly (never gate on `te()`).
- Existing `ChartCard` / `StatTile` callers must keep working unchanged (all new props optional).
- Chart ⇄ table switch is client-only: no request, no filter change; table DOM renders only while shown.
- RTL: logical utilities only (`ms-/me-/ps-/pe-/text-start/text-end`); numbers in cells wrapped in `<bdi>`.
- Commits: stage explicit file paths only; message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- After Task 4 is committed, message the Reports Center session (`irnb-laravel-f0`) with the commit hash (it rebases `feat/reports-r1` onto it).
- Stop after Task 7 for owner check; do not start Phase 2.

---

### Task 0: Worktree ready to test and build

**Files:** none committed.

- [ ] **Step 1: Copy dependencies and env (copies, never junctions)**

```powershell
robocopy D:\irnb-laravel\vendor D:\irnb-stats\vendor /E /NFL /NDL /NJH /NJS /MT:16
robocopy D:\irnb-laravel\node_modules D:\irnb-stats\node_modules /E /NFL /NDL /NJH /NJS /MT:16
Copy-Item D:\irnb-laravel\.env D:\irnb-stats\.env
```

robocopy exit codes 0–7 are success.

- [ ] **Step 2: Baseline**

Run in `D:\irnb-stats`:
```bash
php artisan test --filter=Finance
npm run test:js
npm run build
```
Expected: all green, build succeeds. Record the pass counts; any failure here is pre-existing and must be reported, not fixed in this plan.

---

### Task 1: `lib/statTable.js` — sort, totals, CSV

**Files:**
- Create: `resources/js/lib/statTable.js`
- Test: `tests/js/statTable.test.mjs`

**Interfaces:**
- Produces:
  - `BOM: string` (`'\uFEFF'`)
  - `sortRows(rows: object[], key: string|null, dir: 'asc'|'desc' = 'asc', locale?: string): object[]` — new array, blanks (`null|undefined|''`) last in both directions, stable.
  - `sumColumn(rows: object[], key: string): number` — blanks ignored, rounded to 2 decimals.
  - `escapeFormula(value: string): string` — mirrors `CsvWriter::escapeFormula`.
  - `toCsv(columns: Column[], rows: object[], opts?: { currency?: string, totals?: object|null, totalLabel?: string }): string`
  - `safeFilename(name: string): string`
  - `downloadCsv(filename: string, csv: string): void` (browser only)
  - `Column = { key: string, label: string, format?: 'number'|'money'|'percent'|'text'|'date', align?: 'start'|'end'|'center', sortable?: boolean, total?: 'sum'|'none', csv?: (row) => any }`

- [ ] **Step 1: Write the failing tests**

`tests/js/statTable.test.mjs`:
```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BOM, sortRows, sumColumn, escapeFormula, toCsv, safeFilename } from '../../resources/js/lib/statTable.js';

const rows = [
    { name: 'Béja', amount: 300 },
    { name: 'alger', amount: null },
    { name: 'Oran', amount: '1200.50' },
    { name: 'Annaba', amount: 45 },
];

test('numbers sort as numbers, numeric strings included', () => {
    assert.deepEqual(sortRows(rows, 'amount', 'asc').map((r) => r.name), ['Annaba', 'Béja', 'Oran', 'alger']);
    assert.deepEqual(sortRows(rows, 'amount', 'desc').map((r) => r.name), ['Oran', 'Béja', 'Annaba', 'alger']);
});

test('blanks stay last whichever way the column is sorted', () => {
    assert.equal(sortRows(rows, 'amount', 'asc').at(-1).name, 'alger');
    assert.equal(sortRows(rows, 'amount', 'desc').at(-1).name, 'alger');
});

test('text sorts case- and accent-insensitively, and sorting never mutates the input', () => {
    const before = rows.map((r) => r.name);
    assert.deepEqual(sortRows(rows, 'name', 'asc', 'fr').map((r) => r.name), ['alger', 'Annaba', 'Béja', 'Oran']);
    assert.deepEqual(rows.map((r) => r.name), before);
});

test('no sort key returns a copy in the original order', () => {
    const out = sortRows(rows, null);
    assert.notEqual(out, rows);
    assert.deepEqual(out, rows);
});

test('equal values keep their original order', () => {
    const tied = [{ k: 'a', v: 1 }, { k: 'b', v: 1 }, { k: 'c', v: 0 }];
    assert.deepEqual(sortRows(tied, 'v', 'desc').map((r) => r.k), ['a', 'b', 'c']);
});

test('sumColumn ignores blanks and rounds float noise', () => {
    assert.equal(sumColumn(rows, 'amount'), 1545.5);
    assert.equal(sumColumn([{ v: 0.1 }, { v: 0.2 }], 'v'), 0.3);
    assert.equal(sumColumn([], 'v'), 0);
});

test('escapeFormula matches the server CsvWriter rules', () => {
    assert.equal(escapeFormula('=SUM(A1)'), "'=SUM(A1)");
    assert.equal(escapeFormula('@cmd'), "'@cmd");
    assert.equal(escapeFormula('\tx'), "'\tx");
    assert.equal(escapeFormula('+HYPERLINK("x")'), '\'+HYPERLINK("x")');
    assert.equal(escapeFormula('-cmd|x'), "'-cmd|x");
    assert.equal(escapeFormula('+213 555 12 34'), '+213 555 12 34');
    assert.equal(escapeFormula('-5'), '-5');
    assert.equal(escapeFormula(''), '');
    assert.equal(escapeFormula('Oran'), 'Oran');
});

const columns = [
    { key: 'name', label: 'Ville', format: 'text' },
    { key: 'amount', label: 'Montant', format: 'money', total: 'sum' },
    { key: 'share', label: 'Part', format: 'percent' },
];

test('toCsv: BOM, CRLF, comma, currency in the money header, raw numbers', () => {
    const csv = toCsv(columns, [{ name: 'Oran', amount: 1200.5, share: 12.5 }], { currency: 'DA' });
    assert.ok(csv.startsWith(BOM));
    // "Montant (DA)" holds a space, so fputcsv-style quoting wraps it.
    assert.equal(csv, `${BOM}Ville,"Montant (DA)",Part\r\nOran,1200.5,12.5\r\n`);
});

test('toCsv quotes commas, quotes, line breaks and spaces like fputcsv', () => {
    const csv = toCsv([{ key: 'v', label: 'V' }], [{ v: 'a,b' }, { v: 'say "hi"' }, { v: 'two\nlines' }, { v: 'Sidi Bel Abbès' }]);
    assert.equal(csv, `${BOM}V\r\n"a,b"\r\n"say ""hi"""\r\n"two\nlines"\r\n"Sidi Bel Abbès"\r\n`);
});

test('toCsv escapes formula cells, leaves numbers alone, blanks empty, booleans 1/0', () => {
    const csv = toCsv([{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }], [{ a: '=1+1', b: -5 }, { a: null, b: true }]);
    assert.equal(csv, `${BOM}A,B\r\n'=1+1,-5\r\n,1\r\n`);
});

test('toCsv appends the totals row with its label in the first column', () => {
    const csv = toCsv(columns, [{ name: 'Oran', amount: 10, share: 50 }], { totals: { amount: 10 }, totalLabel: 'Total' });
    assert.ok(csv.endsWith('Total,10,\r\n'));
});

test('toCsv uses a column csv() accessor when given', () => {
    const csv = toCsv([{ key: 'm', label: 'Mois', csv: (row) => `M${row.m}` }], [{ m: 9 }]);
    assert.equal(csv, `${BOM}Mois\r\nM9\r\n`);
});

test('safeFilename strips characters Windows refuses and keeps Arabic', () => {
    assert.equal(safeFilename('a/b:c*?"<>|d'), 'a-b-c-d');
    assert.equal(safeFilename('المداخيل 2026/2027'), 'المداخيل 2026-2027');
    assert.equal(safeFilename('   '), 'export');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test tests/js/statTable.test.mjs`
Expected: FAIL — `Cannot find module '.../resources/js/lib/statTable.js'`.

- [ ] **Step 3: Implement**

`resources/js/lib/statTable.js`:
```js
/**
 * Pure helpers behind StatTable and the per-panel CSV download.
 *
 * toCsv mirrors App\Support\Export\CsvWriter so a file saved from a chart
 * panel opens in Excel exactly like a server export: UTF-8 BOM (Excel's cue to
 * read Arabic correctly), CRLF, comma, fputcsv-style quoting, and the same
 * formula escaping. Change one, change the other.
 */
export const BOM = '\uFEFF';

const isBlank = (value) => value === null || value === undefined || value === '';

const asNumber = (value) => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);

    return null;
};

/** A sorted copy. Blanks go last in both directions; ties keep their order. */
export function sortRows(rows, key, dir = 'asc', locale = undefined) {
    if (!key) return rows.slice();

    const collator = new Intl.Collator(locale, { numeric: true, sensitivity: 'base' });
    const sign = dir === 'desc' ? -1 : 1;

    return rows
        .map((row, index) => ({ row, index }))
        .sort((x, y) => {
            const a = x.row[key];
            const b = y.row[key];
            if (isBlank(a) && isBlank(b)) return x.index - y.index;
            if (isBlank(a)) return 1;
            if (isBlank(b)) return -1;

            const na = asNumber(a);
            const nb = asNumber(b);
            const order = na !== null && nb !== null ? na - nb : collator.compare(String(a), String(b));

            return sign * order || x.index - y.index;
        })
        .map(({ row }) => row);
}

/** Sum of a column; blanks ignored, rounded to cents so 0.1 + 0.2 reads 0.3. */
export function sumColumn(rows, key) {
    const sum = rows.reduce((total, row) => total + (asNumber(row[key]) ?? 0), 0);

    return Math.round(sum * 100) / 100;
}

/**
 * Excel runs a cell starting with =, @, a tab or a CR as a formula; an
 * apostrophe forces it back to text. + and - are escaped unless the cell reads
 * as a plain number or phone number. Same rule as CsvWriter::escapeFormula.
 */
export function escapeFormula(value) {
    if (value === '') return value;

    const first = value[0];
    if (first === '=' || first === '@' || first === '\t' || first === '\r') return `'${value}`;
    if ((first === '+' || first === '-') && !/^[+-][\d\s().]*$/.test(value)) return `'${value}`;

    return value;
}

function stringify(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? '1' : '0';
    if (typeof value === 'number') return String(value);

    return escapeFormula(String(value));
}

// fputcsv quotes a field holding the delimiter, the enclosure, a line break,
// a tab or a space.
const quote = (cell) => (/[",\r\n\t ]/.test(cell) ? `"${cell.replace(/"/g, '""')}"` : cell);

const line = (cells) => cells.map((value) => quote(stringify(value))).join(',');

/** The table as CSV text: header (money headers carry the currency), rows, optional totals. */
export function toCsv(columns, rows, { currency = '', totals = null, totalLabel = '' } = {}) {
    const header = columns.map((column) => (column.format === 'money' && currency ? `${column.label} (${currency})` : column.label));
    const lines = [line(header)];

    for (const row of rows) {
        lines.push(line(columns.map((column) => (column.csv ? column.csv(row) : row[column.key]))));
    }

    if (totals) {
        lines.push(line(columns.map((column, index) => {
            if (column.total === 'sum' && totals[column.key] !== undefined) return totals[column.key];

            return index === 0 ? totalLabel : null;
        })));
    }

    return `${BOM}${lines.join('\r\n')}\r\n`;
}

/** A file name Windows accepts: reserved characters become dashes. */
export function safeFilename(name) {
    const cleaned = String(name ?? '').replace(/[\\/:*?"<>|]+/g, '-').trim();

    return cleaned === '' ? 'export' : cleaned;
}

/** Saves CSV text as a file through a temporary object URL. Browser only. */
export function downloadCsv(filename, csv) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const base = safeFilename(filename);
    link.href = url;
    link.download = base.toLowerCase().endsWith('.csv') ? base : `${base}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}
```

Note on the totals test: columns are `name` (no total), `amount` (sum), `share` (no total) → `Total,10,` — the trailing empty cell is the blank `share`.

- [ ] **Step 4: Run to verify it passes**

Run: `node --test tests/js/statTable.test.mjs`
Expected: all tests PASS. Then `npm run test:js` — all files PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/statTable.js tests/js/statTable.test.mjs
git commit -m "feat(stats): statTable helpers — sort, totals, CSV mirroring CsvWriter

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `StatTable.vue` + stats i18n keys

**Files:**
- Create: `resources/js/Components/Stats/StatTable.vue`
- Create (temporary, not committed): `C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\43c0584d-c7d0-4b0b-940c-0a0eedcaa312\scratchpad\stats-keys.json`
- Modify: `resources/js/i18n/ar.json`, `en.json`, `fr.json` (via script only)

**Interfaces:**
- Consumes: `sortRows`, `sumColumn` from Task 1; `useFormatMoney().formatMoney`, `useFormatDate().formatDate`, `useCurrency().currencyLabel`.
- Produces: `<StatTable>` props `columns: Column[]` (required), `rows: object[]`, `rowKey: string|((row, index) => any)|null`, `sort: {key, dir}|null` (controlled), `sortMode: 'client'|'server'` (default `'client'`), `totals: boolean|object` (default `false`), `totalLabel: string|null`, `emptyText: string|null`, `dense: boolean`, `clickable: boolean`, `rowClass: (row) => string|null`, `maxHeight: string|null` (Tailwind class, e.g. `'max-h-96'`). Emits `sort({key, dir})`, `row-click(row)`. Slots `cell-<key>` `{ row, value, formatted }`, `footer`. Injects `statTableInScroller` (Boolean, default `false`): when true it renders without its own scroll wrapper (the parent scrolls, so the sticky header sticks).
- Produces i18n keys: `stats.total`, `stats.empty`, `stats.sort_by`, `stats.view`, `stats.view_chart`, `stats.view_table`, `stats.download_csv`, `stats.load_failed`, `stats.retry`, `stats.filters`, `stats.active_filters`, `stats.vs_previous_season`, `stats.month`.

- [ ] **Step 1: Add the i18n keys**

Write `stats-keys.json` in the scratchpad:
```json
{
    "stats.total": { "ar": "المجموع", "fr": "Total", "en": "Total" },
    "stats.empty": { "ar": "لا توجد بيانات لعرضها", "fr": "Aucune donnée à afficher", "en": "No data to show" },
    "stats.sort_by": { "ar": "ترتيب حسب {column}", "fr": "Trier par {column}", "en": "Sort by {column}" },
    "stats.view": { "ar": "طريقة العرض", "fr": "Affichage", "en": "View" },
    "stats.view_chart": { "ar": "مخطط", "fr": "Graphique", "en": "Chart" },
    "stats.view_table": { "ar": "جدول", "fr": "Tableau", "en": "Table" },
    "stats.download_csv": { "ar": "تنزيل الجدول (CSV)", "fr": "Télécharger le tableau (CSV)", "en": "Download table (CSV)" },
    "stats.load_failed": { "ar": "تعذّر تحميل هذه البيانات", "fr": "Impossible de charger ces données", "en": "These figures could not be loaded" },
    "stats.retry": { "ar": "إعادة المحاولة", "fr": "Réessayer", "en": "Try again" },
    "stats.filters": { "ar": "عوامل التصفية", "fr": "Filtres", "en": "Filters" },
    "stats.active_filters": { "ar": "{count} مفعّل", "fr": "{count} actif(s)", "en": "{count} active" },
    "stats.vs_previous_season": { "ar": "مقارنة بالموسم السابق", "fr": "par rapport à la saison précédente", "en": "vs previous season" },
    "stats.month": { "ar": "الشهر", "fr": "Mois", "en": "Month" }
}
```

Run: `node scripts/i18n-add.mjs "C:\Users\MUSTAPHA\AppData\Local\Temp\claude\d--irnb-laravel\43c0584d-c7d0-4b0b-940c-0a0eedcaa312\scratchpad\stats-keys.json"`
Expected: `✓ 13 key(s) written to ar/en/fr`. Then `git diff --stat resources/js/i18n` shows exactly 13 insertions per file.

- [ ] **Step 2: Create the component**

`resources/js/Components/Stats/StatTable.vue`:
```vue
<script setup>
import { computed, inject, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { sortRows, sumColumn } from '@/lib/statTable';

/**
 * The one table for statistics: a chart panel's table view, stats pages and
 * the Reports Center viewer all render through it, so a number looks the same
 * wherever it is read.
 *
 * Columns declare a format; the table formats, aligns (numbers to the end, in
 * tabular figures) and totals. Sorting is in the browser by default; with
 * sortMode="server" the table only emits `sort` and shows the `sort` prop it
 * is handed back, so a paginated page can sort on the server.
 *
 * Totals are opt-in per column (total: 'sum') — a summed percentage or average
 * is a wrong number, so the table never guesses.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: [String, Function], default: null },
    sort: { type: Object, default: null },
    sortMode: { type: String, default: 'client' },
    totals: { type: [Boolean, Object], default: false },
    totalLabel: { type: String, default: null },
    emptyText: { type: String, default: null },
    dense: { type: Boolean, default: false },
    clickable: { type: Boolean, default: false },
    rowClass: { type: Function, default: null },
    maxHeight: { type: String, default: null },
});

const emit = defineEmits(['sort', 'row-click']);

const { t, locale } = useI18n();
const { currencyLabel } = useCurrency();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();

// Inside a ChartCard the card's table box scrolls; a second scroll box here
// would capture the sticky header and stop it sticking.
const inScroller = inject('statTableInScroller', false);

const NUMERIC = ['number', 'money', 'percent'];
const isNumeric = (column) => NUMERIC.includes(column.format);

const localSort = ref(null);
const activeSort = computed(() => props.sort ?? localSort.value);

function nextSort(column) {
    const current = activeSort.value;
    if (current?.key === column.key) return { key: column.key, dir: current.dir === 'asc' ? 'desc' : 'asc' };

    // Biggest first is what a reader of figures wants; names read A→Z.
    return { key: column.key, dir: isNumeric(column) ? 'desc' : 'asc' };
}

function onSort(column) {
    if (!column.sortable) return;
    const next = nextSort(column);
    if (props.sortMode === 'client') localSort.value = next;
    emit('sort', next);
}

const displayRows = computed(() => {
    const sort = activeSort.value;
    if (props.sortMode !== 'client' || !sort) return props.rows;

    return sortRows(props.rows, sort.key, sort.dir, locale.value);
});

const totalsRow = computed(() => {
    if (!props.totals) return null;
    if (typeof props.totals === 'object') return props.totals;

    return Object.fromEntries(
        props.columns.filter((column) => column.total === 'sum').map((column) => [column.key, sumColumn(props.rows, column.key)]),
    );
});

const numberFormat = new Intl.NumberFormat();

function format(column, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (column.format === 'money') return formatMoney(value);
    if (column.format === 'percent') return `${Number(value).toFixed(1)}%`;
    if (column.format === 'number') return numberFormat.format(Number(value));
    if (column.format === 'date') return formatDate(value);

    return String(value);
}

function alignClass(column) {
    const align = column.align ?? (isNumeric(column) ? 'end' : 'start');

    return { start: 'text-start', end: 'text-end', center: 'text-center' }[align];
}

function ariaSort(column) {
    if (!column.sortable) return undefined;
    if (activeSort.value?.key !== column.key) return 'none';

    return activeSort.value.dir === 'asc' ? 'ascending' : 'descending';
}

function keyOf(row, index) {
    if (typeof props.rowKey === 'function') return props.rowKey(row, index);
    if (props.rowKey) return row[props.rowKey];

    return index;
}

const cellPad = computed(() => (props.dense ? 'px-3 py-1.5' : 'px-3 py-2.5'));
</script>

<template>
    <div :class="inScroller ? '' : ['overflow-auto', maxHeight]">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :aria-sort="ariaSort(column)"
                        class="sticky top-0 z-10 whitespace-nowrap border-b border-border bg-card text-xs font-semibold text-muted-foreground"
                        :class="[cellPad, alignClass(column)]"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 rounded hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :title="t('stats.sort_by', { column: column.label })"
                            @click="onSort(column)"
                        >
                            <span>{{ column.label }}</span>
                            <svg class="size-3 shrink-0" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                <path d="M3 4.5 6 1.5l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" :opacity="activeSort?.key === column.key && activeSort.dir === 'asc' ? 1 : 0.35" />
                                <path d="M3 7.5 6 10.5l3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" :opacity="activeSort?.key === column.key && activeSort.dir === 'desc' ? 1 : 0.35" />
                            </svg>
                        </button>
                        <template v-else>{{ column.label }}</template>
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-if="!displayRows.length">
                    <td :colspan="columns.length" class="px-3 py-10 text-center text-sm text-muted-foreground">
                        {{ emptyText ?? t('stats.empty') }}
                    </td>
                </tr>
                <tr
                    v-for="(row, index) in displayRows"
                    :key="keyOf(row, index)"
                    class="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/40"
                    :class="[clickable ? 'cursor-pointer focus-visible:bg-muted/60 focus-visible:outline-none' : '', rowClass?.(row)]"
                    :tabindex="clickable ? 0 : undefined"
                    @click="clickable && emit('row-click', row)"
                    @keydown.enter="clickable && emit('row-click', row)"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        :class="[cellPad, alignClass(column), isNumeric(column) ? 'whitespace-nowrap tabular-nums' : '']"
                    >
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]" :formatted="format(column, row[column.key])">
                            <template v-if="isNumeric(column)"><bdi>{{ format(column, row[column.key]) }}</bdi><span v-if="column.format === 'money' && format(column, row[column.key]) !== '—'" class="ms-1 text-xs text-muted-foreground">{{ currencyLabel }}</span></template>
                            <template v-else>{{ format(column, row[column.key]) }}</template>
                        </slot>
                    </td>
                </tr>
            </tbody>

            <tfoot v-if="totalsRow && displayRows.length">
                <tr class="border-t-2 border-border font-semibold">
                    <td
                        v-for="(column, index) in columns"
                        :key="column.key"
                        :class="[cellPad, alignClass(column), isNumeric(column) ? 'whitespace-nowrap tabular-nums' : '']"
                    >
                        <template v-if="column.total === 'sum' && totalsRow[column.key] !== undefined">
                            <bdi>{{ format(column, totalsRow[column.key]) }}</bdi><span v-if="column.format === 'money'" class="ms-1 text-xs font-normal text-muted-foreground">{{ currencyLabel }}</span>
                        </template>
                        <template v-else-if="index === 0">{{ totalLabel ?? t('stats.total') }}</template>
                    </td>
                </tr>
            </tfoot>
        </table>
        <slot name="footer" />
    </div>
</template>
```

- [ ] **Step 3: Verify**

Run: `npm run build` → succeeds. `npm run i18n:check` → passes.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Stats/StatTable.vue resources/js/i18n/ar.json resources/js/i18n/en.json resources/js/i18n/fr.json
git commit -m "feat(stats): StatTable — shared sortable stats table with totals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Live chart theme + ranked-bar helper

**Files:**
- Create: `resources/js/Composables/useChartTheme.js`
- Modify: `resources/js/lib/chartTheme.js` (append one export after `barDataset`)

**Interfaces:**
- Produces: `useChartTheme(): { themeVersion: Ref<number> }` — increments only when the light/dark mode actually flips; observer disconnected on unmount. Callers read `themeVersion.value` inside chart `computed`s so they recompute.
- Produces: `rankedBarDataset(label: string, values: number[]): object` — one bar dataset coloured on the sequential ramp, darkest for the first (largest) row; same look as the dashboard's category bars.

- [ ] **Step 1: Composable**

`resources/js/Composables/useChartTheme.js`:
```js
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { isDark } from '@/lib/chartTheme';

/**
 * chartTheme reads the theme at the moment options are built, so a chart
 * drawn in light mode stayed light after a toggle. Read `themeVersion.value`
 * inside each chart computed: it changes only when dark mode actually flips,
 * and the charts rebuild in the new colours.
 */
export function useChartTheme() {
    const themeVersion = ref(0);
    let observer = null;
    let dark = false;

    onMounted(() => {
        dark = isDark();
        observer = new MutationObserver(() => {
            if (isDark() !== dark) {
                dark = isDark();
                themeVersion.value += 1;
            }
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { themeVersion };
}
```

- [ ] **Step 2: Ranked bars in chartTheme**

Append to `resources/js/lib/chartTheme.js`:
```js
/**
 * A "which is biggest" bar series: one hue darkening with rank, not one
 * categorical colour per row. Rows must arrive sorted largest first.
 */
export function rankedBarDataset(label, values) {
    const ramp = sequential(6);

    return {
        label,
        data: values,
        backgroundColor: values.map((_, index) => ramp[Math.max(0, ramp.length - 1 - index)] ?? ramp[0]),
        borderRadius: 4,
        maxBarThickness: 24,
    };
}
```

- [ ] **Step 3: Verify**

Run: `npm run build` → succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Composables/useChartTheme.js resources/js/lib/chartTheme.js
git commit -m "feat(stats): charts recolour on theme toggle; ranked bar helper

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: ChartCard — labelled Chart|Table toggle, error + retry, CSV download

**Files:**
- Modify: `resources/js/Components/Dashboard/ChartCard.vue` (whole file below)

**Interfaces:**
- Consumes: `toCsv`, `downloadCsv` (Task 1), `useCurrency`.
- Produces (all optional, existing props unchanged): `error: string|boolean|null` (true → generic message), `onRetry: Function|null` (bind with `@retry`; Retry button only shown when bound), `csv: { filename: string, columns: Column[], rows: object[], totals?: object|null }|null`. Provides `statTableInScroller = true` to its subtree.
- Existing callers (`OverviewTab`, `FinanceTab`, `MembersTab`, `OperationsTab`, `Attendance/Stats`, `ActivityCharts`) pass raw `<table>`s into `#table` and must render exactly as before apart from the new toggle look.

- [ ] **Step 1: Replace the component**

`resources/js/Components/Dashboard/ChartCard.vue`:
```vue
<script setup>
import { computed, provide, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { useCurrency } from '@/Composables/useCurrency';
import { downloadCsv, toCsv } from '@/lib/statTable';

/**
 * The frame every chart sits in.
 *
 * Carries the states a chart actually has — loading, failed, empty, and
 * drawn — plus a table view. The table is not a nicety: three colours in the
 * palette sit below 3:1 contrast on the light surface, and the rule that
 * comes with them is that the values must be reachable as text.
 *
 * Switching to the table is local state only: no request, no filter change.
 * With `csv`, the panel also offers the table as a CSV file built from the
 * same rows.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    empty: { type: Boolean, default: false },
    emptyHint: { type: String, default: null },
    loading: { type: Boolean, default: false },
    height: { type: String, default: 'h-64' },
    hasTable: { type: Boolean, default: false },
    error: { type: [String, Boolean], default: null },
    onRetry: { type: Function, default: null },
    csv: { type: Object, default: null },
});

const { t } = useI18n();
const { currencyLabel } = useCurrency();

// StatTable inside the table box must not open a second scroll box.
provide('statTableInScroller', true);

const view = ref('chart');
const showTable = computed(() => view.value === 'table');

const VIEWS = [
    { key: 'chart', icon: 'dashboard', label: 'stats.view_chart' },
    { key: 'table', icon: 'clipboard', label: 'stats.view_table' },
];

const failed = computed(() => props.error !== null && props.error !== false && props.error !== '');
const errorText = computed(() => (typeof props.error === 'string' && props.error !== '' ? props.error : t('stats.load_failed')));
const canDownload = computed(() => !!props.csv?.rows?.length && !props.loading && !failed.value && !props.empty);

function switchView(event) {
    view.value = view.value === 'chart' ? 'table' : 'chart';
    const group = event.currentTarget.parentElement;
    requestAnimationFrame(() => group.querySelector('[aria-selected="true"]')?.focus());
}

function download() {
    downloadCsv(props.csv.filename, toCsv(props.csv.columns, props.csv.rows, {
        currency: currencyLabel.value,
        totals: props.csv.totals ?? null,
        totalLabel: t('stats.total'),
    }));
}
</script>

<template>
    <Card class="border-border/70 shadow-none">
        <CardHeader class="flex-row flex-wrap items-start justify-between gap-3 space-y-0 px-5 pb-2 pt-4">
            <div class="min-w-0">
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <CardDescription v-if="subtitle" class="mt-0.5">{{ subtitle }}</CardDescription>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <slot name="actions" />
                <IconButton
                    v-if="canDownload"
                    icon="download"
                    :label="t('stats.download_csv')"
                    variant="ghost"
                    size="sm"
                    @click="download"
                />
                <div
                    v-if="hasTable && !empty && !failed"
                    role="tablist"
                    :aria-label="t('stats.view')"
                    class="inline-flex rounded-lg bg-muted p-0.5 text-xs font-medium"
                >
                    <button
                        v-for="option in VIEWS"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :aria-selected="view === option.key"
                        :tabindex="view === option.key ? 0 : -1"
                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="view === option.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="view = option.key"
                        @keydown.left.prevent="switchView"
                        @keydown.right.prevent="switchView"
                    >
                        <Icon :name="option.icon" class="size-3.5" />
                        <span>{{ t(option.label) }}</span>
                    </button>
                </div>
            </div>
        </CardHeader>

        <CardContent class="px-5 pb-5">
            <div v-if="loading" :class="height" class="animate-pulse rounded-lg bg-muted/60" />

            <div
                v-else-if="failed"
                :class="height"
                role="alert"
                class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-rose-300/70 text-center dark:border-rose-500/30"
            >
                <p class="text-sm font-medium text-rose-700 dark:text-rose-400">{{ errorText }}</p>
                <button
                    v-if="onRetry"
                    type="button"
                    class="rounded text-xs font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    @click="onRetry()"
                >
                    {{ t('stats.retry') }}
                </button>
            </div>

            <div
                v-else-if="empty"
                :class="height"
                class="flex flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border text-center"
            >
                <p class="text-sm font-medium text-muted-foreground">{{ t('dashboard.no_data') }}</p>
                <p v-if="emptyHint" class="max-w-xs text-xs text-muted-foreground/80">{{ emptyHint }}</p>
            </div>

            <div v-else-if="showTable" class="max-h-72 overflow-auto rounded-lg border border-border/70">
                <slot name="table" />
            </div>

            <div v-else :class="height">
                <slot />
            </div>
        </CardContent>
    </Card>
</template>
```

`dashboard.toggle_table` becomes unused by this component; leave the key in the catalogs (removing keys is out of scope).

- [ ] **Step 2: Verify**

Run: `npm run build` → succeeds. `npm run i18n:check` → passes. `grep -rn "dashboard.toggle_table" resources/js` → only catalogs (and no other component depended on it).

- [ ] **Step 3: Browser smoke of existing callers**

Start the app (`php artisan serve --port=2026` in `D:\irnb-stats` with `npm run build` done, or the project's usual dev runner). Open `/dashboard` (Overview, Finance, Members, Operations tabs), `/attendance/stats`, `/users/activity`. For each ChartCard: toggle Chart ⇄ Table with mouse and with arrow keys; confirm no network request fires on toggle (DevTools Network), the raw tables still scroll inside the box, no console errors, RTL (ar) and dark mode look right.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Dashboard/ChartCard.vue
git commit -m "feat(stats): ChartCard labelled chart/table toggle, error state, CSV download

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Notify the Reports Center session**

`SendMessage` to `irnb-laravel-f0`: StatTable (`resources/js/Components/Stats/StatTable.vue`), `resources/js/lib/statTable.js` and the ChartCard toggle are committed on `feat/stats-redesign` at `<git rev-parse HEAD>`; prop contract as in spec §4; `stats.*` i18n keys added (13). It can rebase `feat/reports-r1` onto that commit.

---

### Task 5: `StatsToolbar.vue`

**Files:**
- Create: `resources/js/Components/Stats/StatsToolbar.vue`

**Interfaces:**
- Produces: `<StatsToolbar>` props `activeCount: number` (default 0), `loading: boolean`, `onReset: Function|null` (bind with `@reset`; Reset shown only when bound and `activeCount > 0`). Default slot = the page's filter controls; slot `actions` = page actions (export, settings…). Uses i18n `stats.filters`, `stats.active_filters`, existing `reset`.

- [ ] **Step 1: Create**

`resources/js/Components/Stats/StatsToolbar.vue`:
```vue
<script setup>
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';

/**
 * The bar a stats page's filters sit in. It owns the look — spacing, the
 * active-filter count, Reset, the busy state — never the filters: each page
 * keeps its own controls and the requests they make.
 */
defineProps({
    activeCount: { type: Number, default: 0 },
    loading: { type: Boolean, default: false },
    onReset: { type: Function, default: null },
});

const { t } = useI18n();
</script>

<template>
    <div
        role="toolbar"
        :aria-label="t('stats.filters')"
        :aria-busy="loading"
        class="flex flex-wrap items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2"
    >
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <slot />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span
                v-if="loading"
                class="size-4 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-primary"
                aria-hidden="true"
            />
            <span
                v-if="activeCount > 0"
                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold tabular-nums text-primary"
            >{{ t('stats.active_filters', { count: activeCount }) }}</span>
            <IconButton
                v-if="onReset && activeCount > 0"
                icon="filteroff"
                :label="t('reset')"
                variant="ghost"
                size="sm"
                @click="onReset()"
            />
            <slot name="actions" />
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verify + commit**

Run: `npm run build` → succeeds.
```bash
git add resources/js/Components/Stats/StatsToolbar.vue
git commit -m "feat(stats): StatsToolbar shell for stats page filters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Finance season deltas (backend)

**Files:**
- Modify: `app/Http/Controllers/FinanceController.php` (`index()` and a new private `seasonDeltas()`)
- Test: `tests/Feature/FinanceSeasonPageTest.php` (append two tests)

**Interfaces:**
- Consumes: `App\Services\Dashboard\Support\DeltaCalculator::compute(float $current, ?float $previous, string $direction = UP_GOOD): ?array{percent, raw, tone}`.
- Produces: Inertia prop `detail.deltas = { income: ?Delta, expense: ?Delta, net: ?Delta }`, `Delta = { percent: float, raw: float, tone: 'positive'|'negative'|'neutral' }`. All three `null` when the previous season is missing or its value is 0. Expense is DOWN_GOOD.

- [ ] **Step 1: Write the failing tests**

Append inside `FinanceSeasonPageTest` (add `use App\Models\FiscalYear;` to the imports):
```php
    #[Test]
    public function the_season_kpis_compare_with_the_season_before(): void
    {
        Carbon::setTestNow('2026-10-14');
        // The finance migration seeds a fiscal year for the current year; start from a clean slate.
        FiscalYear::query()->delete();
        FiscalYear::create(['year' => 2026, 'status' => 'closed', 'opening_balance' => 0, 'total_income' => 1000, 'total_expense' => 400]);
        FiscalYear::create(['year' => 2027, 'status' => 'open', 'opening_balance' => 600, 'total_income' => 1500, 'total_expense' => 300]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index', ['year' => 2027]))
            ->assertInertia(fn ($page) => $page
                // Whole percents come back as ints: Inertia props round-trip through JSON.
                ->where('detail.deltas.income.percent', 50)
                ->where('detail.deltas.income.tone', 'positive')
                // Spending less is the good direction.
                ->where('detail.deltas.expense.percent', -25)
                ->where('detail.deltas.expense.tone', 'positive')
                ->where('detail.deltas.net.percent', 100)
                ->where('detail.deltas.net.tone', 'positive'));
    }

    #[Test]
    public function the_first_season_has_nothing_to_compare_with(): void
    {
        Carbon::setTestNow('2026-10-14');
        // The finance migration seeds a fiscal year for the current year; start from a clean slate.
        FiscalYear::query()->delete();
        FiscalYear::create(['year' => 2027, 'status' => 'open', 'opening_balance' => 0, 'total_income' => 1500, 'total_expense' => 300]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.index', ['year' => 2027]))
            ->assertInertia(fn ($page) => $page
                ->where('detail.deltas.income', null)
                ->where('detail.deltas.expense', null)
                ->where('detail.deltas.net', null));
    }
```

(The finance migration seeds a `fiscal_years` row for the current year, hence the delete; `start_date`, `end_date`, `label` are nullable. No transactions are created, so `TransactionObserver` never recomputes these stored totals.)

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=FinanceSeasonPageTest`
Expected: the two new tests FAIL (`detail.deltas` missing); the existing test passes.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/FinanceController.php` add the import:
```php
use App\Services\Dashboard\Support\DeltaCalculator;
```

In `index()`, replace
```php
        $fiscalYear = FiscalYear::where('year', $selectedYear)->first();
```
with
```php
        $fiscalYear = FiscalYear::where('year', $selectedYear)->first();
        $detail = $fiscalYear ? $this->yearDetail($fiscalYear) : null;
        if ($detail !== null) {
            $detail['deltas'] = $this->seasonDeltas($detail, $years->firstWhere('year', $selectedYear - 1));
        }
```
and in the `Inertia::render` array replace
```php
            'detail' => $fiscalYear ? $this->yearDetail($fiscalYear) : null,
```
with
```php
            'detail' => $detail,
```

Add the method after `yearsWithDeletability()`:
```php
    /**
     * Change against the season before, from the season list already loaded —
     * no extra query. No previous season, or one with nothing booked, means no
     * badge rather than a made-up +100%.
     *
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>|null  $previous
     * @return array{income: ?array, expense: ?array, net: ?array}
     */
    private function seasonDeltas(array $detail, ?array $previous): array
    {
        return [
            'income' => DeltaCalculator::compute($detail['income'], $previous['total_income'] ?? null),
            'expense' => DeltaCalculator::compute($detail['expense'], $previous['total_expense'] ?? null, DeltaCalculator::DOWN_GOOD),
            'net' => DeltaCalculator::compute($detail['net'], $previous['net'] ?? null),
        ];
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --filter=Finance`
Expected: all PASS (new and existing finance tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FinanceController.php tests/Feature/FinanceSeasonPageTest.php
git commit -m "feat(finance): season KPIs compare with the previous season

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Finance/Index on the unified system (pilot)

**Files:**
- Modify: `resources/js/Pages/Finance/Index.vue` (whole file below)

**Interfaces:**
- Consumes: `StatTile` (`@/Components/Dashboard/StatTile.vue`), `ChartCard` (Task 4), `StatTable` (Task 2), `StatsToolbar` (Task 5), `useChartTheme` + `rankedBarDataset` (Task 3), `baseOptions`, `lineDataset`, `MONEY_IN`, `MONEY_OUT` from `chartTheme`, prop `detail.deltas` (Task 6).
- Keeps: every existing prop, the year switch request (`only: ['detail','selectedYear','accounts']`), the registers / print / settings actions and their permission check, the status banner, the accounts list, the all-years row click.
- Visual changes by design: money in/out lines use chartTheme slots (blue in, orange out — chartTheme's validated rule, replacing green/red); category doughnuts become ranked horizontal bars (same chart the dashboard uses for the same data); budget bars stay bars and gain a table view.

- [ ] **Step 1: Replace the page**

`resources/js/Pages/Finance/Index.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Bar, Line } from 'vue-chartjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import StatTable from '@/Components/Stats/StatTable.vue';
import StatsToolbar from '@/Components/Stats/StatsToolbar.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useCan } from '@/Composables/useCan';
import { useChartTheme } from '@/Composables/useChartTheme';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { baseOptions, lineDataset, MONEY_IN, MONEY_OUT, rankedBarDataset } from '@/lib/chartTheme';
import { sumColumn } from '@/lib/statTable';
import '@/lib/registerCharts';

const props = defineProps({
    years: { type: Array, default: () => [] },
    selectedYear: { type: Number, default: null },
    detail: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    allTime: { type: Object, default: () => ({ income: 0, expense: 0 }) },
});

const { t, locale } = useI18n();
const { accountLabel } = useFinanceAccountLabel();
const { formatMoney } = useFormatMoney();
const { can } = useCan();
const { themeVersion } = useChartTheme();

const rtl = computed(() => locale.value === 'ar');

function selectYear(year) {
    router.get(route('finance.index'), { year }, { preserveScroll: true, preserveState: true, only: ['detail', 'selectedYear', 'accounts'] });
}

const isClosed = computed(() => props.detail?.status === 'closed');
const season = computed(() => props.detail?.season_label ?? '');
const vsPrevious = computed(() => t('stats.vs_previous_season'));

// Monthly trend --------------------------------------------------------------
const monthFormatter = computed(() => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : (locale.value === 'fr' ? 'fr' : 'en'), { month: 'short' }));
const monthName = (m) => monthFormatter.value.format(new Date(2000, m - 1, 1));

const monthlyRows = computed(() => (props.detail?.monthly ?? []).map((m) => ({
    month: monthName(m.month),
    income: m.income,
    expense: m.expense,
    net: Math.round((m.income - m.expense) * 100) / 100,
})));
const monthlyEmpty = computed(() => !monthlyRows.value.some((row) => row.income || row.expense));

const monthlyChart = computed(() => {
    themeVersion.value;

    return {
        labels: monthlyRows.value.map((row) => row.month),
        datasets: [
            lineDataset(t('income'), monthlyRows.value.map((row) => row.income), MONEY_IN),
            lineDataset(t('expense'), monthlyRows.value.map((row) => row.expense), MONEY_OUT),
        ],
    };
});

const lineOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: rtl.value, money: formatMoney });
});

const monthlyColumns = computed(() => [
    { key: 'month', label: t('stats.month'), format: 'text' },
    { key: 'income', label: t('income'), format: 'money', total: 'sum' },
    { key: 'expense', label: t('expense'), format: 'money', total: 'sum' },
    { key: 'net', label: t('net'), format: 'money', total: 'sum' },
]);

// Categories -----------------------------------------------------------------
const categoryRows = (rows) => (rows ?? []).map((r) => ({
    id: r.category_id,
    name: r.name || t('other'),
    total: Number(r.total),
    count: Number(r.count),
}));
const incomeRows = computed(() => categoryRows(props.detail?.income_by_category));
const expenseRows = computed(() => categoryRows(props.detail?.expense_by_category));

function categoryChart(rows) {
    themeVersion.value;

    return {
        labels: rows.map((row) => row.name),
        datasets: [rankedBarDataset(t('amount'), rows.map((row) => row.total))],
    };
}
const incomeChart = computed(() => categoryChart(incomeRows.value));
const expenseChart = computed(() => categoryChart(expenseRows.value));

const barOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: rtl.value, horizontal: true, legend: false, money: formatMoney });
});

const categoryColumns = computed(() => [
    { key: 'name', label: t('category'), format: 'text', sortable: true },
    { key: 'count', label: t('transactions'), format: 'number', sortable: true, total: 'sum' },
    { key: 'total', label: t('amount'), format: 'money', sortable: true, total: 'sum' },
]);

// A category list grows the chart instead of squeezing the bars.
const barHeight = (rows) => (rows.length > 8 ? 'h-96' : 'h-64');

// Budget ---------------------------------------------------------------------
const budgetRows = computed(() => (props.detail?.budget ?? []).filter((b) => b.planned > 0).map((b) => ({
    ...b,
    used: Math.round((b.actual / b.planned) * 1000) / 10,
})));

function pct(actual, planned) {
    if (!planned || planned <= 0) return 0;

    return Math.min(100, Math.round((actual / planned) * 100));
}

const budgetColumns = computed(() => [
    { key: 'name', label: t('category'), format: 'text', sortable: true },
    { key: 'planned', label: t('planned'), format: 'money', sortable: true, total: 'sum' },
    { key: 'actual', label: t('actual'), format: 'money', sortable: true, total: 'sum' },
    { key: 'used', label: '%', format: 'percent', sortable: true },
]);

// Accounts + all years ---------------------------------------------------------
const currentBalance = computed(() => props.accounts.reduce((s, a) => s + Number(a.current_balance || 0), 0));

const yearColumns = computed(() => [
    { key: 'season_label', label: t('fiscal_year'), format: 'text' },
    { key: 'total_income', label: t('total_income'), format: 'money', total: 'sum' },
    { key: 'total_expense', label: t('total_expense'), format: 'money', total: 'sum' },
    { key: 'net', label: t('net'), format: 'money', total: 'sum' },
    { key: 'status', label: t('status'), format: 'text', align: 'center' },
]);

// One totals object per table, handed to both the table and its CSV, so the
// screen and the downloaded file can never disagree.
const totalsOf = (rows, keys) => Object.fromEntries(keys.map((key) => [key, sumColumn(rows, key)]));
const monthlyTotals = computed(() => totalsOf(monthlyRows.value, ['income', 'expense', 'net']));
const incomeTotals = computed(() => totalsOf(incomeRows.value, ['count', 'total']));
const expenseTotals = computed(() => totalsOf(expenseRows.value, ['count', 'total']));
const budgetTotals = computed(() => totalsOf(budgetRows.value, ['planned', 'actual']));
const yearTotals = computed(() => totalsOf(props.years, ['total_income', 'total_expense', 'net']));

const csvName = (title) => `${title} ${season.value}`;
</script>

<template>
    <Head :title="t('finance')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('finance') }}</h1>
        </template>

        <div class="space-y-6">
            <StatsToolbar>
                <div role="tablist" :aria-label="t('fiscal_year')" class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="y in years"
                        :key="y.id"
                        type="button"
                        role="tab"
                        :aria-selected="y.year === selectedYear"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="y.year === selectedYear ? 'bg-primary-600 text-white shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        @click="selectYear(y.year)"
                    >
                        {{ y.season_label }}
                        <span v-if="y.status === 'closed'" class="text-xs opacity-70"><Icon name="check" /></span>
                    </button>
                </div>
                <template #actions>
                    <IconButton :href="route('finance.registers.index')" icon="wallet" :label="t('cash_registers')" />
                    <IconButton :href="route('reports.financial', { year: selectedYear })" external target="_blank" icon="print" :label="t('export')" />
                    <IconButton v-if="can('finance', 'edit')" :href="route('finance.settings')" icon="settings" :label="t('finance_settings')" />
                </template>
            </StatsToolbar>

            <template v-if="detail">
                <!-- Status banner -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-2xl p-4 ring-1"
                    :class="isClosed ? 'bg-slate-100 ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700' : 'bg-emerald-50 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/25'"
                >
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl" :class="isClosed ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300'">
                            <Icon :name="isClosed ? 'archive' : 'check'" />
                        </span>
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ detail.season_label }} — {{ isClosed ? t('closed') : t('open') }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <template v-if="isClosed">{{ t('closing_balance') }}: {{ formatMoney(detail.closing_balance) }}<span v-if="detail.closed_by"> · {{ detail.closed_by }}</span></template>
                                <template v-else>{{ t('finance.opening_balance') }}: {{ formatMoney(detail.opening_balance) }}</template>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- KPIs -->
                <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('finance')">
                    <StatTile :label="t('finance.opening_balance')" :value="detail.opening_balance" format="money" icon="money" tone="neutral" />
                    <StatTile :label="t('total_income')" :value="detail.income" format="money" icon="arrow" tone="positive" :delta="detail.deltas?.income" :period-label="vsPrevious" />
                    <StatTile :label="t('total_expense')" :value="detail.expense" format="money" icon="transactions" tone="negative" :delta="detail.deltas?.expense" :period-label="vsPrevious" />
                    <StatTile :label="t('net_balance')" :value="detail.net" format="money" icon="check" :tone="detail.net >= 0 ? 'primary' : 'negative'" :delta="detail.deltas?.net" :period-label="vsPrevious" />
                </section>

                <!-- Trend + balances -->
                <section class="grid gap-4 lg:grid-cols-3">
                    <ChartCard
                        class="lg:col-span-2"
                        :title="t('monthly_trend')"
                        :subtitle="season"
                        :empty="monthlyEmpty"
                        has-table
                        :csv="{ filename: csvName(t('monthly_trend')), columns: monthlyColumns, rows: monthlyRows, totals: monthlyTotals }"
                    >
                        <Line :data="monthlyChart" :options="lineOptions" />
                        <template #table>
                            <StatTable :columns="monthlyColumns" :rows="monthlyRows" :totals="monthlyTotals" dense />
                        </template>
                    </ChartCard>

                    <div class="card p-5">
                        <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('current_balance') }}</p>
                        <p class="figure text-3xl font-extrabold text-primary-700 dark:text-primary-400">{{ formatMoney(currentBalance) }}</p>
                        <ul class="mt-4 space-y-2">
                            <li v-for="a in accounts" :key="a.id" class="flex items-center justify-between text-sm">
                                <span class="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300">
                                    <Icon name="money" class="shrink-0 text-slate-400" />
                                    <span class="truncate">{{ accountLabel(a) }}</span>
                                </span>
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ formatMoney(a.current_balance) }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Category breakdown -->
                <section class="grid gap-4 lg:grid-cols-2">
                    <ChartCard
                        :title="t('income_categories')"
                        :subtitle="season"
                        :empty="!incomeRows.length"
                        :height="barHeight(incomeRows)"
                        has-table
                        :csv="{ filename: csvName(t('income_categories')), columns: categoryColumns, rows: incomeRows, totals: incomeTotals }"
                    >
                        <Bar :data="incomeChart" :options="barOptions" />
                        <template #table>
                            <StatTable :columns="categoryColumns" :rows="incomeRows" row-key="id" :totals="incomeTotals" dense />
                        </template>
                    </ChartCard>
                    <ChartCard
                        :title="t('expense_categories')"
                        :subtitle="season"
                        :empty="!expenseRows.length"
                        :height="barHeight(expenseRows)"
                        has-table
                        :csv="{ filename: csvName(t('expense_categories')), columns: categoryColumns, rows: expenseRows, totals: expenseTotals }"
                    >
                        <Bar :data="expenseChart" :options="barOptions" />
                        <template #table>
                            <StatTable :columns="categoryColumns" :rows="expenseRows" row-key="id" :totals="expenseTotals" dense />
                        </template>
                    </ChartCard>
                </section>

                <!-- Budget vs actual -->
                <ChartCard
                    v-if="budgetRows.length"
                    :title="t('budget_vs_actual')"
                    :subtitle="season"
                    height="h-auto"
                    has-table
                    :csv="{ filename: csvName(t('budget_vs_actual')), columns: budgetColumns, rows: budgetRows, totals: budgetTotals }"
                >
                    <div class="space-y-3">
                        <div v-for="b in budgetRows" :key="b.category_id">
                            <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                                <span class="font-medium text-foreground">{{ b.name }}</span>
                                <span class="whitespace-nowrap tabular-nums text-muted-foreground"><bdi>{{ formatMoney(b.actual) }}</bdi> / <bdi>{{ formatMoney(b.planned) }}</bdi></span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="pct(b.actual, b.planned)" aria-valuemin="0" aria-valuemax="100" :aria-label="b.name">
                                <div class="h-full rounded-full" :class="b.type === 'expense' && b.actual > b.planned ? 'bg-rose-500' : 'bg-primary-500'" :style="{ width: pct(b.actual, b.planned) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                    <template #table>
                        <StatTable :columns="budgetColumns" :rows="budgetRows" row-key="category_id" :totals="budgetTotals" dense />
                    </template>
                </ChartCard>
            </template>

            <!-- All years overview -->
            <ChartCard
                :title="t('all_years')"
                :empty="!years.length"
                height="h-auto"
                :csv="{ filename: t('all_years'), columns: yearColumns, rows: years, totals: yearTotals }"
            >
                <div class="overflow-x-auto rounded-lg border border-border/70">
                    <StatTable
                        :columns="yearColumns"
                        :rows="years"
                        row-key="id"
                        :totals="yearTotals"
                        clickable
                        :row-class="(y) => (y.year === selectedYear ? 'bg-primary-50/60 dark:bg-primary-500/5' : '')"
                        @row-click="(y) => selectYear(y.year)"
                    >
                        <template #cell-status="{ row }">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold" :class="row.status === 'closed' ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'">{{ row.status === 'closed' ? t('closed') : t('open') }}</span>
                        </template>
                    </StatTable>
                </div>
            </ChartCard>
        </div>
    </AuthenticatedLayout>
</template>
```

Notes:
- `t('transactions')`, `t('other')`, `t('amount')`, `t('category')`, `t('planned')`, `t('actual')`, `t('net')` all exist in the catalogs already.
- The all-years card has no `has-table` (it already *is* a table) but keeps the CSV button; `height="h-auto"` lets the default slot size to content. ChartCard provides `statTableInScroller` to its whole subtree, so a StatTable in the default slot also drops its own scroll box; the explicit `overflow-x-auto` wrapper keeps the table from widening the page on phones, and the card grows with the rows (a handful of seasons).
- Budget `used` is a percent, so it has no total.
- Template refs inside `:csv="{ … }"` and `:totals` auto-unwrap computeds (`monthlyTotals` is a ref at script level, a plain object in the template).

- [ ] **Step 2: Build + tests**

Run: `npm run build` → succeeds. `npm run i18n:check` → passes. `php artisan test --filter=Finance` → all PASS.

- [ ] **Step 3: Browser QA (owner-check prep)**

At `/finance` (admin, then a user with finance view but not edit):
- Season tabs switch: one request with `only` (Network), KPIs update, delta badges show with the "vs previous season" title, none on the first season.
- Each ChartCard: Chart ⇄ Table toggles with no request; table sorts by clicking headers (numbers biggest first on first click), totals row correct against the chart values; CSV downloads, opens in Excel with Arabic text intact and currency in the money headers.
- All-years table row click switches season; selected row highlighted; keyboard Enter works.
- Settings button hidden for the view-only user; print link opens the financial report.
- ar (RTL), fr, en; light and dark — toggle dark with the page open and confirm charts recolour; width 375px: no page-level horizontal scroll, tables scroll inside their card.
- Console clean (no Vue warnings).

Desktop note: confirm the CSV download from a blob URL also works in the packaged NativePHP build during the next desktop build (record result; not a blocker for merge).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Finance/Index.vue
git commit -m "feat(finance): season page on the unified stats panels

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Full verification + stop**

Run: `php artisan test` (full suite), `npm run test:js`, `npm run build`, `npm run i18n:check` — record counts vs Task 0 baseline. Report to the owner with screenshots of Finance/Index (light/dark, ar) and stop for the Phase 1 check. Do not merge to main without owner approval.
