<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { Bar, Doughnut } from 'vue-chartjs';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import StatTable from '@/Components/Stats/StatTable.vue';
import { useChartTheme } from '@/Composables/useChartTheme';
import { baseOptions, seriesColor, surface } from '@/lib/chartTheme';
import { columnTotals } from '@/lib/statTable';

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
const { themeVersion } = useChartTheme();
const rtl = computed(() => locale.value === 'ar');

// One fixed colour slot per area (9 areas, 9 palette slots — never cycled).
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

const timelineData = computed(() => {
    themeVersion.value; // recolour on theme toggle

    return {
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
    };
});
const timelineOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: rtl.value, stacked: true });
});

const shareAreas = computed(() => areaKeys.value.filter((a) => props.charts.areas[a] > 0));
const shareData = computed(() => {
    themeVersion.value;

    return {
        labels: shareAreas.value.map(areaLabel),
        datasets: [{
            data: shareAreas.value.map((a) => props.charts.areas[a]),
            backgroundColor: shareAreas.value.map((a) => seriesColor(slotOf(a))),
            borderColor: surface(),
            borderWidth: 2,
        }],
    };
});
const shareOptions = computed(() => {
    themeVersion.value;

    const base = baseOptions({ rtl: rtl.value });
    return { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { ...base.plugins.legend, position: 'right' }, tooltip: base.plugins.tooltip } };
});

const actionsData = computed(() => {
    themeVersion.value;

    return {
        labels: props.charts.actions.map((a) => actionLabel(a.action)),
        datasets: [{
            label: t('activity.events'),
            data: props.charts.actions.map((a) => a.count),
            backgroundColor: props.charts.actions.map((a) => seriesColor(slotOf(a.area))),
        }],
    };
});
const barOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: rtl.value, horizontal: true, legend: false });
});

const topUsers = computed(() => (props.users ?? []).filter((u) => u.total > 0).slice(0, 10));
const usersData = computed(() => {
    themeVersion.value;

    return {
        labels: topUsers.value.map((u) => u.name),
        datasets: [{ label: t('activity.events'), data: topUsers.value.map((u) => u.total), backgroundColor: seriesColor(0) }],
    };
});


// Timeline table: one number column per shown area, plus the row total.
const timelineColumns = computed(() => [
    { key: 'label', label: t('date'), format: 'text' },
    ...shareAreas.value.map((area) => ({ key: area, label: areaLabel(area), format: 'number', total: 'sum' })),
    { key: 'total', label: t('activity.total'), format: 'number' },
]);
const timelineRows = computed(() => props.charts.buckets.map((bucket, i) => {
    const row = { bucket, label: bucketLabel(bucket), total: 0 };
    shareAreas.value.forEach((area) => {
        row[area] = props.charts.timeline[area][i];
        row.total += row[area];
    });

    return row;
}));
const timelineTotals = computed(() => columnTotals(timelineColumns.value, timelineRows.value));

const areaColumns = computed(() => [
    { key: 'label', label: t('stats.area'), format: 'text', sortable: true },
    { key: 'count', label: t('activity.events'), format: 'number', sortable: true, total: 'sum' },
    { key: 'share', label: t('dashboard.fin_share'), format: 'percent', sortable: true },
]);
const areaRows = computed(() => shareAreas.value.map((area) => ({
    area,
    label: areaLabel(area),
    count: props.charts.areas[area],
    share: total.value ? (props.charts.areas[area] / total.value) * 100 : 0,
})));
const areaTotals = computed(() => columnTotals(areaColumns.value, areaRows.value));

const actionColumns = computed(() => [
    { key: 'label', label: t('action'), format: 'text', sortable: true },
    { key: 'areaName', label: t('stats.area'), format: 'text', sortable: true },
    { key: 'count', label: t('activity.events'), format: 'number', sortable: true, total: 'sum' },
]);
const actionRows = computed(() => props.charts.actions.map((a) => ({ ...a, label: actionLabel(a.action), areaName: areaLabel(a.area) })));
const actionTotals = computed(() => columnTotals(actionColumns.value, actionRows.value));

const userColumns = computed(() => [
    { key: 'name', label: t('user'), format: 'text', sortable: true },
    { key: 'total', label: t('activity.events'), format: 'number', sortable: true },
]);
const userTotals = computed(() => columnTotals(userColumns.value, topUsers.value));
</script>

<template>
    <div class="space-y-4">
        <!-- Key figures -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile :label="t('activity.total')" :value="total" format="number" icon="chart" tone="primary" />
            <StatTile :label="charts.unit === 'week' ? t('activity.active_weeks') : t('activity.active_days')" :value="activeDays" format="number" :meta="`/ ${charts.buckets.length}`" icon="calendar" tone="neutral" />
            <StatTile :label="t('activity.average_per_active')" :value="activeDays ? total / activeDays : 0" format="number" icon="task" tone="neutral" />
            <StatTile :label="t('activity.busiest')" :value="busiest?.count ?? 0" format="number" :meta="busiest ? bucketLabel(busiest.bucket) : null" icon="flag" tone="warning" />
        </div>

        <ChartCard :title="t('activity.chart_timeline')" :subtitle="charts.unit === 'week' ? t('activity.per_week') : t('activity.per_day')" :empty="!total" has-table height="h-72" :csv="{ filename: 'activity-timeline', columns: timelineColumns, rows: timelineRows, totals: timelineTotals }">
            <Bar :data="timelineData" :options="timelineOptions" />
            <template #table>
                <StatTable :columns="timelineColumns" :rows="timelineRows" row-key="bucket" :totals="timelineTotals" dense />
            </template>
        </ChartCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard :title="t('activity.chart_areas')" :empty="!total" has-table :csv="{ filename: 'activity-by-area', columns: areaColumns, rows: areaRows, totals: areaTotals }">
                <Doughnut :data="shareData" :options="shareOptions" />
                <template #table>
                    <StatTable :columns="areaColumns" :rows="areaRows" row-key="area" :totals="areaTotals" dense />
                </template>
            </ChartCard>

            <ChartCard :title="t('activity.chart_actions')" :empty="!charts.actions.length" has-table :csv="{ filename: 'activity-by-action', columns: actionColumns, rows: actionRows, totals: actionTotals }">
                <Bar :data="actionsData" :options="barOptions" />
                <template #table>
                    <StatTable :columns="actionColumns" :rows="actionRows" row-key="action" :totals="actionTotals" dense />
                </template>
            </ChartCard>

            <ChartCard v-if="users" :title="t('activity.chart_users')" :empty="!topUsers.length" has-table class="lg:col-span-2" :csv="{ filename: 'activity-top-users', columns: userColumns, rows: topUsers, totals: userTotals }">
                <Bar :data="usersData" :options="barOptions" />
                <template #table>
                    <StatTable :columns="userColumns" :rows="topUsers" row-key="name" :totals="userTotals" dense />
                </template>
            </ChartCard>
        </div>
    </div>
</template>
