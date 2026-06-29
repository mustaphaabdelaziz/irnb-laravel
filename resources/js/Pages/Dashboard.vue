<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import '@/lib/registerCharts';
import { Doughnut, Line, Bar } from 'vue-chartjs';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    stats: { type: Object, required: true },
    charts: { type: Object, default: () => ({ categoryDistribution: [], monthlyRevenue: [], subscriptionSummary: [] }) },
    recentTransactions: { type: Array, default: () => [] },
    topDebtors: { type: Array, default: () => [] },
});

const statusColor = (s) => {
    if (s === 'Paid') return 'emerald';
    if (s === 'Partial') return 'amber';
    if (s === 'Exempt') return 'slate';
    return 'rose';
};

const palette = ['#02a85c', '#0284c7', '#d97706', '#e11d48', '#7c3aed', '#0891b2', '#65a30d', '#db2777'];
const monthLabels = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'];
const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } };

const hasCategoryData = computed(() => (props.charts.categoryDistribution || []).length > 0);
const hasSubscriptionData = computed(() => (props.charts.subscriptionSummary || []).length > 0);

const categoryChart = computed(() => ({
    labels: props.charts.categoryDistribution.map((c) => c.name),
    datasets: [{ data: props.charts.categoryDistribution.map((c) => c.count), backgroundColor: palette }],
}));

const monthlyChart = computed(() => ({
    labels: monthLabels,
    datasets: [{
        label: t('monthly_income'),
        data: props.charts.monthlyRevenue,
        borderColor: '#02a85c',
        backgroundColor: 'rgba(2, 168, 92, 0.12)',
        fill: true,
        tension: 0.35,
        pointRadius: 3,
    }],
}));

const subscriptionChart = computed(() => ({
    labels: props.charts.subscriptionSummary.map((s) => `${s.name} ${s.year}`),
    datasets: [
        { label: t('paid'), data: props.charts.subscriptionSummary.map((s) => s.paid), backgroundColor: '#02a85c' },
        { label: t('owed'), data: props.charts.subscriptionSummary.map((s) => s.owed), backgroundColor: '#e2e8f0' },
    ],
}));
</script>

<template>
    <Head :title="t('dashboard')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('dashboard') }}</h1>
        </template>

        <div class="space-y-6">
            <!-- Player stats -->
            <section class="stagger grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard :label="t('total_players')" :value="stats.totalPlayers" icon="players" color="primary" />
                <StatCard :label="t('paid_players')" :value="stats.paidPlayers" icon="check" color="emerald" />
                <StatCard :label="t('unpaid_players')" :value="stats.unpaidPlayers" icon="alert" color="rose" />
                <StatCard :label="t('outstanding_debt')" :value="formatMoney(stats.outstandingDebt)" icon="money" color="amber" suffix="DZD" />
            </section>

            <!-- Financial stats (this month) -->
            <section class="grid gap-4 md:grid-cols-3">
                <StatCard :label="t('monthly_income')" :value="formatMoney(stats.monthlyIncome)" color="emerald" suffix="DZD" />
                <StatCard :label="t('monthly_expense')" :value="formatMoney(stats.monthlyExpense)" color="rose" suffix="DZD" />
                <StatCard
                    :label="t('monthly_balance')"
                    :value="formatMoney(stats.balance)"
                    :color="Number(stats.balance) >= 0 ? 'emerald' : 'rose'"
                    suffix="DZD"
                />
            </section>

            <!-- All-time financial overview -->
            <section class="card p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('financial_overview') }}</h3>
                    <div class="flex items-center gap-3">
                        <a :href="route('reports.financial')" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-700 dark:text-slate-200 transition-colors hover:border-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <Icon name="document" class="text-sm" /> PDF
                        </a>
                        <Badge
                            dot
                            :label="t(stats.financeStatus || 'balanced')"
                            :color="stats.financeStatus === 'profit' ? 'emerald' : stats.financeStatus === 'loss' ? 'rose' : 'slate'"
                        />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('total_income') }}</p><p class="figure mt-1 text-lg font-bold text-emerald-700 dark:text-emerald-400">{{ formatMoney(stats.totalIncome) }}</p></div>
                    <div><p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('total_expense') }}</p><p class="figure mt-1 text-lg font-bold text-rose-700 dark:text-rose-400">{{ formatMoney(stats.totalExpense) }}</p></div>
                    <div><p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('donation') }}</p><p class="figure mt-1 text-lg font-bold text-sky-700 dark:text-sky-400">{{ formatMoney(stats.totalDonations) }}</p></div>
                    <div><p class="eyebrow text-slate-500 dark:text-slate-400">{{ t('net_balance') }}</p><p class="figure mt-1 text-lg font-bold" :class="Number(stats.netBalance) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ formatMoney(stats.netBalance) }}</p></div>
                </div>
            </section>

            <!-- Charts -->
            <section class="grid gap-6 lg:grid-cols-3">
                <article class="card p-5">
                    <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('players_by_category') }}</h3>
                    <div class="h-64">
                        <Doughnut v-if="hasCategoryData" :data="categoryChart" :options="chartOptions" />
                        <p v-else class="flex h-full items-center justify-center text-sm text-slate-400 dark:text-slate-500">{{ t('no_results') }}</p>
                    </div>
                </article>
                <article class="card p-5 lg:col-span-2">
                    <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('monthly_revenue') }}</h3>
                    <div class="h-64">
                        <Line :data="monthlyChart" :options="chartOptions" />
                    </div>
                </article>
                <article v-if="hasSubscriptionData" class="card p-5 lg:col-span-3">
                    <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('subscriptions') }}</h3>
                    <div class="h-72">
                        <Bar :data="subscriptionChart" :options="chartOptions" />
                    </div>
                </article>
            </section>

            <!-- Tables row -->
            <section class="grid gap-6 lg:grid-cols-3">
                <!-- Recent transactions -->
                <article class="card overflow-hidden lg:col-span-2">
                    <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('recent_transactions') }}</h3>
                    </div>

                    <div v-if="!recentTransactions.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                        {{ t('no_transactions') }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                            <thead class="bg-slate-50/80 dark:bg-slate-950">
                                <tr>
                                    <th class="eyebrow px-4 py-3 text-start text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                    <th class="eyebrow px-4 py-3 text-start text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                    <th class="eyebrow px-4 py-3 text-start text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                    <th class="eyebrow px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="item in recentTransactions" :key="item.id">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ item.date || '-' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ item.category }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :label="item.status" :color="statusColor(item.status)" />
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold"
                                        :class="item.type === 'income' ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"
                                    >
                                        {{ item.type === 'income' ? '+' : '-' }}{{ formatMoney(item.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- Top debtors -->
                <article class="card overflow-hidden">
                    <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('top_debtors') }}</h3>
                    </div>

                    <div v-if="!topDebtors.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                        {{ t('no_debtors') }}
                    </div>

                    <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="debtor in topDebtors" :key="debtor.id" class="flex items-center justify-between px-5 py-3 transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-800">
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-500 ring-1 ring-inset ring-rose-600/15 dark:bg-rose-500/15 dark:text-rose-400 dark:ring-rose-500/25"><Icon name="user" class="text-base" /></span>
                                <div>
                                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ debtor.name || '-' }}</p>
                                    <p class="jersey text-xs text-slate-400 dark:text-slate-500">{{ debtor.membership_id || '-' }}</p>
                                </div>
                            </div>
                            <p class="figure text-sm font-semibold text-rose-700 dark:text-rose-400">{{ formatMoney(debtor.outstanding) }}</p>
                        </li>
                    </ul>
                </article>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
