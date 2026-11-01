<script setup>
import { computed, inject, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatNumber } from '@/Composables/useFormatNumber';
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
 *
 * A controlled `sort` prop means the parent must echo the emitted `sort` back.
 * The totals label goes in the first column, so don't make that column summable.
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
const { formatNumber, formatPercent } = useFormatNumber();
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

// Each cell is formatted once here, not once per template reference.
const bodyRows = computed(() =>
    displayRows.value.map((row, index) => ({
        row,
        key: keyOf(row, index),
        cells: props.columns.map((column) => ({ column, value: row[column.key], text: format(column, row[column.key]) })),
    })),
);

const totalsRow = computed(() => {
    if (!props.totals) return null;
    if (typeof props.totals === 'object') return props.totals;

    return Object.fromEntries(
        props.columns.filter((column) => column.total === 'sum').map((column) => [column.key, sumColumn(props.rows, column.key)]),
    );
});

function format(column, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (column.format === 'money') return formatMoney(value);
    if (column.format === 'percent') return formatPercent(value);
    if (column.format === 'number') return formatNumber(value);
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

function onRowActivate(event, row) {
    if (!props.clickable) return;

    // A link or button inside a cell does its own job; it must not also open the row.
    const inner = event.target.closest?.('a, button, input, select, textarea, label');
    if (inner && inner !== event.currentTarget && event.currentTarget.contains(inner)) return;

    emit('row-click', row);
}

const cellPad = computed(() => (props.dense ? 'px-3 py-1.5' : 'px-3 py-2.5'));
</script>

<template>
    <div>
        <div :class="inScroller ? '' : ['overflow-auto', maxHeight]">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :aria-sort="ariaSort(column)"
                        class="sticky top-0 z-10 whitespace-nowrap bg-card text-xs shadow-[inset_0_-1px_0_hsl(var(--border))] font-semibold text-muted-foreground"
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
                    v-for="{ row, key, cells } in bodyRows"
                    :key="key"
                    class="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/40"
                    :class="[clickable ? 'cursor-pointer focus-visible:bg-muted/60 focus-visible:outline-none' : '', rowClass?.(row)]"
                    :tabindex="clickable ? 0 : undefined"
                    @click="onRowActivate($event, row)"
                    @keydown.enter="onRowActivate($event, row)"
                >
                    <td
                        v-for="{ column, value, text } in cells"
                        :key="column.key"
                        :class="[cellPad, alignClass(column), isNumeric(column) ? 'whitespace-nowrap tabular-nums' : '']"
                    >
                        <slot :name="`cell-${column.key}`" :row="row" :value="value" :formatted="text">
                            <template v-if="isNumeric(column)"><bdi>{{ text }}</bdi><span v-if="column.format === 'money' && text !== '—'" class="ms-1 text-xs text-muted-foreground">{{ currencyLabel }}</span></template>
                            <template v-else>{{ text }}</template>
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
                            <bdi>{{ format(column, totalsRow[column.key]) }}</bdi><span v-if="column.format === 'money' && format(column, totalsRow[column.key]) !== '—'" class="ms-1 text-xs font-normal text-muted-foreground">{{ currencyLabel }}</span>
                        </template>
                        <template v-else-if="index === 0">{{ totalLabel ?? t('stats.total') }}</template>
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>
        <slot name="footer" />
    </div>
</template>
