<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Icon from '@/Components/Icon.vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import '@/lib/registerCharts';
import { Line, Doughnut } from 'vue-chartjs';

const props = defineProps({
    years: { type: Array, default: () => [] },
    selectedYear: { type: Number, default: null },
    detail: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    allTime: { type: Object, default: () => ({ income: 0, expense: 0 }) },
});

const { t, locale } = useI18n();
const { formatMoney } = useFormatMoney();
const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);

function selectYear(year) {
    router.get(route('finance.index'), { year }, { preserveScroll: true, preserveState: true, only: ['detail', 'selectedYear', 'accounts'] });
}

const isClosed = computed(() => props.detail?.status === 'closed');

function closeYear() {
    if (!window.confirm(t('close_year_confirm'))) return;
    router.post(route('finance.years.close', props.detail.id), {}, { preserveScroll: true });
}
function reopenYear() {
    if (!window.confirm(t('reopen_year_confirm'))) return;
    router.post(route('finance.years.reopen', props.detail.id), {}, { preserveScroll: true });
}

const monthLabels = computed(() =>
    Array.from({ length: 12 }, (_, i) => new Date(2000, i, 1).toLocaleString(locale.value === 'ar' ? 'ar' : (locale.value === 'fr' ? 'fr' : 'en'), { month: 'short' })),
);

const monthlyChart = computed(() => ({
    labels: monthLabels.value,
    datasets: [
        { label: t('income'), data: (props.detail?.monthly || []).map((m) => m.income), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.12)', fill: true, tension: 0.35 },
        { label: t('expense'), data: (props.detail?.monthly || []).map((m) => m.expense), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.10)', fill: true, tension: 0.35 },
    ],
}));

function doughnut(rows) {
    return {
        labels: (rows || []).map((r) => r.name || t('other')),
        datasets: [{ data: (rows || []).map((r) => Number(r.total)), backgroundColor: (rows || []).map((r) => r.color || '#94a3b8'), borderWidth: 0 }],
    };
}
const incomeChart = computed(() => doughnut(props.detail?.income_by_category));
const expenseChart = computed(() => doughnut(props.detail?.expense_by_category));

const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };
const doughnutOptions = { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false } } };

const budgetRows = computed(() => props.detail?.budget || []);
const hasBudget = computed(() => budgetRows.value.some((b) => b.planned > 0));

function pct(actual, planned) {
    if (!planned || planned <= 0) return 0;
    return Math.min(100, Math.round((actual / planned) * 100));
}

const currentBalance = computed(() => props.accounts.reduce((s, a) => s + Number(a.current_balance || 0), 0));
</script>

