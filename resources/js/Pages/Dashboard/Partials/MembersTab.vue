<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import Meter from '@/Components/Dashboard/Meter.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import AttendanceCard from '@/Pages/Dashboard/Partials/AttendanceCard.vue';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import StatTable from '@/Components/Stats/StatTable.vue';
import { Separator } from '@/Components/ui/separator';
import { useChartTheme } from '@/Composables/useChartTheme';
import { barDataset, baseOptions, ordinal, rankedBarDataset, seriesColor, spanLabel } from '@/lib/chartTheme';
import CompletionBar from '@/Components/Stats/CompletionBar.vue';
import { useFormatNumber } from '@/Composables/useFormatNumber';
import { completionBarProps, completionColors } from '@/lib/completion';
import { columnTotals } from '@/lib/statTable';

const props = defineProps({
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    rtl: { type: Boolean, default: false },
    branchId: { type: [Number, String], default: null },
});

const { t } = useI18n();
const { themeVersion } = useChartTheme();
const { formatNumber, formatPercent } = useFormatNumber();

const summary = computed(() => props.data?.summary ?? []);
const growth = computed(() => props.data?.growth ?? { labels: [], joined: [], left: [], cumulative: [] });
const byCategory = computed(() => props.data?.byCategory ?? []);
const leftByCategory = computed(() => props.data?.leftByCategory ?? []);
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
    left: { icon: 'logout', tone: 'negative' },
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

const growthLeft = computed(() => growth.value.left ?? []);
const hasGrowth = computed(() => growth.value.joined.some((v) => v > 0) || growthLeft.value.some((v) => v > 0));

/**
 * Joins per month as columns with the running total as a line.
 *
 * Both are counts of members on one axis, so they can share a scale. The
 * columns answer "how many joined", the line "how many there are" — two
 * questions a club asks together.
 */
const growthChart = computed(() => {
    themeVersion.value; // recolour on theme toggle

    return {
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
            type: 'bar',
            label: t('dashboard.mem_left'),
            data: growthLeft.value,
            backgroundColor: seriesColor(1),
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
    };
});

const growthOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: props.rtl });
});

const barOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: props.rtl, horizontal: true, legend: false });
});


const growthColumns = computed(() => [
    { key: 'month', label: t('dashboard.month'), format: 'text' },
    { key: 'joined', label: t('dashboard.mem_joined'), format: 'number', total: 'sum' },
    { key: 'left', label: t('dashboard.mem_left'), format: 'number', total: 'sum' },
    { key: 'cumulative', label: t('dashboard.mem_total'), format: 'number' },
]);
const growthRows = computed(() => growth.value.labels.map((month, i) => ({
    month,
    joined: growth.value.joined[i] ?? 0,
    left: growthLeft.value[i] ?? 0,
    cumulative: growth.value.cumulative[i] ?? 0,
})));
const growthTotals = computed(() => columnTotals(growthColumns.value, growthRows.value));

const countColumns = computed(() => [
    { key: 'label', label: t('dashboard.category'), format: 'text', sortable: true },
    { key: 'count', label: t('dashboard.members'), format: 'number', sortable: true, total: 'sum' },
]);
// Rows carry the display label as data, so the table, the sort and the CSV agree.
const withLabel = (rows) => rows.map((r) => ({ ...r, label: rowLabel(r) }));
const categoryRows = computed(() => withLabel(byCategory.value));
const leftCategoryRows = computed(() => withLabel(leftByCategory.value));
const categoryTotals = computed(() => columnTotals(countColumns.value, categoryRows.value));
const leftCategoryTotals = computed(() => columnTotals(countColumns.value, leftCategoryRows.value));
const rowKey = (r) => r.labelKey || r.name;

const ageColumns = computed(() => [
    { key: 'label', label: t('stats.age_band'), format: 'text' },
    { key: 'count', label: t('dashboard.members'), format: 'number', total: 'sum' },
]);
const ageRows = computed(() => byAge.value.map((r) => ({ ...r, label: t(`dashboard.age_${r.band}`) })));
const ageTotals = computed(() => columnTotals(ageColumns.value, ageRows.value));

