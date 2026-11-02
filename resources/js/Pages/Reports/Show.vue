<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import StatBars from '@/Components/StatBars.vue';
import StatDoughnut from '@/Components/StatDoughnut.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import StatTable from '@/Components/Stats/StatTable.vue';
import ReportFilters from '@/Components/Reports/ReportFilters.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, onMounted, ref } from 'vue';
import { useCurrency } from '@/Composables/useCurrency.js';
import { rememberReport } from '@/lib/reportPrefs';

// One report: filters, summary tiles, charts or table on the same data, and
// downloads that carry the same filters and sort. Everything the user sees
// comes from the server, which validated the query string.
const { t } = useI18n();
const { currencyLabel } = useCurrency();
const page = usePage();
const props = defineProps({
    report: Object,
    filterDefs: Array,
    filters: Object,
    applied: Array,
    sortable: Array,
    perPageOptions: Array,
    columns: Array,
    rows: Object,
    totals: { type: Object, default: null },
    summary: Array,
    charts: Array,
    generatedAt: String,
});

const view = ref('table');
const url = computed(() => route('reports.view', [props.report.category, props.report.key]));

// The filters as the server applied them, without empty values.
const current = computed(() => Object.fromEntries(Object.entries(props.filters)
    .filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length))));

function reload(query) {
    router.get(url.value, query, { preserveState: true, preserveScroll: true, replace: true });
}
function generate(filterQuery) {
    const { sort, dir, per_page } = props.filters;
    reload({ ...filterQuery, sort, dir, per_page });
}
function sortBy({ key, dir }) {
    reload({ ...current.value, sort: key, dir, page: undefined });
}

const tableColumns = computed(() => props.columns.map((c) => ({
    key: c.key,
    label: t(c.label),
    format: c.type,
    sortable: props.sortable.includes(c.key),
    total: c.total ? 'sum' : 'none',
})));

const downloadHref = computed(() => route('reports.download', [props.report.category, props.report.key, current.value]));

onMounted(() => rememberReport(props.report.id));
</script>

<template>
    <Head :title="t(report.title)" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Link :href="route('reports.hub')" class="text-sm text-slate-500 hover:text-emerald-600 print:hidden dark:hover:text-emerald-400 dark:text-slate-400">{{ t('reports.title') }}</Link>
                    <span class="text-slate-300 print:hidden">/</span>
                    <Link :href="route('reports.category', report.category)" class="text-sm text-slate-500 hover:text-emerald-600 print:hidden dark:hover:text-emerald-400 dark:text-slate-400">{{ t(`reports.category.${report.category}.title`) }}</Link>
                    <span class="text-slate-300 print:hidden">/</span>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t(report.title) }}</h1>
                </div>
                <div class="print:hidden">
                    <ExportMenu :href="downloadHref" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <div class="text-sm text-slate-500 dark:text-slate-400">
                <p>{{ t(report.description) }}</p>
                <p v-if="applied.length" class="mt-1">
                    <span class="font-medium">{{ t('reports.applied_filters') }}:</span>
                    <span v-for="(f, i) in applied" :key="f.label">{{ i ? ' · ' : ' ' }}{{ f.label }}: {{ f.value }}</span>
                </p>
                <p class="mt-1 text-xs">{{ t('reports.generated_at') }}: <bdi dir="ltr">{{ generatedAt }}</bdi></p>
            </div>

            <ReportFilters :defs="filterDefs" :values="filters" :errors="page.props.errors ?? {}"
                           @generate="generate" @reset="reload({})" />

            <div v-if="summary.length" class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <StatTile v-for="tile in summary" :key="tile.label" :label="t(tile.label)" :value="tile.value" :format="tile.format" />
            </div>

            <div v-if="charts.length" class="flex justify-end print:hidden">
                <div class="inline-flex rounded-lg ring-1 ring-slate-300 dark:ring-slate-700" role="group">
                    <button v-for="v in ['table', 'chart']" :key="v" type="button" :aria-pressed="view === v"
                            class="flex items-center gap-1 px-3 py-1.5 text-sm first:rounded-s-lg last:rounded-e-lg"
                            :class="view === v ? 'bg-emerald-600 text-white' : 'text-slate-600 dark:text-slate-300'"
                            @click="view = v">
                        <Icon :name="v === 'chart' ? 'chart' : 'menu'" class="h-4 w-4" />{{ t(`reports.view_${v}`) }}
                    </button>
                </div>
            </div>

            <div v-if="view === 'chart' && charts.length" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <template v-for="chart in charts" :key="chart.title">
                    <StatDoughnut v-if="chart.type === 'doughnut'" :title="t(chart.title)" :stats="chart.stats"
                                  :unit="chart.unit === 'money' ? currencyLabel : (chart.unit ? t(chart.unit) : null)" />
                    <StatBars v-else :title="t(chart.title)" :stats="chart.stats" :format="chart.unit === 'money' ? 'money' : 'number'"
                              :unit="chart.unit === 'money' ? currencyLabel : (chart.unit ? t(chart.unit) : null)" />
                </template>
            </div>

            <StatTable v-else :columns="tableColumns" :rows="rows.data" sort-mode="server"
                       :sort="{ key: filters.sort, dir: filters.dir }" :totals="totals ?? false"
                       :empty-text="t('no_results')" @sort="sortBy">
                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                        <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ t('reports.per_page') }}
                            <select :value="filters.per_page" class="rounded-lg border-slate-300 py-1 text-sm dark:border-slate-700 dark:bg-slate-950"
                                    @change="reload({ ...current, per_page: $event.target.value, page: undefined })">
                                <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                            </select>
                        </label>
                        <Pagination :links="rows.links" />
                    </div>
                </template>
            </StatTable>
        </div>
    </AuthenticatedLayout>
</template>
