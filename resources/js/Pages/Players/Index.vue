<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, watch, computed } from 'vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useBulkSelection } from '@/Composables/useBulkSelection';
import { useListFilters } from '@/Composables/useListFilters';
import { formatFileNumber } from '@/lib/fileNumber';
import BulkEditModal from '@/Components/BulkEditModal.vue';
import StatDoughnut from '@/Components/StatDoughnut.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Icon from '@/Components/Icon.vue';
import AcademicResultsPrint from '@/Pages/Players/Partials/AcademicResultsPrint.vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    strip: { type: Array, default: () => [] },
    players: Object,
    categories: Array,
    branches: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    playerStatuses: { type: Array, default: () => [] },
    wilayas: { type: Array, default: () => [] },
    categoryStats: { type: Array, default: () => [] },
    statusStats: { type: Array, default: () => [] },
    positionStats: { type: Array, default: () => [] },
    ageStats: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    filters: Object,
    currentSchoolYear: { type: Number, default: null },
});

const search = ref(props.filters?.search || '');
// The backend filters on `category_id` — the param must match or the filter is a no-op.
const categoryFilter = ref(props.filters?.category_id || '');

const statusFilter = ref(props.filters?.status || '');
const positionFilter = ref(props.filters?.position_id || '');
const branchFilter = ref(props.filters?.branch_id || '');
const ageFilter = ref(props.filters?.age || '');
const wilayaFilter = ref(props.filters?.wilaya_id || '');
const academicFilter = ref(props.filters?.academic || '');
const certificateFilter = ref(props.filters?.certificate || '');
// missing | expiring | missing-<typeId> — one select, one query parameter.
const documentsFilter = ref(props.filters?.documents || '');
// Active vs Archived view. Backend defaults to active when no `archived` param.
const archivedView = ref(!!Number(props.filters?.archived));

// The stats follow the filters too; the lookup lists never do, so they stay out
// of the reload.
const { params: filterParams, loading: filtering } = useListFilters('players.index', () => ({
    search: search.value,
    category_id: categoryFilter.value,
    status: statusFilter.value,
    position_id: positionFilter.value,
    branch_id: branchFilter.value,
    age: ageFilter.value,
    wilaya_id: wilayaFilter.value,
    academic: academicFilter.value,
    certificate: certificateFilter.value,
    documents: documentsFilter.value,
    archived: archivedView.value ? 1 : undefined,
}), { only: ['players', 'filters', 'categoryStats', 'statusStats', 'positionStats', 'ageStats'] });

// --- Remembered filters ---
// The filters stick until the user clears them, even after leaving the page or
// restarting the app. A visit with its own query string (a dashboard drill-down,
// a bookmark) wins; a bare visit (the sidebar link) restores the last filters.
// Setting the refs here, before the first render, lets useListFilters' watcher
// reload the list with them.
const FILTERS_KEY = 'players.filters';
const filterRefs = {
    search, category_id: categoryFilter, status: statusFilter, position_id: positionFilter,
    branch_id: branchFilter, age: ageFilter, wilaya_id: wilayaFilter, academic: academicFilter,
    certificate: certificateFilter, documents: documentsFilter,
};

if (!window.location.search) {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(FILTERS_KEY) || 'null'); } catch { saved = null; }
    if (saved && typeof saved === 'object') {
        for (const [key, r] of Object.entries(filterRefs)) {
            if (saved[key] !== undefined) r.value = String(saved[key]);
        }
        if (saved.archived) archivedView.value = true;
    }
}

watch(filterParams, (params) => {
    try {
        if (Object.keys(params).length) localStorage.setItem(FILTERS_KEY, JSON.stringify(params));
        else localStorage.removeItem(FILTERS_KEY);
    } catch { /* storage unavailable: filters just aren't remembered */ }
}, { immediate: true });

const hasActiveFilters = computed(() => Object.keys(filterParams.value).length > 0);

