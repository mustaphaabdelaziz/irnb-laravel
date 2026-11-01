<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import { Badge } from '@/Components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import StatTable from '@/Components/Stats/StatTable.vue';
import { Separator } from '@/Components/ui/separator';
import { useChartTheme } from '@/Composables/useChartTheme';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { baseOptions, seriesColor, spanLabel } from '@/lib/chartTheme';
import { columnTotals } from '@/lib/statTable';
import { useStatusLabel } from '@/Composables/useStatusLabel';

const props = defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    rtl: { type: Boolean, default: false },
});

const { t } = useI18n();
const { statusLabel } = useStatusLabel();
const { formatMoney } = useFormatMoney();
const { themeVersion } = useChartTheme();

const summary = computed(() => props.data?.summary ?? []);
const funnel = computed(() => props.data?.subscriptionFunnel ?? []);
const itemsByStatus = computed(() => props.data?.itemsByStatus ?? []);
const lowStock = computed(() => props.data?.lowStock ?? []);
const rentals = computed(() => props.data?.rentalsPerMonth ?? { labels: [], counts: [] });
const inventory = computed(() => props.data?.inventory ?? { lastSession: null, inProgress: 0 });

const TILE_STYLE = {
    stock_value: { icon: 'box', tone: 'primary' },
    on_loan: { icon: 'external', tone: 'neutral' },
    overdue: { icon: 'alert', tone: 'negative' },
    low_stock: { icon: 'archive', tone: 'warning' },
};

const tiles = computed(() => summary.value.map((tile) => ({
    ...tile,
    label: t(`dashboard.ops_${tile.key}`),
    icon: TILE_STYLE[tile.key]?.icon ?? 'dot',
    tone: TILE_STYLE[tile.key]?.tone ?? 'primary',
    meta: tile.key === 'stock_value' && tile.meta ? `${tile.meta.units} ${t('dashboard.ops_units')}` : null,
})));

const monthLabel = (key) => key?.slice(5) ?? '';
const hasRentals = computed(() => rentals.value.counts.some((v) => v > 0));

/**
 * The funnel as a stacked bar per subscription.
 *
 * Four identities, so four categorical slots — the reader has to tell paid
 * from unpaid at a glance. The stacking order puts exempt between partial and
 * unpaid, which is both defensible (exempt is settled by decision, not owed)
 * and necessary: the colours it separates would otherwise be adjacent, and
 * orange beside red fails the normal-vision floor at ΔE 7.1. This order was
 * validated in both modes — worst adjacent pair ΔE 33.6 light, 22.5 dark.
 */
const STAGES = [
    { key: 'paid', slot: 0 },      // blue
    { key: 'partial', slot: 1 },   // orange
    { key: 'exempt', slot: 6 },    // violet
    { key: 'unpaid', slot: 7 },    // red
];

const funnelChart = computed(() => {
    themeVersion.value; // recolour on theme toggle

    return {
        labels: funnel.value.map((row) => `${row.name} ${row.year}`),
        datasets: STAGES.map((stage) => ({
            label: t(`dashboard.ops_${stage.key}`),
            data: funnel.value.map((row) => row[stage.key]),
            backgroundColor: seriesColor(stage.slot),
            borderRadius: 4,
            maxBarThickness: 28,
            stack: 'funnel',
        })),
    };
});

const funnelOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: props.rtl, stacked: true, horizontal: true });
});

const rentalsChart = computed(() => {
    themeVersion.value;

    return {
        labels: rentals.value.labels.map(monthLabel),
        datasets: [{
            label: t('dashboard.ops_rentals'),
            data: rentals.value.counts,
            backgroundColor: seriesColor(0),
            borderRadius: 4,
            maxBarThickness: 24,
        }],
    };
});

const rentalsOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: props.rtl, legend: false });
});


const funnelColumns = computed(() => [
    { key: 'label', label: t('dashboard.subscriptions'), format: 'text', sortable: true },
    { key: 'enrolled', label: t('dashboard.ops_enrolled'), format: 'number', sortable: true, total: 'sum' },
    { key: 'paid', label: t('dashboard.ops_paid'), format: 'number', sortable: true, total: 'sum' },
    { key: 'partial', label: t('dashboard.ops_partial'), format: 'number', sortable: true, total: 'sum' },
    { key: 'unpaid', label: t('dashboard.ops_unpaid'), format: 'number', sortable: true, total: 'sum' },
    { key: 'exempt', label: t('dashboard.ops_exempt'), format: 'number', sortable: true, total: 'sum' },
    { key: 'rate', label: t('dashboard.ops_rate'), format: 'percent', sortable: true },
]);
// Rows carry the display label as data, so the table, the sort and the CSV agree.
const funnelRows = computed(() => funnel.value.map((row) => ({ ...row, label: `${row.name} ${row.year}` })));
const funnelTotals = computed(() => columnTotals(funnelColumns.value, funnelRows.value));

const rentalsColumns = computed(() => [
    { key: 'month', label: t('dashboard.month'), format: 'text' },
    { key: 'count', label: t('dashboard.ops_rentals'), format: 'number', total: 'sum' },
]);
const rentalsRows = computed(() => rentals.value.labels.map((month, index) => ({ month, count: rentals.value.counts[index] })));
const rentalsTotals = computed(() => columnTotals(rentalsColumns.value, rentalsRows.value));

