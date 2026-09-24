<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import Meter from '@/Components/Dashboard/Meter.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { baseOptions, ordinal, seriesColor, sequential, spanLabel } from '@/lib/chartTheme';

const props = defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    rtl: { type: Boolean, default: false },
    branchId: { type: [Number, String], default: null },
});

const { t } = useI18n();

const summary = computed(() => props.data?.summary ?? []);
const growth = computed(() => props.data?.growth ?? { labels: [], joined: [], cumulative: [] });
const byCategory = computed(() => props.data?.byCategory ?? []);
const byStatus = computed(() => props.data?.byStatus ?? []);
const byAge = computed(() => props.data?.byAge ?? []);
const debtBands = computed(() => props.data?.debtBands ?? []);
const split = computed(() => props.data?.split ?? { students: 0, workers: 0, male: 0, female: 0 });
const topCities = computed(() => props.data?.topCities ?? []);
const academic = computed(() => props.data?.academic ?? null);

// StatTile renders its value as plain text (no markup slot), so a "12.50 / 20"
// string can't be wrapped in a dir="ltr" element. Unicode isolates (LRI/PDI)
// give the same protection against the digits and slash re-ordering under the
// Arabic UI without needing HTML.
const academicAverageDisplay = computed(() => (academic.value?.average === null || academic.value?.average === undefined
    ? null
    : `\u2066${academic.value.average.toFixed(2)} / 20\u2069`));

// The at-risk / missing-GPA counts are scoped to the dashboard's branch
// filter (see MemberStats::academic), so the drill-down into the players
// list must carry that same branch along or it lands on an unfiltered,
// club-wide list that no longer matches the number just clicked.
//
// Accepts either the original shorthand (a bucket name, for the "academic"
// filter) or a params object, e.g. `academicLink({ certificate: 'excellence' })`.
function academicLink(bucket) {
    const params = typeof bucket === 'string' ? { academic: bucket } : { ...bucket };
    if (props.branchId) params.branch_id = props.branchId;
    return route('players.index', params);
}

const CERTIFICATE_CLASSES = {
    excellence: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300',
    congratulations: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-300',
    encouragement: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300',
    honor_roll: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/15 dark:text-violet-300',
};

const CERTIFICATE_ORDER = ['excellence', 'congratulations', 'encouragement', 'honor_roll'];

const certificateRows = computed(() => CERTIFICATE_ORDER.map((key) => ({
    key,
    count: academic.value?.certificates?.[key] ?? 0,
})));

const hasCertificates = computed(() => certificateRows.value.some((row) => row.count > 0));

const TILE_STYLE = {
    total_members: { icon: 'players', tone: 'primary' },
    new_members: { icon: 'plus', tone: 'positive' },
    renewal_rate: { icon: 'refresh', tone: 'positive' },
    median_debt: { icon: 'money', tone: 'warning' },
    archived: { icon: 'archive', tone: 'neutral' },
};

const tiles = computed(() => summary.value.map((tile) => ({
    ...tile,
    label: t(`dashboard.mem_${tile.key}`),
    icon: TILE_STYLE[tile.key]?.icon ?? 'dot',
    tone: TILE_STYLE[tile.key]?.tone ?? 'primary',
})));

const rowLabel = (row) => (row.labelKey ? t(`dashboard.fin_${row.labelKey}`) : row.name);

// A member with no status is not "uncategorised" — that word belongs to
// categories. The status card says it in its own terms.
const statusLabel = (row) => (row.labelKey ? t('dashboard.mem_no_status') : row.name);

const monthLabel = (key) => key?.slice(5) ?? '';

const hasGrowth = computed(() => growth.value.joined.some((v) => v > 0));

/**
 * Joins per month as columns with the running total as a line.
 *
 * Both are counts of members on one axis, so they can share a scale. The
 * columns answer "how many joined", the line "how many there are" — two
 * questions a club asks together.
 */