function clearFilters() {
    for (const r of Object.values(filterRefs)) r.value = '';
    archivedView.value = false;
}

const exportHref = computed(() => route('players.export', filterParams.value));

// Secondary header actions: shown inline on wide screens, folded into a
// "more" menu below xl so the fixed-height header never overflows.
const secondaryActions = computed(() => [
    { key: 'template', label: t('template'), icon: 'document', href: route('players.import.template') },
    { key: 'import', label: t('import'), icon: 'upload', onClick: () => { showImport.value = true; } },
    { key: 'export', label: t('export'), icon: 'download', href: exportHref.value },
]);

// Distribution panels: map each stat source to StatDoughnut's {key, label, count}.
const categoryChips = computed(() => props.categoryStats.map((s) => ({
    key: s.category_id ?? '', label: s.name || t('uncategorized'), count: s.count,
})));
const statusChips = computed(() => props.statusStats.map((s) => ({
    key: s.status_id ?? '', label: s.name || t('uncategorized'), count: s.count,
})));
const positionChips = computed(() => props.positionStats.map((s) => ({
    key: s.position_id ?? '', label: s.name || t('unassigned'), count: s.count,
})));
const ageLabel = (bucket) => (bucket === 'u10' ? '< 10' : bucket === 'unknown' ? t('unknown') : bucket);
const ageChips = computed(() => props.ageStats.map((s) => ({
    key: s.bucket, label: ageLabel(s.bucket), count: s.count,
})));

const statusPalette = ['#0284c7', '#d97706', '#e11d48', '#64748b', '#7c3aed', '#02a85c'];
const agePalette = ['#7c3aed', '#0284c7', '#02a85c', '#d97706', '#e11d48', '#64748b'];

// List/grid view toggle, remembered across visits.
const view = ref(localStorage.getItem('players.view') || 'list');
watch(view, (v) => localStorage.setItem('players.view', v));

const showImport = ref(false);
const importForm = useForm({ file: null });
const importConverting = ref(false);

// CSV uploads pass straight through. Excel (.xlsx/.xls) can't be parsed by the
// desktop build's PHP (no xmlreader), so convert it to CSV in the browser and
// feed the existing CSV import pipeline — works identically on web and desktop.
async function onImportFile(e) {
    const file = e.target.files?.[0];
    importForm.clearErrors();
    if (!file) { importForm.file = null; return; }
    if (!/\.(xlsx|xls)$/i.test(file.name)) {
        importForm.file = file;
        return;
    }
    importConverting.value = true;
    try {
        const XLSX = await import('xlsx');
        const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' });
        const sheet = wb.Sheets[wb.SheetNames[0]];
        const csv = XLSX.utils.sheet_to_csv(sheet);
        const name = file.name.replace(/\.(xlsx|xls)$/i, '.csv');
        importForm.file = new File([csv], name, { type: 'text/csv' });
    } catch (err) {
        importForm.file = null;
        importForm.setError('file', t('excel_parse_failed'));
    } finally {
        importConverting.value = false;
    }
}

function submitImport() {
    importForm.post(route('players.import.store'), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); },
    });
}

// --- Row selection (list view) ---
// filterParams is the reset signal: when the filters change the rows are
// replaced, so a carried-over selection would act on off-screen players.
const { selected, allSelected, toggleAll, toggleOne, clear: clearSelection } =
    useBulkSelection(computed(() => props.players.data), filterParams);

// --- Single-row actions (confirm-gated) ---
const archiveId = ref(null);
const restoreId = ref(null);
const forceId = ref(null);
const opts = { preserveScroll: true, onSuccess: () => clearSelection() };

function doArchive() { const id = archiveId.value; archiveId.value = null; router.delete(route('players.destroy', id), opts); }
function doRestore() { const id = restoreId.value; restoreId.value = null; router.put(route('players.restore', id), {}, opts); }
function doForce() { const id = forceId.value; forceId.value = null; router.delete(route('players.forceDelete', id), opts); }

// --- Bulk edit ---
const showBulkEdit = ref(false);