<template>
    <Head :title="t('finance')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('finance') }}</h1>
        </template>

        <div class="space-y-6">
            <!-- Year tabs + actions -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="y in years"
                        :key="y.id"
                        @click="selectYear(y.year)"
                        class="flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-bold transition-colors"
                        :class="y.year === selectedYear ? 'bg-primary-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800'"
                    >
                        {{ y.year }}
                        <span v-if="y.status === 'closed'" class="text-xs opacity-70"><Icon name="check" /></span>
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        :href="route('reports.financial', { year: selectedYear })" target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 transition-colors hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"
                    ><Icon name="print" /> {{ t('export') }}</a>
                    <Link
                        v-if="isAdmin" :href="route('finance.settings')"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 transition-colors hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"
                    ><Icon name="settings" /> {{ t('finance_settings') }}</Link>
                </div>
            </div>

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
                            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ selectedYear }} — {{ isClosed ? t('closed') : t('open') }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <template v-if="isClosed">{{ t('closing_balance') }}: {{ formatMoney(detail.closing_balance) }}<span v-if="detail.closed_by"> · {{ detail.closed_by }}</span></template>
                                <template v-else>{{ t('opening_balance') }}: {{ formatMoney(detail.opening_balance) }}</template>
                            </p>
                        </div>
                    </div>
                    <div v-if="isAdmin" class="flex items-center gap-2">
                        <button v-if="!isClosed" @click="closeYear" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-bold text-white transition-colors hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">
                            <Icon name="archive" /> {{ t('close_year') }}
                        </button>
                        <button v-else @click="reopenYear" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-200 transition-colors hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700">
                            <Icon name="refresh" /> {{ t('reopen_year') }}
                        </button>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard :label="t('opening_balance')" :value="formatMoney(detail.opening_balance)" icon="money" color="slate" />
                    <StatCard :label="t('total_income')" :value="formatMoney(detail.income)" icon="arrow" color="emerald" />
                    <StatCard :label="t('total_expense')" :value="formatMoney(detail.expense)" icon="transactions" color="rose" />
                    <StatCard :label="t('net_balance')" :value="formatMoney(detail.net)" icon="check" :color="detail.net >= 0 ? 'primary' : 'rose'" />
                </div>

                <!-- Charts -->
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="card p-5 lg:col-span-2">
                        <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('monthly_trend') }}</p>
                        <div class="h-64"><Line :data="monthlyChart" :options="chartOptions" /></div>
                    </div>
                    <div class="card p-5">
                        <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('current_balance') }}</p>
                        <p class="figure text-3xl font-extrabold text-primary-700 dark:text-primary-400">{{ formatMoney(currentBalance) }}</p>
                        <ul class="mt-4 space-y-2">
                            <li v-for="a in accounts" :key="a.id" class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><Icon name="money" class="text-slate-400" /> {{ a.name }}</span>
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ formatMoney(a.current_balance) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Category breakdown -->
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="card p-5">
                        <p class="mb-4 text-sm font-bold text-emerald-700 dark:text-emerald-400">{{ t('income_categories') }}</p>
                        <div v-if="detail.income_by_category.length" class="flex items-center gap-5">
                            <div class="h-40 w-40 shrink-0"><Doughnut :data="incomeChart" :options="doughnutOptions" /></div>
                            <ul class="min-w-0 flex-1 space-y-2">
                                <li v-for="r in detail.income_by_category" :key="r.category_id" class="flex items-center justify-between gap-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: r.color || '#94a3b8' }"></span><span class="truncate">{{ r.name }}</span></span>
                                    <span class="shrink-0 font-semibold text-slate-900 dark:text-slate-100">{{ formatMoney(r.total) }}</span>
                                </li>
                            </ul>
                        </div>
                        <p v-else class="py-8 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
                    </div>
                    <div class="card p-5">
                        <p class="mb-4 text-sm font-bold text-rose-700 dark:text-rose-400">{{ t('expense_categories') }}</p>
                        <div v-if="detail.expense_by_category.length" class="flex items-center gap-5">
                            <div class="h-40 w-40 shrink-0"><Doughnut :data="expenseChart" :options="doughnutOptions" /></div>
                            <ul class="min-w-0 flex-1 space-y-2">
                                <li v-for="r in detail.expense_by_category" :key="r.category_id" class="flex items-center justify-between gap-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: r.color || '#94a3b8' }"></span><span class="truncate">{{ r.name }}</span></span>
                                    <span class="shrink-0 font-semibold text-slate-900 dark:text-slate-100">{{ formatMoney(r.total) }}</span>
                                </li>
                            </ul>
                        </div>
                        <p v-else class="py-8 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
                    </div>
                </div>

                <!-- Budget vs actual -->
                <div v-if="hasBudget" class="card p-5">
                    <p class="mb-4 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('budget_vs_actual') }}</p>
                    <div class="space-y-3">
                        <div v-for="b in budgetRows.filter((x) => x.planned > 0)" :key="b.category_id">
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-medium text-slate-700 dark:text-slate-200">{{ b.name }}</span>
                                <span class="text-slate-500 dark:text-slate-400">{{ formatMoney(b.actual) }} / {{ formatMoney(b.planned) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                <div class="h-full rounded-full" :class="b.type === 'expense' && b.actual > b.planned ? 'bg-rose-500' : 'bg-primary-500'" :style="{ width: pct(b.actual, b.planned) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- All years overview -->
            <div class="card overflow-hidden">
                <p class="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ t('all_years') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-start text-xs uppercase tracking-wide text-slate-400">
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <th class="px-5 py-2.5 text-start font-semibold">{{ t('fiscal_year') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('total_income') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('total_expense') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold">{{ t('net') }}</th>
                                <th class="px-5 py-2.5 text-center font-semibold">{{ t('status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="y in years" :key="y.id"
                                class="cursor-pointer border-b border-slate-50 transition-colors hover:bg-slate-50 dark:border-slate-800/50 dark:hover:bg-slate-800/40"
                                :class="y.year === selectedYear ? 'bg-primary-50/60 dark:bg-primary-500/5' : ''"
                                @click="selectYear(y.year)"
                            >
                                <td class="px-5 py-3 font-bold text-slate-900 dark:text-slate-100">{{ y.year }}</td>
                                <td class="px-5 py-3 text-end text-emerald-700 dark:text-emerald-400">{{ formatMoney(y.total_income) }}</td>
                                <td class="px-5 py-3 text-end text-rose-700 dark:text-rose-400">{{ formatMoney(y.total_expense) }}</td>
                                <td class="px-5 py-3 text-end font-semibold" :class="y.net >= 0 ? 'text-slate-900 dark:text-slate-100' : 'text-rose-700 dark:text-rose-400'">{{ formatMoney(y.net) }}</td>
                                <td class="px-5 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold" :class="y.status === 'closed' ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'">{{ y.status === 'closed' ? t('closed') : t('open') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