const growthChart = computed(() => ({
    labels: growth.value.labels.map(monthLabel),
    datasets: [
        {
            type: 'bar',
            label: t('dashboard.mem_joined'),
            data: growth.value.joined,
            backgroundColor: seriesColor(0),
            borderRadius: 4,
            maxBarThickness: 24,
        },
        {
            type: 'line',
            label: t('dashboard.mem_total'),
            data: growth.value.cumulative,
            borderColor: seriesColor(2),
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            pointRadius: 0,
            pointHoverRadius: 5,
            fill: false,
        },
    ],
}));

const growthOptions = computed(() => baseOptions({ rtl: props.rtl }));

const barOptions = computed(() => baseOptions({ rtl: props.rtl, horizontal: true, legend: false }));

/** Magnitude: one hue, darkest for the largest bar. */
function magnitudeChart(rows, labelFor) {
    const ramp = sequential(6);

    return {
        labels: rows.map(labelFor),
        datasets: [{
            label: t('dashboard.members'),
            data: rows.map((row) => row.count),
            backgroundColor: rows.map((_, index) => ramp[Math.max(0, ramp.length - 1 - index)] ?? ramp[0]),
            borderRadius: 4,
            maxBarThickness: 24,
        }],
    };
}

const categoryChart = computed(() => magnitudeChart(byCategory.value, rowLabel));

/** Age is an ordered scale, so it wears the ordinal ramp rather than magnitude. */
const ageChart = computed(() => {
    const ramp = ordinal(4);

    return {
        labels: byAge.value.map((row) => t(`dashboard.age_${row.band}`)),
        datasets: [{
            label: t('dashboard.members'),
            data: byAge.value.map((row) => row.count),
            backgroundColor: byAge.value.map((row, index) => (row.band === 'unknown'
                ? 'rgba(148, 163, 184, .45)'
                : ramp[Math.min(index, ramp.length - 1)])),
            borderRadius: 4,
            maxBarThickness: 28,
        }],
    };
});

const ageOptions = computed(() => baseOptions({ rtl: props.rtl, legend: false }));

const debtTotal = computed(() => debtBands.value.reduce((sum, band) => sum + band.count, 0) || 1);
const statusTotal = computed(() => byStatus.value.reduce((sum, row) => sum + row.count, 0) || 1);
const cityTotal = computed(() => topCities.value.reduce((sum, row) => sum + row.count, 0) || 1);
const splitTotal = computed(() => (split.value.students + split.value.workers) || 1);
const genderTotal = computed(() => (split.value.male + split.value.female) || 1);
</script>