// Only fields backed by a real lookup are offered; the backend enforces the
// same allow-list, this just builds the controls.
const bulkFields = computed(() => [
    {
        key: 'category_id',
        label: t('category'),
        options: props.categories.map((c) => ({ value: c.id, label: c.localized_name || c.name })),
    },
    {
        key: 'position_id',
        label: t('position'),
        options: props.positions.map((p) => ({ value: p.id, label: p.name })),
    },
    {
        key: 'status_id',
        label: t('membership_status'),
        options: props.playerStatuses.map((s) => ({ value: s.id, label: s.localized_name || s.name })),
    },
    {
        key: 'branches',
        label: t('branch'),
        multiple: true,
        options: props.branches.map((b) => ({ value: b.id, label: b.localized_name || b.name })),
    },
]);

// --- Bulk actions (confirm-gated) ---
const bulkAction = ref(null); // 'archive' | 'restore' | 'force'
const bulkMessage = computed(() => ({
    archive: t('confirm_bulk_archive', { count: selected.value.length }),
    restore: t('confirm_bulk_restore', { count: selected.value.length }),
    force: t('confirm_bulk_force_delete', { count: selected.value.length }),
}[bulkAction.value] || ''));
function runBulk() {
    const action = bulkAction.value;
    bulkAction.value = null;
    const routes = { archive: 'players.bulkArchive', restore: 'players.bulkRestore', force: 'players.bulkForceDelete' };
    router.post(route(routes[action]), { ids: selected.value }, opts);
}
</script>

