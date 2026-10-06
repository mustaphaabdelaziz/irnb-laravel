<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { Bar, Doughnut } from 'vue-chartjs';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import { baseOptions, seriesColor, surface } from '@/lib/chartTheme';

/**
 * Activity statistics for a period: events over time per area, the share of
 * each area, the most frequent actions and — on the all-users page — the most
 * active users. Every chart has a table view (ChartCard).
 */
const props = defineProps({
    // ActivityReport::charts(): { unit, buckets, timeline, areas, actions }
    charts: { type: Object, required: true },
    // Optional [{ name, total }] for the "most active users" chart.
    users: { type: Array, default: null },
});

const { t, locale } = useI18n();
const rtl = computed(() => locale.value === 'ar');

// One fixed colour slot per area (8 areas, 8 palette slots — never cycled).
const areaKeys = computed(() => Object.keys(props.charts.areas ?? {}));
const slotOf = (area) => Math.max(0, areaKeys.value.indexOf(area));
const areaLabel = (area) => t(`activity.area.${area}`);
const actionLabel = (action) => t(`activity.action.${action}`);

const total = computed(() => Object.values(props.charts.areas ?? {}).reduce((s, n) => s + n, 0));
const activeDays = computed(() => props.charts.buckets.filter((_, i) => areaKeys.value.some((a) => props.charts.timeline[a][i] > 0)).length);
const busiest = computed(() => {
    let best = null;
    props.charts.buckets.forEach((b, i) => {
        const n = areaKeys.value.reduce((s, a) => s + props.charts.timeline[a][i], 0);
        if (n > 0 && (!best || n > best.count)) best = { bucket: b, count: n };
    });
    return best;
});

function bucketLabel(iso) {
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' });
}

const timelineData = computed(() => ({
    labels: props.charts.buckets.map(bucketLabel),
    datasets: areaKeys.value
        .filter((area) => props.charts.areas[area] > 0)
        .map((area) => ({
            label: areaLabel(area),
            data: props.charts.timeline[area],
            backgroundColor: seriesColor(slotOf(area)),
            borderColor: surface(),
            borderWidth: { top: 2 },
            stack: 'areas',
        })),
}));
const timelineOptions = computed(() => baseOptions({ rtl: rtl.value, stacked: true }));

const shareAreas = computed(() => areaKeys.value.filter((a) => props.charts.areas[a] > 0));
const shareData = computed(() => ({
    labels: shareAreas.value.map(areaLabel),
    datasets: [{
        data: shareAreas.value.map((a) => props.charts.areas[a]),
        backgroundColor: shareAreas.value.map((a) => seriesColor(slotOf(a))),
        borderColor: surface(),
        borderWidth: 2,
    }],
}));
const shareOptions = computed(() => {
    const base = baseOptions({ rtl: rtl.value });
    return { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { ...base.plugins.legend, position: 'right' }, tooltip: base.plugins.tooltip } };
});

const actionsData = computed(() => ({
    labels: props.charts.actions.map((a) => actionLabel(a.action)),
    datasets: [{
        label: t('activity.events'),
        data: props.charts.actions.map((a) => a.count),
        backgroundColor: props.charts.actions.map((a) => seriesColor(slotOf(a.area))),
    }],
}));
const barOptions = computed(() => baseOptions({ rtl: rtl.value, horizontal: true, legend: false }));

const topUsers = computed(() => (props.users ?? []).filter((u) => u.total > 0).slice(0, 10));
const usersData = computed(() => ({
    labels: topUsers.value.map((u) => u.name),
    datasets: [{ label: t('activity.events'), data: topUsers.value.map((u) => u.total), backgroundColor: seriesColor(0) }],
}));

const th = 'px-3 py-2 font-medium';
</script>

<template>
    <div class="space-y-4">
        <!-- Key figures -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.total') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ total }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ charts.unit === 'week' ? t('activity.active_weeks') : t('activity.active_days') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ activeDays }} <span class="text-sm font-normal text-slate-400">/ {{ charts.buckets.length }}</span></p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.average_per_active') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ activeDays ? (total / activeDays).toFixed(1) : 0 }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.busiest') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ busiest ? busiest.count : 0 }}</p>
                <p v-if="busiest" class="text-xs text-slate-500 dark:text-slate-400">{{ bucketLabel(busiest.bucket) }}</p>
            </div>
        </div>

        <ChartCard :title="t('activity.chart_timeline')" :subtitle="charts.unit === 'week' ? t('activity.per_week') : t('activity.per_day')" :empty="!total" has-table height="h-72">
            <Bar :data="timelineData" :options="timelineOptions" />
            <template #table>
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                        <tr><th :class="th" class="text-start">{{ t('date') }}</th><th v-for="ds in timelineData.datasets" :key="ds.label" :class="th" class="text-end">{{ ds.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(label, i) in timelineData.labels" :key="i" class="border-t border-border/60">
                            <td class="px-3 py-1.5">{{ label }}</td>
                            <td v-for="ds in timelineData.datasets" :key="ds.label" class="px-3 py-1.5 text-end tabular-nums">{{ ds.data[i] }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard :title="t('activity.chart_areas')" :empty="!total" has-table>
                <Doughnut :data="shareData" :options="shareOptions" />
                <template #table>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="area in shareAreas" :key="area" class="border-t border-border/60 first:border-0">
                                <td class="px-3 py-1.5">{{ areaLabel(area) }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ charts.areas[area] }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums text-muted-foreground">{{ Math.round((charts.areas[area] / total) * 100) }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard :title="t('activity.chart_actions')" :empty="!charts.actions.length" has-table>
                <Bar :data="actionsData" :options="barOptions" />
                <template #table>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="a in charts.actions" :key="a.action" class="border-t border-border/60 first:border-0">
                                <td class="px-3 py-1.5">{{ actionLabel(a.action) }}</td>
                                <td class="px-3 py-1.5 text-muted-foreground">{{ areaLabel(a.area) }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ a.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard v-if="users" :title="t('activity.chart_users')" :empty="!topUsers.length" has-table class="lg:col-span-2">
                <Bar :data="usersData" :options="barOptions" />
                <template #table>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="u in topUsers" :key="u.name" class="border-t border-border/60 first:border-0">
                                <td class="px-3 py-1.5">{{ u.name }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ u.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>
        </div>
    </div>
</template>