const statusTone = {
    Available: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    Rented: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    'Under Repair': 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
};
</script>

<template>
    <div class="space-y-5">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('dashboard.tab_operations')">
            <StatTile
                v-for="tile in tiles"
                :key="tile.key"
                :label="tile.label"
                :value="tile.value"
                :format="tile.format"
                :icon="tile.icon"
                :tone="tile.tone"
                :meta="tile.meta"
            />
        </section>

        <ChartCard
            v-if="data?.subscriptionFunnel !== null"
            :title="t('dashboard.ops_funnel')"
            :subtitle="t('dashboard.ops_funnel_hint')"
            :loading="loading"
            :empty="!funnel.length"
            :empty-hint="t('dashboard.ops_funnel_empty')"
            has-table
            :csv="{ filename: 'dashboard-subscription-funnel', columns: funnelColumns, rows: funnelRows, totals: funnelTotals }"
            height="h-72"
        >
            <Bar :data="funnelChart" :options="funnelOptions" />

            <template #table>
                <StatTable :columns="funnelColumns" :rows="funnelRows" row-key="label" :totals="funnelTotals" dense />
            </template>
        </ChartCard>

        <section class="grid gap-5 lg:grid-cols-3">
            <ChartCard
                class="lg:col-span-2"
                :title="t('dashboard.ops_rentals_per_month')"
                :subtitle="spanLabel(rentals.labels)"
                :loading="loading"
                :empty="!hasRentals"
                :empty-hint="t('dashboard.ops_rentals_empty')"
                has-table
                :csv="{ filename: 'dashboard-rentals-per-month', columns: rentalsColumns, rows: rentalsRows, totals: rentalsTotals }"
                height="h-64"
            >
                <Bar :data="rentalsChart" :options="rentalsOptions" />

                <template #table>
                    <StatTable :columns="rentalsColumns" :rows="rentalsRows" row-key="month" :totals="rentalsTotals" dense />
                </template>
            </ChartCard>

            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.ops_inventory') }}</CardTitle>
                </CardHeader>
                <Separator />
                <div class="space-y-3 px-5 py-4">
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('dashboard.ops_last_count') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ inventory.lastSession || '—' }}
                        </p>
                        <p v-if="!inventory.lastSession" class="mt-1 text-xs text-muted-foreground">
                            {{ t('dashboard.ops_never_counted') }}
                        </p>
                    </div>

                    <div v-if="inventory.lastSession" class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-muted/60 p-2">
                            <p class="text-xs text-muted-foreground">{{ t('dashboard.ops_expected') }}</p>
                            <p class="text-sm font-semibold tabular-nums">{{ inventory.expected }}</p>
                        </div>
                        <div class="rounded-lg bg-muted/60 p-2">
                            <p class="text-xs text-muted-foreground">{{ t('dashboard.ops_found') }}</p>
                            <p class="text-sm font-semibold tabular-nums">{{ inventory.found }}</p>
                        </div>
                        <div class="rounded-lg bg-muted/60 p-2">
                            <p class="text-xs text-muted-foreground">{{ t('dashboard.ops_missing') }}</p>
                            <p
                                class="text-sm font-semibold tabular-nums"
                                :class="inventory.missing > 0 ? 'text-rose-700 dark:text-rose-400' : ''"
                            >
                                {{ inventory.missing }}
                            </p>
                        </div>
                    </div>

                    <p v-if="inventory.inProgress" class="text-xs text-muted-foreground">
                        {{ inventory.inProgress }} {{ t('dashboard.ops_in_progress') }}
                    </p>
                </div>
            </Card>
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.ops_by_status') }}</CardTitle>
                </CardHeader>
                <Separator />
                <p v-if="!itemsByStatus.length" class="px-5 py-10 text-center text-sm text-muted-foreground">
                    {{ t('dashboard.ops_no_equipment') }}
                </p>
                <ul v-else class="divide-y divide-border/70">
                    <li
                        v-for="row in itemsByStatus"
                        :key="row.status"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <Badge variant="outline" :class="statusTone[row.status] || ''">{{ statusLabel('equipment', row.status) }}</Badge>
                        <span class="text-sm font-semibold tabular-nums">{{ row.count }}</span>
                    </li>
                </ul>
            </Card>

            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.ops_low_stock') }}</CardTitle>
                </CardHeader>
                <Separator />
                <p v-if="!lowStock.length" class="px-5 py-10 text-center text-sm text-muted-foreground">
                    {{ t('dashboard.ops_low_stock_empty') }}
                </p>
                <ul v-else class="divide-y divide-border/70">
                    <li
                        v-for="row in lowStock"
                        :key="row.name"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                <Icon name="alert" class="size-3.5" />
                            </span>
                            <span class="truncate text-sm">{{ row.name }}</span>
                        </div>
                        <span class="shrink-0 text-sm font-semibold tabular-nums">
                            {{ row.available }} {{ t('dashboard.ops_left') }}
                        </span>
                    </li>
                </ul>
            </Card>
        </section>
    </div>
</template>
