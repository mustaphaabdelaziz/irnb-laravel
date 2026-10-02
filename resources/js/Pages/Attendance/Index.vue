<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { dateKey } from '@/lib/attendanceCalendar';
import AddSessionModal from './Partials/AddSessionModal.vue';
import ViewSwitcher from './Partials/ViewSwitcher.vue';
import MonthView from './Partials/MonthView.vue';
import WeekView from './Partials/WeekView.vue';
import AgendaView from './Partials/AgendaView.vue';
import TimelineView from './Partials/TimelineView.vue';

const props = defineProps({
    view: { type: String, default: 'month' },
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM, the anchor kept when switching views
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
    week: { type: Object, default: null }, // { start, end } in the week view
    from: { type: String, default: null }, // timeline window, YYYY-MM
    to: { type: String, default: null },
    kind: { type: String, default: null }, // timeline kind filter
    events: { type: Array, default: () => [] }, // timeline events
});
const { t } = useI18n();
const { can } = useCan();
const today = dateKey(new Date());

// The blank month sheet for the category and month on screen (month view only).
const sheetHref = computed(() => (props.categoryId ? route('attendance.sheets.month', { category_id: props.categoryId, month: props.month }) : null));

// Empty values are dropped so the URL only carries what is set. The category
// travels along unless a view sets it (null = every category).
const clean = (params) => Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
// The props each view returns (AttendanceCalendarController). Moving within a
// view reloads only these, so the server skips the category list and codes.
// Shared props that change while the page is open come along too
// (HandleInertiaRequests): `flash` so an old message is not shown again,
// `auth` and `pendingApprovals` so permissions and badges stay current.
// Switching views is a full visit, since each view returns different props.
const SHARED_PROPS = ['flash', 'auth', 'pendingApprovals'];
const VIEW_PROPS = {
    month: ['view', 'categoryId', 'month', 'sessions', 'preseason', 'hasSchedule'],
    week: ['view', 'categoryId', 'month', 'week', 'sessions'],
    agenda: ['view', 'categoryId', 'month', 'sessions'],
    timeline: ['view', 'categoryId', 'kind', 'month', 'from', 'to', 'events'],
};
function navigate(params, options = {}) {
    const view = params.view ?? props.view;
    const only = view === props.view ? [...VIEW_PROPS[view], ...SHARED_PROPS] : undefined;
    router.get(route('attendance.index'), clean({ view: props.view, category_id: props.categoryId, ...params }), { preserveScroll: true, ...(only ? { only } : {}), ...options });
}
const switchView = (view) => navigate({ view, month: props.month }, { preserveScroll: false });

// ---- Add an extra / pre-season session ----
const showCreate = ref(false);
const createKind = ref('extra');
function openCreate(kind) {
    createKind.value = kind;
    showCreate.value = true;
}
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h1>
                <div class="flex gap-2 print:hidden">
                    <Link :href="route('attendance.stats')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="dashboard" />{{ t('att.statistics') }}</Link>
                    <Link v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.grid') }}</Link>
                    <a v-if="categoryId && view === 'month'" :href="sheetHref" target="_blank" :title="t('att.sheet.print_hint')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.sheet.print') }}</a>
                    <Link v-if="can('attendance', 'edit')" :href="route('attendance.settings')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="settings" />{{ t('att.settings') }}</Link>
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <ViewSwitcher :view="view" @switch="switchView" />
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <button class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700" @click="openCreate('extra')">+ {{ t('att.add_extra') }}</button>
                    <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600" @click="openCreate('preseason')">+ {{ t('att.add_preseason') }}</button>
                </div>
            </div>

            <MonthView v-if="view === 'month'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" :preseason="preseason" :has-schedule="hasSchedule" @navigate="navigate" />
            <WeekView v-else-if="view === 'week'" :week="week" :sessions="sessions" @navigate="navigate" />
            <AgendaView v-else-if="view === 'agenda'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" @navigate="navigate" />
            <TimelineView v-else-if="view === 'timeline'" :categories="categories" :category-id="categoryId" :kind="kind" :from="from" :to="to" :events="events" @navigate="navigate" />
        </div>

        <AddSessionModal :show="showCreate" :kind="createKind" :categories="categories" :category-id="categoryId" :date="today" @close="showCreate = false" />
    </AuthenticatedLayout>
</template>
