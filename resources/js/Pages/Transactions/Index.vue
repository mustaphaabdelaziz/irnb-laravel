<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import StatBars from '@/Components/StatBars.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import CategoryManager from '@/Components/CategoryManager.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { asList, useListFilters } from '@/Composables/useListFilters';
import { useBulkSelection } from '@/Composables/useBulkSelection';
import { useCan } from '@/Composables/useCan';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { readJson, writeJson } from '@/lib/safeStorage';

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    strip: { type: Array, default: () => [] },
    incomeByCategory: { type: Array, default: () => [] },
    expenseByCategory: { type: Array, default: () => [] },
    byAccount: { type: Array, default: () => [] },
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    currentSeason: { type: Number, default: null },
});

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();
const { accountLabel } = useFinanceAccountLabel();

// --- Filters: each lives in the URL and has a control (or a chip) here ---
const search = ref(props.filters?.search || '');
const typeFilter = ref(asList(props.filters?.type));
const categoryFilter = ref(asList(props.filters?.finance_category_id));
const accountFilter = ref(asList(props.filters?.finance_account_id));
// Payment status has no menu — nearly every entry is Paid — but a link may
// carry it; it then shows as a removable chip below the filters.
const statusFilter = ref(asList(props.filters?.status));
// '' = the server default: the current season, or every season once a date
// range decides which days to show. Otherwise 'all' or one season key.
const seasonFilter = ref(asList(props.filters?.fiscal_year)[0] ?? '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
// Page size; empty = the server default (25), kept out of the URL. The server
// always echoes the size it applied, so its '25' maps back to empty here.
// A view preference, not a filter.
const DEFAULT_PER_PAGE = '25';
const echoedPerPage = props.filters?.per_page ? String(props.filters.per_page) : '';
const perPage = ref(echoedPerPage === DEFAULT_PER_PAGE ? '' : echoedPerPage);

const hasDates = computed(() => !!(dateFrom.value || dateTo.value));
const currentSeasonKey = computed(() => (props.currentSeason ? String(props.currentSeason) : ''));
// The select always names what the list shows, the default included.
const seasonSelect = computed({
    get: () => seasonFilter.value || (hasDates.value ? 'all' : currentSeasonKey.value),
    set: (value) => {
        const isDefault = hasDates.value ? value === 'all' : value === currentSeasonKey.value;
        seasonFilter.value = isDefault ? '' : value;
    },
});

const { params: filterParams, loading: filtering } = useListFilters('transactions.index', () => ({
    search: search.value,
    type: typeFilter.value,
    finance_category_id: categoryFilter.value,
    finance_account_id: accountFilter.value,
    status: statusFilter.value,
    fiscal_year: seasonFilter.value,
    date_from: dateFrom.value,
    date_to: dateTo.value,
    per_page: perPage.value,
}), { only: ['transactions', 'filters', 'strip', 'incomeByCategory', 'expenseByCategory', 'byAccount'] });

const hasActiveFilters = computed(() => Object.keys(filterParams.value).some((k) => k !== 'per_page'));

function clearFilters() {
    search.value = '';
    typeFilter.value = [];
    categoryFilter.value = [];
    accountFilter.value = [];
    statusFilter.value = [];
    seasonFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
}

// A reload changes the height of the statistics above the filter bar, which
// would slide the bar (and the box being typed in). Measure before the new
// rows render and scroll back by the difference — as on Players.
const filterBar = ref(null);
let filterBarTop = null;
watch(() => props.transactions, () => {
    filterBarTop = filterBar.value?.getBoundingClientRect().top ?? null;
}, { flush: 'pre' });
watch(() => props.transactions, () => {
    const top = filterBar.value?.getBoundingClientRect().top;
    if (filterBarTop === null || top === undefined) return;
    const shift = top - filterBarTop;
    if (Math.abs(shift) >= 1) window.scrollBy({ top: shift, behavior: 'instant' });
}, { flush: 'post' });

// --- Filter options ---
const typeOptions = computed(() => [
    { value: 'income', label: t('income') },
    { value: 'expense', label: t('expense') },
]);
const categoryOptions = computed(() => ['income', 'expense'].flatMap((type) => props.financeCategories
    .filter((c) => c.type === type)
    .map((c) => ({ value: c.id, label: c.localized_name || c.name, group: type === 'income' ? t('income') : t('expense') }))));
const accountOptions = computed(() => props.financeAccounts.map((a) => ({ value: a.id, label: accountLabel(a) })));

// --- Statistics (folded state remembered per browser) ---
const STATS_KEY = 'transactions.stats';
const statsOpen = ref(readJson(STATS_KEY, true) !== false);
watch(statsOpen, (open) => writeJson(STATS_KEY, open));

const incomeChips = computed(() => props.incomeByCategory.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.uncategorised'), count: r.count })));
const expenseChips = computed(() => props.expenseByCategory.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.uncategorised'), count: r.count })));
const accountChips = computed(() => props.byAccount.map((r) => ({ key: r.key ?? '', label: r.label || t('tx.no_caisse'), count: r.count })));

// Both category cards drive the one category filter, but each reads and
// writes only its own type's ids: one card's Clear leaves the other's alone.
function categoryCardModel(type, chips) {
    const ids = computed(() => new Set([
        ...props.financeCategories.filter((c) => c.type === type).map((c) => String(c.id)),
        ...chips.value.map((c) => String(c.key)).filter((k) => k !== ''),
    ]));
    return computed({
        get: () => categoryFilter.value.filter((id) => ids.value.has(String(id))),
        set: (list) => {
            categoryFilter.value = [
                ...categoryFilter.value.filter((id) => !ids.value.has(String(id))),
                ...list.map(String),
            ];
        },
    });
}
const incomeCategoryFilter = categoryCardModel('income', incomeChips);
const expenseCategoryFilter = categoryCardModel('expense', expenseChips);

// --- Header "more" menu ---
const FORMATS = ['xlsx', 'csv'];
const withFormat = (href, format) => {
    const url = new URL(href, window.location.origin);
    url.searchParams.set('format', format);
    return url.pathname + url.search;
};
// The export matches the current view: the same filters, season and dates
// included (the server applies the same current-season default). The page
// size is a view preference and stays out.
const exportUrl = computed(() => {
    const { per_page: _perPage, ...params } = filterParams.value;
    return route('transactions.export', params);
});
const menuItem = 'flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm text-slate-700 transition-colors hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:bg-slate-700';
const menuHeading = 'px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500';
const menuIcon = 'text-base text-slate-400 dark:text-slate-500';
const fieldClass = 'h-9 w-full rounded-lg border-slate-300 bg-white py-1 text-sm text-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200';

const showCategories = ref(false);

const importInput = ref(null);
function onImport(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('transactions.import.store'), { file }, {
        forceFormData: true,
        onFinish: () => { if (importInput.value) importInput.value.value = ''; },
    });
}

// --- Rows ---
const isIncome = (tx) => tx.transaction_type === 'income';
const signedAmount = (tx) => `${isIncome(tx) ? '+' : '−'}${formatMoney(tx.amount)}`;
const amountClass = (tx) => (isIncome(tx) ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400');
const categoryName = (tx) => tx.finance_category?.localized_name || tx.finance_category?.name || t('tx.uncategorised');
const caisseName = (tx) => (tx.finance_account ? accountLabel(tx.finance_account) : t('tx.no_caisse'));
const typeLabel = (tx) => (isIncome(tx) ? t('income') : t('expense'));
// The server's title; never the stored slug — else the type, as on the server.
const titleOf = (tx) => tx.display_title || typeLabel(tx);

const canEdit = computed(() => can('transactions', 'edit'));
const canDelete = computed(() => can('transactions', 'delete'));

// --- Delete (one, or the selection) ---
const deleteId = ref(null);
function destroy() {
    const id = deleteId.value;
    deleteId.value = null;
    router.delete(route('transactions.destroy', id), { preserveScroll: true });
}

// The selection resets whenever the filters replace the rows.
const { selected, allSelected, toggleAll, toggleOne, clear: clearSelection } = useBulkSelection(
    computed(() => props.transactions.data),
    filterParams,
);
const bulkDeletePending = ref(false);
function bulkDestroy() {
    bulkDeletePending.value = false;
    router.post(route('transactions.bulkDestroy'), { ids: selected.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    });
}
</script>

<template>
    <Head :title="t('transactions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="min-w-0 truncate text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">{{ t('transactions') }}</h1>
                <div class="flex shrink-0 items-center gap-2">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <IconButton icon="more" :label="t('more_actions')" />
                        </template>
                        <template #content>
                            <div class="py-1">
                                <template v-if="can('transactions', 'add')">
                                    <button type="button" :class="menuItem" @click="importInput?.click()">
                                        <Icon name="upload" :class="menuIcon" /> {{ t('import') }}
                                    </button>
                                    <p :class="menuHeading">{{ t('template') }}</p>
                                    <a v-for="f in FORMATS" :key="`tpl-${f}`" :href="withFormat(route('transactions.import.template'), f)" download :class="menuItem">
                                        <Icon name="document" :class="menuIcon" /> {{ t(`export.format.${f}`) }}
                                    </a>
                                </template>
                                <p :class="menuHeading">{{ t('export') }}</p>
                                <a v-for="f in FORMATS" :key="`exp-${f}`" :href="withFormat(exportUrl, f)" download :class="menuItem">
                                    <Icon name="download" :class="menuIcon" /> {{ t(`export.format.${f}`) }}
                                </a>
                                <button v-if="can('finance', 'edit')" type="button" :class="[menuItem, 'mt-1 border-t border-slate-100 dark:border-slate-700']" @click="showCategories = true">
                                    <Icon name="categories" :class="menuIcon" /> {{ t('fincat.title') }}
                                </button>
                            </div>
                        </template>
                    </Dropdown>
                    <input ref="importInput" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" @change="onImport" />
                    <IconButton v-if="can('transactions', 'add')" :href="route('transactions.create')" icon="plus" :label="t('add_transaction')" variant="primary" />
                </div>
            </div>
        </template>

        <StatStrip :tiles="strip" class="mb-4" />

        <div class="space-y-4">
            <!-- Statistics: where the money came from and went, and which caisses were used. Click a bar to filter. -->
            <section class="space-y-3">
                <button type="button" :aria-expanded="statsOpen" aria-controls="transaction-stats" @click="statsOpen = !statsOpen"
                    class="inline-flex items-center gap-1.5 rounded-md text-sm font-semibold text-slate-600 transition-colors hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:text-slate-300 dark:hover:text-white dark:focus-visible:ring-offset-slate-950">
                    <Icon name="chevron" class="text-base transition-transform" :class="statsOpen ? '' : '-rotate-90 rtl:rotate-90'" />
                    {{ t('statistics') }}
                </button>
                <div v-if="statsOpen" id="transaction-stats" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <StatBars v-model="incomeCategoryFilter" :title="t('tx.by_income_category')" :stats="incomeChips" format="money" :unit="t('currency_symbol')" />
                    <StatBars v-model="expenseCategoryFilter" :title="t('tx.by_expense_category')" :stats="expenseChips" format="money" :unit="t('currency_symbol')" />
                    <StatBars v-model="accountFilter" :title="t('tx.by_caisse')" :stats="accountChips" :unit="t('tx.entries')" class="md:col-span-2 xl:col-span-1" />
                </div>
            </section>

            <!-- Filters -->
            <div ref="filterBar" class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :loading="filtering" :placeholder="t('search')" />
                </div>
                <MultiSelectFilter v-model="typeFilter" collapse-all :options="typeOptions" :label="t('type')" :placeholder="`${t('type')}: ${t('all')}`" class="min-w-0 flex-1 sm:w-40 sm:flex-none" />
                <MultiSelectFilter v-model="categoryFilter" :options="categoryOptions" :label="t('category')" :placeholder="t('all_categories')" class="min-w-0 flex-1 sm:w-52 sm:flex-none" />
                <MultiSelectFilter v-model="accountFilter" :options="accountOptions" :label="t('cash_register')" :placeholder="t('all_cash_registers')" class="min-w-0 flex-1 sm:w-52 sm:flex-none" />
                <label class="min-w-0 flex-1 sm:w-48 sm:flex-none">
                    <span class="sr-only">{{ t('season') }}</span>
                    <select v-model="seasonSelect" :class="fieldClass">
                        <option value="all">{{ t('tx.all_seasons') }}</option>
                        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ t('tx.season_option', { season: s.label }) }}</option>
                    </select>
                </label>
                <label class="flex min-w-[11rem] flex-1 items-center gap-2 text-sm text-slate-500 dark:text-slate-400 sm:min-w-0 sm:flex-none">
                    <span class="shrink-0">{{ t('tx.date_from') }}</span>
                    <input v-model="dateFrom" type="date" :max="dateTo || undefined" :class="fieldClass" />
                </label>
                <label class="flex min-w-[11rem] flex-1 items-center gap-2 text-sm text-slate-500 dark:text-slate-400 sm:min-w-0 sm:flex-none">
                    <span class="shrink-0">{{ t('tx.date_to') }}</span>
                    <input v-model="dateTo" type="date" :min="dateFrom || undefined" :class="fieldClass" />
                </label>
                <IconButton v-if="hasActiveFilters" icon="filteroff" :label="t('clear_filters')" variant="danger" @click="clearFilters" />
            </div>

            <!-- A filter with no menu here (payment status, from a link) shows as a chip: nothing filters out of sight. -->
            <div v-if="statusFilter.length" class="flex flex-wrap items-center gap-2">
                <button v-for="s in statusFilter" :key="s" type="button"
                    :aria-label="`${t('tx.remove_filter')}: ${t('tx.status_chip', { status: statusLabel('payment', s) })}`"
                    class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700 ring-1 ring-primary-200 transition-colors hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/30 dark:hover:bg-primary-500/20"
                    @click="statusFilter = statusFilter.filter((v) => v !== s)">
                    {{ t('tx.status_chip', { status: statusLabel('payment', s) }) }}
                    <Icon name="close" class="text-sm" />
                </button>
            </div>

            <!-- Results. With a filter on they keep at least a screen of height so a short result does not drag the scroll up. -->
            <div class="space-y-4" :class="{ 'min-h-screen': hasActiveFilters }">
                <div v-if="selected.length" class="flex flex-wrap items-center gap-3 rounded-xl bg-primary-50 px-4 py-2.5 ring-1 ring-primary-200 dark:bg-primary-900/20 dark:ring-primary-800">
                    <span class="text-sm font-medium text-primary-800 dark:text-primary-200">{{ t('selected_count', { count: selected.length }) }}</span>
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <IconButton icon="close" :label="t('cancel')" variant="ghost" @click="clearSelection" />
                        <IconButton icon="trash" :label="t('delete_selected')" variant="danger" @click="bulkDeletePending = true" />
                    </div>
                </div>

                <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                    <!-- Phones: one card per entry -->
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                        <li v-for="tx in transactions.data" :key="tx.id" class="flex gap-3 px-4 py-3"
                            :class="selected.includes(tx.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                            <input v-if="canDelete" type="checkbox" :checked="selected.includes(tx.id)" :aria-label="t('select_row')" @change="toggleOne(tx.id)"
                                class="mt-1 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex items-start justify-between gap-3">
                                    <Link :href="route('transactions.show', tx.id)" class="min-w-0 break-words text-sm font-semibold text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-400">{{ titleOf(tx) }}</Link>
                                    <span class="shrink-0 text-sm font-bold tabular-nums" :class="amountClass(tx)" dir="ltr">{{ signedAmount(tx) }}</span>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(tx.transaction_date) }} · {{ caisseName(tx) }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: tx.finance_category?.color || '#94a3b8' }" aria-hidden="true"></span>
                                        {{ categoryName(tx) }}
                                    </span>
                                    <Link v-if="tx.player_summary" :href="route('players.show', tx.player_summary.id)" class="text-xs text-primary-600 hover:underline dark:text-primary-400">{{ tx.player_summary.name }}</Link>
                                    <div class="ms-auto flex items-center gap-1">
                                        <IconButton v-if="canEdit" :href="route('transactions.edit', tx.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                                        <IconButton v-if="canDelete" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = tx.id" />
                                    </div>
                                </div>
                            </div>
                        </li>
                        <li v-if="!transactions.data.length" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</li>
                    </ul>

                    <!-- md and up: the table -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                            <thead class="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th v-if="canDelete" class="w-10 px-4 py-3">
                                        <input type="checkbox" :checked="allSelected" :aria-label="t('select_all')" @change="toggleAll"
                                            class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('type') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('title') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('cash_register') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('player') }}</th>
                                    <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                    <!-- Pinned to the end edge so edit/delete stay reachable however far the row scrolls. -->
                                    <th class="sticky end-0 bg-slate-50 px-3 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-[-8px_0_8px_-8px_rgba(15,23,42,0.15)] rtl:shadow-[8px_0_8px_-8px_rgba(15,23,42,0.15)] dark:bg-slate-950 dark:text-slate-400">{{ t('actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="tx in transactions.data" :key="tx.id" class="group"
                                    :class="selected.includes(tx.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800'">
                                    <td v-if="canDelete" class="w-10 px-4 py-3">
                                        <input type="checkbox" :checked="selected.includes(tx.id)" :aria-label="t('select_row')" @change="toggleOne(tx.id)"
                                            class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ formatDate(tx.transaction_date) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :label="typeLabel(tx)" :color="isIncome(tx) ? 'emerald' : 'rose'" />
                                    </td>
                                    <td class="max-w-xs px-4 py-3 text-sm">
                                        <Link :href="route('transactions.show', tx.id)" class="block truncate font-medium text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-400">{{ titleOf(tx) }}</Link>
                                        <span v-if="tx.description" class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tx.description }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: tx.finance_category?.color || '#94a3b8' }" aria-hidden="true"></span>
                                            {{ categoryName(tx) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ caisseName(tx) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm">
                                        <template v-if="tx.player_summary">
                                            <Link :href="route('players.show', tx.player_summary.id)" class="text-primary-600 hover:underline dark:text-primary-400">{{ tx.player_summary.name }}</Link>
                                            <span class="block font-mono text-xs text-slate-400">{{ tx.player_summary.membership_id }}</span>
                                        </template>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold tabular-nums" :class="amountClass(tx)">
                                        <span dir="ltr">{{ signedAmount(tx) }}</span>
                                    </td>
                                    <td class="sticky end-0 whitespace-nowrap bg-white px-3 py-2 text-end shadow-[-8px_0_8px_-8px_rgba(15,23,42,0.15)] group-hover:bg-slate-50 rtl:shadow-[8px_0_8px_-8px_rgba(15,23,42,0.15)] dark:bg-slate-900 dark:group-hover:bg-slate-800">
                                        <div class="flex items-center justify-end gap-1">
                                            <IconButton :href="route('transactions.show', tx.id)" icon="eye" :label="t('details')" variant="primary" plain size="sm" />
                                            <IconButton v-if="canEdit" :href="route('transactions.edit', tx.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                                            <IconButton v-if="canDelete" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = tx.id" />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!transactions.data.length">
                                    <td :colspan="canDelete ? 9 : 8" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                            <select v-model="perPage" class="rounded-lg border-slate-300 py-1 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                <option value="">25</option>
                                <option v-for="n in [50, 100, 200]" :key="n" :value="String(n)">{{ n }}</option>
                            </select>
                            {{ t('per_page') }}
                        </label>
                        <Pagination :links="transactions" class="min-w-0 flex-1" />
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" variant="danger" :title="t('tx.delete_title')" :message="t('tx.delete_message')" @confirm="destroy" @cancel="deleteId = null" />
        <ConfirmModal :show="bulkDeletePending" variant="danger" :title="t('tx.bulk_delete_title')" :message="t('tx.bulk_delete_message', { count: selected.length })" @confirm="bulkDestroy" @cancel="bulkDeletePending = false" />

        <CategoryManager :show="showCategories" :categories="financeCategories" @close="showCategories = false" />
    </AuthenticatedLayout>
</template>
