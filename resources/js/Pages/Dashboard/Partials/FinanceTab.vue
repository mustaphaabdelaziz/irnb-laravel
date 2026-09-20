<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import Meter from '@/Components/Dashboard/Meter.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import { Badge } from '@/Components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { baseOptions, sequential } from '@/lib/chartTheme';

const props = defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    rtl: { type: Boolean, default: false },
});

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const summary = computed(() => props.data?.summary ?? []);
const expenses = computed(() => props.data?.expenseByCategory ?? []);
const income = computed(() => props.data?.incomeByCategory ?? []);
const accounts = computed(() => props.data?.accounts ?? []);
const transfers = computed(() => props.data?.transfers ?? { count: 0, total: 0, recent: [] });

const TILE_STYLE = {
    avg_transaction: { icon: 'transactions', tone: 'primary' },
    largest_expense: { icon: 'money', tone: 'negative' },
    burn_rate: { icon: 'alert', tone: 'warning' },
    runway: { icon: 'calendar', tone: 'positive' },
};

const tiles = computed(() => summary.value.map((tile) => ({
    ...tile,
    label: t(`dashboard.fin_${tile.key}`),
    icon: TILE_STYLE[tile.key]?.icon ?? 'dot',
    tone: TILE_STYLE[tile.key]?.tone ?? 'primary',
    meta: tileMeta(tile),
})));

function tileMeta(tile) {
    if (tile.key === 'largest_expense' && tile.meta) return `${tile.meta.label} · ${tile.meta.date}`;
    if (tile.key === 'avg_transaction' && tile.meta) return `${tile.meta.entries} ${t('dashboard.fin_entries')}`;
    if (tile.key === 'runway' && tile.value !== null) return t('dashboard.fin_runway_hint');

    return null;
}

// Runway is a count of months, not money. StatTile has no such format, so the
// value is pre-rendered here and passed through as a string.
const tileValue = (tile) => (tile.format === 'months' && tile.value !== null
    ? `${tile.value} ${t('dashboard.fin_months')}`
    : tile.value);
const tileFormat = (tile) => (tile.format === 'months' ? 'number' : tile.format);

// The server sends a translation key for the two rows it names itself
// ("uncategorised", "other") and a database name for the rest.
const rowLabel = (row) => (row.labelKey ? t(`dashboard.fin_${row.labelKey}`) : row.name);

/**
 * A magnitude chart, so one hue darkening with the value — not eight
 * categorical colours. The categories here are not identities competing for
 * attention; the question is only "which is biggest".
 */
function categoryChart(rows) {
    const ramp = sequential(6);

    return {
        labels: rows.map(rowLabel),
        datasets: [{
            label: t('dashboard.amount'),
            data: rows.map((row) => row.amount),
            // Darkest for the largest bar, stepping down the ramp.
            backgroundColor: rows.map((_, index) => ramp[Math.max(0, ramp.length - 1 - index)] ?? ramp[0]),
            borderRadius: 4,
            maxBarThickness: 24,
        }],
    };
}

const chartOptions = computed(() => baseOptions({
    rtl: props.rtl,
    horizontal: true,
    legend: false,
    money: formatMoney,
}));

const expenseChart = computed(() => categoryChart(expenses.value));
const incomeChart = computed(() => categoryChart(income.value));

// Meters compare each account against the largest one, which is the only
// reference the data actually provides — there is no budget or ceiling stored.
const largestBalance = computed(
    () => accounts.value.reduce((max, account) => Math.max(max, Math.abs(account.current)), 0) || 1,
);
</script>

<template>
    <div class="space-y-5">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('dashboard.tab_finance')">
            <StatTile
                v-for="tile in tiles"
                :key="tile.key"
                :label="tile.label"
                :value="tileValue(tile)"
                :format="tileFormat(tile)"
                :icon="tile.icon"
                :tone="tile.tone"
                :meta="tile.meta"
            />
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <ChartCard
                :title="t('dashboard.fin_expense_by_category')"
                :subtitle="t('dashboard.fin_where_it_went')"
                :loading="loading"
                :empty="!expenses.length"
                :empty-hint="t('dashboard.fin_expense_empty')"
                has-table
                height="h-80"
            >
                <Bar :data="expenseChart" :options="chartOptions" />

                <template #table>
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ t('dashboard.category') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.amount') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.fin_share') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="row in expenses" :key="row.labelKey || row.name">
                                <td class="px-3 py-2">{{ rowLabel(row) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ formatMoney(row.amount) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ row.share }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard
                :title="t('dashboard.fin_income_by_category')"
                :subtitle="t('dashboard.fin_where_it_came_from')"
                :loading="loading"
                :empty="!income.length"
                :empty-hint="t('dashboard.fin_income_empty')"
                has-table
                height="h-80"
            >
                <Bar :data="incomeChart" :options="chartOptions" />

                <template #table>
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ t('dashboard.category') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.amount') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.fin_share') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="row in income" :key="row.labelKey || row.name">
                                <td class="px-3 py-2">{{ rowLabel(row) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ formatMoney(row.amount) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ row.share }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>
        </section>

        <section class="grid gap-5 lg:grid-cols-5">
            <Card class="overflow-hidden border-border/70 shadow-none lg:col-span-3">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.fin_accounts') }}</CardTitle>
                </CardHeader>
                <Separator />

                <p v-if="!accounts.length" class="px-5 py-10 text-center text-sm text-muted-foreground">
                    {{ t('dashboard.fin_accounts_empty') }}
                </p>

                <ul v-else class="divide-y divide-border/70">
                    <li v-for="account in accounts" :key="account.id" class="px-5 py-3.5">
                        <div class="mb-2 flex items-baseline justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-sm font-medium">{{ account.name }}</span>
                                <Badge v-if="account.is_treasury" variant="outline" class="shrink-0 text-[10px]">
                                    {{ t('dashboard.fin_treasury') }}
                                </Badge>
                                <span v-if="account.branch" class="shrink-0 text-xs text-muted-foreground">
                                    {{ account.branch }}
                                </span>
                            </div>
                            <span
                                class="shrink-0 text-sm font-semibold tabular-nums"
                                :class="account.current < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-foreground'"
                            >
                                {{ formatMoney(account.current) }}
                            </span>
                        </div>
                        <Meter :value="Math.abs(account.current)" :max="largestBalance" />
                    </li>
                </ul>
            </Card>

            <Card class="overflow-hidden border-border/70 shadow-none lg:col-span-2">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.fin_transfers') }}</CardTitle>
                </CardHeader>
                <Separator />

                <!-- Stated plainly because it is the most common way a set of
                     books ends up wrong: a transfer is movement, not income. -->
                <p class="px-5 pt-4 text-xs text-muted-foreground">{{ t('dashboard.fin_transfers_note') }}</p>

                <div class="px-5 py-4">
                    <p class="text-2xl font-semibold tabular-nums">{{ formatMoney(transfers.total) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ transfers.count }} {{ t('dashboard.fin_transfer_count') }}
                    </p>
                </div>

                <Separator v-if="transfers.recent.length" />

                <ul v-if="transfers.recent.length" class="divide-y divide-border/70">
                    <li v-for="transfer in transfers.recent" :key="transfer.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Icon name="arrow" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">{{ transfer.from }} → {{ transfer.to }}</p>
                            <p class="text-xs text-muted-foreground">{{ transfer.date }}</p>
                        </div>
                        <span class="shrink-0 text-sm font-medium tabular-nums">{{ formatMoney(transfer.amount) }}</span>
                    </li>
                </ul>
            </Card>
        </section>
    </div>
</template>
