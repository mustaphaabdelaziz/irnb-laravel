<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import ActivityCharts from '@/Components/Activity/ActivityCharts.vue';
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    period: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    charts: { type: Object, default: null },
});

// Most active users first, for the chart.
const ranked = computed(() => [...props.rows].map((r) => ({ name: r.user.name, total: r.total })).sort((a, b) => b.total - a.total));

// The period as query params: presets need only their name, a custom range its dates.
function periodQuery() {
    return props.period.period === 'custom'
        ? { period: 'custom', from: props.period.from, to: props.period.to }
        : { period: props.period.period };
}

function detailUrl(userId, extra = {}) {
    return route('users.activity.show', { user: userId, ...periodQuery(), ...extra });
}

const th = 'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
const cellLink = 'rounded px-1 tabular-nums hover:bg-primary-50 hover:text-primary-700 dark:hover:bg-primary-500/10 dark:hover:text-primary-300';
</script>

<template>
    <Head :title="t('activity.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="truncate text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('activity.title') }}</h1>
                <Link :href="route('users.index')" class="shrink-0 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ t('users') }}</Link>
            </div>
        </template>

        <div class="space-y-4">
            <PeriodFilter :period="period" :href="route('users.activity.index')" />

            <ActivityCharts v-if="charts" :charts="charts" :users="ranked" />

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th :class="th" class="text-start">{{ t('user') }}</th>
                                <th v-for="area in areas" :key="area" :class="th" class="text-end">{{ t(`activity.area.${area}`) }}</th>
                                <th :class="th" class="text-end">{{ t('activity.payments') }}</th>
                                <th :class="th" class="text-end">{{ t('activity.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="row in rows" :key="row.user.id" class="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                                    <Link :href="detailUrl(row.user.id)" class="hover:text-primary-700 dark:hover:text-primary-300">{{ row.user.name }}</Link>
                                </td>
                                <td v-for="area in areas" :key="area" class="whitespace-nowrap px-4 py-3 text-end text-sm"
                                    :class="row.areas[area] ? 'text-slate-700 dark:text-slate-200' : 'text-slate-300 dark:text-slate-600'">
                                    <Link :href="detailUrl(row.user.id, { area })" :class="cellLink">{{ row.areas[area] }}</Link>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                                    <Link :href="detailUrl(row.user.id, { area: 'money', action: 'payment_recorded' })" :class="cellLink" class="inline-flex flex-col items-end">
                                        <span :class="row.payments.count ? 'font-semibold text-slate-900 dark:text-slate-100' : 'text-slate-300 dark:text-slate-600'">{{ row.payments.count }}</span>
                                        <span v-if="row.payments.count" class="text-xs text-slate-500 dark:text-slate-400">{{ formatMoney(row.payments.amount) }}</span>
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    <Link :href="detailUrl(row.user.id)" :class="cellLink">{{ row.total }}</Link>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td :colspan="areas.length + 3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('activity.no_activity') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.no_author_note') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