/** Magnitude: one hue, darkest for the largest bar (shared ranked-bar helper). */
function magnitudeChart(rows) {
    themeVersion.value; // recolour on theme toggle

    return {
        labels: rows.map((r) => r.label),
        datasets: [rankedBarDataset(t('dashboard.members'), rows.map((r) => r.count))],
    };
}

const categoryChart = computed(() => magnitudeChart(categoryRows.value));
const leftCategoryChart = computed(() => magnitudeChart(leftCategoryRows.value));

/** Age is an ordered scale, so it wears the ordinal ramp rather than magnitude. */
const ageChart = computed(() => {
    themeVersion.value;
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

const ageOptions = computed(() => {
    themeVersion.value;

    return baseOptions({ rtl: props.rtl, legend: false });
});

const debtTotal = computed(() => debtBands.value.reduce((sum, band) => sum + band.count, 0) || 1);
const statusTotal = computed(() => byStatus.value.reduce((sum, row) => sum + row.count, 0) || 1);
const cityTotal = computed(() => topCities.value.reduce((sum, row) => sum + row.count, 0) || 1);
const splitTotal = computed(() => (split.value.students + split.value.workers) || 1);
const genderTotal = computed(() => (split.value.male + split.value.female) || 1);

// --- Document completion (documents/view; null hides the whole section) ---
const documents = computed(() => props.data?.documents ?? null);

// Drill-downs land on the players list with the dashboard's branch, like
// academicLink. The list's default population (not archived, not left) is the
// same as this section's, so the numbers match what the reader clicks.
function playersLink(params) {
    const query = { ...params };
    if (props.branchId) query.branch_id = props.branchId;
    return route('players.index', query);
}

const docMissingHref = computed(() => (documents.value?.summary.missing ? playersLink({ documents: 'missing' }) : null));

const docCountColumns = () => [
    { key: 'required', label: t('stats.doc_required'), format: 'number', sortable: true, total: 'sum' },
    { key: 'received', label: t('stats.doc_received'), format: 'number', sortable: true, total: 'sum' },
    { key: 'expiring', label: t('stats.doc_expiring'), format: 'number', sortable: true, total: 'sum' },
    { key: 'missing', label: t('stats.doc_missing'), format: 'number', sortable: true, total: 'sum' },
    // A percent never sums.
    { key: 'pct', label: t('stats.doc_pct'), format: 'percent', sortable: true },
];

const docTypeColumns = computed(() => [
    { key: 'label', label: t('stats.doc_document'), format: 'text', sortable: true },
    ...docCountColumns(),
]);

const docCategoryColumns = computed(() => [
    { key: 'label', label: t('dashboard.category'), format: 'text', sortable: true },
    ...docCountColumns(),
    // Each player has one category (or none, its own row): categories
    // partition the players, so these two counts add up.
    { key: 'players_complete', label: t('stats.doc_complete_files'), format: 'number', sortable: true, total: 'sum' },
    { key: 'players_total', label: t('stats.doc_players'), format: 'number', sortable: true, total: 'sum' },
]);

const docTypeRows = computed(() => (documents.value?.summary.by_type ?? []).map((row) => ({
    ...row,
    label: row.name,
    href: row.missing > 0 ? playersLink({ documents: `missing-${row.id}` }) : null,
})));

const docCategoryRows = computed(() => (documents.value?.by_category ?? []).map((row) => ({
    ...row,
    label: row.name ?? t('dashboard.fin_uncategorised'),
    // The list has no "no category" filter, so that row has no link.
    href: row.missing > 0 && row.category_id ? playersLink({ category_id: row.category_id, documents: 'missing' }) : null,
})));

const docTypeTotals = computed(() => columnTotals(docTypeColumns.value, docTypeRows.value));
const docCategoryTotals = computed(() => columnTotals(docCategoryColumns.value, docCategoryRows.value));
const docEmpty = computed(() => !documents.value?.summary.players_total);

// Each row as shares of its own required documents (100 % stacked), so a
// small category and a large one compare on one scale; counts in the tooltip.
const DOC_PARTS = [
    { key: 'received', label: 'stats.doc_valid', count: (row) => row.received - row.expiring },
    { key: 'expiring', label: 'stats.doc_expiring', count: (row) => row.expiring },
    { key: 'missing', label: 'stats.doc_missing', count: (row) => row.missing },
];

function completionChart(rows) {
    themeVersion.value; // recolour on theme toggle
    const colors = completionColors();

    return {
        labels: rows.map((row) => row.label),
        datasets: DOC_PARTS.map((part) => ({
            ...barDataset(
                t(part.label),
                rows.map((row) => (row.required > 0 ? (part.count(row) / row.required) * 100 : 0)),
                0,
                { stack: 'documents', color: colors[part.key] },
            ),
            // barDataset's surface-coloured border, switched on at the end edge:
            // the gap between neighbouring parts, so two colours never touch.
            borderWidth: { top: 0, right: 2, bottom: 0, left: 0 },
        })),
    };
}

function completionOptions(rows) {
    themeVersion.value;
    const base = baseOptions({ rtl: props.rtl, stacked: true, horizontal: true });

    return {
        ...base,
        scales: {
            ...base.scales,
            x: { ...base.scales.x, max: 100, ticks: { ...base.scales.x.ticks, callback: (value) => formatPercent(value, { trim: true }) } },
        },
        plugins: {
            ...base.plugins,
            tooltip: {
                ...base.plugins.tooltip,
                callbacks: {
                    label: (ctx) => ` ${ctx.dataset.label}: ${formatNumber(DOC_PARTS[ctx.datasetIndex].count(rows[ctx.dataIndex]))} (${formatPercent(ctx.parsed.x)})`,
                },
            },
        },
        // A bar opens the players behind it, like its table row.
        onClick: (_event, elements) => {
            const href = rows[elements[0]?.index]?.href;
            if (href) router.visit(href);
        },
        onHover: (event, elements) => {
            const target = event.native?.target;
            if (target) target.style.cursor = elements.length && rows[elements[0].index]?.href ? 'pointer' : 'default';
        },
    };
}

const docTypeChart = computed(() => completionChart(docTypeRows.value));
const docTypeOptions = computed(() => completionOptions(docTypeRows.value));
const docCategoryChart = computed(() => completionChart(docCategoryRows.value));
const docCategoryOptions = computed(() => completionOptions(docCategoryRows.value));
</script>

<template>
    <div class="space-y-5">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" :aria-label="t('dashboard.tab_members')">
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

        <section v-if="documents" class="space-y-4" :aria-label="t('stats.doc_title')">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h3 class="text-sm font-semibold text-muted-foreground">{{ t('stats.doc_title') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('stats.doc_as_of_today') }}</p>
            </div>

            <CompletionBar v-bind="completionBarProps(documents.summary)" :links="{ missing: docMissingHref }" size="lg" />

            <div class="grid gap-5 lg:grid-cols-2">
                <ChartCard
                    :title="t('stats.doc_by_category')"
                    :loading="loading"
                    :empty="docEmpty"
                    :empty-hint="t('dashboard.mem_no_members')"
                    has-table
                    :csv="{ filename: 'dashboard-documents-by-category', columns: docCategoryColumns, rows: docCategoryRows, totals: docCategoryTotals }"
                    height="h-72"
                >
                    <Bar :data="docCategoryChart" :options="docCategoryOptions" />

                    <template #table>
                        <StatTable :columns="docCategoryColumns" :rows="docCategoryRows" :row-key="(r) => r.category_id ?? 'none'" :totals="docCategoryTotals" dense>
                            <template #cell-label="{ row, formatted }">
                                <Link v-if="row.href" :href="row.href" class="font-medium text-primary-600 hover:underline dark:text-primary-300">{{ formatted }}</Link>
                                <template v-else>{{ formatted }}</template>
                            </template>
                        </StatTable>
                    </template>
                </ChartCard>

                <ChartCard
                    :title="t('stats.doc_by_type')"
                    :loading="loading"
                    :empty="docEmpty"
                    :empty-hint="t('dashboard.mem_no_members')"
                    has-table
                    :csv="{ filename: 'dashboard-documents-by-type', columns: docTypeColumns, rows: docTypeRows, totals: docTypeTotals }"
                    height="h-72"
                >
                    <Bar :data="docTypeChart" :options="docTypeOptions" />

                    <template #table>
                        <StatTable :columns="docTypeColumns" :rows="docTypeRows" row-key="id" :totals="docTypeTotals" dense>
                            <template #cell-label="{ row, formatted }">
                                <Link v-if="row.href" :href="row.href" class="font-medium text-primary-600 hover:underline dark:text-primary-300">{{ formatted }}</Link>
                                <template v-else>{{ formatted }}</template>
                            </template>
                        </StatTable>
                    </template>
                </ChartCard>
            </div>
        </section>

        <AttendanceCard v-if="data?.attendance" :data="data.attendance" />

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
            :csv="{ filename: 'dashboard-member-growth', columns: growthColumns, rows: growthRows, totals: growthTotals }"
            height="h-72"
        >
            <Bar :data="growthChart" :options="growthOptions" />

            <template #table>
                <StatTable :columns="growthColumns" :rows="growthRows" row-key="month" :totals="growthTotals" dense />
            </template>
        </ChartCard>

        <section class="grid gap-5 lg:grid-cols-2">
            <ChartCard
                :title="t('dashboard.mem_by_category')"
                :loading="loading"
                :empty="!byCategory.length"
                :empty-hint="t('dashboard.mem_no_members')"
                has-table
                :csv="{ filename: 'dashboard-members-by-category', columns: countColumns, rows: categoryRows, totals: categoryTotals }"
                height="h-72"
            >
                <Bar :data="categoryChart" :options="barOptions" />

                <template #table>
                    <StatTable :columns="countColumns" :rows="categoryRows" :row-key="rowKey" :totals="categoryTotals" dense />
                </template>
            </ChartCard>

            <ChartCard
                :title="t('dashboard.mem_by_age')"
                :subtitle="t('dashboard.mem_by_age_hint')"
                :loading="loading"
                :empty="!byAge.length"
                :empty-hint="t('dashboard.mem_no_members')"
                has-table
                :csv="{ filename: 'dashboard-members-by-age', columns: ageColumns, rows: ageRows, totals: ageTotals }"
                height="h-72"
            >
                <Bar :data="ageChart" :options="ageOptions" />

                <template #table>
                    <StatTable :columns="ageColumns" :rows="ageRows" row-key="band" :totals="ageTotals" dense />
                </template>
            </ChartCard>
        </section>

        <ChartCard
            :title="t('dashboard.mem_left_by_category')"
            :subtitle="t('dashboard.mem_left_by_category_hint')"
            :loading="loading"
            :empty="!leftByCategory.length"
            :empty-hint="t('dashboard.mem_no_leavers')"
            has-table
            :csv="{ filename: 'dashboard-leavers-by-category', columns: countColumns, rows: leftCategoryRows, totals: leftCategoryTotals }"
            height="h-64"
        >
            <Bar :data="leftCategoryChart" :options="barOptions" />

            <template #table>
                <StatTable :columns="countColumns" :rows="leftCategoryRows" :row-key="rowKey" :totals="leftCategoryTotals" dense />
            </template>
        </ChartCard>

        <section class="grid gap-5 lg:grid-cols-3">
            <Card v-if="data?.debtBands !== null" class="border-border/70 shadow-none">
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
