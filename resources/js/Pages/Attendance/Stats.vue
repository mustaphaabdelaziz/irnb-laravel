<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatusBreakdown from '@/Components/Attendance/StatusBreakdown.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { barDataset, baseOptions } from '@/lib/chartTheme';
import { hasMarks, hours, monthTick, pct, periodQuery, statusBars } from '@/lib/attendanceStats';
import StatsPlayerTable from './Partials/StatsPlayerTable.vue';
import StatsRanking from './Partials/StatsRanking.vue';

/**
 * Club-wide attendance statistics for a period (URL: period, from, to) and an
 * optional category (category_id). Every number is computed by
 * AttendanceStats on the server; this page lays it out. Status names and
 * colours come from the configured codes.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    monthly: { type: Object, required: true },
    sessionsByMonth: { type: Object, required: true },
    categoryRows: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    ranking: { type: Object, required: true },
    attendanceCodes: { type: Object, default: null },
});
const { t, locale } = useI18n();
const { statuses, label, color } = useAttendanceCodes();
const rtl = computed(() => locale.value === 'ar');

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
const exportHref = computed(() => route('attendance.stats.export', { ...periodQuery(props.period), ...keep.value }));
function pickCategory(value) {
    router.get(route('attendance.stats'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}

const tiles = computed(() => [
    { key: 'held', label: t('att.stats.held_sessions'), value: props.totals.held },
    { key: 'cancelled', label: t('att.stats.cancelled_sessions'), value: props.totals.cancelled },
    { key: 'marks', label: t('att.stats.marks'), value: props.totals.expected },
    { key: 'late', label: t('att.col.late_minutes'), value: props.totals.late_minutes },
    { key: 'missed', label: t('att.col.missed_hours'), value: hours(props.totals.missed_hours) },
    { key: 'score', label: t('att.col.score_pct'), value: pct(props.totals.score_pct) },
]);

const statusChart = computed(() => statusBars(props.monthly, statuses, label, color, locale.value));
const stackedOptions = computed(() => baseOptions({ rtl: rtl.value, stacked: true }));

const sessionsChart = computed(() => ({
    labels: props.sessionsByMonth.labels.map((ym) => monthTick(ym, locale.value)),
    datasets: [
        barDataset(t('att.state.held'), props.sessionsByMonth.held, 0),
        barDataset(t('att.state.cancelled'), props.sessionsByMonth.cancelled, 1),
    ],
}));
const hasSessions = computed(() => [...props.sessionsByMonth.held, ...props.sessionsByMonth.cancelled].some((n) => n > 0));
const sessionsOptions = computed(() => baseOptions({ rtl: rtl.value }));

const preseasonText = (p) => (!p ? '—' : p.target ? `${p.done}/${p.target}` : String(p.done));

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <Head :title="t('att.stats_title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.stats_title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.index')" :class="linkButton">{{ t('attendance') }}</Link>
                    <Link :href="route('attendance.alerts')" :class="linkButton">{{ t('att.risk.title') }}</Link>
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']">
                        <template #icon><Icon name="download" /></template>
                    </ExportMenu>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.stats')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <section class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.key" :class="[card, 'p-4']">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ tile.label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ tile.value }}</bdi></p>
                </div>
            </section>

            <div :class="[card, 'p-4']">
                <p v-if="!totals.expected" class="text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
                <StatusBreakdown v-else :counts="totals.counts" />
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('att.stats.score_help') }}</p>
            </div>

            <section class="grid gap-5 lg:grid-cols-2">
                <ChartCard :title="t('att.stats.by_month')" :empty="!hasMarks(monthly)" has-table height="h-72">
                    <Bar :data="statusChart" :options="stackedOptions" />
                    <template #table>
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('att.date') }}</th>
                                    <th v-for="s in statuses" :key="s" class="px-3 py-2 text-end font-medium">{{ label(s) }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="(ym, i) in monthly.labels" :key="ym">
                                    <td class="px-3 py-2"><bdi dir="ltr">{{ ym }}</bdi></td>
                                    <td v-for="s in statuses" :key="s" class="px-3 py-2 text-end tabular-nums">{{ monthly.statuses[s][i] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </ChartCard>

                <ChartCard :title="t('att.stats.sessions_by_month')" :empty="!hasSessions" has-table height="h-72">
                    <Bar :data="sessionsChart" :options="sessionsOptions" />
                    <template #table>
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('att.date') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('att.state.held') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('att.state.cancelled') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="(ym, i) in sessionsByMonth.labels" :key="ym">
                                    <td class="px-3 py-2"><bdi dir="ltr">{{ ym }}</bdi></td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ sessionsByMonth.held[i] }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ sessionsByMonth.cancelled[i] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </ChartCard>
            </section>

            <section :class="[card, 'overflow-x-auto']">
                <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.stats.by_category') }}</h2>
                <table class="mt-2 w-full min-w-[60rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th class="p-2 text-start font-semibold">{{ t('att.category') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.state.held') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.state.cancelled') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.kind.preseason') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.stats.marks') }}</th>
                            <th v-for="s in statuses" :key="s" class="p-2 text-end font-semibold">
                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: color(s) }"></span>{{ label(s) }}</span>
                            </th>
                            <th class="p-2 text-end font-semibold">{{ t('att.col.late_minutes') }}</th>
                            <th class="p-2 text-end font-semibold">{{ t('att.col.missed_hours') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in categoryRows"
                            :key="row.category_id"
                            class="border-t border-slate-100 dark:border-slate-800"
                            :class="{ 'bg-primary-50/60 dark:bg-primary-500/10': row.category_id === categoryId }"
                        >
                            <td class="p-2 font-medium">{{ row.name }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.held }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.cancelled }}</td>
                            <td class="p-2 text-end tabular-nums"><bdi dir="ltr">{{ preseasonText(row.preseason) }}</bdi></td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td v-for="s in statuses" :key="s" class="whitespace-nowrap p-2 text-end tabular-nums">
                                {{ row.counts[s] }} <bdi dir="ltr" class="text-xs text-slate-400">{{ pct(row.pct[s]) }}</bdi>
                            </td>
                            <td class="p-2 text-end tabular-nums">{{ row.late_minutes }}</td>
                            <td class="p-2 text-end tabular-nums">{{ hours(row.missed_hours) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <StatsRanking :ranking="ranking" />
            <StatsPlayerTable :rows="players" />
        </div>
    </AuthenticatedLayout>
</template>
