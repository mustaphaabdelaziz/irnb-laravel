<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import AlertChip from '@/Components/Dashboard/AlertChip.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import Icon from '@/Components/Icon.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { baseOptions, barDataset, ordinal, seriesColor, spanLabel } from '@/lib/chartTheme';

const props = defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    rtl: { type: Boolean, default: false },
});

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const cashFlow = computed(() => props.data?.cashFlow ?? { labels: [], income: [], expense: [], net: [] });
const aging = computed(() => props.data?.debtAging ?? []);
const alerts = computed(() => props.data?.alerts ?? []);
const activity = computed(() => props.data?.activity ?? []);

// Month keys arrive as YYYY-MM; the axis only needs the month.
const monthLabel = (key) => key?.slice(5) ?? '';

const hasCashFlow = computed(
    () => cashFlow.value.income.some((v) => v !== 0) || cashFlow.value.expense.some((v) => v !== 0),
);
const hasAging = computed(() => aging.value.some((bucket) => bucket.amount > 0));

/**
 * Income and expense as columns with net as a line, all on one axis.
 *
 * All three are dinars, so they share a scale honestly. A second y-axis would
 * let the net line be placed anywhere relative to the columns, which is the
 * most common way a chart like this ends up lying.
 */
const cashFlowChart = computed(() => ({
    labels: cashFlow.value.labels.map(monthLabel),
    datasets: [
        { type: 'bar', ...barDataset(t('dashboard.income'), cashFlow.value.income, 0) },
        { type: 'bar', ...barDataset(t('dashboard.expense'), cashFlow.value.expense, 1) },
        {
            type: 'line',
            label: t('dashboard.net'),
            data: cashFlow.value.net,
            borderColor: seriesColor(6),
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            pointRadius: 0,
            pointHoverRadius: 5,
            fill: false,
        },
    ],
}));

const cashFlowOptions = computed(() => baseOptions({ rtl: props.rtl, money: formatMoney }));

const agingChart = computed(() => ({
    // One unnamed category: the card title already says what this bar is, and
    // repeating it on the axis spends width the bar needs.
    labels: [''],
    datasets: aging.value.map((bucket, index) => ({
        label: t(`dashboard.aging_${bucket.bucket.replace('+', '_plus').replace('-', '_')}`),
        data: [bucket.amount],
        backgroundColor: ordinal(4)[index],
        borderRadius: 4,
        maxBarThickness: 28,
        stack: 'debt',
    })),
}));

const agingOptions = computed(() => baseOptions({
    rtl: props.rtl,
    stacked: true,
    horizontal: true,
    money: formatMoney,
}));

const activityIcon = { transaction: 'money', registration: 'user', rental: 'box', assignment: 'wrench' };
</script>

<template>
    <div class="space-y-5">
        <section v-if="alerts.length" class="flex flex-wrap gap-2" :aria-label="t('dashboard.alerts')">
            <AlertChip
                v-for="alert in alerts"
                :key="alert.key"
                :label="t(`dashboard.alert_${alert.key}`)"
                :count="alert.count"
                :severity="alert.severity"
                :href="alert.href ? route(alert.href) : null"
            />
        </section>

        <section class="grid gap-5 lg:grid-cols-5">
            <ChartCard
                class="lg:col-span-3"
                :title="t('dashboard.cash_flow')"
                :subtitle="spanLabel(cashFlow.labels)"
                :loading="loading"
                :empty="!hasCashFlow"
                :empty-hint="t('dashboard.cash_flow_empty')"
                has-table
                height="h-72"
            >
                <Bar :data="cashFlowChart" :options="cashFlowOptions" />

                <template #table>
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ t('dashboard.month') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.income') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.expense') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.net') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="(label, index) in cashFlow.labels" :key="label">
                                <td class="px-3 py-2">{{ label }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ formatMoney(cashFlow.income[index]) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ formatMoney(cashFlow.expense[index]) }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums">{{ formatMoney(cashFlow.net[index]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard
                class="lg:col-span-2"
                :title="t('dashboard.debt_aging')"
                :subtitle="t('dashboard.debt_aging_subtitle')"
                :loading="loading"
                :empty="!hasAging"
                :empty-hint="t('dashboard.debt_aging_empty')"
                has-table
                height="h-72"
            >
                <Bar :data="agingChart" :options="agingOptions" />

                <template #table>
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ t('dashboard.bucket') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.amount') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.members') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="bucket in aging" :key="bucket.bucket">
                                <td class="px-3 py-2">{{ bucket.bucket }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ formatMoney(bucket.amount) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ bucket.players }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>
        </section>

        <Card class="overflow-hidden border-border/70 shadow-none">
            <CardHeader class="px-5 py-4">
                <CardTitle class="text-base">{{ t('dashboard.recent_activity') }}</CardTitle>
            </CardHeader>
            <Separator />

            <p v-if="!activity.length" class="px-5 py-10 text-center text-sm text-muted-foreground">
                {{ t('dashboard.activity_empty') }}
            </p>

            <ul v-else class="divide-y divide-border/70">
                <li
                    v-for="entry in activity"
                    :key="`${entry.type}-${entry.id}`"
                    class="flex items-center justify-between gap-4 px-5 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Icon :name="activityIcon[entry.type] ?? 'dot'" class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ entry.label || t(`dashboard.activity_${entry.type}`) }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ t(`dashboard.activity_${entry.type}`) }} · {{ entry.at?.slice(0, 16) }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="entry.amount !== null"
                        class="shrink-0 text-sm font-semibold tabular-nums"
                        :class="entry.amount >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"
                    >
                        {{ entry.amount >= 0 ? '+' : '−' }}{{ formatMoney(Math.abs(entry.amount)) }}
                    </p>
                </li>
            </ul>
        </Card>
    </div>
</template>
