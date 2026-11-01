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
import { useFormatNumber } from '@/Composables/useFormatNumber';
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
const { formatPercent } = useFormatNumber();
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
    typeLabel: b.type === 'expense' ? t('expense') : t('income'),
    used: Math.round((b.actual / b.planned) * 1000) / 10,
})));

function pct(actual, planned) {
    if (!planned || planned <= 0) return 0;

    return Math.min(100, Math.round((actual / planned) * 100));
}

const budgetColumns = computed(() => [
    { key: 'name', label: t('category'), format: 'text', sortable: true },
    { key: 'typeLabel', label: t('stats.type'), format: 'text', sortable: true },
    { key: 'planned', label: t('planned'), format: 'money', sortable: true },
    { key: 'actual', label: t('actual'), format: 'money', sortable: true },
    { key: 'used', label: '%', format: 'percent', sortable: true },
]);

// Accounts + all years ---------------------------------------------------------
const currentBalance = computed(() => props.accounts.reduce((s, a) => s + Number(a.current_balance || 0), 0));

const yearColumns = computed(() => [
    { key: 'season_label', label: t('fiscal_year'), format: 'text' },
    { key: 'total_income', label: t('total_income'), format: 'money', total: 'sum' },
    { key: 'total_expense', label: t('total_expense'), format: 'money', total: 'sum' },
    { key: 'net', label: t('net'), format: 'money', total: 'sum' },
    { key: 'status', label: t('status'), format: 'text', align: 'center', csv: (row) => (row.status === 'closed' ? t('closed') : t('open')) },
]);

// One totals object per table, handed to both the table and its CSV, so the
// screen and the downloaded file can never disagree.
const totalsOf = (rows, keys) => Object.fromEntries(keys.map((key) => [key, sumColumn(rows, key)]));
const monthlyTotals = computed(() => totalsOf(monthlyRows.value, ['income', 'expense', 'net']));
const incomeTotals = computed(() => totalsOf(incomeRows.value, ['count', 'total']));
const expenseTotals = computed(() => totalsOf(expenseRows.value, ['count', 'total']));
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
                <div role="group" :aria-label="t('fiscal_year')" class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="y in years"
                        :key="y.id"
                        type="button"
                        :aria-pressed="y.year === selectedYear"
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
                            <StatTable :columns="categoryColumns" :rows="incomeRows" :row-key="(r) => r.id ?? 'uncategorised'" :totals="incomeTotals" dense />
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
                            <StatTable :columns="categoryColumns" :rows="expenseRows" :row-key="(r) => r.id ?? 'uncategorised'" :totals="expenseTotals" dense />
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
                    :csv="{ filename: csvName(t('budget_vs_actual')), columns: budgetColumns, rows: budgetRows }"
                >
                    <div class="space-y-3">
                        <div v-for="b in budgetRows" :key="b.category_id">
                            <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                                <span class="font-medium text-foreground">{{ b.name }}</span>
                                <span class="whitespace-nowrap tabular-nums text-muted-foreground"><bdi>{{ formatMoney(b.actual) }}</bdi> / <bdi>{{ formatMoney(b.planned) }}</bdi></span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="pct(b.actual, b.planned)" aria-valuemin="0" aria-valuemax="100" :aria-label="b.name" :aria-valuetext="`${formatMoney(b.actual)} / ${formatMoney(b.planned)} (${formatPercent(b.used)})`">
                                <div class="h-full rounded-full" :class="b.type === 'expense' && b.actual > b.planned ? 'bg-rose-500' : 'bg-primary-500'" :style="{ width: pct(b.actual, b.planned) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                    <template #table>
                        <StatTable :columns="budgetColumns" :rows="budgetRows" row-key="category_id" dense>
                            <template #cell-typeLabel="{ row }">{{ row.typeLabel }}</template>
                        </StatTable>
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