<template>
    <div class="space-y-5">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" :aria-label="t('dashboard.tab_members')">
            <StatTile
                v-for="tile in tiles"
                :key="tile.key"
                :label="tile.label"
                :value="tile.value"
                :format="tile.format"
                :delta="tile.delta"
                :icon="tile.icon"
                :tone="tile.tone"
            />
        </section>

        <section v-if="academic && academic.students" class="space-y-3" :aria-label="t('dashboard.mem_academic')">
            <h3 class="text-sm font-semibold text-muted-foreground">{{ t('dashboard.mem_academic') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile :label="t('dashboard.mem_students')" :value="academic.students" icon="players" tone="primary" />
                <StatTile :label="t('dashboard.mem_academic_average')" :value="academicAverageDisplay" format="text" icon="check" tone="positive" />
                <StatTile :label="t('dashboard.mem_at_risk')" :value="academic.at_risk" icon="alert" tone="negative" :href="academicLink('at_risk')" />
                <StatTile :label="t('dashboard.mem_missing_gpa')" :value="academic.missing" icon="dot" tone="warning" :href="academicLink('none')" />
            </div>

            <div v-if="hasCertificates" class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-muted-foreground">{{ t('dashboard.mem_certificates_year') }}</span>
                <Link
                    v-for="cert in certificateRows"
                    :key="cert.key"
                    :href="academicLink({ certificate: cert.key })"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset transition-colors hover:opacity-80"
                    :class="CERTIFICATE_CLASSES[cert.key]"
                >
                    {{ t(`certificate_short_${cert.key}`) }}
                    <span class="tabular-nums">{{ cert.count }}</span>
                </Link>
            </div>
        </section>

        <ChartCard
            :title="t('dashboard.mem_growth')"
            :subtitle="spanLabel(growth.labels)"
            :loading="loading"
            :empty="!hasGrowth"
            :empty-hint="t('dashboard.mem_growth_empty')"
            has-table
            height="h-72"
        >
            <Bar :data="growthChart" :options="growthOptions" />

            <template #table>
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-muted/60 text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium">{{ t('dashboard.month') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.mem_joined') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ t('dashboard.mem_total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr v-for="(label, index) in growth.labels" :key="label">
                            <td class="px-3 py-2">{{ label }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ growth.joined[index] }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ growth.cumulative[index] }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>

        <section class="grid gap-5 lg:grid-cols-2">
            <ChartCard
                :title="t('dashboard.mem_by_category')"
                :loading="loading"
                :empty="!byCategory.length"
                :empty-hint="t('dashboard.mem_no_members')"
                has-table
                height="h-72"
            >
                <Bar :data="categoryChart" :options="barOptions" />

                <template #table>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="row in byCategory" :key="row.labelKey || row.name">
                                <td class="px-3 py-2">{{ rowLabel(row) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ row.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard
                :title="t('dashboard.mem_by_age')"
                :subtitle="t('dashboard.mem_by_age_hint')"
                :loading="loading"
                :empty="!byAge.length"
                :empty-hint="t('dashboard.mem_no_members')"
                height="h-72"
            >
                <Bar :data="ageChart" :options="ageOptions" />
            </ChartCard>
        </section>

        <section class="grid gap-5 lg:grid-cols-3">
            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.mem_debt_spread') }}</CardTitle>
                </CardHeader>
                <Separator />
                <div class="space-y-3 px-5 py-4">
                    <Meter
                        v-for="band in debtBands"
                        :key="band.band"
                        :value="band.count"
                        :max="debtTotal"
                        :label="t(`dashboard.debt_${band.band}`)"
                        :caption="String(band.count)"
                    />
                </div>
            </Card>

            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.mem_by_status') }}</CardTitle>
                </CardHeader>
                <Separator />
                <p v-if="!byStatus.length" class="px-5 py-10 text-center text-sm text-muted-foreground">
                    {{ t('dashboard.mem_no_members') }}
                </p>
                <div v-else class="space-y-3 px-5 py-4">
                    <Meter
                        v-for="row in byStatus"
                        :key="row.labelKey || row.name"
                        :value="row.count"
                        :max="statusTotal"
                        :label="statusLabel(row)"
                        :caption="String(row.count)"
                    />
                </div>
            </Card>

            <Card class="border-border/70 shadow-none">
                <CardHeader class="px-5 py-4">
                    <CardTitle class="text-base">{{ t('dashboard.mem_make_up') }}</CardTitle>
                </CardHeader>
                <Separator />
                <div class="space-y-3 px-5 py-4">
                    <Meter
                        :value="split.students"
                        :max="splitTotal"
                        :label="t('dashboard.mem_students')"
                        :caption="String(split.students)"
                    />
                    <Meter
                        :value="split.workers"
                        :max="splitTotal"
                        :label="t('dashboard.mem_workers')"
                        :caption="String(split.workers)"
                    />
                    <Meter
                        :value="split.male"
                        :max="genderTotal"
                        :label="t('dashboard.mem_male')"
                        :caption="String(split.male)"
                    />
                    <Meter
                        :value="split.female"
                        :max="genderTotal"
                        :label="t('dashboard.mem_female')"
                        :caption="String(split.female)"
                    />
                </div>
            </Card>
        </section>

        <Card v-if="topCities.length" class="border-border/70 shadow-none">
            <CardHeader class="px-5 py-4">
                <CardTitle class="text-base">{{ t('dashboard.mem_top_cities') }}</CardTitle>
            </CardHeader>
            <Separator />
            <div class="grid gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
                <Meter
                    v-for="city in topCities"
                    :key="city.name"
                    :value="city.count"
                    :max="cityTotal"
                    :label="city.name"
                    :caption="String(city.count)"
                />
            </div>
        </Card>
    </div>
</template>