<template>
    <Head :title="t('players')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="min-w-0 truncate text-lg font-bold text-slate-900 dark:text-slate-100 sm:text-xl">{{ t('players') }}</h1>
                <div class="flex shrink-0 items-center gap-2">
                    <!-- Secondary actions inline on wide screens -->
                    <div class="hidden items-center gap-2 xl:flex">
                        <component
                            :is="action.href ? 'a' : 'button'"
                            v-for="action in secondaryActions"
                            :key="action.key"
                            :href="action.href"
                            :type="action.href ? undefined : 'button'"
                            @click="action.onClick?.()"
                            class="inline-flex h-9 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:focus-visible:ring-offset-slate-900"
                        >
                            <Icon :name="action.icon" class="text-base" />
                            {{ action.label }}
                        </component>
                    </div>

                    <a v-if="categoryFilter" :href="route('players.board-table', { category_id: categoryFilter })" target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                        <Icon name="print" /> {{ t('print_board_table') }}
                    </a>
                    <AcademicResultsPrint :categories="categories" :category-id="categoryFilter" :current-school-year="currentSchoolYear" />

                    <!-- ...folded into an overflow menu below xl -->
                    <Dropdown align="right" width="48" class="xl:hidden">
                        <template #trigger>
                            <button
                                type="button"
                                :aria-label="t('more_actions')"
                                :title="t('more_actions')"
                                aria-haspopup="menu"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg text-slate-600 shadow-sm transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:focus-visible:ring-offset-slate-900"
                            >
                                <Icon name="more" />
                            </button>
                        </template>
                        <template #content>
                            <div role="menu">
                                <component
                                    :is="action.href ? 'a' : 'button'"
                                    v-for="action in secondaryActions"
                                    :key="action.key"
                                    :href="action.href"
                                    :type="action.href ? undefined : 'button'"
                                    role="menuitem"
                                    @click="action.onClick?.()"
                                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-start text-sm text-slate-700 transition-colors hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:bg-slate-700"
                                >
                                    <Icon :name="action.icon" class="text-base text-slate-400 dark:text-slate-500" />
                                    {{ action.label }}
                                </component>
                            </div>
                        </template>
                    </Dropdown>

                    <!-- Primary action: always visible, icon-only on phones -->
                    <Link
                        :href="route('players.create')"
                        :aria-label="t('new_player')"
                        :title="t('new_player')"
                        class="inline-flex h-9 min-w-9 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-primary-600 px-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-slate-900 sm:px-4"
                    >
                        <Icon name="plus" class="text-base" :stroke-width="2" />
                        <span class="hidden sm:inline">{{ t('new_player') }}</span>
                    </Link>
                </div>
            </div>
        </template>

        <StatStrip :tiles="strip || []" class="mb-4" />

        <div class="space-y-4">
            <!-- Distribution doughnuts (count + % of active players); click a slice or chip to filter -->
            <div class="grid gap-4 lg:grid-cols-2">
                <StatDoughnut v-if="categoryChips.length" v-model="categoryFilter" :title="t('by_category')" :stats="categoryChips" />
                <StatDoughnut v-if="statusChips.length" v-model="statusFilter" :title="t('by_status')" :stats="statusChips" :palette="statusPalette" />
                <StatDoughnut v-if="positionChips.length" v-model="positionFilter" :title="t('by_position')" :stats="positionChips" />
                <StatDoughnut v-if="ageChips.length" v-model="ageFilter" :title="t('by_age')" :stats="ageChips" :palette="agePalette" />
            </div>

            <!-- Filters + view toggle -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :loading="filtering" :placeholder="t('search_for_member')" />
                </div>
                <select
                    v-model="categoryFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('all_categories') }}</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.localized_name || cat.name }}</option>
                </select>
                <select
                    v-if="branches.length"
                    v-model="branchFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('all_branches') }}</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                </select>
                <select
                    v-if="wilayas.length"
                    v-model="wilayaFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('all_wilayas') }}</option>
                    <!-- A value of its own: useListFilters drops empty values. -->
                    <option value="none">{{ t('no_wilaya') }}</option>
                    <option v-for="w in wilayas" :key="w.id" :value="w.id">{{ w.code }} · {{ w.localized_name || w.name }}</option>
                </select>
                <select
                    v-model="academicFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('academic_all') }}</option>
                    <option value="at_risk">{{ t('academic_at_risk') }}</option>
                    <option value="good">{{ t('academic_good') }}</option>
                    <option value="none">{{ t('academic_none') }}</option>
                </select>
                <select
                    v-model="certificateFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('certificate_filter_all') }}</option>
                    <option value="excellence">{{ t('certificate_excellence') }}</option>
                    <option value="congratulations">{{ t('certificate_congratulations') }}</option>
                    <option value="encouragement">{{ t('certificate_encouragement') }}</option>
                    <option value="honor_roll">{{ t('certificate_honor_roll') }}</option>
                </select>
                <select
                    v-model="documentsFilter"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:max-w-xs sm:flex-none"
                >
                    <option value="">{{ t('doc_filter_all') }}</option>
                    <option value="missing">{{ t('doc_filter_missing') }}</option>
                    <option value="expiring">{{ t('doc_filter_expiring') }}</option>
                    <optgroup v-if="documentTypes.length" :label="t('doc_filter_missing_type')">
                        <option v-for="dt in documentTypes" :key="dt.id" :value="`missing-${dt.id}`">{{ dt.localized_name || dt.name }}</option>
                    </optgroup>
                </select>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30"
                >
                    <Icon name="xcircle" class="text-sm" /> {{ t('clear_filters') }}
                </button>
                <!-- Toggles share a row on phones: status left, view right -->
                <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:flex-1">
                    <div class="inline-flex rounded-xl bg-slate-100 p-0.5 dark:bg-slate-800">
                        <button type="button" @click="archivedView = false" :aria-pressed="!archivedView" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                            :class="!archivedView ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">{{ t('active') }}</button>
                        <button type="button" @click="archivedView = true" :aria-pressed="archivedView" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                            :class="archivedView ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">{{ t('archived') }}</button>
                    </div>
                    <div class="inline-flex rounded-xl bg-slate-100 p-0.5 dark:bg-slate-800">
                        <button type="button" @click="view = 'list'" :title="t('list_view')" :aria-label="t('list_view')" :aria-pressed="view === 'list'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                            :class="view === 'list' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                            <Icon name="menu" class="text-sm" />
                            <span class="hidden sm:inline">{{ t('list_view') }}</span>
                        </button>
                        <button type="button" @click="view = 'grid'" :title="t('grid_view')" :aria-label="t('grid_view')" :aria-pressed="view === 'grid'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                            :class="view === 'grid' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                            <Icon name="dashboard" class="text-sm" />
                            <span class="hidden sm:inline">{{ t('grid_view') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bulk action bar (list view) -->
            <div v-if="view === 'list' && selected.length" class="flex flex-wrap items-center gap-3 rounded-xl bg-primary-50 dark:bg-primary-900/20 px-4 py-2.5 ring-1 ring-primary-200 dark:ring-primary-800">
                <span class="text-sm font-medium text-primary-800 dark:text-primary-200">{{ t('selected_count', { count: selected.length }) }}</span>
                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <button v-if="!archivedView" @click="showBulkEdit = true" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">{{ t('bulk_edit') }}</button>
                    <button v-if="!archivedView" @click="bulkAction = 'archive'" class="rounded-lg bg-white dark:bg-slate-900 px-3 py-1.5 text-sm font-medium text-slate-700 dark:text-slate-200 ring-1 ring-slate-300 dark:ring-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('archive_selected') }}</button>
                    <button v-if="archivedView" @click="bulkAction = 'restore'" class="rounded-lg bg-white dark:bg-slate-900 px-3 py-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-300 dark:ring-emerald-800 hover:bg-emerald-50 dark:hover:bg-emerald-900/30">{{ t('restore_selected') }}</button>
                    <button v-if="archivedView" @click="bulkAction = 'force'" class="rounded-lg bg-white dark:bg-slate-900 px-3 py-1.5 text-sm font-medium text-rose-700 dark:text-rose-300 ring-1 ring-rose-300 dark:ring-rose-800 hover:bg-rose-50 dark:hover:bg-rose-900/30">{{ t('delete_permanently_selected') }}</button>
                    <a :href="route('players.labels', { ids: selected.join(',') })" target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                        <Icon name="print" /> {{ t('print_selected_labels') }}
                    </a>
                </div>
            </div>

            <!-- Grid view -->
            <div v-if="view === 'grid'" class="space-y-4 transition-opacity" :class="{ 'opacity-60': filtering }" :aria-busy="filtering">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <Link v-for="player in players.data" :key="player.id" :href="route('players.show', player.id)"
                        class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition-shadow hover:shadow-md dark:bg-slate-900 dark:ring-slate-800">
                        <div class="flex items-center gap-3">
                            <img v-if="player.picture_url" :src="player.picture_url" :alt="player.firstname" class="h-14 w-14 shrink-0 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                            <div v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-xl font-bold text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300">
                                {{ (player.firstname || '?').charAt(0).toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ player.fullname || `${player.lastname} ${player.firstname}` }}</p>
                                <p class="truncate font-mono text-xs text-slate-400">{{ player.membership_id }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ player.category?.localized_name || player.category?.name || '-' }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-2 text-xs dark:border-slate-800">
                            <Badge v-if="player.archived" :label="t('archived')" color="slate" />
                            <Badge v-else :label="t('active')" color="emerald" />
                            <span class="font-semibold" :class="player.total_debt > 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(player.total_debt || 0) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-end gap-3 text-xs">
                            <button v-if="!player.archived" @click.prevent.stop="archiveId = player.id" class="text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                            <button v-if="player.archived" @click.prevent.stop="restoreId = player.id" class="text-emerald-600 hover:text-emerald-800">{{ t('restore') }}</button>
                            <button v-if="player.archived" @click.prevent.stop="forceId = player.id" class="text-rose-600 hover:text-rose-800">{{ t('delete_permanently') }}</button>
                        </div>
                    </Link>
                </div>
                <p v-if="!players.data.length" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</p>
                <Pagination :links="players" />
            </div>

            <!-- Table -->
            <div v-if="view === 'list'" :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden transition-opacity rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="w-10 px-4 py-3">
                                    <input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                </th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('membership_id') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('file_number') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('position') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('documents') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('debt') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="player in players.data" :key="player.id" class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                                :class="selected.includes(player.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                                <td class="px-4 py-3">
                                    <input type="checkbox" :checked="selected.includes(player.id)" @change="toggleOne(player.id)" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">{{ player.membership_id }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">{{ formatFileNumber(player.file_number) }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Link :href="route('players.show', player.id)" class="text-sm font-medium text-slate-900 dark:text-slate-100 hover:text-primary-600">
                                        {{ player.fullname || `${player.lastname} ${player.firstname}` }}
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ player.category?.localized_name || player.category?.name || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                    {{ player.position?.abbreviation || '-' }}
                                    <span v-if="player.other_positions?.length" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                                        :title="player.other_positions.map((p) => p.abbreviation).join(', ')">
                                        +{{ player.other_positions.length }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <!-- Membership status (منخرط/معتزل…); the active/archived split is the view toggle. -->
                                    <Badge v-if="player.status" :label="player.status.localized_name || player.status.name" color="primary" />
                                    <span v-else class="text-sm text-slate-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Badge v-if="Number(player.missing_documents_count) > 0" :label="t('doc_missing_count', { count: Number(player.missing_documents_count) })" color="rose" />
                                    <Icon v-else name="check" class="text-emerald-500" :title="t('doc_complete')" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold"
                                    :class="player.total_debt > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'">
                                    {{ formatMoney(player.total_debt || 0) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('players.show', player.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('details') }}</Link>
                                        <Link :href="route('players.edit', player.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                        <button v-if="!player.archived" @click="archiveId = player.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                        <button v-if="player.archived" @click="restoreId = player.id" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('restore') }}</button>
                                        <button v-if="player.archived" @click="forceId = player.id" class="text-sm text-rose-600 hover:text-rose-800">{{ t('delete_permanently') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!players.data.length">
                                <td colspan="10" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4">
                    <Pagination :links="players" />
                </div>
            </div>
        </div>

        <!-- Import modal -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showImport = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('import_players') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('import_players_hint') }}</p>
                    <form @submit.prevent="submitImport" class="mt-4 space-y-4">
                        <input
                            type="file"
                            accept=".csv,.xlsx,.xls,text/csv"
                            @change="onImportFile"
                            required
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                        />
                        <p v-if="importConverting" class="text-sm text-slate-500 dark:text-slate-400">{{ t('converting_excel') }}</p>
                        <p v-if="importForm.errors.file" class="text-sm text-rose-600">{{ importForm.errors.file }}</p>
                        <div class="flex items-center justify-between gap-3 pt-2">
                            <a :href="route('players.import.template')" class="text-sm font-medium text-primary-600 hover:underline">{{ t('download_template') }}</a>
                            <div class="flex gap-2">
                                <button type="button" @click="showImport = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                                <button type="submit" :disabled="importForm.processing || importConverting || !importForm.file" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('import') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmModal :show="!!archiveId" :message="t('confirm_archive_player')" @confirm="doArchive" @cancel="archiveId = null" />
        <ConfirmModal :show="!!restoreId" :message="t('confirm_restore_player')" @confirm="doRestore" @cancel="restoreId = null" />
        <ConfirmModal :show="!!forceId" :message="t('confirm_force_delete_player')" @confirm="doForce" @cancel="forceId = null" />
        <ConfirmModal :show="!!bulkAction" :message="bulkMessage" @confirm="runBulk" @cancel="bulkAction = null" />

        <BulkEditModal
            :show="showBulkEdit"
            :ids="selected"
            action="players.bulkUpdate"
            :fields="bulkFields"
            @close="showBulkEdit = false"
            @saved="clearSelection"
        />
    </AuthenticatedLayout>
</template>
